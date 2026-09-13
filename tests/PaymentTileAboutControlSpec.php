<?php

declare(strict_types=1);

/**
 * ABN-554: the payment-tile "What is <brand>?" icon link is brand-configured
 * through brands/two.php 'about_url'.
 *
 * The key carries the link TARGET only - the copy stays translated strings in
 * the template, so it is localisable. A brand declaring no usable URL renders
 * no icon and no link, which is how a brand with no about page expresses that;
 * the template asserts the absent element itself
 * (tests/js/payment-tile-about-control.test.js).
 *
 * The merchant "Show What is <brand> explainer link" setting is a narrowing
 * control over the icon and never a source of a link target, so the two
 * compose in one direction only.
 */
final class PaymentTileAboutControlSpec
{
    public static function runAll(): void
    {
        self::testOnlyHttpUrlsSurvive();
        self::testTheTemplateReceivesTheResolvedUrlAndTheSettingSeparately();
        self::testTheAboutUrlReachesTheTemplateSeparately();
    }

    /**
     * ABN-554: the "What is <brand>?" icon link has its own brand key, the
     * canonical /what-is-two target the other platforms use, and a brand
     * declaring none leaves the template nothing to render.
     */
    private static function testTheAboutUrlReachesTheTemplateSeparately(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $module->_path = '/modules/twopayment/';

        TinyAssert::same(
            'https://www.two.inc/what-is-two',
            $module->getTwoAboutUrl(),
            'the shipped brand declares the canonical about URL'
        );
        TinyAssert::same(
            'https://www.two.inc/what-is-two',
            $module->exposeTwoPaymentOptionAssigned('about_url'),
            'the template receives it as its own variable'
        );

        self::reset();
        $unset = self::moduleWithoutAboutUrl();
        $unset->_path = '/modules/twopayment/';
        TinyAssert::same(
            '',
            $unset->exposeTwoPaymentOptionAssigned('about_url'),
            'a brand declaring no about URL leaves no target to render'
        );
    }

    private static function moduleWithoutAboutUrl(): object
    {
        return new class extends TwopaymentTestHarness {
            public function getTwoBrandConfig($key)
            {
                return $key === 'about_url' ? null : parent::getTwoBrandConfig($key);
            }
        };
    }

    private static function testOnlyHttpUrlsSurvive(): void
    {
        // [declared value, resolved URL, why].
        $cases = [
            ['https://brand.example/about', 'https://brand.example/about', 'an https URL is the ordinary case'],
            ['http://brand.example/about', 'http://brand.example/about', 'plain http is still a reachable page'],
            ['  https://brand.example/about  ', 'https://brand.example/about', 'padding a stored value does not change the target'],
            ['HTTPS://brand.example/about', 'HTTPS://brand.example/about', 'the scheme test is case-insensitive and leaves the URL verbatim'],
            ['', '', 'an empty declaration is a brand saying it has no about page'],
            ['   ', '', 'whitespace is emptiness, not a target'],
            [null, '', 'an absent key and an explicit null are one input'],
            ['javascript:alert(1)', '', 'a script URL never reaches a buyer-facing href'],
            ['data:text/html,x', '', 'nor does an inline document'],
            ['//brand.example/about', '', 'a protocol-relative URL declares no scheme at all'],
            ['brand.example/about', '', 'a bare host is not a link'],
            [['https://brand.example/about'], '', 'a brand declaring an array gets no icon, not a type error'],
            [true, '', 'nor does any other non-string'],
        ];

        foreach ($cases as [$declared, $expected, $description]) {
            TinyAssert::same($expected, Twopayment::normalizeTwoBrandUrl($declared), $description);
        }
    }

    private static function testTheTemplateReceivesTheResolvedUrlAndTheSettingSeparately(): void
    {
        // [declared brand URL, stored setting, assigned URL, assigned setting, why].
        $cases = [
            ['https://brand.example/about', '1', 'https://brand.example/about', true, 'a URL and the setting on renders the explainer'],
            ['https://brand.example/about', '0', 'https://brand.example/about', false, 'the setting off hides the explainer while the target stands'],
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
                $module->exposeTwoPaymentOptionAssigned('about_url'),
                $description . ' - about_url'
            );
            TinyAssert::same(
                $expectedSetting,
                $module->exposeTwoPaymentOptionAssigned('show_about_link'),
                $description . ' - show_about_link'
            );
        }
    }

    /** @param mixed $declared the brand's 'about_url' */
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
                return $key === 'about_url'
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
