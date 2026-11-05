<?php

declare(strict_types=1);

/**
 * The checkout tile's call-to-action text.
 *
 * PS_TWO_TITLE is stored per language, and a language with no row of its own
 * reads as `false` rather than an empty string, which core's emptiness test
 * does not count as empty — so the default-title fallback has to be driven by
 * a string comparison rather than by Tools::isEmpty(). Nothing is stored at
 * install, and the fallback is the translated "Business invoice" (TWO-27107).
 *
 * The title is rendered verbatim and gains no term length. That matches the
 * Magento and Magento Hyva tiles, where the payment method title only picks up
 * a " - N days" suffix once the buyer's selected term is attached to the
 * payment, which never happens at tile render.
 */
final class CheckoutTitleSpec
{
    public static function runAll(): void
    {
        self::testRendersTheConfiguredTitle();
        self::testDefaultTitleIsTranslatedNotStored();
    }

    private static function moduleWithTitle($title): object
    {
        StubStore::reset();
        StubStore::$languages = [
            ['id_lang' => 1, 'iso_code' => 'en'],
            ['id_lang' => 2, 'iso_code' => 'gb'],
        ];
        // Only ever the first language, so an unset value is a language with no
        // row of its own on a shop where another language does have one.
        if ($title !== null) {
            StubStore::$configurationLang[1]['PS_TWO_TITLE'] = $title;
        }
        StubStore::$configurationLang[2]['PS_TWO_TITLE'] = 'Pay on invoice';

        $module = new TwopaymentTestHarness();
        $module->_path = '/modules/twopayment/';

        return $module;
    }

    private static function testRendersTheConfiguredTitle(): void
    {
        $cases = [
            ['Business invoice', 'Business invoice', 'the configured title is rendered verbatim, with no term length appended'],
            ['Business invoice 30 days', 'Business invoice 30 days', 'a merchant who wants a day count in the title keeps it'],
            ['Faktura & "kredit" <30 dagar>', 'Faktura & "kredit" <30 dagar>', 'quotes, ampersands and angle brackets reach core unescaped and unaltered'],
            ['Rabatt 5% på faktura', 'Rabatt 5% på faktura', 'a literal percent is not read as a format placeholder'],
            ['', 'Business invoice', 'an empty title falls back to the default title'],
            ['   ', 'Business invoice', 'a whitespace-only title falls back to the default title'],
            [null, 'Business invoice', 'a language with no title row at all falls back to the default title'],
        ];

        foreach ($cases as list($stored, $expected, $description)) {
            TinyAssert::same(
                $expected,
                self::moduleWithTitle($stored)->exposeTwoPaymentOptionTitle(),
                $description
            );
        }
    }

    /**
     * TWO-27107: install stores no English title or subtitle, and the empty
     * title's default goes through the module's translations, so each shopper
     * sees it in their own language.
     */
    private static function testDefaultTitleIsTranslatedNotStored(): void
    {
        StubStore::reset();
        StubStore::$languages = [['id_lang' => 1, 'iso_code' => 'en'], ['id_lang' => 2, 'iso_code' => 'sv']];
        $module = new TwopaymentTestHarness();
        (new ReflectionMethod(Twopayment::class, 'installTwoSettings'))->invoke($module);
        foreach (['PS_TWO_TITLE', 'PS_TWO_SUB_TITLE'] as $key) {
            foreach ([1, 2] as $idLang) {
                TinyAssert::same('', trim((string) Configuration::get($key, $idLang)), $key . ' is not stored at install for language ' . $idLang);
            }
        }

        $translating = new class extends TwopaymentTestHarness {
            public function l($string, $specific = false, $locale = null)
            {
                return '[sv] ' . $string;
            }
        };
        $translating->_path = '/modules/twopayment/';
        TinyAssert::same('[sv] Business invoice', $translating->exposeTwoPaymentOptionTitle(), 'the default title is the translated string');
    }
}
