<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/confirmation.php';

/**
 * TWO-26289: when the plugin mirrors a Two-side cancellation onto the order, moving it to the mapped Order Cancelled
 * status fires hookActionOrderStatusUpdate. That hook must not send Two a cancel for an order Two already holds
 * cancelled. These rows drive the real confirmation controller paths, with changeOrderStatus() firing the status
 * hook the way core's OrderHistory does, and the order's `twopayment` row starting at a stale VERIFIED.
 */
final class CancelMirrorSpec
{
    private const ORDER = 7811;
    private const CART = 7812;
    private const CUSTOMER = 7813;
    private const MAPPED_CANCELLED = 43;
    private const TWO_ORDER = 'two-order-7811';
    private const TOKEN = 'attempt-cancel-mirror';

    public static function runAll(): void
    {
        $abort = ['abortConfirmationIfAttemptCancelled', [self::TOKEN, null], 'CANCELLED'];
        $best = 'confirmation_after_cancelled_attempt';
        // [controller method, its arguments, attempt status, cancel HTTP status, state Two's GET answers,
        //  expected cancel POSTs by trigger in order, expected stored state, description]
        $rows = [
            ['handleAttemptTokenConfirmation', [self::TOKEN], 'CREATED', 200, 'CANCELLED', [], 'CANCELLED', 'attempt confirmation, Two answers CANCELLED: no cancel POST'],
            ['handleLegacyOrderConfirmation', [self::ORDER], 'CREATED', 200, 'CANCELLED', [], 'CANCELLED', 'legacy confirmation, Two answers CANCELLED: no cancel POST'],
            array_merge($abort, [200, 'VERIFIED', [$best], 'CANCELLED', 'attempt cancelled, the best-effort cancel succeeds: recorded, no second cancel from the status hook']),
            array_merge($abort, [409, 'CANCELLED', [$best], 'CANCELLED', 'attempt cancelled, the cancel is refused but Two reads CANCELLED: recorded, no second cancel']),
            array_merge($abort, [500, 'VERIFIED', [$best, 'status_change'], 'VERIFIED', 'attempt cancelled, the cancel fails and Two reads VERIFIED: not recorded, the status hook sends the cancel after the best-effort one']),
        ];
        $failures = [];
        foreach ($rows as [$method, $args, $attemptStatus, $cancelHttp, $twoState, $triggers, $stored, $description]) {
            try {
                $module = self::module($attemptStatus);
                $module->cancelHttp = $cancelHttp;
                $module->twoState = $twoState;
                $controller = new TwopaymentConfirmationModuleFrontController();
                $controller->module = $module;
                $args = array_map(static function ($a) use ($module) {
                    return $a === null ? $module->attempt : $a;
                }, $args);
                $call = new ReflectionMethod($controller, $method);
                try {
                    $call->invokeArgs($controller, $args);
                } catch (StubRedirect $redirect) {
                }

                TinyAssert::same([self::ORDER], $module->statusChanges, $description . ': the order is moved to the mapped cancelled status');
                TinyAssert::same($triggers, $module->cancels, $description . ': cancel POSTs by trigger');
                TinyAssert::same($stored, StubStore::$twoPaymentRows[self::ORDER]['two_order_state'], $description . ': stored state');
            } catch (Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }
        if ($failures !== []) {
            throw new RuntimeException(count($failures) . " row(s) failed:\n  - " . implode("\n  - ", $failures));
        }
    }

    private static function module(string $attemptStatus): TwopaymentTestHarness
    {
        StubStore::reset();
        Configuration::updateValue('PS_TWO_OS_CANCELLED_MAP', self::MAPPED_CANCELLED);
        StubStore::$customers[self::CUSTOMER] = ['email' => 'buyer@example.com', 'firstname' => 'Eva', 'lastname' => 'Martin', 'secure_key' => 'key-7813', 'loaded' => true];
        StubStore::$carts[self::CART] = ['id_customer' => self::CUSTOMER, 'id_currency' => 978, 'id_address_invoice' => 0, 'id_address_delivery' => 0, 'id_carrier' => 0, 'id_lang' => 1];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$orders[self::ORDER] = ['id_cart' => self::CART, 'id_customer' => self::CUSTOMER, 'total_paid' => 100.0, 'module' => 'twopayment'];
        StubStore::$twoPaymentRows[self::ORDER] = [
            'id_order' => self::ORDER, 'two_order_id' => self::TWO_ORDER, 'two_order_reference' => 'ref', 'two_order_state' => 'VERIFIED',
            'two_order_status' => 'APPROVED', 'two_day_on_invoice' => '30', 'two_payment_term_type' => 'STANDARD', 'two_invoice_url' => '', 'two_invoice_id' => null,
        ];
        Context::getContext()->cookie->two_payment_term = 30;

        return new class ([
            'attempt_token' => self::TOKEN, 'id_cart' => self::CART, 'id_customer' => self::CUSTOMER, 'id_order' => 0,
            'two_order_id' => self::TWO_ORDER, 'cart_snapshot_hash' => '', 'status' => $attemptStatus, 'customer_secure_key' => 'key-7813',
        ]) extends TwopaymentTestHarness {
            public array $attempt;
            /** @var string[] the trigger of each cancel sent */
            public array $cancels = [];
            public int $cancelHttp = 200;
            public string $twoState = 'CANCELLED';
            /** @var int[] orders moved to a status */
            public array $statusChanges = [];

            public function __construct(array $attempt)
            {
                parent::__construct();
                $this->attempt = $attempt;
            }

            public function sendTwoOrderRequest($requestType, $trigger, $endpoint, array $payload, $method, $cart = null, $order = null, array $headers = array(), &$sentPayload = null, $checks = null, $twoOrder = null)
            {
                if (substr((string) $endpoint, -7) === '/cancel') {
                    $this->cancels[] = (string) $trigger;

                    return ['http_status' => $this->cancelHttp];
                }

                return ['http_status' => 200];
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                return ['http_status' => 200, 'id' => 'two-order-7811', 'state' => $this->twoState, 'status' => 'APPROVED', 'merchant_reference' => 'ref', 'invoice_url' => ''];
            }

            /** Core's OrderHistory::changeIdOrderState() fires actionOrderStatusUpdate. */
            public function changeOrderStatus($id_order, $id_order_status)
            {
                $this->statusChanges[] = (int) $id_order;
                $this->hookActionOrderStatusUpdate(['id_order' => (int) $id_order, 'newOrderStatus' => new OrderState((int) $id_order_status)]);

                return true;
            }

            public function getTwoCheckoutAttempt($attempt_token)
            {
                return $this->attempt;
            }

            public function updateTwoCheckoutAttemptStatus($attempt_token, $status, $extra_data = array())
            {
                $this->attempt['status'] = (string) $status;

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

            public function resolveTwoAttemptOrderIdForCancellation($attempt)
            {
                return 7811;
            }

            public function restoreDuplicateCart($id_order, $id_customer)
            {
                return true;
            }

            public function getTwoOrderCompanySnapshot($cart)
            {
                return array();
            }
        };
    }
}
