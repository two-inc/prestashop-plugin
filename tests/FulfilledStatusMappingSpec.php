<?php

declare(strict_types=1);

/**
 * Coverage for the "Two: Order Fulfilled - Trigger Statuses" multi-select
 * (PS_TWO_OS_FULFILLED_MAP) - TWO-24769.
 *
 * PrestaShop core's HelperForm::generate() rewrites a multi-select field's name
 * in place ($params['name'] .= '[]') and the form template resolves the
 * pre-selection with $fields_value[$input.name], i.e. under the rewritten
 * '[]'-suffixed key. Identical in PS 1.7.6.x, 8.x and 9.x. These tests pin:
 *
 *  - the multi-select pre-selection is exposed under that '[]' key (and the
 *    plain key), for every selected status, after a save-then-read-back cycle;
 *  - the IDs are strings, matching the id_order_state the option values are
 *    built from, so the comparison holds even under a strict comparison;
 *  - every writer of PS_TWO_OS_FULFILLED_MAP stores the same JSON array format,
 *    including the ensureCustomStatesExist() runtime recovery path which used to
 *    write a bare status ID;
 *  - the 2.6.2 upgrade normalises a legacy bare-ID value already in a database.
 *
 * Also covers the removal of the PS_TWO_FINALIZE_PURCHASE master switch
 * (TWO-24769 follow-up): PS_TWO_OS_FULFILLED_MAP is now the sole trigger -
 * an explicit empty selection must persist as "never fulfils" rather than
 * falling back to Shipped, and isFulfillmentTriggerStatus() must collapse
 * the old switch-on/off distinction into non-empty-list/empty-list. The
 * upgrade-2.7.14 migration protects shops that had the switch off.
 */
final class FulfilledStatusMappingSpec
{
    public static function runAll(): void
    {
        self::testSaveThenReadBackPreSelectsEverySelectedStatus();
        self::testReadBackNormalisesIdsToStrings();
        self::testLegacyBareIdValueStillPreSelects();
        self::testUnsetValueDefaultsToShippedStatus();
        self::testCustomStateRecoveryWritesJsonArrayFormat();
        self::testUpgrade262NormalisesLegacyBareIdValue();
        self::testUpgrade262LeavesCanonicalValueUntouched();
        self::testExplicitEmptySelectionPersistsAsNeverFulfils();
        self::testIsFulfillmentTriggerStatusCollapsedLogic();
        self::testUpgrade2714ClearsMapWhenSwitchWasOff();
        self::testUpgrade2714LeavesMapUntouchedWhenSwitchWasOn();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
        Configuration::updateValue('PS_OS_SHIPPING', 4);
    }

    /** @return array<string,mixed> */
    private static function formValues(TwopaymentTestHarness $module): array
    {
        $method = new ReflectionMethod(Twopayment::class, 'getTwoOrderStatusFormValues');

        return $method->invoke($module);
    }

    /** @param array<int,string> $postedIds */
    private static function save(TwopaymentTestHarness $module, array $postedIds): void
    {
        Tools::setTestValue('PS_TWO_OS_FULFILLED_MAP', $postedIds);
        $method = new ReflectionMethod(Twopayment::class, 'saveTwoOrderStatusFormValues');
        $method->invoke($module);
    }

    private static function testSaveThenReadBackPreSelectsEverySelectedStatus(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // Two statuses selected in the admin multi-select. PHP parses the
        // '[]'-suffixed field name into an array of strings in $_POST.
        self::save($module, ['3', '4']);

        TinyAssert::same('[3,4]', Configuration::get('PS_TWO_OS_FULFILLED_MAP'));

        $values = self::formValues($module);

        // The '[]' key is the one the core form template actually reads.
        TinyAssert::same(['3', '4'], $values['PS_TWO_OS_FULFILLED_MAP[]']);
        TinyAssert::same(['3', '4'], $values['PS_TWO_OS_FULFILLED_MAP']);
    }

    private static function testReadBackNormalisesIdsToStrings(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // json_decode() of the stored value yields PHP ints, while the option
        // values come from id_order_state as database strings.
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode([3, 5]));

        $values = self::formValues($module);

        TinyAssert::same(['3', '5'], $values['PS_TWO_OS_FULFILLED_MAP[]']);
        foreach ($values['PS_TWO_OS_FULFILLED_MAP[]'] as $id) {
            TinyAssert::true(is_string($id));
        }
    }

    private static function testLegacyBareIdValueStillPreSelects(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // Pre-2.1.2 format, and what ensureCustomStatesExist() wrote before 2.6.2.
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', '4');

        $values = self::formValues($module);

        TinyAssert::same(['4'], $values['PS_TWO_OS_FULFILLED_MAP[]']);
    }

    private static function testUnsetValueDefaultsToShippedStatus(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $values = self::formValues($module);

        TinyAssert::same(['4'], $values['PS_TWO_OS_FULFILLED_MAP[]']);
    }

    private static function testCustomStateRecoveryWritesJsonArrayFormat(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // No Two custom states and no mappings: the recovery path creates the
        // states and seeds the default mappings.
        $method = new ReflectionMethod(Twopayment::class, 'ensureCustomStatesExist');
        $method->invoke($module);

        TinyAssert::same('[4]', Configuration::get('PS_TWO_OS_FULFILLED_MAP'));

        // And the value it wrote must round-trip through the form reader.
        TinyAssert::same(['4'], self::formValues($module)['PS_TWO_OS_FULFILLED_MAP[]']);
    }

    private static function testUpgrade262NormalisesLegacyBareIdValue(): void
    {
        self::reset();
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.6.2.php';

        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', '3');

        TinyAssert::true(upgrade_module_2_6_2(new TwopaymentTestHarness()));
        TinyAssert::same('[3]', Configuration::get('PS_TWO_OS_FULFILLED_MAP'));
    }

    private static function testUpgrade262LeavesCanonicalValueUntouched(): void
    {
        self::reset();
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.6.2.php';

        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', '[3,4]');

        TinyAssert::true(upgrade_module_2_6_2(new TwopaymentTestHarness()));
        TinyAssert::same('[3,4]', Configuration::get('PS_TWO_OS_FULFILLED_MAP'));
        TinyAssert::count(0, PrestaShopLogger::$logs);
    }

    private static function testExplicitEmptySelectionPersistsAsNeverFulfils(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // A pre-existing selection, then a save that posts no statuses at all
        // (every checkbox/option deselected) - must clear, not fall back.
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode([4]));
        self::save($module, []);

        TinyAssert::same('[]', Configuration::get('PS_TWO_OS_FULFILLED_MAP'));
    }

    /**
     * @param string $mapJson    stored PS_TWO_OS_FULFILLED_MAP value
     * @param int    $statusId   status ID under test
     * @param bool   $expected   expected isFulfillmentTriggerStatus() result
     * @param string $why        assertion message
     */
    private static function assertTriggerStatus(string $mapJson, int $statusId, bool $expected, string $why): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', $mapJson);
        $module = new TwopaymentTestHarness();

        $method = new ReflectionMethod(Twopayment::class, 'isFulfillmentTriggerStatus');
        TinyAssert::same($expected, $method->invoke($module, $statusId), $why);
    }

    private static function testIsFulfillmentTriggerStatusCollapsedLogic(): void
    {
        $cases = [
            ['[]', 4, false, 'empty selection (switch-off equivalent): never triggers'],
            ['[]', 3, false, 'empty selection: no status triggers, not even Shipped'],
            ['[4]', 4, true, 'non-empty selection (switch-on equivalent): listed status triggers'],
            ['[4]', 3, false, 'non-empty selection: unlisted status does not trigger'],
            ['[3,4]', 3, true, 'multi-status selection: either listed status triggers'],
            ['[3,4]', 4, true, 'multi-status selection: either listed status triggers'],
        ];

        foreach ($cases as [$mapJson, $statusId, $expected, $why]) {
            self::assertTriggerStatus($mapJson, $statusId, $expected, $why);
        }
    }

    private static function testUpgrade2714ClearsMapWhenSwitchWasOff(): void
    {
        self::reset();
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.14.php';

        Configuration::updateValue('PS_TWO_FINALIZE_PURCHASE', 0);
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode([4]));

        TinyAssert::true(upgrade_module_2_7_14(new TwopaymentTestHarness()));

        TinyAssert::same('[]', Configuration::get('PS_TWO_OS_FULFILLED_MAP'), 'switch off must clear the mapping to empty');
        TinyAssert::false(Configuration::hasKey('PS_TWO_FINALIZE_PURCHASE'), 'the retired switch row must be deleted');
    }

    private static function testUpgrade2714LeavesMapUntouchedWhenSwitchWasOn(): void
    {
        self::reset();
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.14.php';

        Configuration::updateValue('PS_TWO_FINALIZE_PURCHASE', 1);
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode([4]));

        TinyAssert::true(upgrade_module_2_7_14(new TwopaymentTestHarness()));

        TinyAssert::same('[4]', Configuration::get('PS_TWO_OS_FULFILLED_MAP'), 'switch on: existing mapping already reflects intended behaviour, must not change');
        TinyAssert::false(Configuration::hasKey('PS_TWO_FINALIZE_PURCHASE'), 'the retired switch row must be deleted');
    }
}
