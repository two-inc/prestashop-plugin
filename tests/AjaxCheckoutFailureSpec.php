<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/payment.php';

/**
 * TWO-24768: a checkout failure must reach whoever submitted the payment form.
 * A front-end that posts the form over XHR follows a 302 transparently, gets
 * the order page's HTML with HTTP 200, and cannot recognise a failure at all.
 */
final class AjaxCheckoutFailureSpec
{
    private const CART_ID = 9601;
    private const CUSTOMER_ID = 9001;
    private const ADDRESS_ID = 9201;
    private const GENERIC_REFUSAL = 'Invoice purchase with Two is not available for this order.';

    public static function runAll(): void
    {
        self::testXmlHttpRequestHeaderIsDetectedAsAjax();
        self::testJsonOnlyAcceptHeaderIsDetectedAsAjax();
        self::testBrowserNavigationIsNotDetectedAsAjax();
        self::testFailurePayloadFallsBackWhenMessageIsEmpty();
        self::testProviderRejectionReachesAjaxCallerAsJsonError();
        self::testProviderRejectionStillRedirectsBrowserNavigation();
        self::testNonPluginExceptionIsNotRelayedToTheBuyer();
        self::testPluginAmountDiagnosticStillReachesTheBuyer();
        self::testASubmissionWithNoOfferedTermIsRefusedBeforeOrderCreation();
        self::testACreateRefusalTellsTheBuyerOnlyWhatTheyCanActOn();
        self::testARawResponseBodyIsKeptAsPlainText();
    }

    /**
     * TWO-25161 information disclosure: payload building walks PrestaShop
     * core, and a PrestaShopDatabaseException carries SQL text and
     * table/column names. The buyer gets the generic message; the real
     * exception class and message are logged.
     */
    private static function testNonPluginExceptionIsNotRelayedToTheBuyer(): void
    {
        $sql = 'Unknown column \'tax_rules_group\' in \'field list\'<br />' .
            'SELECT * FROM ps_carrier_tax_rules_group_shop WHERE id_carrier = 7';
        $controller = self::makeController(new PrestaShopDatabaseException($sql));
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        try {
            self::runPostProcess($controller);
        } finally {
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        }

        TinyAssert::count(1, $controller->emitted);
        $payload = $controller->emitted[0];
        TinyAssert::same(
            'Two could not build this order from your cart. ' .
            'Please review your cart and try again, or contact the store.',
            $payload['message'],
            'a core exception must yield the generic buyer message, with no detail appended'
        );
        TinyAssert::false(
            strpos($payload['message'], 'ps_carrier_tax_rules_group_shop') !== false,
            'SQL text must never reach the buyer'
        );

        TinyAssert::true(
            self::loggedContains('[PrestaShopDatabaseException]'),
            'the real exception class must be logged'
        );
        TinyAssert::true(
            self::loggedContains('ps_carrier_tax_rules_group_shop'),
            'the real exception message must be logged'
        );
    }

    /**
     * The deliberate half of TWO-25161: a plugin-raised amount diagnostic keeps
     * its numbers, which is what makes a merchant-side cart/shipping
     * misconfiguration diagnosable from the checkout page.
     */
    private static function testPluginAmountDiagnosticStillReachesTheBuyer(): void
    {
        $diagnostic = 'Order totals do not reconcile with cart totals: gross cart 150.00 ' .
            'vs order lines 121.00 (difference 29.00)';
        $controller = self::makeController(new TwoCheckoutAmountException($diagnostic));
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        try {
            self::runPostProcess($controller);
        } finally {
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        }

        TinyAssert::count(1, $controller->emitted);
        TinyAssert::same(
            'Two could not build this order from your cart. Details: ' . $diagnostic . '. ' .
            'Please review your cart and try again, or contact the store.',
            $controller->emitted[0]['message'],
            'a plugin-raised amount diagnostic must keep reaching the buyer'
        );
    }

    /**
     * ABN-533. An unresolvable merchant record leaves the tile on offer with an
     * empty term set, so a buyer can submit with nothing to book against. That
     * submission is refused here rather than booked under the historical 30-day
     * default, which is what aligns this with Magento.
     *
     * Runs the real controller: the provider double answers the order call with
     * a 401, so reaching it at all is distinguishable from being refused first.
     */
    private static function testASubmissionWithNoOfferedTermIsRefusedBeforeOrderCreation(): void
    {
        // [cached offered set, refused for want of a term, description].
        $cases = array(
            array(json_encode(array((int) Twopayment::DEFAULT_PAYMENT_TERM_DAYS)), false,
                'a resolved and ticked term reaches order creation'),
            array('', true,
                'an unresolved record offers no term, so nothing can be booked'),
            array(json_encode(array(60)), true,
                'a record that withdrew the ticked term offers none either'),
        );

        foreach ($cases as $case) {
            list($cached, $refused, $description) = $case;
            $controller = self::makeController();
            Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS, $cached);
            Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS, time() - 10);

            try {
                self::runPostProcess($controller);
            } catch (Exception $e) {
                // Every guard on this path ends in a redirect the stub core raises.
            }

            TinyAssert::same(
                $refused,
                self::loggedContains('no offered payment term'),
                'submission refused: ' . $description
            );
        }
    }

    /**
     * TWO-26264. When Two refuses order creation, whatever the status, the buyer
     * is told only what they can act on, worded as the WooCommerce plugin words
     * it, and Two's own reason goes to the module log at error severity against
     * the cart, since there is no order yet to note it on.
     */
    private static function testACreateRefusalTellsTheBuyerOnlyWhatTheyCanActOn(): void
    {
        $phone = 'Please enter a valid Phone number to pay on invoice.';
        $sameCompany = 'Buyer and merchant may not be the same company.';
        $invalid = "OrderError {'order_id': 'a line item lacks a field'}";
        $hint = 'Minimum order value is EUR50.00 excluding tax.';
        $fieldJson = '"loc":["billing_address","city"]';
        $longJson = array_fill(0, 40, ['loc' => ['line_items', 0, 'tax_rate'], 'msg' => str_repeat('x', 40)]);

        // [create response, buyer message, a fragment the log must carry, description, minimum hint].
        $cases = array(
            array(['http_status' => 400, 'error_code' => 'ORDER_INVALID', 'error_message' => 'Order is invalid', 'error_details' => $invalid],
                self::GENERIC_REFUSAL, $invalid, 'raw error details stay off the storefront'),
            array(['http_status' => 400, 'error_code' => 'SCHEMA_ERROR', 'error_json' => [['loc' => ['buyer', 'representative', 'phone_number'], 'msg' => 'value is not a valid phone number']]],
                $phone, 'SCHEMA_ERROR', 'a field Two names is one the buyer can correct'),
            array(['http_status' => 400, 'error_trace_id' => 'trace-4711', 'error_json' => [['loc' => ['buyer'], 'msg' => 'Invalid phone number']]],
                $phone, 'trace-4711', 'a buyer-level phone error names the phone number, and the trace id is logged'),
            array(['http_status' => 422, 'error_trace_id' => 'trace-4712', 'error_json' => [['loc' => ['billing_address', 'city'], 'msg' => 'required'], ['loc' => ['invoice_details', 'invoice_emails', 0], 'msg' => 'bad']]],
                'Please enter a valid City to pay on invoice. Please enter a valid Invoice email address to pay on invoice.', $fieldJson, 'every named field is its own sentence, in order, and the field errors are logged'),
            array(['http_status' => 422, 'error_trace_id' => 'trace-4712', 'error_json' => [['loc' => ['billing_address', 'city'], 'msg' => 'required']]],
                'Please enter a valid City to pay on invoice.', 'trace-4712', 'a 422 with only field errors logs its trace id'),
            array(['http_status' => 400, 'error_json' => [['loc' => ['buyer', 'representative', 'phone_number'], 'msg' => 'x'], ['loc' => ['buyer'], 'msg' => 'Invalid phone number']]],
                $phone, 'HTTP 400', 'two errors naming the same field read as one sentence'),
            array(['http_status' => 400, 'error_json' => [['loc' => ['buyer'], 'msg' => ['Invalid phone number']]]],
                self::GENERIC_REFUSAL, 'HTTP 400', 'a msg that is not text is skipped, not cast'),
            array(['http_status' => 400, 'error_json' => [['loc' => ['billing_address', 'city'], 'msg' => 'required']]],
                'Please enter a valid City to pay on invoice. ' . $hint, 'HTTP 400', 'the minimum hint follows a field sentence as its own sentence', $hint),
            array(['http_status' => 400, 'error_code' => 'SCHEMA_ERROR', 'error_json' => [['loc' => ['line_items', 0, 'tax_rate'], 'msg' => 'bad']]],
                self::GENERIC_REFUSAL, 'SCHEMA_ERROR', 'a field the buyer cannot correct falls back to the generic sentence'),
            array(['http_status' => 400, 'error_code' => 'SAME_BUYER_SELLER_ERROR', 'error_message' => 'same company'],
                $sameCompany . ' ' . $hint, 'SAME_BUYER_SELLER_ERROR', 'buying from yourself is named, closed before the hint', $hint),
            array(['http_status' => 400, 'error_code' => 'SAME_BUYER_SELLER_ERROR', 'error_json' => [['loc' => ['buyer', 'representative', 'phone_number'], 'msg' => 'x']]],
                $phone, 'SAME_BUYER_SELLER_ERROR', 'a named field outranks the same-company refusal'),
            array(['http_status' => 401, 'error_code' => 'UNAUTHORIZED', 'error_message' => 'Invalid API key'],
                self::GENERIC_REFUSAL, 'Invalid API key', 'a rejected key is a refusal like any other'),
            array(['http_status' => 500, 'error_message' => 'Internal error'],
                self::GENERIC_REFUSAL, 'HTTP 500 Internal error', 'a server error is a refusal like any other'),
            array(['http_status' => 502, 'data' => null, 'raw_body' => '<html>Bad gateway</html>'],
                self::GENERIC_REFUSAL, 'Bad gateway', 'a body that is not JSON is logged as received'),
            array(['http_status' => 422, 'error_code' => 'SCHEMA_ERROR', 'error_json' => $longJson, 'error_trace_id' => 'trace-4713'],
                self::GENERIC_REFUSAL, 'HTTP 422 trace-4713', 'the length cap never cuts the trace id, which leads the log'),
            array(['http_status' => 400, 'error_code' => 'SCHEMA_ERROR', 'error_json' => [['loc' => ['buyer'], 'msg' => "bad \xC3\x28 byte"]]],
                self::GENERIC_REFUSAL, 'SCHEMA_ERROR', 'field errors carrying invalid UTF-8 do not cost the rest of the reason'),
            array(['http_status' => 0],
                'Connection error with payment provider. Please try again.', null, 'no answer at all is not a refusal'),
        );

        foreach ($cases as $case) {
            list($response, $buyerMessage, $logged, $description) = $case;
            $controller = self::makeController(null, $response, isset($case[4]) ? $case[4] : '');
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

            try {
                self::runPostProcess($controller);
            } finally {
                unset($_SERVER['HTTP_X_REQUESTED_WITH']);
            }

            TinyAssert::count(1, $controller->emitted, 'one failure reaches the caller: ' . $description);
            TinyAssert::same($buyerMessage, $controller->emitted[0]['message'], 'buyer message: ' . $description);

            $refusals = array_values(array_filter(PrestaShopLogger::$logs, function ($entry) {
                return strpos((string) $entry['message'], 'Two refused order creation for cart ' . self::CART_ID) !== false;
            }));
            if ($logged === null) {
                TinyAssert::count(0, $refusals, 'no refusal logged: ' . $description);
                continue;
            }
            TinyAssert::count(1, $refusals, 'one refusal logged: ' . $description);
            TinyAssert::same(3, $refusals[0]['severity'], 'logged at error severity: ' . $description);
            TinyAssert::same('Cart', $refusals[0]['object_type'], 'logged against the cart: ' . $description);
            TinyAssert::same(self::CART_ID, $refusals[0]['object_id'], 'logged against this cart: ' . $description);
            TinyAssert::true(strpos($refusals[0]['message'], $logged) !== false, 'the log carries Two\'s reason: ' . $description);
        }
    }

    /**
     * TWO-26264. A body that is not JSON reaches order private notes through
     * getTwoApiErrorDetail(), so it is kept as plain text: markup there would fail
     * the note's HTML validation and lose the note.
     */
    private static function testARawResponseBodyIsKeptAsPlainText(): void
    {
        // [raw body, kept text, description].
        $cases = array(
            array("<html><head><script>alert(1)</script></head>\n<body><h1>502</h1>\t Bad   gateway</body></html>", 'alert(1) 502 Bad gateway', 'tags go and whitespace collapses'),
            array('  plain text  ', 'plain text', 'plain text is trimmed and otherwise kept'),
            array('<p>   </p>', '', 'markup with no text keeps nothing'),
            array(str_repeat('a', 600), str_repeat('a', 500), 'at most 500 characters are kept'),
            array('<b>' . str_repeat('b', 600) . '</b>', str_repeat('b', 500), 'the cap applies after the tags are gone'),
        );

        foreach ($cases as $case) {
            list($raw, $kept, $description) = $case;
            TinyAssert::same($kept, Twopayment::sanitizeTwoRawResponseBody($raw), $description);
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

    private static function testXmlHttpRequestHeaderIsDetectedAsAjax(): void
    {
        TinyAssert::true(TwopaymentPaymentModuleFrontController::isTwoAjaxCheckoutRequest(
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => '*/*']
        ), 'XMLHttpRequest header must be treated as an AJAX submit');

        TinyAssert::true(TwopaymentPaymentModuleFrontController::isTwoAjaxCheckoutRequest(
            ['HTTP_X_REQUESTED_WITH' => ' xmlhttprequest ']
        ), 'header comparison must be case- and whitespace-insensitive');
    }

    private static function testJsonOnlyAcceptHeaderIsDetectedAsAjax(): void
    {
        // fetch() callers send no X-Requested-With at all.
        TinyAssert::true(TwopaymentPaymentModuleFrontController::isTwoAjaxCheckoutRequest(
            ['HTTP_ACCEPT' => 'application/json, text/plain, */*']
        ), 'a JSON-only Accept cannot be a page navigation');
    }

    private static function testBrowserNavigationIsNotDetectedAsAjax(): void
    {
        TinyAssert::false(TwopaymentPaymentModuleFrontController::isTwoAjaxCheckoutRequest(
            ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']
        ), 'an ordinary form post must keep the redirect behaviour');

        TinyAssert::false(
            TwopaymentPaymentModuleFrontController::isTwoAjaxCheckoutRequest([]),
            'a request with no headers at all must not be guessed as AJAX'
        );

        // Some front-ends ask for JSON *and* accept HTML; that is still a
        // navigation, so the redirect must stay.
        TinyAssert::false(TwopaymentPaymentModuleFrontController::isTwoAjaxCheckoutRequest(
            ['HTTP_ACCEPT' => 'text/html,application/json']
        ), 'accepting HTML means the caller can render the order page');
    }

    private static function testFailurePayloadFallsBackWhenMessageIsEmpty(): void
    {
        // Internal failures carry no buyer-facing text; an empty message would
        // be a silent hang in the caller's UI.
        $payload = TwopaymentPaymentModuleFrontController::buildTwoCheckoutFailurePayload(
            '   ',
            'Generic failure text.',
            'index.php?controller=order'
        );
        TinyAssert::true($payload['error'], 'payload must be flagged as an error');
        TinyAssert::same('Generic failure text.', $payload['message']);
        TinyAssert::same('index.php?controller=order', $payload['redirect_url']);

        $specific = TwopaymentPaymentModuleFrontController::buildTwoCheckoutFailurePayload(
            'Payment method configuration error.',
            'Generic failure text.',
            'index.php?controller=order'
        );
        TinyAssert::same('Payment method configuration error.', $specific['message']);
    }

    /**
     * Provider rejects order creation: a store with no usable API key, so
     * /v1/order answers 401.
     */
    private static function testProviderRejectionReachesAjaxCallerAsJsonError(): void
    {
        $controller = self::makeController();
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        try {
            self::runPostProcess($controller);
        } finally {
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        }

        TinyAssert::count(1, $controller->emitted);
        $payload = $controller->emitted[0];
        TinyAssert::true($payload['error'], 'AJAX caller must receive an explicit error flag');
        TinyAssert::same(
            self::GENERIC_REFUSAL,
            $payload['message'],
            'the refusal message must survive to the caller instead of being flashed into a session nobody reads'
        );
        TinyAssert::same('index.php?controller=order', $payload['redirect_url']);
        TinyAssert::count(0, $controller->errors);
    }

    private static function testProviderRejectionStillRedirectsBrowserNavigation(): void
    {
        $controller = self::makeController();
        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml';

        try {
            $redirect = self::runPostProcess($controller);
        } finally {
            unset($_SERVER['HTTP_ACCEPT']);
        }

        TinyAssert::count(0, $controller->emitted, 'a navigation must not be answered with JSON');
        TinyAssert::true(
            $redirect !== null && strpos($redirect->getMessage(), 'controller=order') !== false,
            'browser navigation must still be redirected back to checkout'
        );
        TinyAssert::count(1, $controller->errors);
        TinyAssert::same(self::GENERIC_REFUSAL, $controller->errors[0]);
    }

    private static function runPostProcess($controller): ?StubRedirect
    {
        try {
            $controller->postProcess();
        } catch (StubRedirect $redirect) {
            return $redirect;
        } catch (StubJsonFailureEmitted $emitted) {
            return null;
        }

        return null;
    }

    /**
     * @param Exception|null $payloadException Thrown by getTwoNewOrderData() when set
     */
    private static function makeController($payloadException = null, array $createResponse = ['http_status' => 401], string $minimumHint = '')
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
        Tools::setTestValue('token', Tools::getToken(false));
        // An offered term, or the controller refuses before reaching the
        // payload build these specs are about (ABN-533).
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_' . Twopayment::DEFAULT_PAYMENT_TERM_DAYS, 1);

        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[33] = 'FR';
        StubStore::$addresses[self::ADDRESS_ID] = [
            'id_country' => 33,
            'company' => 'Acme FR SAS',
            'companyid' => 'FR123456789',
            'address1' => '10 Rue de Paris',
            'city' => 'Paris',
            'postcode' => '75001',
            'phone' => '+33100000000',
            'loaded' => true,
        ];
        StubStore::$customers[self::CUSTOMER_ID] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Eva',
            'lastname' => 'Martin',
            'secure_key' => 'secure-key-9001',
            'loaded' => true,
        ];
        StubStore::$carts[self::CART_ID] = [
            'id_customer' => self::CUSTOMER_ID,
            'id_currency' => 978,
            'id_address_invoice' => self::ADDRESS_ID,
            'id_address_delivery' => self::ADDRESS_ID,
            'id_carrier' => 0,
            'id_lang' => 1,
        ];

        $context = Context::getContext();
        $context->cart = new Cart(self::CART_ID);
        $context->cookie->two_order_intent_approved = '1';

        $controller = new class extends TwopaymentPaymentModuleFrontController {
            /** @var array<int,array> */
            public array $emitted = [];

            protected function emitTwoCheckoutJsonFailure(array $payload)
            {
                $this->emitted[] = $payload;
                // Stands in for the production `exit`.
                throw new StubJsonFailureEmitted('json failure emitted');
            }
        };
        $controller->module = self::makeModule($payloadException, $createResponse, $minimumHint);

        return $controller;
    }

    /**
     * Module double: /v1/order answers $createResponse, a 401 by default, and
     * any minimum-order hint is $minimumHint.
     * Passing an exception makes the payload build fail instead.
     *
     * @param Exception|null $payloadException
     */
    private static function makeModule($payloadException, array $createResponse, string $minimumHint): Twopayment
    {
        return new class($payloadException, $createResponse, $minimumHint) extends TwopaymentTestHarness {
            /** @var Exception|null */
            private $payloadException;

            /** @var array */
            private $createResponse;

            /** @var string */
            private $minimumHint;

            public function __construct($payloadException, array $createResponse, string $minimumHint)
            {
                parent::__construct();
                $this->payloadException = $payloadException;
                $this->createResponse = $createResponse;
                $this->minimumHint = $minimumHint;
            }

            public function getTwoMinimumOrderDeclineHint($response, $cart)
            {
                return $this->minimumHint;
            }

            public function isCartCurrencySupportedByTwo($cart)
            {
                return true;
            }

            public function getTwoCheckoutCompanyData($address)
            {
                return ['company_name' => 'Acme FR SAS', 'organization_number' => 'FR123456789'];
            }

            public function maybeCleanupStaleTwoCheckoutAttempts($force = false)
            {
                return 0;
            }

            public function checkTwoOrderIntentApprovalAtPayment($cart, $customer, $currency, $address)
            {
                return ['approved' => true, 'status' => 'APPROVED', 'http_status' => 200, 'message' => ''];
            }

            public function generateTwoCheckoutAttemptToken($id_cart, $id_customer)
            {
                return 'attempt-ajax-1';
            }

            public function buildTwoMerchantOrderId($attempt_token, $id_cart)
            {
                return 'merchant-ajax-1';
            }

            public function getTwoNewOrderData($merchant_order_id, $cart, $merchant_urls = null, $syncSurchargeCartLine = true, $trigger = 'checkout')
            {
                if ($this->payloadException !== null) {
                    throw $this->payloadException;
                }

                return ['gross_amount' => '100.00'];
            }

            public function calculateTwoCheckoutSnapshotHash($cart, $paymentdata)
            {
                return 'snapshot-hash';
            }

            public function buildTwoOrderCreateIdempotencyKey($cart, $snapshot_hash)
            {
                return 'idempotency-key';
            }

            public function buildTwoApiResponseLogSummary($response)
            {
                return ['http_status' => isset($response['http_status']) ? (int) $response['http_status'] : 0];
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                return $this->createResponse;
            }
        };
    }
}

class StubJsonFailureEmitted extends Exception
{
}
