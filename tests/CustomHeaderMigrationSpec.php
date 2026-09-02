<?php

declare(strict_types=1);

/**
 * Coverage for upgrade/upgrade-2.7.15.php (ABN-490).
 *
 * A shop with a firewall token configured has it because its firewall
 * refuses traffic without it, so the key cannot simply be dropped in favour
 * of the header table - it has to arrive as a row named X-WAF-TOKEN, the
 * header the removed field was always sent as, carrying the old browser flag.
 */
final class CustomHeaderMigrationSpec
{
    public static function runAll(): void
    {
        self::testConfiguredTokenBecomesOneRow();
        self::testBrowserFlagIsCarriedOver();
        self::testNoTokenLeavesAnEmptyList();
        self::testAnExistingHeaderListIsNeverOverwritten();
        self::testOldKeysAreRemoved();
    }

    private static function loadScript(): void
    {
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.15.php';
    }

    private static function migrate(): void
    {
        self::loadScript();
        TinyAssert::true(upgrade_module_2_7_15(new TwopaymentTestHarness()), 'the upgrade script must report success');
    }

    private static function storedRows(): array
    {
        return (array) json_decode((string) Configuration::get(Twopayment::CONFIG_CUSTOM_HEADERS), true);
    }

    private static function testConfiguredTokenBecomesOneRow(): void
    {
        StubStore::reset();
        Configuration::updateValue('PS_TWO_FIREWALL_TOKEN', 'waf-token-1');
        self::migrate();

        TinyAssert::same(
            array(array('name' => 'X-WAF-TOKEN', 'value' => 'waf-token-1', 'send_from_browser' => false)),
            self::storedRows(),
            'a configured token must survive as an X-WAF-TOKEN row'
        );
        TinyAssert::same(
            array('X-WAF-TOKEN:waf-token-1'),
            Twopayment::getTwoCustomHeaderLines(),
            'and must still be sent on every server-side call after the migration'
        );
    }

    private static function testBrowserFlagIsCarriedOver(): void
    {
        StubStore::reset();
        Configuration::updateValue('PS_TWO_FIREWALL_TOKEN', 'waf-token-1');
        Configuration::updateValue('PS_TWO_FIREWALL_TOKEN_BROWSER', 1);
        self::migrate();

        TinyAssert::same(
            array('X-WAF-TOKEN' => 'waf-token-1'),
            Twopayment::getTwoBrowserCustomHeaders(),
            'a shop that published the token to the browser must keep doing so'
        );
    }

    private static function testNoTokenLeavesAnEmptyList(): void
    {
        StubStore::reset();
        self::migrate();

        TinyAssert::same(array(), self::storedRows(), 'a shop with no token configured gets an empty list');
    }

    private static function testAnExistingHeaderListIsNeverOverwritten(): void
    {
        StubStore::reset();
        Configuration::updateValue('PS_TWO_FIREWALL_TOKEN', 'waf-token-1');
        Configuration::updateValue(Twopayment::CONFIG_CUSTOM_HEADERS, json_encode(array(
            array('name' => 'X-Corp-Gate', 'value' => 'gate-2', 'send_from_browser' => false),
        )));
        self::migrate();

        TinyAssert::same(
            array(array('name' => 'X-Corp-Gate', 'value' => 'gate-2', 'send_from_browser' => false)),
            self::storedRows(),
            'a re-run must not discard rows added since, nor re-add a token deliberately removed'
        );
    }

    private static function testOldKeysAreRemoved(): void
    {
        StubStore::reset();
        Configuration::updateValue('PS_TWO_FIREWALL_TOKEN', 'waf-token-1');
        Configuration::updateValue('PS_TWO_FIREWALL_TOKEN_BROWSER', 1);
        self::migrate();

        TinyAssert::false(Configuration::hasKey('PS_TWO_FIREWALL_TOKEN'), 'the old token key is deleted');
        TinyAssert::false(Configuration::hasKey('PS_TWO_FIREWALL_TOKEN_BROWSER'), 'the old browser switch is deleted');
    }
}
