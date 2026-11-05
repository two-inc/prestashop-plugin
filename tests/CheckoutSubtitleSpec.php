<?php

declare(strict_types=1);

/**
 * The checkout tile's subtitle.
 *
 * The field is optional and per-language, and it is plain text (TWO-27107):
 * core strips tags when the field is saved, and the template escapes whatever
 * is stored, so nothing in it becomes markup or a link,
 * and a blank field shows no subtitle at all.
 *
 * The title is a separate field, optional too: empty shows the default title.
 */
final class CheckoutSubtitleSpec
{
    private const LANGUAGES = [['id_lang' => 1], ['id_lang' => 2]];

    public static function runAll(): void
    {
        self::testFieldRequiredness();
        self::testAdminSaveStoresTheSubtitleAsTyped();
        self::testTileSubtitleIsThePlainStoredText();
        self::testTemplateEscapesTheSubtitle();
        self::testTooltipsSwitchFollowsTheOptionalFields();
    }

    /**
     * `required` is core's own flag, not just decoration: it stars the label
     * and is what the back office refuses an empty submission on, so relaxing
     * the validation alone would still leave the field unclearable.
     */
    private static function testFieldRequiredness(): void
    {
        self::reset();
        StubStore::$languages = self::LANGUAGES;
        $module = new TwopaymentTestHarness();
        $form = (new ReflectionMethod(Twopayment::class, 'getTwoCheckoutFieldsForm'))->invoke($module);

        $byName = [];
        foreach ($form['form']['input'] as $input) {
            if (isset($input['name'])) {
                $byName[$input['name']] = $input;
            }
        }

        $cases = [
            ['PS_TWO_TITLE', false, 'the title is optional: empty shows the default title'],
            ['PS_TWO_SUB_TITLE', false, 'the subtitle is optional'],
        ];

        foreach ($cases as list($name, $expected, $description)) {
            TinyAssert::same($expected, $byName[$name]['required'], $description);
        }
    }

    private static function reset(): void
    {
        StubStore::reset();
        Tools::resetTestValues();
    }

    /** The submitTwoCheckoutFieldsForm branch of getContent(), without the form rendering around it. */
    private static function savingModule(): object
    {
        return new class () extends TwopaymentTestHarness {
            /** @return array{errors: array, output: string} */
            public function submitCheckoutFields(): array
            {
                $this->errors = [];
                $this->output = '';
                $this->validTwoCheckoutFieldsFormValues();
                if (!count($this->errors)) {
                    $this->saveTwoCheckoutFieldsFormValues();
                }

                return ['errors' => $this->errors, 'output' => $this->output];
            }
        };
    }

    /**
     * Given any subtitle; When the merchant saves; Then the module stores it
     * without refusing it. On a real shop core's Configuration::updateValue()
     * strips tags on the way in; the stub here does not, so the rows below
     * check only that the module itself refuses nothing.
     */
    private static function testAdminSaveStoresTheSubtitleAsTyped(): void
    {
        $cases = [
            ['Pay by invoice', 'Pay later, interest free', '', 'Pay later, interest free', 'a filled subtitle saves unchanged'],
            ['Pay by invoice', '', '', '', 'a cleared subtitle saves as an empty row'],
            ['Pay by invoice', '   ', '', '   ', 'whitespace saves verbatim; the tile trims it away at render'],
            ['', 'Pay later, interest free', '', 'Pay later, interest free', 'an empty title no longer blocks the save'],
            ['Pay by invoice', "Don't wait & save", '', "Don't wait & save", 'an apostrophe and an ampersand save as typed'],
            [
                'Pay by invoice',
                '<a href="https://faq.example.test/x">read more</a>',
                '',
                '<a href="https://faq.example.test/x">read more</a>',
                'a link is not refused by the module',
            ],
            ['Pay by invoice', '<b>Pay</b> later', '', '<b>Pay</b> later', 'markup is no longer refused by the module'],
        ];

        foreach ($cases as list($title, $subtitle, $expectedError, $expectedStored, $description)) {
            self::reset();
            StubStore::$languages = self::LANGUAGES;
            $module = self::savingModule();
            $module->languages = self::LANGUAGES;

            foreach (self::LANGUAGES as $language) {
                Tools::setTestValue('PS_TWO_TITLE_' . $language['id_lang'], $title);
                Tools::setTestValue('PS_TWO_SUB_TITLE_' . $language['id_lang'], $subtitle);
            }

            $result = $module->submitCheckoutFields();

            TinyAssert::same(
                $expectedError === '' ? [] : [$expectedError, $expectedError],
                $result['errors'],
                $description . ' - validation errors'
            );

            if ($expectedStored === null) {
                TinyAssert::false(
                    Configuration::hasKey('PS_TWO_SUB_TITLE', 1),
                    $description . ' - nothing stored'
                );
                continue;
            }

            TinyAssert::true(
                strpos($result['output'], 'Checkout field settings are updated.') !== false,
                $description . ' - the ordinary success acknowledgement'
            );
            foreach (self::LANGUAGES as $language) {
                TinyAssert::same(
                    $expectedStored,
                    Configuration::get('PS_TWO_SUB_TITLE', $language['id_lang']),
                    $description . ' - stored for language ' . $language['id_lang']
                );
            }
        }
    }

    private static function testTileSubtitleIsThePlainStoredText(): void
    {
        // [stored subtitle, assigned subtitle, why].
        $cases = [
            ['Pay later, interest free', 'Pay later, interest free', 'a stored subtitle is shown'],
            ['0', '0', 'a subtitle of "0" is content, not emptiness'],
            ['', '', 'an empty subtitle shows none'],
            ['   ', '', 'a whitespace-only subtitle shows none'],
            [null, '', 'a language with no subtitle row at all shows none'],
            ['<a href="https://x.test">read more</a>', '<a href="https://x.test">read more</a>', 'a stored value with markup (an import) is handed over for the template to escape'],
        ];

        foreach ($cases as list($stored, $expected, $description)) {
            self::reset();
            StubStore::$languages = self::LANGUAGES;
            if ($stored !== null) {
                StubStore::$configurationLang[1]['PS_TWO_SUB_TITLE'] = $stored;
            }
            StubStore::$configurationLang[2]['PS_TWO_SUB_TITLE'] = 'Another language';

            $module = new TwopaymentTestHarness();
            $module->_path = '/modules/twopayment/';

            TinyAssert::same($expected, $module->exposeTwoPaymentOptionAssigned('subtitle'), $description);
        }
    }

    /** The template is the escaping boundary, so it must escape the subtitle and never emit it raw. */
    private static function testTemplateEscapesTheSubtitle(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__) . '/views/templates/hook/paymentinfo.tpl');
        TinyAssert::true(strpos($template, "{\$subtitle|escape:'html':'UTF-8'}") !== false, 'the subtitle is escaped as HTML');
        TinyAssert::false((bool) preg_match('/\$subtitle[^}]*nofilter/', $template), 'the subtitle is never emitted unescaped');
    }

    /**
     * TWO-27107: the tooltips switch's help speaks of "the optional checkout
     * fields above", so it sits after the four field switches.
     */
    private static function testTooltipsSwitchFollowsTheOptionalFields(): void
    {
        self::reset();
        StubStore::$languages = self::LANGUAGES;
        $form = (new ReflectionMethod(Twopayment::class, 'getTwoCheckoutFieldsForm'))->invoke(new TwopaymentTestHarness());
        $names = array_values(array_filter(array_map(static function ($input) {
            return $input['name'] ?? null;
        }, $form['form']['input'])));
        $expected = ['PS_TWO_ENABLE_INVOICE_EMAIL', 'PS_TWO_ENABLE_PO_NUMBER', 'PS_TWO_ENABLE_PROJECT', 'PS_TWO_ENABLE_DEPARTMENT', 'PS_TWO_DISPLAY_TOOLTIPS'];
        $positions = array_map(static function ($name) use ($names) {
            return array_search($name, $names, true);
        }, $expected);
        $sorted = $positions;
        sort($sorted);
        TinyAssert::same($sorted, $positions, 'field switches then tooltips, got: ' . implode(', ', $names));
    }
}
