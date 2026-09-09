<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/payment.php';

/** TWO-25326 - API-key verification failures are not one failure. */
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
        self::testARecordlessHttp200IsNotAVerifiedKey();
        self::testVerifiedKeyReturnsTheMerchantRecord();
        self::testARecordWithNoShortNameStillVerifies();
        self::testMissingKeyIsNotConfigured();

        // Merchant-facing surface.
        self::testEachCategoryGetsItsOwnWording();
        self::testNoticeNeverLeaksTheResponseBody();
        self::testNoticeIsSilentWhenVerifiedOrUnconfigured();
        self::testNoticeSaysNothingWhileAVerificationIsStillRunning();
        self::testSaveReportsTheCategoryAndPublishesTheVerdict();
        self::testARejectedKeyIsTheOnlyValueASaveDiscards();
        self::testAnIdentityChangeRefreshesTheRecordAndNeverClearsIt();
        self::testTheWarningIsRenderedAlongsideTheSaveConfirmation();
        self::testVerifiedPanelFollowsTheLiveVerdict();

        // Checkout gate.
        self::testOnlyADefinitiveRejectionWithholdsThePaymentOption();
        self::testARecordlessHttp200IsCategorisedButDoesNotWithhold();
        self::testWithholdingThePaymentOptionIsLogged();
        self::testWithholdReasonIsLoggedOncePerRequestNotPerCall();
        self::testPaymentSubmissionIsRefusedWhenTheKeyDoesNotVerify();
        self::testPaymentSubmissionSurvivesATransientFailure();
        self::testPaymentControllerAsksTheDefinitiveQuestionNotTheRenderOne();
        self::testAStaleRejectedKeyStillRefusesASubmission();
        self::testAStaleRejectionNeverFollowsAReplacedKeyOrEnvironment();

        // Company-search gate.
        self::testCheckoutConfigCarriesTheVerdictAsABoolean();
        self::testOnlyTheCheckoutPageMayPayForAVerification();

        // Cache.
        self::testCheckoutRendersReuseOneVerification();
        self::testFailingVerdictIsRetriedSoonerThanAHealthyOne();
        self::testColdCacheClaimStopsConcurrentVerifications();
        self::testAnAbandonedClaimExpiresQuickly();
        self::testAClaimWithNoPriorVerdictKeepsTheGatesClosed();
        self::testAClaimCarriesAPriorVerdictSoReVerificationDoesNotBlinkTwoOff();
        self::testAnEnvironmentChangeInvalidatesTheVerdict();
        self::testAnAncientVerdictIsNotCarriedForever();
        self::testAClaimCarriesTheVerdictsOriginalAgeNotAFreshOne();
        self::testASlotWithoutAVerdictClockIsStillCarryable();
        self::testSwitchingEnvironmentAloneNeverPublishesAVerdict();
        self::testChangedKeyNeverInheritsThePreviousVerdict();

        // Inline live check (TWO-25386 #4).
        self::testLiveCheckReportsOkForAVerifiedKey();
        self::testLiveCheckReportsTheFailureMessageForARejectedKey();
        self::testLiveCheckNeverTouchesConfigurationBeforeSave();
        self::testLiveCheckDoesNotCallOutForAnEmptyKeyOrEnvironment();
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

            public function setPathForTest(string $path): void
            {
                $this->_path = $path;
            }

            /**
             * The merchant-record and FX refreshes the checkout media hook makes
             * are not this spec's subject and must not reach the network stub for
             * the API key.
             */
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                return array('http_status' => 500);
            }

            /** @return array{status:string,code:int|null,body:array|null} */
            public function verifyForTest(string $apiKey, string $environment = 'staging'): array
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
                $this->apiKeyVerificationWarning = null;
                $this->validTwoGeneralFormValues();
                return $this->errors;
            }

            public function saveGeneralFormForTest(): void
            {
                $this->saveTwoGeneralFormValues();
            }

            public function generalFormWarningForTest(): ?string
            {
                return $this->apiKeyVerificationWarning;
            }

            public function verifiedPanelFlagForTest(): int
            {
                return (int) $this->isTwoApiKeyVerified();
            }

            /** @return array{status:string,ok:bool,message:string} */
            public function liveCheckForTest($apiKey, $environment): array
            {
                return $this->buildApiKeyLiveVerificationResult($apiKey, $environment);
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
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'staging');
    }

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

    /** A 200 the endpoint answered, carrying no merchant record. */
    private static function recordlessOutcome(): array
    {
        return array('response' => json_encode(array('detail' => 'ok')), 'code' => 200, 'error' => '');
    }

    private static function transportOutcome(): array
    {
        return array('response' => false, 'code' => 0, 'error' => 'Could not resolve host');
    }

    private static function generalFormPost(string $apiKey): void
    {
        Tools::setTestValue('PS_TWO_ENVIRONMENT', 'staging');
        Tools::setTestValue('PS_TWO_TITLE_1', 'Two');
        Tools::setTestValue('PS_TWO_SUB_TITLE_1', 'Pay later');
        Tools::setTestValue('PS_TWO_MERCHANT_SHORT_NAME', 'merchant');
        Tools::setTestValue('PS_TWO_MERCHANT_API_KEY', $apiKey);
    }

    /**
     * The slot identity the module derives for $apiKey - key AND environment, so
     * an environment change misses the cache rather than carrying the other
     * environment's verdict.
     */
    private static function slotKey(string $apiKey): string
    {
        return md5($apiKey . '|' . (string) Configuration::get('PS_TWO_ENVIRONMENT'));
    }

    private static function storeVerdict(
        string $status,
        ?int $code,
        string $apiKey,
        int $age = 0,
        bool $claim = false,
        ?int $verifiedAge = null
    ): void {
        Configuration::updateValue(Twopayment::CONFIG_API_KEY_STATUS, json_encode(array(
            'status' => $status,
            'code' => $code,
            'key_hash' => self::slotKey($apiKey),
            'claim' => $claim,
            // How old the VERDICT is, which is what bounds serve-stale on a claim -
            // as distinct from how old the slot write is. Defaults to the same age.
            'verified_on' => time() - ($verifiedAge === null ? $age : $verifiedAge),
        )));
        Configuration::updateValue(Twopayment::CONFIG_API_KEY_STATUS_TS, time() - $age);
    }

    /**
     * Deliberately not "did anything redirect": every other guard on that path
     * (the checkout token, the currency, the module state) also ends in a
     * redirect, so a redirect proves nothing about which check fired - and one of
     * them fires on this fixture. The log line is the gate's signature.
     */
    private static function gateRefusedTheSubmission(object $module): bool
    {
        self::submittableCart($module);
        Tools::setTestValue('token', Tools::getToken(false));
        PrestaShopLogger::reset();

        $controller = new TwopaymentPaymentModuleFrontController();
        $controller->module = $module;

        try {
            $controller->postProcess();
        } catch (Exception $e) {
            // Any guard on this path ends in a redirect the stub core raises, and
            // anything past them all reaches provider plumbing this harness does
            // not stub. Either way the log below is what decides.
        }

        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'API key does not verify') !== false) {
                return true;
            }
        }

        return false;
    }

    private static function submittableCart(object $module): void
    {
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
        $module->context->cart = new Cart(9601);
        Context::getContext()->cart = $module->context->cart;
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

    /**
     * A 200 carrying no id or short_name confirmed nothing, so it is not a
     * verified key - the buyer gate must withhold Two on it (ABN-495).
     */
    private static function testARecordlessHttp200IsNotAVerifiedKey(): void
    {
        $module = self::module(self::recordlessOutcome());
        $result = $module->verifyForTest('stored-key');

        TinyAssert::same(Twopayment::API_KEY_STATUS_ERROR, $result['status']);
        TinyAssert::same(200, $result['code']);
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
     * The record that resolves a merchant is its id; a short name is optional
     * on the wire and an empty one withholds Two through hookPaymentOptions()'s
     * own guard. Requiring it here instead reported a resolvable merchant as an
     * error, and disagreed with the other platforms (ABN-495).
     */
    private static function testARecordWithNoShortNameStillVerifies(): void
    {
        $module = self::module(array(
            'response' => json_encode(array('id' => 'm-123')),
            'code' => 200,
            'error' => '',
        ));
        $result = $module->verifyForTest('stored-key');

        TinyAssert::same(Twopayment::API_KEY_STATUS_OK, $result['status']);
        TinyAssert::same('m-123', $result['body']['id']);
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
     * for the log.
     */
    private static function testNoticeNeverLeaksTheResponseBody(): void
    {
        $body = 'SECRET-UPSTREAM-BODY';
        $module = self::module(self::httpOutcome(401, $body));

        $module->cacheTwoApiKeyVerificationStatus('stored-key', $module->verifyForTest('stored-key'));
        $notice = $module->noticeForTest();

        TinyAssert::true($notice !== '', 'a rejected key must be reported on the config page');
        TinyAssert::true(strpos($notice, $body) === false, 'the response body must not reach the back office');
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
     * A verification another request is still making is not a diagnosis: the
     * notice must not fall through to the generic "could not be verified"
     * wording while the key is in the middle of being verified.
     */
    private static function testNoticeSaysNothingWhileAVerificationIsStillRunning(): void
    {
        $module = self::module(self::okOutcome());
        $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_VERIFYING, null);

        TinyAssert::same('', $module->noticeForTest(), 'a verification in flight must not be reported as a failure');
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

        TinyAssert::same(0, count($errors), 'an unreachable API must not block the save');
        TinyAssert::same(
            $module->getTwoApiKeyFailureMessage(Twopayment::API_KEY_STATUS_UNREACHABLE),
            $module->generalFormWarningForTest(),
            'the save must report the category, not a generic "check your API key"'
        );

        // The submitted key here IS the stored one, so the verdict is published
        // by the validation itself rather than waiting for the save.
        // Asserted on the STORED slot and the call count: reading the status back
        // would answer 'unreachable' either way, since a cache miss just re-runs
        // the same stubbed wire call.
        $stored = json_decode((string) Configuration::get(Twopayment::CONFIG_API_KEY_STATUS), true);
        TinyAssert::true(is_array($stored), 'a verdict for the STORED key must be published even when the form does not save');
        TinyAssert::same(Twopayment::API_KEY_STATUS_UNREACHABLE, $stored['status']);
        TinyAssert::same(self::slotKey('stored-key'), $stored['key_hash']);
        TinyAssert::true(empty($stored['claim']), 'a published verdict is not a claim');
        TinyAssert::same(1, $module->verifyCalls, 'and it must cost exactly the one verification the validation made');
        TinyAssert::same(
            Twopayment::API_KEY_STATUS_UNREACHABLE,
            $module->getTwoApiKeyVerificationStatus()['status']
        );
        TinyAssert::same(1, $module->verifyCalls, 'reading the verdict back must not verify again');

        // A verdict for a key the shop does NOT store is held for the save
        // instead: validation can fail on an unrelated field, and a verdict for
        // an unstored key describes nothing the gates can act on.
        $other = self::module(self::transportOutcome());
        self::generalFormPost('a-different-key');
        $other->validateGeneralFormForTest();
        TinyAssert::same(
            '',
            (string) Configuration::get(Twopayment::CONFIG_API_KEY_STATUS),
            'validating an unstored key must not write a cached verdict'
        );
        $other->saveGeneralFormForTest();
        TinyAssert::same(
            Twopayment::API_KEY_STATUS_UNREACHABLE,
            $other->getTwoApiKeyVerificationStatus()['status'],
            'the save publishes it once the key it describes is the stored one'
        );

        $fixed = self::module(self::okOutcome());
        self::generalFormPost('stored-key');
        self::storeVerdict(Twopayment::API_KEY_STATUS_INVALID, 401, 'stored-key');

        TinyAssert::same(0, count($fixed->validateGeneralFormForTest()), 'a verifying key must save cleanly');
        $fixed->saveGeneralFormForTest();
        TinyAssert::same(Twopayment::API_KEY_STATUS_OK, $fixed->getTwoApiKeyVerificationStatus()['status']);
        TinyAssert::same(1, $fixed->verifyCalls, 'the save must reuse its own check, not verify twice');
    }

    /**
     * The general save never blocks on the verification (ABN-495). The form
     * re-renders from POST, so a blocked save looks like a stored one while
     * leaving the merchant unable to store the key - or even the vendor name -
     * that would fix it. A key Two rejected is the one submitted value the save
     * discards: the stored key stays, everything beside it is written.
     */
    private static function testARejectedKeyIsTheOnlyValueASaveDiscards(): void
    {
        $timeoutOutcome = array('response' => false, 'code' => 0, 'error' => 'Operation timed out');
        $idOnlyOutcome = array('response' => json_encode(array('id' => 'm-123')), 'code' => 200, 'error' => '');
        // [wire outcome, key that must end up stored, short name that must end
        // up stored, wording the warning must carry (null = no warning), why].
        // The short name is server-derived, not a form input, so a save that
        // resolved no merchant must leave the stored one alone.
        $cases = array(
            array(self::transportOutcome(), 'freshly-pasted-key', 'old-merchant', 'could not reach', 'a connection failure'),
            array($timeoutOutcome, 'freshly-pasted-key', 'old-merchant', 'could not reach', 'a timeout'),
            array(self::httpOutcome(500), 'freshly-pasted-key', 'old-merchant', 'could not verify the API key right now', 'a 500 from the API'),
            array(self::httpOutcome(401), 'stored-key', 'old-merchant', 'previously saved key was kept', 'a 401 rejection'),
            array(self::httpOutcome(403), 'stored-key', 'old-merchant', 'previously saved key was kept', 'a 403 rejection'),
            array(self::okOutcome(), 'freshly-pasted-key', 'acme', null, 'a verified key'),
            array($idOnlyOutcome, 'freshly-pasted-key', 'old-merchant', null, 'a record with no short name'),
            array(self::recordlessOutcome(), 'freshly-pasted-key', 'old-merchant', 'unexpected response', 'a 200 carrying no merchant record'),
        );

        foreach ($cases as list($outcome, $expectedKey, $expectedShortName, $warning, $case)) {
            $verified = $warning === null;
            $module = self::module($outcome);
            Configuration::updateValue('PS_TWO_MERCHANT_SHORT_NAME', 'old-merchant');
            Configuration::updateValue('PS_TWO_VENDOR_NAME', 'Old Vendor');
            // Only the three inputs the General form actually posts.
            Tools::setTestValue('PS_TWO_MERCHANT_API_KEY', 'freshly-pasted-key');
            Tools::setTestValue('PS_TWO_VENDOR_NAME', 'New Vendor');
            Tools::setTestValue('PS_TWO_ENVIRONMENT', 'production');

            // getContent()'s general branch: validate, then save only if clean.
            TinyAssert::same(
                0,
                count($module->validateGeneralFormForTest()),
                $case . ' must not block the save'
            );
            $module->saveGeneralFormForTest();

            $expected = array(
                'PS_TWO_MERCHANT_SHORT_NAME' => $expectedShortName,
                'PS_TWO_MERCHANT_API_KEY' => $expectedKey,
                'PS_TWO_VENDOR_NAME' => 'New Vendor',
                'PS_TWO_ENVIRONMENT' => 'production',
            );
            foreach ($expected as $key => $value) {
                TinyAssert::same($value, (string) Configuration::get($key), $case . ' must store ' . $value . ' in ' . $key);
            }
            $reported = $module->generalFormWarningForTest();
            if ($warning === null) {
                TinyAssert::same(null, $reported, $case . ' must leave no warning');
            } else {
                TinyAssert::true(
                    $reported !== null && strpos($reported, $warning) !== false,
                    $case . ' must warn with the category, not a generic "check your API key"'
                );
            }
            TinyAssert::same(
                $verified ? '1' : '0',
                (string) Configuration::get('PS_TWO_API_KEY_VERIFIED'),
                $case . ' must record whether the key it stored is verified'
            );
            $published = json_decode((string) Configuration::get(Twopayment::CONFIG_API_KEY_STATUS), true);
            TinyAssert::same(
                $verified,
                is_array($published) && $published['status'] === Twopayment::API_KEY_STATUS_OK,
                $case . ' must publish a verdict the checkout gate can trust'
            );
        }
    }

    /**
     * The cached merchant record is NEVER cleared by a save (ABN-519). A merchant
     * with two shops who cycles their key and updates only one must not have the
     * other quietly forget the terms, fees and minimum its admin controls are
     * built from; an identity change refreshes the record instead, and a refresh
     * that cannot reach the API leaves every cached value exactly as it was.
     */
    private static function testAnIdentityChangeRefreshesTheRecordAndNeverClearsIt(): void
    {
        // [wire outcome, submitted key, submitted environment, why].
        $cases = array(
            array(self::transportOutcome(), 'freshly-pasted-key', 'staging', 'a changed key the save could not verify'),
            array(self::transportOutcome(), 'stored-key', 'production', 'a changed environment'),
            array(self::transportOutcome(), 'stored-key', 'staging', 'an unchanged key and environment'),
            array(self::httpOutcome(401), 'freshly-pasted-key', 'staging', 'a rejected key the save reverted'),
            array(self::okOutcome(), 'stored-key', 'staging', 'an unchanged key that resolved a different merchant'),
        );

        $held = array(
            Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS => json_encode(array(14, 30)),
            Twopayment::CONFIG_MERCHANT_DUE_IN_DAYS => '30',
            Twopayment::CONFIG_PLATFORM_MIN_ORDER => '250',
            Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED => '1',
            // Stamped for the key it was fetched with (ABN-530), which is the shape
            // every record has once fetched: an unstamped one cannot be dropped at all.
            Twopayment::CONFIG_MERCHANT_RECORD_KEY => TwopaymentTestHarness::recordKeyStampForTest('stored-key'),
        );

        foreach ($cases as list($outcome, $apiKey, $environment, $case)) {
            $module = self::module($outcome);
            Configuration::updateValue('PS_TWO_MERCHANT_ID', 'm-old');
            foreach ($held as $key => $value) {
                Configuration::updateValue($key, $value);
            }
            $stamp = time() - 5;
            Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS, $stamp);
            Tools::setTestValue('PS_TWO_MERCHANT_SHORT_NAME', 'merchant');
            Tools::setTestValue('PS_TWO_MERCHANT_API_KEY', $apiKey);
            Tools::setTestValue('PS_TWO_ENVIRONMENT', $environment);

            TinyAssert::same(0, count($module->validateGeneralFormForTest()), $case . ' must not block the save');
            $module->saveGeneralFormForTest();

            foreach ($held as $key => $value) {
                TinyAssert::same($value, (string) Configuration::get($key), $case . ' must keep ' . $key);
            }
            TinyAssert::same(
                (string) $stamp,
                (string) Configuration::get(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS),
                $case . ' must leave the success stamp describing the record actually held'
            );
        }
    }

    /**
     * Asserted against the source: getContent() cannot be reached without a
     * HelperForm, and this is the one-line wiring that turns the held warning
     * into something the merchant sees (ABN-495).
     */
    private static function testTheWarningIsRenderedAlongsideTheSaveConfirmation(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/twopayment.php');
        $expected = "                \$this->saveTwoGeneralFormValues();\n"
            . "                if (\$this->apiKeyVerificationWarning !== null) {\n"
            . "                    \$this->output .= \$this->displayWarning(\$this->apiKeyVerificationWarning);\n"
            . "                }";

        TinyAssert::true(
            strpos($source, $expected) !== false,
            'getContent() must render the verification warning after the general save'
        );
    }

    /**
     * The config page used to read a sticky flag written only at save time, so
     * a key that later expired rendered the green "verified" panel directly
     * above the red notice saying Two is hidden from checkout (TWO-25326).
     */
    private static function testVerifiedPanelFollowsTheLiveVerdict(): void
    {
        $module = self::module(self::httpOutcome(401));
        // What a save on a then-working key left behind.
        Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 1);
        $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_INVALID, 401);

        TinyAssert::same(0, $module->verifiedPanelFlagForTest(), 'the panel must follow the current verdict');
        TinyAssert::true($module->noticeForTest() !== '', 'and the failure notice must still be shown');

        // Asserted against the source because neither getContent()'s
        // `two_api_verified` template variable nor the health checklist's "API
        // key" row can be reached without a HelperForm, and both are one-line
        // reads that would silently go back to the sticky save-time flag.
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
     * ABN-533. Only a definitive rejection - a key Two refused, or no key at
     * all - withholds the payment option. Every other category is a statement
     * about the service, and taking Two off a correctly configured shop for one
     * costs the merchant every order for the length of the incident.
     *
     * [primed status, HTTP code, payment options offered, why].
     */
    private static function testOnlyADefinitiveRejectionWithholdsThePaymentOption(): void
    {
        $cases = array(
            array(Twopayment::API_KEY_STATUS_OK, 200, 1,
                'a verifying key is offered'),
            array(Twopayment::API_KEY_STATUS_INVALID, 401, 0,
                'Two refused the key, so the integration cannot take an order'),
            array(Twopayment::API_KEY_STATUS_NOT_CONFIGURED, null, 0,
                'there is no key to take an order with'),
            array(Twopayment::API_KEY_STATUS_SERVICE_ERROR, 503, 1,
                'a 5xx says nothing about the key'),
            array(Twopayment::API_KEY_STATUS_UNREACHABLE, null, 1,
                'an outage must not empty a correctly configured checkout'),
            array(Twopayment::API_KEY_STATUS_ERROR, 418, 1,
                'a non-2xx that is not a 401/403 is not a rejection of the key'),
            array(Twopayment::API_KEY_STATUS_VERIFYING, null, 1,
                'a cold cache is not evidence of a broken shop'),
        );

        foreach ($cases as $case) {
            list($status, $code, $offered, $description) = $case;
            $module = self::module(self::okOutcome());
            $module->primeTwoApiKeyStatus($status, $code);
            self::offerableCart($module);

            TinyAssert::same(
                $offered,
                count($module->hookPaymentOptions([])),
                'verification status "' . $status . '": ' . $description
            );
        }
    }

    /**
     * A 200 with no merchant record still categorises as 'error' rather than
     * 'ok' (ABN-495) - a proxy or a maintenance page answers 200 too. ABN-533:
     * that rejects no key, so it no longer withholds. Asserted through the
     * gate's own live check rather than a primed verdict.
     */
    private static function testARecordlessHttp200IsCategorisedButDoesNotWithhold(): void
    {
        $module = self::module(self::recordlessOutcome());
        self::offerableCart($module);

        TinyAssert::same(
            Twopayment::API_KEY_STATUS_ERROR,
            $module->getTwoApiKeyVerificationStatus()['status'],
            'a 200 carrying no merchant record is not a verification'
        );
        TinyAssert::same(
            1,
            count($module->hookPaymentOptions([])),
            'but it rejects no key, so the payment option stands'
        );
    }

    private static function testWithholdingThePaymentOptionIsLogged(): void
    {
        $module = self::module(self::okOutcome());
        $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_INVALID, 401);
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
        TinyAssert::true(strpos($logged, 'invalid_key') !== false, 'the log must name the category');
        TinyAssert::true(strpos($logged, '401') !== false, 'the log must carry the HTTP status');
    }


    /**
     * PrestaShop asks for payment options several times per payment-step
     * render. The reason needs saying once, not once per ask, or a broken shop
     * with traffic buries it in its own repetition.
     */
    private static function testWithholdReasonIsLoggedOncePerRequestNotPerCall(): void
    {
        $module = self::module(self::okOutcome());
        $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_INVALID, 401);
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
     * failing opaquely at order creation.
     */
    private static function testPaymentSubmissionIsRefusedWhenTheKeyDoesNotVerify(): void
    {
        $module = self::module(self::okOutcome());
        $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_INVALID, 401);
        self::submittableCart($module);

        TinyAssert::true(
            self::gateRefusedTheSubmission($module),
            'a submission on an unverifiable key must be refused, not processed'
        );
    }


    /**
     * ...but NOT over a transient one. Refusing a submitted
     * order because Two answered 5xx at that instant - or because the verdict
     * cache merely happened to be cold - costs the buyer the order, where
     * proceeding reaches order creation with its own longer timeout and its own
     * decline handling. Only a rejected key and no key at all are refusals here.
     */
    private static function testPaymentSubmissionSurvivesATransientFailure(): void
    {
        foreach (
            [
                Twopayment::API_KEY_STATUS_SERVICE_ERROR,
                Twopayment::API_KEY_STATUS_UNREACHABLE,
                Twopayment::API_KEY_STATUS_VERIFYING,
            ] as $status
        ) {
            $module = self::module(self::okOutcome());
            $module->primeTwoApiKeyStatus($status, null);

            TinyAssert::false(
                $module->isTwoApiKeyDefinitelyUnusable(),
                'status "' . $status . '" must not refuse a submitted order'
            );
        }

        foreach ([Twopayment::API_KEY_STATUS_INVALID, Twopayment::API_KEY_STATUS_NOT_CONFIGURED] as $status) {
            $module = self::module(self::okOutcome());
            $module->primeTwoApiKeyStatus($status, null);

            TinyAssert::true(
                $module->isTwoApiKeyDefinitelyUnusable(),
                'status "' . $status . '" is definitive and must refuse'
            );
        }

        // And the check itself must never make the call: a 10s stall inside a
        // payment POST is a stall in the buyer's submit.
        $cold = self::module(self::httpOutcome(401));
        $cold->isTwoApiKeyDefinitelyUnusable();
        TinyAssert::same(0, $cold->verifyCalls, 'the payment POST must not pay for a verification');
    }


    /**
     * The controller's CALL SITE, not just the module method it should be using:
     * swapping in the render path's question - "verified?" rather than
     * "definitively unusable?" - reinstates fail-closed-on-a-blip, and every
     * category-level assertion elsewhere still passes.
     */
    private static function testPaymentControllerAsksTheDefinitiveQuestionNotTheRenderOne(): void
    {
        $module = self::module(self::okOutcome());
        $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_SERVICE_ERROR, 503);
        self::submittableCart($module);

        TinyAssert::false(
            self::gateRefusedTheSubmission($module),
            'a transient failure must not refuse a submitted order'
        );
        TinyAssert::same(0, $module->verifyCalls, 'and the POST must not pay for a verification either');
    }

    /**
     * ...while a REJECTED key still refuses, however old the verdict is. The gate
     * exists for the buyer who was already on the payment step when the verdict
     * changed - minutes later, by definition - and it may not make the call
     * itself, so a TTL-fresh verdict is precisely what it does not have.
     */
    private static function testAStaleRejectedKeyStillRefusesASubmission(): void
    {
        $module = self::module(self::okOutcome());
        self::storeVerdict(
            Twopayment::API_KEY_STATUS_INVALID,
            401,
            'stored-key',
            Twopayment::API_KEY_STATUS_TTL * 10
        );
        self::submittableCart($module);

        TinyAssert::true(
            self::gateRefusedTheSubmission($module),
            'a rejected key refuses a submission however stale the verdict is'
        );
        TinyAssert::same(0, $module->verifyCalls, 'cache-only, still');

        $transient = self::module(self::okOutcome());
        self::storeVerdict(
            Twopayment::API_KEY_STATUS_SERVICE_ERROR,
            503,
            'stored-key',
            Twopayment::API_KEY_STATUS_TTL * 10
        );
        TinyAssert::false(
            $transient->isTwoApiKeyDefinitelyUnusable(),
            'an old transient failure must not become definitive by ageing'
        );

        // A claim in flight is a different case again, and the distinction is
        // worth pinning: a claim only ever CARRIES a real previous verdict (it
        // carries 'verifying' when there is none), so a claim carrying
        // 'invalid_key' is the last real verdict and refuses like any other. A
        // claim carrying nothing refuses nothing.
        $carrying = self::module(self::okOutcome());
        self::storeVerdict(Twopayment::API_KEY_STATUS_INVALID, 401, 'stored-key', 0, true);
        TinyAssert::true(
            $carrying->isTwoApiKeyDefinitelyUnusable(),
            'a claim carrying a rejected-key verdict is still carrying that verdict'
        );

        $empty = self::module(self::okOutcome());
        self::storeVerdict(Twopayment::API_KEY_STATUS_VERIFYING, null, 'stored-key', 0, true);
        TinyAssert::false(
            $empty->isTwoApiKeyDefinitelyUnusable(),
            'a claim with no verdict to carry must not refuse a submitted order'
        );
    }


    /**
     * The past-TTL read is bound to the key AND environment the shop holds NOW.
     * Without that binding, a merchant who has just
     * replaced a rejected key - or pointed the same key at another environment -
     * has their buyers' submissions refused by the OLD key's rejection, which is
     * the "I fixed it and it still says unavailable" failure in its worst form:
     * at the submit button, on an order.
     */
    private static function testAStaleRejectionNeverFollowsAReplacedKeyOrEnvironment(): void
    {
        $replaced = self::module(self::okOutcome());
        self::storeVerdict(
            Twopayment::API_KEY_STATUS_INVALID,
            401,
            'the-old-key',
            Twopayment::API_KEY_STATUS_TTL * 10
        );

        TinyAssert::false(
            $replaced->isTwoApiKeyDefinitelyUnusable(),
            'a replacement key must not inherit the old key\'s rejection at the submit'
        );

        $moved = self::module(self::okOutcome());
        self::storeVerdict(
            Twopayment::API_KEY_STATUS_INVALID,
            401,
            'stored-key',
            Twopayment::API_KEY_STATUS_TTL * 10
        );
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'production');

        TinyAssert::false(
            $moved->isTwoApiKeyDefinitelyUnusable(),
            'a rejection reached against another environment must not refuse submissions here'
        );

        // An EXPIRED claim is not a verdict either, however definitive what it
        // carries looks: the request that made it never finished, so nothing
        // confirmed that verdict. Fail-open, and pinned so it stays deliberate.
        $abandoned = self::module(self::okOutcome());
        self::storeVerdict(
            Twopayment::API_KEY_STATUS_INVALID,
            401,
            'stored-key',
            Twopayment::API_KEY_STATUS_CLAIM_WINDOW + 1,
            true
        );

        TinyAssert::false(
            $abandoned->isTwoApiKeyDefinitelyUnusable(),
            'an abandoned claim must not refuse a submitted order'
        );
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


    /**
     * The media hook runs on the module's OWN front controllers too - and one of
     * those is the payment POST, where the verification gate deliberately refuses
     * to make an HTTP call because a stall there is a stall in the buyer's
     * submit. Those pages render no company-search control, so a cache-only
     * answer costs them nothing.
     */
    private static function testOnlyTheCheckoutPageMayPayForAVerification(): void
    {
        $module = self::mediaHookModule('module-twopayment-payment');
        Media::reset();

        $module->hookActionFrontControllerSetMedia();

        TinyAssert::same(0, $module->verifyCalls, 'a module front controller must not pay for a verification');

        // The real checkout page still may: it is the page whose company-search
        // control the verdict decides, and it is a page render, not a submit.
        $checkout = self::mediaHookModule('order');
        Media::reset();

        $checkout->hookActionFrontControllerSetMedia();

        TinyAssert::same(1, $checkout->verifyCalls, 'the checkout page resolves the verdict for real');
        TinyAssert::same(
            true,
            Media::$jsDef['twopayment']['api_key_verified'],
            'and hands the browser a real boolean'
        );

        // The flag must be the SAME predicate the address-form override asks:
        // these are two halves of one affordance, and the flag has exactly one
        // reader. "verified?" and "warranted?" diverge on a claim in flight,
        // which is where a shop with a back-office translation of the core
        // placeholder keeps the hint on a dead field.
        $claimInFlight = self::mediaHookModule('order');
        // A REAL claim slot, not a primed memo: this is the state the two halves
        // used to disagree on, so it is the state worth asserting through.
        $claimInFlight->primeTwoApiKeyStatus(null);
        self::storeVerdict(Twopayment::API_KEY_STATUS_VERIFYING, null, 'stored-key', 0, true);
        Media::reset();

        $claimInFlight->hookActionFrontControllerSetMedia();

        TinyAssert::same(
            true,
            Media::$jsDef['twopayment']['api_key_verified'],
            'a claim in flight must not take the affordance away - the verdict is not in yet'
        );
        TinyAssert::same(
            $claimInFlight->isTwoCompanySearchAffordanceWarranted(),
            Media::$jsDef['twopayment']['api_key_verified'],
            'the browser flag and the server-side affordance question must be the same question'
        );

        // ABN-533: a transient failure keeps the affordance; only a rejection
        // takes it away, because only a rejection withholds Two itself.
        $affordanceByStatus = array(
            array(Twopayment::API_KEY_STATUS_SERVICE_ERROR, 503, true,
                'a 5xx leaves a captured company something to feed'),
            array(Twopayment::API_KEY_STATUS_UNREACHABLE, null, true,
                'so does an unreachable Two'),
            array(Twopayment::API_KEY_STATUS_ERROR, 418, true,
                'and any other non-2xx'),
            array(Twopayment::API_KEY_STATUS_INVALID, 401, false,
                'a rejected key withholds Two, so the search has nothing to feed'),
            array(Twopayment::API_KEY_STATUS_NOT_CONFIGURED, null, false,
                'nor has an unconfigured shop'),
        );

        foreach ($affordanceByStatus as $case) {
            list($status, $code, $warranted, $description) = $case;
            $failing = self::mediaHookModule('order');
            $failing->primeTwoApiKeyStatus($status, $code);
            Media::reset();

            $failing->hookActionFrontControllerSetMedia();

            TinyAssert::same(
                $warranted,
                Media::$jsDef['twopayment']['api_key_verified'],
                $status . ': ' . $description
            );
            TinyAssert::same(
                $warranted,
                $failing->isTwoCompanySearchAffordanceWarranted(),
                $status . ': the browser flag and the server-side question stay one question'
            );
        }
    }

    private static function mediaHookModule(string $phpSelf): object
    {
        $module = self::module(self::okOutcome());
        $module->setPathForTest('/modules/twopayment/');

        $controller = new class extends ModuleFrontController {
            public $php_self = '';
            public $controller_name = '';

            public function registerStylesheet($id, $path, $options = [])
            {
            }

            public function registerJavascript($id, $path, $options = [])
            {
            }

            public function addJquery()
            {
            }

            public function addJqueryUI($component)
            {
            }
        };
        $controller->php_self = $phpSelf;
        $controller->module = $module;
        $module->context->controller = $controller;
        $module->context->country = new class {
            public $iso_code = 'NO';
        };
        StubStore::$countries[578] = 'NO';

        return $module;
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
        self::storeVerdict(Twopayment::API_KEY_STATUS_OK, 200, 'stored-key');

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

        $failing = self::module(self::okOutcome());
        self::storeVerdict(Twopayment::API_KEY_STATUS_SERVICE_ERROR, 503, 'stored-key', $ageBetweenTtls);

        TinyAssert::same(Twopayment::API_KEY_STATUS_OK, $failing->getTwoApiKeyVerificationStatus()['status']);
        TinyAssert::same(1, $failing->verifyCalls, 'a stale FAILED verdict must be re-verified');

        // The stub would answer 503, so a re-verification here would be visible
        // in the status as well as in the call count.
        $healthy = self::module(self::httpOutcome(503));
        self::storeVerdict(Twopayment::API_KEY_STATUS_OK, 200, 'stored-key', $ageBetweenTtls);

        TinyAssert::same(Twopayment::API_KEY_STATUS_OK, $healthy->getTwoApiKeyVerificationStatus()['status']);
        TinyAssert::same(0, $healthy->verifyCalls, 'a healthy verdict of the same age is still fresh');

        // The verdict JSON is written again here on purpose: writing only the
        // clock would make this pass on the empty-slot branch instead of the
        // expiry branch, and the long TTL would then have no coverage at all.
        $expired = self::module(self::httpOutcome(401));
        self::storeVerdict(Twopayment::API_KEY_STATUS_OK, 200, 'stored-key', Twopayment::API_KEY_STATUS_TTL + 1);

        TinyAssert::same(Twopayment::API_KEY_STATUS_INVALID, $expired->getTwoApiKeyVerificationStatus()['status']);
        TinyAssert::same(1, $expired->verifyCalls, 'a healthy verdict past its TTL must be re-verified');
    }

    /**
     * Anti-stampede. The clock is claimed BEFORE the wire call,
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
        TinyAssert::same(self::slotKey('stored-key'), $decoded['key_hash'], 'the claim belongs to the key being verified');
        TinyAssert::true(!empty($decoded['claim']), 'a claim must be marked as one, not left to look like a verdict');

        // Marked as a claim, it expires after the claim window rather than a
        // TTL - so a claim abandoned by a process that died mid-call stops
        // standing in for a verdict within seconds. Asserted through the reader,
        // which is what actually decides.
        $stale = self::module(self::httpOutcome(401));
        self::storeVerdict(
            Twopayment::API_KEY_STATUS_OK,
            200,
            'stored-key',
            Twopayment::API_KEY_STATUS_CLAIM_WINDOW + 1,
            true
        );
        TinyAssert::same(
            Twopayment::API_KEY_STATUS_INVALID,
            $stale->getTwoApiKeyVerificationStatus()['status'],
            'an abandoned claim must expire after the claim window, not after a full TTL'
        );
        TinyAssert::true(
            Twopayment::API_KEY_STATUS_CLAIM_WINDOW > Twopayment::API_TIMEOUT_STATE_CHECK,
            'the claim window must outlast the call it covers'
        );
    }


    /**
     * The claim must never GUESS 'ok'. A shop whose key reached
     * Configuration without a successful config-page save - install seeding, a DB
     * clone, direct SQL - has no prior verdict to serve, and a claim carrying
     * 'ok' would offer Two, and let the payment POST through, for the whole claim
     * window on a key that has never verified once.
     */
    private static function testAClaimWithNoPriorVerdictKeepsTheGatesClosed(): void
    {
        $module = self::module(self::okOutcome());
        $seenByConcurrentRequest = null;

        $module->onWireCall(function () use (&$seenByConcurrentRequest) {
            $concurrent = new class extends TwopaymentTestHarness {
                public function __construct()
                {
                    parent::__construct();
                    $this->primeTwoApiKeyStatus(null);
                }

                protected function requestTwoApiKeyVerification($apiKey, $environment, $timeout = null)
                {
                    throw new RuntimeException('a concurrent request must stand down, not verify');
                }
            };
            $seenByConcurrentRequest = $concurrent->getTwoApiKeyVerificationStatus()['status'];
        });

        $module->getTwoApiKeyVerificationStatus();

        TinyAssert::notSame(
            Twopayment::API_KEY_STATUS_OK,
            (string) $seenByConcurrentRequest,
            'an unverified shop must not read as verified while a claim is in flight'
        );
        TinyAssert::same(Twopayment::API_KEY_STATUS_VERIFYING, (string) $seenByConcurrentRequest);
    }

    /**
     * ...and equally must not blink Two off a HEALTHY shop. A verdict expires
     * every TTL, so the re-verification's claim happens on a working shop
     * routinely; the previous verdict rides along with it (serve-stale) so
     * concurrent renders keep offering Two meanwhile.
     */
    private static function testAClaimCarriesAPriorVerdictSoReVerificationDoesNotBlinkTwoOff(): void
    {
        $module = self::module(self::okOutcome());
        // A healthy verdict, just expired.
        self::storeVerdict(Twopayment::API_KEY_STATUS_OK, 200, 'stored-key', Twopayment::API_KEY_STATUS_TTL + 1);
        $seenByConcurrentRequest = null;

        $module->onWireCall(function () use (&$seenByConcurrentRequest) {
            $concurrent = new class extends TwopaymentTestHarness {
                public function __construct()
                {
                    parent::__construct();
                    $this->primeTwoApiKeyStatus(null);
                }

                protected function requestTwoApiKeyVerification($apiKey, $environment, $timeout = null)
                {
                    throw new RuntimeException('a concurrent request must stand down, not verify');
                }
            };
            $seenByConcurrentRequest = $concurrent->getTwoApiKeyVerificationStatus()['status'];
        });

        $module->getTwoApiKeyVerificationStatus();

        TinyAssert::same(
            Twopayment::API_KEY_STATUS_OK,
            (string) $seenByConcurrentRequest,
            're-verifying a healthy shop must not withhold Two while it happens'
        );
    }

    /**
     * A key is valid for ONE environment. An environment change by any route that
     * does not go through the config-page save (an upgrade script,
     * dev/configure.php, direct SQL) must miss the cache rather than carry the
     * other environment's verdict for a full TTL.
     */
    private static function testAnEnvironmentChangeInvalidatesTheVerdict(): void
    {
        $module = self::module(self::httpOutcome(401));
        self::storeVerdict(Twopayment::API_KEY_STATUS_OK, 200, 'stored-key');
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'production');

        TinyAssert::same(
            Twopayment::API_KEY_STATUS_INVALID,
            $module->getTwoApiKeyVerificationStatus()['status'],
            'the same key against a different environment must be verified afresh'
        );
        TinyAssert::same(1, $module->verifyCalls);
    }


    /**
     * A claim re-stamps the slot's clock, so serve-stale is bounded by the age of
     * the VERDICT rather than of the last write - otherwise a shop whose
     * verification never completes (a fatal, a killed worker) re-carries and
     * re-freshens the same ancient 'ok' indefinitely.
     */
    private static function testAnAncientVerdictIsNotCarriedForever(): void
    {
        $module = self::module(self::okOutcome());
        // Slot written moments ago (as a claim would leave it), but the verdict it
        // carries was reached long ago.
        self::storeVerdict(
            Twopayment::API_KEY_STATUS_OK,
            200,
            'stored-key',
            Twopayment::API_KEY_STATUS_TTL + 1,
            false,
            Twopayment::API_KEY_STATUS_CARRY_MAX_AGE + 1
        );
        $seenByConcurrentRequest = null;

        $module->onWireCall(function () use (&$seenByConcurrentRequest) {
            $concurrent = new class extends TwopaymentTestHarness {
                public function __construct()
                {
                    parent::__construct();
                    $this->primeTwoApiKeyStatus(null);
                }

                protected function requestTwoApiKeyVerification($apiKey, $environment, $timeout = null)
                {
                    throw new RuntimeException('a concurrent request must stand down, not verify');
                }
            };
            $seenByConcurrentRequest = $concurrent->getTwoApiKeyVerificationStatus()['status'];
        });

        $module->getTwoApiKeyVerificationStatus();

        TinyAssert::same(
            Twopayment::API_KEY_STATUS_VERIFYING,
            (string) $seenByConcurrentRequest,
            'a verdict older than the carry cap must not ride along on a claim'
        );
    }

    /**
     * Switching only the ENVIRONMENT dropdown to one this key is not valid for
     * must not publish anything against the configuration the shop is still
     * running: the check runs against the SUBMITTED environment while the slot
     * is keyed to the STORED one, so publishing here would take Two off a
     * healthy checkout over an environment the shop has not moved to yet.
     */
    private static function testSwitchingEnvironmentAloneNeverPublishesAVerdict(): void
    {
        $module = self::module(self::httpOutcome(401));
        self::generalFormPost('stored-key');
        // Deliberately different from reset()'s stored 'staging' - the whole
        // point of this test is a submitted/stored MISMATCH.
        Tools::setTestValue('PS_TWO_ENVIRONMENT', 'production');

        $errors = $module->validateGeneralFormForTest();

        TinyAssert::same(0, count($errors), 'the save is not blocked');
        TinyAssert::true(
            strpos((string) $module->generalFormWarningForTest(), 'rejected') !== false,
            'the merchant is still told the key was rejected there'
        );
        TinyAssert::same(
            '',
            (string) Configuration::get(Twopayment::CONFIG_API_KEY_STATUS),
            'but nothing is published against the configuration the shop is still running'
        );
    }


    /**
     * The actual mechanism of the carry cap: a claim carries the verdict's
     * ORIGINAL age, it does not re-stamp it. Without that, every claim refreshes
     * the same ancient verdict and serve-stale never ends - the loop the cap
     * exists to break - and observing one claim cycle cannot tell a preserved
     * clock from a reset one.
     *
     * Asserted on the slot as written mid-claim, which is the one observable
     * moment the distinction exists: wall-clock ageing is not something a test
     * can wait out.
     */
    private static function testAClaimCarriesTheVerdictsOriginalAgeNotAFreshOne(): void
    {
        $verdictAge = 120;
        $module = self::module(self::transportOutcome());
        self::storeVerdict(
            Twopayment::API_KEY_STATUS_OK,
            200,
            'stored-key',
            Twopayment::API_KEY_STATUS_TTL + 1,
            false,
            $verdictAge
        );
        $expectedVerifiedOn = time() - $verdictAge;
        $claimed = null;

        $module->onWireCall(function () use (&$claimed) {
            $claimed = json_decode((string) Configuration::get(Twopayment::CONFIG_API_KEY_STATUS), true);
        });
        $module->getTwoApiKeyVerificationStatus();

        TinyAssert::true(is_array($claimed), 'the claim must be written before the wire call');
        TinyAssert::true(!empty($claimed['claim']), 'and be marked as a claim');
        TinyAssert::same(Twopayment::API_KEY_STATUS_OK, (string) $claimed['status'], 'carrying the previous verdict');
        // Within a second of the ORIGINAL verdict's clock, not of now: a reset
        // would land ~120s later.
        TinyAssert::true(
            abs((int) $claimed['verified_on'] - $expectedVerifiedOn) <= 1,
            'a claim must carry the verdict\'s original age (expected ~' . $expectedVerifiedOn
                . ', got ' . (int) $claimed['verified_on'] . ')'
        );
    }

    /**
     * A slot written before the verdict clock existed is not ageless: read as
     * age zero it is never carryable, so the first re-verification against such
     * a slot withholds Two for the length of a claim window. The reader may not
     * assume the shape of what it reads back out of Configuration.
     */
    private static function testASlotWithoutAVerdictClockIsStillCarryable(): void
    {
        $module = self::module(self::okOutcome());
        // A slot with no 'verified_on' key at all.
        Configuration::updateValue(Twopayment::CONFIG_API_KEY_STATUS, json_encode(array(
            'status' => Twopayment::API_KEY_STATUS_OK,
            'code' => 200,
            'key_hash' => self::slotKey('stored-key'),
        )));
        Configuration::updateValue(
            Twopayment::CONFIG_API_KEY_STATUS_TS,
            time() - (Twopayment::API_KEY_STATUS_TTL + 1)
        );
        $seen = null;

        $module->onWireCall(function () use (&$seen) {
            $concurrent = new class extends TwopaymentTestHarness {
                public function __construct()
                {
                    parent::__construct();
                    $this->primeTwoApiKeyStatus(null);
                }

                protected function requestTwoApiKeyVerification($apiKey, $environment, $timeout = null)
                {
                    throw new RuntimeException('a concurrent request must stand down');
                }
            };
            $seen = $concurrent->getTwoApiKeyVerificationStatus()['status'];
        });
        $module->getTwoApiKeyVerificationStatus();

        TinyAssert::same(
            Twopayment::API_KEY_STATUS_OK,
            (string) $seen,
            'a slot with no verdict clock must still be carryable, not read as ageless'
        );

        // And the fallback is the slot's own clock, not "now": a legacy slot older
        // than the carry cap is still too old to carry.
        $ancient = self::module(self::okOutcome());
        Configuration::updateValue(Twopayment::CONFIG_API_KEY_STATUS, json_encode(array(
            'status' => Twopayment::API_KEY_STATUS_OK,
            'code' => 200,
            'key_hash' => self::slotKey('stored-key'),
        )));
        Configuration::updateValue(
            Twopayment::CONFIG_API_KEY_STATUS_TS,
            time() - (Twopayment::API_KEY_STATUS_CARRY_MAX_AGE + 1)
        );
        $seenAncient = null;
        $ancient->onWireCall(function () use (&$seenAncient) {
            $concurrent = new class extends TwopaymentTestHarness {
                public function __construct()
                {
                    parent::__construct();
                    $this->primeTwoApiKeyStatus(null);
                }

                protected function requestTwoApiKeyVerification($apiKey, $environment, $timeout = null)
                {
                    throw new RuntimeException('a concurrent request must stand down');
                }
            };
            $seenAncient = $concurrent->getTwoApiKeyVerificationStatus()['status'];
        });
        $ancient->getTwoApiKeyVerificationStatus();

        TinyAssert::same(
            Twopayment::API_KEY_STATUS_VERIFYING,
            (string) $seenAncient,
            'a legacy slot must age from its own clock, not from now'
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
        self::storeVerdict(Twopayment::API_KEY_STATUS_INVALID, 401, 'the-old-key');

        $status = $module->getTwoApiKeyVerificationStatus();

        TinyAssert::same(Twopayment::API_KEY_STATUS_OK, $status['status'], 'a different key must be verified afresh');
        TinyAssert::same(1, $module->verifyCalls);
    }

    /* ===================================================================
     * Inline live check (TWO-25386 #4) - General tab's blur/keystroke
     * verification, never touching Configuration.
     * =================================================================== */

    private static function testLiveCheckReportsOkForAVerifiedKey(): void
    {
        $module = self::module(self::okOutcome());
        $result = $module->liveCheckForTest('a-fresh-key', 'staging');

        TinyAssert::true($result['ok']);
        TinyAssert::same(Twopayment::API_KEY_STATUS_OK, $result['status']);
        TinyAssert::same('', $result['message']);
    }

    private static function testLiveCheckReportsTheFailureMessageForARejectedKey(): void
    {
        $module = self::module(self::httpOutcome(401));
        $result = $module->liveCheckForTest('a-bad-key', 'staging');

        TinyAssert::false($result['ok']);
        TinyAssert::same(Twopayment::API_KEY_STATUS_INVALID, $result['status']);
        TinyAssert::true($result['message'] !== '', 'a failed check must carry a merchant-facing message');
    }

    private static function testLiveCheckNeverTouchesConfigurationBeforeSave(): void
    {
        $module = self::module(self::okOutcome());
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'stored-key');
        Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 0);

        $module->liveCheckForTest('a-different-key-being-tried', 'staging');

        TinyAssert::same(
            'stored-key',
            (string) Configuration::get('PS_TWO_MERCHANT_API_KEY'),
            'trying a key inline must never publish it as stored'
        );
        TinyAssert::same(
            0,
            (int) Configuration::get('PS_TWO_API_KEY_VERIFIED'),
            'trying a key inline must never flip the published verified flag'
        );
        TinyAssert::same(1, $module->verifyCalls, 'sanity: the check did run');
    }

    private static function testLiveCheckDoesNotCallOutForAnEmptyKeyOrEnvironment(): void
    {
        $module = self::module(self::okOutcome());

        $result = $module->liveCheckForTest('', 'staging');
        TinyAssert::false($result['ok']);
        TinyAssert::same(Twopayment::API_KEY_STATUS_NOT_CONFIGURED, $result['status']);

        $result = $module->liveCheckForTest('a-key', '');
        TinyAssert::false($result['ok']);
        TinyAssert::same(Twopayment::API_KEY_STATUS_NOT_CONFIGURED, $result['status']);

        TinyAssert::same(0, $module->verifyCalls, 'an incomplete request must never reach the wire');
    }
}
