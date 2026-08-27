<?php

declare(strict_types=1);

/**
 * TWO-25503: buyer.company falls back to the shipping address when the invoice
 * address identifies no company.
 *
 * The buyer types their company on the shipping step, then leaves the billing
 * address blank; every payload then went to Two with an empty buyer company.
 *
 * The pairing assertion is the one that matters: the resolved name and
 * organisation number must be the pair one address supplied, never a name from
 * the invoice address beside a number from the delivery address.
 */
final class BuyerCompanyFallbackSpec
{
    private const CART_ID = 8910;

    private const ORDER_ID = 8911;

    private const INVOICE_ADDRESS_ID = 8920;

    private const DELIVERY_ADDRESS_ID = 8921;

    private const PRODUCT_ID = 8930;

    private const CURRENCY_ID = 826;

    private const COUNTRY_ID = 8940;

    private const DELIVERY_COUNTRY_ID = 8941;

    private const CARRIER_ID = 8950;

    /**
     * invoice name, invoice org, shipping name, shipping org,
     * expected buyer name, expected buyer org, description.
     *
     * @return array<int,array{0:string,1:string,2:string,3:string,4:string,5:string,6:string}>
     */
    private static function cases(): array
    {
        return [
            ['Invoice Co Ltd', 'INV-12345678', 'Delivery Co Ltd', 'DEL-87654321', 'Invoice Co Ltd', 'INV-12345678', 'both present - the invoice company wins'],
            ['', '', 'Delivery Co Ltd', 'DEL-87654321', 'Delivery Co Ltd', 'DEL-87654321', 'invoice empty - the shipping company is used'],
            ['', '', '', '', '', '', 'both empty - nothing is invented'],
            ['Invoice Co Ltd', 'INV-12345678', '', '', 'Invoice Co Ltd', 'INV-12345678', 'shipping empty - the invoice company is untouched'],
            // A half-populated invoice company still identifies the buyer, so it
            // is not "absent" and the complete shipping company does not displace
            // it. These two rows are what discriminate that rule from an AND.
            ['Invoice Co Ltd', '', 'Delivery Co Ltd', 'DEL-87654321', 'Invoice Co Ltd', '', 'invoice name only - it is present, and keeps its empty number'],
            ['', 'INV-12345678', 'Delivery Co Ltd', 'DEL-87654321', '', 'INV-12345678', 'invoice number only - it is present, and keeps its empty name'],
        ];
    }

    public static function runAll(): void
    {
        self::testEveryPayloadResolvesTheBuyerCompany();
        self::testSessionCompanySurvivesTheOtherAddressOfTheSameCart();
        self::testAHalfStoredSnapshotIsNotCompletedFromAnAddress();
        self::testACompanyPickedForAForeignDeliveryAddressSurvives();
    }

    /**
     * All three payloads - intent, create, update - over the same table. A
     * fallback present on one and missing on another would let a checkout Two
     * approved be created with a different buyer.
     */
    private static function testEveryPayloadResolvesTheBuyerCompany(): void
    {
        foreach (self::cases() as $case) {
            list($invoiceName, $invoiceOrg, $shippingName, $shippingOrg, $expectedName, $expectedOrg, $description) = $case;

            foreach (['intent', 'create', 'update'] as $payloadKind) {
                self::reset();
                self::seedCart($invoiceName, $invoiceOrg, $shippingName, $shippingOrg);
                $module = new TwopaymentTestHarness();

                $payload = self::buildPayload($module, $payloadKind);
                $company = $payload['buyer']['company'];
                $label = $payloadKind . ' payload, ' . $description;

                TinyAssert::same($expectedName, $company['company_name'], 'company name - ' . $label);
                TinyAssert::same($expectedOrg, $company['organization_number'], 'organisation number - ' . $label);

                // Same address for both fields, asserted against the sources
                // rather than the expectation, so a name/number mix cannot pass
                // by matching two independently-correct values.
                TinyAssert::true(
                    [$company['company_name'], $company['organization_number']] === [$invoiceName, $invoiceOrg]
                        || [$company['company_name'], $company['organization_number']] === [$shippingName, $shippingOrg],
                    'name and organisation number must come from one address - ' . $label
                );

                TinyAssert::same(
                    $expectedName,
                    $payload['billing_address']['organization_name'],
                    'the billing address carries the resolved buyer company - ' . $label
                );
            }
        }
    }

    /**
     * The record is cart-scoped already, so a selection made against the
     * shipping address is the same buyer's selection when the invoice address is
     * resolved moments later.
     */
    private static function testSessionCompanySurvivesTheOtherAddressOfTheSameCart(): void
    {
        // The invoice address names a company but carries no organisation
        // number, so only the session record can supply one - and the shipping
        // fallback cannot stand in for it. Without that the assertion would pass
        // on the fallback alone and prove nothing about the address check.
        $payload = self::resolveWithSessionCompanyStampedOn(self::DELIVERY_ADDRESS_ID);
        TinyAssert::same(
            'Searched Co Ltd',
            $payload['buyer']['company']['company_name'],
            'a selection made on the cart\'s delivery address must survive resolution of its invoice address'
        );
        TinyAssert::same(
            'SEARCHED-999',
            $payload['buyer']['company']['organization_number'],
            'the same selection supplies the organisation number the address has not got'
        );

        // An address belonging to no cart of this buyer's is still discarded.
        $stale = self::resolveWithSessionCompanyStampedOn(self::DELIVERY_ADDRESS_ID + 500);
        TinyAssert::same(
            'Invoice Co Ltd',
            $stale['buyer']['company']['company_name'],
            'a selection against an address outside the cart is still an address switch'
        );
        TinyAssert::same(
            '',
            $stale['buyer']['company']['organization_number'],
            'and supplies no organisation number once discarded'
        );
    }

    /**
     * A snapshot is honoured as a pair. Half of one plus half of an address is a
     * buyer the order was never placed with - and half is the normal shape,
     * because the organisation number resolves empty in admin context whenever
     * the identifier is internally minted or the country issues no such number.
     */
    private static function testAHalfStoredSnapshotIsNotCompletedFromAnAddress(): void
    {
        $cases = [
            ['Old Invoice Co', '', 'Old Invoice Co', '', 'name-only snapshot keeps its empty number'],
            ['', 'OLD-INV-1', '', 'OLD-INV-1', 'number-only snapshot keeps its empty name'],
        ];

        foreach ($cases as $case) {
            list($storedName, $storedOrg, $expectedName, $expectedOrg, $description) = $case;

            self::reset();
            // Invoice address empty and delivery address complete: the shipping
            // fallback is armed and would fill the missing half if the snapshot
            // were applied field by field.
            self::seedCart('', '', 'Delivery Co Ltd', 'DEL-87654321');
            $module = new TwopaymentTestHarness();

            $payload = $module->getTwoUpdateOrderData(self::makeOrder(), array(
                'two_order_reference' => 'ref-' . self::ORDER_ID,
                'two_day_on_invoice' => '30',
                'two_organization_number' => $storedOrg,
                'two_company_name' => $storedName,
            ));
            $company = $payload['buyer']['company'];

            TinyAssert::same($expectedName, $company['company_name'], 'company name - ' . $description);
            TinyAssert::same($expectedOrg, $company['organization_number'], 'organisation number - ' . $description);
            TinyAssert::notSame(
                'DEL-87654321',
                $company['organization_number'],
                'the delivery address must never complete a snapshot - ' . $description
            );
            TinyAssert::notSame(
                'Delivery Co Ltd',
                $company['company_name'],
                'the delivery address must never complete a snapshot - ' . $description
            );
        }
    }

    /**
     * A company picked for a delivery address in another country. Resolving the
     * invoice address first must neither consume nor destroy it.
     */
    private static function testACompanyPickedForAForeignDeliveryAddressSurvives(): void
    {
        self::reset();
        self::seedCart('', '', '', '');
        StubStore::$countries[self::DELIVERY_COUNTRY_ID] = 'FR';
        StubStore::$addresses[self::DELIVERY_ADDRESS_ID]['id_country'] = self::DELIVERY_COUNTRY_ID;
        Context::getContext()->cart = new Cart(self::CART_ID);

        $cookie = Context::getContext()->cookie;
        $cookie->two_company_name = 'Searched FR Co';
        $cookie->two_company_id = 'FR-999';
        $cookie->two_company_country = 'FR';
        $cookie->two_company_address_id = (string) self::DELIVERY_ADDRESS_ID;
        $cookie->two_company_cart_id = (string) self::CART_ID;

        $payload = self::buildPayload(new TwopaymentTestHarness(), 'create');
        $company = $payload['buyer']['company'];

        TinyAssert::same('Searched FR Co', $company['company_name'], 'company name from the foreign delivery address');
        TinyAssert::same('FR-999', $company['organization_number'], 'organisation number from the same selection');
        TinyAssert::same('FR', $company['country_prefix'], 'the country prefix travels with the number that was issued under it');

        TinyAssert::same(
            'FR-999',
            (string) Context::getContext()->cookie->two_company_id,
            'resolving the invoice address must not destroy a selection held for the delivery address'
        );
    }

    /** @return array the create payload, with a cart-scoped selection stamped on $addressId */
    private static function resolveWithSessionCompanyStampedOn(int $addressId): array
    {
        self::reset();
        self::seedCart('Invoice Co Ltd', '', '', '');
        Context::getContext()->cart = new Cart(self::CART_ID);

        $cookie = Context::getContext()->cookie;
        $cookie->two_company_name = 'Searched Co Ltd';
        $cookie->two_company_id = 'SEARCHED-999';
        $cookie->two_company_country = 'GB';
        $cookie->two_company_address_id = (string) $addressId;
        $cookie->two_company_cart_id = (string) self::CART_ID;

        return self::buildPayload(new TwopaymentTestHarness(), 'create');
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private static function buildPayload(TwopaymentTestHarness $module, string $kind): array
    {
        if ($kind === 'intent') {
            return $module->getTwoIntentOrderData(
                new Cart(self::CART_ID),
                new Customer(self::CART_ID),
                new Currency(self::CURRENCY_ID),
                new Address(self::INVOICE_ADDRESS_ID)
            );
        }

        if ($kind === 'create') {
            return $module->getTwoNewOrderData(self::ORDER_ID, new Cart(self::CART_ID));
        }

        // Nothing stored, which is what makes the update payload re-resolve.
        return $module->getTwoUpdateOrderData(self::makeOrder(), array(
            'two_order_reference' => 'ref-' . self::ORDER_ID,
            'two_day_on_invoice' => '30',
            'two_organization_number' => '',
            'two_company_name' => '',
        ));
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
        Context::getContext()->cookie = new Cookie();
    }

    private static function seedCart(
        string $invoiceName,
        string $invoiceOrg,
        string $shippingName,
        string $shippingOrg
    ): Cart {
        StubStore::$customers[self::CART_ID] = [
            'email' => 'buyer@example.invalid',
            'firstname' => 'Ada',
            'lastname' => 'Byron',
            'secure_key' => 'secure-key-' . self::CART_ID,
            'loaded' => true,
        ];
        StubStore::$currencies[self::CURRENCY_ID] = ['iso_code' => 'GBP', 'loaded' => true];
        StubStore::$countries[self::COUNTRY_ID] = 'GB';

        StubStore::$addresses[self::INVOICE_ADDRESS_ID] = [
            'id_country' => self::COUNTRY_ID,
            'company' => $invoiceName,
            'companyid' => $invoiceOrg,
            'address1' => '1 Invoice Street',
            'city' => 'London',
            'postcode' => 'EC1A 1AA',
            'phone' => '02079460001',
            'loaded' => true,
        ];
        StubStore::$addresses[self::DELIVERY_ADDRESS_ID] = [
            'id_country' => self::COUNTRY_ID,
            'company' => $shippingName,
            'companyid' => $shippingOrg,
            'address1' => '2 Delivery Road',
            'city' => 'London',
            'postcode' => 'EC1A 2BB',
            'phone' => '02079460002',
            'loaded' => true,
        ];

        StubStore::$carts[self::CART_ID] = [
            'id_customer' => self::CART_ID,
            'id_currency' => self::CURRENCY_ID,
            'id_address_invoice' => self::INVOICE_ADDRESS_ID,
            'id_address_delivery' => self::DELIVERY_ADDRESS_ID,
            'id_lang' => 1,
            'id_carrier' => self::CARRIER_ID,
        ];
        $cart = new Cart(self::CART_ID);

        StubStore::$cartProducts[self::CART_ID] = [[
            'id_product' => self::PRODUCT_ID,
            'link_rewrite' => 'example-product',
            'name' => 'Example Product',
            'description_short' => 'Product',
            'manufacturer_name' => 'ACME',
            'ean13' => '',
            'upc' => '',
            'total' => 100.00,
            'total_wt' => 120.00,
            'cart_quantity' => 1,
            'rate' => 20.0,
            'price' => 100.00,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[self::PRODUCT_ID] = [['name' => 'General']];
        StubStore::$images[self::PRODUCT_ID] = ['id_image' => self::PRODUCT_ID];
        StubStore::$products[self::PRODUCT_ID]['id_tax_rules_group'] = 9000 + self::PRODUCT_ID;
        StubStore::$taxRuleRates[9000 + self::PRODUCT_ID] = 20.0;

        StubStore::$cartTotals[self::CART_ID] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 0.00,
                Cart::BOTH => 120.00,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 0.00,
                Cart::BOTH => 100.00,
            ],
        ];

        StubStore::$carriers[self::CARRIER_ID] = ['name' => 'Example Carrier', 'max_delivery_days' => 3];

        return $cart;
    }

    private static function makeOrder(): object
    {
        $order = new class {
            public bool $loaded = true;
            public int $id = 0;
            public int $id_cart = 0;
            public int $id_carrier = 0;
            public string $shipping_number = '';

            public function getIdOrderCarrier(): int
            {
                return 0;
            }
        };
        $order->id = self::ORDER_ID;
        $order->id_cart = self::CART_ID;
        $order->id_carrier = self::CARRIER_ID;

        return $order;
    }
}
