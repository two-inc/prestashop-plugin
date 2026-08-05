<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/payment.php';

/**
 * TWO-25326 - API-key verification failures are not one failure.
 *
 * Before this, every non-200 from GET /v1/merchant/verify_api_key collapsed
 * into a single `false`: a rejected key, a 5xx on Two's side and a shop that
 * could not reach Two at all were the same thing. The merchant was told
 * "check your API key" in all three cases, which is advice for exactly one of
 * them, and the checkout gates could not consult the verdict at all because
 * verification was a live HTTP call on the merchant's save path only - so the
 * payment option and the company-search control kept rendering on an
 * integration that could not authenticate.
 *
 * Contract pinned here:
 *
 *  - CATEGORIES. 401/403 -> invalid_key, 5xx -> service_error, no response at
 *    all -> unreachable, any other non-200 (and an unreadable 200 body) ->
 *    error, no stored key -> not_configured, 200 + merchant record -> ok.
 *  - MERCHANT SURFACE. The config page states the category and the HTTP
 *    status, and never the response body.
 *  - CHECKOUT GATE. Two is withheld for EVERY non-ok category, not just
 *    invalid_key, and is offered when the key verifies. The withholding is
 *    logged, because a silently absent payment method is the failure this
 *    ticket exists to remove.
 *  - COMPANY SEARCH. The same verdict reaches the browser as a real boolean,
 *    so the address-step control can stand down on a shop where Two is not
 *    available (the JS side of that is tests/js/api-key-verification.test.js).
 *  - CACHE. The verdict is read from Configuration with a TTL clock, so a
 *    checkout render costs no HTTP call; a failing verdict is retried sooner
 *    than a healthy one; a different key never inherits the previous key's
 *    verdict; and the config-page save publishes its own fresh verdict so a
 *    just-fixed key does not wait out the TTL.
 */
final class ApiKeyVerificationSpec
{
    public static function runAll(): void
    {
        // Categorisation.
        self::testUnauthorizedAndForbiddenAreKeyRejections();
        self::testServerErrorsAreServiceErrors();
        self::testTransportFailureIsUnreachableNotAnInvalidKey();
        self::testOtherNonOkCodesAreGenericErrors();
        self::testUnreadableBodyOnHttp200IsNotAVerifiedKey();
        self::testVerifiedKeyReturnsTheMerchantRecord();
        self::testMissingKeyIsNotConfigured();

        // Merchant-facing surface.
        self::testEachCategoryGetsItsOwnWording();
        self::testNoticeNeverLeaksTheResponseBody();
        self::testNoticeIsSilentWhenVerifiedOrUnconfigured();
        self::testSaveReportsTheCategoryAndPublishesTheVerdict();
        self::testVerifiedPanelFollowsTheLiveVerdict();

        // Checkout gate.
        self::testEveryFailureCategoryWithholdsThePaymentOption();
        self::testVerifiedKeyKeepsThePaymentOption();
        self::testWithholdingThePaymentOptionIsLogged();
        self::testWithholdReasonIsLoggedOncePerRequestNotPerCall();
        self::testPaymentSubmissionIsRefusedWhenTheKeyDoesNotVerify();

        // Company-search gate.
        self::testCheckoutConfigCarriesTheVerdictAsABoolean();

        // Cache.
        self::testCheckoutRendersReuseOneVerification();
        self::testFailingVerdictIsRetriedSoonerThanAHealthyOne();
        self::testColdCacheClaimStopsConcurrentVerifications();
        self::testAnAbandonedClaimExpiresQuickly();
        self::testChangedKeyNeverInheritsThePreviousVerdict();
    }

    /* ===================================================================
     * Harness
     * =================================================================== */

    /**
     * A module whose verify_api_key wire call is stubbed and counted.
     *
     * @param array $outcome requestTwoApiKeyVerification()'s return shape:
     *   ['response' => string|false, 'code' => int, 'error' => string]
     */
    private static function module(array $outcome): object
    {
        self::reset();

        return new class ($outcome) extends TwopaymentTestHarness {
            public int $verifyCalls = 0;
            /** @var array<int,int|null> */
            public array $verifyTimeouts = [];
            private array $outcome;

            public function __construct(array $outcome)
            {
                parent::__construct();
                $this->outcome = $outcome;
                // The real thing, not the harness's primed verdict: these
                // specs are about how the verdict is REACHED.
                $this->primeTwoApiKeyStatus(null);
            }

            /** @var callable|null runs INSIDE the wire call, i.e. mid-flight */
            private $duringWireCall = null;

            public function onWireCall(callable $callback): void
            {
                $this->duringWireCall = $callback;
            }

            protected function requestTwoApiKeyVerification($apiKey, $environment, $timeout = null)
            {
                $this->verifyCalls++;
                $this->verifyTimeouts[] = $timeout;
                if ($this->duringWireCall !== null) {
                    call_user_func($this->duringWireCall);
                }
                return $this->outcome;
            }

            /** @return array{status:string,code:int|null,body:array|null} */
            public function verifyForTest(string $apiKey, string $environment = 'development'): array
            {
                return $this->verifyTwoApiKey($apiKey, $environment);
            }

            public function noticeForTest(): string
            {
                return $this->getTwoApiKeyStatusNotice();
            }

            /** @return array<int,string> */
            public function validateGeneralFormForTest(): array
            {
                $this->errors = array();
                $this->validTwoGeneralFormValues();
                return $this->errors;
            }

            public function saveGeneralFormForTest(): void
            {
                $this->saveTwoGeneralFormValues();
            }

            /** The config page's own health row / verified panel source. */
            public function verifiedPanelFlagForTest(): int
            {
                return (int) $this->isTwoApiKeyVerified();
            }

            protected function getTwoPaymentOption()
            {
                return (object) ['method' => 'two'];
            }
        };
    }

    private static function reset(): void
    {
        StubStore::reset();
        Tools::resetTestValues();
        PrestaShopLogger::reset();
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'stored-key');
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'development');
    }

    /** A response the endpoint would consider a success. */
    private static function okOutcome(): array
    {
        return array(
            'response' => json_encode(array('id' => 'm-123', 'short_name' => 'acme')),
            'code' => 200,
            'error' => '',
        );
    }

    private static function httpOutcome(int $code, string $body = 'the raw upstream body'): array
    {
        return array('response' => $body, 'code' => $code, 'error' => '');
    }

    private static function transportOutcome(): array
    {
        return array('response' => false, 'code' => 0, 'error' => 'Could not resolve host');
    }

    /** POST values a minimal valid general-form save needs. */
    private static function generalFormPost(string $apiKey): void
    {
        Tools::setTestValue('PS_TWO_ENVIRONMENT', 'development');
        Tools::setTestValue('PS_TWO_TITLE_1', 'Two');
        Tools::setTestValue('PS_TWO_SUB_TITLE_1', 'Pay later');
        Tools::setTestValue('PS_TWO_MERCHANT_SHORT_NAME', 'merchant');
        Tools::setTestValue('PS_TWO_MERCHANT_API_KEY', $apiKey);
    }

    /** Writes a stored verdict for $apiKey, stamped $age seconds ago. */
    private static function storeVerdict(string $status, ?int $code, string $apiKey, int $age = 0): void
    {
        Configuration::updateValue(Twopayment::CONFIG_API_KEY_STATUS, json_encode(array(
            'status' => $status,
            'code' => $code,
            'key_hash' => md5($apiKey),
        )));
        Configuration::updateValue(Twopayment::CONFIG_API_KEY_STATUS_TS, time() - $age);
    }

    /** A cart that clears every OTHER hookPaymentOptions gate. */
    private static function offerableCart(object $module): void
    {
        StubStore::$countries[826] = 'GB';
        StubStore::$addresses[904] = [
            'id_country' => 826,
            'company' => 'Acme UK Ltd',
            'vat_number' => 'GB123456789',
            'loaded' => true,
        ];
        StubStore::$currencies[826] = ['iso_code' => 'GBP', 'loaded' => true];
        StubStore::$moduleCurrencies['twopayment'] = [['id_currency' => 826]];

        $cart = new Cart(7326);
        $cart->id_address_invoice = 904;
        $cart->id_currency = 826;
        $module->context->cart = $cart;
    }

    /* ===================================================================
     * Categorisation
     * =================================================================== */

    private static function testUnauthorizedAndForbiddenAreKeyRejections(): void
    {
        foreach ([401, 403] as $code) {
            $module = self::module(self::httpOutcome($code));
            $result = $module->verifyForTest('stored-key');
            TinyAssert::same(Twopayment::API_KEY_STATUS_INVALID, $result['status'], 'HTTP ' . $code . ' is a rejected key');
            TinyAssert::same($code, $result['code']);
            TinyAssert::same(null, $result['body'], 'a rejected key must not carry a merchant record');
        }
    }

    private static function testServerErrorsAreServiceErrors(): void
    {
        foreach ([500, 502, 503] as $code) {
            $module = self::module(self::httpOutcome($code));
            $result = $module->verifyForTest('stored-key');
            TinyAssert::same(
                Twopayment::API_KEY_STATUS_SERVICE_ERROR,
                $result['status'],
                'HTTP ' . $code . ' is Two failing, not the merchant\'s key failing'
            );
            TinyAssert::same($code, $result['code']);
        }
    }

    /**
     * The failure the incident was actually made of: nothing came back, so no
     * verdict on the key was ever reached. Reporting it as an invalid key sends
     * the merchant to re-paste a key that was fine.
     */
    private static function testTransportFailureIsUnreachableNotAnInvalidKey(): void
    {
        $module = self::module(self::transportOutcome());
        $result = $module->verifyForTest('stored-key');

        TinyAssert::same(Twopayment::API_KEY_STATUS_UNREACHABLE, $result['status']);
        TinyAssert::notSame(Twopayment::API_KEY_STATUS_INVALID, $result['status']);
        TinyAssert::same(null, $result['code'], 'there is no HTTP status when there was no response');
    }

    private static function testOtherNonOkCodesAreGenericErrors(): void
    {
        $module = self::module(self::httpOutcome(418));
        $result = $module->verifyForTest('stored-key');

        TinyAssert::same(Twopayment::API_KEY_STATUS_ERROR, $result['status']);
        TinyAssert::same(418, $result['code']);
    }

    /**
     * A 200 carrying something that is not the merchant record - a proxy error
     * page, a captive portal - is not a verified key. 'error', not
     * 'invalid_key': the key was never judged by Two at all.
     */
    private static function testUnreadableBodyOnHttp200IsNotAVerifiedKey(): void
    {
        $module = self::module(self::httpOutcome(200, '<html>Gateway login required</html>'));
        $result = $module->verifyForTest('stored-key');

        TinyAssert::same(Twopayment::API_KEY_STATUS_ERROR, $result['status']);
        TinyAssert::notSame(Twopayment::API_KEY_STATUS_OK, $result['status']);
    }

    private static function testVerifiedKeyReturnsTheMerchantRecord(): void
    {
        $module = self::module(self::okOutcome());
        $result = $module->verifyForTest('stored-key');

        TinyAssert::same(Twopayment::API_KEY_STATUS_OK, $result['status']);
        TinyAssert::same(200, $result['code']);
        TinyAssert::same('m-123', $result['body']['id']);
        TinyAssert::same('acme', $result['body']['short_name']);
    }

    /**
     * No key stored is its own category, and it must not cost a wire call: a
     * fresh install has nothing to verify.
     */
    private static function testMissingKeyIsNotConfigured(): void
    {
        $module = self::module(self::okOutcome());
        $result = $module->verifyForTest('');

        TinyAssert::same(Twopayment::API_KEY_STATUS_NOT_CONFIGURED, $result['status']);
        TinyAssert::same(0, $module->verifyCalls, 'nothing to verify must not reach the network');

        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', '');
        TinyAssert::same(
            Twopayment::API_KEY_STATUS_NOT_CONFIGURED,
            $module->getTwoApiKeyVerificationStatus()['status']
        );
        TinyAssert::same(0, $module->verifyCalls);
    }

    /* ===================================================================
     * Merchant-facing surface
     * =================================================================== */

    /**
     * The point of the whole ticket: four different failures must not read as
     * the same sentence, and the wording must say which one happened.
     */
    private static function testEachCategoryGetsItsOwnWording(): void
    {
        $module = self::module(self::okOutcome());

        $messages = array();
        foreach (
            array(
                Twopayment::API_KEY_STATUS_INVALID => 401,
                Twopayment::API_KEY_STATUS_SERVICE_ERROR => 503,
                Twopayment::API_KEY_STATUS_UNREACHABLE => null,
                Twopayment::API_KEY_STATUS_ERROR => 418,
                Twopayment::API_KEY_STATUS_NOT_CONFIGURED => null,
            ) as $status => $code
        ) {
            $messages[$status] = $module->getTwoApiKeyFailureMessage($status, $code);
            TinyAssert::true($messages[$status] !== '', $status . ' must have wording');
        }

        TinyAssert::same(count($messages), count(array_unique($messages)), 'every category must read differently');
        // The status is what makes a service error diagnosable at all.
        TinyAssert::true(
            strpos($messages[Twopayment::API_KEY_STATUS_SERVICE_ERROR], '503') !== false,
            'a service error must state the HTTP status'
        );
        TinyAssert::true(
            strpos($messages[Twopayment::API_KEY_STATUS_ERROR], '418') !== false,
            'an unexpected response must state the HTTP status'
        );
    }

    /**
     * Categories and status codes are for the merchant; the response body is
     * for the log. A back-office notice that pastes the upstream body back out
     * is what the old debug payload did.
     */
    private static function testNoticeNeverLeaksTheResponseBody(): void
    {
        $body = 'SECRET-UPSTREAM-BODY';
        $module = self::module(self::httpOutcome(401, $body));

        $module->cacheTwoApiKeyVerificationStatus('stored-key', $module->verifyForTest('stored-key'));
        $notice = $module->noticeForTest();

        TinyAssert::true($notice !== '', 'a rejected key must be reported on the config page');
        TinyAssert::true(strpos($notice, $body) === false, 'the response body must not reach the back office');
        // And the stored verdict itself carries no body either.
        $stored = json_decode((string) Configuration::get(Twopayment::CONFIG_API_KEY_STATUS), true);
        TinyAssert::true(strpos(json_encode($stored), $body) === false, 'the cached verdict must not store the body');
    }

    private static function testNoticeIsSilentWhenVerifiedOrUnconfigured(): void
    {
        $module = self::module(self::okOutcome());
        $module->cacheTwoApiKeyVerificationStatus('stored-key', $module->verifyForTest('stored-key'));
        TinyAssert::same('', $module->noticeForTest(), 'a working integration needs no notice');

        // No key at all is the form's own "Enter an API key" validation; a
        // second notice saying the same thing is noise.
        $fresh = self::module(self::okOutcome());
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', '');
        TinyAssert::same('', $fresh->noticeForTest());
    }

    /**
     * The save path is the merchant's own live re-check, so it both reports the
     * category and becomes the verdict checkout reads - otherwise a merchant
     * who has just pasted a working key waits out the TTL before Two returns.
     */
    private static function testSaveReportsTheCategoryAndPublishesTheVerdict(): void
    {
        $module = self::module(self::transportOutcome());
        self::generalFormPost('stored-key');

        $errors = $module->validateGeneralFormForTest();

        TinyAssert::same(1, count($errors), 'an unreachable API must be reported once');
        TinyAssert::same(
            $module->getTwoApiKeyFailureMessage(Twopayment::API_KEY_STATUS_UNREACHABLE),
            $errors[0],
            'the save must report the category, not a generic "check your API key"'
        );

        // Validation ALONE must not publish a verdict: PrestaShop skips the
        // save when any field failed, and a verdict for a key the shop does not
        // store would then describe nothing the gates can act on (and would
        // make the config-page notice re-verify a second time in the same
        // request).
        TinyAssert::same(
            '',
            (string) Configuration::get(Twopayment::CONFIG_API_KEY_STATUS),
            'a validation-only pass must not write a cached verdict'
        );

        // The save is what publishes it.
        $module->saveGeneralFormForTest();
        TinyAssert::same(
            Twopayment::API_KEY_STATUS_UNREACHABLE,
            $module->getTwoApiKeyVerificationStatus()['status'],
            'the save\'s own live check must become the verdict the gates read'
        );

        // The recovery direction: a save that verifies must not leave the
        // previous failure cached, and must not cost a second verification.
        $fixed = self::module(self::okOutcome());
        self::generalFormPost('stored-key');
        self::storeVerdict(Twopayment::API_KEY_STATUS_INVALID, 401, 'stored-key');

        TinyAssert::same(0, count($fixed->validateGeneralFormForTest()), 'a verifying key must save cleanly');
        $fixed->saveGeneralFormForTest();
        TinyAssert::same(Twopayment::API_KEY_STATUS_OK, $fixed->getTwoApiKeyVerificationStatus()['status']);
        TinyAssert::same(1, $fixed->verifyCalls, 'the save must reuse its own check, not verify twice');
    }

    /**
     * The config page used to read a sticky flag written only at save time, so
     * a key that later expired rendered the green "verified" panel directly
     * above the red notice saying Two is hidden from checkout (TWO-25326,
     * review round 1).
     */
    private static function testVerifiedPanelFollowsTheLiveVerdict(): void
    {
        $module = self::module(self::httpOutcome(401));
        // What a save on a then-working key left behind.
        Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 1);
        $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_INVALID, 401);

        TinyAssert::same(0, $module->verifiedPanelFlagForTest(), 'the panel must follow the current verdict');
        TinyAssert::true($module->noticeForTest() !== '', 'and the failure notice must still be shown');

        // The two consumers themselves, asserted against the source: neither
        // getContent()'s `two_api_verified` template variable nor the health
        // checklist's "API key" row can be reached without a HelperForm, and
        // both are one-line reads that would silently go back to the sticky
        // save-time flag under an unrelated edit. Same source-level approach
        // AssetCacheBustingSpec uses for structure it cannot execute.
        $source = (string) file_get_contents(dirname(__DIR__) . '/twopayment.php');
        TinyAssert::true(
            strpos($source, "'two_api_verified' => (int) \$this->isTwoApiKeyVerified()") !== false,
            "getContent()'s two_api_verified must come from the live verdict"
        );
        TinyAssert::true(
            strpos($source, "\$api_verified = \$this->isTwoApiKeyVerified()") !== false,
            'the health checklist API-key row must come from the live verdict'
        );
        TinyAssert::same(
            0,
            substr_count($source, "Configuration::get('PS_TWO_API_KEY_VERIFIED')"),
            'PS_TWO_API_KEY_VERIFIED is a save-time record only - it must have no readers left'
        );
    }

    /* ===================================================================
     * Checkout gate
     * =================================================================== */

    /**
     * ANY category, not just invalid_key. An outage and a routing failure are
     * exactly the cases where the old code kept offering Two and handed the
     * buyer a dead end at the last step.
     */
    private static function testEveryFailureCategoryWithholdsThePaymentOption(): void
    {
        $failures = array(
            Twopayment::API_KEY_STATUS_INVALID => 401,
            Twopayment::API_KEY_STATUS_SERVICE_ERROR => 503,
            Twopayment::API_KEY_STATUS_UNREACHABLE => null,
            Twopayment::API_KEY_STATUS_ERROR => 418,
            Twopayment::API_KEY_STATUS_NOT_CONFIGURED => null,
        );

        foreach ($failures as $status => $code) {
            $module = self::module(self::okOutcome());
            $module->primeTwoApiKeyStatus($status, $code);
            self::offerableCart($module);

            TinyAssert::same(
                0,
                count($module->hookPaymentOptions([])),
                'verification status "' . $status . '" must withhold the payment option'
            );
        }
    }

    private static function testVerifiedKeyKeepsThePaymentOption(): void
    {
        $module = self::module(self::okOutcome());
        $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_OK, 200);
        self::offerableCart($module);

        TinyAssert::same(1, count($module->hookPaymentOptions([])), 'a verified key must still be offered Two');
    }

    private static function testWithholdingThePaymentOptionIsLogged(): void
    {
        $module = self::module(self::okOutcome());
        $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_SERVICE_ERROR, 503);
        self::offerableCart($module);
        PrestaShopLogger::reset();

        $module->hookPaymentOptions([]);

        $logged = '';
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'API key verification status') !== false) {
                $logged = $entry['message'];
            }
        }
        TinyAssert::true($logged !== '', 'hiding the payment option must say why in the log');
        TinyAssert::true(strpos($logged, 'service_error') !== false, 'the log must name the category');
        TinyAssert::true(strpos($logged, '503') !== false, 'the log must carry the HTTP status');
    }


    /**
     * PrestaShop asks for payment options several times per payment-step
     * render. The reason needs saying once, not once per ask, or a broken shop
     * with traffic buries it in its own repetition (review round 1).
     */
    private static function testWithholdReasonIsLoggedOncePerRequestNotPerCall(): void
    {
        $module = self::module(self::okOutcome());
        $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_UNREACHABLE, null);
        self::offerableCart($module);
        PrestaShopLogger::reset();

        $module->hookPaymentOptions([]);
        $module->hookPaymentOptions([]);
        $module->hookPaymentOptions([]);

        $lines = 0;
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'API key verification status') !== false) {
                ++$lines;
            }
        }
        TinyAssert::same(1, $lines, 'the withhold reason must be logged once per request');
    }

    /**
     * The payment option being withheld only stops the buyer who loads the page
     * after the verdict changed. One who was already looking at the payment step
     * can still submit, and that submission has to be refused here rather than
     * failing opaquely at order creation (review round 1).
     */
    private static function testPaymentSubmissionIsRefusedWhenTheKeyDoesNotVerify(): void
    {
        $module = self::module(self::okOutcome());
        $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_INVALID, 401);

        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[33] = 'FR';
        StubStore::$addresses[9201] = ['id_country' => 33, 'company' => 'Acme FR SAS', 'loaded' => true];
        StubStore::$customers[9001] = ['email' => 'buyer@example.com', 'loaded' => true];
        StubStore::$carts[9601] = [
            'id_customer' => 9001,
            'id_currency' => 978,
            'id_address_invoice' => 9201,
            'id_address_delivery' => 9201,
            'id_carrier' => 0,
            'id_lang' => 1,
        ];

        $context = Context::getContext();
        $context->cart = new Cart(9601);

        $controller = new TwopaymentPaymentModuleFrontController();
        $controller->module = $module;
        PrestaShopLogger::reset();

        // The refusal path ends in redirectWithNotifications(), which the stub
        // core raises instead of redirecting.
        $refused = false;
        try {
            $controller->postProcess();
        } catch (StubRedirect $e) {
            $refused = true;
        }

        TinyAssert::true($refused, 'a submission on an unverifiable key must be refused, not processed');
        $logged = false;
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'API key does not verify') !== false) {
                $logged = true;
            }
        }
        TinyAssert::true($logged, 'the refusal must be logged');
    }

    /* ===================================================================
     * Company-search gate (server side of it)
     * =================================================================== */

    /**
     * The browser decides whether to mount the address-step company search on
     * this key, and it must arrive as a real boolean - `'0'`-style strings are
     * what the location switch uses, and mixing the two is how a truthy '0'
     * turns a gate off by accident.
     */
    private static function testCheckoutConfigCarriesTheVerdictAsABoolean(): void
    {
        foreach (array(Twopayment::API_KEY_STATUS_OK => true, Twopayment::API_KEY_STATUS_UNREACHABLE => false) as $status => $expected) {
            $module = self::module(self::okOutcome());
            $module->primeTwoApiKeyStatus($status, null);

            $verified = $module->isTwoApiKeyVerified();
            TinyAssert::true(is_bool($verified), 'the verdict handed to the JS must be a real boolean');
            TinyAssert::same($expected, $verified, 'status "' . $status . '" verified?');
        }
    }

    /* ===================================================================
     * Cache
     * =================================================================== */

    /**
     * hookPaymentOptions and the media hook both ask, several times per
     * checkout render. One verification per TTL, not one per question - and
     * the render-path call must carry the tight timeout, because the TTL
     * bounds how OFTEN it happens and never how long one call may block.
     */
    private static function testCheckoutRendersReuseOneVerification(): void
    {
        $module = self::module(self::okOutcome());

        $module->getTwoApiKeyVerificationStatus();
        $module->getTwoApiKeyVerificationStatus();
        TinyAssert::same(1, $module->verifyCalls, 'the request-scoped memo must absorb repeat questions');
        TinyAssert::same(
            Twopayment::API_TIMEOUT_STATE_CHECK,
            $module->verifyTimeouts[0],
            'a verification on a shopper\'s render path must use the tight timeout'
        );

        // A second request (fresh instance, so no memo) reads the stored
        // verdict rather than re-verifying.
        $next = self::module(self::okOutcome());
        Configuration::updateValue(Twopayment::CONFIG_API_KEY_STATUS, json_encode(array(
            'status' => Twopayment::API_KEY_STATUS_OK,
            'code' => 200,
            'key_hash' => md5('stored-key'),
        )));
        Configuration::updateValue(Twopayment::CONFIG_API_KEY_STATUS_TS, time());

        TinyAssert::same(Twopayment::API_KEY_STATUS_OK, $next->getTwoApiKeyVerificationStatus()['status']);
        TinyAssert::same(0, $next->verifyCalls, 'a fresh request within the TTL must not re-verify');
    }

    /**
     * Recovery must not wait as long as a healthy shop's re-check: a resolved
     * outage, or a key rotated in the portal, should reach checkout in about a
     * minute, while a working shop is not re-verified every minute for nothing.
     */
    private static function testFailingVerdictIsRetriedSoonerThanAHealthyOne(): void
    {
        TinyAssert::true(
            Twopayment::API_KEY_STATUS_FAILURE_TTL < Twopayment::API_KEY_STATUS_TTL,
            'a failing verdict must expire sooner than a healthy one'
        );

        $ageBetweenTtls = Twopayment::API_KEY_STATUS_FAILURE_TTL + 1;

        // Failed verdict, older than the failure TTL: re-verified.
        $failing = self::module(self::okOutcome());
        self::storeVerdict(Twopayment::API_KEY_STATUS_SERVICE_ERROR, 503, 'stored-key', $ageBetweenTtls);

        TinyAssert::same(Twopayment::API_KEY_STATUS_OK, $failing->getTwoApiKeyVerificationStatus()['status']);
        TinyAssert::same(1, $failing->verifyCalls, 'a stale FAILED verdict must be re-verified');

        // A healthy verdict of exactly the same age is still good. The stub
        // would answer 503, so a re-verification here would be visible in the
        // status as well as in the call count.
        $healthy = self::module(self::httpOutcome(503));
        self::storeVerdict(Twopayment::API_KEY_STATUS_OK, 200, 'stored-key', $ageBetweenTtls);

        TinyAssert::same(Twopayment::API_KEY_STATUS_OK, $healthy->getTwoApiKeyVerificationStatus()['status']);
        TinyAssert::same(0, $healthy->verifyCalls, 'a healthy verdict of the same age is still fresh');

        // Past the long TTL it is re-verified too. The verdict JSON is written
        // again here on purpose: writing only the clock would make this pass on
        // the empty-slot branch instead of the expiry branch, and the long TTL
        // would then have no coverage at all.
        $expired = self::module(self::httpOutcome(401));
        self::storeVerdict(Twopayment::API_KEY_STATUS_OK, 200, 'stored-key', Twopayment::API_KEY_STATUS_TTL + 1);

        TinyAssert::same(Twopayment::API_KEY_STATUS_INVALID, $expired->getTwoApiKeyVerificationStatus()['status']);
        TinyAssert::same(1, $expired->verifyCalls, 'a healthy verdict past its TTL must be re-verified');
    }

    /**
     * Anti-stampede (review round 1). The clock is claimed BEFORE the wire call,
     * so concurrent renders on a cold cache stand down instead of each firing
     * their own verification - which on an unreachable API costs every one of
     * them the full timeout, once per failure TTL, for the length of the
     * outage.
     */
    private static function testColdCacheClaimStopsConcurrentVerifications(): void
    {
        // The claim is observable as a stored slot that a SECOND request (fresh
        // instance, no memo) treats as fresh, written while the first request's
        // wire call is still in flight. Reaching into the call itself is how a
        // second request is simulated here.
        $module = self::module(self::okOutcome());
        $secondRequestSaw = null;

        $module->onWireCall(function () use (&$secondRequestSaw) {
            $concurrent = new class extends TwopaymentTestHarness {
                public int $verifyCalls = 0;

                public function __construct()
                {
                    parent::__construct();
                    $this->primeTwoApiKeyStatus(null);
                }

                protected function requestTwoApiKeyVerification($apiKey, $environment, $timeout = null)
                {
                    $this->verifyCalls++;
                    return array('response' => false, 'code' => 0, 'error' => 'should not be reached');
                }
            };
            $concurrent->getTwoApiKeyVerificationStatus();
            $secondRequestSaw = $concurrent->verifyCalls;
        });

        $module->getTwoApiKeyVerificationStatus();

        TinyAssert::same(1, $module->verifyCalls, 'the first request verifies');
        TinyAssert::same(0, (int) $secondRequestSaw, 'a concurrent request must not fire its own verification');
    }

    /**
     * ...and a claim that is never superseded (the claiming process died
     * mid-call) must expire quickly rather than standing in for a real verdict
     * for a whole TTL.
     */
    private static function testAnAbandonedClaimExpiresQuickly(): void
    {
        $module = self::module(self::okOutcome());
        $abandoned = null;

        $module->onWireCall(function () use (&$abandoned) {
            $abandoned = array(
                'raw' => (string) Configuration::get(Twopayment::CONFIG_API_KEY_STATUS),
                'ts' => (int) Configuration::get(Twopayment::CONFIG_API_KEY_STATUS_TS),
            );
        });
        $module->getTwoApiKeyVerificationStatus();

        TinyAssert::true(is_array($abandoned), 'the claim must be written before the wire call');
        $decoded = json_decode($abandoned['raw'], true);
        TinyAssert::same(md5('stored-key'), $decoded['key_hash'], 'the claim belongs to the key being verified');

        // The claim was back-dated so it survives the call it covers and little
        // more.
        $remaining = ($abandoned['ts'] + Twopayment::API_KEY_STATUS_TTL) - time();
        TinyAssert::true(
            $remaining <= Twopayment::API_KEY_STATUS_CLAIM_WINDOW,
            'a claim must not stand in for a verdict for a whole TTL (got ' . $remaining . 's)'
        );
        TinyAssert::true(
            Twopayment::API_KEY_STATUS_CLAIM_WINDOW > Twopayment::API_TIMEOUT_STATE_CHECK,
            'the claim window must outlast the call it covers'
        );
    }

    /**
     * A verdict belongs to the key it was reached for. Without this, pasting a
     * replacement key inherits the old key's "invalid" for a whole TTL - the
     * merchant fixes the problem and the page insists it is still broken.
     */
    private static function testChangedKeyNeverInheritsThePreviousVerdict(): void
    {
        $module = self::module(self::okOutcome());
        Configuration::updateValue(Twopayment::CONFIG_API_KEY_STATUS, json_encode(array(
            'status' => Twopayment::API_KEY_STATUS_INVALID,
            'code' => 401,
            'key_hash' => md5('the-old-key'),
        )));
        Configuration::updateValue(Twopayment::CONFIG_API_KEY_STATUS_TS, time());

        $status = $module->getTwoApiKeyVerificationStatus();

        TinyAssert::same(Twopayment::API_KEY_STATUS_OK, $status['status'], 'a different key must be verified afresh');
        TinyAssert::same(1, $module->verifyCalls);
    }
}
