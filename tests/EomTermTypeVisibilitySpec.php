<?php

declare(strict_types=1);

/**
 * TWO-25656: the EOM "Payment terms type" selector is rendered only on a shop
 * whose stored term type is already EOM, the same gate the other platforms apply.
 */
final class EomTermTypeVisibilitySpec
{
    public static function runAll(): void
    {
        self::testSelectorVisibilityByStoredType();
        self::testSavingStandardRemovesTheSelector();
        self::testConfigWriteRestoresTheSelector();
        self::testAbsentPostLeavesTheStoredTypeUntouched();
        self::testSelectorFollowsTheContextRow();
        self::testEomDayListIsPublishedToTheAdminTemplate();
    }

    /**
     * TWO-25705: the admin JS narrows the live term offers by term type, and
     * core's checkbox template drops the per-option class that used to carry
     * it, so the eligible day counts have to be published as their own value.
     */
    private static function testEomDayListIsPublishedToTheAdminTemplate(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/twopayment.php');

        TinyAssert::true(
            strpos($source, "'two_eom_term_days' => json_encode(array_map('intval', self::EOM_PAYMENT_TERMS_OPTIONS)),") !== false,
            'the admin template no longer receives the EOM-eligible day counts'
        );
        TinyAssert::same(
            array(30, 45, 60),
            array_map('intval', Twopayment::EOM_PAYMENT_TERMS_OPTIONS),
            'the published day counts are only as right as the constant behind them'
        );
    }

    private static function module(): TwopaymentTestHarness
    {
        StubStore::reset();
        Tools::resetTestValues();

        return new TwopaymentTestHarness();
    }

    /** @return array<int,array<string,mixed>> */
    private static function paymentTermsInputs(object $module): array
    {
        $form = (new ReflectionMethod(Twopayment::class, 'getTwoPaymentTermsForm'))->invoke($module);

        return $form['form']['input'];
    }

    private static function hasTermTypeField(object $module): bool
    {
        foreach (self::paymentTermsInputs($module) as $input) {
            if (isset($input['name']) && $input['name'] === 'PS_TWO_PAYMENT_TERM_TYPE') {
                return true;
            }
        }

        return false;
    }

    private static function testSelectorVisibilityByStoredType(): void
    {
        $cases = array(
            array('EOM', true, 'a shop stored on EOM must be able to see and change the selector'),
            array('STANDARD', false, 'the install default must not expose the unrolled-out EOM choice'),
            array('', false, 'an unset term type must not expose the selector'),
            array('eom', false, 'the predicate is exact - a lowercase value is not a configured EOM shop'),
            array('  EOM  ', false, 'a padded value the term logic reads as Standard must not show the selector either'),
            array('NET_TERMS', false, 'an unrecognised stored value must not expose the selector'),
        );

        foreach ($cases as $case) {
            list($stored, $expected, $description) = $case;
            $module = self::module();
            Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', $stored);
            TinyAssert::same($expected, self::hasTermTypeField($module), $description);
        }
    }

    private static function testSavingStandardRemovesTheSelector(): void
    {
        $module = self::module();
        Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', 'EOM');
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1);
        Tools::setTestValue('PS_TWO_PAYMENT_TERM_TYPE', 'STANDARD');

        (new ReflectionMethod(Twopayment::class, 'saveTwoPaymentTermsFormValues'))->invoke($module);

        TinyAssert::same('STANDARD', Configuration::get('PS_TWO_PAYMENT_TERM_TYPE'), 'the submitted Standard choice must be stored');
        TinyAssert::false(self::hasTermTypeField($module), 'saving Standard must remove the selector');
    }

    private static function testConfigWriteRestoresTheSelector(): void
    {
        $module = self::module();
        Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', 'STANDARD');
        TinyAssert::false(self::hasTermTypeField($module), 'a Standard shop starts without the selector');

        Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', 'EOM');
        TinyAssert::true(self::hasTermTypeField($module), 'a config write of EOM by other means must bring the selector back');
    }

    /**
     * The hidden field is not POSTed, and reading that absence as a choice
     * would silently rewrite the stored type on every unrelated save.
     */
    private static function testAbsentPostLeavesTheStoredTypeUntouched(): void
    {
        $module = self::module();
        Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', 'EOM');
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1);

        (new ReflectionMethod(Twopayment::class, 'saveTwoPaymentTermsFormValues'))->invoke($module);

        TinyAssert::same('EOM', Configuration::get('PS_TWO_PAYMENT_TERM_TYPE'), 'an absent POST must not rewrite the stored term type');
    }

    /**
     * The gate reads the same context row the field value and the save use: a selector rendered for a
     * shop row the all-shops save cannot reach would be a control that does nothing.
     */
    private static function testSelectorFollowsTheContextRow(): void
    {
        // [group rows, shop rows, context, context id, expected, description]
        $cases = array(
            array(array(), array(2 => 'EOM'), Shop::CONTEXT_ALL, null, false, 'a shop row must not show the selector in an all-shops context, whose save cannot reach it'),
            array(array(1 => 'EOM'), array(), Shop::CONTEXT_ALL, null, false, 'a group row must not show the selector in an all-shops context'),
            array(array(1 => 'EOM'), array(), Shop::CONTEXT_GROUP, 1, true, 'a group row shows the selector in its own group context'),
            array(array(1 => 'EOM'), array(), Shop::CONTEXT_GROUP, 2, false, 'a group row must not show the selector in another group context'),
            array(array(1 => 'EOM'), array(), Shop::CONTEXT_SHOP, 1, true, 'a shop under an EOM group inherits the selector'),
            array(array(), array(2 => 'EOM'), Shop::CONTEXT_SHOP, 1, false, 'a sibling shop row must not show the selector'),
            array(array(1 => 'STANDARD'), array(2 => 'EOM'), Shop::CONTEXT_SHOP, 2, true, 'a shop row wins over its group row'),
        );

        foreach ($cases as $case) {
            list($groupRows, $shopRows, $context, $id, $expected, $description) = $case;
            $module = self::module();
            StubStore::$multistore = true;
            StubStore::$shops = array(1 => 1, 2 => 1, 3 => 2);
            StubStore::$configuration['PS_TWO_PAYMENT_TERM_TYPE'] = 'STANDARD';
            foreach ($groupRows as $idShopGroup => $type) {
                StubStore::$configurationGroup[$idShopGroup]['PS_TWO_PAYMENT_TERM_TYPE'] = $type;
            }
            foreach ($shopRows as $idShop => $type) {
                StubStore::$configurationShop[$idShop]['PS_TWO_PAYMENT_TERM_TYPE'] = $type;
            }
            Shop::setContext($context, $id);

            TinyAssert::same($expected, self::hasTermTypeField($module), $description);
            StubStore::reset();
        }
    }
}
