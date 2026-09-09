<?php

declare(strict_types=1);

// TWO-25503 - hookPaymentOptions must withhold Two, not fall back to the
// hardcoded PAYMENT_TERMS_OPTIONS preset, when terms are unresolved.
final class TermDiscoverySpec
{
    public static function runAll(): void
    {
        self::testColdCacheWithholdsThePaymentOption();
        self::testResolvedCacheKeepsThePaymentOption();
        self::testColdCacheIsLogged();
        self::testWithholdReasonIsLoggedOncePerRequestNotPerCall();
        self::testInvalidatedCacheWithholdsUntilReResolved();
        self::testUnresolvedRecordRefetchDecidesTheBuyerOutcome();
    }

    private static function module(): TwopaymentTestHarness
    {
        StubStore::reset();

        return new TwopaymentTestHarness();
    }

    /**
     * Payment-options harness. The API-key gate is fed its own verdict field, so
     * it stays in the assertion path ahead of the term gate.
     */
    private static function moduleWithMerchantRecordResponse(array $response, string $verdict = Twopayment::API_KEY_STATUS_OK): object
    {
        StubStore::reset();
        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'mid');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key');

        $module = new class ($response) extends TwopaymentTestHarness {
            public int $calls = 0;
            private array $response;

            public function __construct(array $response)
            {
                parent::__construct();
                $this->response = $response;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                ++$this->calls;
                return $this->response;
            }
        };
        $module->primeTwoApiKeyStatus($verdict, 200);

        return $module;
    }

    /**
     * ABN-495. Given a record dropped during an outage, When the buyer reaches
     * the payment step after it clears, Then the render itself refetches.
     */
    private static function testUnresolvedRecordRefetchDecidesTheBuyerOutcome(): void
    {
        $ok = ['http_status' => 200, 'available_terms' => [30]];
        $down = ['http_status' => 0];

        $unreachable = Twopayment::API_KEY_STATUS_UNREACHABLE;
        $verified = Twopayment::API_KEY_STATUS_OK;

        $cases = [
            ['',       $ok,   $verified,    1, 1, 'a dropped record is refetched on the payment render and the method returns'],
            ['',       $down, $verified,    1, 0, 'a record that still cannot be fetched keeps the method withheld'],
            ['[]',     $ok,   $verified,    0, 0, 'an explicitly empty offer set withholds without a refetch'],
            ['[30]',   $ok,   $verified,    0, 1, 'a resolved record offers the method with no wire call'],
            ['',       $ok,   $unreachable, 0, 0, 'an unverified key withholds before the term gate spends a request'],
        ];

        foreach ($cases as [$cached, $response, $verdict, $expectedCalls, $expectedOptions, $description]) {
            $module = self::moduleWithMerchantRecordResponse($response, $verdict);
            Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS, $cached);
            Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS, 0);
            self::offerableCart($module);

            $options = $module->hookPaymentOptions([]);

            TinyAssert::same($expectedCalls, $module->calls, 'wire calls: ' . $description);
            TinyAssert::same($expectedOptions, count($options), 'payment options: ' . $description);
        }
    }

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

    private static function testColdCacheWithholdsThePaymentOption(): void
    {
        $module = self::module();
        $module->primeTwoAvailableTerms([]);
        self::offerableCart($module);

        TinyAssert::same(
            0,
            count($module->hookPaymentOptions([])),
            'an unresolved backend term set must withhold the payment option'
        );
    }

    private static function testResolvedCacheKeepsThePaymentOption(): void
    {
        $module = self::module();
        $module->primeTwoAvailableTerms([30]);
        self::offerableCart($module);

        TinyAssert::true(
            count($module->hookPaymentOptions([])) > 0,
            'a resolved backend term set must offer the payment option'
        );
    }

    private static function testColdCacheIsLogged(): void
    {
        $module = self::module();
        $module->primeTwoAvailableTerms([]);
        self::offerableCart($module);
        PrestaShopLogger::reset();

        $module->hookPaymentOptions([]);

        $logged = '';
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'offerable payment terms not resolved') !== false) {
                $logged = $entry['message'];
            }
        }
        TinyAssert::true($logged !== '', 'hiding the payment option must say why in the log');
    }

    // PrestaShop calls hookPaymentOptions several times per render.
    private static function testWithholdReasonIsLoggedOncePerRequestNotPerCall(): void
    {
        $module = self::module();
        $module->primeTwoAvailableTerms([]);
        self::offerableCart($module);
        PrestaShopLogger::reset();

        $module->hookPaymentOptions([]);
        $module->hookPaymentOptions([]);
        $module->hookPaymentOptions([]);

        $lines = 0;
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'offerable payment terms not resolved') !== false) {
                ++$lines;
            }
        }
        TinyAssert::same(1, $lines, 'the withhold reason must be logged once per request');
    }

    // A merchant-identity change invalidates the cache; must stay withheld
    // until re-resolved, never fall back to the old merchant's data.
    private static function testInvalidatedCacheWithholdsUntilReResolved(): void
    {
        $module = self::module();
        $module->primeTwoAvailableTerms([30]);
        self::offerableCart($module);
        TinyAssert::true(count($module->hookPaymentOptions([])) > 0, 'sanity: resolved cache offers the option');

        $module->invalidateMerchantAvailableTerms();

        TinyAssert::same(
            0,
            count($module->hookPaymentOptions([])),
            'an invalidated term cache must withhold the payment option until re-resolved'
        );
    }
}
