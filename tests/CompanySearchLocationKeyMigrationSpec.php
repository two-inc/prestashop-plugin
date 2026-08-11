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
 *  - the old row is gone afterwards on every path where nothing would be lost
 *    by removing it;
 *  - a copy that FAILS without throwing keeps the old row instead - it is then
 *    the only surviving copy of the value - and says so in the log at the
 *    raised severity, naming a recovery a human can actually perform and NOT
 *    promising a re-run (the script cannot re-run: it returns true, so core
 *    records the module at 2.7.6 and only runs scripts above the recorded
 *    version - see the script header's WHY NOT RETURN false section);
 *  - a copy that THROWS is reported as the SAME state as one that answered
 *    falsy, at the same severity: the value is uncarried and the old row is
 *    untouched either way, so a log that distinguished them would be describing
 *    the implementation, not the shop;
 *  - a delete that FAILS after a successful copy is reported rather than being
 *    folded into a success message;
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
        self::testFailedCopyKeepsTheOldRow();
        self::testCopyThatThrowsIsReportedAsTheSameStateAsAFalsyCopy();
        self::testFailedDeleteAfterASuccessfulCopyIsReported();
        self::testSecondRunChangesNothing();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
    }

    /**
     * The `true` here is load-bearing, not boilerplate: it pins the decision
     * that this script reports every outcome and fails NONE of them. Core
     * disables the module on a falsy return (and, since this module ships an
     * `override/` directory, strips its overrides out of the shop) - so a failed
     * config write must not answer false, however recoverable that would make
     * the setting. See the script header's WHY NOT RETURN false section.
     */
    private static function upgrade(TwopaymentTestHarness $module): void
    {
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.6.php';
        TinyAssert::true(
            upgrade_module_2_7_6($module),
            'the migration must never fail the upgrade, on any path'
        );
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

    private static function lastLogSeverity(): int
    {
        TinyAssert::true(
            PrestaShopLogger::$logs !== [],
            'the migration must leave a trail in the shop log'
        );

        return PrestaShopLogger::$logs[count(PrestaShopLogger::$logs) - 1]['severity'];
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

    /**
     * A write that fails WITHOUT throwing - core's updateValue() returns an
     * accumulated Db result, so this is a real shape, not a hypothetical. The
     * old row is then the ONLY copy of the merchant's position and must
     * survive: deleting it there loses the value outright.
     */
    private static function testFailedCopyKeepsTheOldRow(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        Configuration::updateValue(self::OLD_KEY, '0');
        StubStore::$configurationUpdateFailsOnce[self::NEW_KEY] = true;

        self::upgrade($module);

        TinyAssert::false(
            Configuration::hasKey(self::NEW_KEY),
            'a failed write must not leave a row on the new key'
        );
        TinyAssert::true(
            Configuration::hasKey(self::OLD_KEY),
            'the old row is the only surviving copy of the value and must be kept'
        );
        TinyAssert::same('0', (string) Configuration::get(self::OLD_KEY));
        self::assertKeptRowReportedWithAHumanRecovery();
    }

    /**
     * The other real write-failure shape: core's Db raises as well as returning
     * an accumulated result. The SHOP is in the same state as above - nothing on
     * the new key, the old row untouched and uncarried - so the report must be
     * the same too. It was not: the kept-old-row state used to be recorded
     * inside the try, after the write, so a throw skipped it and the log claimed
     * "the delete never ran" at severity 2 instead.
     */
    private static function testCopyThatThrowsIsReportedAsTheSameStateAsAFalsyCopy(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        Configuration::updateValue(self::OLD_KEY, '0');
        StubStore::$configurationUpdateThrowsOnce[self::NEW_KEY] = 'MySQL server has gone away';

        self::upgrade($module);

        TinyAssert::false(
            Configuration::hasKey(self::NEW_KEY),
            'a write that threw must not leave a row on the new key'
        );
        TinyAssert::true(
            Configuration::hasKey(self::OLD_KEY),
            'the old row is the only surviving copy of the value and must be kept'
        );
        TinyAssert::same('0', (string) Configuration::get(self::OLD_KEY));
        TinyAssert::true(
            strpos(self::lastLogMessage(), 'MySQL server has gone away') !== false,
            'the raised message must reach the log - it is the only clue to the cause'
        );
        TinyAssert::true(
            strpos(self::lastLogMessage(), 'the delete never ran') === false,
            'a throw from the copy must not be reported as a state where nothing was attempted'
        );
        self::assertKeptRowReportedWithAHumanRecovery();
    }

    /**
     * Shared by both write-failure shapes on purpose: the assertion is that they
     * are INDISTINGUISHABLE in the log, because the shop state they leave is.
     */
    private static function assertKeptRowReportedWithAHumanRecovery(): void
    {
        $message = self::lastLogMessage();

        TinyAssert::true(
            strpos($message, 'deliberately KEPT') !== false,
            'the kept old row must be logged as deliberate'
        );
        // The script returns true, so core records 2.7.6 and never runs this
        // again: an instruction to re-run the upgrade cannot be followed and
        // must not be given.
        TinyAssert::true(
            strpos($message, 're-run') === false,
            'the log must not offer a re-run of an upgrade that can never run again'
        );
        TinyAssert::true(
            strpos($message, 're-select the company-search position') !== false,
            'the log must name a recovery a human can actually perform'
        );
        TinyAssert::same(
            3,
            self::lastLogSeverity(),
            'a value that could not be carried is an error, not an informational note'
        );
    }

    /**
     * The copy landed, the delete did not. The value is safe, but the old row
     * is still in ps_configuration - the log must say so rather than claim the
     * rename completed.
     */
    private static function testFailedDeleteAfterASuccessfulCopyIsReported(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        Configuration::updateValue(self::OLD_KEY, '0');
        StubStore::$configurationDeleteFailsOnce[self::OLD_KEY] = true;

        self::upgrade($module);

        TinyAssert::same('0', (string) Configuration::get(self::NEW_KEY));
        TinyAssert::same('0', self::resolve($module));
        TinyAssert::true(
            Configuration::hasKey(self::OLD_KEY),
            'a failed delete leaves the old row in place - the fixture is what makes this branch reachable'
        );
        TinyAssert::true(
            strpos(self::lastLogMessage(), 'deleting the old key FAILED') !== false,
            'a failed delete must be reported, not folded into a success message'
        );
        TinyAssert::same(
            2,
            self::lastLogSeverity(),
            'a surviving old row is a warning: the value is safe but the shop is not clean'
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
