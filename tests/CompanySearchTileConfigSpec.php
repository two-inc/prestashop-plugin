<?php

declare(strict_types=1);

/**
 * Coverage for the company-search location toggle PS_TWO_COMPANY_SEARCH_TILE -
 * TWO-25326 §7.1 (2026-08-03 design ruling).
 *
 * PrestaShop had NO such setting before this ticket - the control was always
 * address-area. So, unlike PS_TWO_ADDRESS_LOOKUP (which had to default an
 * absent key to the pre-toggle always-on behaviour), an absent key here has
 * only one correct reading: address area, the only behaviour that ever
 * existed. These tests pin:
 *
 *  - a fresh install seeds the key to 0 (address area);
 *  - an absent key resolves to '0' everywhere it is read - the value handed
 *    to the checkout JS and the admin switch's rendered position;
 *  - the admin save round-trips both positions;
 *  - the resolved value is the '1'/'0' string the checkout JS compares
 *    against, not a bool or an int, matching every other switch of this
 *    shape in the module (see AddressLookupConfigSpec).
 */
final class CompanySearchTileConfigSpec
{
    public static function runAll(): void
    {
        self::testFreshInstallSeedsAddressArea();
        self::testAbsentKeyResolvesToAddressArea();
        self::testSaveRoundTripsBothPositions();
        self::testResolvedValueIsTheStringTheJsCompares();
        self::testUninstallRemovesTheKey();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
    }

    private static function resolve(TwopaymentTestHarness $module): string
    {
        $method = new ReflectionMethod(Twopayment::class, 'getCompanySearchTileEnabled');

        return $method->invoke($module);
    }

    /** @return array<string,mixed> */
    private static function formValues(TwopaymentTestHarness $module): array
    {
        $method = new ReflectionMethod(Twopayment::class, 'getTwoOtherFormValues');

        return $method->invoke($module);
    }

    private static function save(TwopaymentTestHarness $module, $posted): void
    {
        Tools::setTestValue('PS_TWO_COMPANY_SEARCH_TILE', $posted);
        $method = new ReflectionMethod(Twopayment::class, 'saveTwoOtherFormValues');
        $method->invoke($module);
    }

    private static function testFreshInstallSeedsAddressArea(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $method = new ReflectionMethod(Twopayment::class, 'installTwoSettings');
        TinyAssert::true($method->invoke($module));

        TinyAssert::true(Configuration::hasKey('PS_TWO_COMPANY_SEARCH_TILE'));
        TinyAssert::same(0, Configuration::get('PS_TWO_COMPANY_SEARCH_TILE'));
        TinyAssert::same('0', self::resolve($module));
    }

    private static function testAbsentKeyResolvesToAddressArea(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::false(Configuration::hasKey('PS_TWO_COMPANY_SEARCH_TILE'));
        TinyAssert::same('0', self::resolve($module));
        TinyAssert::same('0', self::formValues($module)['PS_TWO_COMPANY_SEARCH_TILE']);

        // An empty stored value is the same case, not a deliberate tile pick.
        Configuration::updateValue('PS_TWO_COMPANY_SEARCH_TILE', '');
        TinyAssert::same('0', self::resolve($module));
    }

    private static function testSaveRoundTripsBothPositions(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        self::save($module, '1');
        TinyAssert::same(1, Configuration::get('PS_TWO_COMPANY_SEARCH_TILE'));
        TinyAssert::same('1', self::resolve($module));
        TinyAssert::same('1', self::formValues($module)['PS_TWO_COMPANY_SEARCH_TILE']);

        Tools::resetTestValues();
        self::save($module, '0');
        TinyAssert::same(0, Configuration::get('PS_TWO_COMPANY_SEARCH_TILE'));
        TinyAssert::same('0', self::resolve($module));
        TinyAssert::same('0', self::formValues($module)['PS_TWO_COMPANY_SEARCH_TILE']);
    }

    private static function testResolvedValueIsTheStringTheJsCompares(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // views/js/twopayment.js reads `twopayment.company_search_tile === '1'`,
        // so the payload value has to be an exact '1'/'0' string whichever
        // shape the configuration row happens to hold.
        foreach ([1, '1', true] as $enabled) {
            Configuration::updateValue('PS_TWO_COMPANY_SEARCH_TILE', $enabled);
            TinyAssert::same('1', self::resolve($module));
        }

        foreach ([0, '0', false] as $disabled) {
            Configuration::updateValue('PS_TWO_COMPANY_SEARCH_TILE', $disabled);
            TinyAssert::same('0', self::resolve($module));
        }
    }

    private static function testUninstallRemovesTheKey(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        Configuration::updateValue('PS_TWO_COMPANY_SEARCH_TILE', 1);
        TinyAssert::true(Configuration::hasKey('PS_TWO_COMPANY_SEARCH_TILE'));

        $method = new ReflectionMethod(Twopayment::class, 'uninstallTwoSettings');
        TinyAssert::true($method->invoke($module));

        TinyAssert::false(Configuration::hasKey('PS_TWO_COMPANY_SEARCH_TILE'));
    }
}
