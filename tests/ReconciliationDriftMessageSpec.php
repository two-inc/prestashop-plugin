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
        // [line net, line tax, line gross, cart net, cart gross, reconciles, expected detail, description]
        $cases = [
            [64.00, 12.80, 76.80, 69.03, 76.80, false, 'net cart 69.03 vs order lines 64.00 (difference 5.03); tax cart 7.77 vs order lines 12.80 (difference 5.03)', 'gross matches, net and tax drift'],
            [100.00, 21.00, 121.00, 110.00, 131.00, false, 'gross cart 131.00 vs order lines 121.00 (difference 10.00); net cart 110.00 vs order lines 100.00 (difference 10.00)', 'tax matches, gross and net drift'],
            [100.00, 21.00, 121.00, 100.00, 125.00, false, 'gross cart 125.00 vs order lines 121.00 (difference 4.00); tax cart 25.00 vs order lines 21.00 (difference 4.00)', 'net matches, gross and tax drift'],
            [100.00, 21.00, 121.00, 123.97, 150.00, false, 'gross cart 150.00 vs order lines 121.00 (difference 29.00); net cart 123.97 vs order lines 100.00 (difference 23.97); tax cart 26.03 vs order lines 21.00 (difference 5.03)', 'all three drift'],
            [100.00, 21.00, 121.00, 100.02, 121.02, true, '', 'drift within tolerance names nothing'],
            [100.00, 21.00, 125.00, 100.00, 125.00, false, 'order lines gross 125.00 vs order lines net+tax 121.00', 'order lines failing gross = net + tax'],
            [100.00, 21.00, 121.00, 95.00, 115.00, false, 'gross cart 115.00 vs order lines 121.00 (difference 6.00); net cart 95.00 vs order lines 100.00 (difference 5.00); tax cart 20.00 vs order lines 21.00 (difference 1.00)', 'negative drift, order lines above the cart'],
            [100.00, 21.00, 121.00, 100.03, 121.03, false, 'gross cart 121.03 vs order lines 121.00 (difference 0.03); net cart 100.03 vs order lines 100.00 (difference 0.03)', 'exactly 0.03, just outside tolerance'],
            [100.00, 21.00, 121.00, 100.02, 121.03, false, 'gross cart 121.03 vs order lines 121.00 (difference 0.03)', 'net at 0.02 is within tolerance, gross at 0.03 is not'],
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

    private static function testBlockedOrderNamesTheKindOfMismatch(): void
    {
        // [line net, line tax, line gross, cart net, cart gross, expected exception message, description]
        $cases = [
            [100.00, 21.00, 121.00, 110.00, 131.00, 'Order totals do not reconcile with cart totals: gross cart 131.00 vs order lines 121.00 (difference 10.00); net cart 110.00 vs order lines 100.00 (difference 10.00)', 'order lines drift from the cart'],
            [100.00, 21.00, 125.00, 100.00, 125.00, 'Order line totals are internally inconsistent: order lines gross 125.00 vs order lines net+tax 121.00', 'order lines failing gross = net + tax'],
        ];

        $method = new ReflectionMethod(Twopayment::class, 'buildTwoOrderPricingData');
        foreach ($cases as [$lineNet, $lineTax, $lineGross, $cartNet, $cartGross, $expected, $description]) {
            StubStore::reset();
            PrestaShopLogger::reset();
            $line = ['name' => 'Lamp', 'net_amount' => $lineNet, 'tax_amount' => $lineTax, 'gross_amount' => $lineGross];
            // Per-line validation normally stops a line failing gross = net + tax earlier; bypass it to reach the totals gate.
            $module = new class ($line) extends TwopaymentTestHarness {
                private array $line;

                public function __construct(array $line)
                {
                    parent::__construct();
                    $this->line = $line;
                }

                public function getTwoProductItems($cart)
                {
                    return [$this->line];
                }

                public function validateTwoLineItems($line_items)
                {
                    return true;
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
