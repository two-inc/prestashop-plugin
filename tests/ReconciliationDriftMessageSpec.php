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
        self::testBlockedOrderNamesTheKindOfMismatch();
    }

    private static function testDriftDetailNamesOnlyTheFiguresThatDiffer(): void
    {
        // [line net, line tax, line gross, cart net, cart gross, lines summed, reconciles, expected detail, description]
        $cases = [
            [64.00, 12.80, 76.80, 69.03, 76.80, 1, false, 'net cart 69.03 vs order lines 64.00 (difference 5.03); tax cart 7.77 vs order lines 12.80 (difference 5.03)', 'gross matches, net and tax drift'],
            [100.00, 21.00, 121.00, 110.00, 131.00, 1, false, 'gross cart 131.00 vs order lines 121.00 (difference 10.00); net cart 110.00 vs order lines 100.00 (difference 10.00)', 'tax matches, gross and net drift'],
            [100.00, 21.00, 121.00, 100.00, 125.00, 1, false, 'gross cart 125.00 vs order lines 121.00 (difference 4.00); tax cart 25.00 vs order lines 21.00 (difference 4.00)', 'net matches, gross and tax drift'],
            [100.00, 21.00, 121.00, 123.97, 150.00, 1, false, 'gross cart 150.00 vs order lines 121.00 (difference 29.00); net cart 123.97 vs order lines 100.00 (difference 23.97); tax cart 26.03 vs order lines 21.00 (difference 5.03)', 'all three drift'],
            [100.00, 21.00, 121.00, 100.02, 121.02, 1, true, '', 'drift within tolerance names nothing'],
            [100.00, 21.00, 121.00, 95.00, 115.00, 1, false, 'gross cart 115.00 vs order lines 121.00 (difference 6.00); net cart 95.00 vs order lines 100.00 (difference 5.00); tax cart 20.00 vs order lines 21.00 (difference 1.00)', 'negative drift, order lines above the cart'],
            [100.00, 21.00, 121.00, 100.03, 121.03, 1, false, 'gross cart 121.03 vs order lines 121.00 (difference 0.03); net cart 100.03 vs order lines 100.00 (difference 0.03)', 'exactly 0.03, just outside tolerance'],
            [100.00, 21.00, 121.00, 100.02, 121.03, 1, false, 'gross cart 121.03 vs order lines 121.00 (difference 0.03)', 'net at 0.02 is within tolerance, gross at 0.03 is not'],
            // The tolerance is max(0.02, (lines + 1) x 0.005), as the WooCommerce and Magento plugins have it (TWO-26340).
            [100.00, 21.00, 121.00, 100.02, 121.02, 3, true, '', 'three lines: 0.02 is within tolerance'],
            [100.00, 21.00, 121.00, 100.03, 121.03, 3, false, 'gross cart 121.03 vs order lines 121.00 (difference 0.03); net cart 100.03 vs order lines 100.00 (difference 0.03)', 'three lines: 0.03 is outside tolerance'],
            [100.00, 21.00, 121.00, 100.03, 121.03, 5, true, '', 'five lines: 0.03 is within tolerance'],
            [100.00, 21.00, 121.00, 100.04, 121.04, 7, true, '', 'seven lines: 0.04 is within tolerance'],
            [100.00, 21.00, 121.00, 100.05, 121.05, 7, false, 'gross cart 121.05 vs order lines 121.00 (difference 0.05); net cart 100.05 vs order lines 100.00 (difference 0.05)', 'seven lines: 0.05 is outside tolerance'],
        ];

        $method = new ReflectionMethod(Twopayment::class, 'validateTwoOrderReconciliationAgainstCart');
        foreach ($cases as [$lineNet, $lineTax, $lineGross, $cartNet, $cartGross, $lineCount, $reconciles, $expected, $description]) {
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
            $args = [$cart, ['net' => $lineNet, 'tax' => $lineTax, 'gross' => $lineGross], 'order create payload', &$maxDiffCents, &$detail, null, $lineCount];
            $ok = $method->invokeArgs($module, $args);

            TinyAssert::same($reconciles, $ok, $description . ': reconciliation verdict');
            TinyAssert::same($expected, $detail, $description . ': drift detail');
            if ($expected !== '') {
                TinyAssert::true(self::loggedContains($expected), $description . ': the log must carry the same drift detail');
            }
        }
    }

    private static function testBlockedOrderNamesTheKindOfMismatch(): void
    {
        // [line net, line tax, line gross, lines, cart net, cart gross, expected exception message (null: none), description]
        $cases = [
            [100.00, 21.00, 121.00, 1, 110.00, 131.00, 'Order totals do not reconcile with cart totals: gross cart 131.00 vs order lines 121.00 (difference 10.00); net cart 110.00 vs order lines 100.00 (difference 10.00)', 'order lines drift from the cart'],
            // TWO-26283: their own arithmetic is not checked, but they are compared with the cart like any other lines.
            [100.00, 21.00, 125.00, 1, 100.00, 125.00, 'Order totals do not reconcile with cart totals: tax cart 25.00 vs order lines 21.00 (difference 4.00)', 'order lines failing gross = net + tax: the cart comparison names the figure that drifts'],
            // TWO-26340: the tolerance grows with the lines the build sums.
            [10.00, 2.10, 12.10, 7, 70.04, 84.74, null, 'seven lines 0.04 under the cart: within their tolerance'],
            [10.00, 2.10, 12.10, 7, 70.05, 84.75, 'Order totals do not reconcile with cart totals: gross cart 84.75 vs order lines 84.70 (difference 0.05); net cart 70.05 vs order lines 70.00 (difference 0.05)', 'seven lines 0.05 under the cart: outside their tolerance'],
        ];

        $method = new ReflectionMethod(Twopayment::class, 'buildTwoOrderPricingData');
        foreach ($cases as [$lineNet, $lineTax, $lineGross, $lineCount, $cartNet, $cartGross, $expected, $description]) {
            StubStore::reset();
            PrestaShopLogger::reset();
            $lines = [];
            for ($i = 1; $i <= $lineCount; $i++) {
                $lines[] = ['name' => 'Lamp ' . $i, 'net_amount' => $lineNet, 'tax_amount' => $lineTax, 'gross_amount' => $lineGross];
            }
            $module = new class ($lines) extends TwopaymentTestHarness {
                private array $lines;

                public function __construct(array $lines)
                {
                    parent::__construct();
                    $this->lines = $lines;
                }

                public function getTwoProductItems($cart)
                {
                    return $this->lines;
                }
            };

            $cart = new Cart(9602);
            StubStore::$cartProducts[9602] = [['id_product' => 1, 'cart_quantity' => 1]];
            StubStore::$cartTotals[9602] = [
                true => [Cart::BOTH => $cartGross],
                false => [Cart::BOTH => $cartNet],
            ];

            $message = null;
            try {
                // The checks run after the hook (TWO-26274), so the build hands them back.
                $module->runPricingChecksForTest($method->invoke($module, $cart, 'order create payload', true));
            } catch (TwoCheckoutAmountException $e) {
                $message = $e->getMessage();
            }
            TinyAssert::same($expected, $message, $description . ': exception message');
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
