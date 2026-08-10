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
        self::testLegacyKeyIsStillHonouredBeforeTheUpgradeScriptRuns();
        self::testLegacyKeyShimIsRetiredInTwoPointEight();
        self::testUninstallRemovesBothSpellings();
        self::testNewKeyWinsOverALingeringLegacyRow();
        self::testUpgradeFinishesEvenWhenTheOverrideRefreshThrows();
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

    /**
     * The window between a file-swap deploy and the upgrade script running.
     *
     * A PrestaShop upgrade script runs only when the web Module Manager (or
     * dev/ci/upgrade-module.sh) runs it - NOT when a deploy merely replaces the
     * module directory, which is how the git-synced shops update. In that window
     * the new key is absent while the old row is still in the DB, and without the
     * read shim a merchant who chose the payment tile is silently flipped back to
     * the address area AND has address autofill re-enabled, on a live storefront.
     */
    private static function testLegacyKeyIsStillHonouredBeforeTheUpgradeScriptRuns(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // Files swapped, upgrade script not yet run.
        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', '0');
        TinyAssert::false(Configuration::hasKey('PS_TWO_COMPANY_SEARCH_LOCATION'));

        TinyAssert::same('0', self::resolve($module));

        // The NEW key always wins once it exists, even while the old row lingers -
        // the shim is a fallback, never an override.
        Configuration::updateValue('PS_TWO_COMPANY_SEARCH_LOCATION', '1');
        TinyAssert::same('1', self::resolve($module));

        // And an empty legacy row is "absent", not a tile pick - the same
        // distinction the migration itself must preserve.
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', '');
        TinyAssert::same('1', self::resolve($module));
    }

    /**
     * The shim expires by ENFORCEMENT, not by anyone remembering.
     *
     * It is a compatibility read of a key this module no longer writes, and it
     * exists only to cover shops upgrading across 2.7.5. Once the declared
     * version reaches 2.8.0 every such shop has had a release to migrate, and
     * leaving the old spelling in a live code path becomes exactly the "two
     * spellings of one setting in the tree forever" that the rename was for.
     *
     * When this test goes red, DELETE: the legacy branch in
     * isCompanySearchInAddressArea(), the extra deleteByName() in
     * uninstallTwoSettings(), this test, and
     * testLegacyKeyIsStillHonouredBeforeTheUpgradeScriptRuns(). Do NOT bump the
     * boundary to keep it green.
     */
    private static function testLegacyKeyShimIsRetiredInTwoPointEight(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::true(
            version_compare((string) $module->version, '2.8.0', '<'),
            'The declared module version has reached 2.8.0: delete the PS_TWO_ENABLE_COMPANY_NAME'
            . ' read shim in isCompanySearchInAddressArea(), the matching deleteByName() in'
            . ' uninstallTwoSettings(), and the two tests covering them. Do not bump this boundary.'
        );
    }

    /**
     * Uninstall must remove BOTH spellings for as long as the shim lives.
     *
     * A shop whose upgrade script never ran still holds the old row. Without
     * this, uninstall orphans it and a later reinstall writes the new key = 1,
     * discarding a tile-mode choice that is still in the DB.
     */
    private static function testUninstallRemovesBothSpellings(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', '0');
        Configuration::updateValue('PS_TWO_COMPANY_SEARCH_LOCATION', '0');

        $method = new ReflectionMethod(Twopayment::class, 'uninstallTwoSettings');
        $method->invoke($module);

        TinyAssert::false(Configuration::hasKey('PS_TWO_ENABLE_COMPANY_NAME'));
        TinyAssert::false(Configuration::hasKey('PS_TWO_COMPANY_SEARCH_LOCATION'));
    }

    /**
     * NEWER WINS: a merchant who saved the position after the files landed but
     * before the upgrade script ran must not have that save reverted when it
     * finally runs (adversarial review, MAJOR: ordering hazard). The 2.7.5 admin
     * form writes the new key immediately; the script may run much later.
     *
     * The stale old row is still deleted - kept-and-orphaned would be the
     * uninstall bug this same spec covers, one release later.
     */
    private static function testNewKeyWinsOverALingeringLegacyRow(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // Files landed with the merchant previously on the payment tile...
        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', '0');
        // ...and the merchant then moved it to the address area through the new form.
        Configuration::updateValue('PS_TWO_COMPANY_SEARCH_LOCATION', '1');

        self::runUpgrade($module);

        TinyAssert::same('1', (string) Configuration::get('PS_TWO_COMPANY_SEARCH_LOCATION'));
        TinyAssert::same('1', self::resolve($module));
        TinyAssert::false(Configuration::hasKey('PS_TWO_ENABLE_COMPANY_NAME'));
    }

    /**
     * The override refresh is housekeeping ON TOP of an upgrade that has already
     * succeeded, so nothing it can hit may propagate - a throw here leaves the
     * module version un-bumped and the shop in a state no later script can reason
     * about. Errors included, not just exceptions.
     *
     * This also pins the ORDER: the key rename must have completed before the
     * refresh is attempted, so a broken override tree cannot cost the merchant
     * their setting. Mirrors OverrideMigrationSpec's equivalent test for 2.7.3;
     * the migrator itself is shared and covered there.
     */
    private static function testUpgradeFinishesEvenWhenTheOverrideRefreshThrows(): void
    {
        self::reset();
        PrestaShopLogger::reset();

        $module = new class extends TwopaymentTestHarness {
            public function getLocalPath()
            {
                throw new TypeError('anything at all, from anywhere in the migrator');
            }
        };

        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', '0');

        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.5.php';
        TinyAssert::true(
            upgrade_module_2_7_5($module),
            'an upgrade script must finish the upgrade even when its housekeeping raises an Error'
        );

        // The rename still happened, and is not undone by the failure below it.
        TinyAssert::same('0', (string) Configuration::get('PS_TWO_COMPANY_SEARCH_LOCATION'));
        TinyAssert::false(Configuration::hasKey('PS_TWO_ENABLE_COMPANY_NAME'));

        $logged = false;
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'override refresh raised') !== false) {
                $logged = true;
            }
        }
        TinyAssert::true($logged, 'and it must say so, or the shop is silently stale');
    }
}
