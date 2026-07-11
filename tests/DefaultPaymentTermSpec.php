<?php

declare(strict_types=1);

/**
 * TWO-24859 - the merchant's API default term (due_in_days) seeds the plugin's
 * default offered term, mirroring magento-plugin and woocommerce-plugin, without
 * overwriting the merchant's own term config.
 *
 * After consolidation onto the TWO-24813 merchant-record seam, `due_in_days` and
 * `available_terms` are sourced from a SINGLE GET /v1/merchant fetch
 * (getMerchantAvailableTerms), share one cache timestamp, and invalidate together.
 * getMerchantDueInDays() is cache-only - the sanctioned refresh points prime it.
 */
final class DefaultPaymentTermSpec
{
    public static function runAll(): void
    {
        // getDefaultPaymentTerm preference logic (due_in_days as a seed).
        self::testDefaultTermPrefersApiDueInDaysWhenOffered();
        self::testDefaultTermIgnoresApiDueInDaysWhenNotOffered();
        self::testDefaultTermFallsBackTo30WhenNoApiDefault();
        self::testSingleOfferedTermWinsOverApiDefault();

        // Shared merchant-record fetch / cache behaviour.
        self::testSharedFetchPopulatesBothCachesInOneCall();
        self::testDueInDaysIsCacheOnlyNeverFetches();
        self::testDueInDaysNullWhenAbsentFromResponse();
        self::testFreshCacheServedWithoutRefetch();
        self::testStaleCacheRefetchesBoth();
        self::testInvalidateClearsBothCaches();
        self::testFailedFetchRetriesAfterBackoffNotFullTtl();

        // The interaction the last review flagged as untested: a due_in_days the
        // backend-narrowed available_terms set no longer offers must be ignored.
        self::testDefaultIgnoresApiDefaultWithdrawnFromBackendTerms();
    }

    private static function enableTerms(array $days): void
    {
        foreach (Twopayment::PAYMENT_TERMS_OPTIONS as $term) {
            Configuration::updateValue('PS_TWO_PAYMENT_TERMS_' . $term, in_array($term, $days, true) ? 1 : 0);
        }
    }

    /** Set the identity config the merchant-record fetch guards on. */
    private static function configureMerchantIdentity(): void
    {
        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'm-123');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');
    }

    /**
     * Harness whose API default term is fixed, so getDefaultPaymentTerm can be
     * exercised without touching the cache/fetch path.
     */
    private static function moduleWithApiDefault(?int $apiDefault): TwopaymentTestHarness
    {
        return new class ($apiDefault) extends TwopaymentTestHarness {
            private $apiDefault;

            public function __construct($apiDefault)
            {
                parent::__construct();
                $this->apiDefault = $apiDefault;
            }

            public function getMerchantDueInDays()
            {
                return $this->apiDefault;
            }
        };
    }

    /**
     * Harness whose GET /v1/merchant fetch (setTwoPaymentRequest) is stubbed and
     * counted, so the shared cache behaviour can be asserted offline.
     */
    private static function moduleWithMerchantResponse($response): object
    {
        return new class ($response) extends TwopaymentTestHarness {
            public int $fetchCount = 0;
            private $response;

            public function __construct($response)
            {
                parent::__construct();
                $this->response = $response;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->fetchCount++;
                return $this->response;
            }
        };
    }

    private static function okResponse(array $terms, ?int $dueInDays): array
    {
        $body = array('http_status' => Twopayment::HTTP_STATUS_OK, 'available_terms' => $terms);
        if ($dueInDays !== null) {
            $body['due_in_days'] = $dueInDays;
        }
        return $body;
    }

    // ---- getDefaultPaymentTerm preference logic ---------------------------

    private static function testDefaultTermPrefersApiDueInDaysWhenOffered(): void
    {
        StubStore::reset();
        self::enableTerms([7, 15, 30, 60]);
        $module = self::moduleWithApiDefault(15);

        TinyAssert::same(15, $module->getDefaultPaymentTerm());
    }

    private static function testDefaultTermIgnoresApiDueInDaysWhenNotOffered(): void
    {
        StubStore::reset();
        self::enableTerms([7, 15, 30]);
        // due_in_days = 45 is not an offered term: fall through to the historical
        // DEFAULT_PAYMENT_TERM_DAYS (30), which is offered.
        $module = self::moduleWithApiDefault(45);

        TinyAssert::same(30, $module->getDefaultPaymentTerm());
    }

    private static function testDefaultTermFallsBackTo30WhenNoApiDefault(): void
    {
        StubStore::reset();
        self::enableTerms([7, 15, 30, 60]);
        $module = self::moduleWithApiDefault(null);

        TinyAssert::same(30, $module->getDefaultPaymentTerm());
    }

    private static function testSingleOfferedTermWinsOverApiDefault(): void
    {
        StubStore::reset();
        self::enableTerms([60]);
        // Only one term offered: it is the default regardless of due_in_days.
        $module = self::moduleWithApiDefault(30);

        TinyAssert::same(60, $module->getDefaultPaymentTerm());
    }

    // ---- shared merchant-record fetch / cache -----------------------------

    private static function testSharedFetchPopulatesBothCachesInOneCall(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        $module = self::moduleWithMerchantResponse(self::okResponse([7, 15, 30], 15));

        // A single refresh primes BOTH caches from ONE wire call.
        TinyAssert::same(array(7, 15, 30), $module->getMerchantAvailableTerms(true));
        TinyAssert::same(15, $module->getMerchantDueInDays());
        TinyAssert::same(1, $module->fetchCount);
    }

    private static function testDueInDaysIsCacheOnlyNeverFetches(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        // No prime: cache-only reader must NOT hit the wire, and returns null.
        $module = self::moduleWithMerchantResponse(self::okResponse([7, 15, 30], 15));

        TinyAssert::same(null, $module->getMerchantDueInDays());
        TinyAssert::same(0, $module->fetchCount);
    }

    private static function testDueInDaysNullWhenAbsentFromResponse(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        // Valid response, but no due_in_days key: a legitimate "unset" answer.
        $module = self::moduleWithMerchantResponse(self::okResponse([7, 15, 30], null));

        $module->getMerchantAvailableTerms(true);

        TinyAssert::same(null, $module->getMerchantDueInDays());
        TinyAssert::same(0, (int) Configuration::get(Twopayment::CONFIG_MERCHANT_DUE_IN_DAYS));
        TinyAssert::same(array(7, 15, 30), $module->getMerchantAvailableTerms(false));
    }

    private static function testFreshCacheServedWithoutRefetch(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        $module = self::moduleWithMerchantResponse(self::okResponse([7, 15, 30], 15));

        $module->getMerchantAvailableTerms(true); // prime (fetch 1)
        // A second refresh within TTL must serve cache, not re-hit the wire.
        $module->getMerchantAvailableTerms(true);

        TinyAssert::same(1, $module->fetchCount);
        TinyAssert::same(15, $module->getMerchantDueInDays());
    }

    private static function testStaleCacheRefetchesBoth(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        // Pre-seed a stale cache belonging to an earlier fetch.
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS, json_encode(array(30)));
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_DUE_IN_DAYS, 30);
        Configuration::updateValue(
            Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS,
            time() - Twopayment::MERCHANT_AVAILABLE_TERMS_TTL - 10
        );
        $module = self::moduleWithMerchantResponse(self::okResponse([7, 15, 60], 60));

        TinyAssert::same(array(7, 15, 60), $module->getMerchantAvailableTerms(true));
        TinyAssert::same(60, $module->getMerchantDueInDays());
        TinyAssert::same(1, $module->fetchCount);
    }

    private static function testInvalidateClearsBothCaches(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        $module = self::moduleWithMerchantResponse(self::okResponse([7, 15, 30], 15));
        $module->getMerchantAvailableTerms(true); // prime both

        $module->invalidateMerchantAvailableTerms();

        TinyAssert::same(array(), $module->getMerchantAvailableTerms(false));
        TinyAssert::same(null, $module->getMerchantDueInDays());
        TinyAssert::same(0, (int) Configuration::get(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS));
    }

    /**
     * A failed fetch must not mark the shared cache fresh for the full TTL: the
     * retry window shrinks to MERCHANT_RECORD_RETRY_BACKOFF, while a concurrent
     * burst is still absorbed within that window (TWO-24859 review).
     */
    private static function testFailedFetchRetriesAfterBackoffNotFullTtl(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        // 500 with no body: a failed fetch.
        $module = self::moduleWithMerchantResponse(array('http_status' => 500));

        $module->getMerchantAvailableTerms(true);
        TinyAssert::same(1, $module->fetchCount);

        // Remaining freshness after a failure is at most the retry backoff.
        $checked_on = (int) Configuration::get(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS);
        $remaining = $checked_on + Twopayment::MERCHANT_AVAILABLE_TERMS_TTL - time();
        TinyAssert::true($remaining > 0 && $remaining <= Twopayment::MERCHANT_RECORD_RETRY_BACKOFF + 5);

        // Within the backoff window a second refresh does not re-hit the API.
        $module->getMerchantAvailableTerms(true);
        TinyAssert::same(1, $module->fetchCount);
    }

    /**
     * The backend can offer a due_in_days that its own narrowed available_terms
     * set no longer includes (a withdrawn term). getDefaultPaymentTerm must not
     * select it - it is not offerable - and must fall back to 30 (TWO-24859).
     */
    private static function testDefaultIgnoresApiDefaultWithdrawnFromBackendTerms(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        // Backend offers [7,15,30] but reports due_in_days = 90 (withdrawn).
        $module = self::moduleWithMerchantResponse(self::okResponse([7, 15, 30], 90));
        $module->getMerchantAvailableTerms(true); // prime both caches

        // Merchant ticks 7/15/30 (90 cannot be ticked - backend does not offer it).
        self::enableTerms([7, 15, 30]);

        // The raw default is cached (90) but is not an offered term ...
        TinyAssert::same(90, $module->getMerchantDueInDays());
        TinyAssert::same(array(7, 15, 30), $module->getAvailablePaymentTerms());
        // ... so the default falls back to the historical 30, not 90.
        TinyAssert::same(30, $module->getDefaultPaymentTerm());
    }
}
