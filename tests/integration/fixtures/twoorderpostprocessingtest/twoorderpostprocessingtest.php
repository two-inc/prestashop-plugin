<?php
/**
 * TEST FIXTURE - NOT PART OF THE SHIPPED TWO MODULE.
 *
 * A merchant handler on actionTwoOrderPostprocessing (TWO-26092), and a working
 * example of one. It records every call and, once armed, applies one named
 * behaviour to the payload. Unarmed it edits nothing, but an enabled handler
 * on the hook makes the module's own default handler stand down (TWO-26274),
 * so dev/ci/install-order-postprocessing-fixture.sh leaves it disabled and the
 * probe enables it only while it runs.
 *
 *   TWO_OPP_TEST_MODE - '' (unarmed), record, resplit, gross_change,
 *                       off_by_cent, stale_totals, stale_subtotals, no_lines,
 *                       outside_carrier_line, outside_carrier_line_checked,
 *                       shop_match, throws, non_array, body_on_cancel
 *   TWO_OPP_TEST_RATE - the rate resplit and outside_carrier_line use when the
 *                       context's shipping_tax_rate is empty (the probe's carrier is "No tax")
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
            case 'shop_match':
                // Opts back in to the module's shop-match checks on the payload as built, and edits nothing.
                Module::getInstanceByName('twopayment')->runTwoShopMatchChecks($params['payload']);

                return;
        }
        $rate = (float) $params['context']['shipping_tax_rate'];
        if ($rate <= 0) {
            $rate = (float) Configuration::get(self::CONFIG_RATE);
        }
        if (empty($params['payload']['line_items'])) {
            if (!empty($params['payload']['tax_subtotals'])) {
                $params['payload'] = self::resplitUntaxedRefund($params['payload'], $rate);
            }

            return;
        }
        if ($mode === 'outside_carrier_line') {
            $params['payload'] = self::addOutsideCarrierLine($params['payload'], $params['context'], $rate);

            return;
        }
        if ($mode === 'outside_carrier_line_checked') {
            // The README opt-in: the cost at the merchant's rate, then the module's checks on the lines it left alone.
            $params['payload'] = self::addOutsideCarrierLine($params['payload'], $params['context'], $rate);
            Module::getInstanceByName('twopayment')->runTwoShopMatchChecks($params['payload'], Twopayment::SHOP_MATCH_LINES);

            return;
        }
        $before = $params['payload'];
        $params['payload'] = self::resplitShipping($params['payload'], $rate);
        self::breakOnPurpose($mode, $params['payload'], $before);
    }

    /**
     * The README example of a handler that owns the shop-match checks: the shop adds a cost to the cart total outside
     * any carrier, so the order lines fall short of it. Send that cost as its own shipping line, VAT-inclusive at the
     * merchant's rate, and keep the totals consistent. An update's shipping line is everything the placed order's
     * total charged beyond its products, so it carries the cost as well: cut it back to the carrier's own shipping,
     * and send the cost as the same line the create sent.
     *
     * @param array $payload
     * @param array $context
     * @param float $rate
     * @return array
     */
    public static function addOutsideCarrierLine(array $payload, array $context, $rate)
    {
        if ($context['request_type'] === 'order_update') {
            $carrier = round((float) $context['order']->total_shipping_tax_incl, 2);
            $gross = 0.0;
            foreach ($payload['line_items'] as $i => $line) {
                if ($line['type'] !== 'SHIPPING_FEE') {
                    continue;
                }
                $gross = round((float) $line['gross_amount'] - $carrier, 2);
                if ($gross > 0 && $carrier > 0) {
                    $payload['line_items'][$i]['gross_amount'] = number_format($carrier, 2, '.', '');
                    $payload['line_items'][$i]['net_amount'] = number_format((float) $line['net_amount'] - $gross, 2, '.', '');
                    $payload['line_items'][$i]['unit_price'] = $payload['line_items'][$i]['net_amount'];
                } elseif ($gross > 0) {
                    unset($payload['line_items'][$i]);
                }
                break;
            }
            $payload['line_items'] = array_values($payload['line_items']);
        } elseif (in_array($context['request_type'], array('order_intent', 'order_create'), true)) {
            $gross = round((float) $context['cart']->getOrderTotal(true, Cart::BOTH) - (float) $payload['gross_amount'], 2);
        } else {
            return $payload;
        }
        if ($gross <= 0) {
            return $payload;
        }
        $net = round($gross / (1 + $rate), 2);
        $payload['line_items'][] = array(
            'name' => 'Delivery', 'description' => '', 'gross_amount' => number_format($gross, 2, '.', ''),
            'net_amount' => number_format($net, 2, '.', ''), 'discount_amount' => '0.00',
            'tax_amount' => number_format($gross - $net, 2, '.', ''), 'tax_class_name' => 'VAT ' . number_format($rate * 100, 2) . '%',
            'tax_rate' => (string) $rate, 'unit_price' => number_format($net, 2, '.', ''), 'quantity' => 1, 'quantity_unit' => 'pcs',
            'image_url' => '', 'product_page_url' => '', 'type' => 'SHIPPING_FEE',
        );

        return Module::getInstanceByName('twopayment')->recomputeTwoOrderTotals($payload);
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
     * The README example, verbatim: re-split a refund's untaxed share, which in this shop is shipping.
     *
     * @param array $payload
     * @param float $rate
     * @return array
     */
    public static function resplitUntaxedRefund(array $payload, $rate)
    {
        $untaxed = 0.0;
        foreach ($payload['tax_subtotals'] as $i => $subtotal) {
            if ((float) $subtotal['tax_rate'] == 0.0 && (float) $subtotal['tax_amount'] == 0.0) {
                $untaxed += (float) $subtotal['taxable_amount'];
                unset($payload['tax_subtotals'][$i]);
            }
        }
        if ($untaxed == 0.0) {
            return $payload;
        }
        $net = round($untaxed / (1 + $rate), 2);
        $tax = round($untaxed - $net, 2);
        foreach ($payload['tax_subtotals'] as &$subtotal) {
            if (abs((float) $subtotal['tax_rate'] - $rate) < 0.000001) {
                $subtotal['taxable_amount'] = number_format((float) $subtotal['taxable_amount'] + $net, 2, '.', '');
                $subtotal['tax_amount'] = number_format((float) $subtotal['tax_amount'] + $tax, 2, '.', '');
                $net = null;
            }
        }
        unset($subtotal);
        if ($net !== null) {
            $payload['tax_subtotals'][] = array(
                'taxable_amount' => number_format($net, 2, '.', ''),
                'tax_amount' => number_format($tax, 2, '.', ''),
                'tax_rate' => number_format($rate, 6, '.', ''),
            );
        }
        $payload['tax_subtotals'] = array_values($payload['tax_subtotals']);

        return $payload;
    }

    /**
     * The README example, verbatim: what is left to refund on one of Two's order lines, its amount less what Two's
     * earlier refunds credited it. Null when the line is not in the context's order_lines or the refunds are unknown.
     *
     * @param array $context
     * @param string $lineId
     * @return float|null
     */
    public static function leftToRefund(array $context, $lineId)
    {
        if (!is_array($context['order_lines']) || !is_array($context['order_refunds'])) {
            return null;
        }
        $left = null;
        foreach ($context['order_lines'] as $line) {
            if ((string) $line['id'] === (string) $lineId) {
                $left = (float) $line['gross_amount'];
            }
        }
        if ($left === null) {
            return null;
        }
        foreach ($context['order_refunds'] as $refund) {
            foreach (isset($refund['line_items']) ? $refund['line_items'] : array() as $refunded) {
                // Each refund line names the order line it credited in prototype_id; Two records refunds as negatives.
                if (isset($refunded['prototype_id']) && (string) $refunded['prototype_id'] === (string) $lineId) {
                    $left -= abs((float) $refunded['gross_amount']);
                }
            }
        }

        return round($left, 2);
    }

    /**
     * Edits applied on top of the re-split. Each is sent as returned: a gross change, and the others, which leave the
     * payload's arithmetic inconsistent for Two's API to judge (TWO-26283).
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
            case 'no_lines':
                $payload['line_items'] = array();

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
