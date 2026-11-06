<?php

declare(strict_types=1);

/**
 * Discount lines traced to the lines each cart rule discounts (TWO-27129).
 *
 * Each case builds the positive lines of an order, applies its cart rules and compares the discount lines as
 * "name gross/net/tax@rate" in order. A part always carries the rate of the line it discounts, so no case may show a
 * rate that none of the rule's own lines has.
 */
final class CartRuleTracingSpec
{
    public static function runAll(): void
    {
        self::testTracesEachRuleShape();
        self::testPlacedOrderReplayTracesTheStoredRule();
        self::testDiscountLineTakesItsLinesTaxRulesGroup();
        self::testCartDiscountTotalIsSharedByRuleValue();
    }

    private static function testCartDiscountTotalIsSharedByRuleValue(): void
    {
        $two = [['name' => 'A', 'value_real' => 6.00], ['name' => 'B', 'value_real' => 4.00]];
        $cases = [
            [$two, 10.00, 'A 6.00; B 4.00', 'values that add up to the total are kept exactly'],
            [$two, 9.00, 'A 5.40; B 3.60', 'a total below the values is shared by value'],
            [$two, 11.00, 'A 6.60; B 4.40', 'a total above the values is shared by value'],
            [[['name' => 'Ship', 'free_shipping' => 1], ['name' => 'Other']], 10.00, 'Ship (+1 more) 10.00 untraced free_shipping',
                'no rule states a value: one untraced discount that waives shipping first'],
        ];
        $module = new TwopaymentTestHarness();
        $share = new ReflectionMethod(Twopayment::class, 'buildTwoDiscountLinesFromCartRules');
        foreach ($cases as [$rules, $total, $want, $description]) {
            $got = implode('; ', array_map(function ($entry) {
                return trim($entry['descriptor']['name'] . ' ' . number_format($entry['gross'], 2, '.', '')
                    . (empty($entry['rule']['untraced']) ? '' : ' untraced') . (empty($entry['rule']['free_shipping']) ? '' : ' free_shipping'));
            }, $share->invoke($module, $rules, $total)));
            TinyAssert::same($want, $got, $description);
        }
    }

    private static function testDiscountLineTakesItsLinesTaxRulesGroup(): void
    {
        $p1 = ['key' => 'product:1', 'group' => 5, 'discount' => false];
        $p2 = ['key' => 'product:2', 'group' => 5, 'discount' => false];
        $p3 = ['key' => 'product:3', 'group' => 6, 'discount' => false];
        $ship = ['key' => 'shipping', 'group' => 0, 'discount' => false];
        $keyless = ['key' => null, 'group' => 0, 'discount' => true];
        $lines = [$p1, $p2, $p3, $ship];
        $cases = [
            [[0, 1], $p1, 'two products of one group: that group, the first one\'s key'],
            [[1], $p2, 'one product: its own descriptor'],
            [[0, 2], $keyless, 'products of two groups: keyless, for step 4'],
            [[3], $keyless, 'shipping with no one group: keyless'],
        ];
        $module = new TwopaymentTestHarness();
        $keys = new ReflectionProperty(Twopayment::class, 'twoLineTaxKeys');
        $tag = new ReflectionMethod(Twopayment::class, 'tagTwoDiscountLineTaxKey');
        foreach ($cases as [$sources, $want, $description]) {
            $keys->setValue($module, $lines);
            $tag->invoke($module, 4, $sources);
            TinyAssert::same($want, $keys->getValue($module)[4], $description);
        }
    }

    /** A positive payload line. */
    private static function line(string $type, string $gross, string $net, string $rate): array
    {
        return ['type' => $type, 'gross_amount' => $gross, 'net_amount' => $net, 'tax_amount' => number_format((float) $gross - (float) $net, 2, '.', ''), 'tax_rate' => $rate];
    }

    /** A product reference as buildTwoLineItems() records it. */
    private static function ref(int $index, int $idProduct, int $quantity = 1, bool $special = false, int $attribute = 0, int $idOrder = 0): array
    {
        return ['id_product' => $idProduct, 'id_product_attribute' => $attribute, 'reduction_applies' => $special, 'quantity' => $quantity, 'id_order' => $idOrder, 'indexes' => [$index]];
    }

    /** A rule entry, its value already the share it carries. */
    private static function rule(string $name, string $gross, array $shape = []): array
    {
        return ['rule' => ['name' => $name] + $shape, 'gross' => (float) $gross, 'descriptor' => ['name' => $name, 'description' => $name]];
    }

    private static function describe(array $lines): string
    {
        return implode('; ', array_map(function ($entry) {
            $l = $entry['line'];
            return $l['name'] . ' ' . $l['gross_amount'] . '/' . $l['net_amount'] . '/' . $l['tax_amount'] . '@' . $l['tax_rate'];
        }, $lines));
    }

    private static function testTracesEachRuleShape(): void
    {
        // Two products at 21% and 10%, shipping at 0%.
        $mixed = [
            self::line('PHYSICAL', '121.00', '100.00', '0.21'),
            self::line('PHYSICAL', '55.00', '50.00', '0.1'),
            self::line('SHIPPING_FEE', '10.00', '10.00', '0'),
        ];
        $mixedRefs = [self::ref(0, 1), self::ref(1, 2, 5)];
        // Three products at 21% (one of them on special) and shipping at 0%, the shape of the refused cart.
        $three = [
            self::line('PHYSICAL', '379.00', '313.22', '0.21'),
            self::line('PHYSICAL', '125.26', '103.52', '0.21'),
            self::line('PHYSICAL', '39.00', '32.23', '0.21'),
            self::line('SHIPPING_FEE', '32.98', '32.98', '0'),
        ];
        $threeRefs = [self::ref(0, 11), self::ref(1, 12, 2, true), self::ref(2, 13, 3)];
        $twoShipping = [
            self::line('PHYSICAL', '121.00', '100.00', '0.21'),
            self::line('SHIPPING_FEE', '12.10', '10.00', '0.21'),
            self::line('SHIPPING_FEE', '5.50', '5.00', '0.1'),
        ];
        StubStore::$cartRuleRows[70] = ['selected' => ['2-0']];

        $cases = [
            [$mixed, $mixedRefs, [self::rule('Ship', '10.00', ['free_shipping' => 1])],
                'Ship -10.00/-10.00/0.00@0', 'free shipping on a 0% shipping line stays at 0%'],
            [$twoShipping, [self::ref(0, 1)], [self::rule('Ship', '17.60', ['free_shipping' => 1])],
                'Ship (VAT 21.00%) -12.10/-10.00/-2.10@0.21; Ship (VAT 10.00%) -5.50/-5.00/-0.50@0.1', 'free shipping over two shipping rates cancels each line exactly'],
            [$mixed, $mixedRefs, [self::rule('Gift', '11.00', ['gift_product' => 2])],
                'Gift -11.00/-10.00/-1.00@0.1', 'free gift takes one unit of its product at that product\'s rate'],
            [$mixed, $mixedRefs, [self::rule('One', '12.10', ['reduction_percent' => 10, 'reduction_product' => 1])],
                'One -12.10/-10.00/-2.10@0.21', 'percent on one product'],
            [$mixed, $mixedRefs, [self::rule('Cheap', '5.50', ['reduction_percent' => 50, 'reduction_product' => -1])],
                'Cheap -5.50/-5.00/-0.50@0.1', 'percent on the cheapest unit'],
            [$mixed, $mixedRefs, [self::rule('Sel', '5.50', ['reduction_percent' => 10, 'reduction_product' => -2, 'id_cart_rule' => 70])],
                'Sel -5.50/-5.00/-0.50@0.1', 'percent on the selected products'],
            [$mixed, $mixedRefs, [self::rule('Pct', '17.60', ['reduction_percent' => 10])],
                'Pct (VAT 21.00%) -12.10/-10.00/-2.10@0.21; Pct (VAT 10.00%) -5.50/-5.00/-0.50@0.1', 'whole-order percent: each product loses it at its own rate, shipping none'],
            [$mixed, $mixedRefs, [self::rule('Fix', '10.00', ['reduction_amount' => 10, 'reduction_tax' => 1])],
                'Fix (VAT 21.00%) -6.88/-5.69/-1.19@0.21; Fix (VAT 10.00%) -3.12/-2.84/-0.28@0.1', 'whole-order amount tax incl: split by gross, parts sum to the rule exactly'],
            [$mixed, $mixedRefs, [self::rule('FixEx', '9.39', ['reduction_amount' => 8, 'reduction_tax' => 0])],
                'FixEx (VAT 21.00%) -6.46/-5.34/-1.12@0.21; FixEx (VAT 10.00%) -2.93/-2.66/-0.27@0.1', 'whole-order amount tax excl: core grosses each net part up, so gross follows gross'],
            [[self::line('PHYSICAL', '605.00', '500.00', '0.21'), self::line('PHYSICAL', '520.00', '500.00', '0.04')], [self::ref(0, 1), self::ref(1, 2)],
                [self::rule('FixEx4', '112.50', ['reduction_amount' => 100, 'reduction_tax' => 0])],
                'FixEx4 (VAT 21.00%) -60.50/-50.00/-10.50@0.21; FixEx4 (VAT 4.00%) -52.00/-50.00/-2.00@0.04', 'tax-excluded amount over 21% and 4%: the nets core charged, 50.00 each'],
            [$three, $threeRefs, [self::rule('NoSpecial', '41.80', ['reduction_percent' => 10, 'reduction_exclude_special' => 1])],
                'NoSpecial -41.80/-34.55/-7.25@0.21', 'percent excluding discounted products skips the special'],
            [$three, $threeRefs, [self::rule('First', '29.99', ['reduction_amount' => 29.99, 'reduction_tax' => 1]), self::rule('Second', '2.99', ['reduction_amount' => 2.99, 'reduction_tax' => 1])],
                'First -29.99/-24.79/-5.20@0.21; Second -2.99/-2.47/-0.52@0.21', 'refused cart: two whole-order amounts on 21% products give no 0% line'],
            [$three, $threeRefs, [self::rule('Ship', '32.98', ['free_shipping' => 1])],
                'Ship -32.98/-32.98/0.00@0', 'refused cart had it been free shipping: the 0% shipping line, honestly 0%'],
            [$mixed, $mixedRefs, [self::rule('Lost', '17.60', ['untraced' => true])],
                'Lost (VAT 21.00%) -12.10/-10.00/-2.10@0.21; Lost (VAT 10.00%) -5.50/-5.00/-0.50@0.1', 'untraceable rule: whole-order amount over the products, never shipping'],
            [$mixed, $mixedRefs, [self::rule('Odd', '1.00', [])],
                'Odd (VAT 21.00%) -0.69/-0.57/-0.12@0.21; Odd (VAT 10.00%) -0.31/-0.28/-0.03@0.1', 'a rule with no shape is a whole-order amount: largest remainder'],
            [$mixed, $mixedRefs, [self::rule('ShipCent', '10.01', ['free_shipping' => 1])],
                'ShipCent -10.01/-10.01/0.00@0', 'a cent the shape does not explain stays on the lines the rule discounts'],
            [$mixed, $mixedRefs, [self::rule('A', '121.00', ['reduction_amount' => 121, 'reduction_tax' => 1, 'reduction_product' => 1]), self::rule('B', '5.50', ['reduction_percent' => 10])],
                'A -121.00/-100.00/-21.00@0.21; B -5.50/-5.00/-0.50@0.1', 'a later rule weighs what earlier rules left of each line'],
            [[self::line('PHYSICAL', '121.00', '100.00', '0.21'), self::line('PHYSICAL', '11.00', '10.00', '0.1')], [self::ref(0, 1), self::ref(1, 2)],
                [self::rule('Half', '60.50', ['reduction_percent' => 50]), self::rule('Gift', '11.00', ['gift_product' => 2])],
                'Half -60.50/-50.00/-10.50@0.21; Gift -11.00/-10.00/-1.00@0.1', 'a percent ahead of a gift leaves the gift line to the gift'],
            [$mixed, $mixedRefs, [self::rule('Big', '60.00', ['reduction_percent' => 10, 'reduction_product' => 2])],
                'Big (VAT 10.00%) -55.00/-50.00/-5.00@0.1; Big (VAT 21.00%) -5.00/-4.13/-0.87@0.21', 'no line is discounted past its value: the overflow goes over the other products'],
        ];

        $module = new TwopaymentTestHarness();
        $trace = new ReflectionMethod(Twopayment::class, 'buildTwoTracedDiscountLines');
        foreach ($cases as [$items, $refs, $rules, $want, $description]) {
            $got = $trace->invoke($module, $rules, $items, $refs, new Cart());
            TinyAssert::same($want, self::describe($got), $description);
        }
    }

    private static function testPlacedOrderReplayTracesTheStoredRule(): void
    {
        StubStore::reset();
        $items = [
            self::line('PHYSICAL', '121.00', '100.00', '0.21'),
            self::line('PHYSICAL', '55.00', '50.00', '0.1'),
            self::line('SHIPPING_FEE', '10.00', '10.00', '0'),
        ];
        $refs = [self::ref(0, 1, 1, false, 0, 1), self::ref(1, 2, 5, false, 0, 2)];
        StubStore::$cartRuleRows[81] = ['reduction_percent' => 10.0, 'reduction_product' => 2];

        $cases = [
            [[['id_cart_rule' => 81, 'name' => 'Kept', 'value' => '5.50', 'value_tax_excl' => '5.00', 'free_shipping' => '0']],
                'Kept -5.50/-5.00/-0.50@0.1', 'the stored rule is loaded and traced to its product'],
            [[['id_cart_rule' => 99, 'name' => 'Gone', 'value' => '27.60', 'value_tax_excl' => '25.00', 'free_shipping' => '1']],
                'Gone (VAT 0.00%) -10.00/-10.00/0.00@0; Gone (VAT 21.00%) -12.10/-10.00/-2.10@0.21; Gone (VAT 10.00%) -5.50/-5.00/-0.50@0.1',
                'a deleted rule waives the shipping its row records, the rest is a whole-order amount'],
            [[['id_order' => 1, 'id_cart_rule' => 99, 'name' => 'OrderA', 'value' => '12.10', 'value_tax_excl' => '10.00', 'free_shipping' => '0']],
                'OrderA -12.10/-10.00/-2.10@0.21', 'a multi-carrier split: the row discounts its own order\'s products only'],
        ];

        $module = new TwopaymentTestHarness();
        $placed = new ReflectionMethod(Twopayment::class, 'buildTwoPlacedDiscountLines');
        foreach ($cases as [$rows, $want, $description]) {
            TinyAssert::same($want, self::describe($placed->invoke($module, $rows, $items, $refs, new Cart())), $description);
        }
    }
}
