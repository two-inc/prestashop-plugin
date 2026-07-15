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
 *    platform-minimum gate fails closed, display conversions fail soft.
 *  - Fixed surcharge / cap re-denomination: fetchTwoTermFee converts the
 *    shop-default-currency figures into the quote currency through the
 *    endpoint rate (pinning removed); an unconvertible figure omits the
 *    quote instead of sending a wrong-currency amount.
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
        self::testNeverFetchedFailsGateClosedAndDisplaySoft();
        self::testMissingApiKeyNeverFetches();
        self::testGateJudgesCrossCurrencyBasketOnEndpointRate();
        self::testFixedSurchargeAndCapConvertedIntoQuoteCurrency();
        self::testUnconvertibleFixedSurchargeOmitsQuote();
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
        // The refetch succeeds but transiently DROPS GBP and moves NOK.
        $module = self::fxModule([
            'http_status' => 200,
            'base' => 'EUR',
            'as_of' => '2026-07-15',
            'rates' => ['EUR' => 1.0, 'NOK' => 0.09],
        ]);
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES, json_encode([
            'base' => 'EUR',
            'as_of' => '2026-07-01',
            'rates' => ['EUR' => 1.0, 'NOK' => 0.1, 'GBP' => 1.25],
        ]));
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, time() - Twopayment::FX_RATES_TTL - 1);

        TinyAssert::true($module->refreshTwoFxRates());
        // Fresh values win; the dropped currency keeps its last-known-good
        // rate instead of failing its gate closed for a whole TTL.
        TinyAssert::same(90.0, $module->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'NOK'));
        TinyAssert::same(12.5, $module->convertTwoAmountBetweenCurrencies(10.0, 'EUR', 'GBP'));
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

        // Fresh table WITHOUT the quote currency: USD cannot be converted
        // and (fresh TTL) no refetch is due.
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES, json_encode([
            'base' => 'EUR',
            'as_of' => '2026-07-14',
            'rates' => ['EUR' => 1.0, 'NOK' => 0.1],
        ]));
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, time());

        $module = self::moduleWithResponses([
            '/v1/pricing/order/fee' => [
                'http_status' => 200,
                'buyer_fee_share' => '12.00',
                'currency' => 'USD',
            ],
        ]);

        // Fail-soft: the quote is omitted entirely (fee line skipped,
        // checkout never blocked) instead of quoting 10 "USD" that are
        // really EUR - the wrong-currency figure the old pinning produced.
        TinyAssert::same(null, $module->fetchTwoTermFee(30, 1000.0, 'US', 'USD'));
        foreach ($module->requests as $request) {
            TinyAssert::notSame('/v1/pricing/order/fee', $request['endpoint'], 'no pricing call may carry an unconverted fixed fee');
        }
    }
}
