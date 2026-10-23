<?php

declare(strict_types=1);

/**
 * Coverage for upgrade/upgrade-2.7.8.php (TWO-25455, TWO-26604).
 *
 * The stored environment is rewritten to the canonical set, preserving the
 * hosts the shop already talked to. Released builds installed with
 * `development`, which always resolved to the sandbox hosts, so it must land
 * on `sandbox` and never on `staging`.
 */
final class EnvironmentUpgradeMigrationSpec
{
    public static function runAll(): void
    {
        self::testMigrationTable();
        self::testEveryTierIsMigrated();
        self::testSingleStoreIgnoresStaleShopRows();
        self::testFailureToWriteIsReportedAndFailsTheUpgrade();
    }

    private static function loadScript(): void
    {
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.8.php';
    }

    private static function testMigrationTable(): void
    {
        $cases = [
            ['production', 'production', 'production kept'],
            ['PROD', 'production', 'upper-case alias to production'],
            ['Production', 'production', 'mixed-case production lowercased'],
            ['prod', 'production', 'alias to production'],
            ['sandbox', 'sandbox', 'sandbox kept'],
            ['SANDBOX', 'sandbox', 'upper-case sandbox lowercased'],
            ['staging', 'staging', 'staging kept'],
            ['Staging', 'staging', 'staging in any case kept'],
            ['development', 'sandbox', 'released default development always resolved to sandbox, so it stays on sandbox'],
            ['Development', 'sandbox', 'development in any case to sandbox'],
            ['', 'sandbox', 'empty to sandbox'],
            [null, 'sandbox', 'missing key to sandbox'],
            ['Demo', 'sandbox', 'any other value to sandbox'],
        ];

        self::loadScript();
        foreach ($cases as list($stored, $expected, $description)) {
            StubStore::reset();
            if ($stored === null) {
                unset(StubStore::$configuration['PS_TWO_ENVIRONMENT']);
            } else {
                StubStore::$configuration['PS_TWO_ENVIRONMENT'] = $stored;
            }
            $module = new TwopaymentTestHarness();

            TinyAssert::true(upgrade_module_2_7_8($module), $description . ' (upgrade succeeds)');
            TinyAssert::same($expected, (string) Configuration::get('PS_TWO_ENVIRONMENT'), $description);
            TinyAssert::true(upgrade_module_2_7_8($module), $description . ' (re-run succeeds)');
            TinyAssert::same($expected, (string) Configuration::get('PS_TWO_ENVIRONMENT'), $description . ' (idempotent)');
        }
    }

    /** A shop group's or a shop's own row is rewritten at its own tier, not only the one the context resolves to. */
    private static function testEveryTierIsMigrated(): void
    {
        self::loadScript();
        StubStore::reset();
        StubStore::$multistore = true;
        StubStore::$configuration['PS_TWO_ENVIRONMENT'] = 'development';
        StubStore::$configurationGroup[2]['PS_TWO_ENVIRONMENT'] = 'PROD';
        StubStore::$configurationShop[3]['PS_TWO_ENVIRONMENT'] = 'staging';
        StubStore::$configurationShop[4]['PS_TWO_ENVIRONMENT'] = 'Demo';

        TinyAssert::true(upgrade_module_2_7_8(new TwopaymentTestHarness()), 'the upgrade succeeds');

        $cases = [
            [StubStore::$configuration['PS_TWO_ENVIRONMENT'], 'sandbox', 'global development to sandbox'],
            [StubStore::$configurationGroup[2]['PS_TWO_ENVIRONMENT'], 'production', 'shop group PROD to production'],
            [StubStore::$configurationShop[3]['PS_TWO_ENVIRONMENT'], 'staging', 'shop staging kept'],
            [StubStore::$configurationShop[4]['PS_TWO_ENVIRONMENT'], 'sandbox', 'shop unknown value to sandbox'],
        ];
        foreach ($cases as list($actual, $expected, $description)) {
            TinyAssert::same($expected, $actual, $description);
        }
        StubStore::$multistore = false;
    }

    /** Without multistore a stale shop row is not live, and migrating it would overwrite the global value. */
    private static function testSingleStoreIgnoresStaleShopRows(): void
    {
        self::loadScript();
        StubStore::reset();
        StubStore::$configuration['PS_TWO_ENVIRONMENT'] = 'production';
        StubStore::$configurationShop[1]['PS_TWO_ENVIRONMENT'] = 'development';
        StubStore::$configurationGroup[1]['PS_TWO_ENVIRONMENT'] = 'Demo';

        TinyAssert::true(upgrade_module_2_7_8(new TwopaymentTestHarness()), 'single store: the upgrade succeeds');
        TinyAssert::same('production', StubStore::$configuration['PS_TWO_ENVIRONMENT'], 'single store: global production kept');
        TinyAssert::same('production', (string) Configuration::get('PS_TWO_ENVIRONMENT'), 'single store: effective value stays production');
    }

    private static function testFailureToWriteIsReportedAndFailsTheUpgrade(): void
    {
        self::loadScript();
        StubStore::reset();
        PrestaShopLogger::reset();
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'development');

        $module = new TwopaymentTestHarness();
        StubStore::$configurationUpdateFailsOnce['PS_TWO_ENVIRONMENT'] = true;

        TinyAssert::false(
            upgrade_module_2_7_8($module),
            'a failed write must fail the upgrade rather than silently leaving the shop '
                . 'on a legacy value with no record of why'
        );

        $logged = false;
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'Failed to rewrite PS_TWO_ENVIRONMENT') !== false) {
                $logged = true;
            }
        }
        TinyAssert::true($logged, 'and the failure must be logged, not silent');
    }
}
