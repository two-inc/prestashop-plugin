<?php

declare(strict_types=1);

/**
 * TWO-26067: the cart-vs-order-lines reconciliation diagnostic names only the
 * figures that actually drifted. A gross-only message printed two identical
 * numbers whenever gross matched but net and tax had shifted between them.
 */
final class ReconciliationDriftMessageSpec
{
    public static function runAll(): void
    {
        self::testDriftDetailNamesOnlyTheFiguresThatDiffer();
    }

    private static function testDriftDetailNamesOnlyTheFiguresThatDiffer(): void
    {
        // [line net, line tax, line gross, cart net, cart gross, reconciles, expected detail, description]
        $cases = [
            [64.00, 12.80, 76.80, 69.03, 76.80, false, 'net cart 69.03 vs order lines 64.00 (difference 5.03); tax cart 7.77 vs order lines 12.80 (difference 5.03)', 'gross matches, net and tax drift'],
            [100.00, 21.00, 121.00, 110.00, 131.00, false, 'gross cart 131.00 vs order lines 121.00 (difference 10.00); net cart 110.00 vs order lines 100.00 (difference 10.00)', 'tax matches, gross and net drift'],
            [100.00, 21.00, 121.00, 100.00, 125.00, false, 'gross cart 125.00 vs order lines 121.00 (difference 4.00); tax cart 25.00 vs order lines 21.00 (difference 4.00)', 'net matches, gross and tax drift'],
            [100.00, 21.00, 121.00, 123.97, 150.00, false, 'gross cart 150.00 vs order lines 121.00 (difference 29.00); net cart 123.97 vs order lines 100.00 (difference 23.97); tax cart 26.03 vs order lines 21.00 (difference 5.03)', 'all three drift'],
            [100.00, 21.00, 121.00, 100.02, 121.02, true, '', 'drift within tolerance names nothing'],
            [100.00, 21.00, 125.00, 100.00, 125.00, false, 'order lines gross 125.00 vs order lines net+tax 121.00', 'order lines failing gross = net + tax'],
        ];

        $method = new ReflectionMethod(Twopayment::class, 'validateTwoOrderReconciliationAgainstCart');
        foreach ($cases as [$lineNet, $lineTax, $lineGross, $cartNet, $cartGross, $reconciles, $expected, $description]) {
            StubStore::reset();
            PrestaShopLogger::reset();
            $module = new TwopaymentTestHarness();

            $cart = new Cart(9601);
            StubStore::$cartProducts[9601] = [['id_product' => 1, 'cart_quantity' => 1]];
            StubStore::$cartTotals[9601] = [
                true => [Cart::BOTH => $cartGross],
                false => [Cart::BOTH => $cartNet],
            ];

            $maxDiffCents = 0;
            $detail = 'unset';
            $args = [$cart, ['net' => $lineNet, 'tax' => $lineTax, 'gross' => $lineGross], 'order create payload', &$maxDiffCents, &$detail];
            $ok = $method->invokeArgs($module, $args);

            TinyAssert::same($reconciles, $ok, $description . ': reconciliation verdict');
            TinyAssert::same($expected, $detail, $description . ': drift detail');
            if ($expected !== '') {
                TinyAssert::true(self::loggedContains($expected), $description . ': the log must carry the same drift detail');
            }
        }
    }

    private static function loggedContains(string $needle): bool
    {
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos((string) $entry['message'], $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
