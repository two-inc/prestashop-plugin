<?php

declare(strict_types=1);

/**
 * TWO-26117 - the shipping tax rate control (the Default shipping tax code,
 * switched on by `twopayment:shipping-tax-fallback`) and the postprocessing
 * hook are orthogonal.
 *
 * A carrier that declares a tax rules group provides the rate, 0% included,
 * and it is sent as is with no plugin check, whatever the control says. A line
 * no carrier provides a rate for ("No tax", or no carrier at all) takes the
 * control's rate and is checked against its tax; with the control blank it is
 * sent at 0% with the tax it was charged. So with the control blank the plugin
 * never refuses on shipping tax. Placement records which case it was, for
 * updates and refunds (PlacedOrderUpdateSpec, RefundSpec).
 *
 * One row per cell of that table, plus carrier-less rows. The cart is
 * OrderPostprocessingSpec's: a 100.00 product at 21% and 29.00 of shipping,
 * taxed at 21% (23.97 + 5.03) or untaxed.
 */
final class ShippingRateControlSpec
{
    private const CART = 9701;
    private const CARRIER_GROUP = 7721;
    private const TEN_PERCENT_GROUP = 7731;
    private const REFUSED = 'Declared tax rate diverges from applied tax amounts for shipping';

    public static function runAll(): void
    {
        $failures = [];
        foreach (self::rows() as [$carrier, $taxed, $control, $expected, $provided, $description]) {
            try {
                self::assertRow($carrier, $taxed, $control, $expected, $provided, $description);
            } catch (Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }
        if ($failures !== []) {
            throw new RuntimeException(count($failures) . " row(s) failed:\n  - " . implode("\n  - ", $failures));
        }
    }

    /**
     * carrier: '21%' a group at 21%, '0%' a group at 0%, 'no tax' group 0, 'none' no carrier.
     * control: null blank, else the Default shipping tax code's rate.
     * expected: [tax_rate, net, tax] of the shipping line, or 'refused'.
     * provided: the placement record's shipping_rate_provided, null when refused.
     *
     * @return array<int,array{0:string,1:bool,2:?int,3:array|string,4:?bool,5:string}>
     */
    private static function rows(): array
    {
        return [
            ['21%', true, null, ['0.21', '23.97', '5.03'], true, 'provided rate that reconciles, control blank: sent as is'],
            ['21%', false, null, ['0.21', '29.00', '0.00'], true, 'provided rate that does not reconcile, control blank: sent as is, not refused'],
            ['0%', true, null, ['0', '23.97', '5.03'], true, 'explicit 0% rate on taxed shipping, control blank: sent as is, not refused'],
            ['21%', false, 10, ['0.21', '29.00', '0.00'], true, 'provided rate that does not reconcile, control populated: sent as is, the control unused'],
            ['0%', false, 21, ['0', '29.00', '0.00'], true, 'explicit 0% rate, control populated: the 0% is provided, the control unused'],
            ['no tax', true, null, ['0', '23.97', '5.03'], false, '"No tax" carrier on taxed shipping, control blank: 0% with the tax charged, not refused'],
            ['no tax', false, null, ['0', '29.00', '0.00'], false, '"No tax" carrier on untaxed shipping, control blank: 0%'],
            ['no tax', true, 21, ['0.21', '23.97', '5.03'], false, '"No tax" carrier, control populated and reconciling: the control\'s rate'],
            ['no tax', false, 21, 'refused', null, '"No tax" carrier on untaxed shipping, control at 21%: refused, whatever the line\'s tax'],
            ['none', true, null, ['0', '23.97', '5.03'], false, 'carrier-less, control blank: 0% with the tax charged, not refused'],
            ['none', true, 21, ['0.21', '23.97', '5.03'], false, 'carrier-less, control populated and reconciling: the control\'s rate'],
            ['none', true, 10, 'refused', null, 'carrier-less, control populated and not reconciling: refused'],
        ];
    }

    private static function assertRow(string $carrier, bool $taxed, ?int $control, $expected, ?bool $provided, string $description): void
    {
        OrderPostprocessingSpec::seed($taxed, $carrier === 'no tax');
        if ($carrier === '0%') {
            StubStore::$taxRuleRates[self::CARRIER_GROUP] = 0.0;
        }
        if ($carrier === 'none') {
            StubStore::$carts[self::CART]['id_carrier'] = 0;
            unset(StubStore::$cartDeliveryOptionLists[self::CART]);
        }
        StubStore::$taxRuleRates[self::TEN_PERCENT_GROUP] = 10.0;
        StubStore::$taxRulesGroups[self::TEN_PERCENT_GROUP] = ['name' => 'VAT 10%', 'active' => 1];
        if ($control !== null) {
            Configuration::updateValue('PS_TWO_SHIPPING_TAX_FALLBACK_ENABLED', '1');
            Configuration::updateValue('PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP', (string) ($control === 21 ? self::CARRIER_GROUP : self::TEN_PERCENT_GROUP));
        }
        $module = new TwopaymentTestHarness();

        try {
            $payload = $module->getTwoNewOrderData('merchant-attempt-' . self::CART, new Cart(self::CART), [
                'merchant_confirmation_url' => 'https://shop.local/confirm',
                'merchant_cancel_order_url' => 'https://shop.local/cancel',
                'merchant_edit_order_url' => '',
                'merchant_order_verification_failed_url' => '',
                'merchant_invoice_url' => '',
                'merchant_shipping_document_url' => '',
            ]);
        } catch (Exception $e) {
            $outcome = strpos($e->getMessage(), self::REFUSED) === 0 ? 'refused' : 'threw ' . $e->getMessage();
            TinyAssert::same($expected, $outcome, $description . ' (got: ' . $outcome . ')');

            return;
        }
        $shipping = [];
        foreach ($payload['line_items'] as $line) {
            if ($line['type'] === 'SHIPPING_FEE') {
                $shipping[] = [(string) $line['tax_rate'], (string) $line['net_amount'], (string) $line['tax_amount']];
            }
        }
        TinyAssert::same([$expected], $shipping, $description . ' (got: ' . json_encode($shipping) . ')');
        $record = json_decode((string) $module->getTwoDeclaredChargeRates(), true);
        TinyAssert::same($provided, $record['shipping_rate_provided'] ?? null, $description . ': the placement record');
    }
}
