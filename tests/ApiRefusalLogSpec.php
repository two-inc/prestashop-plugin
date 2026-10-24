<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/orderintent.php';
require_once __DIR__ . '/../controllers/front/confirmation.php';
require_once __DIR__ . '/../controllers/front/cancel.php';

final class StubRefusalRelayResponded extends RuntimeException
{
}

/**
 * TWO-26611: every Two API refusal at checkout records Two's reason, with the cart it was
 * refused for, in the module log the merchant reads on the Two error log tab. The buyer keeps
 * the module's own generic wording and never sees Two's text.
 */
final class ApiRefusalLogSpec
{
    private const CART_ID = 26611;

    private const DETAIL = 'Line item tax_code is required when tax_rate is 0.000000 for ES orders';

    private const ORDER_ID = 26612;

    private const CUSTOMER_ID = 26613;

    private const TWO_ORDER = 'two-order-26611';

    private const TOKEN = 'attempt-26611';

    private const LOOKUP_FAILED = 'Unable to retrieve the order payment information please contact store owner.';

    public static function runAll(): void
    {
        self::testOnlyAnAnswerOfFourHundredOrAboveIsLogged();
        self::testIntentAtPaymentSubmitKeepsTwosReasonOffTheStorefront();
        self::testRelayedCheckoutCallsLogTheirRefusal();
        self::testCompanySearchRefusalIsLoggedOncePerCartAndStatus();
        self::testDeclineAtPaymentSubmitKeepsTwosMessageOffTheStorefront();
        self::testConfirmationKeepsTwosReasonOffTheStorefront();
        self::testConfirmAndCancelLogTwosOwnWords();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
    }

    /** @return array<int,array{message:string,severity:int,object_type:?string,object_id:?int}> */
    private static function refusals(string $refused): array
    {
        return array_values(array_filter(PrestaShopLogger::$logs, function ($entry) use ($refused) {
            return strpos((string) $entry['message'], 'TwoPayment: Two refused ' . $refused . ' for ') === 0;
        }));
    }

    private static function assertLogged(string $refused, string $fragment, string $description): void
    {
        $refusals = self::refusals($refused);
        TinyAssert::count(1, $refusals, 'one refusal logged: ' . $description);
        // An order intent or company search is refused routinely, so it is a warning; anything else is an error.
        $warning = strpos($refused, 'order intent') === 0 || $refused === 'company search';
        TinyAssert::same($warning ? 2 : 3, $refusals[0]['severity'], 'logged severity: ' . $description);
        TinyAssert::same('Cart', $refusals[0]['object_type'], 'logged against the cart: ' . $description);
        TinyAssert::same(self::CART_ID, $refusals[0]['object_id'], 'logged against this cart: ' . $description);
        TinyAssert::true(strpos($refusals[0]['message'], 'cart ' . self::CART_ID) !== false, 'the log names the cart: ' . $description);
        TinyAssert::true(strpos($refusals[0]['message'], $fragment) !== false, 'the log carries Two\'s reason: ' . $description);
    }

    private static function testOnlyAnAnswerOfFourHundredOrAboveIsLogged(): void
    {
        // [response, whether it is a refusal, description].
        $cases = [
            [['http_status' => 400, 'error_details' => self::DETAIL], true, 'a 400 is a refusal'],
            [['http_status' => 500, 'error_message' => 'Internal error'], true, 'a 500 is a refusal'],
            [['http_status' => 0, 'error_message' => 'Unable to connect'], false, 'no answer is already logged as a connection error'],
            [['http_status' => 200, 'approved' => false], false, 'a decline is an answer, not a refusal'],
            [['http_status' => 399], false, 'below 400 is not a refusal'],
            [null, false, 'no response at all is not a refusal'],
        ];
        foreach ($cases as [$response, $refusal, $description]) {
            self::reset();
            $logged = (new TwopaymentTestHarness())->logTwoApiRefusal('order intent', 'cart ' . self::CART_ID, $response, self::CART_ID);
            TinyAssert::same($refusal, $logged, 'reported: ' . $description);
            TinyAssert::count($refusal ? 1 : 0, self::refusals('order intent'), 'logged: ' . $description);
        }
    }

    private static function testIntentAtPaymentSubmitKeepsTwosReasonOffTheStorefront(): void
    {
        $generic = 'Invoice purchase with Two is not available for this order.';
        // [intent response, buyer message, a fragment the log must carry (null: none), description].
        $cases = [
            [['http_status' => 400, 'error_code' => 'SCHEMA_ERROR', 'error_details' => self::DETAIL],
                $generic, self::DETAIL, 'Two\'s reason stays off the storefront'],
            [['http_status' => 422, 'error_trace_id' => 'trace-26611', 'error_json' => [['loc' => ['buyer', 'representative', 'phone_number'], 'msg' => 'bad']]],
                'Please enter a valid Phone number to pay on invoice.', 'trace-26611', 'a field the buyer can correct is named in the module\'s words'],
            [['http_status' => 500, 'error_message' => 'Internal error'],
                'Payment provider temporarily unavailable. Please try again later.', 'HTTP 500 Internal error', 'a server error is logged too'],
            [['http_status' => 0, 'error_message' => 'Unable to connect'],
                'Connection error with payment provider. Please try again.', null, 'no answer is not a refusal'],
        ];
        foreach ($cases as [$response, $buyerMessage, $fragment, $description]) {
            self::reset();
            $module = new class extends TwopaymentTestHarness {
                /** @var array */
                public $response = [];

                public function getTwoIntentOrderData($cart, $customer, $currency, $address)
                {
                    return ['currency' => 'EUR'];
                }

                protected function shouldRunStrictOrderIntentParityAtPayment()
                {
                    return false;
                }

                public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
                {
                    return $this->response;
                }
            };
            $module->response = $response;

            $result = $module->checkTwoOrderIntentApprovalAtPayment(new Cart(self::CART_ID), new Customer(), new Currency(), new Address());

            TinyAssert::false($result['approved'], 'not approved: ' . $description);
            TinyAssert::same($buyerMessage, $result['message'], 'buyer message: ' . $description);
            if ($fragment === null) {
                TinyAssert::count(0, self::refusals('order intent at payment submit'), 'no refusal logged: ' . $description);
                continue;
            }
            self::assertLogged('order intent at payment submit', $fragment, $description);
        }
    }

    private static function testRelayedCheckoutCallsLogTheirRefusal(): void
    {
        // [what the relay names, response, a fragment the log must carry (null: none), description].
        $cases = [
            ['order intent', ['http_status' => 400, 'data' => ['error_details' => self::DETAIL], 'error_details' => self::DETAIL],
                self::DETAIL, 'an order intent refusal is logged'],
            ['company lookup', ['http_status' => 404, 'data' => ['error_code' => 'NOT_FOUND'], 'error_code' => 'NOT_FOUND'],
                'HTTP 404 NOT_FOUND', 'a company lookup refusal is logged'],
            ['company search', ['http_status' => 503, 'data' => ['error_message' => 'Search unavailable'], 'error_message' => 'Search unavailable'],
                'HTTP 503 Search unavailable', 'a company search refusal is logged'],
            ['order intent', ['http_status' => 200, 'data' => ['approved' => true], 'approved' => true],
                null, 'an answer is not logged'],
            [null, ['http_status' => 400, 'data' => ['error_code' => 'INVALID_REQUEST']],
                null, 'a refusal the module made up itself is not Two\'s'],
        ];
        foreach ($cases as [$refused, $response, $fragment, $description]) {
            self::reset();
            Context::getContext()->cookie = new Cookie();
            $emitted = self::relay($response, $refused, self::CART_ID);

            TinyAssert::same([$response['data']], $emitted, 'the body is relayed untouched: ' . $description);
            if ($fragment === null) {
                TinyAssert::count(0, array_filter(PrestaShopLogger::$logs, function ($entry) {
                    return strpos((string) $entry['message'], 'TwoPayment: Two refused ') === 0;
                }), 'no refusal logged: ' . $description);
                continue;
            }
            self::assertLogged((string) $refused, $fragment, $description);
        }
    }

    /**
     * Relay a response through the order intent controller for this cart.
     *
     * @return array<int,mixed> the bodies sent to the browser
     */
    private static function relay(array $response, ?string $refused, int $id_cart): array
    {
        Context::getContext()->cart = new Cart($id_cart);
        $controller = new class extends TwopaymentOrderintentModuleFrontController {
            /** @var array<int,mixed> */
            public array $emitted = [];

            public function sendJsonResponse($content)
            {
                $this->emitted[] = json_decode((string) $content, true);

                throw new StubRefusalRelayResponded('relayed');
            }
        };
        $controller->module = new TwopaymentTestHarness();
        $relay = new ReflectionMethod($controller, 'relayTwoApiResponse');
        try {
            // @: the relay sets the HTTP status, which a CLI run that has printed cannot.
            @$relay->invoke($controller, $response, $refused);
        } catch (StubRefusalRelayResponded $e) {
        }

        return $controller->emitted;
    }

    /** A search outage must not push the real refusals off the error log page, which lists the last 100 lines. */
    private static function testCompanySearchRefusalIsLoggedOncePerCartAndStatus(): void
    {
        self::reset();
        Context::getContext()->cookie = new Cookie();
        $other = self::CART_ID + 1;
        // [cart, HTTP status, refusal lines for the first cart so far, for the other cart so far, description].
        $steps = [
            [self::CART_ID, 503, 1, 0, 'the first 503 is logged'],
            [self::CART_ID, 503, 1, 0, 'a repeated 503 for the cart is not'],
            [self::CART_ID, 400, 2, 0, 'another status for the cart is'],
            [self::CART_ID, 200, 2, 0, 'an answer is not a refusal'],
            [self::CART_ID, 503, 2, 0, 'the 503 stays logged once'],
            [$other, 503, 2, 1, 'another cart logs its own 503'],
        ];
        foreach ($steps as [$id_cart, $status, $first, $second, $description]) {
            self::relay(['http_status' => $status, 'data' => ['error_code' => 'E' . $status], 'error_code' => 'E' . $status], 'company search', $id_cart);
            $counts = [self::CART_ID => 0, $other => 0];
            foreach (self::refusals('company search') as $entry) {
                ++$counts[$entry['object_id']];
                TinyAssert::same(2, $entry['severity'], 'logged as a warning: ' . $description);
            }
            TinyAssert::same([self::CART_ID => $first, $other => $second], $counts, 'refusal lines per cart: ' . $description);
        }
        TinyAssert::true(
            Tools::strlen((string) Context::getContext()->cookie->two_company_search_refusals) < 32,
            'the cookie marker stays a few bytes'
        );
    }

    private static function testDeclineAtPaymentSubmitKeepsTwosMessageOffTheStorefront(): void
    {
        $generic = 'Your order could not be approved by Two payment. Please choose another payment method or contact support.';
        $hint = 'Minimum order value is EUR50.00 excluding tax.';
        // [intent response, buyer message, fragments the log must carry, description].
        $cases = [
            [['http_status' => 200, 'approved' => false, 'message' => 'Internal decline wording', 'decline_reason' => 'CREDIT_LIMIT'],
                $generic, ['decline_reason: CREDIT_LIMIT', 'message: Internal decline wording'], 'Two\'s decline message stays off the storefront'],
            [['http_status' => 200, 'approved' => false, 'data' => ['message' => 'Nested wording']],
                $generic, ['message: Nested wording'], 'a message in the body\'s data is logged too'],
            [['http_status' => 200, 'approved' => false, 'decline_reason' => 'ORDER_BELOW_MIN_INVOICE_AMOUNT', 'message' => 'Below minimum'],
                $generic . ' ' . $hint, ['decline_reason: ORDER_BELOW_MIN_INVOICE_AMOUNT'], 'the minimum hint the module gives is kept'],
            [['http_status' => 200, 'approved' => false],
                $generic, ['no reason given'], 'a decline with no reason is still logged'],
        ];
        foreach ($cases as [$response, $buyerMessage, $fragments, $description]) {
            self::reset();
            $module = new class ($hint) extends TwopaymentTestHarness {
                /** @var array */
                public $response = [];
                /** @var string */
                private $hint;

                public function __construct(string $hint)
                {
                    parent::__construct();
                    $this->hint = $hint;
                }

                public function getTwoIntentOrderData($cart, $customer, $currency, $address)
                {
                    return ['currency' => 'EUR'];
                }

                protected function shouldRunStrictOrderIntentParityAtPayment()
                {
                    return false;
                }

                public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
                {
                    return $this->response;
                }

                public function getTwoMinimumOrderDeclineHint($response, $cart)
                {
                    return ($response['decline_reason'] ?? '') === 'ORDER_BELOW_MIN_INVOICE_AMOUNT' ? $this->hint : '';
                }
            };
            $module->response = $response;

            $result = $module->checkTwoOrderIntentApprovalAtPayment(new Cart(self::CART_ID), new Customer(), new Currency(), new Address());

            TinyAssert::same('declined', $result['status'], 'declined: ' . $description);
            TinyAssert::same($buyerMessage, $result['message'], 'buyer message: ' . $description);
            $declines = array_values(array_filter(PrestaShopLogger::$logs, function ($entry) {
                return strpos((string) $entry['message'], 'TwoPayment: Two declined the order intent at payment submit for cart ' . self::CART_ID . ' - ') === 0;
            }));
            TinyAssert::count(1, $declines, 'one decline logged: ' . $description);
            TinyAssert::same(2, $declines[0]['severity'], 'logged as a warning: ' . $description);
            TinyAssert::same(self::CART_ID, $declines[0]['object_id'], 'logged against the cart: ' . $description);
            foreach ($fragments as $fragment) {
                TinyAssert::true(strpos($declines[0]['message'], $fragment) !== false, 'the log carries ' . $fragment . ': ' . $description);
            }
            TinyAssert::count(0, self::refusals('order intent at payment submit'), 'a decline is not a refusal: ' . $description);
        }
    }

    /**
     * A module double for the confirmation and cancel controllers: Two answers the order lookup,
     * the confirm and the cancel as each test sets.
     */
    private static function checkoutModule(): TwopaymentTestHarness
    {
        self::reset();
        Tools::resetTestValues();
        Context::getContext()->cookie = new Cookie();
        StubStore::$customers[self::CUSTOMER_ID] = ['email' => 'buyer@example.com', 'firstname' => 'Eva', 'lastname' => 'Martin', 'secure_key' => 'key-26613', 'loaded' => true];
        StubStore::$carts[self::CART_ID] = ['id_customer' => self::CUSTOMER_ID, 'id_currency' => 978, 'id_address_invoice' => 0, 'id_address_delivery' => 0, 'id_carrier' => 0, 'id_lang' => 1];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$orders[self::ORDER_ID] = ['id_cart' => self::CART_ID, 'id_customer' => self::CUSTOMER_ID, 'total_paid' => 100.0, 'module' => 'twopayment'];
        StubStore::$twoPaymentRows[self::ORDER_ID] = [
            'id_order' => self::ORDER_ID, 'two_order_id' => self::TWO_ORDER, 'two_order_reference' => 'ref', 'two_order_state' => 'VERIFIED',
            'two_order_status' => 'APPROVED', 'two_day_on_invoice' => '30', 'two_payment_term_type' => 'STANDARD', 'two_invoice_url' => '', 'two_invoice_id' => null,
        ];

        return new class ([
            'attempt_token' => self::TOKEN, 'id_cart' => self::CART_ID, 'id_customer' => self::CUSTOMER_ID, 'id_order' => 0,
            'two_order_id' => self::TWO_ORDER, 'cart_snapshot_hash' => '', 'status' => 'CREATED', 'customer_secure_key' => 'key-26613',
        ]) extends TwopaymentTestHarness {
            public array $attempt;
            public array $lookup = ['http_status' => 200, 'state' => 'VERIFIED', 'status' => 'APPROVED'];
            public array $confirm = ['http_status' => 200, 'state' => 'CONFIRMED'];
            public array $cancel = ['http_status' => 200];
            public int $attemptOrderId = 0;

            public function __construct(array $attempt)
            {
                parent::__construct();
                $this->attempt = $attempt;
            }

            public function sendTwoOrderRequest($requestType, $trigger, $endpoint, array $payload, $method, $cart = null, $order = null, array $headers = array(), &$sentPayload = null, $checks = null, $twoOrder = null)
            {
                return substr((string) $endpoint, -7) === '/cancel' ? $this->cancel : $this->confirm;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                return $this->lookup + ['id' => 'two-order-26611', 'merchant_reference' => 'ref', 'invoice_url' => ''];
            }

            public function changeOrderStatus($id_order, $id_order_status)
            {
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
                return $this->attemptOrderId;
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

    /**
     * @param object $controller
     * @param array<int,mixed> $args
     */
    private static function runToRedirect($controller, string $method, array $args): void
    {
        try {
            (new ReflectionMethod($controller, $method))->invokeArgs($controller, $args);
        } catch (StubRedirect $redirect) {
            return;
        }
        throw new RuntimeException($method . ' ended without redirecting');
    }

    private static function testConfirmationKeepsTwosReasonOffTheStorefront(): void
    {
        $refusal = ['http_status' => 404, 'error_code' => 'NOT_FOUND', 'error_details' => self::DETAIL];
        // [confirmation path, its argument, the reference the log names, description].
        $cases = [
            ['handleAttemptTokenConfirmation', self::TOKEN, 'attempt ' . self::TOKEN . ', Two order ' . self::TWO_ORDER, 'return from Two on an attempt'],
            ['handleLegacyOrderConfirmation', self::ORDER_ID, 'order ' . self::ORDER_ID . ', Two order ' . self::TWO_ORDER, 'return from Two on a placed order'],
        ];
        foreach ($cases as [$method, $arg, $reference, $description]) {
            $module = self::checkoutModule();
            $module->lookup = $refusal;
            $controller = new TwopaymentConfirmationModuleFrontController();
            $controller->module = $module;
            self::runToRedirect($controller, $method, [$arg]);

            TinyAssert::same([self::LOOKUP_FAILED], $controller->errors, 'the buyer gets the generic sentence: ' . $description);
            self::assertLogged('the order lookup', self::DETAIL, $description);
            TinyAssert::true(strpos(self::refusals('the order lookup')[0]['message'], $reference) !== false, 'the log names ' . $reference . ': ' . $description);
        }
    }

    private static function testConfirmAndCancelLogTwosOwnWords(): void
    {
        $refusal = ['http_status' => 409, 'error_code' => 'ORDER_STATE', 'error_message' => 'Order cannot be cancelled in state FULFILLED'];
        $reason = 'HTTP 409 ORDER_STATE | Order cannot be cancelled in state FULFILLED';

        $module = self::checkoutModule();
        $module->confirm = ['http_status' => 400, 'error_details' => self::DETAIL];
        TinyAssert::false($module->confirmTwoOrder(self::TWO_ORDER, 'confirmation')['success'], 'the confirm fails');
        TinyAssert::true(self::loggedOnce('TwoPayment: Order confirmation failed for Two order ID: ' . self::TWO_ORDER . ', Error: HTTP 400 ' . self::DETAIL), 'a failed confirm logs Two\'s own words');

        // [order the attempt is linked to, the line logged, description].
        $cases = [
            [0, 'TwoPayment: Attempt cancel failed for token ' . self::TOKEN . ', Two order ' . self::TWO_ORDER . ' - ' . $reason, 'an attempt cancel'],
            [self::ORDER_ID, 'TwoPayment: Legacy cancel callback could not confirm CANCELLED provider state for order ' . self::ORDER_ID
                . ', Two order ' . self::TWO_ORDER . ', cancel: ' . $reason . ', fetch_http=200', 'a placed order cancel'],
        ];
        foreach ($cases as [$attemptOrderId, $line, $description]) {
            $module = self::checkoutModule();
            $module->cancel = $refusal;
            $module->attemptOrderId = $attemptOrderId;
            $controller = new TwopaymentCancelModuleFrontController();
            $controller->module = $module;
            self::runToRedirect($controller, 'handleAttemptCancel', [self::TOKEN]);

            TinyAssert::true(self::loggedOnce($line), 'the log carries Two\'s own words: ' . $description);
            foreach ($controller->errors as $error) {
                TinyAssert::true(strpos($error, 'FULFILLED') === false, 'the buyer never sees Two\'s words: ' . $description);
            }
        }
    }

    private static function loggedOnce(string $prefix): bool
    {
        return count(array_filter(PrestaShopLogger::$logs, function ($entry) use ($prefix) {
            return strpos((string) $entry['message'], $prefix) === 0;
        })) === 1;
    }
}
