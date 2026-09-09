<?php

declare(strict_types=1);

/**
 * The checkout tile's call-to-action text (ABN-514).
 *
 * PS_TWO_TITLE is stored per language, and a language with no row of its own
 * reads as `false` rather than an empty string, which core's emptiness test
 * does not count as empty — so the brand-name fallback has to be driven by a
 * string comparison rather than by Tools::isEmpty().
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
            ['', 'Pay with Two', 'an empty title falls back to the brand product name'],
            ['   ', 'Pay with Two', 'a whitespace-only title falls back to the brand product name'],
            [null, 'Pay with Two', 'a language with no title row at all falls back to the brand product name'],
        ];

        foreach ($cases as list($stored, $expected, $description)) {
            TinyAssert::same(
                $expected,
                self::moduleWithTitle($stored)->exposeTwoPaymentOptionTitle(),
                $description
            );
        }
    }
}
