<?php

declare(strict_types=1);

/**
 * ABN-518 - a payment option that is simply absent from checkout, with no log
 * line and nothing on the settings page, is diagnosable only by reading source.
 *
 * Two surfaces are pinned here: every branch of hookPaymentOptions that
 * withholds writes a line naming its reason, and the admin health panel names
 * the reason that applies without needing a cart.
 */
final class CheckoutWithholdReasonSpec
{
    public static function runAll(): void
    {
        self::testEveryWithholdingBranchNamesItsReasonInTheLog();
        self::testAWithholdReasonIsLoggedOncePerRequest();
        self::testTheProviderNameFallsBackToTheProductName();
        self::testTheActionRequiredAlertFollowsTheDefinitiveVerdict();
        self::testTheHealthChecklistNamesWhyTheMethodIsAbsent();
    }

    /**
     * The three branches that withheld silently. The currency, country,
     * minimum-order and API-key branches already logged and are pinned by
     * their own specs.
     */
    private static function testEveryWithholdingBranchNamesItsReasonInTheLog(): void
    {
        // [module mutation, log fragment, why].
        $cases = [
            [
                static function ($module): void {
                    $module->api_key = '';
                },
                'no API key is saved in the module settings',
                'an unconfigured module names what is missing',
            ],
            [
                static function ($module): void {
                    $module->merchant_short_name = '';
                },
                'the merchant account has not been identified yet',
                'a shop whose key never verified is a different cause from a missing key',
            ],
            [
                static function ($module): void {
                    Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'not-a-method');
                },
                'Payment option hidden - the saved surcharge method is not recognised',
                'an unrecognised stored surcharge method names the withhold, not only itself',
            ],
        ];

        foreach ($cases as [$mutate, $fragment, $description]) {
            $module = self::offerableModule();
            $mutate($module);
            PrestaShopLogger::reset();

            $options = $module->hookPaymentOptions([]);

            TinyAssert::true(
                $options === null || $options === [],
                $description . ': the payment option must be withheld'
            );
            TinyAssert::true(
                self::logged($fragment),
                $description . ': logged ' . self::allLogged()
            );
        }
    }

    /**
     * ABN-533: only a definitive rejection earns "action required". A transient
     * verdict falls through to the cached record and withholds nothing, so the
     * panel must not tell the merchant to go and fix their key.
     */
    private static function testTheActionRequiredAlertFollowsTheDefinitiveVerdict(): void
    {
        // [primed verdict, HTTP code, alert expected, why].
        $cases = [
            [Twopayment::API_KEY_STATUS_INVALID, 401, true, 'a rejected key is the merchant\'s to fix'],
            [Twopayment::API_KEY_STATUS_NOT_CONFIGURED, null, true, 'and so is an unconfigured one'],
            [Twopayment::API_KEY_STATUS_SERVICE_ERROR, 503, false, 'an outage is not the merchant\'s to fix'],
            [Twopayment::API_KEY_STATUS_UNREACHABLE, null, false, 'nor is a network failure reaching us'],
        ];

        foreach ($cases as [$status, $code, $expected, $description]) {
            $module = self::offerableModule();
            $module->primeTwoApiKeyStatus($status, $code);

            $html = $module->exposeTwoPluginHealthChecklist();

            TinyAssert::same(
                $expected,
                strpos($html, 'Action required:') !== false,
                $description . ': rendered ' . $html
            );
        }
    }

    /**
     * An overlay whose brand file predates provider_full_name must still name
     * somebody to contact.
     */
    private static function testTheProviderNameFallsBackToTheProductName(): void
    {
        self::offerableModule();
        // [brand config, expected name, why].
        $cases = [
            [['provider_full_name' => 'Acme Pay Ltd', 'product_name' => 'Acme Pay'], 'Acme Pay Ltd',
                'the legal name is preferred where the overlay declares one'],
            [['product_name' => 'Acme Pay'], 'Acme Pay',
                'an overlay whose brand file predates the key still names somebody'],
        ];

        foreach ($cases as [$brand, $expected, $description]) {
            $module = new class ($brand) extends TwopaymentTestHarness {
                /** @var array<string,mixed> */
                private $brand;

                public function __construct(array $brand)
                {
                    parent::__construct();
                    $this->brand = $brand;
                }

                public function getTwoBrandConfig($key)
                {
                    return array_key_exists($key, $this->brand) ? $this->brand[$key] : null;
                }

                public function exposeTwoProviderFullName(): string
                {
                    return $this->twoProviderFullName();
                }
            };

            TinyAssert::same($expected, $module->exposeTwoProviderFullName(), $description);
        }
    }

    /**
     * PrestaShop asks for payment options several times per payment-step
     * render; one cause is one line.
     */
    private static function testAWithholdReasonIsLoggedOncePerRequest(): void
    {
        $module = self::offerableModule();
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', '');
        $module->api_key = '';
        PrestaShopLogger::reset();

        $module->hookPaymentOptions([]);
        $module->hookPaymentOptions([]);
        $module->hookPaymentOptions([]);

        $lines = 0;
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'no API key is saved in the module settings') !== false) {
                $lines++;
            }
        }
        TinyAssert::same(1, $lines, 'the reason must be logged once per request, not once per evaluation');
    }

    /**
     * The settings page answers "why is it not in my checkout?" for every
     * reason that does not need a cart.
     */
    private static function testTheHealthChecklistNamesWhyTheMethodIsAbsent(): void
    {
        // [module mutation, expected panel fragment, why].
        $cases = [
            [
                static function ($module): void {
                    $module->active = false;
                },
                'Not shown at checkout - the module is not enabled for this shop. Enable it in Module Manager.',
                'a module switched off for the shop names the switch',
            ],
            [
                static function ($module): void {
                    Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', '');
                },
                'Not shown at checkout - no API key is saved. Check API key.',
                'an unconfigured install is not a rejected key',
            ],
            [
                static function ($module): void {
                    $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_INVALID, 401);
                },
                'Not shown at checkout - the API key was rejected. Check API key and Environment.',
                'a definitive rejection names both fields',
            ],
            [
                static function ($module): void {
                    $module->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_SERVICE_ERROR, 503);
                },
                'Shown at checkout',
                'ABN-533: a transient verdict falls through to the cached record and withholds nothing',
            ],
            [
                static function ($module): void {
                    Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'not-a-method');
                },
                'the saved surcharge method is not recognised. Check Surcharge method.',
                'an unrecognised stored surcharge method names its own admin field',
            ],
            [
                static function ($module): void {
                    Configuration::updateValue('PS_TWO_MERCHANT_SHORT_NAME', '');
                },
                'your merchant account has not been identified yet. Save General to verify the API key.',
                'a shop whose key never verified is withheld, and the short name is no form field',
            ],
            [
                static function ($module): void {
                    Configuration::updateValue(Twopayment::CONFIG_MERCHANT_BUYER_COUNTRIES, '[]');
                },
                'no buyer countries are currently enabled for your account. Contact Two',
                'an empty allowlist hides the method for every buyer, which no local field explains',
            ],
            [
                static function ($module): void {
                    Configuration::updateValue(Twopayment::CONFIG_MERCHANT_BUYER_COUNTRIES, 'false');
                },
                'the buyer countries on your account could not be read.',
                'an unreadable list is not a deliberate account restriction',
            ],
            [
                static function ($module): void {
                    StubStore::$moduleCountries = [];
                },
                'no country is enabled for this module under Payment > Preferences.',
                "PrestaShop's own restriction screen hides the option for every buyer",
            ],
            [
                static function ($module): void {
                    StubStore::$moduleCurrencies['twopayment'] = [];
                },
                'no currency is enabled for this module under Payment > Preferences.',
                'and the same for its currency allowlist',
            ],
            [
                static function ($module): void {
                    // Assigned, but not the shop's default: core's checkbox
                    // mode hands back the whole list, so membership decides.
                    StubStore::$moduleCurrencies['twopayment'] = [['id_currency' => 999]];
                },
                'no currency is enabled for this module under Payment > Preferences.',
                'an allowlist that excludes the default currency withholds every cart',
            ],
            [
                static function ($module): void {
                    // A second enabled currency the provider does not support:
                    // every cart in it is refused, which is per-cart and so a
                    // constraint rather than a reason.
                    StubStore::$currencies[978] = ['iso_code' => 'PLN', 'loaded' => true];
                },
                'hidden for baskets in PLN',
                'a non-default enabled currency that would be refused is named',
            ],
            [
                static function ($module): void {
                    // The default is unassigned, but a second supported one is
                    // assigned: carts in THAT currency are still offered Two.
                    StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
                    StubStore::$moduleCurrencies['twopayment'] = [['id_currency' => 978]];
                },
                'Shown at checkout - hidden for baskets in GBP',
                'a refused default currency is per-cart while another usable one remains',
            ],
            [
                static function ($module): void {
                    StubStore::$currencies[826] = ['iso_code' => '', 'loaded' => true];
                },
                'the shop default currency has no ISO code.',
                'a blank ISO gets its own wording rather than an empty placeholder',
            ],
            [
                static function ($module): void {
                    StubStore::$currencies[826] = ['iso_code' => 'PLN', 'loaded' => true];
                },
                'no currency is enabled for this module under Payment > Preferences.',
                'a shop with no usable currency at all withholds every cart',
            ],
            [
                static function ($module): void {
                    Configuration::updateValue(Twopayment::CONFIG_MERCHANT_BUYER_COUNTRIES, '["NO","GB"]');
                },
                'offered only to buyers in GB, NO',
                'a populated allowlist withholds from every other buyer, which no local field explains',
            ],
            [
                static function ($module): void {
                },
                'Shown at checkout',
                'nothing withholding it reads as shown',
            ],
            [
                static function ($module): void {
                    Configuration::updateValue(
                        Twopayment::CONFIG_PLATFORM_MIN_ORDER,
                        '{"amount":250,"currency":"EUR","basis":"net"}'
                    );
                },
                'Shown at checkout - hidden for baskets below 250.00 EUR (excluding tax)',
                'the cart-dependent gate is named as a constraint, not as the current state',
            ],
            [
                static function ($module): void {
                    Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER, 1000);
                    Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER_BASIS, 'gross');
                },
                'hidden for baskets below 1000.00 GBP (including tax)',
                'the merchant own floor binds even with no platform floor',
            ],
            [
                static function ($module): void {
                    Configuration::updateValue(
                        Twopayment::CONFIG_PLATFORM_MIN_ORDER,
                        '{"amount":250,"currency":"EUR","basis":"net"}'
                    );
                    Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER, 1000);
                    Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER_BASIS, 'gross');
                },
                '250.00 EUR (excluding tax) or 1000.00 GBP (including tax)',
                'two floors in different currencies cannot be reduced to one, so both are named',
            ],
            [
                static function ($module): void {
                    Configuration::updateValue(
                        Twopayment::CONFIG_PLATFORM_MIN_ORDER,
                        '{"amount":250,"currency":"GBP","basis":"gross"}'
                    );
                    Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER, 1000);
                    Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER_BASIS, 'gross');
                },
                'hidden for baskets below 1000.00 GBP (including tax)',
                'same currency and basis is one floor - naming both would state a bar that never binds',
            ],
            [
                static function ($module): void {
                    Configuration::updateValue(Twopayment::CONFIG_MERCHANT_RECORD_KEY, 'another-key-hash');
                },
                'minimum order value not known until your profile refreshes',
                'a key-mismatched record means the constraint is unknown, not absent',
            ],
            [
                static function ($module): void {
                    Configuration::deleteByName(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED);
                    Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER, 1000);
                    Configuration::updateValue(Twopayment::CONFIG_MERCHANT_MIN_ORDER_BASIS, 'gross');
                },
                'minimum order value not known until your profile refreshes; hidden for baskets below 1000.00 GBP',
                'a local admin floor is known even when the platform floor is not',
            ],
            [
                static function ($module): void {
                    Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
                },
                'hidden for baskets in a currency the buyer surcharge cannot be priced in',
                'whether the fee can be priced depends on the basket currency, so it is a constraint',
            ],
        ];

        foreach ($cases as [$mutate, $fragment, $description]) {
            $module = self::offerableModule();
            $mutate($module);

            $html = $module->exposeTwoPluginHealthChecklist();

            TinyAssert::true(
                strpos($html, 'Payment method at checkout') !== false,
                $description . ': the row must be rendered'
            );
            TinyAssert::true(
                strpos($html, $fragment) !== false,
                $description . ': rendered ' . $html
            );
        }
    }

    /**
     * A module every gate ahead of the branch under test lets through: shop
     * currency GBP, a GB billing address, no country restriction, no minimum.
     */
    private static function offerableModule(): object
    {
        StubStore::reset();
        Tools::resetTestValues();
        PrestaShopLogger::reset();
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'sandbox');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', '');
        Configuration::updateValue('PS_CURRENCY_DEFAULT', 826);
        // A stored merchant record; without one the row reports the floor as
        // unknown rather than naming it.
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED, '1');
        StubStore::$countries = [826 => 'GB'];

        $module = new TwopaymentTestHarness();
        $module->_path = '/modules/twopayment/';
        TwopaymentTestHarness::resetSurchargeTypeLog();

        StubStore::$currencies[826] = ['iso_code' => 'GBP', 'loaded' => true];
        StubStore::$moduleCurrencies['twopayment'] = [['id_currency' => 826]];
        StubStore::$customers[9001] = ['email' => 'buyer@example.com', 'loaded' => true];
        StubStore::$addresses[904] = ['id_country' => 826, 'loaded' => true];

        StubStore::$moduleCountries = [[
            'id_module' => (int) $module->id,
            'id_shop' => (int) $module->context->shop->id,
            'id_country' => 826,
        ]];

        $cart = new Cart(7340);
        $cart->id_customer = 9001;
        $cart->id_currency = 826;
        $cart->id_address_invoice = 904;
        $cart->id_address_delivery = 904;
        $module->context->cart = $cart;
        Context::getContext()->cart = $cart;

        return $module;
    }

    private static function logged(string $needle): bool
    {
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function allLogged(): string
    {
        $messages = [];
        foreach (PrestaShopLogger::$logs as $entry) {
            $messages[] = $entry['message'];
        }

        return '[' . implode(' | ', $messages) . ']';
    }
}
