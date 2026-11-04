<?php
/**
 * The order postprocessing trace (TWO-27014): one JSON record per request with a body that the hook ran merchant
 * subscribers on, stored in PrestaShop's log like the discrepancy snapshots and listed on the module's error log page.
 * It holds the payload before the first subscriber and after each one, in run order, so a merchant without server
 * access can see which subscriber changed what.
 *
 * A trace must never change a request or fail one: it keeps its own reference-free copies of every payload, and
 * none of its methods lets an exception out.
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
    const MAX_DEPTH = 512;

    // The one switch for the redaction below: false stores every payload whole.
    const REDACT_BUYER_DETAILS = true;

    // The buyer's personal fields the payload builders send (getTwoIntentOrderData(), getTwoNewOrderData(),
    // getTwoUpdateOrderData() and buildTwoAddress()): person names, email, phone, street address lines, the
    // buyer's free-text order note, and the company name, which for a sole trader is a person's name.
    // Amounts, tax, lines, the organisation number, country, city and postal code are kept.
    const BUYER_DETAIL_PATHS = array(
        '/buyer/representative/first_name',
        '/buyer/representative/last_name',
        '/buyer/representative/email',
        '/buyer/representative/phone_number',
        '/buyer/company/company_name',
        '/billing_address/organization_name',
        '/billing_address/street_address',
        '/shipping_address/organization_name',
        '/shipping_address/street_address',
        '/invoice_details/invoice_emails',
        '/order_note',
    );

    /** @var array the record's header: request, ids, versions, subscribers */
    private $header;

    /** @var array the payload before the first subscriber */
    private $before;

    /** @var array<int,array> after each subscriber that returned, in run order: {subscriber, payload} or {subscriber, left} */
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
        $this->before = self::copy($before);
    }

    /**
     * @param Module $module the subscriber about to run
     * @return void
     */
    public function starting($module)
    {
        try {
            $this->current = self::name($module);
        } catch (Throwable $e) {
            $this->current = null;
        }
    }

    /**
     * @param Module $module the subscriber that just returned
     * @param mixed $payload what it left; copied, so a reference it kept can change neither the trace nor the request
     * @return void
     */
    public function finished($module, $payload)
    {
        try {
            $this->steps[] = is_array($payload)
                ? array('subscriber' => self::name($module), 'payload' => self::copy($payload))
                : array('subscriber' => self::name($module), 'left' => gettype($payload));
        } catch (Throwable $e) {
            $this->steps[] = array('subscriber' => $this->current, 'unrecorded' => get_class($e));
        }
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
        $this->threw = array('subscriber' => $this->current, 'class' => get_class($e), 'at' => null);
        try {
            $this->threw['at'] = TwoDiscrepancySnapshot::throwSite($e);
        } catch (Throwable $ignored) {
        }
    }

    /**
     * Whether the request fails at the hook: a subscriber threw or left something other than an array.
     *
     * @return bool
     */
    public function failed()
    {
        if ($this->threw !== null) {
            return true;
        }
        foreach ($this->steps as $step) {
            if (isset($step['left'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return int the cart the record is filed under, else the order: core keeps an object type only with a non-zero id
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
            if (isset($step['payload'])) {
                $step['payload'] = self::redact($step['payload']);
            }
            $record['steps'][] = $step;
        }
        if ($this->threw !== null) {
            $record['threw'] = $this->threw;
        }

        return $record;
    }

    /**
     * The record as log-safe JSON within TwoDiscrepancySnapshot::MAX_BYTES. What does not fit is shed in this
     * order, each listed under `shed`: each subscriber's payload from the last back to the first, replaced by its
     * diff against the step before, redacted as the payloads are; then the payload before the first subscriber;
     * then the diffs, leaving each step's count of changes; then everything but the header.
     *
     * @return string
     */
    public function encode()
    {
        $record = $this->record();
        $json = TwoDiscrepancySnapshot::json($record);
        $shed = array();
        for ($i = count($this->steps) - 1; $i >= 0 && !TwoDiscrepancySnapshot::fits($json); --$i) {
            if (!isset($this->steps[$i]['payload'])) {
                continue;
            }
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
     * A reference-free copy of the payload with each buyer detail in BUYER_DETAIL_PATHS replaced by "redacted". An
     * object is read as the array Two would receive. An empty or absent detail is left as it is, so a missing one
     * still shows.
     *
     * @param mixed $payload
     * @return mixed
     */
    public static function redact($payload)
    {
        $payload = self::copy($payload);
        if (!self::REDACT_BUYER_DETAILS || !is_array($payload)) {
            return $payload;
        }
        foreach (self::BUYER_DETAIL_PATHS as $path) {
            $payload = self::redactPath($payload, explode('/', ltrim($path, '/')));
        }

        return $payload;
    }

    /**
     * Whether a JSON pointer is, or lies under, one of BUYER_DETAIL_PATHS.
     *
     * @param string $path
     * @return bool
     */
    public static function isBuyerDetail($path)
    {
        if (!self::REDACT_BUYER_DETAILS) {
            return false;
        }
        foreach (self::BUYER_DETAIL_PATHS as $detail) {
            if ($path === $detail || strpos($path, $detail . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rebuilt by value: an array copy shares any slot PHP holds as a reference, so a write to the copy could reach
     * the payload being sent. Objects become the arrays they encode to. Past json_encode()'s own depth limit, which
     * a payload that refers to itself reaches, nothing is copied: Two could not be sent it either.
     *
     * @param mixed $value
     * @param int $depth
     * @return mixed
     */
    private static function copy($value, $depth = 0)
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }
        if (is_object($value)) {
            $json = json_encode($value);
            $value = $json === false ? null : json_decode($json, true);
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = array();
        foreach ($value as $key => $item) {
            $out[$key] = self::copy($item, $depth + 1);
        }

        return $out;
    }

    private static function redactPath(array $node, array $keys)
    {
        $key = array_shift($keys);
        if (!array_key_exists($key, $node)) {
            return $node;
        }
        if ($keys !== array()) {
            if (is_array($node[$key])) {
                $node[$key] = self::redactPath($node[$key], $keys);
            }

            return $node;
        }
        if ($node[$key] !== null && $node[$key] !== '' && $node[$key] !== array()) {
            $node[$key] = self::REDACTED;
        }

        return $node;
    }

    /**
     * A step's diff against the step before, its values redacted as the payloads are, cut to MAX_DIFF_ENTRIES.
     */
    private function diffStep($i)
    {
        $step = $this->steps[$i];
        $previous = $this->before;
        for ($p = $i - 1; $p >= 0; --$p) {
            if (isset($this->steps[$p]['payload'])) {
                $previous = $this->steps[$p]['payload'];
                break;
            }
        }
        $diff = TwoOrderPostprocessing::diff($previous, $step['payload']);
        $entries = array();
        foreach (array_slice($diff, 0, TwoOrderPostprocessing::MAX_DIFF_ENTRIES) as $entry) {
            $hide = self::isBuyerDetail($entry['path']);
            $entries[] = array(
                'path' => self::cut($entry['path']),
                'before' => self::shown($entry['before'], $hide),
                'after' => self::shown($entry['after'], $hide),
            );
        }

        return array('subscriber' => $step['subscriber'], 'changes' => count($diff), 'diff' => $entries);
    }

    private static function shown($value, $hide)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if ($hide) {
            return self::REDACTED;
        }
        if (is_array($value)) {
            return self::cut(TwoOrderPostprocessing::canonical($value));
        }

        return is_string($value) ? self::cut($value) : $value;
    }

    private static function cut($value)
    {
        $value = (string) $value;
        if (strlen($value) <= TwoOrderPostprocessing::MAX_STRING) {
            return $value;
        }

        return (function_exists('mb_strcut') ? mb_strcut($value, 0, TwoOrderPostprocessing::MAX_STRING, 'UTF-8') : substr($value, 0, TwoOrderPostprocessing::MAX_STRING)) . '...';
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
