<?php

declare(strict_types=1);

/**
 * Coverage for upgrade/upgrade-2.7.6.php - the company-search location key
 * rename `PS_TWO_ENABLE_COMPANY_NAME` -> `PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS`
 * (TWO-40 item #1).
 *
 * Kept separate from CompanySearchLocationConfigSpec, which pins how the key is
 * RESOLVED and round-tripped. This file pins the one-time MIGRATION, which has
 * its own failure modes and its own reason to exist - the rename shipped with
 * no assertion on its behaviour at all until review round 1 said so.
 *
 * What is pinned here:
 *
 *  - old '0' is carried across intact. '0' is falsy but MEANINGFUL: it is the
 *    merchant saying "the search lives in the payment tile", and a guard
 *    written against truthiness rather than against "usable value" would drop
 *    exactly the position that is not the default;
 *  - old '1' is carried across;
 *  - an absent old key copies nothing, and the resolver's '1' default still
 *    applies afterwards;
 *  - an EMPTY old row counts as absent, matching what the resolver does with
 *    an empty value - copying it would carry nothing while looking like it
 *    carried something;
 *  - a new key that ALREADY holds a value wins, and the copy is skipped. This
 *    is the file-swap window's regression test: files can reach 2.7.6 without
 *    the upgrade running, so a merchant can save a position (new key written,
 *    old row untouched) BEFORE the upgrade, and a later Module Manager upgrade
 *    must not put the stale old row back over that choice;
 *  - the old row is gone afterwards in every case;
 *  - a second run changes nothing.
 *
 * The offline `Configuration` double is a flat name->value array with no shop
 * dimension, so nothing here says anything about the multistore tiers. That is
 * deliberate and matches the script, which is global-tier-only on Doug's
 * explicit ruling - see the script's header and `.ai/decisions.md`.
 */
final class CompanySearchLocationKeyMigrationSpec
{
    private const OLD_KEY = 'PS_TWO_ENABLE_COMPANY_NAME';
    private const NEW_KEY = 'PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS';

    public static function runAll(): void
    {
        self::testCarriesFalsyButMeaningfulTileValueAcross();
        self::testCarriesAddressAreaValueAcross();
        self::testAbsentOldKeyCopiesNothingAndDefaultApplies();
        self::testEmptyOldRowCountsAsAbsent();
        self::testExistingNewValueWinsOverTheOldRow();
        self::testSecondRunChangesNothing();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
    }

    private static function upgrade(TwopaymentTestHarness $module): void
    {
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.6.php';
        TinyAssert::true(upgrade_module_2_7_6($module));
    }

    private static function resolve(TwopaymentTestHarness $module): string
    {
        $method = new ReflectionMethod(Twopayment::class, 'isCompanySearchInAddressArea');

        return $method->invoke($module);
    }

    private static function assertOldRowGone(): void
    {
        TinyAssert::false(
            Configuration::hasKey(self::OLD_KEY),
            'the old key must not survive the migration'
        );
    }

    private static function lastLogMessage(): string
    {
        TinyAssert::true(
            PrestaShopLogger::$logs !== [],
            'the migration must leave a trail in the shop log'
        );

        return PrestaShopLogger::$logs[count(PrestaShopLogger::$logs) - 1]['message'];
    }

    private static function testCarriesFalsyButMeaningfulTileValueAcross(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // The merchant chose the payment tile. Falsy, and the whole point.
        Configuration::updateValue(self::OLD_KEY, '0');

        self::upgrade($module);

        TinyAssert::same('0', (string) Configuration::get(self::NEW_KEY));
        TinyAssert::same('0', self::resolve($module));
        self::assertOldRowGone();
    }

    private static function testCarriesAddressAreaValueAcross(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        Configuration::updateValue(self::OLD_KEY, '1');

        self::upgrade($module);

        TinyAssert::same('1', (string) Configuration::get(self::NEW_KEY));
        TinyAssert::same('1', self::resolve($module));
        self::assertOldRowGone();
    }

    private static function testAbsentOldKeyCopiesNothingAndDefaultApplies(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::false(Configuration::hasKey(self::OLD_KEY));

        self::upgrade($module);

        TinyAssert::false(
            Configuration::hasKey(self::NEW_KEY),
            'nothing to carry must mean no row is written, not a row written with a junk value'
        );
        // The resolver's own default takes over, which is the same position the
        // old key's default produced.
        TinyAssert::same('1', self::resolve($module));
        self::assertOldRowGone();
    }

    private static function testEmptyOldRowCountsAsAbsent(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        Configuration::updateValue(self::OLD_KEY, '');

        self::upgrade($module);

        TinyAssert::false(
            Configuration::hasKey(self::NEW_KEY),
            'an empty old row means "no position chosen" and must not be copied'
        );
        TinyAssert::same('1', self::resolve($module));
        self::assertOldRowGone();
    }

    /**
     * The file-swap window's regression test. Remove the new-key half of the
     * script's guard and this goes red: the merchant's just-saved tile choice
     * would be overwritten by the stale old row and the search would move back
     * into the address area on a live storefront.
     */
    private static function testExistingNewValueWinsOverTheOldRow(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // Files swapped to 2.7.6 without the upgrade running, merchant then
        // saved "payment tile" through the config page: new key written, old
        // row still holding the previous position.
        Configuration::updateValue(self::OLD_KEY, '1');
        Configuration::updateValue(self::NEW_KEY, '0');

        self::upgrade($module);

        TinyAssert::same(
            '0',
            (string) Configuration::get(self::NEW_KEY),
            'a value already on the new key must survive the migration'
        );
        TinyAssert::same('0', self::resolve($module));
        self::assertOldRowGone();

        // The skip has to be readable as "already migrated / merchant already
        // chose", not as "there was nothing to carry".
        TinyAssert::true(
            strpos(self::lastLogMessage(), 'the new key already held') !== false,
            'the skipped copy must be logged distinguishably from the no-old-value case'
        );
    }

    private static function testSecondRunChangesNothing(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        Configuration::updateValue(self::OLD_KEY, '0');

        self::upgrade($module);
        $afterFirst = Configuration::get(self::NEW_KEY);

        self::upgrade($module);

        TinyAssert::same($afterFirst, Configuration::get(self::NEW_KEY));
        TinyAssert::same('0', self::resolve($module));
        self::assertOldRowGone();
    }
}
