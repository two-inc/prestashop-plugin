<?php

declare(strict_types=1);

/**
 * TWO-26064 - the discrepancy snapshot: its decision tree, its size and PII
 * limits, and which gate outcomes write one. The real-engine counterpart is
 * tests/integration/discrepancy-snapshot.php.
 */
final class DiscrepancySnapshotSpec
{
    public static function runAll(): void
    {
        self::testClassifiesEachCartShape();
        self::testEncodeStaysUnderTheLimitAndDecodesBack();
        self::testGateOutcomesWriteTheExpectedSnapshot();
    }

    /** A snapshot carrying only what classify() reads. */
    private static function shape(array $ship, float $residual, int $carrierGroup, float $delta, array $overrides, array $hooks): array
    {
        return [
            'totals' => ['ONLY_SHIPPING' => ['incl' => $ship[0], 'excl' => $ship[1]], 'residual' => ['incl' => $residual, 'excl' => $residual]],
            'shipping' => ['carrier_tax_rules_group' => $carrierGroup],
            'products' => [['delta' => $delta]],
            'overrides' => $overrides,
            'hooks' => $hooks,
        ];
    }

    private static function testClassifiesEachCartShape(): void
    {
        $core = ['files' => ['Cart' => 'core'], 'cart_methods_added' => [], 'cart_methods_overridden' => []];
        $cartOverride = ['files' => ['Cart' => 'override/classes/Cart.php'], 'cart_methods_added' => [], 'cart_methods_overridden' => ['getOrderTotal']];
        $noHooks = ['actionProductPriceCalculation' => []];
        $priceHook = ['actionProductPriceCalculation' => ['somepricemodule']];

        $cases = [
            // [ship incl/excl, residual, carrier group, product delta, overrides, hooks, expected, description]
            [[0.0, 0.0], 29.0, 0, 0.0, $cartOverride, $noHooks, 'C', 'cost in BOTH but not in ONLY_SHIPPING'],
            [[29.0, 29.0], 0.0, 0, 0.0, $core, $noHooks, 'A', 'carrier-less shipping with no tax component'],
            [[29.0, 23.97], 0.0, 0, 0.0, $core, $noHooks, 'B', 'carrier-less shipping carrying tax'],
            [[29.0, 23.97], 0.0, 7, 0.0, $core, $noHooks, 'other', 'taxed shipping behind a carrier group is normal'],
            [[5.0, 4.13], 0.0, 7, -25.2, $cartOverride, $noHooks, 'D', 'fixed untaxed amount on a product line, Cart override'],
            [[5.0, 4.13], 0.0, 7, -25.2, $core, $priceHook, 'D', 'fixed untaxed amount on a product line, price hook module'],
            [[5.0, 4.13], 0.0, 7, -25.2, $core, $noHooks, 'other', 'product delta with no override or hook to explain it'],
            [[5.0, 4.13], 0.0, 7, 0.01, $cartOverride, $noHooks, 'other', 'rounding-sized delta is not a product anomaly'],
            [[0.0, 0.0], 0.0, 0, 0.0, $core, $noHooks, 'other', 'a clean cart'],
        ];
        foreach ($cases as [$ship, $residual, $group, $delta, $overrides, $hooks, $expected, $description]) {
            $actual = TwoDiscrepancySnapshot::classify(self::shape($ship, $residual, $group, $delta, $overrides, $hooks));
            TinyAssert::same($expected, $actual, 'classify: ' . $description . ' - expected ' . $expected . ', got ' . $actual);
        }
        TinyAssert::same('other', TwoDiscrepancySnapshot::classify([]), 'classify: an empty snapshot');
        TinyAssert::same('other', TwoDiscrepancySnapshot::classify(['totals' => ['error' => 'boom']]), 'classify: a section that raised');
    }

    private static function testEncodeStaysUnderTheLimitAndDecodesBack(): void
    {
        $row = ['id_product' => 1, 'total' => 47.8, 'name' => str_repeat('é', 200)];
        $cases = [
            // [snapshot, expect products kept, description]
            [['v' => 1, 'shape' => 'A', 'products' => [$row]], true, 'a small snapshot is kept whole, floats unmangled'],
            [['v' => 1, 'shape' => 'A', 'products' => array_fill(0, 2000, $row)], false, 'an oversized section is dropped, not the record'],
        ];
        foreach ($cases as [$snapshot, $kept, $description]) {
            $json = TwoDiscrepancySnapshot::encode($snapshot);
            TinyAssert::true(strlen(addslashes($json)) <= TwoDiscrepancySnapshot::MAX_BYTES, 'encode: fits the limit even pSQL()-escaped - ' . $description);
            foreach ([$json, addslashes($json)] as $stored) {
                $decoded = json_decode((string) TwoDiscrepancySnapshot::decodeStored($stored), true);
                TinyAssert::same('A', $decoded['shape'] ?? null, 'encode: decodes back, raw or escaped - ' . $description);
            }
            $decoded = json_decode($json, true);
            TinyAssert::same($kept, isset($decoded['products'][0]['id_product']), 'encode: ' . $description);
            if ($kept) {
                TinyAssert::true(strpos($json, '47.8,') !== false, 'encode: ' . $description);
            }
        }
        TinyAssert::same(null, TwoDiscrepancySnapshot::decodeStored('TwoPayment: not json'), 'decodeStored: a plain log line');
    }

    /** DefaultShippingTaxCodeSpec's cart fixtures, reused rather than copied. */
    private static function fixture(string $method, ...$args)
    {
        $reflection = new ReflectionMethod('DefaultShippingTaxCodeSpec', $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$args);
    }

    private static function testGateOutcomesWriteTheExpectedSnapshot(): void
    {
        $cases = [
            // [default shipping group, debug, product total_wt, cart BOTH incl, expected gate (null none, '' baseline), severity, has sent lines, description]
            ['', '0', 121.00, 150.00, 'shipping_rate_unresolvable', 3, false, 'carrier-less shipping refused'],
            ['4210', '0', 121.00, 150.00, null, 0, false, 'shipping gate caught by the Default shipping tax code is not a failure'],
            ['4210', '1', 121.00, 150.00, '', 1, true, 'debug mode leaves a baseline for a passing cart'],
            ['4210', '0', 131.00, 160.00, 'declared_rate', 3, false, 'product tax contradicts its declared rate'],
            ['4210', '0', 121.00, 170.00, 'reconciliation', 3, true, 'order lines do not reconcile with the cart total'],
        ];
        foreach ($cases as $i => [$group, $debug, $productGross, $cartGross, $gate, $severity, $hasLines, $description]) {
            StubStore::reset();
            PrestaShopLogger::reset();
            StubStore::$taxRulesGroups[4210] = ['name' => 'IVA 21%', 'active' => 1];
            StubStore::$taxRuleRates[4210] = 21.0;
            Configuration::updateValue('PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP', $group);
            Configuration::updateValue('PS_TWO_DEBUG_MODE', $debug);
            $id = 9400 + $i;
            $cart = self::fixture('seedCarrierlessCart', $id, $id + 10, $id + 20);
            self::fixture('seedTotalsFor21PercentShipping', $id);
            StubStore::$cartProducts[$id][0]['total_wt'] = $productGross;
            StubStore::$cartTotals[$id][true][Cart::BOTH] = $cartGross;

            try {
                (new TwopaymentTestHarness())->getTwoNewOrderData('attempt-' . $id, $cart, self::fixture('merchantUrls'));
            } catch (Exception $e) {
                // Refusals are expected; the snapshot row is what is asserted.
            }

            $rows = array_values(array_filter(PrestaShopLogger::$logs, static function (array $entry): bool {
                return ($entry['object_type'] ?? null) === TwoDiscrepancySnapshot::LOG_OBJECT_TYPE;
            }));
            TinyAssert::count($gate === null ? 0 : 1, $rows, 'snapshot rows: ' . $description);
            if ($gate === null) {
                continue;
            }
            $snapshot = json_decode($rows[0]['message'], true);
            TinyAssert::same($id, $rows[0]['object_id'], 'object_id is the cart: ' . $description);
            TinyAssert::same($severity, $rows[0]['severity'], 'severity: ' . $description);
            TinyAssert::same($gate === '' ? null : $gate, $snapshot['gate']['name'] ?? null, 'gate: ' . $description);
            TinyAssert::same($hasLines, is_array($snapshot['sent_line_items']), 'sent line items: ' . $description);
            foreach (['buyer@example.com', 'Calle Uno', '28001', '666666601', 'Pia'] as $pii) {
                TinyAssert::false(strpos($rows[0]['message'], $pii) !== false, 'no buyer PII "' . $pii . '": ' . $description);
            }
        }
        Configuration::updateValue('PS_TWO_DEBUG_MODE', '0');
    }
}
