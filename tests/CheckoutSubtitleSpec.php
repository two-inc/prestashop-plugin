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
        self::testAdminSaveRejectsWhatTheTileWouldStrip();
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

    /**
     * Given a subtitle the escaper would rewrite; When the merchant saves;
     * Then the save is refused with a message naming the rendered form,
     * rather than the copy vanishing silently at checkout (ABN-554).
     */
    private static function testAdminSaveRejectsWhatTheTileWouldStrip(): void
    {
        $rejection = 'Subtitle accepts plain text and a single link only; "%s" would be shown as "%s".';

        $cases = [
            ['Pay by invoice', 'Pay later, interest free', '', 'Pay later, interest free', 'a filled subtitle saves unchanged'],
            ['Pay by invoice', '', '', '', 'a cleared subtitle saves as an empty row'],
            ['Pay by invoice', '   ', '', '   ', 'whitespace saves verbatim; the tile trims it away at render'],
            ['', 'Pay later, interest free', 'Enter a title.', null, 'the title is still mandatory, so the form saves nothing'],
            [
                'Pay by invoice',
                "Don't wait & save",
                '',
                "Don't wait & save",
                'an apostrophe and an ampersand are plain text, not markup to reject',
            ],
            [
                'Pay by invoice',
                '<a href="https://faq.example.test/x">read more</a>',
                '',
                '<a href="https://faq.example.test/x">read more</a>',
                'the one link the tile allows saves unchanged',
            ],
            [
                'Pay by invoice',
                '<b>Pay</b> later',
                sprintf($rejection, '&lt;b&gt;Pay&lt;/b&gt; later', 'Pay later'),
                null,
                'a dropped tag is refused, and nothing is stored',
            ],
            [
                'Pay by invoice',
                '<a href="https://faq.example.test/x" onclick="steal()">read more</a>',
                sprintf(
                    $rejection,
                    '&lt;a href=&quot;https://faq.example.test/x&quot; onclick=&quot;steal()&quot;&gt;read more&lt;/a&gt;',
                    '&lt;a href=&quot;https://faq.example.test/x&quot;&gt;read more&lt;/a&gt;'
                ),
                null,
                'a link stripped of an attribute is refused rather than quietly rewritten',
            ],
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

        // [stored subtitle, brand FAQ URL, tagline translation (null = shipped), assigned subtitle, why].
        $cases = [
            ['Pay later, interest free', $faqUrl, null, 'Pay later, interest free', 'a stored subtitle wins over the brand tagline'],
            ['0', $faqUrl, null, '0', 'a subtitle of "0" is content, not emptiness'],
            ['', $faqUrl, null, $tagline, 'an empty subtitle falls back to the brand tagline'],
            ['   ', $faqUrl, null, $tagline, 'a whitespace-only subtitle is emptiness and falls back too'],
            [null, $faqUrl, null, $tagline, 'a language with no subtitle row at all falls back'],
            ['', null, null, '', 'a brand with no FAQ URL renders no subtitle element'],
            ['', 'javascript:alert(1)', null, '', 'a rejected URL is the same as none, never a dead sentence'],
            ['Pay later', null, null, 'Pay later', 'the merchant field is unaffected by the brand having no URL'],
            ['<b>Pay</b> later', $faqUrl, null, 'Pay later', 'merchant markup is reduced to what the escaper allows'],
            ['<b> </b>', $faqUrl, null, $tagline, 'copy whose only content is markup the escaper drops is emptiness too'],
            [
                '',
                $faqUrl,
                'For all companies, %1$sread more%2$s.<img src=x onerror=alert(1)>',
                $tagline,
                'a translation is copy like any other: markup in it reaches the page only through the escaper',
            ],
        ];

        foreach ($cases as list($stored, $brandUrl, $translated, $expected, $description)) {
            self::reset();
            StubStore::$languages = self::LANGUAGES;
            if ($stored !== null) {
                StubStore::$configurationLang[1]['PS_TWO_SUB_TITLE'] = $stored;
            }
            StubStore::$configurationLang[2]['PS_TWO_SUB_TITLE'] = 'Another language';

            $module = self::moduleWithSubtitleFaqUrl($brandUrl, $translated);
            $module->_path = '/modules/twopayment/';

            TinyAssert::same($expected, $module->exposeTwoPaymentOptionAssigned('subtitle'), $description);
        }
    }

    /**
     * @param mixed $declared the brand's 'checkout_subtitle_faq_url'
     * @param string|null $translated stands in for the tagline's translated
     *                    sentence, null to use the shipped one
     */
    private static function moduleWithSubtitleFaqUrl($declared, $translated = null): object
    {
        return new class ($declared, $translated) extends TwopaymentTestHarness {
            /** @var mixed */
            private $declared;

            /** @var string|null */
            private $translated;

            /**
             * @param mixed $declared
             * @param string|null $translated
             */
            public function __construct($declared, $translated = null)
            {
                parent::__construct();
                $this->declared = $declared;
                $this->translated = $translated;
            }

            public function getTwoBrandConfig($key)
            {
                return $key === 'checkout_subtitle_faq_url'
                    ? $this->declared
                    : parent::getTwoBrandConfig($key);
            }

            public function l($string)
            {
                if ($this->translated !== null && strpos($string, 'For all companies,') === 0) {
                    return $this->translated;
                }

                return parent::l($string);
            }
        };
    }
}
