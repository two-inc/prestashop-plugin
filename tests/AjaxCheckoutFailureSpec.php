<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/payment.php';

/**
 * TWO-24768: a checkout failure must reach whoever submitted the payment form.
 *
 * The payment controller's only failure signal used to be a 302 back to the
 * order page with the message flashed into the session. A checkout front-end
 * that posts the payment form over XHR instead of navigating - PrestaShop's
 * own checkout module does - follows that redirect transparently, gets the
 * order page's HTML with HTTP 200, and has nothing it can recognise as a
 * failure. The buyer sees a checkout that never moves.
 *
 * These specs drive the real controller over the stub core, with the provider
 * call canned, and assert on the response the caller actually receives.
 */
final class AjaxCheckoutFailureSpec
{
    private const CART_ID = 9601;
    private const CUSTOMER_ID = 9001;
    private const ADDRESS_ID = 9201;

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
    }

    /* ---- payload-build failures: what the buyer is allowed to see ---- */

    /**
     * TWO-25161 information disclosure: payload building walks PrestaShop core,
     * so a core exception can reach the controller's catch. Its message must
     * NOT be relayed - a PrestaShopDatabaseException carries SQL text and
     * table/column names, and since TWO-24768 the same string is also written
     * into the AJAX JSON body. The buyer gets the generic message; the real
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

        // Diagnosability is not lost: the real exception is in the shop log,
        // with its class named.
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
     * No regression on the deliberate part of TWO-25161: an amount diagnostic
     * the plugin itself raised still reaches the buyer with its numbers intact.
     * That detail is what makes a merchant-side cart/shipping misconfiguration
     * diagnosable from the checkout page.
     */
    private static function testPluginAmountDiagnosticStillReachesTheBuyer(): void
    {
        $diagnostic = 'Order totals do not reconcile with cart totals: cart total 150.00 ' .
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

    /** Did any log line mention this fragment? */
    private static function loggedContains(string $needle): bool
    {
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos((string) $entry['message'], $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /* ---- request-shape detection ---- */

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
        // Internal failures deliberately carry no buyer-facing text. Emitting
        // an empty message would reproduce the silent hang in the caller's UI.
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

    /* ---- end-to-end through postProcess ---- */

    /**
     * Provider rejects order creation (the shape seen in the wild: a store
     * with no usable API key, so /v1/order answers 401). This path never went
     * through failCheckout at all - it redirected inline - so it is the one
     * that actually stranded buyers.
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
            'Payment method configuration error. Please contact the store.',
            $payload['message'],
            'the 401 message must survive to the caller instead of being flashed into a session nobody reads'
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
        TinyAssert::same(
            'Payment method configuration error. Please contact the store.',
            $controller->errors[0]
        );
    }

    /* ---- fixtures ---- */

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
    private static function makeController($payloadException = null)
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
        Tools::setTestValue('token', Tools::getToken(false));

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
                // Stands in for the production `exit` without killing the
                // test process.
                throw new StubJsonFailureEmitted('json failure emitted');
            }
        };
        $controller->module = self::makeModule($payloadException);

        return $controller;
    }

    /**
     * Module double: everything the controller needs up to the provider call
     * is canned, and /v1/order answers 401 (no usable API key). Passing an
     * exception makes the payload build itself fail instead.
     *
     * @param Exception|null $payloadException
     */
    private static function makeModule($payloadException = null): Twopayment
    {
        return new class($payloadException) extends TwopaymentTestHarness {
            /** @var Exception|null */
            private $payloadException;

            public function __construct($payloadException = null)
            {
                parent::__construct();
                $this->payloadException = $payloadException;
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

            public function getTwoNewOrderData($merchant_order_id, $cart, $merchant_urls = null, $syncSurchargeCartLine = true)
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
                return ['http_status' => 401];
            }
        };
    }
}

class StubJsonFailureEmitted extends Exception
{
}
