<?php

declare(strict_types=1);

/**
 * The checkout tile's call-to-action text.
 *
 * PS_TWO_TITLE is stored per language, and a language with no row of its own
 * reads as `false` rather than an empty string, which core's emptiness test
 * does not count as empty, so the default-title fallback has to be driven by
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
        self::testUpgrade2722ClearsOnlyTheUntouchedEnglishTitle();
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
            ['Business invoice, 60 days', 'Business invoice, 60 days', 'a merchant who wants a day count in the title keeps it'],
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

    /**
     * TWO-27107: upgrade 2.7.22 clears a title still holding one of the
     * English titles earlier installs stored in every language, on every
     * shop tier, so those shops get the per-language default. A title the
     * merchant edited stays, and the subtitle is never touched (blank shows
     * none). Running it twice changes nothing more.
     */
    private static function testUpgrade2722ClearsOnlyTheUntouchedEnglishTitle(): void
    {
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.22.php';
        $sub = 'Buy now, pay later - instant credit';
        // [multistore, global tier [id_lang => [title, subtitle]] (null = no row),
        //  shop 3's own tier [id_lang => title] (null = no row), expected global,
        //  expected shop 3, description]
        $cases = [
            [false, [1 => ['Business invoice', $sub], 2 => ['Business invoice', $sub]], [], [1 => ['', $sub], 2 => ['', $sub]], [], 'the current default title is cleared and the subtitle kept'],
            [false, [1 => ['Business invoice 30 days', $sub], 2 => ['Business invoice', $sub]], [], [1 => ['', $sub], 2 => ['', $sub]], [], 'the older default title is cleared too'],
            [false, [1 => ['Pay by invoice', $sub], 2 => ['Business invoice', 'Betala senare']], [], [1 => ['Pay by invoice', $sub], 2 => ['', 'Betala senare']], [], 'an edited title stays, per language'],
            [false, [1 => ['Business invoice, 60 days', null], 2 => ['business invoice', null]], [], [1 => ['Business invoice, 60 days', null], 2 => ['business invoice', null]], [], 'only an exact match is a default'],
            [false, [1 => [null, null], 2 => ['Business invoice', null]], [], [1 => [null, null], 2 => ['', null]], [], 'a language with no row gets none'],
            [true, [1 => ['Pay by invoice', $sub], 2 => ['Business invoice', $sub]], [1 => 'Business invoice 30 days', 2 => 'Faktura'], [1 => ['Pay by invoice', $sub], 2 => ['', $sub]], [1 => '', 2 => 'Faktura'], 'a shop tier is cleared on its own terms under multistore'],
        ];
        foreach ($cases as [$multistore, $global, $shopTier, $expectedGlobal, $expectedShop, $description]) {
            foreach ([1, 2] as $run) {
                if ($run === 1) {
                    StubStore::reset();
                    StubStore::$languages = [['id_lang' => 1, 'iso_code' => 'en'], ['id_lang' => 2, 'iso_code' => 'sv']];
                    StubStore::$multistore = $multistore;
                    foreach ($global as $idLang => [$t, $st]) {
                        if ($t !== null) {
                            StubStore::$configurationLang[$idLang]['PS_TWO_TITLE'] = $t;
                        }
                        if ($st !== null) {
                            StubStore::$configurationLang[$idLang]['PS_TWO_SUB_TITLE'] = $st;
                        }
                    }
                    if ($shopTier !== []) {
                        // The shop's own configuration row, which the tier query finds.
                        StubStore::$shops[3] = 1;
                        StubStore::$configurationShop[3]['PS_TWO_TITLE'] = '';
                        foreach ($shopTier as $idLang => $t) {
                            StubStore::$configurationLangShop[3][$idLang]['PS_TWO_TITLE'] = $t;
                        }
                    }
                }
                $label = $description . ($run === 2 ? ' (rerun)' : '');
                TinyAssert::true(upgrade_module_2_7_22(new TwopaymentTestHarness()), $label . ': the upgrade succeeds');
                foreach ($expectedGlobal as $idLang => [$t, $st]) {
                    foreach (['PS_TWO_TITLE' => $t, 'PS_TWO_SUB_TITLE' => $st] as $key => $want) {
                        TinyAssert::same(
                            $want,
                            Configuration::hasKey($key, $idLang) ? (string) Configuration::get($key, $idLang) : null,
                            $label . ': ' . $key . ' for language ' . $idLang
                        );
                    }
                }
                foreach ($expectedShop as $idLang => $want) {
                    TinyAssert::same($want, (string) Configuration::get('PS_TWO_TITLE', $idLang, null, 3), $label . ': shop 3 title for language ' . $idLang);
                }
            }
        }
    }
}
