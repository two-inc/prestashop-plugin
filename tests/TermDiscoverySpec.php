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
    }

    private static function module(): TwopaymentTestHarness
    {
        StubStore::reset();

        return new TwopaymentTestHarness();
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
