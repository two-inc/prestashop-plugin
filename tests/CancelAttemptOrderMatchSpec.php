<?php

declare(strict_types=1);

/**
 * Harness with a scripted cart order and its stored Two payment row.
 */
class TwoCancelAttemptOrderMatchHarness extends TwopaymentTestHarness
{
    public $cartOrderId = 0;
    /** @var array|false */
    public $paymentRow = false;

    public function getTwoOrderIdByCart($id_cart)
    {
        return $this->cartOrderId;
    }

    public function getTwoOrderPaymentData($id_order)
    {
        return (int)$id_order === $this->cartOrderId ? $this->paymentRow : false;
    }
}

/**
 * TWO-27356: a cancelled attempt resolves to the cart's order only when that
 * order carries the attempt's own Two order. A buyer who opened Two checkout
 * twice for one cart, completed one and cancelled the other must not have the
 * completed order cancelled.
 */
final class CancelAttemptOrderMatchSpec
{
    public static function runAll(): void
    {
        $cases = [
            // [attempt id_order, attempt two_order_id, cart order id, cart order payment row, expected, description]
            [12, 'two-A', 30, ['two_order_id' => 'two-B'], 12, 'a linked attempt resolves to its own order'],
            [null, 'two-A', 30, ['two_order_id' => 'two-A'], 30, 'race window: the cart order holds this attempt\'s Two order'],
            [null, 'two-A', 30, ['two_order_id' => ' two-A '], 30, 'race window: stored ids are compared trimmed'],
            [null, 'two-A', 30, ['two_order_id' => 'two-B'], 0, 'stale attempt: the cart order holds another attempt\'s Two order'],
            [null, 'two-A', 30, false, 0, 'the cart order has no Two payment row'],
            [null, 'two-A', 30, ['two_order_id' => ''], 0, 'the cart order has no Two order id'],
            [null, '', 30, ['two_order_id' => ''], 0, 'an attempt with no Two order never takes the cart order'],
            [null, 'two-A', 0, false, 0, 'no order on the cart'],
        ];

        foreach ($cases as [$attemptOrderId, $attemptTwoOrderId, $cartOrderId, $paymentRow, $expected, $description]) {
            $module = new TwoCancelAttemptOrderMatchHarness();
            $module->cartOrderId = $cartOrderId;
            $module->paymentRow = $paymentRow;

            $attempt = [
                'id_order' => $attemptOrderId,
                'id_cart' => 5,
                'two_order_id' => $attemptTwoOrderId,
            ];

            TinyAssert::same($expected, $module->resolveTwoAttemptOrderIdForCancellation($attempt), $description);
        }
    }
}
