<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/cancel.php';

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
 * Harness for the cancel controller: the attempt row is read from a queue, so the row the
 * controller sees once it holds the cart lock can differ from the one it read first.
 */
class TwoCancelLockHarness extends TwoCancelAttemptOrderMatchHarness
{
    /** @var array<int,array> */
    public $attemptReads = [];
    /** @var array<int,string> */
    public $twoCalls = [];
    /** @var array<int,array> */
    public $statusWrites = [];
    /** @var array<int,array{0:string,1:bool}> lock name, whether this request held it during the call */
    public $locksAtTwoCall = [];
    public $lockName = '';

    public function getTwoCheckoutAttempt($attempt_token)
    {
        return count($this->attemptReads) > 1 ? array_shift($this->attemptReads) : $this->attemptReads[0];
    }

    public function sendTwoOrderRequest($requestType, $trigger, $endpoint, array $payload, $method, $cart = null, $order = null, array $headers = array(), &$sentPayload = null, $checks = null, $twoOrder = null, $finish = null)
    {
        $this->twoCalls[] = (string) $endpoint;
        $this->locksAtTwoCall[] = !empty(StubStore::$dbLocks[$this->lockName]);

        return ['http_status' => 200];
    }

    public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
    {
        $id = substr((string) $endpoint, strlen('/v1/order/'));

        return ['http_status' => 200, 'id' => $id, 'state' => 'CANCELLED', 'status' => 'CANCELLED', 'merchant_reference' => 'ref', 'invoice_url' => ''];
    }

    public function updateTwoCheckoutAttemptStatus($attempt_token, $status, $extra_data = array())
    {
        $this->statusWrites[] = ['status' => (string) $status, 'id_order' => isset($extra_data['id_order']) ? (int) $extra_data['id_order'] : 0];

        return true;
    }

    public function isTwoAttemptCallbackAuthorized($attempt, $provided_secure_key = '', $context_customer_id = 0, $context_customer_secure_key = '')
    {
        return true;
    }

    public function isTwoOrderCustomerAccessAuthorized($order, $customer, $provided_key = '', $context_customer_id = 0, $context_customer_secure_key = '')
    {
        return true;
    }

    public function setTwoOrderPaymentData($id_order, $payment_data)
    {
        return true;
    }

    public function restoreDuplicateCart($id_order, $id_customer)
    {
        return true;
    }

    public function changeOrderStatus($id_order, $id_order_status)
    {
        return true;
    }
}

/**
 * TWO-27356: a cancelled attempt resolves to the cart's order only when that
 * order carries the attempt's own Two order. A buyer who opened Two checkout
 * twice for one cart, completed one and cancelled the other must not have the
 * completed order cancelled. The cancel also runs under the cart lock
 * placement holds, so it never interleaves with this attempt's own placement.
 */
final class CancelAttemptOrderMatchSpec
{
    private const CART_ID = 27356;
    private const CUSTOMER_ID = 27357;
    private const ORDER_ID = 27358;
    private const TOKEN = 'attempt-27356';

    public static function runAll(): void
    {
        self::testResolverTable();
        self::testCancelRunsUnderTheCartLock();
    }

    private static function testResolverTable(): void
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

    private static function testCancelRunsUnderTheCartLock(): void
    {
        $unlinked = self::attempt(0, 'CREATED');
        $linked = self::attempt(self::ORDER_ID, 'CONFIRMED');
        $busy = 'Your payment is still being processed. Please reload this page in a moment.';
        $cancelled = 'Your order is cancelled.';

        $cases = [
            // [lock held elsewhere, attempt reads (first before the lock, then under it), cart order's Two id, Two calls, status writes, buyer message, description]
            [true, [$unlinked], 'two-own', [], [], $busy, 'placement holds the lock: nothing is sent to Two and nothing written'],
            [true, [self::attempt(0, 'CANCELLED')], 'two-own', [], [], $cancelled, 'busy, but the attempt is already cancelled: the buyer is told so'],
            [false, [$unlinked, $linked], 'two-own', ['/v1/order/two-own/cancel'], [['status' => 'CANCELLED', 'id_order' => self::ORDER_ID]], $cancelled,
                'placement linked the order while the cancel waited: the re-read takes the placed order\'s path'],
            [false, [$unlinked], 'two-other', ['/v1/order/two-own/cancel'], [['status' => 'CANCELLED', 'id_order' => 0]], $cancelled,
                'stale attempt: only its own Two order is cancelled, under the lock'],
        ];

        foreach ($cases as [$heldElsewhere, $reads, $cartOrderTwoId, $twoCalls, $statusWrites, $message, $description]) {
            StubStore::reset();
            Tools::resetTestValues();
            Context::getContext()->cookie = new Cookie();
            StubStore::$customers[self::CUSTOMER_ID] = ['email' => 'buyer@example.com', 'firstname' => 'Eva', 'lastname' => 'Martin', 'secure_key' => 'key', 'loaded' => true];
            StubStore::$carts[self::CART_ID] = ['id_customer' => self::CUSTOMER_ID, 'id_currency' => 978, 'id_address_invoice' => 0, 'id_address_delivery' => 0, 'id_carrier' => 0, 'id_lang' => 1];
            StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
            StubStore::$orders[self::ORDER_ID] = ['id_cart' => self::CART_ID, 'id_customer' => self::CUSTOMER_ID, 'total_paid' => 100.0, 'module' => 'twopayment'];

            $lock = TwoPendingRecovery::lockName($unlinked, self::TOKEN);
            if ($heldElsewhere) {
                StubStore::$dbLocks[$lock] = true;
            }

            $module = new TwoCancelLockHarness();
            $module->lockName = $lock;
            $module->attemptReads = $reads;
            $module->cartOrderId = self::ORDER_ID;
            $module->paymentRow = ['id_order' => self::ORDER_ID, 'two_order_id' => $cartOrderTwoId, 'two_day_on_invoice' => '30', 'two_payment_term_type' => 'STANDARD'];

            $controller = new TwopaymentCancelModuleFrontController();
            $controller->module = $module;
            try {
                (new ReflectionMethod($controller, 'handleAttemptCancel'))->invokeArgs($controller, [self::TOKEN]);
                throw new RuntimeException('handleAttemptCancel ended without redirecting: ' . $description);
            } catch (StubRedirect $redirect) {
                // Every path ends in a redirect.
            }

            TinyAssert::same($twoCalls, $module->twoCalls, $description . ': Two calls');
            TinyAssert::same($statusWrites, $module->statusWrites, $description . ': attempt status writes');
            TinyAssert::same([$message], $controller->errors, $description . ': buyer message');
            TinyAssert::false(in_array(false, $module->locksAtTwoCall, true), $description . ': every Two call is made under the lock');
            TinyAssert::same($heldElsewhere, !empty(StubStore::$dbLocks[$lock]), $description . ': the lock is released, or left with its holder');
        }
    }

    private static function attempt(int $idOrder, string $status): array
    {
        return [
            'attempt_token' => self::TOKEN,
            'id_cart' => self::CART_ID,
            'id_customer' => self::CUSTOMER_ID,
            'id_order' => $idOrder,
            'two_order_id' => 'two-own',
            'status' => $status,
            'customer_secure_key' => 'key',
        ];
    }
}
