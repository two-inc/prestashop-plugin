<?php

declare(strict_types=1);

/**
 * The checkout tile's subtitle (ABN-554).
 *
 * The field is optional and per-language: a merchant who clears it gets an
 * empty stored row for every language, and the tile then falls back to the
 * brand's tagline - a translated sentence carrying a "read more" link to the
 * brand's 'checkout_subtitle_faq_url'. A brand declaring no such URL renders
 * no subtitle element at all.
 *
 * Whatever reaches the template is sanitised by TwoAnchorOnlyHtml
 * (TwoAnchorOnlyHtmlSpec), since the template emits it unescaped.
 *
 * The title is a separate field and is still mandatory; the last row of the
 * save table exists so relaxing the subtitle cannot quietly relax it too.
 */
final class CheckoutSubtitleSpec
{
    private const LANGUAGES = [['id_lang' => 1], ['id_lang' => 2]];

    public static function runAll(): void
    {
        self::testFieldRequiredness();
        self::testAdminSaveAcceptsAnySubtitle();
        self::testTileSubtitleFallsBackToTheBrandTagline();
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
            ['PS_TWO_TITLE', true, 'the title is mandatory'],
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

    private static function testTileSubtitleFallsBackToTheBrandTagline(): void
    {
        $faqUrl = 'https://brand.example/faq';
        $tagline = 'For all companies, <a href="' . $faqUrl . '" target="_blank" rel="noopener">read more</a>.';

        // [stored subtitle, brand FAQ URL, assigned subtitle, why].
        $cases = [
            ['Pay later, interest free', $faqUrl, 'Pay later, interest free', 'a stored subtitle wins over the brand tagline'],
            ['0', $faqUrl, '0', 'a subtitle of "0" is content, not emptiness'],
            ['', $faqUrl, $tagline, 'an empty subtitle falls back to the brand tagline'],
            ['   ', $faqUrl, $tagline, 'a whitespace-only subtitle is emptiness and falls back too'],
            [null, $faqUrl, $tagline, 'a language with no subtitle row at all falls back'],
            ['', null, '', 'a brand with no FAQ URL renders no subtitle element'],
            ['', 'javascript:alert(1)', '', 'a rejected URL is the same as none, never a dead sentence'],
            ['Pay later', null, 'Pay later', 'the merchant field is unaffected by the brand having no URL'],
            ['<b>Pay</b> later', $faqUrl, 'Pay later', 'merchant markup is reduced to what the escaper allows'],
        ];

        foreach ($cases as list($stored, $brandUrl, $expected, $description)) {
            self::reset();
            StubStore::$languages = self::LANGUAGES;
            if ($stored !== null) {
                StubStore::$configurationLang[1]['PS_TWO_SUB_TITLE'] = $stored;
            }
            StubStore::$configurationLang[2]['PS_TWO_SUB_TITLE'] = 'Another language';

            $module = self::moduleWithSubtitleFaqUrl($brandUrl);
            $module->_path = '/modules/twopayment/';

            TinyAssert::same($expected, $module->exposeTwoPaymentOptionAssigned('subtitle'), $description);
        }
    }

    /** @param mixed $declared the brand's 'checkout_subtitle_faq_url' */
    private static function moduleWithSubtitleFaqUrl($declared): object
    {
        return new class ($declared) extends TwopaymentTestHarness {
            /** @var mixed */
            private $declared;

            /** @param mixed $declared */
            public function __construct($declared)
            {
                parent::__construct();
                $this->declared = $declared;
            }

            public function getTwoBrandConfig($key)
            {
                return $key === 'checkout_subtitle_faq_url'
                    ? $this->declared
                    : parent::getTwoBrandConfig($key);
            }
        };
    }
}
