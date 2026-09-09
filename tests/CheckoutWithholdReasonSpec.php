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
                    $module->active = false;
                },
                'the module is not enabled for this shop',
                'a module switched off for the shop names the switch',
            ],
            [
                static function ($module): void {
                    $module->api_key = '';
                },
                'no API key or merchant short name is saved',
                'an unconfigured module names what is missing',
            ],
            [
                static function ($module): void {
                    Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'not-a-method');
                },
                'the buyer surcharge cannot be priced',
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
                'Not shown at checkout - the module is not enabled for this shop.',
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
                'Cannot be checked - the API key could not be verified just now.',
                'a transient verdict must not be reported as the method being withheld (ABN-533)',
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
                    Configuration::updateValue(Twopayment::CONFIG_MERCHANT_BUYER_COUNTRIES, '[]');
                },
                'your account allows no buyer countries.',
                'an empty allowlist hides the method for every buyer, which no local field explains',
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
                'Shown at checkout - hidden for baskets below 250.00 EUR (net)',
                'the cart-dependent gate is named as a constraint, not as the current state',
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
