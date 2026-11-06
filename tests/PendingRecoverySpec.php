<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/confirmation.php';

/**
 * TWO-26300: a buyer who verifies with Two and never comes back leaves only a checkout attempt.
 * The scheduled check, the admin button and the buyer's return settle it through one resolver.
 * These rows drive TwoPendingRecovery against a module whose wire, attempt store and order
 * creation are recorded, so each row states what was sent to Two and what changed here.
 */
final class PendingRecoverySpec
{
    private const CART = 9101;
    private const CUSTOMER = 9102;
    private const PLACED_ORDER = 9103;
    private const OTHER_ORDER = 9104;
    private const TWO_ORDER = 'two-order-9101';
    private const TOKEN = 'attempt-pending-9101';

    public static function runAll(): void
    {
        self::testStateMapping();
        self::testTriggers();
        self::testNotFoundMustRepeat();
        self::testRepeatedChecksLogOnce();
        self::testScheduledSelection();
        self::testBuyerReturnToATwoCancelledAttemptSendsNoCancel();
        self::testBuyerReturnWhileACheckHoldsTheLock();
        self::testBuyerReturnOnUnverifiedKeepsTheRefusal();
        self::testAnOldAttemptNeverPlacesASecondOrderForItsCart();
    }

    /**
     * The buyer back on UNVERIFIED gets the same refusal and keeps the same cart as before
     * TWO-26300; only the attempt stays open for the scheduled check.
     */
    private static function testBuyerReturnOnUnverifiedKeepsTheRefusal(): void
    {
        $module = self::module([]);
        $module->get = ['http_status' => 200, 'id' => self::TWO_ORDER, 'state' => 'UNVERIFIED', 'status' => 'APPROVED'];
        $controller = new TwopaymentConfirmationModuleFrontController();
        $controller->module = $module;
        Tools::resetTestValues();
        Tools::setTestValue('key', 'key-9102');
        $redirect = '';
        try {
            (new ReflectionMethod($controller, 'handleAttemptTokenConfirmation'))->invoke($controller, self::TOKEN);
        } catch (StubRedirect $e) {
            $redirect = $e->getMessage();
        }
        TinyAssert::same(['Payment has not been verified yet. Please try again or contact support.'], $controller->errors, 'unverified return: the same refusal');
        TinyAssert::same('index.php?controller=order', $redirect, 'unverified return: back to checkout');
        TinyAssert::same(self::CART, (int) Context::getContext()->cookie->id_cart, 'unverified return: the buyer keeps the attempt\'s cart');
        TinyAssert::same('REDIRECTED', $module->attempt['status'], 'unverified return: the attempt stays open for the scheduled check');
        TinyAssert::same(0, $module->created, 'unverified return: no order');
        TinyAssert::same([], $module->cancels, 'unverified return: nothing sent to Two');
    }

    /**
     * The buyer abandoned attempt 1, came back and paid the same cart through attempt 2. A later
     * check of attempt 1 must never place a second order for the cart; its Two order is cancelled
     * at Two unless Two already holds it cancelled, and the order attempt 2 placed is untouched.
     */
    private static function testAnOldAttemptNeverPlacesASecondOrderForItsCart(): void
    {
        $verified = ['http_status' => 200, 'id' => self::TWO_ORDER, 'state' => 'VERIFIED', 'status' => 'APPROVED', 'gross_amount' => '120.00'];
        // [Two's state of the old order, module of the cart's order, found before creation (else only by
        //  validateOrder's duplicate recovery), outcome, attempt status, cancel POSTs, confirm POSTs, description]
        $rows = [
            ['VERIFIED', 'twopayment', true, TwoPendingRecovery::OUTCOME_DUPLICATE, 'FAILED', ['rebinding_guard'], 0, 'old attempt VERIFIED, cart ordered by a newer Two attempt: not placed or confirmed, cancelled at Two'],
            ['UNVERIFIED', 'twopayment', true, TwoPendingRecovery::OUTCOME_DUPLICATE, 'FAILED', ['rebinding_guard'], 0, 'old attempt UNVERIFIED, cart ordered: cancelled at Two'],
            ['CANCELLED', 'twopayment', true, TwoPendingRecovery::OUTCOME_DUPLICATE, 'CANCELLED', [], 0, 'old attempt Two already cancelled: closed, nothing sent'],
            ['VERIFIED', 'ps_wirepayment', true, TwoPendingRecovery::OUTCOME_DUPLICATE, 'FAILED', ['rebinding_guard'], 0, 'cart ordered with another payment method: not placed, cancelled at Two'],
            ['VERIFIED', 'twopayment', false, TwoPendingRecovery::OUTCOME_DUPLICATE, 'FAILED', ['rebinding_guard'], 1, 'cart ordered between the check and validateOrder: the duplicate recovery refuses it'],
        ];
        foreach ($rows as [$state, $otherModule, $foundFirst, $outcome, $status, $cancels, $confirms, $description]) {
            $module = self::module([]);
            $module->get = ['state' => $state] + $verified;
            StubStore::$orders[self::OTHER_ORDER] = ['id_cart' => self::CART, 'id_customer' => self::CUSTOMER, 'module' => $otherModule];
            $later = ['id_order' => self::OTHER_ORDER, 'two_order_id' => 'two-order-later', 'two_order_state' => 'CONFIRMED'];
            if ($otherModule === 'twopayment') {
                StubStore::$twoPaymentRows[self::OTHER_ORDER] = $later;
            }
            if ($foundFirst) {
                $module->orderIdByCart = self::OTHER_ORDER;
            } else {
                $module->recoverExisting = self::OTHER_ORDER;
            }
            $result = $module->getTwoPendingRecovery()->resolveAttempt(self::TOKEN, TwoPendingRecovery::TRIGGER_SCHEDULED);
            TinyAssert::same($outcome, $result['outcome'], $description . ': outcome');
            TinyAssert::same($status, $module->attempt['status'], $description . ': old attempt status');
            TinyAssert::same($cancels, $module->cancels, $description . ': cancel POSTs');
            TinyAssert::same($confirms, count($module->confirms), $description . ': confirm POSTs');
            TinyAssert::same($foundFirst ? 0 : 1, $module->created, $description . ': validateOrder calls');
            TinyAssert::same([], $module->statusChanges, $description . ': the placed order keeps its status');
            TinyAssert::same([], $module->notes, $description . ': no note on the placed order');
            TinyAssert::same(
                $otherModule === 'twopayment' ? $later : null,
                StubStore::$twoPaymentRows[self::OTHER_ORDER] ?? null,
                $description . ': the placed order keeps its own Two data'
            );
        }

        TinyAssert::same(
            TwoPendingRecovery::lockName(['id_cart' => self::CART], 'attempt-one'),
            TwoPendingRecovery::lockName(['id_cart' => self::CART], 'attempt-two'),
            'two attempts for one cart share one lock'
        );
    }

    private static function testStateMapping(): void
    {
        $c = TwoPendingRecovery::ACTION_CONFIRM;
        $p = TwoPendingRecovery::ACTION_PLACE;
        $x = TwoPendingRecovery::ACTION_CANCEL;
        $d = TwoPendingRecovery::ACTION_DECLINED;
        $w = TwoPendingRecovery::ACTION_WAIT;
        $u = TwoPendingRecovery::ACTION_UNKNOWN;
        // [state, status, action, description]
        $rows = [
            ['VERIFIED', 'APPROVED', $c, 'verified: confirm, then place'],
            ['verified', '', $c, 'state is read case-insensitively'],
            ['VERIFIED', 'REJECTED', $c, 'a verified order is never cancelled for its status'],
            ['CONFIRMED', 'APPROVED', $p, 'confirmed: place without confirming again'],
            ['FULFILLING', '', $p, 'fulfilling: place'],
            ['FULFILLED', '', $p, 'fulfilled: place'],
            ['DELIVERED', '', $p, 'delivered: place'],
            ['FULFILMENT_NEEDS_MANUAL_RESOLUTION', '', $p, 'manual resolution: place'],
            ['CANCELLED', 'APPROVED', $x, 'cancelled, including Two\'s auto-cancel'],
            ['UNVERIFIED', 'REJECTED', $d, 'rejected status: declined'],
            ['UNVERIFIED', 'DECLINED', $d, 'declined status: declined'],
            ['UNVERIFIED', 'APPROVED', $w, 'unverified: wait'],
            ['REFUNDED', 'APPROVED', $u, 'refunded: change nothing'],
            ['SOMETHING_NEW', '', $u, 'an unknown state: change nothing'],
            ['', '', $u, 'no state: change nothing'],
        ];
        foreach ($rows as [$state, $status, $expected, $description]) {
            TinyAssert::same($expected, TwoPendingRecovery::classifyTwoState($state, $status), $description);
        }
    }

    private static function testTriggers(): void
    {
        $s = TwoPendingRecovery::TRIGGER_SCHEDULED;
        $a = TwoPendingRecovery::TRIGGER_ADMIN;
        $verified = ['http_status' => 200, 'id' => self::TWO_ORDER, 'state' => 'VERIFIED', 'status' => 'APPROVED', 'gross_amount' => '120.00'];
        $confirmed = ['state' => 'CONFIRMED'] + $verified;
        // [trigger, Two's GET, attempt overrides, outcome, attempt status after, confirm POSTs, cancel POSTs,
        //  orders created, order notes, description]
        $rows = [
            [$s, $verified, [], TwoPendingRecovery::OUTCOME_PLACED, 'CONFIRMED', 1, 0, 1, 1, 'scheduled, VERIFIED: confirmed with Two and placed, with a note'],
            [$a, $verified, [], TwoPendingRecovery::OUTCOME_PLACED, 'CONFIRMED', 1, 0, 1, 1, 'admin, VERIFIED: confirmed with Two and placed, with a note'],
            [$s, $confirmed, [], TwoPendingRecovery::OUTCOME_PLACED, 'CONFIRMED', 0, 0, 1, 1, 'scheduled, CONFIRMED: placed without confirming again'],
            [$s, ['state' => 'CANCELLED'] + $verified, [], TwoPendingRecovery::OUTCOME_CANCELLED, 'CANCELLED', 0, 0, 0, 0, 'scheduled, CANCELLED: unplaced, nothing sent to Two'],
            [$s, ['state' => 'UNVERIFIED', 'status' => 'REJECTED'] + $verified, [], TwoPendingRecovery::OUTCOME_DECLINED, 'CANCELLED', 0, 0, 0, 0, 'scheduled, REJECTED: unplaced, nothing sent to Two'],
            [$s, ['state' => 'UNVERIFIED'] + $verified, [], TwoPendingRecovery::OUTCOME_WAITING, 'REDIRECTED', 0, 0, 0, 0, 'scheduled, UNVERIFIED: still waiting'],
            [$a, ['state' => 'REFUNDED'] + $verified, [], TwoPendingRecovery::OUTCOME_UNCHANGED, 'REDIRECTED', 0, 0, 0, 0, 'admin, REFUNDED: nothing changes'],
            [$s, ['http_status' => 500, 'error_code' => 'SERVER_ERROR', 'error_details' => 'boom'], [], TwoPendingRecovery::OUTCOME_LOOKUP_FAILED, 'REDIRECTED', 0, 0, 0, 0, 'scheduled, failed read: nothing changes'],
            [$s, ['http_status' => 404], ['pending_not_found_at' => '2000-01-01 00:00:00'], TwoPendingRecovery::OUTCOME_LOOKUP_FAILED, 'REDIRECTED', 0, 0, 0, 0, 'scheduled, a 404 that is not Two\'s own never cancels'],
            [$s, $verified, ['status' => 'CONFIRMED', 'id_order' => self::PLACED_ORDER], TwoPendingRecovery::OUTCOME_NOT_WAITING, 'CONFIRMED', 0, 0, 0, 0, 'scheduled, already placed: not touched'],
            [$s, $verified, ['status' => 'CANCELLED'], TwoPendingRecovery::OUTCOME_NOT_WAITING, 'CANCELLED', 0, 0, 0, 0, 'scheduled, already cancelled: not touched'],
            [$s, $verified, ['cart_snapshot_hash' => 'stale-hash'], TwoPendingRecovery::OUTCOME_CART_CHANGED, 'REDIRECTED', 0, 0, 0, 0, 'scheduled, cart changed since: nothing placed, nothing sent to Two'],
        ];
        $failures = [];
        foreach ($rows as [$trigger, $get, $overrides, $outcome, $status, $confirms, $cancels, $created, $notes, $description]) {
            try {
                $module = self::module($overrides);
                $module->get = $get;
                $result = $module->getTwoPendingRecovery()->resolveAttempt(self::TOKEN, $trigger);
                TinyAssert::same($outcome, $result['outcome'], $description . ': outcome');
                TinyAssert::same($status, $module->attempt['status'], $description . ': attempt status');
                TinyAssert::same($confirms, count($module->confirms), $description . ': confirm POSTs');
                TinyAssert::same($cancels, count($module->cancels), $description . ': cancel POSTs');
                TinyAssert::same($created, $module->created, $description . ': orders created');
                TinyAssert::same($notes, count($module->notes), $description . ': order notes');
                if ($outcome !== TwoPendingRecovery::OUTCOME_NOT_WAITING) {
                    TinyAssert::true(!empty($module->attempt['pending_checked_at']), $description . ': the check is stamped');
                    TinyAssert::same($module->attempt['updated_at'], '2026-01-01 00:00:00', $description . ': a check never moves updated_at');
                } else {
                    TinyAssert::same(0, $module->gets, $description . ': no read of Two');
                }
                if ($outcome === TwoPendingRecovery::OUTCOME_CANCELLED) {
                    TinyAssert::same('CANCELLED', $module->attempt['two_order_state'], $description . ': Two\'s state is recorded, so no later path echoes a cancel');
                }
                if ($trigger === $a && $notes > 0) {
                    TinyAssert::true(strpos($module->notes[0], 'Ada Admin') !== false, $description . ': the note names the employee');
                }
                if ($trigger === $s && $notes > 0) {
                    TinyAssert::true(strpos($module->notes[0], 'scheduled check') !== false, $description . ': the note names the scheduled check');
                }
            } catch (Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }

        // A Two cancel on an old attempt never touches the order a later attempt placed for the same cart.
        try {
            $module = self::module([]);
            $module->get = ['state' => 'CANCELLED'] + $verified;
            $module->orderIdByCart = self::OTHER_ORDER;
            StubStore::$orders[self::OTHER_ORDER] = ['id_cart' => self::CART, 'id_customer' => self::CUSTOMER, 'module' => 'twopayment'];
            StubStore::$twoPaymentRows[self::OTHER_ORDER] = ['id_order' => self::OTHER_ORDER, 'two_order_id' => 'two-order-later', 'two_order_state' => 'CONFIRMED'];
            $module->getTwoPendingRecovery()->resolveAttempt(self::TOKEN, $s);
            TinyAssert::same([], $module->statusChanges, 'a cancelled old attempt leaves another attempt\'s order alone');
        } catch (Throwable $e) {
            $failures[] = $e->getMessage();
        }

        if ($failures !== []) {
            throw new RuntimeException(count($failures) . " row(s) failed:\n  - " . implode("\n  - ", $failures));
        }
    }

    private static function testNotFoundMustRepeat(): void
    {
        $notFound = ['http_status' => 404, 'error_code' => 'ORDER_NOT_FOUND', 'error_details' => 'no such order'];
        $now = time();
        // [first not-found stamp, outcome, attempt status, description]
        $rows = [
            [null, TwoPendingRecovery::OUTCOME_LOOKUP_FAILED, 'REDIRECTED', 'a first ORDER_NOT_FOUND is only remembered'],
            [date('Y-m-d H:i:s', $now - 300), TwoPendingRecovery::OUTCOME_LOOKUP_FAILED, 'REDIRECTED', 'a repeat 5 minutes later is not enough'],
            [date('Y-m-d H:i:s', $now - 16 * 60), TwoPendingRecovery::OUTCOME_NOT_FOUND, 'CANCELLED', 'a repeat 16 minutes later cancels here'],
        ];
        foreach ($rows as [$first, $outcome, $status, $description]) {
            $module = self::module(['pending_not_found_at' => $first]);
            $module->get = $notFound;
            $result = $module->getTwoPendingRecovery()->resolveAttempt(self::TOKEN, TwoPendingRecovery::TRIGGER_SCHEDULED);
            TinyAssert::same($outcome, $result['outcome'], $description);
            TinyAssert::same($status, $module->attempt['status'], $description . ': status');
            TinyAssert::same([], $module->cancels, $description . ': nothing sent to Two');
            if ($first === null) {
                TinyAssert::true(!empty($module->attempt['pending_not_found_at']), $description . ': stamped');
            }
        }
    }

    private static function testRepeatedChecksLogOnce(): void
    {
        // [Two's GET, log line fragment, description]
        $rows = [
            [['http_status' => 500, 'error_code' => 'SERVER_ERROR', 'error_details' => 'boom'], 'the order lookup', 'a failing read is logged once a day'],
            [['http_status' => 200, 'id' => self::TWO_ORDER, 'state' => 'REFUNDED', 'status' => 'APPROVED'], 'which the module does not act on', 'an unknown state is warned about once'],
        ];
        foreach ($rows as [$get, $fragment, $description]) {
            $module = self::module([]);
            $module->get = $get;
            PrestaShopLogger::$logs = [];
            for ($i = 0; $i < 3; $i++) {
                $module->attempt['pending_checked_at'] = null;
                $module->getTwoPendingRecovery()->resolveAttempt(self::TOKEN, TwoPendingRecovery::TRIGGER_SCHEDULED);
            }
            $lines = array_filter(PrestaShopLogger::$logs, static function ($entry) use ($fragment) {
                return strpos((string) $entry['message'], $fragment) !== false;
            });
            TinyAssert::count(1, $lines, $description);
        }
    }

    private static function testScheduledSelection(): void
    {
        $module = self::module([]);
        $module->candidates = [self::TOKEN];
        $module->get = ['http_status' => 200, 'id' => self::TWO_ORDER, 'state' => 'UNVERIFIED', 'status' => 'APPROVED'];
        $now = 1767225600;
        $summary = $module->getTwoPendingRecovery()->runScheduledCheck($now);
        TinyAssert::same(
            [date('Y-m-d H:i:s', $now - 1800), date('Y-m-d H:i:s', $now - 7 * 86400), date('Y-m-d H:i:s', $now - 900), 50],
            $module->candidateArgs,
            'selection: 30 minutes to 7 days old, unchecked for 15 minutes, 50 per run'
        );
        TinyAssert::same(1, $summary['checked'], 'the run checks its candidates');
    }

    private static function testBuyerReturnToATwoCancelledAttemptSendsNoCancel(): void
    {
        $module = self::module(['status' => 'CANCELLED', 'two_order_state' => 'CANCELLED']);
        $controller = new TwopaymentConfirmationModuleFrontController();
        $controller->module = $module;
        Tools::resetTestValues();
        Tools::setTestValue('key', 'key-9102');
        try {
            (new ReflectionMethod($controller, 'handleAttemptTokenConfirmation'))->invoke($controller, self::TOKEN);
        } catch (StubRedirect $redirect) {
        }
        TinyAssert::same([], $module->cancels, 'a buyer returning to an attempt Two cancelled sends Two no cancel');
        TinyAssert::same(['Your order is cancelled.'], $controller->errors, 'the buyer is told the order is cancelled, never shown a success page');
    }

    private static function testBuyerReturnWhileACheckHoldsTheLock(): void
    {
        // [attempt status while the check holds the lock, buyer message, description]
        $rows = [
            ['REDIRECTED', 'Your payment is still being processed. Please reload this page in a moment.', 'a check still running: reload'],
            ['CANCELLED', 'Your order is cancelled.', 'the check cancelled it: never a success page'],
        ];
        foreach ($rows as [$status, $message, $description]) {
            $module = self::module(['status' => $status]);
            StubStore::$dbLocks[TwoPendingRecovery::lockName($module->attempt, self::TOKEN)] = true;
            $controller = new TwopaymentConfirmationModuleFrontController();
            $controller->module = $module;
            Tools::resetTestValues();
            Tools::setTestValue('key', 'key-9102');
            try {
                (new ReflectionMethod($controller, 'handleAttemptTokenConfirmation'))->invoke($controller, self::TOKEN);
            } catch (StubRedirect $redirect) {
            }
            TinyAssert::same([$message], $controller->errors, $description);
            TinyAssert::same(0, $module->gets, $description . ': the busy return reads nothing from Two');
        }
        StubStore::$dbLocks = [];
    }

    private static function module(array $overrides): TwopaymentTestHarness
    {
        StubStore::reset();
        StubStore::$customers[self::CUSTOMER] = ['email' => 'buyer@example.com', 'firstname' => 'Eva', 'lastname' => 'Martin', 'secure_key' => 'key-9102', 'loaded' => true];
        StubStore::$carts[self::CART] = ['id_customer' => self::CUSTOMER, 'id_currency' => 978, 'id_address_invoice' => 0, 'id_address_delivery' => 0, 'id_carrier' => 0, 'id_lang' => 1];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        $context = Context::getContext();
        $context->employee = (object) ['firstname' => 'Ada', 'lastname' => 'Admin'];
        $context->cookie->two_payment_term = 30;

        return new class (array_merge([
            'attempt_token' => self::TOKEN, 'id_cart' => self::CART, 'id_customer' => self::CUSTOMER, 'id_order' => 0,
            'two_order_id' => self::TWO_ORDER, 'two_order_reference' => 'ref', 'two_order_state' => 'UNVERIFIED',
            'two_invoice_id' => null, 'two_invoice_url' => '', 'two_day_on_invoice' => '30', 'two_payment_term_type' => 'STANDARD',
            'cart_snapshot_hash' => '', 'status' => 'REDIRECTED', 'customer_secure_key' => 'key-9102', 'merchant_order_id' => 'm-1',
            'updated_at' => '2026-01-01 00:00:00', 'pending_checked_at' => null, 'pending_outcome' => null,
            'pending_not_found_at' => null, 'pending_logged_at' => null,
        ], $overrides), self::PLACED_ORDER) extends TwopaymentTestHarness {
            public array $attempt;
            public array $get = [];
            public int $gets = 0;
            public array $confirms = [];
            public array $cancels = [];
            public int $created = 0;
            public array $notes = [];
            public array $statusChanges = [];
            public int $orderIdByCart = 0;
            public int $recoverExisting = 0;
            public array $candidates = [];
            public array $candidateArgs = [];
            private int $placedOrder;

            public function __construct(array $attempt, int $placedOrder)
            {
                parent::__construct();
                $this->attempt = $attempt;
                $this->placedOrder = $placedOrder;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->gets++;

                return $this->get;
            }

            public function sendTwoOrderRequest($requestType, $trigger, $endpoint, array $payload, $method, $cart = null, $order = null, array $headers = array(), &$sentPayload = null, $checks = null, $twoOrder = null)
            {
                if (substr((string) $endpoint, -8) === '/confirm') {
                    $this->confirms[] = (string) $trigger;

                    return ['http_status' => 200, 'state' => 'CONFIRMED', 'status' => 'APPROVED'];
                }
                if (substr((string) $endpoint, -7) === '/cancel') {
                    $this->cancels[] = (string) $trigger;
                }

                return ['http_status' => 200];
            }

            public function getTwoCheckoutAttempt($attempt_token)
            {
                return $this->attempt;
            }

            public function updateTwoCheckoutAttemptStatus($attempt_token, $status, $extra_data = array())
            {
                $this->attempt = array_merge($this->attempt, $extra_data, ['status' => (string) $status]);

                return true;
            }

            public function stampTwoPendingAttempt($attempt_token, array $fields)
            {
                $this->attempt = array_merge($this->attempt, $fields);

                return true;
            }

            public function getTwoPendingAttemptCandidates($created_before, $created_after, $checked_before, $limit)
            {
                $this->candidateArgs = [$created_before, $created_after, $checked_before, $limit];

                return $this->candidates;
            }

            public function getTwoNewOrderData($merchant_order_id, $cart, $merchant_urls = null, $syncSurchargeCartLine = true, $trigger = 'checkout')
            {
                return ['merchant_order_id' => (string) $merchant_order_id];
            }

            public function calculateTwoCheckoutSnapshotHash($cart, $paymentdata)
            {
                return 'current-hash';
            }

            public function getTwoOrderCompanySnapshot($cart)
            {
                return ['two_company_name' => 'Acme', 'two_organization_number' => '123'];
            }

            public function getTwoOrderIdByCart($id_cart)
            {
                return $this->orderIdByCart;
            }

            public function createTwoLocalOrderAfterProviderVerification($cart, $customer, $initial_status, $provider_gross_amount)
            {
                $this->created++;
                if ($this->recoverExisting > 0) {
                    // Core refuses an ordered cart; the module's duplicate recovery finds its order.
                    return ['success' => true, 'id_order' => $this->recoverExisting, 'recovered_existing' => true, 'error' => 'cart already ordered'];
                }
                StubStore::$orders[$this->placedOrder] = ['id_cart' => (int) $cart->id, 'id_customer' => (int) $customer->id, 'module' => 'twopayment'];

                return ['success' => true, 'id_order' => $this->placedOrder, 'recovered_existing' => false, 'error' => ''];
            }

            public function setTwoOrderPaymentData($id_order, $payment_data)
            {
                StubStore::$twoPaymentRows[(int) $id_order] = $payment_data;

                return true;
            }

            public function putTwoOrderUpdate($order, $orderpaymentdata, &$paymentdata = null, $trigger = 'admin_edit', $twoOrder = null)
            {
                return null;
            }

            public function changeOrderStatus($id_order, $id_order_status)
            {
                $this->statusChanges[] = (int) $id_order;

                return true;
            }

            protected function addTwoOrderPrivateNote($idOrder, $text)
            {
                $this->notes[] = (string) $text;
            }

            public function clearTwoCartRecords($cartId)
            {
            }

            public function clearTwoOrderIntentSession()
            {
            }
        };
    }
}
