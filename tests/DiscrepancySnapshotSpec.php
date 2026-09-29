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
        self::testTruncatedSectionsCountWhatWasCut();
        self::testGateOutcomesWriteTheExpectedSnapshot();
    }

    /** A product row as products() stores it, delta computed as 1c78e7e did, before ecotax and item rounding. */
    private static function product(float $total, float $totalWt, int $qty, float $declared, float $ecotax = 0.0, float $ecotaxRate = 0.0): array
    {
        return ['qty' => $qty, 'total' => $total, 'total_wt' => $totalWt, 'ecotax' => $ecotax, 'ecotax_rate' => $ecotaxRate,
            'declared_rate' => $declared, 'delta' => round($totalWt - $total * (1 + $declared), 2)];
    }

    private static function testClassifiesEachCartShape(): void
    {
        $clean = [
            'totals' => ['BOTH' => ['incl' => 150.0, 'excl' => 123.97], 'ONLY_SHIPPING' => ['incl' => 0.0, 'excl' => 0.0], 'residual' => ['incl' => 0.0, 'excl' => 0.0]],
            'shipping' => ['id_carrier' => 7, 'carrier_tax_rules_group' => 4, 'delivery_option' => '', 'priced_option' => [5 => '7,']],
            'delivery_options' => [['id_address' => 5, 'key' => '7,', 'carriers' => [['id_carrier' => 7, 'tax_rules_group' => 4]]]],
            'config' => ['PS_ROUND_TYPE' => '2', 'PS_PRICE_ROUND_MODE' => '2', 'currency_precision' => 2],
            'products' => [self::product(100.0, 121.0, 1, 0.21)],
            'overrides' => ['files' => ['Cart' => 'core'], 'methods_overridden' => ['Cart' => [], 'Product' => [], 'Carrier' => []]],
            'hooks' => ['actionProductPriceCalculation' => []],
        ];
        $cartOverride = ['overrides' => ['files' => ['Cart' => 'override/classes/Cart.php'], 'methods_overridden' => ['Cart' => ['getOrderTotal']]]];
        $addedMethodOnly = ['overrides' => ['files' => ['Cart' => 'override/classes/Cart.php']]];
        $priceHook = ['hooks' => ['actionProductPriceCalculation' => ['somepricemodule']]];
        $carrierless = static function (float $incl, float $excl): array {
            return ['totals' => ['ONLY_SHIPPING' => ['incl' => $incl, 'excl' => $excl]], 'shipping' => ['id_carrier' => 0, 'carrier_tax_rules_group' => 0, 'priced_option' => [5 => '0,']]];
        };
        // Core prices ONLY_SHIPPING from the auto-selected option, whatever the cart's own delivery_option says.
        $autoSelectedTaxed = static function ($stored): array {
            return ['totals' => ['ONLY_SHIPPING' => ['incl' => 29.0, 'excl' => 23.97]], 'shipping' => ['id_carrier' => 0, 'carrier_tax_rules_group' => 0, 'delivery_option' => $stored, 'priced_option' => [5 => '7,']]];
        };
        $error = ['error' => 'PrestaShopException', 'code' => 0];
        // A line as core prices it under PS_ROUND_TYPE (1 item, 2 line), PS_PRICE_ROUND_MODE (0 up, 1 down, 2 half up) and currency precision.
        $rounded = static function (array $product, int $roundType, int $roundMode, int $precision) use ($cartOverride): array {
            return array_replace_recursive(['products' => [$product], 'config' => ['PS_ROUND_TYPE' => (string) $roundType, 'PS_PRICE_ROUND_MODE' => (string) $roundMode, 'currency_precision' => $precision]], $cartOverride);
        };

        $cases = [
            // [patch over a clean cart, expected, description]
            [array_replace_recursive(['totals' => ['residual' => ['incl' => 29.0]]], $cartOverride), 'C', 'cost in BOTH but not in ONLY_SHIPPING'],
            [$carrierless(29.0, 29.0), 'A', 'carrier-less shipping with no tax component'],
            [$carrierless(29.0, 23.97), 'B', 'carrier-less shipping carrying tax'],
            [['totals' => ['ONLY_SHIPPING' => ['incl' => 29.0, 'excl' => 23.97]]], 'other', 'taxed shipping behind a carrier group is normal'],
            [array_replace_recursive(['products' => [self::product(100.0, 95.8, 1, 0.21)]], $cartOverride), 'D', 'fixed untaxed amount on a product line, Cart override'],
            [array_replace_recursive(['products' => [self::product(220.0, 241.0, 1, 0.21)]], $cartOverride), 'D', 'TWO-25938 matrix: 120 untaxed on a 100 line at 21%, delta -25.20'],
            [array_replace_recursive(['products' => [self::product(100.0, 95.8, 1, 0.21)]], $priceHook), 'D', 'fixed untaxed amount on a product line, price hook module'],
            [['products' => [self::product(100.0, 95.8, 1, 0.21)]], 'other', 'product delta with no override or hook to explain it'],
            [array_replace_recursive(['products' => [self::product(100.0, 121.01, 1, 0.21)]], $cartOverride), 'other', 'rounding-sized delta is not a product anomaly'],
            [[], 'other', 'a clean cart'],
            [['totals' => ['ONLY_SHIPPING' => ['incl' => $error, 'excl' => $error], 'residual' => ['incl' => 29.0]]], 'other', 'errored ONLY_SHIPPING is not a zero shipping total'],
            [['totals' => ['ONLY_SHIPPING' => ['incl' => 29.0, 'excl' => 23.97]], 'shipping' => ['carrier_tax_rules_group' => $error], 'delivery_options' => [['carriers' => [['tax_rules_group' => $error]]]]], 'other', 'errored carrier group is not a missing one'],
            [['totals' => ['ONLY_SHIPPING' => ['incl' => 29.0, 'excl' => 23.97]], 'shipping' => ['id_carrier' => 0, 'carrier_tax_rules_group' => 0, 'priced_option' => [5 => '3,8,']],
                'delivery_options' => [['id_address' => 5, 'key' => '3,8,', 'carriers' => [['id_carrier' => 3, 'tax_rules_group' => 4], ['id_carrier' => 8, 'tax_rules_group' => 4]]]]],
                'other', 'multi-carrier option leaves id_carrier 0 but its carriers carry a group'],
            // D is material, not a rounding detector: |delta| > max(1% of the line's gross, 1.00).
            [array_replace_recursive(['products' => [self::product(100.0, 119.5, 1, 0.21)]], $cartOverride), 'D', 'delta 1.50 is above 1% of a 119.50 line'],
            [array_replace_recursive(['products' => [self::product(100.0, 120.0, 1, 0.21)]], $cartOverride), 'other', 'delta 1.00 is not above the 1.00 floor'],
            [$rounded(self::product(1002.5, 1212.5, 100, 0.21), 2, 2, 2), 'other', 'materiality floor: 2.50 untaxed on a 1002.50 line, delta -0.53'],
            // Amounts as core 1.7.6.5 Tools::ps_round and Cart::getProducts produce them for the unit net named.
            [$rounded(self::product(64.26, 77.82, 6, 0.21), 1, 2, 2), 'other', 'ROUND_ITEM half-up, NL 21% qty 6, unit net 10.714999: delta 0.07'],
            [$rounded(self::product(101.0, 127.0, 1, 0.25), 2, 2, 0), 'other', 'ROUND_LINE, NOK precision 0, unit net 101.40: delta 0.75'],
            [$rounded(self::product(1020.0, 1233.0, 100, 0.21), 1, 0, 2), 'other', 'ROUND_ITEM PS_ROUND_UP qty 100, unit net 10.19001: delta -1.20'],
            [$rounded(self::product(1080.0, 1308.0, 100, 0.21), 1, 1, 2), 'other', 'ROUND_ITEM PS_ROUND_DOWN qty 100, unit net 10.80999: delta 1.20'],
            [$rounded(self::product(200.0, 242.2, 20, 0.21), 1, 2, 2), 'other', 'ROUND_ITEM half-up qty 20, unit net 10.0042: delta 0.20'],
            [$autoSelectedTaxed(''), 'other', 'empty delivery_option: core prices the auto-selected taxed carrier'],
            [$autoSelectedTaxed([5 => '0,']), 'other', 'stale carrier-less key: core prices the auto-selected taxed carrier'],
            [['shipping' => ['priced_option' => $error], 'totals' => ['ONLY_SHIPPING' => ['incl' => 29.0, 'excl' => 29.0]]], 'other', 'errored priced option'],
            [array_replace_recursive(['products' => [self::product(220.0, 235.0, 2, 0.055, 10.0, 0.2)]], $cartOverride), 'other', 'ecotax under its own group: its 2.90 rate gap alone is above 1% of the line'],
            [array_replace_recursive(['products' => [self::product(100.0, 95.8, 1, 0.21)]], $addedMethodOnly), 'other', 'a Cart override that only adds a method'],
            [['totals' => ['BOTH' => ['incl' => 0.0, 'excl' => 0.0], 'residual' => ['incl' => -20.0]]], 'other', 'stacked vouchers clamp BOTH to 0'],
            [['totals' => ['ONLY_SHIPPING' => ['incl' => 5.0, 'excl' => 4.13], 'ONLY_DISCOUNTS' => ['incl' => 5.0, 'excl' => 4.13]]], 'other', 'free-shipping discount'],
        ];
        foreach ($cases as [$patch, $expected, $description]) {
            $actual = TwoDiscrepancySnapshot::classify(array_replace_recursive($clean, $patch));
            TinyAssert::same($expected, $actual, 'classify: ' . $description . ' - expected ' . $expected . ', got ' . $actual);
        }
        TinyAssert::same('other', TwoDiscrepancySnapshot::classify([]), 'classify: an empty snapshot');
        TinyAssert::same('other', TwoDiscrepancySnapshot::classify(['totals' => $error]), 'classify: a section that raised');
    }

    private static function testEncodeStaysUnderTheLimitAndDecodesBack(): void
    {
        $row = ['id_product' => 1, 'total' => 47.8, 'name' => str_repeat('é', 200)];
        $tagged = ['id_product' => 1, 'total' => 47.8, 'name' => 'Kabel <3m> & "Stecker" \'rot\' \\ /'];
        $cases = [
            // [snapshot, expect products kept, description]
            [['v' => 1, 'shape' => 'A', 'products' => [$row]], true, 'a small snapshot is kept whole, floats unmangled'],
            [['v' => 1, 'shape' => 'A', 'products' => array_fill(0, 2000, $row)], false, 'an oversized section is dropped, not the record'],
            [['v' => 1, 'shape' => 'A', 'products' => [$tagged]], true, 'a name with < survives core strip_tags'],
        ];
        foreach ($cases as [$snapshot, $kept, $description]) {
            $json = TwoDiscrepancySnapshot::encode($snapshot);
            TinyAssert::true(strlen(addslashes($json)) <= TwoDiscrepancySnapshot::MAX_BYTES, 'encode: fits the limit even pSQL()-escaped - ' . $description);
            // What core stores: strip_tags() on 8 and 9, and after addslashes() on 1.7.
            foreach (['8/9' => strip_tags($json), '1.7' => strip_tags(addslashes($json))] as $version => $stored) {
                $decoded = json_decode((string) TwoDiscrepancySnapshot::decodeStored($stored), true);
                TinyAssert::same('A', $decoded['shape'] ?? null, 'encode: decodes back on ' . $version . ' - ' . $description);
                TinyAssert::same($kept ? $snapshot['products'][0]['name'] : null, $decoded['products'][0]['name'] ?? null, 'encode: name byte-for-byte on ' . $version . ' - ' . $description);
            }
            if ($kept) {
                TinyAssert::true(strpos($json, '47.8,') !== false, 'encode: ' . $description);
            }
        }
        TinyAssert::same(null, TwoDiscrepancySnapshot::decodeStored('TwoPayment: not json'), 'decodeStored: a plain log line');
    }

    private static function testTruncatedSectionsCountWhatWasCut(): void
    {
        $cases = [
            // [rows per section, expected truncated, description]
            [TwoDiscrepancySnapshot::MAX_ROWS, null, 'at the cap nothing is cut'],
            [TwoDiscrepancySnapshot::MAX_ROWS + 5, ['products' => 5, 'cart_rules' => 5, 'sent_line_items' => 5], 'over the cap each section counts its cut rows'],
        ];
        foreach ($cases as $i => [$rows, $expected, $description]) {
            StubStore::reset();
            $cart = new Cart(9500 + $i);
            StubStore::$cartProducts[$cart->id] = array_fill(0, $rows, ['id_product' => 1, 'total' => 1.0, 'total_wt' => 1.21, 'cart_quantity' => 1]);
            StubStore::$cartRules[$cart->id] = array_fill(0, $rows, ['id_cart_rule' => 1]);
            $snapshot = TwoDiscrepancySnapshot::build($cart, null, array_fill(0, $rows, ['type' => 'PHYSICAL']), static function (): float {
                return 0.21;
            }, null);
            TinyAssert::same($expected, $snapshot['truncated'] ?? null, 'truncated: ' . $description);
            foreach (['products', 'cart_rules', 'sent_line_items'] as $section) {
                TinyAssert::count(min($rows, TwoDiscrepancySnapshot::MAX_ROWS), $snapshot[$section], 'rows kept in ' . $section . ': ' . $description);
            }
        }
    }

    /** DefaultShippingTaxCodeSpec's cart fixtures, reused rather than copied. */
    private static function fixture(string $method, ...$args)
    {
        $reflection = new ReflectionMethod('DefaultShippingTaxCodeSpec', $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$args);
    }

    /** A harness whose line formulas or tax subtotals fail, or whose line build throws, to reach the gates no cart fixture can. */
    private static function harness($fault): TwopaymentTestHarness
    {
        return new class ($fault) extends TwopaymentTestHarness {
            private $fault;

            public function __construct($fault)
            {
                parent::__construct();
                $this->fault = $fault;
            }

            public function getTwoProductItems($cart)
            {
                if ($this->fault instanceof Throwable) {
                    throw $this->fault;
                }

                return $this->fault === 'noLines' ? [] : parent::getTwoProductItems($cart);
            }

            public function validateTwoLineItems($line_items)
            {
                return $this->fault !== 'badFormulas' && parent::validateTwoLineItems($line_items);
            }

            public function getTwoTaxSubtotals($line_items)
            {
                $subtotals = parent::getTwoTaxSubtotals($line_items);
                if ($this->fault === 'badSubtotals') {
                    $subtotals[0]['taxable_amount'] += 5.0;
                }

                return $subtotals;
            }
        };
    }

    private static function testGateOutcomesWriteTheExpectedSnapshot(): void
    {
        $cases = [
            // [default shipping group, debug, product total_wt, cart BOTH incl, harness fault, expected gate (null none, '' baseline), severity, has sent lines, description]
            ['', '0', 121.00, 150.00, null, 'shipping_rate_unresolvable', 3, false, 'carrier-less shipping refused'],
            ['4210', '0', 121.00, 150.00, null, null, 0, false, 'shipping gate caught by the Default shipping tax code is not a failure'],
            ['4210', '1', 121.00, 150.00, null, '', 1, true, 'debug mode leaves a baseline for a passing cart'],
            ['4210', '0', 131.00, 160.00, null, 'declared_rate', 3, false, 'product tax contradicts its declared rate'],
            ['4210', '0', 121.00, 170.00, null, 'reconciliation', 3, true, 'order lines do not reconcile with the cart total'],
            ['4210', '0', 121.00, 150.00, 'badFormulas', 'line_formulas', 3, true, 'line item formulas do not hold'],
            ['4210', '0', 121.00, 150.00, 'badSubtotals', 'tax_subtotals', 3, true, 'tax subtotals do not reconcile with the lines'],
            ['4210', '0', 121.00, 150.00, 'wrapping', 'Exception', 3, false, 'gift wrapping gross below net, after a fallback-caught shipping gate'],
            ['4210', '0', 121.00, 150.00, new Exception('Discount amounts diverge from all declared cart tax rates'), 'Exception', 3, false, 'discount diverges from every declared rate'],
            ['4210', '0', 121.00, 150.00, new Exception('Cannot attribute shipping tax under PS_ATCP_SHIPWRAP: no product rate classes'), 'Exception', 3, false, 'PS_ATCP_SHIPWRAP shipping with no product rate class'],
            ['4210', '0', 121.00, 150.00, new Exception('Cannot reconcile gift wrapping tax under PS_ATCP_SHIPWRAP with canonical rates'), 'Exception', 3, false, 'PS_ATCP_SHIPWRAP wrapping residual beyond tolerance'],
            ['4210', '0', 121.00, 150.00, 'noLines', null, 0, false, 'a cart with no valid line items is not a discrepancy'],
        ];
        foreach ($cases as $i => [$group, $debug, $productGross, $cartGross, $harness, $gate, $severity, $hasLines, $description]) {
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
            if ($harness === 'wrapping') {
                StubStore::$cartTotals[$id][true][Cart::ONLY_WRAPPING] = 5.00;
                StubStore::$cartTotals[$id][false][Cart::ONLY_WRAPPING] = 10.00;
            }

            $caught = null;
            try {
                self::harness($harness)->getTwoNewOrderData('attempt-' . $id, $cart, self::fixture('merchantUrls'));
            } catch (Exception $e) {
                // Refusals are expected; the snapshot row is what is asserted.
                $caught = $e;
            }
            if ($harness instanceof Throwable) {
                TinyAssert::true($caught === $harness, 'the same exception is rethrown: ' . $description);
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
            if ($caught !== null) {
                TinyAssert::false(strpos($rows[0]['message'], $caught->getMessage()) !== false, 'no exception message: ' . $description);
            }
            foreach (['buyer@example.com', 'Calle Uno', '28001', '666666601', 'Pia'] as $pii) {
                TinyAssert::false(strpos($rows[0]['message'], $pii) !== false, 'no buyer PII "' . $pii . '": ' . $description);
            }
        }
        Configuration::updateValue('PS_TWO_DEBUG_MODE', '0');
    }
}
