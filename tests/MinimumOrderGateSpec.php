<?php

declare(strict_types=1);

/**
 * TWO-24775 - minimum-order-value gate. The platform minimum
 * (min_order_amount/min_order_currency/min_order_basis on GET /v1/merchant)
 * rides the SAME merchant-record fetch/cache as available_terms and
 * due_in_days; the merchant's own optional minimum (admin config, shop
 * default currency) stacks on top. hookPaymentOptions hides the payment
 * option when either bar is unmet; the payment/intent decline paths surface
 * a currency-formatted hint when a rejection is attributable to the minimum.
 *
 * Failure posture parity with magento-plugin's MinimumOrderGate:
 * fail-open on an unresolved minimum, fail-CLOSED on an unconvertible
 * cross-currency basket vs the platform minimum, fail-open on the merchant's
 * own optional bar, fail-soft (no hint) on the decline hint.
 */
final class MinimumOrderGateSpec
{
    public static function runAll(): void
    {
        // Tuple parsing.
        self::testParseAcceptsFullTuple();
        self::testParseRejectsPartialOrMalformedTuples();

        // Shared merchant-record fetch / cache behaviour.
        self::testSharedFetchCachesPlatformMinimum();
        self::testSharedFetchCachesTheNoMinimumOutcome();
        self::testInvalidateClearsPlatformMinimum();

        // Checkout gate.
        self::testGateInclusiveSameCurrency();
        self::testGateComparesOnDeclaredBasis();
        self::testGateConvertsCrossCurrencyBasket();
        self::testGateFailsClosedWithoutFxRate();
        self::testMerchantMinimumStacksOnTop();
        self::testMerchantMinimumMustNotUndercutPlatformFloorAtCheckout();
        self::testMerchantMinimumFailsOpenWhenUnjudgeable();
        self::testMerchantBasisFallsBackToPlatformThenGross();
        self::testGatePassesWhenNoMinimumResolved();

        // Decline hint.
        self::testDeclineHintFromDeclineReason();
        self::testDeclineHintConvertsToCartCurrency();
        self::testDeclineHintStrictlyBelowFallback();
        self::testDeclineHintFailsSoftWhenUnattributable();

        // Admin save validation.
        self::testSaveValidationRejectsNegativeAndBelowFloor();
        self::testSaveValidationAllowsFloorAndAbove();
    }

    private static function freshModule(): TwopaymentTestHarness
    {
        StubStore::reset();
        Tools::resetTestValues();
        PrestaShopLogger::reset();
        // Shop currencies: 1=EUR (default), 2=NOK, 3=GBP. PS core
        // conversion_rate values are deliberately POISONED: since TWO-25105
        // every Two-side conversion must come from the cached
        // /refdata/v1/fx-rates table, never from PS core's own rates.
        StubStore::$currencies = [
            1 => ['iso_code' => 'EUR', 'conversion_rate' => 999.0, 'symbol' => "\u{20AC}"],
            2 => ['iso_code' => 'NOK', 'conversion_rate' => 999.0, 'symbol' => 'kr'],
            3 => ['iso_code' => 'GBP', 'conversion_rate' => 999.0, 'symbol' => "\u{A3}"],
        ];
        Configuration::updateValue('PS_CURRENCY_DEFAULT', 1);
        // Cached FX table (EUR pivot: rates[CCY] = 1 CCY in EUR), matching
        // the historical fixture rates 11.5 NOK/EUR and 0.85 GBP/EUR.
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES, json_encode([
            'base' => 'EUR',
            'as_of' => '2026-07-15',
            'rates' => ['EUR' => 1.0, 'NOK' => 1 / 11.5, 'GBP' => 1 / 0.85],
        ]));
        Configuration::updateValue(Twopayment::CONFIG_FX_RATES_TS, time());
        return new TwopaymentTestHarness();
    }

    private static function cachePlatformMinimum(?array $minimum): void
    {
        Configuration::updateValue(
            Twopayment::CONFIG_PLATFORM_MIN_ORDER,
            $minimum ? json_encode($minimum) : ''
        );
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

    /** Harness whose GET /v1/merchant fetch is stubbed and counted. */
    private static function moduleWithMerchantResponse($response): object
    {
        StubStore::reset();
        Tools::resetTestValues();
        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'm-123');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');
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

    private static function testParseAcceptsFullTuple(): void
    {
        $module = self::freshModule();
        $tuple = $module->parseTwoPlatformMinimumOrder([
            'min_order_amount' => '250.5',
            'min_order_currency' => ' eur ',
            'min_order_basis' => 'net',
        ]);
        TinyAssert::same(['amount' => 250.5, 'currency' => 'EUR', 'basis' => 'net'], $tuple);
    }

    private static function testParseRejectsPartialOrMalformedTuples(): void
    {
        $module = self::freshModule();
        // The API omits all three fields when no minimum is configured; a
        // partial or malformed tuple must resolve to "no minimum" too.
        $cases = [
            [],
            ['min_order_amount' => 100, 'min_order_currency' => 'EUR'], // basis missing
            ['min_order_amount' => 100, 'min_order_basis' => 'gross'], // currency missing
            ['min_order_currency' => 'EUR', 'min_order_basis' => 'gross'], // amount missing
            ['min_order_amount' => 0, 'min_order_currency' => 'EUR', 'min_order_basis' => 'gross'],
            ['min_order_amount' => -5, 'min_order_currency' => 'EUR', 'min_order_basis' => 'gross'],
            ['min_order_amount' => 'abc', 'min_order_currency' => 'EUR', 'min_order_basis' => 'gross'],
            ['min_order_amount' => 100, 'min_order_currency' => '', 'min_order_basis' => 'gross'],
            ['min_order_amount' => 100, 'min_order_currency' => 'EUR', 'min_order_basis' => 'GROSS'],
            ['min_order_amount' => 100, 'min_order_currency' => 'EUR', 'min_order_basis' => 'both'],
        ];
        foreach ($cases as $i => $case) {
            TinyAssert::same(null, $module->parseTwoPlatformMinimumOrder($case), 'case ' . $i . ' should parse to null');
        }
        TinyAssert::same(null, $module->parseTwoPlatformMinimumOrder('not-an-array'));
    }

    private static function testSharedFetchCachesPlatformMinimum(): void
    {
        $module = self::moduleWithMerchantResponse([
            'http_status' => 200,
            'available_terms' => [14, 30],
            'min_order_amount' => 400,
            'min_order_currency' => 'nok',
            'min_order_basis' => 'gross',
        ]);
        StubStore::$currencies = [1 => ['iso_code' => 'EUR', 'conversion_rate' => 1.0]];
        Configuration::updateValue('PS_CURRENCY_DEFAULT', 1);

        $module->getMerchantAvailableTerms(true);
        TinyAssert::same(1, $module->fetchCount);
        TinyAssert::same(
            ['amount' => 400.0, 'currency' => 'NOK', 'basis' => 'gross'],
            $module->getPlatformMinimumOrder()
        );

        // Within TTL the cached tuple is served with no refetch.
        $module->getMerchantAvailableTerms(true);
        TinyAssert::same(1, $module->fetchCount, 'fresh cache must not refetch');
    }

    private static function testSharedFetchCachesTheNoMinimumOutcome(): void
    {
        $module = self::moduleWithMerchantResponse([
            'http_status' => 200,
            'available_terms' => [30],
        ]);
        // A stale tuple from a previous identity/response must be overwritten:
        // an absent tuple IS the answer ("no minimum"), not a fetch failure.
        Configuration::updateValue(
            Twopayment::CONFIG_PLATFORM_MIN_ORDER,
            json_encode(['amount' => 100.0, 'currency' => 'EUR', 'basis' => 'gross'])
        );

        $module->getMerchantAvailableTerms(true);
        TinyAssert::same(1, $module->fetchCount);
        TinyAssert::same(null, $module->getPlatformMinimumOrder());
        TinyAssert::same('', Configuration::get(Twopayment::CONFIG_PLATFORM_MIN_ORDER));

        // The no-minimum outcome is cached: no refetch per checkout render.
        $module->getMerchantAvailableTerms(true);
        TinyAssert::same(1, $module->fetchCount, 'no-minimum outcome must be cached too');
    }

    private static function testInvalidateClearsPlatformMinimum(): void
    {
        $module = self::freshModule();
        self::cachePlatformMinimum(['amount' => 100.0, 'currency' => 'EUR', 'basis' => 'gross']);
        $module->invalidateMerchantAvailableTerms();
        TinyAssert::same(null, $module->getPlatformMinimumOrder(), 'identity change must drop the old merchant\'s minimum');
    }

    private static function testGateInclusiveSameCurrency(): void
    {
        $module = self::freshModule();
        self::cachePlatformMinimum(['amount' => 100.0, 'currency' => 'EUR', 'basis' => 'gross']);

        TinyAssert::false($module->isTwoMinimumOrderSatisfied(self::cart(1, 99.99, 80.0)));
        TinyAssert::true($module->isTwoMinimumOrderSatisfied(self::cart(1, 100.0, 80.0)), 'an exactly-minimum basket passes');
        TinyAssert::true($module->isTwoMinimumOrderSatisfied(self::cart(1, 150.0, 120.0)));
    }

    private static function testGateComparesOnDeclaredBasis(): void
    {
        $module = self::freshModule();
        self::cachePlatformMinimum(['amount' => 100.0, 'currency' => 'EUR', 'basis' => 'net']);

        // Gross clears the bar but the declared basis is net - must judge net.
        TinyAssert::false($module->isTwoMinimumOrderSatisfied(self::cart(1, 110.0, 99.0)));
        TinyAssert::true($module->isTwoMinimumOrderSatisfied(self::cart(1, 110.0, 100.0)));
    }

    private static function testGateConvertsCrossCurrencyBasket(): void
    {
        $module = self::freshModule();
        self::cachePlatformMinimum(['amount' => 100.0, 'currency' => 'EUR', 'basis' => 'gross']);

        // NOK basket vs EUR minimum: 1200 NOK / 11.5 = 104.35 EUR -> passes;
        // 1100 NOK / 11.5 = 95.65 EUR -> below.
        TinyAssert::true($module->isTwoMinimumOrderSatisfied(self::cart(2, 1200.0, 1000.0)));
        TinyAssert::false($module->isTwoMinimumOrderSatisfied(self::cart(2, 1100.0, 900.0)));
    }

    private static function testGateFailsClosedWithoutFxRate(): void
    {
        $module = self::freshModule();
        // USD carries no rate in the cached FX table (and no API key is
        // configured, so no on-demand refetch happens) - the basket cannot be
        // proven to satisfy the funding partner's minimum: fail closed.
        self::cachePlatformMinimum(['amount' => 100.0, 'currency' => 'USD', 'basis' => 'gross']);
        TinyAssert::false($module->isTwoMinimumOrderSatisfied(self::cart(1, 1000000.0, 999999.0)));
    }

    private static function testMerchantMinimumStacksOnTop(): void
    {
        $module = self::freshModule();
        self::cachePlatformMinimum(['amount' => 100.0, 'currency' => 'EUR', 'basis' => 'gross']);
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER, '200');
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER_BASIS, 'gross');

        // Above the platform floor but below the merchant's own bar.
        TinyAssert::false($module->isTwoMinimumOrderSatisfied(self::cart(1, 150.0, 120.0)));
        TinyAssert::true($module->isTwoMinimumOrderSatisfied(self::cart(1, 200.0, 160.0)));
    }

    private static function testMerchantMinimumMustNotUndercutPlatformFloorAtCheckout(): void
    {
        $module = self::freshModule();
        self::cachePlatformMinimum(['amount' => 100.0, 'currency' => 'EUR', 'basis' => 'gross']);
        // A stale/undercutting merchant value (e.g. saved before the platform
        // floor rose) must not weaken the platform bar - both are enforced.
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER, '50');
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER_BASIS, 'gross');

        TinyAssert::false($module->isTwoMinimumOrderSatisfied(self::cart(1, 80.0, 64.0)), 'platform floor still applies');
        TinyAssert::true($module->isTwoMinimumOrderSatisfied(self::cart(1, 100.0, 80.0)));
    }

    private static function testMerchantMinimumFailsOpenWhenUnjudgeable(): void
    {
        $module = self::freshModule();
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER, '200');
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER_BASIS, 'gross');
        // Cart currency id 99 is not installed: the merchant's own optional
        // bar cannot be judged - fail open (no platform minimum here).
        TinyAssert::true($module->isTwoMinimumOrderSatisfied(self::cart(99, 10.0, 8.0)));
    }

    private static function testMerchantBasisFallsBackToPlatformThenGross(): void
    {
        $module = self::freshModule();
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER, '100');
        // No basis saved, no platform minimum -> gross.
        $minimum = $module->getMerchantMinimumOrder();
        TinyAssert::same('gross', $minimum['basis']);
        TinyAssert::same('EUR', $minimum['currency'], 'merchant minimum is shop-default-currency scoped');

        // No basis saved, platform minimum declares net -> net.
        self::cachePlatformMinimum(['amount' => 50.0, 'currency' => 'EUR', 'basis' => 'net']);
        $minimum = $module->getMerchantMinimumOrder();
        TinyAssert::same('net', $minimum['basis']);
    }

    private static function testGatePassesWhenNoMinimumResolved(): void
    {
        $module = self::freshModule();
        // Cold cache / API blip / none configured: fail open - the server
        // still enforces the platform minimum at order creation.
        TinyAssert::true($module->isTwoMinimumOrderSatisfied(self::cart(1, 0.01, 0.01)));
    }

    private static function testDeclineHintFromDeclineReason(): void
    {
        $module = self::freshModule();
        self::cachePlatformMinimum(['amount' => 100.0, 'currency' => 'EUR', 'basis' => 'gross']);

        $hint = $module->getTwoMinimumOrderDeclineHint(
            ['decline_reason' => 'ORDER_BELOW_MIN_INVOICE_AMOUNT'],
            self::cart(1, 50.0, 40.0)
        );
        TinyAssert::same("Minimum order value is \u{20AC}100.00 including tax.", $hint);

        // Nested under data (order-intent response shape).
        $hint = $module->getTwoMinimumOrderDeclineHint(
            ['data' => ['decline_reason' => 'ORDER_BELOW_MIN_INVOICE_AMOUNT']],
            self::cart(1, 50.0, 40.0)
        );
        TinyAssert::same("Minimum order value is \u{20AC}100.00 including tax.", $hint);
    }

    private static function testDeclineHintConvertsToCartCurrency(): void
    {
        $module = self::freshModule();
        self::cachePlatformMinimum(['amount' => 100.0, 'currency' => 'EUR', 'basis' => 'net']);

        // Buyer shops in NOK: the hint must speak the buyer's currency.
        $hint = $module->getTwoMinimumOrderDeclineHint(
            ['decline_reason' => 'ORDER_BELOW_MIN_INVOICE_AMOUNT'],
            self::cart(2, 500.0, 400.0)
        );
        TinyAssert::same('Minimum order value is kr1,150.00 excluding tax.', $hint);
    }

    private static function testDeclineHintStrictlyBelowFallback(): void
    {
        $module = self::freshModule();
        self::cachePlatformMinimum(['amount' => 100.0, 'currency' => 'EUR', 'basis' => 'gross']);

        // No machine-readable reason, but the basket is strictly below.
        $hint = $module->getTwoMinimumOrderDeclineHint([], self::cart(1, 99.0, 80.0));
        TinyAssert::same("Minimum order value is \u{20AC}100.00 including tax.", $hint);

        // At the minimum: not attributable, no hint.
        TinyAssert::same('', $module->getTwoMinimumOrderDeclineHint([], self::cart(1, 100.0, 80.0)));
    }

    private static function testDeclineHintFailsSoftWhenUnattributable(): void
    {
        $module = self::freshModule();
        // No platform minimum resolved: never a hint.
        TinyAssert::same(
            '',
            $module->getTwoMinimumOrderDeclineHint(
                ['decline_reason' => 'ORDER_BELOW_MIN_INVOICE_AMOUNT'],
                self::cart(1, 50.0, 40.0)
            )
        );

        // Minimum in a currency the shop cannot convert to the cart currency:
        // fail-soft (no hint), never a wrong figure.
        self::cachePlatformMinimum(['amount' => 100.0, 'currency' => 'USD', 'basis' => 'gross']);
        TinyAssert::same(
            '',
            $module->getTwoMinimumOrderDeclineHint(
                ['decline_reason' => 'ORDER_BELOW_MIN_INVOICE_AMOUNT'],
                self::cart(1, 50.0, 40.0)
            )
        );
    }

    /** Harness exposing the protected admin-save validation. */
    private static function validatingModule(): object
    {
        return new class () extends TwopaymentTestHarness {
            public function runPaymentSettingsValidation(): array
            {
                $this->errors = [];
                $this->validTwoPaymentSettingsFormValues();
                return $this->errors;
            }
        };
    }

    private static function minimumOrderErrors(array $errors): array
    {
        return array_values(array_filter($errors, static function ($error) {
            return strpos($error, 'Minimum Order Value') !== false;
        }));
    }

    private static function testSaveValidationRejectsNegativeAndBelowFloor(): void
    {
        self::freshModule();
        $module = self::validatingModule();
        // Keep the unrelated "at least one term" validation quiet.
        Tools::setTestValue('PS_TWO_PAYMENT_TERMS_30', '1');

        Tools::setTestValue('PS_TWO_MERCHANT_MIN_ORDER', '-5');
        $errors = self::minimumOrderErrors($module->runPaymentSettingsValidation());
        TinyAssert::count(1, $errors, 'negative value must be rejected');

        Tools::setTestValue('PS_TWO_MERCHANT_MIN_ORDER', 'abc');
        $errors = self::minimumOrderErrors($module->runPaymentSettingsValidation());
        TinyAssert::count(1, $errors, 'non-numeric value must be rejected');

        // Platform floor 1150 NOK = 100 EUR: a merchant value of 99 EUR would
        // lower the effective minimum below the platform floor - rejected.
        self::cachePlatformMinimum(['amount' => 1150.0, 'currency' => 'NOK', 'basis' => 'gross']);
        Tools::setTestValue('PS_TWO_MERCHANT_MIN_ORDER', '99');
        $errors = self::minimumOrderErrors($module->runPaymentSettingsValidation());
        TinyAssert::count(1, $errors, 'value below the platform floor must be rejected');
        TinyAssert::true(strpos($errors[0], '100 EUR (1150 NOK)') !== false, 'floor shown in shop currency with native figure: ' . $errors[0]);

        Tools::setTestValue('PS_TWO_MERCHANT_MIN_ORDER_BASIS', 'both');
        Tools::setTestValue('PS_TWO_MERCHANT_MIN_ORDER', '');
        $errors = self::minimumOrderErrors($module->runPaymentSettingsValidation());
        TinyAssert::count(1, $errors, 'unknown basis must be rejected');
    }

    private static function testSaveValidationAllowsFloorAndAbove(): void
    {
        self::freshModule();
        $module = self::validatingModule();
        Tools::setTestValue('PS_TWO_PAYMENT_TERMS_30', '1');
        self::cachePlatformMinimum(['amount' => 100.0, 'currency' => 'EUR', 'basis' => 'gross']);

        Tools::setTestValue('PS_TWO_MERCHANT_MIN_ORDER_BASIS', 'net');
        foreach (['', '100', '250,50'] as $value) {
            Tools::setTestValue('PS_TWO_MERCHANT_MIN_ORDER', $value);
            $errors = self::minimumOrderErrors($module->runPaymentSettingsValidation());
            TinyAssert::count(0, $errors, 'value "' . $value . '" must be accepted');
        }

        // A floor that cannot be expressed in the shop currency (no rate)
        // skips the numeric check - both minima are enforced independently
        // at checkout instead.
        self::cachePlatformMinimum(['amount' => 100.0, 'currency' => 'USD', 'basis' => 'gross']);
        Tools::setTestValue('PS_TWO_MERCHANT_MIN_ORDER', '1');
        $errors = self::minimumOrderErrors($module->runPaymentSettingsValidation());
        TinyAssert::count(0, $errors, 'unconvertible floor must not block the save');
    }
}
