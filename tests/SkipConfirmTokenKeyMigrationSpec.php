<?php

declare(strict_types=1);

/**
 * Coverage for upgrade/upgrade-2.7.12.php - the skip-confirm-token debug
 * toggle's configuration-key rename (TWO-25386 #4).
 *
 * What is pinned here:
 *
 *  - an enabled toggle survives the rename, so a shop mid-debug is not silently
 *    put back on the enforced check;
 *  - an explicit '0' is carried too - it is the merchant saying "enforced", and
 *    a truthiness guard would drop it;
 *  - an absent or empty old row copies nothing and the reader's default (the
 *    check ENFORCED) stands;
 *  - a new key that already holds a value wins: files can reach 2.7.12 before
 *    the upgrade runs, so a merchant can save the toggle first and the stale old
 *    row must not be copied over that choice;
 *  - the old row is gone afterwards on every path where nothing is lost with it,
 *    and is KEPT, at raised severity, on the one path where it holds the only
 *    copy of the value;
 *  - the migration never fails the upgrade. A falsy return makes core disable
 *    the module and strip its overrides out of the shop - see the WHY NOT RETURN
 *    false section in upgrade-2.7.6.php's header.
 */
final class SkipConfirmTokenKeyMigrationSpec
{
    private const OLD_KEY = 'PS_TWO_SKIP_CONFIRM_NONCE_CHECK';
    private const NEW_KEY = 'PS_TWO_SKIP_CONFIRM_TOKEN_CHECK';

    public static function runAll(): void
    {
        self::testCarriesAnEnabledToggleAcross();
        self::testCarriesAnExplicitlyDisabledToggleAcross();
        self::testAbsentOldKeyCopiesNothingAndTheCheckStaysEnforced();
        self::testEmptyOldRowCountsAsAbsent();
        self::testExistingNewValueWinsOverTheOldRow();
        self::testFailedCopyKeepsTheOldRowAndRaisesSeverity();
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
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.12.php';
        TinyAssert::true(
            upgrade_module_2_7_12($module),
            'the migration must never fail the upgrade, on any path'
        );
    }

    private static function assertOldRowGone(): void
    {
        TinyAssert::false(
            Configuration::hasKey(self::OLD_KEY),
            'the old key must not survive the migration'
        );
    }

    private static function testCarriesAnEnabledToggleAcross(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue(self::OLD_KEY, '1');

        self::upgrade($module);

        TinyAssert::same('1', (string) Configuration::get(self::NEW_KEY));
        TinyAssert::true($module->isTwoSkipConfirmTokenCheckEnabled());
        self::assertOldRowGone();
    }

    private static function testCarriesAnExplicitlyDisabledToggleAcross(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue(self::OLD_KEY, '0');

        self::upgrade($module);

        TinyAssert::same('0', (string) Configuration::get(self::NEW_KEY));
        TinyAssert::false($module->isTwoSkipConfirmTokenCheckEnabled());
        self::assertOldRowGone();
    }

    private static function testAbsentOldKeyCopiesNothingAndTheCheckStaysEnforced(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        self::upgrade($module);

        TinyAssert::false(
            Configuration::hasKey(self::NEW_KEY),
            'nothing to carry must mean no row is written, not a row written with a junk value'
        );
        TinyAssert::false($module->isTwoSkipConfirmTokenCheckEnabled());
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
            'an empty old row carries nothing and must not be copied'
        );
        TinyAssert::false($module->isTwoSkipConfirmTokenCheckEnabled());
        self::assertOldRowGone();
    }

    /**
     * The file-swap window's regression test: remove the new-key half of the
     * script's guard and this goes red.
     */
    private static function testExistingNewValueWinsOverTheOldRow(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue(self::OLD_KEY, '1');
        Configuration::updateValue(self::NEW_KEY, '0');

        self::upgrade($module);

        TinyAssert::same(
            '0',
            (string) Configuration::get(self::NEW_KEY),
            'a value the merchant saved on the new key must not be overwritten by the old row'
        );
        self::assertOldRowGone();
    }

    private static function testFailedCopyKeepsTheOldRowAndRaisesSeverity(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue(self::OLD_KEY, '1');
        StubStore::$configurationUpdateFailsOnce[self::NEW_KEY] = true;

        self::upgrade($module);

        TinyAssert::true(
            Configuration::hasKey(self::OLD_KEY),
            'a copy that did not land leaves the old row as the only copy of the value, so it must be kept'
        );

        $last = PrestaShopLogger::$logs[count(PrestaShopLogger::$logs) - 1];
        TinyAssert::same(3, $last['severity'], 'an uncarried value must not read as a clean migration');
    }

    private static function testSecondRunChangesNothing(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue(self::OLD_KEY, '1');

        self::upgrade($module);
        self::upgrade($module);

        TinyAssert::same('1', (string) Configuration::get(self::NEW_KEY));
        self::assertOldRowGone();
    }
}
