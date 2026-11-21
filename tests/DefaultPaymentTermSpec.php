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
        self::testDefaultTermResolution();

        // Shared merchant-record fetch / cache behaviour.
        self::testSharedFetchPopulatesBothCachesInOneCall();
        self::testDueInDaysIsCacheOnlyNeverFetches();
        self::testDueInDaysNullWhenAbsentFromResponse();
        self::testFreshCacheServedWithoutRefetch();
        self::testStaleRecordStandsInAndRefetchesBoth();
        self::testFailedFirstFetchRetriesAfterBackoff();

        // The interaction the last review flagged as untested: a due_in_days the
        // backend-narrowed available_terms set no longer offers must be ignored.
        self::testDefaultIgnoresApiDefaultWithdrawnFromBackendTerms();

        // TWO-25709.
        self::testRetainedTermIsPublishedToTheCheckoutJs();
    }

    /**
     * TWO-25709: the checkout JS seeds its term picker from the payload this
     * hook publishes, and the order is booked on getSelectedPaymentTerm() -
     * the retained selection, not the default. Publishing only the default
     * leaves the picker showing one term while the submission uses another,
     * which is not visible from the browser tests: they can only read what
     * this key carries.
     */
    private static function testRetainedTermIsPublishedToTheCheckoutJs(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/twopayment.php');
        $payloadStart = strpos($source, "Media::addJsDef(array('twopayment' =>");
        TinyAssert::true($payloadStart !== false, 'the checkout JS payload is no longer built with Media::addJsDef');

        // [key, resolver, description]
        $cases = [
            ['selected_payment_term', 'getSelectedPaymentTerm', 'the retained selection must reach the term picker'],
            ['default_payment_term', 'getDefaultPaymentTerm', 'the configured default stays published as the fallback'],
        ];
        foreach ($cases as [$key, $resolver, $description]) {
            TinyAssert::true(
                strpos($source, "'" . $key . "' => (int) \$this->" . $resolver . "()", $payloadStart) !== false,
                $description
            );
        }
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

    /**
     * Each row: the backend's offerable set, the ticked presets, due_in_days,
     * the expected default, a description.
     *
     * @return array<int,array{0:int[],1:int[],2:int|null,3:int|null,4:string}>
     */
    private static function resolutionCases(): array
    {
        return [
            [[7, 15, 30, 60], [7, 15, 30, 60], 15, 15, 'the API default term is used when offered'],
            [[7, 15, 30], [7, 15, 30], 45, 30, 'an API default term that is not offered falls through'],
            [[7, 15, 30, 60], [7, 15, 30, 60], null, 30, 'no API default falls through to the offered 30'],
            [[7, 15, 60], [7, 15, 60], null, 7, 'without 30 offered the shortest offered term is used'],
            [[30, 60], [60], 30, 60, 'a single offered term wins over the API default'],
            [[], [7, 15, 30], null, null, 'an unresolvable merchant record leaves no default at all'],
            [[7, 15, 30], [], null, null, 'no ticked term leaves no default at all'],
        ];
    }

    private static function testDefaultTermResolution(): void
    {
        foreach (self::resolutionCases() as [$backend, $ticked, $apiDefault, $expected, $description]) {
            StubStore::reset();
            self::enableTerms($ticked);
            $module = self::moduleWithApiDefault($apiDefault);
            $module->primeTwoAvailableTerms($backend);

            TinyAssert::same($expected, $module->getDefaultPaymentTerm(), $description);
        }
    }

    // ---- shared merchant-record fetch / cache -----------------------------

    private static function testSharedFetchPopulatesBothCachesInOneCall(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        $module = self::moduleWithMerchantResponse(self::okResponse([7, 15, 30], 15));

        // A single refresh primes BOTH caches from ONE wire call.
        TinyAssert::same(array(7, 15, 30), $module->getMerchantAvailableTerms());
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

        $module->getMerchantAvailableTerms();

        TinyAssert::same(null, $module->getMerchantDueInDays());
        TinyAssert::same(0, (int) Configuration::get(Twopayment::CONFIG_MERCHANT_DUE_IN_DAYS));
        TinyAssert::same(array(7, 15, 30), $module->getMerchantAvailableTerms());
    }

    private static function testFreshCacheServedWithoutRefetch(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        $module = self::moduleWithMerchantResponse(self::okResponse([7, 15, 30], 15));

        $module->getMerchantAvailableTerms(); // prime (fetch 1)
        // A second read inside the staleness window serves cache, not the wire.
        $module->getMerchantAvailableTerms();

        TinyAssert::same(1, $module->fetchCount);
        TinyAssert::same(15, $module->getMerchantDueInDays());
    }

    private static function testStaleRecordStandsInAndRefetchesBoth(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        // A record fetched over the staleness window ago.
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS, json_encode(array(30)));
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_DUE_IN_DAYS, 30);
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED, '0');
        Configuration::updateValue(
            Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS,
            time() - Twopayment::MERCHANT_RECORD_STALE_AFTER - 10
        );
        $module = self::moduleWithMerchantResponse(self::okResponse([7, 15, 60], 60));

        TinyAssert::same(array(7, 15, 60), $module->getMerchantAvailableTerms());
        TinyAssert::same(60, $module->getMerchantDueInDays());
        TinyAssert::same(1, $module->fetchCount);
    }

    /**
     * A record never fetched retries on the short backoff, and the failure bumps
     * the clock so a burst of reads shares one attempt (TWO-24859).
     */
    private static function testFailedFirstFetchRetriesAfterBackoff(): void
    {
        StubStore::reset();
        self::configureMerchantIdentity();
        // 500 with no body: a failed fetch.
        $module = self::moduleWithMerchantResponse(array('http_status' => 500));

        $module->getMerchantAvailableTerms();
        TinyAssert::same(1, $module->fetchCount);

        // The failure's own retry floor: the next read is due within the backoff.
        $due_in = (int) Configuration::get(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS)
            + Twopayment::MERCHANT_RECORD_RETRY_BACKOFF - time();
        TinyAssert::true($due_in > 0 && $due_in <= Twopayment::MERCHANT_RECORD_RETRY_BACKOFF);

        // Within the backoff window a second read does not re-hit the API.
        $module->getMerchantAvailableTerms();
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
        $module->getMerchantAvailableTerms(); // prime both caches

        // Merchant ticks 7/15/30 (90 cannot be ticked - backend does not offer it).
        self::enableTerms([7, 15, 30]);

        // The raw default is cached (90) but is not an offered term ...
        TinyAssert::same(90, $module->getMerchantDueInDays());
        TinyAssert::same(array(7, 15, 30), $module->getAvailablePaymentTerms());
        // ... so the default falls back to the historical 30, not 90.
        TinyAssert::same(30, $module->getDefaultPaymentTerm());
    }
}
