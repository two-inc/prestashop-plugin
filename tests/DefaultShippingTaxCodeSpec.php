<?php

declare(strict_types=1);

/**
 * TWO-25200 - the "Default shipping tax code" setting.
 *
 * PrestaShop declares shipping VAT on the carrier row
 * (`carrier_tax_rules_group_shop`) and nowhere else. A merchant pricing
 * shipping outside the carrier table - third-party carrier modules,
 * click-and-collect, marketplace shipping, `id_carrier = 0` - has nowhere in
 * core to declare it, and core discards the whole delivery-option list on
 * that sentinel, so TWO-25161's carrier lookup has nothing to read and the
 * order is refused.
 *
 * The field is always visible in Order Management, like every other setting
 * on that tab - no build-time flag, no runtime "hide until configured" gate.
 * This spec covers the two things about it that are easy to get wrong:
 *
 *   1. UNSET must be byte-for-byte the pre-TWO-25200 loud refusal. There is
 *      no default value and nothing seeds one.
 *   2. Resolution order: the carrier's own declared group always wins over
 *      the default, and a selection pointing at a deleted group is treated
 *      as unset rather than silently relayed at 0%.
 */
final class DefaultShippingTaxCodeSpec
{
    /** The config key the field stores into. */
    private const CONFIG_KEY = 'PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP';

    /** The log fragment that proves the fallback was actually taken. */
    private const FALLBACK_LOG = 'assuming the configured Default shipping tax code';

    public static function runAll(): void
    {
        self::testFieldIsAlwaysRendered();
        self::testFieldPreSelectsNothingWhenUnset();
        self::testFieldPreSelectsTheStoredGroup();
        self::testInactiveStoredGroupStaysInTheOptionList();
        self::testSaveStoresAnExplicitSelection();
        self::testSaveStoresNoTaxOnlyWhenSubmitted();
        self::testSaveClearsTheSelectionWhenSubmittedBlank();
        self::testSaveOmittingTheFieldNeverWipesAStoredSelection();
        self::testValidationRejectsGarbageAndUnknownGroups();
        self::testValidationAcceptsBlankAndNoTax();

        self::testUnsetDefaultStillRefusesCarrierlessCartLoudly();
        self::testCarrierlessCartRelaysTheDefaultShippingTaxCode();
        self::testNoTaxDefaultRelaysAnExplicitZeroRate();
        self::testCarrierDeclaredGroupWinsOverTheDefault();
        self::testDeletedDefaultGroupRefusesRatherThanRelayZero();
        self::testRefusalLogDropsToWarningWhenADefaultIsConfigured();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
    }

    /** Did any log line mention this fragment? */
    private static function loggedContains(string $needle): bool
    {
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos((string) $entry['message'], $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Severity of the first log line mentioning this fragment, or -1. */
    private static function loggedSeverity(string $needle): int
    {
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos((string) $entry['message'], $needle) !== false) {
                return (int) $entry['severity'];
            }
        }

        return -1;
    }

    /** Every input name the Order Management form renders. */
    private static function orderManagementFieldNames(DefaultShippingTaxCodeHarness $module): array
    {
        $names = [];
        foreach ($module->exposeOrderManagementForm()['form']['input'] as $input) {
            $names[] = (string) ($input['name'] ?? '');
        }

        return $names;
    }

    /** The rendered default-shipping-tax-code input. */
    private static function shippingTaxInput(DefaultShippingTaxCodeHarness $module): ?array
    {
        foreach ($module->exposeOrderManagementForm()['form']['input'] as $input) {
            if ((string) ($input['name'] ?? '') === self::CONFIG_KEY) {
                return $input;
            }
        }

        return null;
    }

    /** Shared buyer/currency/address fixture for a carrier-less ES cart. */
    private static function seedCommonFixtures(int $cartId, int $addressId): void
    {
        StubStore::$customers[$cartId] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Pia',
            'lastname' => 'Sol',
            'secure_key' => 'secure-key-' . $cartId,
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[$addressId] = [
            'id_country' => 34,
            'company' => 'LOGISTICS SHOP',
            'companyid' => 'E20468708',
            'address1' => 'Calle Uno 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '666666601',
            'loaded' => true,
        ];
    }

    /** Single 21% product line, 100.00 net / 121.00 gross. */
    private static function seedProductLine(Cart $cart, int $productId): void
    {
        StubStore::$cartProducts[$cart->id] = [[
            'id_product' => $productId,
            'link_rewrite' => 'logistics-product',
            'name' => 'Logistics Product',
            'description_short' => 'Product',
            'manufacturer_name' => 'ACME',
            'ean13' => '',
            'upc' => '',
            'total' => 100.00,
            'total_wt' => 121.00,
            'cart_quantity' => 1,
            'rate' => 21.0,
            'price' => 100.00,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[$productId] = [['name' => 'General']];
        StubStore::$images[$productId] = ['id_image' => $productId];
        StubStore::$products[$productId]['id_tax_rules_group'] = 9000 + $productId;
        StubStore::$taxRuleRates[9000 + $productId] = 21.0;
    }

    /**
     * The custom-logistics shape: a cart with a real shipping cost, `id_carrier = 0`, and no
     * delivery-option list at all - which is what core hands back once
     * getDeliveryOptionList() has discarded the no-available-carrier sentinel.
     * No carrier exists to declare a shipping tax-rules group.
     */
    private static function seedCarrierlessCart(int $cartId, int $addressId, int $productId): Cart
    {
        self::seedCommonFixtures($cartId, $addressId);
        $cart = new Cart($cartId);
        $cart->id_customer = $cartId;
        $cart->id_currency = 978;
        $cart->id_address_invoice = $addressId;
        $cart->id_address_delivery = $addressId;
        $cart->id_lang = 1;
        $cart->id_carrier = 0;
        self::seedProductLine($cart, $productId);
        // StubStore::$cartDeliveryOptionLists intentionally left empty, and
        // StubStore::$carriers too, so `new Carrier(0)` does not load either.

        return $cart;
    }

    /** Cart totals for 29.00 gross shipping taxed at 21% (net 23.9669). */
    private static function seedTotalsFor21PercentShipping(int $cartId): void
    {
        StubStore::$cartTotals[$cartId] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 29.00,
                Cart::BOTH => 150.00,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 23.9669,
                Cart::BOTH => 123.9669,
            ],
        ];
    }

    private static function merchantUrls(): array
    {
        return [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ];
    }

    /** The SHIPPING_FEE lines of a built payload. */
    private static function shippingLines(array $payload): array
    {
        $lines = [];
        foreach ($payload['line_items'] as $line) {
            if ((string) ($line['type'] ?? '') === 'SHIPPING_FEE') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    // -----------------------------------------------------------------
    // Field presence and admin form behaviour
    // -----------------------------------------------------------------

    private static function testFieldIsAlwaysRendered(): void
    {
        self::reset();
        StubStore::$taxRulesGroups[4210] = ['name' => 'IVA 21%', 'active' => 1];
        StubStore::$taxRulesGroups[4100] = ['name' => 'IVA 10%', 'active' => 1];

        $module = new DefaultShippingTaxCodeHarness();
        $names = self::orderManagementFieldNames($module);
        TinyAssert::true(in_array(self::CONFIG_KEY, $names, true), 'The field must always render on Order Management');
        // The rest of Order Management is untouched.
        TinyAssert::true(in_array('PS_TWO_FINALIZE_PURCHASE', $names, true));
        TinyAssert::true(in_array('PS_TWO_ENABLE_TAX_SUBTOTALS', $names, true));

        $input = self::shippingTaxInput($module);
        TinyAssert::true($input !== null);
        TinyAssert::same('select', (string) $input['type']);
        TinyAssert::same('Default shipping tax code', (string) $input['label']);
        // The help text has to carry the semantics - this is the only place a
        // merchant learns the field is not a blanket shipping tax override.
        TinyAssert::true(
            strpos((string) $input['desc'], 'ASSUMED FOR SHIPPING ONLY') !== false,
            'The help text must state that the group applies to shipping only'
        );
        TinyAssert::true(
            strpos((string) $input['desc'], 'cannot be resolved') !== false,
            'The help text must state the unresolvable-carrier precondition'
        );

        $ids = array_column($input['options']['query'], 'id');
        // Placeholder first, then core's "No tax" sentinel, then the shop's
        // groups. Ids are STRINGS so PHP 7's `'' == 0` cannot conflate the
        // unselected placeholder with "No tax".
        TinyAssert::same('', $ids[0]);
        TinyAssert::same('0', $ids[1]);
        TinyAssert::true(in_array('4210', $ids, true));
        TinyAssert::true(in_array('4100', $ids, true));
        foreach ($ids as $id) {
            TinyAssert::true(is_string($id), 'Option ids must be strings, never ints');
        }
    }

    private static function testFieldPreSelectsNothingWhenUnset(): void
    {
        self::reset();
        $module = new DefaultShippingTaxCodeHarness();

        TinyAssert::same(
            '',
            (string) $module->exposeOrderManagementFormValues()[self::CONFIG_KEY],
            'An unconfigured field must pre-select the placeholder - never "No tax", which is a real treatment'
        );
    }

    private static function testFieldPreSelectsTheStoredGroup(): void
    {
        self::reset();
        StubStore::$taxRulesGroups[4210] = ['name' => 'IVA 21%', 'active' => 1];
        Configuration::updateValue(self::CONFIG_KEY, '4210');

        $module = new DefaultShippingTaxCodeHarness();
        TinyAssert::same('4210', (string) $module->exposeOrderManagementFormValues()[self::CONFIG_KEY]);
    }

    /**
     * A stale selection that dropped out of the active list must stay in the
     * dropdown, or the browser submits the first option (the placeholder) on
     * the next unrelated save and silently unsets the treatment.
     */
    private static function testInactiveStoredGroupStaysInTheOptionList(): void
    {
        self::reset();
        StubStore::$taxRulesGroups[4210] = ['name' => 'IVA 21%', 'active' => 1];
        StubStore::$taxRulesGroups[4900] = ['name' => 'Retired group', 'active' => 0];
        Configuration::updateValue(self::CONFIG_KEY, '4900');

        $module = new DefaultShippingTaxCodeHarness();
        $input = self::shippingTaxInput($module);
        TinyAssert::true($input !== null);

        $byId = [];
        foreach ($input['options']['query'] as $option) {
            $byId[(string) $option['id']] = (string) $option['name'];
        }
        TinyAssert::true(isset($byId['4900']), 'A deactivated but configured group must stay selectable');
        TinyAssert::same('Retired group (inactive)', $byId['4900']);
        TinyAssert::same('4900', (string) $module->exposeOrderManagementFormValues()[self::CONFIG_KEY]);
    }

    private static function testSaveStoresAnExplicitSelection(): void
    {
        self::reset();
        StubStore::$taxRulesGroups[4210] = ['name' => 'IVA 21%', 'active' => 1];

        $module = new DefaultShippingTaxCodeHarness();
        Tools::setTestValue(self::CONFIG_KEY, '4210');
        $module->exposeSaveOrderManagementFormValues();

        TinyAssert::same('4210', (string) Configuration::get(self::CONFIG_KEY));
    }

    /** '0' ("No tax") is stored only because the merchant chose it. */
    private static function testSaveStoresNoTaxOnlyWhenSubmitted(): void
    {
        self::reset();
        $module = new DefaultShippingTaxCodeHarness();
        Tools::setTestValue(self::CONFIG_KEY, '0');
        $module->exposeSaveOrderManagementFormValues();

        TinyAssert::same('0', (string) Configuration::get(self::CONFIG_KEY));
    }

    /** Going back to the placeholder is a legitimate move: refuse again. */
    private static function testSaveClearsTheSelectionWhenSubmittedBlank(): void
    {
        self::reset();
        StubStore::$taxRulesGroups[4210] = ['name' => 'IVA 21%', 'active' => 1];
        Configuration::updateValue(self::CONFIG_KEY, '4210');

        $module = new DefaultShippingTaxCodeHarness();
        Tools::setTestValue(self::CONFIG_KEY, '');
        $module->exposeSaveOrderManagementFormValues();

        TinyAssert::same('', (string) Configuration::get(self::CONFIG_KEY));
    }

    /**
     * THE BUG THIS GUARD PREVENTS. A raw request that omits the field
     * entirely (a stale form, a hand-crafted POST) must not wipe the stored
     * declaration - same class of bug the payment-term checkbox loop was
     * fixed for under TWO-24813.
     */
    private static function testSaveOmittingTheFieldNeverWipesAStoredSelection(): void
    {
        self::reset();
        StubStore::$taxRulesGroups[4210] = ['name' => 'IVA 21%', 'active' => 1];
        Configuration::updateValue(self::CONFIG_KEY, '4210');

        $module = new DefaultShippingTaxCodeHarness();
        // A save request that never mentions this key at all.
        Tools::setTestValue('PS_TWO_FINALIZE_PURCHASE', 1);
        $module->exposeSaveOrderManagementFormValues();

        TinyAssert::same(
            '4210',
            (string) Configuration::get(self::CONFIG_KEY),
            'A save that never posted this field must leave the stored selection alone'
        );
    }

    private static function testValidationRejectsGarbageAndUnknownGroups(): void
    {
        self::reset();
        StubStore::$taxRulesGroups[4210] = ['name' => 'IVA 21%', 'active' => 1];
        $module = new DefaultShippingTaxCodeHarness();

        foreach (['abc', '0.5', '-4210', '4211'] as $bad) {
            Tools::setTestValue(self::CONFIG_KEY, $bad);
            $errors = $module->exposeValidOrderManagementFormValues();
            TinyAssert::count(1, $errors, 'Value ' . $bad . ' must be rejected');
            TinyAssert::true(strpos($errors[0], 'Default shipping tax code') !== false);

            // ...and rejected values are never stored.
            $module->exposeSaveOrderManagementFormValues();
            TinyAssert::same(
                '',
                (string) Configuration::get(self::CONFIG_KEY),
                'A rejected value must never reach configuration (' . $bad . ')'
            );
        }
    }

    private static function testValidationAcceptsBlankAndNoTax(): void
    {
        self::reset();
        $module = new DefaultShippingTaxCodeHarness();

        Tools::setTestValue(self::CONFIG_KEY, '');
        TinyAssert::count(0, $module->exposeValidOrderManagementFormValues(), 'Unselected is a legitimate state');

        Tools::setTestValue(self::CONFIG_KEY, '0');
        TinyAssert::count(0, $module->exposeValidOrderManagementFormValues(), '"No tax" is a legitimate selection');
    }

    // -----------------------------------------------------------------
    // Resolution order, on the real payload builder
    // -----------------------------------------------------------------

    /**
     * Requirement 1, on the real builder: with no default configured, a
     * cart is refused exactly as it was before this feature existed - same
     * message, same error severity.
     */
    private static function testUnsetDefaultStillRefusesCarrierlessCartLoudly(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $cart = self::seedCarrierlessCart(9301, 9311, 9321);
        self::seedTotalsFor21PercentShipping(9301);

        TinyAssert::throws(
            static function () use ($module, $cart): void {
                $module->getTwoNewOrderData('merchant-attempt-9301', $cart, self::merchantUrls());
            },
            'No deliverable carrier for the cart shipping cost'
        );

        TinyAssert::false(
            self::loggedContains(self::FALLBACK_LOG),
            'Nothing may be assumed when no default is configured'
        );
        TinyAssert::same(
            3,
            self::loggedSeverity('No deliverable carrier for the cart shipping cost'),
            'With no default configured the refusal stays an error-severity log'
        );
        TinyAssert::true(
            self::loggedContains('Configure a carrier that covers this delivery address'),
            'The refusal must keep telling the merchant what to fix'
        );
    }

    /**
     * The custom-logistics case end to end: no carrier, a real 29.00 shipping charge, and a
     * declared default of 21%. The shipping line carries the DECLARED 0.21 -
     * note the emitted 2dp amounts imply 20.98% (5.03 / 23.97), so a derived
     * rate would differ - the payload reconciles against the cart total, and
     * the log says which group and rate were assumed.
     */
    private static function testCarrierlessCartRelaysTheDefaultShippingTaxCode(): void
    {
        self::reset();
        StubStore::$taxRulesGroups[4210] = ['name' => 'IVA 21%', 'active' => 1];
        StubStore::$taxRuleRates[4210] = 21.0;
        Configuration::updateValue(self::CONFIG_KEY, '4210');

        $module = new TwopaymentTestHarness();
        $cart = self::seedCarrierlessCart(9303, 9313, 9323);
        self::seedTotalsFor21PercentShipping(9303);

        $payload = $module->getTwoNewOrderData('merchant-attempt-9303', $cart, self::merchantUrls());
        $shipping = self::shippingLines($payload);

        TinyAssert::count(1, $shipping, 'The default applies to the whole shipping charge as one class');
        TinyAssert::same('29.00', (string) $shipping[0]['gross_amount']);
        TinyAssert::same('23.97', (string) $shipping[0]['net_amount']);
        TinyAssert::same('5.03', (string) $shipping[0]['tax_amount']);
        TinyAssert::same('0.21', (string) $shipping[0]['tax_rate']);

        TinyAssert::same('150.00', (string) $payload['gross_amount']);
        TinyAssert::same('123.97', (string) $payload['net_amount']);

        // (c) The log must let us tell a shop on the fallback from one resolving
        // normally, naming the group and the rate.
        TinyAssert::true(self::loggedContains(self::FALLBACK_LOG));
        TinyAssert::true(
            self::loggedContains('"IVA 21%" (tax_rules_group=4210, rate=21%)'),
            'The fallback log must name the group, its id and the resolved rate'
        );
        TinyAssert::same(
            2,
            self::loggedSeverity(self::FALLBACK_LOG),
            'A fallback in use is a warning, not an informational aside'
        );
    }

    /**
     * "No tax" is a tax treatment the merchant can declare, and it is relayed
     * as an explicit 0% - not as an absence of a declaration.
     */
    private static function testNoTaxDefaultRelaysAnExplicitZeroRate(): void
    {
        self::reset();
        Configuration::updateValue(self::CONFIG_KEY, '0');

        $module = new TwopaymentTestHarness();
        $cart = self::seedCarrierlessCart(9304, 9314, 9324);
        // Untaxed shipping: 29.00 gross == 29.00 net.
        StubStore::$cartTotals[9304] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 29.00,
                Cart::BOTH => 150.00,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 29.00,
                Cart::BOTH => 129.00,
            ],
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-9304', $cart, self::merchantUrls());
        $shipping = self::shippingLines($payload);

        TinyAssert::count(1, $shipping);
        TinyAssert::same('29.00', (string) $shipping[0]['gross_amount']);
        TinyAssert::same('29.00', (string) $shipping[0]['net_amount']);
        TinyAssert::same('0.00', (string) $shipping[0]['tax_amount']);
        TinyAssert::same('0', (string) $shipping[0]['tax_rate']);
        TinyAssert::true(
            self::loggedContains('"No tax" (tax_rules_group=0, rate=0%)'),
            'An explicitly declared "No tax" default must still be logged as the assumption it is'
        );
    }

    /**
     * RESOLUTION ORDER. A shop with a working carrier table never sees the
     * default, even with one configured: the carrier's declared group is
     * per-option and per-address, the default is neither.
     */
    private static function testCarrierDeclaredGroupWinsOverTheDefault(): void
    {
        self::reset();
        // A configured default that would be WRONG for this cart.
        StubStore::$taxRulesGroups[4210] = ['name' => 'IVA 21%', 'active' => 1];
        StubStore::$taxRuleRates[4210] = 21.0;
        Configuration::updateValue(self::CONFIG_KEY, '4210');

        self::seedCommonFixtures(9305, 9315);
        $cart = new Cart(9305);
        $cart->id_customer = 9305;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 9315;
        $cart->id_address_delivery = 9315;
        $cart->id_lang = 1;
        $cart->id_carrier = 0;
        self::seedProductLine($cart, 9325);

        // The carrier that priced the shipping declares 10%, and is still
        // enumerable from the delivery option despite id_carrier = 0.
        StubStore::$taxRuleRates[4100] = 10.0;
        StubStore::$carriers[7031] = ['name' => 'Carrier 7031', 'delay' => '', 'tax_rules_group_id' => 4100];
        StubStore::$cartDeliveryOptionLists[9305] = [
            9315 => ['7031,' => ['carrier_list' => [
                7031 => [
                    'price_with_tax' => 29.00,
                    'price_without_tax' => 26.3636,
                    'instance' => new Carrier(7031),
                ],
            ]]],
        ];
        StubStore::$cartTotals[9305] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 29.00,
                Cart::BOTH => 150.00,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 26.3636,
                Cart::BOTH => 126.3636,
            ],
        ];

        $module = new TwopaymentTestHarness();
        $payload = $module->getTwoNewOrderData('merchant-attempt-9305', $cart, self::merchantUrls());
        $shipping = self::shippingLines($payload);

        TinyAssert::count(1, $shipping);
        TinyAssert::same('0.1', (string) $shipping[0]['tax_rate'], 'The carrier\'s declared 10% must win');
        TinyAssert::false(
            self::loggedContains(self::FALLBACK_LOG),
            'The default must not even be consulted when a carrier declares a group'
        );
        TinyAssert::true(self::loggedContains('carrier=7031 tax_rules_group=4100 rate=10%'));
    }

    /**
     * A selection pointing at a group the merchant has since deleted is not a
     * declaration any more. Refuse - never silently relay the 0% that a
     * missing group resolves to.
     */
    private static function testDeletedDefaultGroupRefusesRatherThanRelayZero(): void
    {
        self::reset();
        // The row survives; the group does not (StubStore::$taxRulesGroups empty).
        Configuration::updateValue(self::CONFIG_KEY, '4210');

        $module = new TwopaymentTestHarness();
        $cart = self::seedCarrierlessCart(9306, 9316, 9326);
        self::seedTotalsFor21PercentShipping(9306);

        TinyAssert::throws(
            static function () use ($module, $cart): void {
                $module->getTwoNewOrderData('merchant-attempt-9306', $cart, self::merchantUrls());
            },
            'No deliverable carrier for the cart shipping cost'
        );

        TinyAssert::false(self::loggedContains(self::FALLBACK_LOG));
        TinyAssert::true(
            self::loggedContains('which no longer exists; treating shipping tax as unresolvable'),
            'A dangling selection must say so in the log'
        );
        TinyAssert::same(
            3,
            self::loggedSeverity('which no longer exists'),
            'A dangling selection is an error the merchant has to fix'
        );
    }

    /**
     * With a working default configured, the carrier-side refusal is an
     * internal control-flow step, not a failure - so it must not leave a
     * permanent error-severity line in that merchant's log on every order.
     */
    private static function testRefusalLogDropsToWarningWhenADefaultIsConfigured(): void
    {
        self::reset();
        StubStore::$taxRulesGroups[4210] = ['name' => 'IVA 21%', 'active' => 1];
        StubStore::$taxRuleRates[4210] = 21.0;
        Configuration::updateValue(self::CONFIG_KEY, '4210');

        $module = new TwopaymentTestHarness();
        $cart = self::seedCarrierlessCart(9307, 9317, 9327);
        self::seedTotalsFor21PercentShipping(9307);

        $module->getTwoNewOrderData('merchant-attempt-9307', $cart, self::merchantUrls());

        TinyAssert::same(
            2,
            self::loggedSeverity('No deliverable carrier for the cart shipping cost'),
            'The carrier-side refusal must log as a warning once a default can absorb it'
        );
        TinyAssert::true(
            self::loggedContains('Falling back to the configured Default shipping tax code.'),
            'The refusal log must say what happens next instead of demanding a carrier fix'
        );
        TinyAssert::false(
            self::loggedContains('Configure a carrier that covers this delivery address'),
            'Do not tell a merchant to fix carriers when their configured default handled it'
        );
    }
}

/**
 * Exposes the Order Management form hooks, which are protected on the module
 * because PrestaShop calls them from getContent().
 */
final class DefaultShippingTaxCodeHarness extends TwopaymentTestHarness
{
    public function exposeOrderManagementForm(): array
    {
        return $this->getTwoOrderManagementForm();
    }

    public function exposeOrderManagementFormValues(): array
    {
        return $this->getTwoOrderManagementFormValues();
    }

    /** @return string[] The errors this submission would raise. */
    public function exposeValidOrderManagementFormValues(): array
    {
        $this->errors = array();
        $this->validTwoOrderManagementFormValues();

        return $this->errors;
    }

    public function exposeSaveOrderManagementFormValues(): void
    {
        $this->output = '';
        $this->saveTwoOrderManagementFormValues();
    }
}
