<?php

declare(strict_types=1);

/**
 * fetchTwoMerchantFeeRates() - the merchant-fee lookup behind the inline fee
 * display on the admin "Available Payment Terms" checkboxes. Contract under
 * test:
 *
 *  - POST /pricing/v1/merchant/rates with {buyer_country_code,
 *    recourse_pricing: false, net_terms: int[]} on a tight render-path
 *    timeout.
 *  - Response normalised to {success, currency, fees: {"<days>":
 *    {percentage, fixed}}}.
 *  - Fail-soft: missing API key, empty term list, non-200, or malformed body
 *    never throw, so the admin page never breaks on an API outage.
 *  - Serve-stale (ABN-541): a successful set is stored per identity and
 *    stamped with fetched_at; a failed fetch serves it back with
 *    stale => true, and a 60s cooldown suppresses the wire call meanwhile.
 *    {success: false} only when nothing was ever stored for that identity.
 *  - A 4xx (TWO-26305) is 'rejected' and carries the status and the API's
 *    own message, also while cooling; a network error or 5xx stays the
 *    generic 'upstream'. The log line names the status and message.
 *  - An unrenderable answer (ABN-540) - no currency, or no term priced at
 *    all - is a failed fetch, so it can neither be drawn nor displace the
 *    stored set. A partial answer is renderable and stays a success.
 */
final class MerchantFeeRatesSpec
{
    public static function runAll(): void
    {
        self::testSuccessNormalisesRatesAndSendsExpectedRequest();
        self::testBuyerCountryFallsBackToNlWhenNoDefaultCountry();
        self::testMissingApiKeyFailsWithoutWireCall();
        self::testEmptyOrInvalidTermsFailWithoutWireCall();
        self::testNon200Fails();
        self::testMalformedBodyFails();
        self::testMalformedRateRowsAreSkipped();
        self::testUnrenderableAnswersAreRefusedAndKeepTheStoredSet();
        self::testPartialAnswerIsStillASuccess();
        self::testLastKnownGoodLifecycle();
        self::testTerminalFailuresNeverReachTheWire();
        self::testStoredSetIsIdentityScoped();
        self::testRejectionCarriesTheApiMessage();
        self::testCoolingRendersKeepTheRejection();
    }

    /**
     * Harness with a stubbed, capturing setTwoPaymentRequest.
     *
     * @param mixed $response
     */
    private static function moduleWithRatesResponse($response): object
    {
        return new class ($response) extends TwopaymentTestHarness {
            public int $fetchCount = 0;
            public $lastEndpoint = null;
            public $lastPayload = null;
            public $lastMethod = null;
            public $lastTimeout = null;
            private $response;

            public function __construct($response)
            {
                parent::__construct();
                $this->response = $response;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->fetchCount++;
                $this->lastEndpoint = $endpoint;
                $this->lastPayload = $payload;
                $this->lastMethod = $method;
                $this->lastTimeout = $timeout;
                return $this->response;
            }
        };
    }

    private static function configureMerchantIdentity(): void
    {
        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'm-123');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');
    }

    private static function okResponse(): array
    {
        return [
            'http_status' => 200,
            'currency' => 'NOK',
            'rates' => [
                // The API sends numeric values as strings.
                ['net_terms' => '15', 'percentage_fee' => '1.50', 'fixed_fee' => '0.00'],
                ['net_terms' => 30, 'percentage_fee' => '2.51', 'fixed_fee' => '0.10'],
            ],
        ];
    }

    private static function testSuccessNormalisesRatesAndSendsExpectedRequest(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        // StubStore country map: 47 => 'NO'.
        Configuration::updateValue('PS_COUNTRY_DEFAULT', 47);

        $module = self::moduleWithRatesResponse(self::okResponse());
        // Unsorted, duplicated, mixed-type input must normalise to [15, 30].
        $result = $module->fetchTwoMerchantFeeRates([30, '15', 30]);

        TinyAssert::same('/pricing/v1/merchant/rates', $module->lastEndpoint);
        TinyAssert::same('POST', $module->lastMethod);
        TinyAssert::same(Twopayment::API_TIMEOUT_STATE_CHECK, $module->lastTimeout, 'render-path call must use the tight timeout');
        TinyAssert::same('NO', $module->lastPayload['buyer_country_code']);
        TinyAssert::false($module->lastPayload['recourse_pricing']);
        TinyAssert::same([15, 30], $module->lastPayload['net_terms']);

        TinyAssert::true($result['success']);
        TinyAssert::same('NOK', $result['currency']);
        TinyAssert::same(['percentage' => 1.5, 'fixed' => 0.0], $result['fees']['15']);
        TinyAssert::same(['percentage' => 2.51, 'fixed' => 0.1], $result['fees']['30']);
    }

    private static function testBuyerCountryFallsBackToNlWhenNoDefaultCountry(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        // No PS_COUNTRY_DEFAULT configured at all.

        $module = self::moduleWithRatesResponse(self::okResponse());
        $result = $module->fetchTwoMerchantFeeRates([30]);

        TinyAssert::same('NL', $module->lastPayload['buyer_country_code']);
        TinyAssert::true($result['success']);

        // Configured but unresolvable country id also falls back.
        StubStore::reset();
        self::configureMerchantIdentity();
        Configuration::updateValue('PS_COUNTRY_DEFAULT', 999);
        $module = self::moduleWithRatesResponse(self::okResponse());
        $module->fetchTwoMerchantFeeRates([30]);
        TinyAssert::same('NL', $module->lastPayload['buyer_country_code']);
    }

    private static function testMissingApiKeyFailsWithoutWireCall(): void
    {
        StubStore::reset();
        // No PS_TWO_MERCHANT_API_KEY configured.

        $module = self::moduleWithRatesResponse(self::okResponse());
        $result = $module->fetchTwoMerchantFeeRates([30]);

        TinyAssert::false($result['success']);
        TinyAssert::same(0, $module->fetchCount, 'must not hit the wire without an API key');
    }

    private static function testEmptyOrInvalidTermsFailWithoutWireCall(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();

        $module = self::moduleWithRatesResponse(self::okResponse());

        TinyAssert::false($module->fetchTwoMerchantFeeRates([])['success']);
        // Non-numeric / non-positive entries normalise away to an empty set.
        TinyAssert::false($module->fetchTwoMerchantFeeRates(['abc', -5, 0, null, [7]])['success']);
        TinyAssert::same(0, $module->fetchCount, 'must not hit the wire with no valid terms');
    }

    private static function testNon200Fails(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();

        foreach ([0, 401, 500] as $status) {
            // Per iteration: a previous failure leaves a cooldown that would
            // suppress this one's wire call.
            StubStore::reset();
            self::configureMerchantIdentity();
            $module = self::moduleWithRatesResponse(['http_status' => $status, 'rates' => []]);
            TinyAssert::false($module->fetchTwoMerchantFeeRates([30])['success'], 'HTTP ' . $status . ' must fail soft');
            TinyAssert::same(1, $module->fetchCount);
        }
    }

    private static function testMalformedBodyFails(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();

        $malformed = [
            ['http_status' => 200], // no rates key
            ['http_status' => 200, 'rates' => 'oops'], // rates not an array
            'not-an-array-at-all',
            null,
        ];
        foreach ($malformed as $response) {
            StubStore::reset();
            self::configureMerchantIdentity();
            $module = self::moduleWithRatesResponse($response);
            TinyAssert::false($module->fetchTwoMerchantFeeRates([30])['success'], 'malformed body must fail soft');
        }
    }

    private static function testMalformedRateRowsAreSkipped(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();

        $module = self::moduleWithRatesResponse([
            'http_status' => 200,
            'currency' => 'EUR',
            'rates' => [
                'not-a-row',
                ['percentage_fee' => '9.99'], // no net_terms
                ['net_terms' => 'soon'], // non-numeric net_terms
                ['net_terms' => -30, 'percentage_fee' => '9.99'], // non-positive
                ['net_terms' => 30], // valid but fee fields absent -> zeros
                ['net_terms' => 60, 'percentage_fee' => 'oops', 'fixed_fee' => '1.25'], // non-numeric fee -> zero
            ],
        ]);
        $result = $module->fetchTwoMerchantFeeRates([30, 60]);

        TinyAssert::true($result['success']);
        TinyAssert::count(2, $result['fees']);
        TinyAssert::same(['percentage' => 0.0, 'fixed' => 0.0], $result['fees']['30']);
        TinyAssert::same(['percentage' => 0.0, 'fixed' => 1.25], $result['fees']['60']);
    }

    /**
     * An answer nobody can act on is a failed fetch: it is neither drawn nor
     * allowed to displace figures that were once real.
     */
    private static function testUnrenderableAnswersAreRefusedAndKeepTheStoredSet(): void
    {
        $pricedRow = ['net_terms' => 30, 'percentage_fee' => '1.00', 'fixed_fee' => '0.25'];
        // response, desc
        $cases = [
            [['http_status' => 200, 'rates' => [$pricedRow]], 'an answer with no currency key at all'],
            [['http_status' => 200, 'currency' => '', 'rates' => [$pricedRow]], 'an answer whose currency is empty'],
            [['http_status' => 200, 'currency' => '  ', 'rates' => [$pricedRow]], 'an answer whose currency is blank space'],
            [['http_status' => 200, 'currency' => ['NOK'], 'rates' => [$pricedRow]], 'an answer whose currency is not a string'],
            [['http_status' => 200, 'currency' => 'NOK', 'rates' => []], 'an answer carrying no rate rows'],
            [
                ['http_status' => 200, 'currency' => 'NOK', 'rates' => [['net_terms' => 'soon'], ['net_terms' => -30], 'junk']],
                'an answer whose every rate row fails normalisation',
            ],
        ];

        foreach ($cases as $case) {
            list($response, $desc) = $case;

            // Nothing stored: the refusal is what the caller gets.
            StubStore::reset();
            self::configureMerchantIdentity();
            Configuration::updateValue('PS_COUNTRY_DEFAULT', 47);
            $module = self::moduleWithRatesResponse($response);
            $result = $module->fetchTwoMerchantFeeRates([15, 30]);

            TinyAssert::false($result['success'], 'refused: ' . $desc);
            TinyAssert::same(Twopayment::FEE_RATES_ERROR_UPSTREAM, $result['error'], 'error category: ' . $desc);
            TinyAssert::same(false, isset($result['fees']), 'no figures offered: ' . $desc);

            // A good set already stored: it survives, labelled stale.
            StubStore::reset();
            self::configureMerchantIdentity();
            Configuration::updateValue('PS_COUNTRY_DEFAULT', 47);
            $stored = self::scriptedModule();
            $stored->response = self::okResponse();
            $good = $stored->fetchTwoMerchantFeeRates([15, 30]);
            TinyAssert::true($good['success'], 'stored a good set first: ' . $desc);

            $stored->response = $response;
            $after = $stored->fetchTwoMerchantFeeRates([15, 30]);

            TinyAssert::true($after['success'], 'still answers from the stored set: ' . $desc);
            TinyAssert::true($after['stale'], 'stored set is labelled stale: ' . $desc);
            TinyAssert::same($good['fees'], $after['fees'], 'stored figures are not displaced by: ' . $desc);
            TinyAssert::same($good['currency'], $after['currency'], 'stored currency is not displaced by: ' . $desc);
        }
    }

    /**
     * Boundary: a partial answer is renderable, so this layer keeps it. The
     * term the answer skipped is labelled as unpriced on the screen instead.
     */
    private static function testPartialAnswerIsStillASuccess(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();

        $module = self::moduleWithRatesResponse([
            'http_status' => 200,
            'currency' => 'EUR',
            'rates' => [['net_terms' => 30, 'percentage_fee' => '2.00', 'fixed_fee' => '0.50']],
        ]);
        $result = $module->fetchTwoMerchantFeeRates([15, 30, 60]);

        TinyAssert::true($result['success'], 'one priced term out of three is still a renderable answer');
        TinyAssert::same('EUR', $result['currency']);
        TinyAssert::count(1, $result['fees']);
        TinyAssert::same(['percentage' => 2.0, 'fixed' => 0.5], $result['fees']['30']);
        TinyAssert::same(false, isset($result['fees']['15']), 'an unpriced term carries no invented figure');
    }

    /**
     * Harness whose wire answer is swapped between calls, so one identity can
     * be driven through a failure/recovery sequence.
     */
    private static function scriptedModule(): object
    {
        return new class extends TwopaymentTestHarness {
            public int $fetchCount = 0;
            public $response = null;

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->fetchCount++;
                return $this->response;
            }
        };
    }

    /** @return string[] */
    private static function cooldownKeys(): array
    {
        $keys = [];
        foreach (array_keys(StubStore::$configuration) as $key) {
            if (strpos((string) $key, Twopayment::CONFIG_FEE_RATES_COOLDOWN_PREFIX) === 0) {
                $keys[] = (string) $key;
            }
        }
        return $keys;
    }

    /** Moves every live cooldown past its window, so the next call goes to the wire. */
    private static function expireCooldowns(): void
    {
        foreach (self::cooldownKeys() as $key) {
            Configuration::updateValue($key, time() - Twopayment::FEE_RATES_FAILURE_COOLDOWN - 1);
        }
    }

    private static function secondOkResponse(): array
    {
        return [
            'http_status' => 200,
            'currency' => 'NOK',
            'rates' => [
                ['net_terms' => 15, 'percentage_fee' => '3.00', 'fixed_fee' => '0.50'],
                ['net_terms' => 30, 'percentage_fee' => '4.00', 'fixed_fee' => '0.75'],
            ],
        ];
    }

    private static function testLastKnownGoodLifecycle(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        Configuration::updateValue('PS_COUNTRY_DEFAULT', 47);

        $firstFees = [
            '15' => ['percentage' => 1.5, 'fixed' => 0.0],
            '30' => ['percentage' => 2.51, 'fixed' => 0.1],
        ];
        $secondFees = [
            '15' => ['percentage' => 3.0, 'fixed' => 0.5],
            '30' => ['percentage' => 4.0, 'fixed' => 0.75],
        ];
        $failure = ['http_status' => 500];

        $steps = [
            // response, expire cooldown first, expected fetch delta, expected
            // success, expected stale (null = key absent), expected fees
            // (null = key absent), expected error (null = key absent), desc
            [$failure, false, 1, false, null, null, Twopayment::FEE_RATES_ERROR_UPSTREAM, 'first failure with nothing stored answers the error category, no fees'],
            [$failure, false, 0, false, null, null, Twopayment::FEE_RATES_ERROR_UPSTREAM, 'the failure cooldown suppresses the very next upstream call'],
            [self::okResponse(), true, 1, true, false, $firstFees, null, 'a success after the cooldown expires is fresh, not stale'],
            [$failure, false, 1, true, true, $firstFees, null, 'a failure with a stored set serves those same figures, labelled stale'],
            [self::secondOkResponse(), true, 1, true, false, $secondFees, null, 'a later success replaces the stored set and is fresh again'],
            [$failure, false, 1, true, true, $secondFees, null, 'the replaced set is what a subsequent failure serves'],
        ];

        $module = self::scriptedModule();
        $previousFetchCount = 0;
        foreach ($steps as $step) {
            list($response, $expire, $fetchDelta, $success, $stale, $fees, $error, $desc) = $step;
            if ($expire) {
                self::expireCooldowns();
            }
            $module->response = $response;
            $result = $module->fetchTwoMerchantFeeRates([15, 30]);

            TinyAssert::same($fetchDelta, $module->fetchCount - $previousFetchCount, 'wire calls: ' . $desc);
            $previousFetchCount = $module->fetchCount;
            TinyAssert::same($success, $result['success'], 'success: ' . $desc);
            TinyAssert::same($stale, isset($result['stale']) ? $result['stale'] : null, 'stale flag: ' . $desc);
            TinyAssert::same($fees, isset($result['fees']) ? $result['fees'] : null, 'figures: ' . $desc);
            TinyAssert::same($error, isset($result['error']) ? $result['error'] : null, 'error category: ' . $desc);
            if ($success) {
                TinyAssert::true(
                    isset($result['fetched_at']) && (int) $result['fetched_at'] > 0,
                    'fetched_at stamp: ' . $desc
                );
            }
            if ($success && $stale === false) {
                TinyAssert::same([], self::cooldownKeys(), 'a fresh set clears the cooldown: ' . $desc);
            }
        }
    }

    private static function testTerminalFailuresNeverReachTheWire(): void
    {
        $cases = [
            // api key, terms, expected error, desc
            ['', [30], Twopayment::API_KEY_STATUS_NOT_CONFIGURED, 'no API key stored is its own terminal category'],
            ['test-api-key', [], Twopayment::FEE_RATES_ERROR_NO_TERMS, 'no requested terms is answerable without asking upstream'],
            ['test-api-key', ['abc', -5, 0, null], Twopayment::FEE_RATES_ERROR_NO_TERMS, 'terms that all normalise away read as no terms'],
        ];

        foreach ($cases as $case) {
            list($apiKey, $terms, $error, $desc) = $case;
            StubStore::reset();
            Configuration::updateValue('PS_TWO_MERCHANT_ID', 'm-123');
            Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', $apiKey);

            $module = self::moduleWithRatesResponse(self::okResponse());
            $result = $module->fetchTwoMerchantFeeRates($terms);

            TinyAssert::false($result['success'], 'success: ' . $desc);
            TinyAssert::same($error, $result['error'], 'error category: ' . $desc);
            TinyAssert::same(0, $module->fetchCount, 'no wire call: ' . $desc);
            TinyAssert::same([], self::cooldownKeys(), 'no cooldown set: ' . $desc);
        }
    }

    /**
     * The stored set answers for one identity only. Every input the rates call
     * carries changes the answer, so a set stored under one must never be
     * served under another.
     */
    private static function testStoredSetIsIdentityScoped(): void
    {
        $storedFees = [
            '15' => ['percentage' => 1.5, 'fixed' => 0.0],
            '30' => ['percentage' => 2.51, 'fixed' => 0.1],
        ];
        // terms, default-country id, whether the stored figures may be served, desc
        $cases = [
            [[15, 30], 47, true, 'the identity the set was stored under is served it'],
            [[15, 30, 60], 47, false, 'a wider term set is not served the stored set'],
            [[30], 47, false, 'a narrower term set is not served the stored set'],
            [[15, 30], 34, false, 'a different buyer country is not served the stored set'],
        ];

        foreach ($cases as $case) {
            list($terms, $countryId, $served, $desc) = $case;

            StubStore::reset();
            self::configureMerchantIdentity();
            // Store the set under terms [15, 30] and country id 47 (ISO NO).
            Configuration::updateValue('PS_COUNTRY_DEFAULT', 47);
            $module = self::scriptedModule();
            $module->response = self::okResponse();
            TinyAssert::same($storedFees, $module->fetchTwoMerchantFeeRates([15, 30])['fees'], 'stored figures: ' . $desc);

            Configuration::updateValue('PS_COUNTRY_DEFAULT', $countryId);
            $module->response = ['http_status' => 500];
            $result = $module->fetchTwoMerchantFeeRates($terms);

            if ($served) {
                TinyAssert::same($storedFees, $result['fees'], 'figures: ' . $desc);
                TinyAssert::true($result['stale'], 'stale flag: ' . $desc);
            } else {
                TinyAssert::false($result['success'], 'success: ' . $desc);
                TinyAssert::same(false, isset($result['fees']), 'no borrowed figures: ' . $desc);
            }
        }
    }
    /**
     * A 4xx is the API refusing the request, and its own words say why; a
     * network error or a 5xx says nothing the merchant can act on.
     */
    private static function testRejectionCarriesTheApiMessage(): void
    {
        $payout = 'payout_schedule could not be inferred from the merchant\'s payee accounts';
        // response, error, http_status (null = absent), message (null = absent), log must contain, desc
        $cases = [
            [['http_status' => 400, 'error_code' => 'VALIDATION', 'error_message' => $payout, 'error_trace_id' => 't-1'], Twopayment::FEE_RATES_ERROR_REJECTED, 400, $payout, 'VALIDATION | ' . $payout, 'a 400 answers the error_message as sent'],
            [['http_status' => 422, 'data' => ['detail' => 'net_terms: field required']], Twopayment::FEE_RATES_ERROR_REJECTED, 422, 'net_terms: field required', 'HTTP 422', 'a 422 reads the FastAPI detail from the body'],
            [['http_status' => 403, 'detail' => ['loc' => 'x']], Twopayment::FEE_RATES_ERROR_REJECTED, 403, '', 'HTTP 403', 'a 4xx naming no plain message answers an empty one'],
            [['http_status' => 404, 'data' => null], Twopayment::FEE_RATES_ERROR_REJECTED, 404, '', 'HTTP 404', 'a 4xx with no body answers an empty message'],
            [['http_status' => 400, 'error_message' => '<b>bad</b>'], Twopayment::FEE_RATES_ERROR_REJECTED, 400, '<b>bad</b>', 'HTTP 400', 'markup is passed on as text, for the screen to escape'],
            [['http_status' => 500, 'error_message' => 'internal'], Twopayment::FEE_RATES_ERROR_UPSTREAM, null, null, 'HTTP 500 internal', 'a 5xx stays the generic category'],
            [['http_status' => 0, 'error_message' => 'Unable to connect to Two API'], Twopayment::FEE_RATES_ERROR_UPSTREAM, null, null, 'HTTP 0', 'a network error stays the generic category'],
        ];

        foreach ($cases as $case) {
            list($response, $error, $status, $message, $logged, $desc) = $case;
            StubStore::reset();
            PrestaShopLogger::reset();
            self::configureMerchantIdentity();
            $module = self::moduleWithRatesResponse($response);
            $result = $module->fetchTwoMerchantFeeRates([30]);

            TinyAssert::false($result['success'], 'success: ' . $desc);
            TinyAssert::same($error, $result['error'], 'error category: ' . $desc);
            TinyAssert::same($status, isset($result['http_status']) ? $result['http_status'] : null, 'http_status: ' . $desc);
            TinyAssert::same($message, isset($result['message']) ? $result['message'] : null, 'message: ' . $desc);
            TinyAssert::same(false, isset($result['detail']), 'the log detail is not answered: ' . $desc);
            TinyAssert::count(1, PrestaShopLogger::$logs, 'one log line: ' . $desc);
            TinyAssert::true(
                strpos(PrestaShopLogger::$logs[0]['message'], $logged) !== false,
                'log names the status and message: ' . $desc . ' (got: ' . PrestaShopLogger::$logs[0]['message'] . ')'
            );
        }
    }

    /**
     * A render inside the cooldown makes no wire call, and still says why the
     * last one failed rather than calling a refusal "could not be reached".
     */
    private static function testCoolingRendersKeepTheRejection(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        self::configureMerchantIdentity();
        $module = self::scriptedModule();
        $module->response = ['http_status' => 400, 'error_message' => 'payout_schedule could not be inferred'];
        $first = $module->fetchTwoMerchantFeeRates([30]);

        $module->response = self::okResponse();
        $cooling = $module->fetchTwoMerchantFeeRates([30]);

        TinyAssert::same(1, $module->fetchCount, 'no wire call while cooling');
        TinyAssert::same($first, $cooling, 'the cooling answer is the rejection itself');
        TinyAssert::count(1, PrestaShopLogger::$logs, 'a cooling render does not log again');

        // A cooldown written before the failure was kept with it is a bare
        // timestamp, and still cools as the generic category.
        StubStore::reset();
        self::configureMerchantIdentity();
        $module = self::scriptedModule();
        $module->response = ['http_status' => 500];
        $module->fetchTwoMerchantFeeRates([30]);
        foreach (self::cooldownKeys() as $key) {
            Configuration::updateValue($key, time());
        }
        $legacy = $module->fetchTwoMerchantFeeRates([30]);
        TinyAssert::same(1, $module->fetchCount, 'a legacy cooldown still suppresses the wire call');
        TinyAssert::same(['success' => false, 'error' => Twopayment::FEE_RATES_ERROR_UPSTREAM], $legacy, 'legacy cooldown answer');
    }
}
