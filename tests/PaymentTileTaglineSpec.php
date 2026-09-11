<?php

declare(strict_types=1);

/**
 * TWO-25711: the payment-tile tagline and its "What is <brand>?" explainer link
 * are brand-configured through brands/two.php 'checkout_tagline_faq_url'.
 *
 * The key carries the link TARGET only - the tagline sentence stays a
 * translated string in the template, so it is localisable. A brand declaring
 * no usable URL renders no tagline and no link, which is how a brand with no
 * FAQ page expresses that; the template asserts the absent element itself
 * (tests/js/payment-tile-tagline.test.js).
 *
 * The merchant "Show What is <brand> explainer link" setting is a narrowing
 * control over the tooltip and never a source of a link target, so the two
 * compose in one direction only.
 */
final class PaymentTileTaglineSpec
{
    public static function runAll(): void
    {
        self::testOnlyHttpUrlsSurvive();
        self::testShippedTwoBrandKeepsItsTagline();
        self::testTheTemplateReceivesTheResolvedUrlAndTheSettingSeparately();
    }

    private static function testOnlyHttpUrlsSurvive(): void
    {
        // [declared value, resolved URL, why].
        $cases = [
            ['https://brand.example/faq', 'https://brand.example/faq', 'an https URL is the ordinary case'],
            ['http://brand.example/faq', 'http://brand.example/faq', 'plain http is still a reachable page'],
            ['  https://brand.example/faq  ', 'https://brand.example/faq', 'padding a stored value does not change the target'],
            ['HTTPS://brand.example/faq', 'HTTPS://brand.example/faq', 'the scheme test is case-insensitive and leaves the URL verbatim'],
            ['', '', 'an empty declaration is a brand saying it has no FAQ page'],
            ['   ', '', 'whitespace is emptiness, not a target'],
            [null, '', 'an absent key and an explicit null are one input'],
            ['javascript:alert(1)', '', 'a script URL never reaches a buyer-facing href'],
            ['data:text/html,x', '', 'nor does an inline document'],
            ['//brand.example/faq', '', 'a protocol-relative URL declares no scheme at all'],
            ['brand.example/faq', '', 'a bare host is not a link'],
            [['https://brand.example/faq'], '', 'a brand declaring an array gets no tagline, not a type error'],
            [true, '', 'nor does any other non-string'],
        ];

        foreach ($cases as [$declared, $expected, $description]) {
            TinyAssert::same($expected, Twopayment::normalizeTwoTaglineFaqUrl($declared), $description);
        }
    }

    /** The Two brand ships a tagline; the key exists to let another brand drop it, not to drop Two's. */
    private static function testShippedTwoBrandKeepsItsTagline(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $resolved = $module->getTwoTaglineFaqUrl();

        TinyAssert::notSame('', $resolved, 'the shipped brand declares a usable FAQ URL');
        TinyAssert::same(
            'https',
            parse_url($resolved, PHP_URL_SCHEME),
            'the shipped brand FAQ URL is https'
        );
    }

    private static function testTheTemplateReceivesTheResolvedUrlAndTheSettingSeparately(): void
    {
        // [declared brand URL, stored setting, assigned URL, assigned setting, why].
        $cases = [
            ['https://brand.example/faq', '1', 'https://brand.example/faq', true, 'a URL and the setting on renders both tagline and explainer'],
            ['https://brand.example/faq', '0', 'https://brand.example/faq', false, 'the setting off leaves the tagline its URL and hides the explainer only'],
            ['', '1', '', true, 'the setting stays on and still has nothing to link to'],
            [null, '0', '', false, 'neither control offers a target'],
            ['javascript:alert(1)', '1', '', true, 'a rejected URL reaches the template as emptiness'],
        ];

        foreach ($cases as [$declared, $setting, $expectedUrl, $expectedSetting, $description]) {
            self::reset();
            Configuration::updateValue('PS_TWO_SHOW_ABOUT_LINK', $setting);
            $module = self::moduleWithBrandUrl($declared);
            $module->_path = '/modules/twopayment/';

            TinyAssert::same(
                $expectedUrl,
                $module->exposeTwoPaymentOptionAssigned('tagline_faq_url'),
                $description . ' - tagline_faq_url'
            );
            TinyAssert::same(
                $expectedSetting,
                $module->exposeTwoPaymentOptionAssigned('show_about_link'),
                $description . ' - show_about_link'
            );
        }
    }

    /** @param mixed $declared the brand's 'checkout_tagline_faq_url' */
    private static function moduleWithBrandUrl($declared): object
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
                return $key === 'checkout_tagline_faq_url'
                    ? $this->declared
                    : parent::getTwoBrandConfig($key);
            }
        };
    }

    private static function reset(): void
    {
        StubStore::reset();
        Tools::resetTestValues();
    }
}
