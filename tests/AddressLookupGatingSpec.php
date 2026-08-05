<?php

declare(strict_types=1);

/**
 * The address-lookup switch is unavailable, and forced off, whenever the
 * company search is not in the address area - TWO-25326 §7.1 follow-up.
 *
 * PS_TWO_ADDRESS_LOOKUP governs one thing: what a company selection writes
 * into the checkout ADDRESS step. Once PS_TWO_ENABLE_COMPANY_NAME moves the
 * search itself into the payment tile there is no address-area lookup left to
 * govern, so leaving the switch independently settable let a merchant tick a
 * box the module then ignored. woocommerce-plugin's admin.js already disables
 * and unchecks its own `enable_address_lookup` field when company search is
 * off; this is the PrestaShop equivalent, plus the server-side half Woo does
 * not have.
 *
 * The gate has THREE surfaces and each is pinned separately, because each
 * fails differently:
 *
 *  - the SAVE refuses a submitted value. A disabled radio posts nothing, so
 *    the JS alone looks sufficient - but a replayed or hand-crafted POST does
 *    carry one, and this is the only thing that stops it taking effect;
 *  - the RESOLVER used by the checkout and by the admin form's rendered
 *    position agrees, so an install that has not re-saved its advanced
 *    settings since the search relocated does not hand '1' to the checkout JS
 *    while the form shows "No";
 *  - the ADMIN FORM's rendered position, which is what the merchant reads.
 *
 * The JS half (greying the control out) is asserted as a source shape over
 * views/templates/admin/configuration.tpl - there is no DOM here to drive, and
 * a missing gate would otherwise be invisible to this suite while the two
 * server-side halves passed.
 */
final class AddressLookupGatingSpec
{
    public static function runAll(): void
    {
        self::testSaveRefusesLookupWhenSearchMovesToTile();
        self::testSaveKeepsLookupWhenSearchStaysInAddressArea();
        self::testGateReadsTheSubmittedPositionNotTheStoredOne();
        self::testResolverReportsOffForAStoredTileInstall();
        self::testFormRendersLookupOffWhenSearchIsInTheTile();
        self::testAdminJsGreysTheControlOut();
        self::testAdminJsAutoChecksOnEnable();
        self::testLabelsAreSentenceCase();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
    }

    private static function resolve(TwopaymentTestHarness $module): string
    {
        $method = new ReflectionMethod(Twopayment::class, 'getAddressLookupEnabled');

        return $method->invoke($module);
    }

    /** @return array<string,mixed> */
    private static function formValues(TwopaymentTestHarness $module): array
    {
        $method = new ReflectionMethod(Twopayment::class, 'getTwoOtherFormValues');

        return $method->invoke($module);
    }

    private static function save(TwopaymentTestHarness $module): void
    {
        $method = new ReflectionMethod(Twopayment::class, 'saveTwoOtherFormValues');
        $method->invoke($module);
    }

    /**
     * The forced POST: company search moved to the tile in the same submission
     * that still carries a ticked address-lookup box, exactly as a replayed
     * form or a hand-built request would.
     */
    private static function testSaveRefusesLookupWhenSearchMovesToTile(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue('PS_TWO_ADDRESS_LOOKUP', 1);

        Tools::setTestValue('PS_TWO_ENABLE_COMPANY_NAME', '0');
        Tools::setTestValue('PS_TWO_ADDRESS_LOOKUP', '1');
        self::save($module);

        TinyAssert::same(0, Configuration::get('PS_TWO_ADDRESS_LOOKUP'));
        TinyAssert::same('0', self::resolve($module));
    }

    /**
     * The other half of the gate. Without this the refusal above could pass by
     * simply never storing a 1.
     */
    private static function testSaveKeepsLookupWhenSearchStaysInAddressArea(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        Tools::setTestValue('PS_TWO_ENABLE_COMPANY_NAME', '1');
        Tools::setTestValue('PS_TWO_ADDRESS_LOOKUP', '1');
        self::save($module);

        TinyAssert::same(1, Configuration::get('PS_TWO_ADDRESS_LOOKUP'));
        TinyAssert::same('1', self::resolve($module));
    }

    /**
     * The gate has to read the value being SUBMITTED, not the one on disk.
     * Reading the stored row would leave the lookup enabled for one whole save
     * cycle after the merchant moved the search into the tile - and that write
     * happens in the same method, so the read order is load-bearing rather
     * than stylistic.
     */
    private static function testGateReadsTheSubmittedPositionNotTheStoredOne(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        // Stored state: search in the address area, lookup on.
        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', 1);
        Configuration::updateValue('PS_TWO_ADDRESS_LOOKUP', 1);

        // This save is the one that relocates the search.
        Tools::setTestValue('PS_TWO_ENABLE_COMPANY_NAME', '0');
        Tools::setTestValue('PS_TWO_ADDRESS_LOOKUP', '1');
        self::save($module);

        TinyAssert::same(0, Configuration::get('PS_TWO_ADDRESS_LOOKUP'));

        // And the reverse: a save that moves it BACK must not be gated by the
        // stored '0' it is replacing.
        Tools::resetTestValues();
        Tools::setTestValue('PS_TWO_ENABLE_COMPANY_NAME', '1');
        Tools::setTestValue('PS_TWO_ADDRESS_LOOKUP', '1');
        self::save($module);

        TinyAssert::same(1, Configuration::get('PS_TWO_ADDRESS_LOOKUP'));
    }

    /**
     * An install already in tile mode whose stored lookup row is still 1 -
     * either because it predates this gate or because the merchant has not
     * saved advanced settings since. The value handed to the checkout JS must
     * report the behaviour that actually applies.
     */
    private static function testResolverReportsOffForAStoredTileInstall(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', 0);
        Configuration::updateValue('PS_TWO_ADDRESS_LOOKUP', 1);

        TinyAssert::same('0', self::resolve($module));

        // Not steerable from the request: this resolver runs on the FRONT
        // office, where a query parameter must not be able to re-enable a
        // lookup the stored configuration has turned off.
        Tools::setTestValue('PS_TWO_ENABLE_COMPANY_NAME', '1');
        TinyAssert::same('0', self::resolve($module));
    }

    private static function testFormRendersLookupOffWhenSearchIsInTheTile(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', 0);
        Configuration::updateValue('PS_TWO_ADDRESS_LOOKUP', 1);

        // The merchant must not read a ticked switch the module is ignoring.
        TinyAssert::same('0', self::formValues($module)['PS_TWO_ADDRESS_LOOKUP']);

        // And a failed-validation re-render of the POST that relocated the
        // search shows the position that POST will produce, not the stored one.
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', 1);
        Configuration::updateValue('PS_TWO_ADDRESS_LOOKUP', 1);
        Tools::setTestValue('PS_TWO_ENABLE_COMPANY_NAME', '0');
        Tools::setTestValue('PS_TWO_ADDRESS_LOOKUP', '1');

        TinyAssert::same('0', self::formValues($module)['PS_TWO_ADDRESS_LOOKUP']);
    }

    /**
     * The presentation half, asserted as a shape over the shipped admin
     * template. Nothing else in this suite can see it, and a server-side gate
     * with no visible counterpart is the version of this bug Doug reported:
     * the control still looked settable.
     */
    private static function testAdminJsGreysTheControlOut(): void
    {
        $tpl = file_get_contents(dirname(__DIR__) . '/views/templates/admin/configuration.tpl');
        TinyAssert::true(is_string($tpl) && $tpl !== '');

        // Reacts to the company-search switch at all, and on load as well as on
        // change - a change-only binding leaves a page that renders in the
        // gated state showing an enabled control until the merchant touches it.
        // The load-time call passes `false` (respect the stored position) and
        // the change handler passes `true` (see testAdminJsAutoChecksOnEnable) -
        // that distinction is TWO-25326's bug fix, so both calls are pinned by
        // their exact argument here rather than by a bare substring match that
        // would pass for either.
        TinyAssert::true(strpos($tpl, 'updateAddressLookupAvailability') !== false);
        TinyAssert::true(
            strpos(
                $tpl,
                "$('input[name=\"PS_TWO_ENABLE_COMPANY_NAME\"]').on('change', function () {\n"
                . '                updateAddressLookupAvailability(true);'
            ) !== false
        );
        TinyAssert::true(strpos($tpl, 'updateAddressLookupAvailability(false);') !== false);

        // Disables AND unchecks. Disabling alone leaves a ticked box on screen
        // that the save then silently refuses.
        TinyAssert::true(strpos($tpl, "lookupInputs.prop('disabled', true)") !== false);
        TinyAssert::true(strpos($tpl, "lookupInputs.filter('[value=\"0\"]').prop('checked', true)") !== false);
        // And re-enables, or the merchant cannot turn it back on without a
        // page reload after switching the search back to the address area.
        TinyAssert::true(strpos($tpl, "lookupInputs.prop('disabled', false)") !== false);
    }

    /**
     * Bug report (TWO-25326, 2026-08-04): re-enabling company search must
     * also switch auto-fill ON, not merely stop greying it out - an
     * enabled-but-unchecked control reads as "on" to the merchant but posts
     * '0' on save. Pinned as its own test, separate from
     * testAdminJsGreysTheControlOut(), because the auto-check must fire ONLY
     * on the user's own toggle (`isUserToggle === true`), never on the
     * initial page-load render - a page load must still respect whatever
     * position PS_TWO_ADDRESS_LOOKUP is actually stored in.
     */
    private static function testAdminJsAutoChecksOnEnable(): void
    {
        $tpl = file_get_contents(dirname(__DIR__) . '/views/templates/admin/configuration.tpl');
        TinyAssert::true(is_string($tpl) && $tpl !== '');

        TinyAssert::true(strpos($tpl, 'function updateAddressLookupAvailability(isUserToggle)') !== false);
        TinyAssert::true(strpos($tpl, 'if (isUserToggle) {') !== false);
        TinyAssert::true(strpos($tpl, "lookupInputs.filter('[value=\"1\"]').prop('checked', true);") !== false);
    }

    /**
     * Sentence case, matching every other label on the advanced-settings form.
     * Asserted against the rendered form definition rather than the source
     * string, so a re-worded label still has to keep the casing convention.
     */
    private static function testLabelsAreSentenceCase(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $method = new ReflectionMethod(Twopayment::class, 'getTwoOtherForm');
        $form = $method->invoke($module);

        $labels = array();
        foreach ($form['form']['input'] as $input) {
            if (isset($input['label'])) {
                $labels[$input['name']] = (string) $input['label'];
            }
        }

        TinyAssert::same('Enable company search in address entry', $labels['PS_TWO_ENABLE_COMPANY_NAME']);
        TinyAssert::same('Auto-fill the address from the selected company', $labels['PS_TWO_ADDRESS_LOOKUP']);

        // Every label on this form, not only the two this change touched: the
        // convention is what is being pinned. A label is sentence case when no
        // word after the first starts with a capital, allowing for proper nouns
        // and acronyms the module genuinely uses.
        $allowed = array('Two', 'DNI', 'VAT', 'SSL', 'PrestaShop');
        // KNOWN EXCEPTION, listed rather than papered over: "Disable SSL
        // Verification (Corporate Networks Only)" predates this change and is
        // Title Case. Fixing it means re-keying it in four translation
        // catalogues, which is not this change's scope - it is noted here so
        // the next person to touch these labels knows it is outstanding rather
        // than deliberate.
        $known_exceptions = array('PS_TWO_DISABLE_SSL_VERIFY');
        foreach ($labels as $name => $label) {
            if (in_array($name, $known_exceptions, true)) {
                continue;
            }
            $words = preg_split('/\s+/', trim($label));
            foreach (array_slice($words, 1) as $word) {
                $bare = trim($word, '(),.:"\'');
                if ($bare === '' || in_array($bare, $allowed, true)) {
                    continue;
                }
                TinyAssert::true(
                    $bare === Tools::strtolower($bare) || !ctype_upper($bare[0]),
                    sprintf('Label for %s is not sentence case: "%s" (offending word "%s")', $name, $label, $bare)
                );
            }
        }
    }
}
