<?php

declare(strict_types=1);

/**
 * The reads whose intended fallback used to be passed as Configuration::get()'s
 * second argument, which is core's $id_lang and not a fallback (ABN-532):
 * `get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)`.
 * Every one of these keys is seeded by install(), so a correctly installed shop
 * never noticed - these cases are the partially installed one.
 */
final class ConfigFallbackSpec
{
    public static function runAll(): void
    {
        self::testTaxSubtotalsSetting();
        self::testHealthChecklistEnvironmentRow();
        self::testAttemptCleanupRunsWithNoRecordedRun();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
    }

    private static function testTaxSubtotalsSetting(): void
    {
        $cases = [
            [null, true, 1, 'no row at all sends the breakdown and renders the switch on'],
            ['1', true, 1, 'an enabled row sends the breakdown'],
            ['0', false, 0, 'a merchant who turned it off keeps it off'],
            ['', true, 1, 'an emptied row falls back to the install default'],
        ];

        foreach ($cases as list($stored, $expectedGate, $expectedField, $description)) {
            self::reset();
            if ($stored !== null) {
                StubStore::$configuration['PS_TWO_ENABLE_TAX_SUBTOTALS'] = $stored;
            }
            $module = new TwopaymentTestHarness();

            TinyAssert::same(
                $expectedGate,
                (new ReflectionMethod(Twopayment::class, 'shouldIncludeTaxSubtotals'))->invoke($module),
                $description
            );
            $fields = (new ReflectionMethod(Twopayment::class, 'getTwoOrderManagementFormValues'))->invoke($module);
            TinyAssert::same($expectedField, (int) $fields['PS_TWO_ENABLE_TAX_SUBTOTALS'], $description);
        }
    }

    /** Anything ENVIRONMENT_HOSTS does not name resolves to the sandbox at runtime, so the panel must not call it healthy. */
    private static function testHealthChecklistEnvironmentRow(): void
    {
        $cases = [
            ['production', 'PRODUCTION', true, 'a production shop reports production'],
            ['staging', 'STAGING', true, 'a staging shop reports staging'],
            [null, 'Not configured', false, 'no row at all is reported as unconfigured, not as staging'],
            ['', 'Not configured', false, 'an emptied row is reported as unconfigured'],
            ['development', 'DEVELOPMENT', false, 'the withdrawn value is shown but not called healthy'],
            ['<img src=x onerror=alert(1)>', '&lt;IMG SRC=X ONERROR=ALERT(1)&gt;', false, 'a stored value is escaped before it reaches an admin page'],
        ];

        foreach ($cases as list($stored, $expected, $expectedOk, $description)) {
            self::reset();
            // StubStore::reset() seeds a staging shop, so the no-row case has to clear it.
            if ($stored === null) {
                unset(StubStore::$configuration['PS_TWO_ENVIRONMENT']);
            } else {
                StubStore::$configuration['PS_TWO_ENVIRONMENT'] = $stored;
            }
            $module = new TwopaymentTestHarness();

            $html = (string) (new ReflectionMethod(Twopayment::class, 'renderTwoPluginHealthChecklist'))->invoke($module);
            $row = self::environmentRow($html);

            TinyAssert::true(strpos($row, '</i> ' . $expected . '</span>') !== false, $description);
            TinyAssert::same($expectedOk, strpos($row, 'text-success') !== false, $description);
        }
    }

    /** The Environment row alone, so a sibling row cannot satisfy the assertion. */
    private static function environmentRow(string $html): string
    {
        foreach (explode('<div><strong>', $html) as $fragment) {
            if (strpos($fragment, 'Environment:') === 0) {
                return $fragment;
            }
        }

        throw new RuntimeException('The health checklist rendered no Environment row.');
    }

    private static function testAttemptCleanupRunsWithNoRecordedRun(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::false(Configuration::hasKey('PS_TWO_ATTEMPT_CLEANUP_LAST_RUN'));
        $module->maybeCleanupStaleTwoCheckoutAttempts();

        $purges = array_filter(StubStore::$dbExecuted, static function ($sql) {
            return strpos($sql, 'DELETE FROM `ps_twopayment_attempt`') === 0;
        });
        TinyAssert::count(1, array_values($purges), 'a shop that has never purged purges on the first call');
        TinyAssert::true(
            (int) Configuration::get('PS_TWO_ATTEMPT_CLEANUP_LAST_RUN') > 0,
            'the purge records when it ran'
        );
    }
}
