<?php
/**
 * The order postprocessing trace (TWO-27014): one JSON record per request with a body that the hook ran merchant
 * subscribers on, stored in PrestaShop's log like the discrepancy snapshots and listed on the module's error log page.
 * It holds the payload before the first subscriber and after each one, in run order, so a merchant without server
 * access can see which subscriber changed what.
 *
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoOrderPostprocessingTrace
{
    const LOG_OBJECT_TYPE = 'TwoOrderPostprocessingTrace';
    const SCHEMA_VERSION = 1;
    const REDACTED = 'redacted';

    // The one switch for the redaction below: false stores every payload whole.
    const REDACT_BUYER_DETAILS = true;

    // The buyer's personal fields the payload builders send (getTwoIntentOrderData(), getTwoNewOrderData(),
    // getTwoUpdateOrderData() and buildTwoAddress()): person names, email, phone and street address lines.
    // Amounts, tax, lines, the company, its organisation number, country, city and postal code are kept.
    const BUYER_DETAIL_PATHS = array(
        '/buyer/representative/first_name',
        '/buyer/representative/last_name',
        '/buyer/representative/email',
        '/buyer/representative/phone_number',
        '/billing_address/street_address',
        '/shipping_address/street_address',
        '/invoice_details/invoice_emails',
    );

    /** @var array the record's header: request, ids, versions, subscribers */
    private $header;

    /** @var array the payload before the first subscriber */
    private $before;

    /** @var array<int,array{subscriber:string,payload:mixed}> after each subscriber that returned, in run order */
    private $steps = array();

    /** @var string|null the subscriber running now */
    private $current;

    /** @var array|null {subscriber, class, at} when a subscriber threw */
    private $threw;

    /**
     * @param string $requestType
     * @param string $trigger
     * @param int $idCart
     * @param int $idOrder
     * @param string $moduleVersion
     * @param string[] $subscribers TwoOrderPostprocessing::names(), in run order
     * @param array $before
     */
    public function __construct($requestType, $trigger, $idCart, $idOrder, $moduleVersion, array $subscribers, array $before)
    {
        $this->header = array(
            'v' => self::SCHEMA_VERSION,
            'request_type' => (string) $requestType,
            'trigger' => (string) $trigger,
            'id_cart' => (int) $idCart,
            'id_order' => (int) $idOrder,
            'module_version' => (string) $moduleVersion,
            'contract_version' => TwoOrderPostprocessing::CONTRACT_VERSION,
            'redacted' => self::REDACT_BUYER_DETAILS,
            'subscribers' => array_values($subscribers),
        );
        $this->before = $before;
    }

    /**
     * @param Module $module the subscriber about to run
     * @return void
     */
    public function starting($module)
    {
        $this->current = self::name($module);
    }

    /**
     * @param Module $module the subscriber that just returned
     * @param mixed $payload what it left
     * @return void
     */
    public function finished($module, $payload)
    {
        $this->steps[] = array('subscriber' => self::name($module), 'payload' => $payload);
        $this->current = null;
    }

    /**
     * Names the subscriber that was running: never the exception's message, which can carry buyer data.
     *
     * @param Throwable $e
     * @return void
     */
    public function threw($e)
    {
        $this->threw = array(
            'subscriber' => $this->current,
            'class' => get_class($e),
            'at' => TwoDiscrepancySnapshot::throwSite($e),
        );
    }

    /**
     * @return bool
     */
    public function hasThrown()
    {
        return $this->threw !== null;
    }

    /**
     * @return int the cart the record is filed under: core keeps an object type only with a non-zero id
     */
    public function objectId()
    {
        return $this->header['id_cart'] > 0 ? $this->header['id_cart'] : $this->header['id_order'];
    }

    /**
     * The whole record, every payload redacted.
     *
     * @return array
     */
    public function record()
    {
        $record = $this->header;
        $record['before'] = self::redact($this->before);
        $record['steps'] = array();
        foreach ($this->steps as $step) {
            $record['steps'][] = self::step($step);
        }
        if ($this->threw !== null) {
            $record['threw'] = $this->threw;
        }

        return $record;
    }

    /**
     * The record as log-safe JSON within TwoDiscrepancySnapshot::MAX_BYTES. What does not fit is shed in this
     * order, each listed under `shed`: each subscriber's payload from the last back to the first, replaced by the
     * redacted diff against the step before; then the payload before the first subscriber; then the diffs, leaving
     * each step's count of changes; then everything but the header.
     *
     * @return string
     */
    public function encode()
    {
        $record = $this->record();
        $json = TwoDiscrepancySnapshot::json($record);
        $shed = array();
        for ($i = count($this->steps) - 1; $i >= 0 && !TwoDiscrepancySnapshot::fits($json); --$i) {
            $record['steps'][$i] = $this->diffStep($i);
            $shed[] = 'steps/' . $i . '/payload';
            $json = self::withShed($record, $shed);
        }
        if (!TwoDiscrepancySnapshot::fits($json)) {
            $record['before'] = array('dropped' => 'size');
            $shed[] = 'before';
            $json = self::withShed($record, $shed);
        }
        if (!TwoDiscrepancySnapshot::fits($json)) {
            foreach ($record['steps'] as $i => $step) {
                unset($record['steps'][$i]['diff']);
            }
            $shed[] = 'steps/*/diff';
            $json = self::withShed($record, $shed);
        }
        if (!TwoDiscrepancySnapshot::fits($json)) {
            $minimal = $this->header;
            $minimal['subscribers'] = array_slice($minimal['subscribers'], 0, TwoOrderPostprocessing::MAX_DIFF_ENTRIES);
            if ($this->threw !== null) {
                $minimal['threw'] = $this->threw;
            }
            $shed[] = 'all';
            $json = self::withShed($minimal, $shed);
        }

        return $json;
    }

    /**
     * The payload with each buyer detail in BUYER_DETAIL_PATHS replaced by "redacted"; an empty or absent one is
     * left as it is, so a missing detail still shows.
     *
     * @param mixed $payload
     * @return mixed
     */
    public static function redact($payload)
    {
        if (!self::REDACT_BUYER_DETAILS || !is_array($payload)) {
            return $payload;
        }
        foreach (self::BUYER_DETAIL_PATHS as $path) {
            $keys = explode('/', ltrim($path, '/'));
            $last = array_pop($keys);
            $node = &$payload;
            foreach ($keys as $key) {
                if (!is_array($node) || !isset($node[$key])) {
                    unset($node);
                    continue 2;
                }
                $node = &$node[$key];
            }
            if (is_array($node) && isset($node[$last]) && $node[$last] !== '' && $node[$last] !== array()) {
                $node[$last] = self::REDACTED;
            }
            unset($node);
        }

        return $payload;
    }

    private function diffStep($i)
    {
        $step = $this->steps[$i];
        $previous = $i === 0 ? $this->before : $this->steps[$i - 1]['payload'];
        if (!is_array($step['payload']) || !is_array($previous)) {
            return self::step($step);
        }
        $diff = TwoOrderPostprocessing::diff($previous, $step['payload']);

        return array(
            'subscriber' => $step['subscriber'],
            'changes' => count($diff),
            'diff' => TwoOrderPostprocessing::redactDiff(array_slice($diff, 0, TwoOrderPostprocessing::MAX_DIFF_ENTRIES)),
        );
    }

    private static function step(array $step)
    {
        // A subscriber that leaves no array fails the request; what it left is named, not stored.
        return is_array($step['payload'])
            ? array('subscriber' => $step['subscriber'], 'payload' => self::redact($step['payload']))
            : array('subscriber' => $step['subscriber'], 'left' => gettype($step['payload']));
    }

    private static function withShed(array $record, array $shed)
    {
        $record['shed'] = $shed;

        return TwoDiscrepancySnapshot::json($record);
    }

    private static function name($module)
    {
        $names = TwoOrderPostprocessing::names(array($module));

        return $names[0];
    }
}
