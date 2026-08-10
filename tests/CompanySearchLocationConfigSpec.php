<?php

declare(strict_types=1);

/**
 * Coverage for the company-search location behaviour of PS_TWO_COMPANY_SEARCH_LOCATION
 * - TWO-25326 §7.1 (2026-08-03 design ruling).
 *
 * No new setting was added. Doug's explicit correction: reuse the EXISTING
 * "Enable company search in address entry" switch (label/desc rebadged,
 * same Configuration key) to decide WHERE the one shared company-search
 * control renders, rather than whether it exists at all:
 *
 *  - Yes (default, install value 1): address area, unchanged from before
 *    this ticket.
 *  - No: the SAME control instead renders in the payment tile.
 *
 * This is a deliberate BEHAVIOUR CHANGE for shops that already have the
 * switch set to "No": before this ticket "No" turned company search off
 * entirely; it now relocates it to the payment tile instead. These tests
 * pin the resolution/round-trip logic only - the actual relocation is
 * covered by tests/e2e/tests/company-search-tile-location.spec.ts, and the
 * "the address-area field must stay visible, never hidden" requirement (a
 * confirmed regression on woocommerce-plugin) is also an e2e-only check,
 * since it is about live DOM behaviour this offline suite cannot see.
 */
final class CompanySearchLocationConfigSpec
{
    public static function runAll(): void
    {
        self::testFreshInstallSeedsAddressArea();
        self::testAbsentKeyResolvesToAddressArea();
        self::testSaveRoundTripsBothPositions();
        self::testResolvedValueIsTheStringTheJsCompares();
        self::testTileSmartyFlagIsInverseOfAddressArea();
        self::testUpgradeRenamesTheLegacyKeyAndCarriesTheValue();
        self::testUpgradeLeavesAnUnsetLegacyKeyUnset();
        self::testUpgradeIsIdempotentAndDoesNotResurrectTheLegacyKey();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
    }

    private static function resolve(TwopaymentTestHarness $module): string
    {
        $method = new ReflectionMethod(Twopayment::class, 'isCompanySearchInAddressArea');

        return $method->invoke($module);
    }

    /** @return array<string,mixed> */
    private static function formValues(TwopaymentTestHarness $module): array
    {
        $method = new ReflectionMethod(Twopayment::class, 'getTwoCompanyLookupFormValues');

        return $method->invoke($module);
    }

    private static function save(TwopaymentTestHarness $module, $posted): void
    {
        Tools::setTestValue('PS_TWO_COMPANY_SEARCH_LOCATION', $posted);
        $method = new ReflectionMethod(Twopayment::class, 'saveTwoCompanyLookupFormValues');
        $method->invoke($module);
    }

    private static function testFreshInstallSeedsAddressArea(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $method = new ReflectionMethod(Twopayment::class, 'installTwoSettings');
        TinyAssert::true($method->invoke($module));

        TinyAssert::true(Configuration::hasKey('PS_TWO_COMPANY_SEARCH_LOCATION'));
        TinyAssert::same(1, Configuration::get('PS_TWO_COMPANY_SEARCH_LOCATION'));
        TinyAssert::same('1', self::resolve($module));
    }

    private static function testAbsentKeyResolvesToAddressArea(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::false(Configuration::hasKey('PS_TWO_COMPANY_SEARCH_LOCATION'));
        TinyAssert::same('1', self::resolve($module));
        TinyAssert::same('1', self::formValues($module)['PS_TWO_COMPANY_SEARCH_LOCATION']);

        // An empty stored value is the same case, not a deliberate tile pick.
        Configuration::updateValue('PS_TWO_COMPANY_SEARCH_LOCATION', '');
        TinyAssert::same('1', self::resolve($module));
    }

    private static function testSaveRoundTripsBothPositions(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        self::save($module, '0');
        TinyAssert::same('0', (string) Configuration::get('PS_TWO_COMPANY_SEARCH_LOCATION'));
        TinyAssert::same('0', self::resolve($module));
        TinyAssert::same('0', self::formValues($module)['PS_TWO_COMPANY_SEARCH_LOCATION']);

        Tools::resetTestValues();
        self::save($module, '1');
        TinyAssert::same('1', (string) Configuration::get('PS_TWO_COMPANY_SEARCH_LOCATION'));
        TinyAssert::same('1', self::resolve($module));
        TinyAssert::same('1', self::formValues($module)['PS_TWO_COMPANY_SEARCH_LOCATION']);
    }

    private static function testResolvedValueIsTheStringTheJsCompares(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // views/js/twopayment.js reads `twopayment.company_search_in_address_area !== '0'`,
        // so the payload value has to be an exact '1'/'0' string whichever
        // shape the configuration row happens to hold.
        foreach ([1, '1', true] as $addressArea) {
            Configuration::updateValue('PS_TWO_COMPANY_SEARCH_LOCATION', $addressArea);
            TinyAssert::same('1', self::resolve($module));
        }

        // Deliberately NOT including literal `false` here (unlike the `true`
        // above): Configuration::get() also returns `false` for an ABSENT
        // key, so a stored `false` is indistinguishable from "never set"
        // through this resolver - by design, since absent must resolve to
        // enabled (see testAbsentKeyResolvesToAddressArea). Matches
        // AddressLookupConfigSpec's identical omission for the same reason.
        foreach ([0, '0'] as $tile) {
            Configuration::updateValue('PS_TWO_COMPANY_SEARCH_LOCATION', $tile);
            TinyAssert::same('0', self::resolve($module));
        }
    }

    /**
     * paymentinfo.tpl's `company_search_tile` smarty var (which gates
     * whether the tile mount renders at all) must always be the exact
     * inverse of isCompanySearchInAddressArea() - never independently
     * derived, or the two could disagree.
     */
    private static function testTileSmartyFlagIsInverseOfAddressArea(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        foreach (['1' => false, '0' => true] as $addressAreaValue => $expectedTileFlag) {
            Configuration::updateValue('PS_TWO_COMPANY_SEARCH_LOCATION', $addressAreaValue);
            TinyAssert::same(
                $expectedTileFlag,
                self::resolve($module) !== '1',
                'company_search_tile must be the exact inverse of isCompanySearchInAddressArea()'
            );
        }
    }

    private static function runUpgrade(TwopaymentTestHarness $module): void
    {
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.5.php';
        TinyAssert::true(upgrade_module_2_7_5($module));
    }

    /**
     * The 2.7.5 rename carries the merchant's choice across (TWO-40).
     *
     * The tile position is the one that matters: a merchant who deliberately
     * moved the search into the payment tile must not silently get it back in
     * the address area because the key it was stored under changed name.
     */
    private static function testUpgradeRenamesTheLegacyKeyAndCarriesTheValue(): void
    {
        foreach (['0', '1'] as $stored) {
            self::reset();
            $module = new TwopaymentTestHarness();

            Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', $stored);
            self::runUpgrade($module);

            TinyAssert::false(Configuration::hasKey('PS_TWO_ENABLE_COMPANY_NAME'));
            TinyAssert::same($stored, (string) Configuration::get('PS_TWO_COMPANY_SEARCH_LOCATION'));
            TinyAssert::same($stored, self::resolve($module));
        }
    }

    /**
     * A shop that never wrote the legacy key must come out with NO row, not
     * with a row this migration invented. Absent resolves to the address area
     * through the resolver itself; writing a '1' here would record a decision
     * the merchant never made, and would then survive a later change to what
     * the default means.
     */
    private static function testUpgradeLeavesAnUnsetLegacyKeyUnset(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        self::runUpgrade($module);

        TinyAssert::false(Configuration::hasKey('PS_TWO_ENABLE_COMPANY_NAME'));
        TinyAssert::false(Configuration::hasKey('PS_TWO_COMPANY_SEARCH_LOCATION'));
        TinyAssert::same('1', self::resolve($module));
    }

    /**
     * A second run must not clobber a value the merchant changed in between.
     * The guard is hasKey() on the OLD key, which the first run deleted.
     */
    private static function testUpgradeIsIdempotentAndDoesNotResurrectTheLegacyKey(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', '0');
        self::runUpgrade($module);

        // Merchant then switches back to the address area under the new key.
        Configuration::updateValue('PS_TWO_COMPANY_SEARCH_LOCATION', '1');
        self::runUpgrade($module);

        TinyAssert::false(Configuration::hasKey('PS_TWO_ENABLE_COMPANY_NAME'));
        TinyAssert::same('1', (string) Configuration::get('PS_TWO_COMPANY_SEARCH_LOCATION'));
    }
}
