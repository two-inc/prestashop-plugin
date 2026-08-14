<?php

declare(strict_types=1);

/**
 * Coverage for upgrade/upgrade-2.7.8.php (TWO-25455).
 *
 * The "Development" `PS_TWO_ENVIRONMENT` option is removed from the admin
 * dropdown as of this release - it had no entry in ENVIRONMENT_HOSTS,
 * PORTAL_HOSTS, BUYER_PORTAL_HOSTS or TwoSoleTrader::$signup_hosts, so it
 * silently behaved exactly like an unset/legacy value (sandbox everywhere).
 * It was also the install-time default, so any shop that never touched the
 * dropdown is on this value today. This script rewrites it to 'staging' - a
 * real, mapped environment - rather than leaving those shops on a value the
 * admin UI can no longer render.
 */
final class EnvironmentUpgradeMigrationSpec
{
    public static function runAll(): void
    {
        self::testRewritesStoredDevelopmentValueToStaging();
        self::testLeavesStagingUntouched();
        self::testLeavesProductionUntouched();
        self::testIsCaseInsensitiveOnTheStoredValue();
        self::testFailureToWriteIsReportedAndFailsTheUpgrade();
    }

    private static function loadScript(): void
    {
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.8.php';
    }

    private static function testRewritesStoredDevelopmentValueToStaging(): void
    {
        self::loadScript();
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'development');
        $module = new TwopaymentTestHarness();

        TinyAssert::true(upgrade_module_2_7_8($module), 'the upgrade script must report success');
        TinyAssert::same(
            'staging',
            (string) Configuration::get('PS_TWO_ENVIRONMENT'),
            "a shop stored on the removed 'development' value must be rewritten to 'staging'"
        );

        // Idempotent: a re-run (e.g. a re-triggered upgrade) must not error and
        // must leave the now-migrated value alone.
        TinyAssert::true(upgrade_module_2_7_8($module), 're-running the upgrade must still succeed');
        TinyAssert::same('staging', (string) Configuration::get('PS_TWO_ENVIRONMENT'));
    }

    private static function testLeavesStagingUntouched(): void
    {
        self::loadScript();
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'staging');
        $module = new TwopaymentTestHarness();

        TinyAssert::true(upgrade_module_2_7_8($module));
        TinyAssert::same(
            'staging',
            (string) Configuration::get('PS_TWO_ENVIRONMENT'),
            'a shop already on staging must be left alone'
        );
    }

    private static function testLeavesProductionUntouched(): void
    {
        self::loadScript();
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'production');
        $module = new TwopaymentTestHarness();

        TinyAssert::true(upgrade_module_2_7_8($module));
        TinyAssert::same(
            'production',
            (string) Configuration::get('PS_TWO_ENVIRONMENT'),
            'a shop on production must never be touched by this migration'
        );
    }

    private static function testIsCaseInsensitiveOnTheStoredValue(): void
    {
        self::loadScript();
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'Development');
        $module = new TwopaymentTestHarness();

        TinyAssert::true(upgrade_module_2_7_8($module));
        TinyAssert::same(
            'staging',
            (string) Configuration::get('PS_TWO_ENVIRONMENT'),
            'the stored value is matched case-insensitively, mirroring how the host maps '
                . 'themselves resolve PS_TWO_ENVIRONMENT (strtolower)'
        );
    }

    private static function testFailureToWriteIsReportedAndFailsTheUpgrade(): void
    {
        self::loadScript();
        PrestaShopLogger::reset();
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'development');

        $module = new TwopaymentTestHarness();
        StubStore::$configurationUpdateFailsOnce['PS_TWO_ENVIRONMENT'] = true;

        TinyAssert::false(
            upgrade_module_2_7_8($module),
            'a failed write must fail the upgrade rather than silently leaving the shop '
                . 'on the removed value with no record of why'
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
