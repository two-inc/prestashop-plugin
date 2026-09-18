<?php

declare(strict_types=1);

/**
 * The product-page promotional message (TWO-25799).
 *
 * Opt-in, and gated on the same api-key verdict every other buyer-facing
 * surface asks through, so a shop whose key Two rejected stops advertising a
 * method its buyers cannot use - while a transient blip changes nothing.
 *
 * The wording is the sentence the checkout tile already shows. Reusing that
 * source string is deliberate: the two surfaces cannot drift, and the phrase
 * is already translated in every locale the module carries.
 */
final class ProductPromoMessageSpec
{
    private const DEFAULT_COPY = 'Buy now, receive your goods, pay your invoice later.';

    public static function runAll(): void
    {
        self::testOffByDefault();
        self::testEveryGateWithholdsOnItsOwn();
        self::testATransientVerdictStillRenders();
        self::testTheOverrideWinsAndIsTrimmed();
        self::testWhitespaceOnlyOverridesFallBackToTheDefault();
        self::testANonScalarStoredValueIsAbsentRatherThanFatal();
        self::testTheProductHookIsSelfHealedOntoExistingInstalls();
        self::testTheAdminFieldsArePresentAndOptional();
        self::testQuickViewIsExcludedButACombinationRefreshIsNot();
    }

    private static function reset(): void
    {
        StubStore::reset();
        Tools::resetTestValues();
    }

    /** The whole point of an opt-in: an upgrade advertises nothing by itself. */
    private static function testOffByDefault(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::false(
            $module->isTwoProductMessageWarranted(),
            'nothing stored means nothing rendered'
        );
    }

    /**
     * Three conditions, each load-bearing on its own. A method the buyer
     * cannot use must not advertise itself from the product page.
     */
    private static function testEveryGateWithholdsOnItsOwn(): void
    {
        foreach (
            array(
                array(Twopayment::API_KEY_STATUS_INVALID, 'a key Two rejected'),
                array(Twopayment::API_KEY_STATUS_NOT_CONFIGURED, 'no key at all'),
            ) as $case
        ) {
            list($status, $why) = $case;
            self::reset();
            Configuration::updateValue('PS_TWO_PRODUCT_MESSAGE_ENABLED', 1);
            $module = new TwopaymentTestHarness();
            $module->primeTwoApiKeyStatus($status, 401);

            TinyAssert::false($module->isTwoProductMessageWarranted(), $why . ' withholds the message');
        }

        // The switch itself, with a perfectly good key behind it.
        self::reset();
        Configuration::updateValue('PS_TWO_PRODUCT_MESSAGE_ENABLED', 0);
        $module = new TwopaymentTestHarness();
        TinyAssert::false($module->isTwoProductMessageWarranted(), 'the merchant switch withholds on its own');
    }

    /**
     * TWO-25799: only the definitive categories withhold. A 500 or an
     * unreachable host says nothing about the key, and withholding on one
     * would take the message off every product page for the length of an
     * outage.
     */
    private static function testATransientVerdictStillRenders(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_PRODUCT_MESSAGE_ENABLED', 1);
        $module = new TwopaymentTestHarness();
        $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_ERROR, 500);

        TinyAssert::true(
            $module->isTwoProductMessageWarranted(),
            'a transient failure is not a verdict and withholds nothing'
        );
    }

    private static function testTheOverrideWinsAndIsTrimmed(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue('PS_TWO_PRODUCT_MESSAGE', array(1 => "  Pay  us   later \t"));

        TinyAssert::same(
            'Pay  us   later',
            $module->resolveTwoProductMessage(),
            'the merchant wording wins, trimmed at the ends but not inside'
        );
    }

    /**
     * trim() strips ASCII whitespace only. Two nonbreaking spaces would
     * otherwise pass for a real override and render a bordered badge with no
     * readable message in it.
     */
    private static function testWhitespaceOnlyOverridesFallBackToTheDefault(): void
    {
        foreach (
            array(
                '   ' => 'ordinary spaces',
                "\t\n" => 'tab and newline',
                "\u{00A0}\u{00A0}" => 'nonbreaking spaces',
                "\u{3000}" => 'an ideographic space',
                "\u{FEFF}" => 'a zero-width no-break space',
                " \u{00A0}\t\u{202F} " => 'a mixture',
            ) as $stored => $why
        ) {
            self::reset();
            $module = new TwopaymentTestHarness();
            Configuration::updateValue('PS_TWO_PRODUCT_MESSAGE', array(1 => $stored));

            TinyAssert::same(
                self::DEFAULT_COPY,
                $module->resolveTwoProductMessage(),
                $why . ' is empty, so the default copy stands'
            );
        }
    }

    /**
     * Through the REAL config path, not a stub that can only hold a string:
     * that is the only shape in which this bug is expressible. Casting an
     * array to string is a PHP warning, and on a render path that becomes a
     * notice in the page or an exception that takes the product page down.
     */
    private static function testANonScalarStoredValueIsAbsentRatherThanFatal(): void
    {
        foreach (
            array(
                'a nested array' => array(1 => array('deeper' => 'value')),
                'an object' => array(1 => new stdClass()),
            ) as $why => $stored
        ) {
            self::reset();
            $module = new TwopaymentTestHarness();
            Configuration::updateValue('PS_TWO_PRODUCT_MESSAGE', $stored);

            TinyAssert::same(
                self::DEFAULT_COPY,
                $module->resolveTwoProductMessage(),
                $why . ' reads as absent, and the default copy stands'
            );
        }
    }

    /**
     * The setting alone renders nothing on a shop installed before this
     * existed: PrestaShop only calls a hook the module is registered in.
     */
    private static function testTheProductHookIsSelfHealedOntoExistingInstalls(): void
    {
        self::reset();
        $source = file_get_contents(__DIR__ . '/../twopayment.php');
        TinyAssert::true(
            strpos($source, "'displayProductAdditionalInfo',") !== false,
            'the product hook is in the self-heal list, not only in install()'
        );
        TinyAssert::true(
            strpos($source, "\$this->registerHook('displayProductAdditionalInfo')") !== false,
            'and a fresh install registers it too'
        );
    }

    /** Two keys, never one: the switch decides rendering, the text only wording. */
    private static function testTheAdminFieldsArePresentAndOptional(): void
    {
        self::reset();
        StubStore::$languages = array(array('id_lang' => 1));
        $module = new TwopaymentTestHarness();
        $form = (new ReflectionMethod(Twopayment::class, 'getTwoCheckoutFieldsForm'))->invoke($module);

        $byName = array();
        foreach ($form['form']['input'] as $input) {
            if (isset($input['name'])) {
                $byName[$input['name']] = $input;
            }
        }

        TinyAssert::true(isset($byName['PS_TWO_PRODUCT_MESSAGE_ENABLED']), 'the switch is on the form');
        TinyAssert::same('switch', $byName['PS_TWO_PRODUCT_MESSAGE_ENABLED']['type']);
        TinyAssert::true(isset($byName['PS_TWO_PRODUCT_MESSAGE']), 'the wording override is on the form');
        TinyAssert::true(
            empty($byName['PS_TWO_PRODUCT_MESSAGE']['required']),
            'the wording override is optional - empty means "use the default", never "off"'
        );
        TinyAssert::true(
            !empty($byName['PS_TWO_PRODUCT_MESSAGE']['lang']),
            'and it is per-language, as the subtitle beside it is'
        );
    }

    /**
     * Quick View renders this hook from a fragment request whose markup lands
     * on a category page that never registered the stylesheet, so the badge
     * would appear there unstyled. The classic theme dispatches it as
     * action=quickview; quickview=1 is the direct-URL form core also honours.
     *
     * A combination refresh is the same controller over ajax and DOES get the
     * badge: its page already carries the stylesheet.
     */
    private static function testQuickViewIsExcludedButACombinationRefreshIsNot(): void
    {
        foreach (
            array(
                array(array('action' => 'quickview'), false, 'the theme\'s Quick View request'),
                array(array('quickview' => '1'), false, 'the direct-URL Quick View form'),
                array(array('action' => 'refresh'), true, 'a combination refresh'),
                array(array(), true, 'an ordinary product page view'),
            ) as $case
        ) {
            list($request, $expected, $why) = $case;

            self::reset();
            $module = new TwopaymentTestHarness();
            Configuration::updateValue('PS_TWO_PRODUCT_MESSAGE_ENABLED', 1);
            Tools::setTestValue('controller', 'product');
            foreach ($request as $key => $value) {
                Tools::setTestValue($key, $value);
            }

            // The stub smarty renders nothing, so the assignment is the
            // evidence that the hook got as far as drawing the badge.
            $module->context->smarty->assigned = array();
            $module->hookDisplayProductAdditionalInfo(array());
            $rendered = isset($module->context->smarty->assigned['two_product_message']);

            TinyAssert::same($expected, $rendered, $why . ' renders the badge: ' . var_export($expected, true));
        }
    }
}
