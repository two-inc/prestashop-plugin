<?php

declare(strict_types=1);

/**
 * the optional buyer reference fields in the Two payment tile.
 */
final class OptionalCheckoutFieldsSpec
{
    private const KEYS = [
        'department' => 'PS_TWO_ENABLE_DEPARTMENT',
        'project' => 'PS_TWO_ENABLE_PROJECT',
        'purchase_order_number' => 'PS_TWO_ENABLE_PO_NUMBER',
        'invoice_email' => 'PS_TWO_ENABLE_INVOICE_EMAIL',
    ];

    private const INPUTS = [
        'department' => 'two_department',
        'project' => 'two_project',
        'purchase_order_number' => 'two_purchase_order_number',
        'invoice_email' => 'two_invoice_email',
    ];

    public static function runAll(): void
    {
        self::testFreshInstallEnablesAllFour();
        self::testUpgrade270SeedsOnlyAbsentKeys();
        self::testUpgrade270LeavesAStoredZeroAlone();
        self::testAdminFormRoundTripsEveryField();
        self::testAbsentKeyIsOffRatherThanADefaultOnFallback();
        self::testEnabledFieldsAreExposedToTheTileInRenderOrder();
        self::testAdminSwitchesRenderInTheSameOrderAsTheTile();
        self::testCoreOrderNoteIsRelayedOnCreateAndUpdate();
        self::testDisabledFieldIsNotRenderedAndNotReadFromThePost();
        self::testSubmittedValuesAreTrimmedStrippedAndTruncated();
        self::testInvalidInvoiceEmailIsDroppedAndLogged();
        self::testOrderPayloadCarriesEveryFieldTheBuyerFilledIn();
        self::testOrderPayloadOmitsTheConditionalKeysWhenBlank();
        self::testUpdateOrderPayloadSendsNoOptionalValues();
        self::testAddressFormatterNoLongerInjectsDepartmentOrProject();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
    }

    private static function enableAll(): void
    {
        foreach (self::KEYS as $key) {
            Configuration::updateValue($key, 1);
        }
    }

    private static function post(array $values): void
    {
        foreach ($values as $field => $value) {
            Tools::setTestValue(self::INPUTS[$field], $value);
        }
    }

    private static function upgrade(TwopaymentTestHarness $module): void
    {
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.0.php';
        TinyAssert::true(upgrade_module_2_7_0($module));
    }

    /** @return array<string,mixed> */
    private static function formValues(TwopaymentTestHarness $module): array
    {
        $method = new ReflectionMethod(Twopayment::class, 'getTwoCheckoutFieldsFormValues');

        return $method->invoke($module);
    }

    private static function save(TwopaymentTestHarness $module): void
    {
        $method = new ReflectionMethod(Twopayment::class, 'saveTwoCheckoutFieldsFormValues');
        $method->invoke($module);
    }

    private static function loggedContains(string $needle): bool
    {
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos((string) $entry['message'], $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * A minimal shippable-free cart: one 25% product line, no shipping cost, so
     * the payload builder never needs a carrier to resolve a shipping tax rate.
     */
    private static function seedCart(int $cartId, int $addressId, int $productId): Cart
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
            'company' => 'EXAMPLE SHOP',
            'companyid' => 'E20468708',
            'address1' => 'Calle Uno 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '666666601',
            'loaded' => true,
        ];

        // Registered in the store as well as returned, so the code paths that
        // construct their own Cart from an order id (getTwoUpdateOrderData) see
        // the same fixture.
        StubStore::$carts[$cartId] = [
            'id_customer' => $cartId,
            'id_currency' => 978,
            'id_address_invoice' => $addressId,
            'id_address_delivery' => $addressId,
            'id_lang' => 1,
            'id_carrier' => 0,
        ];
        $cart = new Cart($cartId);

        StubStore::$cartProducts[$cart->id] = [[
            'id_product' => $productId,
            'link_rewrite' => 'example-product',
            'name' => 'Example Product',
            'description_short' => 'Product',
            'manufacturer_name' => 'ACME',
            'ean13' => '',
            'upc' => '',
            'total' => 100.00,
            'total_wt' => 125.00,
            'cart_quantity' => 1,
            'rate' => 25.0,
            'price' => 100.00,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[$productId] = [['name' => 'General']];
        StubStore::$images[$productId] = ['id_image' => $productId];
        StubStore::$products[$productId]['id_tax_rules_group'] = 9000 + $productId;
        StubStore::$taxRuleRates[9000 + $productId] = 25.0;

        StubStore::$cartTotals[$cartId] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 0.00,
                Cart::BOTH => 125.00,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 0.00,
                Cart::BOTH => 100.00,
            ],
        ];

        return $cart;
    }

    /** @return array<string,string> */
    private static function merchantUrls(): array
    {
        return [
            'merchant_confirmation_url' => 'https://shop.example/confirm',
            'merchant_cancel_order_url' => 'https://shop.example/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ];
    }

    private static function testFreshInstallEnablesAllFour(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $method = new ReflectionMethod(Twopayment::class, 'installTwoSettings');
        TinyAssert::true($method->invoke($module));

        foreach (self::KEYS as $field => $key) {
            TinyAssert::true(Configuration::hasKey($key), 'Expected install to write ' . $key);
            TinyAssert::same(1, Configuration::get($key), 'Expected install default ON for ' . $key);
            TinyAssert::true(
                $module->isOptionalCheckoutFieldEnabled($field),
                'Expected ' . $field . ' enabled on a fresh install'
            );
        }
    }

    private static function testUpgrade270SeedsOnlyAbsentKeys(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        foreach (self::KEYS as $key) {
            TinyAssert::false(Configuration::hasKey($key));
        }

        self::upgrade($module);

        foreach (self::KEYS as $field => $key) {
            TinyAssert::same(1, Configuration::get($key), 'Expected upgrade to seed ' . $key);
            TinyAssert::true($module->isOptionalCheckoutFieldEnabled($field));
        }

        TinyAssert::true(
            self::loggedContains('seeded the absent optional checkout field switches'),
            'Expected the upgrade to log the keys it seeded'
        );
    }

    /**
     * A stored value is the merchant's choice regardless of how it got there, so
     * a pre-2.7.0 shop keeps department and project OFF after upgrading and only
     * the two new fields appear. That near-no-op is intended: if this test fails
     * because both keys came back as 1, the seed-only guard has been dropped.
     */
    private static function testUpgrade270LeavesAStoredZeroAlone(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        Configuration::updateValue('PS_TWO_ENABLE_DEPARTMENT', 0);
        Configuration::updateValue('PS_TWO_ENABLE_PROJECT', 0);
        TinyAssert::false(Configuration::hasKey('PS_TWO_ENABLE_PO_NUMBER'));
        TinyAssert::false(Configuration::hasKey('PS_TWO_ENABLE_INVOICE_EMAIL'));

        self::upgrade($module);

        TinyAssert::same(0, (int) Configuration::get('PS_TWO_ENABLE_DEPARTMENT'), 'A stored 0 must survive the upgrade');
        TinyAssert::same(0, (int) Configuration::get('PS_TWO_ENABLE_PROJECT'), 'A stored 0 must survive the upgrade');
        TinyAssert::false($module->isOptionalCheckoutFieldEnabled('department'));
        TinyAssert::false($module->isOptionalCheckoutFieldEnabled('project'));

        TinyAssert::same(1, Configuration::get('PS_TWO_ENABLE_PO_NUMBER'));
        TinyAssert::same(1, Configuration::get('PS_TWO_ENABLE_INVOICE_EMAIL'));
        TinyAssert::same(
            ['invoice_email', 'purchase_order_number'],
            array_keys($module->getOptionalCheckoutFieldsForDisplay()),
            'An upgraded shop with both older switches off shows only the two new fields'
        );

        Configuration::updateValue('PS_TWO_ENABLE_PO_NUMBER', 0);
        self::upgrade($module);
        TinyAssert::same(0, (int) Configuration::get('PS_TWO_ENABLE_PO_NUMBER'), 'A later opt-out must not be resurrected');
    }

    private static function testAdminFormRoundTripsEveryField(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        foreach (self::KEYS as $key) {
            Tools::setTestValue($key, '0');
        }
        self::save($module);
        foreach (self::KEYS as $field => $key) {
            // The switch posts a string and the save stores it as posted, so
            // compare on the int the readers coerce to.
            TinyAssert::same(0, (int) Configuration::get($key), 'Expected save to store 0 for ' . $key);
            TinyAssert::same('0', (string) self::formValues($module)[$key]);
            TinyAssert::false($module->isOptionalCheckoutFieldEnabled($field));
        }

        Tools::resetTestValues();
        foreach (self::KEYS as $key) {
            Tools::setTestValue($key, '1');
        }
        self::save($module);
        foreach (self::KEYS as $field => $key) {
            TinyAssert::same(1, (int) Configuration::get($key), 'Expected save to store 1 for ' . $key);
            TinyAssert::same('1', (string) self::formValues($module)[$key]);
            TinyAssert::true($module->isOptionalCheckoutFieldEnabled($field));
        }
    }

    /**
     * Deliberately NOT a default-on getter fallback. install() and
     * upgrade-2.7.0 both write real rows, so the stored row IS the default; a
     * fallback here would make the admin switch (which renders the stored
     * value) disagree with the rendered checkout.
     */
    private static function testAbsentKeyIsOffRatherThanADefaultOnFallback(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        foreach (self::KEYS as $field => $key) {
            TinyAssert::false(Configuration::hasKey($key));
            TinyAssert::false(
                $module->isOptionalCheckoutFieldEnabled($field),
                'An absent ' . $key . ' must read as off, not as a hidden default-on'
            );
        }

        TinyAssert::count(0, $module->getOptionalCheckoutFieldsForDisplay());
    }

    private static function testEnabledFieldsAreExposedToTheTileInRenderOrder(): void
    {
        self::reset();
        self::enableAll();
        $module = new TwopaymentTestHarness();

        $fields = $module->getOptionalCheckoutFieldsForDisplay();

        // The fifth field in the agreed standard order, the order note, is
        // core's `delivery_message` on the shipping step and has no tile
        // presence, so it cannot appear here.
        TinyAssert::same(
            ['invoice_email', 'purchase_order_number', 'project', 'department'],
            array_keys($fields),
            'Render order is part of the checkout layout, not incidental'
        );
        TinyAssert::same('two_invoice_email', $fields['invoice_email']['input_name']);
        // type=email for the mobile keyboard and hint; the plugin validates it
        // again itself.
        TinyAssert::same('email', $fields['invoice_email']['type']);
        TinyAssert::same('text', $fields['department']['type']);
        TinyAssert::same('255', $fields['department']['max_length']);
    }

    /**
     * Two separate lists express the field order - the constant the tile
     * iterates and the form's input array - and nothing but this test stops
     * them drifting apart.
     */
    private static function testAdminSwitchesRenderInTheSameOrderAsTheTile(): void
    {
        self::reset();
        self::enableAll();
        $module = new TwopaymentTestHarness();

        $method = new ReflectionMethod(Twopayment::class, 'getTwoCheckoutFieldsForm');
        $form = $method->invoke($module);

        $switchOrder = array();
        foreach ($form['form']['input'] as $input) {
            $name = isset($input['name']) ? (string) $input['name'] : '';
            if (in_array($name, self::KEYS, true)) {
                $switchOrder[] = $name;
            }
        }

        $tileOrder = array();
        foreach (array_keys($module->getOptionalCheckoutFieldsForDisplay()) as $field) {
            $tileOrder[] = self::KEYS[$field];
        }

        TinyAssert::same(
            [
                'PS_TWO_ENABLE_INVOICE_EMAIL',
                'PS_TWO_ENABLE_PO_NUMBER',
                'PS_TWO_ENABLE_PROJECT',
                'PS_TWO_ENABLE_DEPARTMENT',
            ],
            $switchOrder,
            'Admin switches must render in the agreed standard field order'
        );
        TinyAssert::same($switchOrder, $tileOrder, 'Admin switch order must match the checkout tile order');
    }

    /**
     * Core writes the order note through Tools::safeOutput() (htmlentities), so
     * the relay has to decode it - otherwise Two receives `&amp;` and `&quot;`.
     * It is read from the cart rather than the request, which is what lets the
     * UPDATE payload carry it: read from the buyer's submission, an admin order
     * edit would blank the note on Two's side.
     */
    private static function testCoreOrderNoteIsRelayedOnCreateAndUpdate(): void
    {
        self::reset();
        self::enableAll();
        $module = new TwopaymentTestHarness();
        $cart = self::seedCart(7204, 7214, 7224);

        // Blank first: no row at all means an empty note, not a warning.
        TinyAssert::same('', $module->getCartOrderNote($cart));
        $payload = $module->getTwoNewOrderData('merchant-attempt-7204a', $cart, self::merchantUrls());
        TinyAssert::same('', $payload['order_note']);

        StubStore::$cartMessages[7204] = [
            'id_message' => 51,
            // Exactly what core stores for: Leave at reception &  ring "twice"
            'message' => 'Leave at reception &amp; ring &quot;twice&quot;',
        ];

        TinyAssert::same('Leave at reception & ring "twice"', $module->getCartOrderNote($cart));

        $payload = $module->getTwoNewOrderData('merchant-attempt-7204b', $cart, self::merchantUrls());
        TinyAssert::same('Leave at reception & ring "twice"', $payload['order_note']);

        $order = new class {
            public bool $loaded = true;
            public int $id = 7204;
            public int $id_cart = 7204;
            public int $id_carrier = 0;
            public string $shipping_number = '';

            public function getIdOrderCarrier(): int
            {
                return 0;
            }
        };
        $updatePayload = $module->getTwoUpdateOrderData($order, [
            'two_order_reference' => 'ref-7204',
            'two_day_on_invoice' => '30',
        ]);
        TinyAssert::same('Leave at reception & ring "twice"', $updatePayload['order_note']);

        // Capped, so one pasted essay cannot be the reason an order is rejected.
        StubStore::$cartMessages[7204]['message'] = str_repeat('n', 1500);
        TinyAssert::same(1000, strlen($module->getCartOrderNote($cart)));
    }

    private static function testDisabledFieldIsNotRenderedAndNotReadFromThePost(): void
    {
        self::reset();
        self::enableAll();
        Configuration::updateValue('PS_TWO_ENABLE_PROJECT', 0);
        $module = new TwopaymentTestHarness();

        TinyAssert::false(
            array_key_exists('project', $module->getOptionalCheckoutFieldsForDisplay()),
            'A disabled field renders no element at all, hidden or otherwise'
        );

        // Forged parameter: nothing renders the input, so the switch has to win.
        self::post(['project' => 'Smuggled', 'department' => 'Finance']);
        $submitted = $module->getSubmittedOptionalCheckoutFields();

        TinyAssert::same(['department' => 'Finance'], $submitted);
    }

    private static function testSubmittedValuesAreTrimmedStrippedAndTruncated(): void
    {
        self::reset();
        self::enableAll();
        $module = new TwopaymentTestHarness();

        self::post([
            'department' => "  Finance  ",
            'project' => '<b>Rebuild</b>',
            'purchase_order_number' => str_repeat('P', 300),
            'invoice_email' => '   ap@example.com ',
        ]);

        $submitted = $module->getSubmittedOptionalCheckoutFields();

        TinyAssert::same('Finance', $submitted['department']);
        TinyAssert::same('Rebuild', $submitted['project']);
        TinyAssert::same(255, strlen($submitted['purchase_order_number']));
        TinyAssert::same('ap@example.com', $submitted['invoice_email']);

        // Empty means absent, not an empty string in the payload sub-objects.
        Tools::resetTestValues();
        self::post(['department' => '   ']);
        TinyAssert::count(0, $module->getSubmittedOptionalCheckoutFields());
    }

    private static function testInvalidInvoiceEmailIsDroppedAndLogged(): void
    {
        self::reset();
        self::enableAll();
        $module = new TwopaymentTestHarness();

        self::post(['department' => 'Finance', 'invoice_email' => 'not-an-email']);
        $submitted = $module->getSubmittedOptionalCheckoutFields();

        // Dropped, not fatal: failing a checkout over an optional field would be
        // worse than sending the order without it.
        TinyAssert::same(['department' => 'Finance'], $submitted);
        TinyAssert::true(
            self::loggedContains('Dropped invalid optional checkout field "invoice_email"'),
            'A dropped value must leave a trail'
        );
    }

    private static function testOrderPayloadCarriesEveryFieldTheBuyerFilledIn(): void
    {
        self::reset();
        self::enableAll();
        $module = new TwopaymentTestHarness();
        $cart = self::seedCart(7201, 7211, 7221);

        self::post([
            'department' => 'Finance',
            'project' => 'Warehouse rebuild',
            'purchase_order_number' => 'PO-4711',
            'invoice_email' => 'ap@example.com',
        ]);

        $payload = $module->getTwoNewOrderData('merchant-attempt-7201', $cart, self::merchantUrls());

        TinyAssert::same('Finance', $payload['buyer_department']);
        TinyAssert::same('Warehouse rebuild', $payload['buyer_project']);
        TinyAssert::same('PO-4711', $payload['buyer_purchase_order_number']);
        TinyAssert::same(['ap@example.com'], $payload['invoice_details']['invoice_emails']);
    }

    private static function testOrderPayloadOmitsTheConditionalKeysWhenBlank(): void
    {
        self::reset();
        self::enableAll();
        $module = new TwopaymentTestHarness();
        $cart = self::seedCart(7202, 7212, 7222);

        $payload = $module->getTwoNewOrderData('merchant-attempt-7202', $cart, self::merchantUrls());

        // buyer_department / buyer_project are always-present scalars in Two's
        // order payload, so they stay as empty strings...
        TinyAssert::same('', $payload['buyer_department']);
        TinyAssert::same('', $payload['buyer_project']);
        // ...while these two are only sent when there is something to send,
        // matching the shape the WooCommerce plugin uses.
        TinyAssert::false(array_key_exists('buyer_purchase_order_number', $payload));
        TinyAssert::false(array_key_exists('invoice_details', $payload));
    }

    /**
     * The order-UPDATE payload is built from admin order edits, provider
     * webhooks and status transitions. None carry the buyer's payment-step
     * submission and the values are not persisted locally, so the optional
     * fields go out empty.
     */
    private static function testUpdateOrderPayloadSendsNoOptionalValues(): void
    {
        self::reset();
        self::enableAll();
        $module = new TwopaymentTestHarness();
        self::seedCart(7203, 7213, 7223);

        // A stray POST in scope must not leak into an admin-side update.
        self::post(['department' => 'Finance', 'project' => 'Rebuild']);

        $order = new class {
            public bool $loaded = true;
            public int $id = 7203;
            public int $id_cart = 7203;
            public int $id_carrier = 0;
            public string $shipping_number = '';

            public function getIdOrderCarrier(): int
            {
                return 0;
            }
        };

        $payload = $module->getTwoUpdateOrderData($order, [
            'two_order_reference' => 'ref-7203',
            'two_day_on_invoice' => '30',
        ]);

        TinyAssert::same('', $payload['buyer_department']);
        TinyAssert::same('', $payload['buyer_project']);
        TinyAssert::false(array_key_exists('buyer_purchase_order_number', $payload));
        TinyAssert::false(array_key_exists('invoice_details', $payload));
    }

    private static function testAddressFormatterNoLongerInjectsDepartmentOrProject(): void
    {
        self::reset();
        // Both switches ON, yet no field in the address form: PrestaShop only
        // shows the billing block when the buyer ticks "Billing address differs
        // from shipping address", which makes that placement unusable.
        self::enableAll();

        $overridePath = dirname(__DIR__) . '/override/classes/form/CustomerAddressFormatter.php';
        if (!class_exists('CustomerAddressFormatter', false)) {
            require_once $overridePath;
        }

        $translator = new class {
            public function trans($message, array $params = [], $domain = null): string
            {
                return (string) $message;
            }
        };

        $formatter = new CustomerAddressFormatter(new Country(), $translator, []);
        $format = $formatter->getFormat();

        TinyAssert::false(
            array_key_exists('department', $format),
            'department must not be injected into the address form any more'
        );
        TinyAssert::false(
            array_key_exists('project', $format),
            'project must not be injected into the address form any more'
        );
        TinyAssert::true(isset($format['company']) && $format['company'] instanceof FormField);
    }
}
