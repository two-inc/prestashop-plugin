<?php

declare(strict_types=1);

/**
 * Coverage for the address-lookup toggle PS_TWO_ADDRESS_LOOKUP - TWO-25203.
 *
 * The company address lookup on the checkout address step (picking a company
 * overwrites the address fields and the DNI / VAT number fields) used to be
 * ungated. The behaviour is unchanged and is deliberate - a re-search must
 * overwrite - so the only requirement of the new key is that it can never
 * silently turn an existing shop's lookup off. These tests pin:
 *
 *  - a fresh install seeds the key to 1;
 *  - the 2.6.6 upgrade seeds an absent key to 1 (the pre-toggle behaviour was
 *    always-on), and leaves a merchant's deliberate 0 alone even if it runs
 *    again;
 *  - an absent key resolves to enabled everywhere it is read - the value
 *    handed to the checkout JS and the admin switch's rendered position - so
 *    an install whose upgrade has not run yet still fills;
 *  - the admin save round-trips both positions, and the value the checkout JS
 *    receives is the '1'/'0' string it compares against, not a bool or an int.
 */
final class AddressLookupConfigSpec
{
    public static function runAll(): void
    {
        self::testFreshInstallSeedsEnabled();
        self::testUpgrade266SeedsAbsentKeyToEnabled();
        self::testUpgrade266LeavesDeliberateOptOutAlone();
        self::testAbsentKeyResolvesToEnabled();
        self::testSaveRoundTripsBothPositions();
        self::testResolvedValueIsTheStringTheJsCompares();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
    }

    private static function resolve(TwopaymentTestHarness $module): string
    {
        $method = new ReflectionMethod(Twopayment::class, 'getAddressLookupEnabled');

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
        Tools::setTestValue('PS_TWO_ADDRESS_LOOKUP', $posted);
        $method = new ReflectionMethod(Twopayment::class, 'saveTwoOtherFormValues');
        $method->invoke($module);
    }

    private static function upgrade(TwopaymentTestHarness $module): void
    {
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.6.6.php';
        TinyAssert::true(upgrade_module_2_6_6($module));
    }

    private static function testFreshInstallSeedsEnabled(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $method = new ReflectionMethod(Twopayment::class, 'installTwoSettings');
        TinyAssert::true($method->invoke($module));

        TinyAssert::true(Configuration::hasKey('PS_TWO_ADDRESS_LOOKUP'));
        TinyAssert::same(1, Configuration::get('PS_TWO_ADDRESS_LOOKUP'));
        TinyAssert::same('1', self::resolve($module));
    }

    private static function testUpgrade266SeedsAbsentKeyToEnabled(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // An install from before the toggle existed: no row, always-on behaviour.
        TinyAssert::false(Configuration::hasKey('PS_TWO_ADDRESS_LOOKUP'));

        self::upgrade($module);

        TinyAssert::same(1, Configuration::get('PS_TWO_ADDRESS_LOOKUP'));
        TinyAssert::same('1', self::resolve($module));
        // The seed is a behavioural decision worth a trail in the shop log.
        TinyAssert::count(1, PrestaShopLogger::$logs);
    }

    private static function testUpgrade266LeavesDeliberateOptOutAlone(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // Merchant has already switched the lookup off. A re-run of the
        // upgrade must not resurrect it.
        Configuration::updateValue('PS_TWO_ADDRESS_LOOKUP', 0);

        self::upgrade($module);

        TinyAssert::same(0, Configuration::get('PS_TWO_ADDRESS_LOOKUP'));
        TinyAssert::same('0', self::resolve($module));
        TinyAssert::count(0, PrestaShopLogger::$logs);
    }

    private static function testAbsentKeyResolvesToEnabled(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // Upgrade script not run yet - the shop is still behaving as always-on,
        // and both readers must agree with that rather than fail closed.
        TinyAssert::same('1', self::resolve($module));
        TinyAssert::same('1', self::formValues($module)['PS_TWO_ADDRESS_LOOKUP']);

        // An empty stored value is the same case, not a deliberate "off".
        Configuration::updateValue('PS_TWO_ADDRESS_LOOKUP', '');
        TinyAssert::same('1', self::resolve($module));
    }

    private static function testSaveRoundTripsBothPositions(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // Switches post strings.
        self::save($module, '0');
        TinyAssert::same(0, Configuration::get('PS_TWO_ADDRESS_LOOKUP'));
        TinyAssert::same('0', self::resolve($module));
        TinyAssert::same('0', self::formValues($module)['PS_TWO_ADDRESS_LOOKUP']);

        Tools::resetTestValues();
        self::save($module, '1');
        TinyAssert::same(1, Configuration::get('PS_TWO_ADDRESS_LOOKUP'));
        TinyAssert::same('1', self::resolve($module));
        TinyAssert::same('1', self::formValues($module)['PS_TWO_ADDRESS_LOOKUP']);
    }

    private static function testResolvedValueIsTheStringTheJsCompares(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // views/js/twopayment.js reads `twopayment.address_lookup !== '0'`, so
        // the payload value has to be a '1'/'0' string whichever shape the
        // configuration row happens to hold (int from install, string from a
        // database read).
        foreach ([1, '1', true] as $enabled) {
            Configuration::updateValue('PS_TWO_ADDRESS_LOOKUP', $enabled);
            TinyAssert::same('1', self::resolve($module));
        }

        // Not `false`: that is what a real Configuration::get() returns for an
        // absent row, so it is the absent case (enabled), not a stored "off".
        foreach ([0, '0'] as $disabled) {
            Configuration::updateValue('PS_TWO_ADDRESS_LOOKUP', $disabled);
            TinyAssert::same('0', self::resolve($module));
        }
    }
}
