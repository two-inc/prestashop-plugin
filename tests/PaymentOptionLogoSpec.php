<?php

declare(strict_types=1);

/**
 * The brand's mark on the checkout payment option (TWO-26609).
 *
 * Core renders PaymentOption::setLogo() beside the method name in the payment
 * step's radio label, where the WooCommerce and Magento plugins already show
 * the brand's mark. The mark is resolved through brands/two.php 'logo', the
 * same module-relative path the product-page badge uses, so an overlay swaps
 * it and a brand declaring none renders the title alone.
 */
final class PaymentOptionLogoSpec
{
    public static function runAll(): void
    {
        self::testSetsTheBrandLogo();
        self::testTheDefaultBrandLogoShips();
    }

    /**
     * @param mixed  $declared the brand's 'logo'
     * @param string $path     the module's base URI, as core sets $this->_path
     */
    private static function moduleWithLogo($declared, string $path): object
    {
        StubStore::reset();
        StubStore::$languages = [['id_lang' => 1, 'iso_code' => 'en']];
        StubStore::$configurationLang[1]['PS_TWO_TITLE'] = 'Business invoice';

        $module = new class ($declared) extends TwopaymentTestHarness {
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
                return $key === 'logo' ? $this->declared : parent::getTwoBrandConfig($key);
            }

            /** @return mixed */
            public function exposeTwoPaymentOptionLogo()
            {
                return $this->getTwoPaymentOption()->getLogo();
            }
        };
        $module->_path = $path;

        return $module;
    }

    private static function testSetsTheBrandLogo(): void
    {
        $two = (new TwopaymentTestHarness())->getTwoBrandConfig('logo');
        $cases = [
            [$two, '/modules/twopayment/', '/modules/twopayment/views/img/TwoLogo.svg', 'the Two brand sets its own mark'],
            [$two, '/shop/modules/twopayment/', '/shop/modules/twopayment/views/img/TwoLogo.svg', 'a shop in a subdirectory keeps its base URI'],
            ['views/img/overlay.svg', '/modules/twopayment/', '/modules/twopayment/views/img/overlay.svg', 'an overlay brand sets its own mark'],
            ['/views/img/overlay.svg', '/modules/twopayment/', '/modules/twopayment/views/img/overlay.svg', 'a leading slash does not double up'],
            [null, '/modules/twopayment/', null, 'a brand declaring no mark sets no logo'],
            ['', '/modules/twopayment/', null, 'an empty mark sets no logo'],
        ];

        foreach ($cases as list($declared, $path, $expected, $description)) {
            TinyAssert::same($expected, self::moduleWithLogo($declared, $path)->exposeTwoPaymentOptionLogo(), $description);
        }
    }

    private static function testTheDefaultBrandLogoShips(): void
    {
        $logo = (string) (new TwopaymentTestHarness())->getTwoBrandConfig('logo');

        TinyAssert::same(true, $logo !== '' && is_file(dirname(__DIR__) . '/' . $logo), 'the Two brand mark is shipped with the module');
    }
}
