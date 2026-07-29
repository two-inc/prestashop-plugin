<?php

declare(strict_types=1);

/**
 * TWO-25105 - the real FX layer on GET /refdata/v1/fx-rates, replacing
 * PrestaShop core's own conversion rates and the currency-pinning
 * workaround. Contract under test:
 *
 *  - convertTwoAmountBetweenCurrencies converts via the endpoint's
 *    EUR-pivot table (rates[CCY] = 1 CCY in EUR), NEVER via PS core
 *    conversion_rate (poisoned in every fixture to prove it).
 *  - Cache: one wire fetch fills the full table; every later conversion
 *    (any pair, any module instance) is served from Configuration without
 *    refetching until the 6h TTL expires; `as_of` rides along.
 *  - Failure: a failed fetch serves the last-known-good table (gate
 *    conversions keep working) and backs off FX_RATES_RETRY_BACKOFF before
 *    retrying; with NO table ever fetched, conversions resolve null - the
 *    charge-deciding gates fail closed, display conversions fail soft.
 *  - Fixed surcharge / cap re-denomination: fetchTwoTermFee converts the
 *    shop-default-currency figures into the quote currency through the
 *    endpoint rate (pinning removed); an unconvertible figure omits the
 *    quote instead of sending a wrong-currency amount.
 *
 * TWO-25269 reverses the buyer-surcharge posture from fail-soft to
 * fail-closed, and the assertions in the second half of this file are the
 * OPPOSITE of what they asserted before it. Three rounds-to-zero cases, held
 * apart deliberately:
 *
 *   no rate resolvable        -> withhold the payment option, error log
 *   configured cap -> 0.00    -> withhold the payment option, error log
 *                                (a zero cap reads as NO cap downstream: an
 *                                uncapped percentage, i.e. an OVERCHARGE)
 *   fixed amount -> 0.00      -> proceed with 0.00, info log (correct, not a
 *                                failure)
 *
 * An ABSENT cap is a legitimate uncapped-percentage configuration and must
 * keep charging normally - see
 * testAbsentCapStillChargesAndOffersTheOption.
 */
final class FxRatesSpec
{
    public static function runAll(): void
    {
        self::testConvertFetchesOnDemandAndUsesEndpointRates();
        self::testConversionsAreServedFromCacheWithoutRefetch();
        self::testSameCurrencyConversionNeedsNoRates();
        self::testRefreshHonoursTtl();
        self::testFailedRefreshServesLastKnownGoodAndBacksOff();
        self::testPartialResponseMergesOverLastKnownGood();
        self::testAllJunkResponseIsAFailedFetch();
        self::testNeverFetchedFailsGateClosedAndDisplaySoft();
        self::testMissingApiKeyNeverFetches();
        self::testGateJudgesCrossCurrencyBasketOnEndpointRate();
        self::testFixedSurchargeAndCapConvertedIntoQuoteCurrency();
        self::testUnconvertibleFixedSurchargeOmitsQuote();
        self::testSettingsSaveWarmsColdCache();
        self::testSettingsSaveWarmRespectsFailureBackoffAndMissingKey();
        self::testMerchantIdentityChangeClearsFxClockSoWarmFetchRuns();
        // TWO-25269 - fail-closed reversal.
        self::testNoRateForCartCurrencyWithholdsPaymentOption();
        self::testPercentageOnlySurchargeNeverTripsTheGate();
        self::testCapRoundingToZeroWithholdsPaymentOption();
        self::testAbsentCapStillChargesAndOffersTheOption();
        self::testFixedSurchargeRoundingToZeroProceedsWithInfoLog();
        // The cart-line-sync half of TWO-25269 lives in SurchargeCartLineSpec,
        // which already owns the real cart/product/tax fixture:
        // testQuoteFailureKeepsLineAndFailsLoudly.
    }

    /**
     * Settings-save harness: the same stubbed-wire module as fxModule, plus
     * an entry point for the protected general-form save and an injectable
     * verified merchant id (what the real save gets from API-key
     * verification).
     */
    private static function saveModule($fxResponse, ?string $verifiedMerchantId = null): object
    {
        $module = new class (['/refdata/v1/fx-rates' => $fxResponse]) extends TwopaymentTestHarness {
            public int $fxFetchCount = 0;
            private array $responsesByEndpoint;

            public function __construct(array $responsesByEndpoint)
            {
                parent::__construct();
                $this->responsesByEndpoint = $responsesByEndpoint;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                if ($endpoint === '/refdata/v1/fx-rates') {
                    $this->fxFetchCount++;
                }
                $response = $this->responsesByEndpoint[$endpoint] ?? ['http_status' => 500];
                return is_callable($response) ? $response($payload) : $response;
            }

            public function setVerifiedMerchantIdForTest(?string $id): void
            {
                $this->verifiedMerchantId = $id;
            }

            public function saveGeneralForTest(): void
            {
                $this->saveTwoGeneralFormValues();
            }
        };
        $module->setVerifiedMerchantIdForTest($verifiedMerchantId);
        return $module;
    }

    /** POST values a minimal valid general-form save needs. */
    private static function generalFormPost(string $apiKey = 'test-api-key'): void
    {
        Tools::setTestValue('PS_TWO_ENVIRONMENT', 'development');
        Tools::setTestValue('PS_TWO_TITLE_1', 'Two title');
        Tools::setTestValue('PS_TWO_SUB_TITLE_1', 'Two subtitle');
        Tools::setTestValue('PS_TWO_MERCHANT_SHORT_NAME', 'merchant');
        Tools::setTestValue('PS_TWO_MERCHANT_API_KEY', $apiKey);
    }

    /**
     * TWO-25184: the module has no scheduler, so a fresh install's cache
     * stayed empty until the FIRST shopper reached checkout and paid the
     * fetch inline (or failed closed when it failed). Saving the settings -
     * the moment the API key first exists - must warm it instead.
     */
    private static function testSettingsSaveWarmsColdCache(): void
    {
        self::reset();
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES, '');
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, 0);
        $module = self::saveModule(self::ratesResponse());
        self::generalFormPost();

        $module->saveGeneralForTest();

        TinyAssert::same(1, $module->fxFetchCount, 'a settings save on a cold cache must warm it');
        TinyAssert::same('2026-07-14', $module->getTwoFxRatesTable()['as_of']);
        // Warm, so a checkout conversion is served from cache - no inline
        // fetch on the hot path.
        TinyAssert::same(100.0, $module->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'NOK'));
        TinyAssert::same(1, $module->fxFetchCount);
    }

    private static function testSettingsSaveWarmRespectsFailureBackoffAndMissingKey(): void
    {
        self::reset();
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES, '');
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, 0);
        $module = self::saveModule(['http_status' => 500]);
        self::generalFormPost();

        // A merchant re-saving a form against a dead endpoint must not turn
        // the save button into an unthrottled fetch trigger: the failure
        // backoff absorbs the repeat.
        $module->saveGeneralForTest();
        TinyAssert::same(1, $module->fxFetchCount);
        $module->saveGeneralForTest();
        TinyAssert::same(1, $module->fxFetchCount, 'FX_RATES_RETRY_BACKOFF must absorb a repeated save');

        // No API key: nothing to authenticate a fetch with, so no wire call
        // at all (the endpoint is merchant-API-key authenticated).
        self::reset();
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES, '');
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, 0);
        $keyless = self::saveModule(self::ratesResponse());
        self::generalFormPost('');
        $keyless->saveGeneralForTest();
        TinyAssert::same(0, $keyless->fxFetchCount, 'a keyless save must not touch the wire');
    }

    private static function testMerchantIdentityChangeClearsFxClockSoWarmFetchRuns(): void
    {
        self::reset();
        // A table fetched minutes ago under the OLD merchant identity: its
        // clock is well inside the 6h TTL and would suppress the warm-up
        // fetch for hours after the key swap.
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES, json_encode([
            'base' => 'EUR',
            'as_of' => '2026-07-01',
            'rates' => ['EUR' => 1.0, 'NOK' => 0.2],
        ]));
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, time());
        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'm-old');

        $module = self::saveModule(self::ratesResponse(), 'm-new');
        self::generalFormPost('new-api-key');
        $module->saveGeneralForTest();

        TinyAssert::same(1, $module->fxFetchCount, 'an identity change must not leave the warm fetch TTL-suppressed');
        // Fresh rate replaced the pre-swap one (0.2 -> 0.1).
        TinyAssert::same(100.0, $module->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'NOK'));

        // Unchanged identity: the clock is untouched, so the TTL still holds
        // the fetch off - a save is not a cache-buster.
        self::reset();
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES, json_encode([
            'base' => 'EUR',
            'as_of' => '2026-07-01',
            'rates' => ['EUR' => 1.0, 'NOK' => 0.2],
        ]));
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, time());
        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'm-same');
        $same = self::saveModule(self::ratesResponse(), 'm-same');
        self::generalFormPost();
        $same->saveGeneralForTest();
        TinyAssert::same(0, $same->fxFetchCount, 'a save within the TTL must not refetch');
    }

    /**
     * Common fixture. Shop currencies 1=EUR (default), 2=NOK, 3=GBP with
     * POISONED PS-core conversion rates: any figure derived from 999.0
     * proves core conversion leaked back in.
     */
    private static function reset(): void
    {
        StubStore::reset();
        Tools::resetTestValues();
        PrestaShopLogger::reset();
        Context::getContext()->cookie = new Cookie();
        StubStore::$currencies = [
            1 => ['iso_code' => 'EUR', 'conversion_rate' => 999.0],
            2 => ['iso_code' => 'NOK', 'conversion_rate' => 999.0],
            3 => ['iso_code' => 'GBP', 'conversion_rate' => 999.0],
        ];
        Configuration::updateValue('PS_CURRENCY_DEFAULT', 1);
        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'm-123');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');
    }

    /**
     * Round-number EUR-pivot table: 10 NOK/EUR, 0.8 GBP/EUR.
     */
    private static function ratesResponse(string $asOf = '2026-07-14'): array
    {
        return [
            'http_status' => 200,
            'base' => 'EUR',
            'as_of' => $asOf,
            'rates' => ['EUR' => 1.0, 'NOK' => 0.1, 'GBP' => 1.25],
        ];
    }

    /**
     * Harness whose wire calls are stubbed per endpoint and counted.
     *
     * @param array<string,mixed> $responsesByEndpoint endpoint => response
     *        (or a callable receiving the payload)
     */
    private static function moduleWithResponses(array $responsesByEndpoint): object
    {
        return new class ($responsesByEndpoint) extends TwopaymentTestHarness {
            public int $fxFetchCount = 0;
            /** @var array<int,array{endpoint:string,method:string,payload:array,timeout:mixed}> */
            public array $requests = [];
            private array $responsesByEndpoint;

            public function __construct(array $responsesByEndpoint)
            {
                parent::__construct();
                $this->responsesByEndpoint = $responsesByEndpoint;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->requests[] = [
                    'endpoint' => $endpoint,
                    'method' => $method,
                    'payload' => $payload,
                    'timeout' => $timeout,
                ];
                if ($endpoint === '/refdata/v1/fx-rates') {
                    $this->fxFetchCount++;
                }
                $response = $this->responsesByEndpoint[$endpoint] ?? ['http_status' => 500];
                return is_callable($response) ? $response($payload) : $response;
            }
        };
    }

    private static function fxModule($fxResponse): object
    {
        return self::moduleWithResponses(['/refdata/v1/fx-rates' => $fxResponse]);
    }

    /** @param float $gross @param float $net */
    private static function cart(int $idCurrency, float $gross, float $net): Cart
    {
        $cart = new Cart();
        $cart->id = 42;
        $cart->id_currency = $idCurrency;
        StubStore::$cartTotals[42][true][Cart::BOTH] = $gross;
        StubStore::$cartTotals[42][false][Cart::BOTH] = $net;
        return $cart;
    }

    private static function testConvertFetchesOnDemandAndUsesEndpointRates(): void
    {
        self::reset();
        $module = self::fxModule(self::ratesResponse());

        // GBP -> NOK crosses through the EUR pivot: 100 * 1.25 / 0.1.
        TinyAssert::same(1250.0, $module->convertTwoAmountBetweenCurrencies(100.0, 'GBP', 'NOK'));
        TinyAssert::same(1, $module->fxFetchCount, 'a cold cache is filled by exactly one on-demand fetch');

        // The fetch is a GET on the refdata endpoint with the tight
        // render-path timeout - never the long default.
        TinyAssert::same('/refdata/v1/fx-rates', $module->requests[0]['endpoint']);
        TinyAssert::same('GET', $module->requests[0]['method']);
        TinyAssert::same(Twopayment::API_TIMEOUT_STATE_CHECK, $module->requests[0]['timeout']);

        // The response's as_of staleness floor is retained in the cache.
        $table = $module->getTwoFxRatesTable();
        TinyAssert::same('2026-07-14', $table['as_of']);
        TinyAssert::same('EUR', $table['base']);
    }

    private static function testConversionsAreServedFromCacheWithoutRefetch(): void
    {
        self::reset();
        $module = self::fxModule(self::ratesResponse());

        TinyAssert::same(100.0, $module->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'NOK'));
        // Different pairs, both directions: still the one original fetch.
        TinyAssert::same(8.0, $module->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'GBP'));
        TinyAssert::same(80.0, $module->convertTwoAmountBetweenCurrencies(1000.0, 'NOK', 'GBP'));
        TinyAssert::same(1, $module->fxFetchCount, 'a fresh table must not be refetched per conversion');

        // A brand-new module instance (fresh request) reads the shared
        // Configuration cache - no wire call at all.
        $second = self::fxModule(self::ratesResponse('2099-01-01'));
        TinyAssert::same(100.0, $second->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'NOK'));
        TinyAssert::same(0, $second->fxFetchCount, 'cross-request cache must serve without refetching');
        TinyAssert::same('2026-07-14', $second->getTwoFxRatesTable()['as_of'], 'cached as_of served, not a new fetch');
    }

    private static function testSameCurrencyConversionNeedsNoRates(): void
    {
        self::reset();
        $module = self::fxModule(self::ratesResponse());
        TinyAssert::same(10.55, $module->convertTwoAmountBetweenCurrencies(10.554, 'EUR', 'EUR'));
        TinyAssert::same(0, $module->fxFetchCount, 'same-currency conversion must not touch the wire');
    }

    private static function testRefreshHonoursTtl(): void
    {
        self::reset();
        $module = self::fxModule(self::ratesResponse('2026-07-15'));

        // Fresh clock: the sanctioned-refresh-point call is a no-op.
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES, json_encode([
            'base' => 'EUR',
            'as_of' => '2026-07-10',
            'rates' => ['EUR' => 1.0, 'NOK' => 0.2],
        ]));
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, time());
        TinyAssert::false($module->refreshTwoFxRates(), 'within TTL the refresh must not fetch');
        TinyAssert::same(0, $module->fxFetchCount);

        // Expired clock: one fetch renews the table AND its as_of.
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, time() - Twopayment::FX_RATES_TTL - 1);
        TinyAssert::true($module->refreshTwoFxRates());
        TinyAssert::same(1, $module->fxFetchCount);
        TinyAssert::same('2026-07-15', $module->getTwoFxRatesTable()['as_of']);
        TinyAssert::same(100.0, $module->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'NOK'), 'renewed rate (0.1) must replace the stale one (0.2)');
    }

    private static function testFailedRefreshServesLastKnownGoodAndBacksOff(): void
    {
        self::reset();
        $module = self::fxModule(['http_status' => 500]);

        // Last-known-good table, expired TTL.
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES, json_encode([
            'base' => 'EUR',
            'as_of' => '2026-07-01',
            'rates' => ['EUR' => 1.0, 'NOK' => 0.1],
        ]));
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, time() - Twopayment::FX_RATES_TTL - 1);

        TinyAssert::false($module->refreshTwoFxRates(), 'failed fetch reports failure');
        TinyAssert::same(1, $module->fxFetchCount);
        // Serve-stale: the gate keeps converting on the last-known-good rate.
        TinyAssert::same(100.0, $module->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'NOK'));
        TinyAssert::same('2026-07-01', $module->getTwoFxRatesTable()['as_of']);

        // Within the retry backoff no second fetch fires - not from the
        // refresh point, not from conversions.
        TinyAssert::false($module->refreshTwoFxRates());
        $module->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'NOK');
        TinyAssert::same(1, $module->fxFetchCount, 'failure backoff must absorb repeat refresh attempts');

        // Once the backoff has elapsed the retry happens.
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, time() - Twopayment::FX_RATES_TTL - 1);
        TinyAssert::false($module->refreshTwoFxRates());
        TinyAssert::same(2, $module->fxFetchCount, 'after the backoff a retry must fire');
    }

    private static function testPartialResponseMergesOverLastKnownGood(): void
    {
        self::reset();
        // The refetch succeeds but moves NOK, DROPS SEK entirely, and
        // carries a malformed (zero) GBP rate.
        $module = self::fxModule([
            'http_status' => 200,
            'base' => 'EUR',
            'as_of' => '2026-07-15',
            'rates' => ['EUR' => 1.0, 'NOK' => 0.09, 'GBP' => 0],
        ]);
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES, json_encode([
            'base' => 'EUR',
            'as_of' => '2026-07-01',
            'rates' => ['EUR' => 1.0, 'NOK' => 0.1, 'GBP' => 1.25, 'SEK' => 0.09],
        ]));
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, time() - Twopayment::FX_RATES_TTL - 1);

        TinyAssert::true($module->refreshTwoFxRates());
        // Fresh validated values win; dropped and malformed currencies keep
        // their last-known-good rates instead of failing their gates closed
        // for a whole TTL.
        TinyAssert::same(111.11, $module->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'NOK'), 'fresh NOK rate (0.09 EUR/NOK) must replace the cached 0.1');
        TinyAssert::same(8.0, $module->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'GBP'), 'malformed (zero) GBP rate must not displace the cached 1.25');
        TinyAssert::same(111.11, $module->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'SEK'), 'dropped SEK keeps its last-known-good rate');
        TinyAssert::same(1, $module->fxFetchCount);
    }

    private static function testAllJunkResponseIsAFailedFetch(): void
    {
        self::reset();
        // A 200 whose rates are entirely unusable must take the FAILURE path
        // (backoff + serve-stale), not wipe the validated table.
        $module = self::fxModule([
            'http_status' => 200,
            'base' => 'EUR',
            'as_of' => '2026-07-15',
            'rates' => ['EUR' => 0, 'NOK' => 'junk'],
        ]);
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES, json_encode([
            'base' => 'EUR',
            'as_of' => '2026-07-01',
            'rates' => ['EUR' => 1.0, 'NOK' => 0.1],
        ]));
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, time() - Twopayment::FX_RATES_TTL - 1);

        TinyAssert::false($module->refreshTwoFxRates());
        TinyAssert::same(100.0, $module->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'NOK'), 'last-known-good table survives an all-junk 200');
        // Failure backoff engaged: no immediate retry.
        TinyAssert::false($module->refreshTwoFxRates());
        TinyAssert::same(1, $module->fxFetchCount);
    }

    private static function testNeverFetchedFailsGateClosedAndDisplaySoft(): void
    {
        self::reset();
        $module = self::fxModule(['http_status' => 500]);
        Configuration::updateValue(
            Twopayment::CONFIG_PLATFORM_MIN_ORDER,
            json_encode(['amount' => 100.0, 'currency' => 'EUR', 'basis' => 'gross'])
        );

        // No table was EVER fetched and the API is down: the cross-currency
        // basket cannot be judged - the platform-minimum gate fails CLOSED.
        TinyAssert::false($module->isTwoMinimumOrderSatisfied(self::cart(2, 1000000.0, 999999.0)));
        TinyAssert::same(1, $module->fxFetchCount, 'the gate miss attempts exactly one on-demand fetch');

        // Display conversion fails SOFT: no hint rather than a wrong figure,
        // and the failure backoff absorbs the extra conversion attempts.
        TinyAssert::same(
            '',
            $module->getTwoMinimumOrderDeclineHint(
                ['decline_reason' => 'ORDER_BELOW_MIN_INVOICE_AMOUNT'],
                self::cart(2, 50.0, 40.0)
            )
        );
        TinyAssert::same(1, $module->fxFetchCount);
    }

    private static function testMissingApiKeyNeverFetches(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', '');
        $module = self::fxModule(self::ratesResponse());

        TinyAssert::same(null, $module->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'NOK'));
        TinyAssert::same(0, $module->fxFetchCount, 'the merchant-key-authenticated endpoint must not be called without a key');
    }

    private static function testGateJudgesCrossCurrencyBasketOnEndpointRate(): void
    {
        self::reset();
        $module = self::fxModule(self::ratesResponse());
        Configuration::updateValue(
            Twopayment::CONFIG_PLATFORM_MIN_ORDER,
            json_encode(['amount' => 100.0, 'currency' => 'EUR', 'basis' => 'gross'])
        );

        // NOK basket vs 100 EUR minimum on the ENDPOINT rate (10 NOK/EUR):
        // 1200 NOK = 120 EUR passes, 900 NOK = 90 EUR fails. The poisoned
        // PS-core rate (999) would have judged both identically.
        TinyAssert::true($module->isTwoMinimumOrderSatisfied(self::cart(2, 1200.0, 1000.0)));
        TinyAssert::false($module->isTwoMinimumOrderSatisfied(self::cart(2, 900.0, 750.0)));
        TinyAssert::same(1, $module->fxFetchCount, 'both judgements share the one cached table');
    }

    private static function surchargeFixtures(): void
    {
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'fixed_and_percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '1.5');
        Configuration::updateValue('PS_TWO_SURCHARGE_FIXED_30', '10');
        Configuration::updateValue('PS_TWO_SURCHARGE_CAP_30', '20');
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1);
    }

    private static function testFixedSurchargeAndCapConvertedIntoQuoteCurrency(): void
    {
        self::reset();
        self::surchargeFixtures();

        $module = self::moduleWithResponses([
            '/refdata/v1/fx-rates' => self::ratesResponse(),
            '/v1/pricing/order/fee' => [
                'http_status' => 200,
                'buyer_fee_share' => '115.00',
                'total_fee_tax_rate' => '0.25',
                'currency' => 'NOK',
            ],
        ]);

        // Shop default is EUR; the buyer checks out in NOK. The configured
        // 10 EUR fixed fee / 20 EUR cap must reach the pricing API as
        // 100 / 200 NOK (endpoint rate 10 NOK/EUR) - NOT as the raw
        // shop-currency figures the old pinning sent.
        $quote = $module->fetchTwoTermFee(30, 1000.0, 'NO', 'NOK');
        TinyAssert::same('115.00', $quote['buyer_fee_share']);

        $pricing = null;
        foreach ($module->requests as $request) {
            if ($request['endpoint'] === '/v1/pricing/order/fee') {
                $pricing = $request['payload'];
            }
        }
        TinyAssert::true(is_array($pricing), 'pricing quote must have been requested');
        TinyAssert::same(100.0, $pricing['buyer_fee_share']['surcharge']);
        TinyAssert::same(200.0, $pricing['buyer_fee_share']['cap']);
        TinyAssert::same(1.5, $pricing['buyer_fee_share']['percentage'], 'percentage is currency-agnostic and passes through');
        TinyAssert::same('NOK', $pricing['currency']);
    }

    private static function testUnconvertibleFixedSurchargeOmitsQuote(): void
    {
        self::reset();
        self::surchargeFixtures();
        self::tableWithoutUsd();

        $module = self::moduleWithResponses([
            '/v1/pricing/order/fee' => [
                'http_status' => 200,
                'buyer_fee_share' => '12.00',
                'currency' => 'USD',
            ],
        ]);

        // The quote is omitted rather than quoting 10 "USD" that are really
        // EUR - the wrong-currency figure the old pinning produced.
        TinyAssert::same(null, $module->fetchTwoTermFee(30, 1000.0, 'US', 'USD'));
        foreach ($module->requests as $request) {
            TinyAssert::notSame('/v1/pricing/order/fee', $request['endpoint'], 'no pricing call may carry an unconverted fixed fee');
        }

        // TWO-25269: it is no longer SILENT. The previous contract logged
        // nothing at all here, which is what let the undercharge through
        // unnoticed on live stores.
        TinyAssert::true(
            self::hasLog('no FX rate EUR->USD', 3),
            'an unquotable surcharge must log at error level naming the currency pair'
        );
        TinyAssert::true(self::hasLog('term 30 days', 3), 'the failure log must name the term');
    }

    /* ------------------------------------------------------------------ *
     *  TWO-25269 - the fail-closed reversal                               *
     *                                                                     *
     *  Before this ticket PrestaShop offered Two normally when the         *
     *  surcharge could not be denominated in the cart currency, the fee    *
     *  quote came back null, the hidden surcharge cart line was REMOVED    *
     *  as though the buyer had deselected it, and the order was created    *
     *  with ZERO surcharge and nothing logged. A silent undercharge.       *
     * ------------------------------------------------------------------ */

    /** Fresh table (no refetch due) that simply lacks USD. */
    private static function tableWithoutUsd(): void
    {
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES, json_encode([
            'base' => 'EUR',
            'as_of' => '2026-07-14',
            'rates' => ['EUR' => 1.0, 'NOK' => 0.1, 'GBP' => 1.25, 'IDR' => 0.00006],
        ]));
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, time());
    }

    private static function hasLog(string $needle, ?int $severity = null): bool
    {
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], $needle) === false) {
                continue;
            }
            if ($severity !== null && $entry['severity'] !== $severity) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * A module whose hookPaymentOptions is reachable: a company-bearing
     * billing address, the cart currency enabled for the module, and a
     * sentinel payment option so a returned option is countable.
     */
    private static function gateModule(int $idCurrency): object
    {
        StubStore::$countries[826] = 'GB';
        StubStore::$addresses[904] = [
            'id_country' => 826,
            'company' => 'Example Trading Ltd',
            'vat_number' => 'GB123456789',
            'loaded' => true,
        ];
        StubStore::$moduleCurrencies['twopayment'] = [['id_currency' => $idCurrency]];

        $module = new class extends TwopaymentTestHarness {
            protected function getTwoPaymentOption()
            {
                return (object) ['method' => 'two'];
            }
        };
        $module->active = true;

        $cart = new Cart(4200 + $idCurrency);
        $cart->id_address_invoice = 904;
        $cart->id_currency = $idCurrency;
        $module->context->cart = $cart;

        return $module;
    }

    /**
     * Case 1 of 3: NO RATE RESOLVABLE -> fail closed.
     *
     * The payment option is WITHHELD, reusing the same mechanism the
     * minimum-order gate already uses (hookPaymentOptions returns []) rather
     * than erroring the checkout.
     */
    private static function testNoRateForCartCurrencyWithholdsPaymentOption(): void
    {
        self::reset();
        self::surchargeFixtures();
        // Shop default stays EUR; the cart is in USD, which the table lacks.
        StubStore::$currencies[4] = ['iso_code' => 'USD', 'conversion_rate' => 999.0, 'loaded' => true];
        self::tableWithoutUsd();

        $module = self::gateModule(4);

        TinyAssert::same(0, count($module->hookPaymentOptions([])), 'an unquotable surcharge must withhold the payment option');
        TinyAssert::true(
            self::hasLog('Payment option hidden for cart', 3),
            'withholding the option is invisible to the merchant unless logged'
        );
        TinyAssert::true(self::hasLog('cannot be quoted in USD', 3));

        // Same store, cart in the shop's own currency: nothing to convert, so
        // the gate must not fire.
        self::reset();
        self::surchargeFixtures();
        self::tableWithoutUsd();
        $sameCurrency = self::gateModule(1);
        TinyAssert::same(1, count($sameCurrency->hookPaymentOptions([])), 'a same-currency cart needs no conversion and must be offered');
    }

    /**
     * The gate is TERM-INDEPENDENT: it must not fire merely because the
     * surcharge grid is percentage-only, since percentages carry no currency.
     */
    private static function testPercentageOnlySurchargeNeverTripsTheGate(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '1.5');
        Configuration::updateValue('PS_TWO_SURCHARGE_FIXED_30', '0');
        Configuration::updateValue('PS_TWO_SURCHARGE_CAP_30', '0');
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1);
        StubStore::$currencies[4] = ['iso_code' => 'USD', 'conversion_rate' => 999.0, 'loaded' => true];
        self::tableWithoutUsd();

        $module = self::gateModule(4);

        TinyAssert::same(
            1,
            count($module->hookPaymentOptions([])),
            'a percentage-only surcharge is currency-agnostic and must never withhold the option'
        );
    }

    /**
     * Case 2 of 3: a CONFIGURED CAP whose converted value rounds to 0.00 ->
     * fail closed. A zero cap is indistinguishable downstream from NO cap, so
     * passing it through sends an UNCAPPED percentage: an OVERCHARGE.
     * PrestaShop had no guard for this at all.
     */
    private static function testCapRoundingToZeroWithholdsPaymentOption(): void
    {
        self::reset();
        // Shop configured in a weak currency: a 20-unit cap is worth
        // 0.0012 EUR, which rounds to nothing at all.
        StubStore::$currencies[4] = ['iso_code' => 'IDR', 'conversion_rate' => 999.0, 'loaded' => true];
        Configuration::updateValue('PS_CURRENCY_DEFAULT', 4);
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'fixed_and_percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '1.5');
        // Healthy fixed amount - only the cap is the problem.
        Configuration::updateValue('PS_TWO_SURCHARGE_FIXED_30', '100000');
        Configuration::updateValue('PS_TWO_SURCHARGE_CAP_30', '20');
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1);
        self::tableWithoutUsd();

        // Cart in EUR, shop default IDR.
        $module = self::gateModule(1);

        TinyAssert::same(0, count($module->hookPaymentOptions([])), 'a cap that rounds to zero would send an uncapped percentage - withhold');
        TinyAssert::true(
            self::hasLog('rounds to 0.00 EUR', 3),
            'the zero-cap overcharge guard must log at error level'
        );
        TinyAssert::true(self::hasLog('uncapped percentage', 3), 'the log must say why a zero cap is dangerous');

        // And no pricing call may carry the zero cap.
        $quoting = self::moduleWithResponses([
            '/v1/pricing/order/fee' => ['http_status' => 200, 'buyer_fee_share' => '5.00', 'currency' => 'EUR'],
        ]);
        TinyAssert::same(null, $quoting->fetchTwoTermFee(30, 1000.0, 'NL', 'EUR'));
        foreach ($quoting->requests as $request) {
            TinyAssert::notSame('/v1/pricing/order/fee', $request['endpoint'], 'no pricing call may carry a zero cap');
        }
    }

    /**
     * ⚠ THE CAP IS OPTIONAL. "No cap defined" is a legitimate configuration
     * meaning an UNCAPPED percentage surcharge, and it must keep charging
     * normally with the option still offered. An absent cap is never a
     * failure - only a configured one that converts to nothing.
     */
    private static function testAbsentCapStillChargesAndOffersTheOption(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'fixed_and_percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '1.5');
        Configuration::updateValue('PS_TWO_SURCHARGE_FIXED_30', '10');
        // No cap configured at all.
        Configuration::updateValue('PS_TWO_SURCHARGE_CAP_30', '0');
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1);
        StubStore::$currencies[2] = ['iso_code' => 'NOK', 'conversion_rate' => 999.0, 'loaded' => true];
        self::tableWithoutUsd();

        // Shop default EUR, cart NOK - a real cross-currency conversion, just
        // with no cap to convert.
        $module = self::gateModule(2);
        TinyAssert::same(1, count($module->hookPaymentOptions([])), 'an absent cap must never withhold the payment option');

        // And the surcharge is genuinely charged, uncapped: the fixed 10 EUR
        // reaches the API as 100 NOK and no `cap` member is sent.
        $quoting = self::moduleWithResponses([
            '/v1/pricing/order/fee' => ['http_status' => 200, 'buyer_fee_share' => '115.00', 'currency' => 'NOK'],
        ]);
        $quote = $quoting->fetchTwoTermFee(30, 1000.0, 'NO', 'NOK');
        TinyAssert::same('115.00', $quote['buyer_fee_share'], 'an uncapped percentage surcharge must still be charged');

        $pricing = null;
        foreach ($quoting->requests as $request) {
            if ($request['endpoint'] === '/v1/pricing/order/fee') {
                $pricing = $request['payload'];
            }
        }
        TinyAssert::true(is_array($pricing), 'the pricing quote must have been requested');
        TinyAssert::same(100.0, $pricing['buyer_fee_share']['surcharge']);
        TinyAssert::same(1.5, $pricing['buyer_fee_share']['percentage']);
        TinyAssert::false(
            array_key_exists('cap', $pricing['buyer_fee_share']),
            'no cap was configured, so none may be invented - the percentage is uncapped by design'
        );
    }

    /**
     * Case 3 of 3: a FIXED amount whose converted value rounds to 0.00 is NOT
     * a failure. It is a legitimately tiny configured amount, genuinely
     * negligible in a stronger currency, and 0.00 is the arithmetically
     * correct answer. Proceed with 0.00, log at INFO.
     */
    private static function testFixedSurchargeRoundingToZeroProceedsWithInfoLog(): void
    {
        self::reset();
        StubStore::$currencies[4] = ['iso_code' => 'IDR', 'conversion_rate' => 999.0, 'loaded' => true];
        Configuration::updateValue('PS_CURRENCY_DEFAULT', 4);
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'fixed_and_percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '1.5');
        // 20 IDR is worth 0.0012 EUR: negligible, but correct.
        Configuration::updateValue('PS_TWO_SURCHARGE_FIXED_30', '20');
        // Healthy cap - only the fixed amount rounds away.
        Configuration::updateValue('PS_TWO_SURCHARGE_CAP_30', '500000');
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1);
        self::tableWithoutUsd();

        $module = self::gateModule(1);
        TinyAssert::same(
            1,
            count($module->hookPaymentOptions([])),
            'a fixed amount that rounds to zero is arithmetically correct, not a failure - keep offering Two'
        );
        TinyAssert::true(
            self::hasLog('quoting 0.00, the surcharge is negligible', 1),
            'the negligible-fixed case is info, not error'
        );
        TinyAssert::false(
            self::hasLog('failing closed', 3),
            'a fixed amount rounding to zero must not be logged as a fail-closed event'
        );

        // The quote proceeds and carries 0.00 for the fixed member while the
        // percentage and the (healthy) cap are intact.
        $quoting = self::moduleWithResponses([
            '/v1/pricing/order/fee' => ['http_status' => 200, 'buyer_fee_share' => '15.00', 'currency' => 'EUR'],
        ]);
        $quote = $quoting->fetchTwoTermFee(30, 1000.0, 'NL', 'EUR');
        TinyAssert::same('15.00', $quote['buyer_fee_share'], 'the quote must proceed, not be omitted');

        $pricing = null;
        foreach ($quoting->requests as $request) {
            if ($request['endpoint'] === '/v1/pricing/order/fee') {
                $pricing = $request['payload'];
            }
        }
        TinyAssert::true(is_array($pricing), 'the pricing quote must have been requested');
        TinyAssert::same(0.0, $pricing['buyer_fee_share']['surcharge'], 'a negligible fixed amount is quoted as 0.00');
        TinyAssert::same(30.0, $pricing['buyer_fee_share']['cap'], '500000 IDR is a healthy 30.00 EUR cap');
    }

}
