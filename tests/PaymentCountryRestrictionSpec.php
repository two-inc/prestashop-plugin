<?php

declare(strict_types=1);

/**
 * TWO-25387 - the native per-module country allowlist was not enforced at
 * display time.
 *
 * PrestaShop lets a merchant restrict an individual payment module to a subset
 * of countries from the back office's Payment Restrictions screen; the rows land
 * in `module_country`, one per (module, shop, country). That allowlist is
 * separate from the shop's own active-country list.
 *
 * Core applied it to Two in only one place - the final order submission, which
 * controllers/front/payment.php defers to core for - and core's display-time
 * hook filter matches the table against the CONTEXTUAL country rather than the
 * cart's billing address. So a shop whose context country differed from the
 * buyer's billing country rendered the Two tile, took the buyer through the
 * whole payment step, and refused the order at the last click. That is a
 * materially worse outcome than the currency check next to it, which withholds
 * the tile.
 *
 * Contract pinned here:
 *
 *  - A billing country outside the allowlist withholds the payment option, and
 *    says so in the log - exactly like an unsupported currency.
 *  - A billing country inside the allowlist still offers it.
 *  - The BILLING (invoice) address decides, not the delivery address, because
 *    the billing address is what core matches on at submission. A gate that
 *    disagreed with core would just relocate the dead end.
 *  - The allowlist is per shop: a row belonging to another shop enables nothing
 *    here.
 *  - A genuinely empty allowlist withholds the option, because core would refuse
 *    the submission too.
 */
final class PaymentCountryRestrictionSpec
{
    /** ps_country id fixtures - see StubStore::$countries. */
    private const GB = 826;
    private const NO = 47;
    private const ES = 34;

    /** The single-shop install's shop id, as the Shop stub reports it. */
    private const SHOP = 1;

    /** The module row id, as the Module stub reports it. */
    private const MODULE = 1;

    public static function runAll(): void
    {
        self::testCountryOutsideTheAllowlistWithholdsThePaymentOption();
        self::testCountryInsideTheAllowlistKeepsThePaymentOption();
        self::testWithholdingForCountryIsLogged();
        self::testTheBillingAddressDecidesNotTheDeliveryAddress();
        self::testAnAllowlistRowForAnotherShopEnablesNothing();
        self::testAnEmptyAllowlistWithholdsThePaymentOption();
        self::testAnUnrestrictedShopIsUnaffected();
    }

    /* ===================================================================
     * Harness
     * =================================================================== */

    private static function reset(): void
    {
        StubStore::reset();
        Tools::resetTestValues();
        PrestaShopLogger::reset();
    }

    /**
     * A module whose cart clears every OTHER hookPaymentOptions gate, so the
     * only thing left that can withhold the option is the country allowlist.
     * Billing and delivery both resolve to $idCountry unless a spec splits them.
     */
    private static function offerableModule(int $idCountry): object
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        StubStore::$addresses[904] = [
            'id_country' => $idCountry,
            'company' => 'Example Trading Ltd',
            'vat_number' => 'GB123456789',
            'loaded' => true,
        ];
        StubStore::$currencies[826] = ['iso_code' => 'GBP', 'loaded' => true];
        StubStore::$moduleCurrencies['twopayment'] = [['id_currency' => 826]];

        $cart = new Cart(7387);
        $cart->id_address_invoice = 904;
        $cart->id_address_delivery = 904;
        $cart->id_currency = 826;
        $module->context->cart = $cart;

        return $module;
    }

    /** One `module_country` row for this module and this shop. */
    private static function allow(int ...$countries): array
    {
        $rows = [];
        foreach ($countries as $idCountry) {
            $rows[] = [
                'id_module' => self::MODULE,
                'id_shop' => self::SHOP,
                'id_country' => $idCountry,
            ];
        }

        return $rows;
    }

    private static function countryLogLines(): int
    {
        $lines = 0;
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'billing country not enabled') !== false) {
                ++$lines;
            }
        }

        return $lines;
    }

    /* ===================================================================
     * Gate
     * =================================================================== */

    private static function testCountryOutsideTheAllowlistWithholdsThePaymentOption(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$moduleCountries = self::allow(self::NO, self::ES);

        TinyAssert::same(
            0,
            count($module->hookPaymentOptions([])),
            'a billing country outside the module allowlist must withhold the payment option'
        );
    }

    private static function testCountryInsideTheAllowlistKeepsThePaymentOption(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$moduleCountries = self::allow(self::NO, self::GB, self::ES);

        TinyAssert::same(
            1,
            count($module->hookPaymentOptions([])),
            'a billing country inside the module allowlist must still be offered Two'
        );
    }

    /**
     * A payment method that vanishes without a trace is the failure mode the
     * currency gate next to this one already logs its way out of.
     */
    private static function testWithholdingForCountryIsLogged(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$moduleCountries = self::allow(self::NO);
        PrestaShopLogger::reset();

        $module->hookPaymentOptions([]);

        TinyAssert::same(1, self::countryLogLines(), 'hiding the payment option must say why in the log');
    }

    /**
     * The whole point of the gate is to agree with the submission check, and
     * core matches `module_country` against the INVOICE address country
     * (Module::getPaymentModules()). A delivery-address gate would offer the
     * tile on carts core then refuses - the exact bug, just reshaped.
     */
    private static function testTheBillingAddressDecidesNotTheDeliveryAddress(): void
    {
        $module = self::offerableModule(self::GB);
        // Delivery in the allowlist, billing outside it.
        StubStore::$addresses[905] = ['id_country' => self::NO, 'loaded' => true];
        $module->context->cart->id_address_delivery = 905;
        StubStore::$moduleCountries = self::allow(self::NO);

        TinyAssert::same(
            0,
            count($module->hookPaymentOptions([])),
            'an allowlisted DELIVERY country must not rescue a billing country core will refuse'
        );

        // And the mirror image, so this is not passing for some unrelated reason.
        $module = self::offerableModule(self::NO);
        StubStore::$addresses[905] = ['id_country' => self::GB, 'loaded' => true];
        $module->context->cart->id_address_delivery = 905;
        StubStore::$moduleCountries = self::allow(self::NO);

        TinyAssert::same(
            1,
            count($module->hookPaymentOptions([])),
            'an allowlisted BILLING country must be offered Two whatever the delivery country is'
        );
    }

    /**
     * `module_country` is keyed by shop as well as module. A multistore merchant
     * who enabled a country on shop 2 has not enabled it on shop 1.
     */
    private static function testAnAllowlistRowForAnotherShopEnablesNothing(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$moduleCountries = [
            ['id_module' => self::MODULE, 'id_shop' => self::SHOP + 1, 'id_country' => self::GB],
        ];

        TinyAssert::same(
            0,
            count($module->hookPaymentOptions([])),
            'an allowlist row for a different shop must not enable the country on this one'
        );
    }

    /**
     * Fail-closed, deliberately: with no rows at all core's submission check
     * finds nothing to match either, so offering the tile would only move the
     * same refusal to the last click.
     */
    private static function testAnEmptyAllowlistWithholdsThePaymentOption(): void
    {
        $module = self::offerableModule(self::GB);
        StubStore::$moduleCountries = [];

        TinyAssert::same(
            0,
            count($module->hookPaymentOptions([])),
            'an empty module_country allowlist must withhold the payment option'
        );
    }

    /**
     * The shop that has never touched the Payment Restrictions screen - every
     * active country enabled, which is what PaymentModule::install() writes.
     * Nothing about this change may narrow that shop's behaviour.
     */
    private static function testAnUnrestrictedShopIsUnaffected(): void
    {
        foreach ([self::GB, self::NO, self::ES] as $idCountry) {
            $module = self::offerableModule($idCountry);
            // StubStore::reset() leaves $moduleCountries null = unrestricted.
            TinyAssert::same(
                1,
                count($module->hookPaymentOptions([])),
                'country ' . $idCountry . ' must still be offered Two on an unrestricted shop'
            );
        }
    }
}
