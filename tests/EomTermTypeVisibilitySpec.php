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
        self::testConfigurableTermSetIsWhatTheAdminJsResolves();
    }

    /**
     * TWO-25705: the admin JS resolves getConfigurableTermSet() from the live
     * form so the screen and the save judge the default term against the same
     * set. Core's checkbox template drops the per-option class that used to
     * carry the term type, so both inputs to that rule have to be published.
     */
    private static function testEomDayListIsPublishedToTheAdminTemplate(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/twopayment.php');

        // [published expression, description]
        $published = array(
            array(
                "'two_eom_term_days' => json_encode(array_map('intval', self::EOM_PAYMENT_TERMS_OPTIONS)),",
                'the admin template no longer receives the EOM-eligible day counts',
            ),
            array(
                "'two_fallback_term_days' => (int) self::DEFAULT_PAYMENT_TERM_DAYS,",
                'the admin template no longer receives the term substituted for an empty narrowing',
            ),
        );
        foreach ($published as list($expression, $description)) {
            TinyAssert::true(strpos($source, $expression) !== false, $description);
        }

        // The browser suite cannot read a PHP constant, so both day counts are
        // written out in tests/js/admin-default-term-options.test.js and
        // tests/js/admin-surcharge-grid-empty-state.test.js. Pinned here, or a
        // change to either constant leaves those fixtures green against a page
        // that no longer behaves that way.
        TinyAssert::same(array(30, 45, 60), array_map('intval', Twopayment::EOM_PAYMENT_TERMS_OPTIONS), 'the EOM day counts the browser fixtures hardcode');
        TinyAssert::same(30, (int) Twopayment::DEFAULT_PAYMENT_TERM_DAYS, 'the fallback day count the browser fixtures hardcode');
    }

    /**
     * The rule the admin JS mirrors, stated on the server side so the set the
     * browser resolves can be read against it (TWO-25705). The browser half is
     * in tests/js/admin-default-term-options.test.js, over these same
     * configurations.
     */
    private static function testConfigurableTermSetIsWhatTheAdminJsResolves(): void
    {
        // [ticked terms, term type, resolved set, description]
        $cases = array(
            array(array(30, 90), 'STANDARD', array(30, 90), 'the ticked terms'),
            array(array(90), 'STANDARD', array(90), 'a single ticked term'),
            array(array(30, 90), 'EOM', array(30), 'a ticked term the term type excludes drops out'),
            array(array(), 'STANDARD', array(30), 'nothing ticked resolves the substituted term'),
            array(array(90), 'EOM', array(30), 'every ticked term excluded resolves it too'),
        );

        foreach ($cases as list($ticked, $termType, $expected, $description)) {
            $module = self::module();
            $module->primeTwoAvailableTerms(array(30, 60, 90));
            foreach (Twopayment::PAYMENT_TERMS_OPTIONS as $days) {
                Configuration::updateValue('PS_TWO_PAYMENT_TERMS_' . (int) $days, in_array((int) $days, $ticked, true) ? 1 : 0);
            }
            Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', $termType);

            TinyAssert::same(
                $expected,
                (new ReflectionMethod(Twopayment::class, 'getConfigurableTermSet'))->invoke($module),
                $description
            );
        }
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
