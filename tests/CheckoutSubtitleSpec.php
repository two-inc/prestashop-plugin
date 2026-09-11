<?php

declare(strict_types=1);

/**
 * The checkout tile's subtitle (TWO-25711).
 *
 * The subtitle is optional: a merchant who clears it gets an empty stored row
 * for every language and a tile with no subtitle element at all. There is no
 * brand-default fallback - the tile's strapline in that state is the brand
 * tagline in paymentinfo.tpl, which is not merchant-configurable.
 *
 * The title is a separate field and is still mandatory; the last row of the
 * save table exists so relaxing the subtitle cannot quietly relax it too.
 */
final class CheckoutSubtitleSpec
{
    private const LANGUAGES = [['id_lang' => 1], ['id_lang' => 2]];

    public static function runAll(): void
    {
        self::testAdminSaveAcceptsAnySubtitle();
        self::testTileSubtitleHasNoFallback();
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

    private static function testAdminSaveAcceptsAnySubtitle(): void
    {
        $cases = [
            ['Pay by invoice', 'Pay later, interest free', '', 'Pay later, interest free', 'a filled subtitle saves unchanged'],
            ['Pay by invoice', '', '', '', 'a cleared subtitle saves as an empty row'],
            ['Pay by invoice', '   ', '', '   ', 'whitespace saves verbatim; the tile trims it away at render'],
            ['', 'Pay later, interest free', 'Enter a title.', null, 'the title is still mandatory, so the form saves nothing'],
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

    private static function testTileSubtitleHasNoFallback(): void
    {
        $cases = [
            ['Pay later, interest free', 'Pay later, interest free', 'a stored subtitle reaches the template verbatim'],
            ['0', '0', 'a subtitle of "0" is content, not emptiness'],
            ['', '', 'an empty subtitle stays empty - no brand default is substituted'],
            ['   ', '', 'a whitespace-only subtitle resolves to empty'],
            [null, '', 'a language with no subtitle row at all resolves to empty'],
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

            TinyAssert::same($expected, $module->exposeTwoPaymentOptionSubtitle(), $description);
        }
    }
}
