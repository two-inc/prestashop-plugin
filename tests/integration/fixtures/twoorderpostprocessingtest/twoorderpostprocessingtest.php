<?php
/**
 * TEST FIXTURE - NOT PART OF THE SHIPPED TWO MODULE.
 *
 * A subscriber on actionTwoOrderPostprocessing (TWO-26092), and a working
 * example of one. It records every call and, once armed, applies one named
 * behaviour to the payload. Inert until TWO_OPP_TEST_MODE is set, so merely
 * installing it changes nothing.
 *
 *   TWO_OPP_TEST_MODE - '' (inert), record, resplit, gross_change,
 *                       off_by_cent, stale_totals, stale_subtotals, throws,
 *                       non_array, body_on_cancel
 *   TWO_OPP_TEST_RATE - the rate resplit uses when the context's
 *                       shipping_tax_rate is empty (the probe's carrier is "No tax")
 *
 * @see tests/integration/order-postprocessing-hook.php
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Twoorderpostprocessingtest extends Module
{
    const CONFIG_MODE = 'TWO_OPP_TEST_MODE';
    const CONFIG_RATE = 'TWO_OPP_TEST_RATE';

    /** @var array<int,array{context:array,payload_in:mixed,payload_out:mixed}> every call in this process */
    public static $calls = array();

    public function __construct()
    {
        $this->name = 'twoorderpostprocessingtest';
        $this->tab = 'others';
        $this->version = '1.0.0';
        $this->author = 'Two';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7.6.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = 'Two order postprocessing test fixture';
        $this->description = 'Test fixture. Subscribes to actionTwoOrderPostprocessing. Inert until '
            . self::CONFIG_MODE . ' is set.';
    }

    public function install()
    {
        return parent::install() && $this->registerHook('actionTwoOrderPostprocessing');
    }

    /**
     * `$params['payload']` is a reference slot: writing through it changes what Two receives.
     *
     * @param array $params
     * @return void
     */
    public function hookActionTwoOrderPostprocessing($params)
    {
        $mode = (string) Configuration::get(self::CONFIG_MODE);
        if ($mode === '') {
            return;
        }
        $context = $params['context'];
        // Objects do not survive a var_export; the probe asserts their class.
        $recorded = $context;
        foreach (array('cart', 'order') as $key) {
            $recorded[$key] = is_object($context[$key]) ? get_class($context[$key]) : $context[$key];
        }
        self::$calls[] = array('context' => $recorded, 'payload_in' => $params['payload']);
        $this->apply($mode, $params);
        self::$calls[count(self::$calls) - 1]['payload_out'] = $params['payload'];
    }

    private function apply($mode, array &$params)
    {
        switch ($mode) {
            case 'record':
                return;
            case 'throws':
                throw new RuntimeException('fixture subscriber failure');
            case 'non_array':
                $params['payload'] = null;

                return;
            case 'body_on_cancel':
                if ($params['context']['request_type'] === 'cancel') {
                    $params['payload']['reason'] = 'added';
                }

                return;
        }
        if (empty($params['payload']['line_items'])) {
            return;
        }
        $rate = (float) $params['context']['shipping_tax_rate'];
        if ($rate <= 0) {
            $rate = (float) Configuration::get(self::CONFIG_RATE);
        }
        $before = $params['payload'];
        $params['payload'] = self::resplitShipping($params['payload'], $rate);
        self::breakOnPurpose($mode, $params['payload'], $before);
    }

    /**
     * The README example, verbatim: re-split shipping the shop recorded untaxed, and keep the totals consistent.
     *
     * @param array $payload
     * @param float $rate
     * @return array
     */
    public static function resplitShipping(array $payload, $rate)
    {
        foreach ($payload['line_items'] as &$line) {
            if ($line['type'] !== 'SHIPPING_FEE' || (float) $line['tax_amount'] != 0.0) {
                continue;
            }
            $gross = (float) $line['gross_amount'];
            $net = round($gross / (1 + $rate), 2);
            $line['net_amount'] = number_format($net, 2, '.', '');
            $line['tax_amount'] = number_format($gross - $net, 2, '.', '');
            $line['unit_price'] = $line['net_amount'];
            $line['tax_rate'] = (string) $rate;
            $line['tax_class_name'] = 'VAT ' . number_format($rate * 100, 2) . '%';
        }
        unset($line);

        return Module::getInstanceByName('twopayment')->recomputeTwoOrderTotals($payload);
    }

    /**
     * The broken subscribers the gates must catch, applied on top of the re-split.
     *
     * @param string $mode
     * @param array $payload
     * @param array $before the payload the subscriber was given
     * @return void
     */
    public static function breakOnPurpose($mode, array &$payload, array $before)
    {
        switch ($mode) {
            case 'off_by_cent':
                foreach ($payload['line_items'] as &$line) {
                    if ($line['type'] === 'SHIPPING_FEE') {
                        $line['net_amount'] = number_format((float) $line['net_amount'] - 0.01, 2, '.', '');
                        $line['unit_price'] = $line['net_amount'];
                    }
                }
                unset($line);

                return;
            case 'stale_totals':
                foreach (array('net_amount', 'tax_amount', 'gross_amount', 'tax_subtotals') as $key) {
                    if (array_key_exists($key, $before)) {
                        $payload[$key] = $before[$key];
                    }
                }

                return;
            case 'stale_subtotals':
                $payload['tax_subtotals'] = isset($before['tax_subtotals']) ? $before['tax_subtotals'] : null;

                return;
            case 'gross_change':
                $payload['line_items'][] = array(
                    'name' => 'Handling', 'description' => '', 'gross_amount' => '12.10', 'net_amount' => '10.00',
                    'discount_amount' => '0.00', 'tax_amount' => '2.10', 'tax_class_name' => 'VAT 21.00%',
                    'tax_rate' => '0.21', 'unit_price' => '10.00', 'quantity' => 1, 'quantity_unit' => 'item',
                    'image_url' => '', 'product_page_url' => '', 'type' => 'SERVICE',
                );
                $payload = Module::getInstanceByName('twopayment')->recomputeTwoOrderTotals($payload);

                return;
        }
    }
}
