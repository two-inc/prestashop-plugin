<?php

declare(strict_types=1);

/**
 * TWO-40: the buyer company an order was created with, persisted on the module's
 * own order-scoped table.
 *
 * WHAT WAS WRONG. `buyer.company.organization_number` can only be resolved in
 * the buyer's own request - it comes from the cart-scoped company selection, and
 * the cart has rotated by the time hookActionOrderEdited or
 * hookActionAdminOrdersTrackingNumberUpdate calls getTwoUpdateOrderData().
 * readTwoCartScopedCompany() therefore reports absent, the resolver falls
 * through to extractOrgNumberFromAddress(), and that finds nothing: a
 * `TWO:`-prefixed identifier never reaches `dni` (core's isDniLite rejects the
 * colon) and a country without need_identification_number has no such field to
 * read at all. Both admin paths PUT an empty organisation number over a good
 * one.
 *
 * THE FIX, and the precedent it follows. `two_day_on_invoice` already solves the
 * identical problem for the payment term: the created order persists it, and
 * getTwoUpdateOrderData() reads it back as $storedTerm rather than recomputing
 * from a request that no longer exists. Two more columns on the same table do
 * the same job for the company.
 *
 * WHAT THESE SPECS DRIVE, rather than read:
 *
 *  - the columns exist after a fresh install AND after the upgrade script, which
 *    is guarded and re-runnable;
 *  - a STATUS-ONLY write does not blank a stored company. This is the one that
 *    matters. Eight of the nine setTwoOrderPaymentData() callers know nothing
 *    about the buyer's company, so the two keys have to be presence-conditional;
 *    default them to `''` like the columns beside them and the first status
 *    transition after checkout erases the value silently, reintroducing the
 *    original bug through the fix for it;
 *  - the update payload prefers the stored value, and falls back to re-resolving
 *    only when there is nothing stored (orders predating the columns);
 *  - the snapshot is taken from the INVOICE address, not the delivery address -
 *    they differ whenever the buyer ships elsewhere, and only the invoice one
 *    drives buyer.company;
 *  - a `TWO:`-prefixed identifier round-trips byte-identical, prefix included.
 */
final class OrderCompanyPersistenceSpec
{
    private const CART_ID = 8810;

    private const ORDER_ID = 8811;

    private const INVOICE_ADDRESS_ID = 8820;

    private const DELIVERY_ADDRESS_ID = 8821;

    private const PRODUCT_ID = 8830;

    private const CURRENCY_ID = 826;

    private const COUNTRY_ID = 8840;

    private const CARRIER_ID = 8850;

    /** An internally-minted identifier: the colon is the whole point. */
    private const TWO_PREFIXED_ID = 'TWO:0f8e1c2a-4b6d-4f19-9c33-7a5e10b2d4c8';

    public static function runAll(): void
    {
        self::testInstallCreatesTheCompanyColumns();
        self::testUpgradeScriptAddsTheColumnsAndIsRerunnable();
        self::testStatusOnlyUpdateLeavesAStoredCompanyAlone();
        self::testUpdatePayloadUsesTheStoredCompany();
        self::testUpdatePayloadFallsBackWhenNothingIsStored();
        self::testSnapshotComesFromTheInvoiceAddressNotTheDeliveryAddress();
        self::testTwoPrefixedIdentifierRoundTripsByteIdentical();
    }

    // -----------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------

    private static function testInstallCreatesTheCompanyColumns(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $create = new ReflectionMethod(Twopayment::class, 'createTwoTables');
        TinyAssert::true($create->invoke($module), 'install DDL must succeed');

        TinyAssert::true(
            isset(StubStore::$dbColumns[_DB_PREFIX_ . 'twopayment.two_organization_number']),
            'a fresh install must carry two_organization_number'
        );
        TinyAssert::true(
            isset(StubStore::$dbColumns[_DB_PREFIX_ . 'twopayment.two_company_name']),
            'a fresh install must carry two_company_name'
        );

        // A fresh install has no work for the runtime guard to do. Asserted so
        // the guard cannot quietly become an every-request ALTER attempt.
        $ensure = new ReflectionMethod(Twopayment::class, 'ensureTwoOrderCompanyColumns');
        $ensure->invoke($module);

        TinyAssert::same(
            [],
            self::alterStatements(),
            'nothing to add on a shop installed with the columns already present'
        );
    }

    /**
     * The upgrade path, and the file-swap path behind it. `createTwoTables()`
     * runs only at install, so an existing shop reaches the columns through
     * this script - or, if its files were swapped in place without core running
     * an upgrade at all, through ensureTwoOrderCompanyColumns(). Both are
     * guarded on information_schema, so both have to be re-runnable.
     */
    private static function testUpgradeScriptAddsTheColumnsAndIsRerunnable(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.7.php';
        TinyAssert::true(upgrade_module_2_7_7($module), 'the upgrade script must report success');

        TinyAssert::same(
            [
                'two_company_name',
                'two_organization_number',
            ],
            self::alterStatements(),
            'both columns must be added on a shop that has neither'
        );
        TinyAssert::true(isset(StubStore::$dbColumns[_DB_PREFIX_ . 'twopayment.two_organization_number']));
        TinyAssert::true(isset(StubStore::$dbColumns[_DB_PREFIX_ . 'twopayment.two_company_name']));

        // Second run: core can re-offer an upgrade, and the runtime guard may
        // already have done the work. Neither may issue a duplicate ALTER.
        StubStore::$dbExecuted = [];
        TinyAssert::true(upgrade_module_2_7_7($module), 'a re-run must still succeed');
        TinyAssert::same([], self::alterStatements(), 'the guarded ALTER must not fire twice');

        // And the runtime guard reaches the same columns on a shop the upgrade
        // never touched, which is the file-swap window this exists for.
        self::reset();
        $swapped = new TwopaymentTestHarness();
        $ensure = new ReflectionMethod(Twopayment::class, 'ensureTwoOrderCompanyColumns');
        $ensure->invoke($swapped);

        TinyAssert::same(
            [
                'two_company_name',
                'two_organization_number',
            ],
            self::alterStatements(),
            'the runtime guard must add both columns on a file-swapped shop'
        );
    }

    // -----------------------------------------------------------------
    // Presence-conditional write - the important one
    // -----------------------------------------------------------------

    /**
     * A status transition must not be able to erase the company.
     *
     * The order-creation write is the only caller that knows the buyer's
     * company; every webhook, cancel callback and status change after it passes
     * a $payment_data array with no company keys in it. If an absent key meant
     * "write empty", the stored organisation number would survive exactly as
     * long as it took Two to send the first status update - and the admin paths
     * would then read back a blank and PUT it, which is the bug these columns
     * were added to fix.
     */
    private static function testStatusOnlyUpdateLeavesAStoredCompanyAlone(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $module->setTwoOrderPaymentData(self::ORDER_ID, self::creationPaymentData());

        $stored = $module->getTwoOrderPaymentData(self::ORDER_ID);
        TinyAssert::same(self::TWO_PREFIXED_ID, (string) $stored['two_organization_number']);
        TinyAssert::same('Example Trading Ltd', (string) $stored['two_company_name']);

        // Exactly the shape a status/webhook caller passes: no company keys.
        $module->setTwoOrderPaymentData(self::ORDER_ID, self::statusOnlyPaymentData('FULFILLED'));

        $afterStatus = $module->getTwoOrderPaymentData(self::ORDER_ID);
        TinyAssert::same(
            self::TWO_PREFIXED_ID,
            (string) $afterStatus['two_organization_number'],
            'a status-only update must not blank the stored organisation number'
        );
        TinyAssert::same(
            'Example Trading Ltd',
            (string) $afterStatus['two_company_name'],
            'a status-only update must not blank the stored company name'
        );
        TinyAssert::same('FULFILLED', (string) $afterStatus['two_order_state']);

        // Pinned at the column-list level too, not only at the outcome: the
        // status write must not NAME these columns at all. An outcome-only
        // assertion would still pass if the write set them to their previous
        // values by luck of a merge somewhere.
        $lastWrite = end(StubStore::$twoPaymentWrites);
        TinyAssert::same('update', (string) $lastWrite['op']);
        TinyAssert::false(
            array_key_exists('two_organization_number', $lastWrite['data']),
            'a status-only write must not name two_organization_number'
        );
        TinyAssert::false(
            array_key_exists('two_company_name', $lastWrite['data']),
            'a status-only write must not name two_company_name'
        );

        // An explicitly empty value is a different statement from an absent one,
        // and is honoured: that is how a caller would ever clear the snapshot.
        $module->setTwoOrderPaymentData(
            self::ORDER_ID,
            array_merge(self::statusOnlyPaymentData('CANCELLED'), array(
                'two_organization_number' => '',
                'two_company_name' => '',
            ))
        );
        $afterClear = $module->getTwoOrderPaymentData(self::ORDER_ID);
        TinyAssert::same('', (string) $afterClear['two_organization_number']);
        TinyAssert::same('', (string) $afterClear['two_company_name']);
    }

    // -----------------------------------------------------------------
    // Read-back on the update payload
    // -----------------------------------------------------------------

    private static function testUpdatePayloadUsesTheStoredCompany(): void
    {
        self::reset();
        self::seedCart();
        $module = new TwopaymentTestHarness();

        $payload = $module->getTwoUpdateOrderData(self::makeOrder(), array(
            'two_order_reference' => 'ref-' . self::ORDER_ID,
            'two_day_on_invoice' => '30',
            'two_organization_number' => self::TWO_PREFIXED_ID,
            'two_company_name' => 'Example Trading Ltd',
        ));

        TinyAssert::same(
            self::TWO_PREFIXED_ID,
            $payload['buyer']['company']['organization_number'],
            'the update payload must send the organisation number the order was created with'
        );
        TinyAssert::same('Example Trading Ltd', $payload['buyer']['company']['company_name']);
        // The billing address's organisation name comes off the same value, so a
        // stored company name has to reach it too.
        TinyAssert::same('Example Trading Ltd', $payload['billing_address']['organization_name']);
    }

    /**
     * Orders placed before these columns existed have nothing stored, and must
     * keep the previous behaviour rather than start sending blanks. An empty
     * column reads as "no snapshot", NOT as a snapshot of nothing - nothing is
     * backfilled, so that distinction is the only thing keeping old orders
     * working.
     */
    private static function testUpdatePayloadFallsBackWhenNothingIsStored(): void
    {
        self::reset();
        self::seedCart();
        $module = new TwopaymentTestHarness();

        $order = self::makeOrder();

        // No columns in the row at all - a row written before the upgrade.
        $legacy = $module->getTwoUpdateOrderData($order, array(
            'two_order_reference' => 'ref-' . self::ORDER_ID,
            'two_day_on_invoice' => '30',
        ));
        TinyAssert::same(
            'INV-12345678',
            $legacy['buyer']['company']['organization_number'],
            'with nothing stored the resolver must still supply what it can'
        );
        TinyAssert::same('Invoice Co Ltd', $legacy['buyer']['company']['company_name']);

        // Columns present but empty - the same shop after the upgrade ran,
        // before any new order was placed.
        $blank = $module->getTwoUpdateOrderData($order, array(
            'two_order_reference' => 'ref-' . self::ORDER_ID,
            'two_day_on_invoice' => '30',
            'two_organization_number' => '',
            'two_company_name' => '',
        ));
        TinyAssert::same('INV-12345678', $blank['buyer']['company']['organization_number']);
        TinyAssert::same('Invoice Co Ltd', $blank['buyer']['company']['company_name']);
    }

    // -----------------------------------------------------------------
    // Which address the snapshot comes from
    // -----------------------------------------------------------------

    /**
     * buyer.company is resolved from the INVOICE address in
     * getTwoNewOrderData(), so the snapshot has to be too. A buyer whose goods
     * go to a warehouse under a different company would otherwise have the
     * warehouse's organisation number credit-checked as their own on every
     * subsequent order update.
     */
    private static function testSnapshotComesFromTheInvoiceAddressNotTheDeliveryAddress(): void
    {
        self::reset();
        $cart = self::seedCart();
        $module = new TwopaymentTestHarness();

        // Fixture self-check: the two addresses must actually disagree, or this
        // spec would pass against a snapshot taken from either one.
        TinyAssert::notSame(
            StubStore::$addresses[self::INVOICE_ADDRESS_ID]['companyid'],
            StubStore::$addresses[self::DELIVERY_ADDRESS_ID]['companyid'],
            'the fixture must give the two addresses different organisation numbers'
        );

        $snapshot = $module->getTwoOrderCompanySnapshot($cart);

        TinyAssert::same('INV-12345678', $snapshot['two_organization_number']);
        TinyAssert::same('Invoice Co Ltd', $snapshot['two_company_name']);

        // An unloadable cart is not a fatal in the confirmation callback: Two has
        // already approved the order by then.
        $snapshotless = $module->getTwoOrderCompanySnapshot(new Cart(0));
        TinyAssert::same('', $snapshotless['two_organization_number']);
        TinyAssert::same('', $snapshotless['two_company_name']);
    }

    // -----------------------------------------------------------------
    // The prefix
    // -----------------------------------------------------------------

    /**
     * `TWO:` is part of the identifier, not decoration on it. It is the reason
     * the value cannot live on `ps_address.dni` in the first place, and anything
     * that strips or reshapes it here would later ask Two to resolve an
     * identifier it never issued.
     */
    private static function testTwoPrefixedIdentifierRoundTripsByteIdentical(): void
    {
        self::reset();
        self::seedCart();
        $module = new TwopaymentTestHarness();

        // Chosen on the shipping pass and still held against this cart, which is
        // the only place a `TWO:` identifier can come from.
        self::seedSessionCompany(self::TWO_PREFIXED_ID);

        $snapshot = $module->getTwoOrderCompanySnapshot(new Cart(self::CART_ID));
        TinyAssert::same(
            self::TWO_PREFIXED_ID,
            $snapshot['two_organization_number'],
            'the snapshot must keep the TWO: prefix exactly as minted'
        );

        $module->setTwoOrderPaymentData(self::ORDER_ID, array_merge(
            self::statusOnlyPaymentData('VERIFIED'),
            $snapshot
        ));

        $stored = $module->getTwoOrderPaymentData(self::ORDER_ID);
        TinyAssert::same(
            self::TWO_PREFIXED_ID,
            (string) $stored['two_organization_number'],
            'persisting must not transform the prefix'
        );

        $payload = $module->getTwoUpdateOrderData(self::makeOrder(), $stored);
        TinyAssert::same(
            self::TWO_PREFIXED_ID,
            $payload['buyer']['company']['organization_number'],
            'and the update payload must send it back unchanged'
        );
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
        Context::getContext()->cookie = new Cookie();
    }

    /** The `ALTER TABLE ... ADD` column names issued so far, sorted. */
    private static function alterStatements(): array
    {
        $added = [];
        foreach (StubStore::$dbExecuted as $sql) {
            if (preg_match('/ALTER TABLE `[^`]+` ADD `([^`]+)`/', (string) $sql, $m)) {
                $added[] = $m[1];
            }
        }
        sort($added);

        return $added;
    }

    /** @return array<string,string> */
    private static function creationPaymentData(): array
    {
        return array_merge(self::statusOnlyPaymentData('VERIFIED'), array(
            'two_company_name' => 'Example Trading Ltd',
            'two_organization_number' => self::TWO_PREFIXED_ID,
        ));
    }

    /**
     * Exactly what a status/webhook caller passes - every key
     * setTwoOrderPaymentData() reads unconditionally, and no company key.
     *
     * @return array<string,string>
     */
    private static function statusOnlyPaymentData(string $state): array
    {
        return array(
            'two_order_id' => 'two-order-uuid',
            'two_order_reference' => 'ref-' . self::ORDER_ID,
            'two_order_state' => $state,
            'two_order_status' => 'APPROVED',
            'two_day_on_invoice' => '30',
            'two_invoice_url' => 'https://example.invalid/invoice',
        );
    }

    /**
     * A company selection held against the current cart, as the checkout AJAX
     * writes it. The country has to agree with the invoice address or the
     * resolver's own guard discards the record before it is ever consulted.
     */
    private static function seedSessionCompany(string $organizationNumber): void
    {
        $cookie = Context::getContext()->cookie;
        $cookie->two_company_name = 'Example Trading Ltd';
        $cookie->two_company_id = $organizationNumber;
        $cookie->two_company_country = 'GB';
        $cookie->two_company_address_id = (string) self::INVOICE_ADDRESS_ID;
        $cookie->two_company_cart_id = (string) self::CART_ID;
        Context::getContext()->cart = new Cart(self::CART_ID);
    }

    /**
     * One 20%-VAT line, no shipping cost, and an invoice address that differs
     * from the delivery address in both company name and organisation number -
     * which is what makes the invoice-vs-delivery assertion above meaningful.
     */
    private static function seedCart(): Cart
    {
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
            'company' => 'Invoice Co Ltd',
            'companyid' => 'INV-12345678',
            'address1' => '1 Invoice Street',
            'city' => 'London',
            'postcode' => 'EC1A 1AA',
            'phone' => '02079460001',
            'loaded' => true,
        ];
        StubStore::$addresses[self::DELIVERY_ADDRESS_ID] = [
            'id_country' => self::COUNTRY_ID,
            'company' => 'Delivery Co Ltd',
            'companyid' => 'DEL-87654321',
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

    /**
     * The order shape getTwoUpdateOrderData() reads: an id, the cart it came
     * from, and a carrier. Deliberately not the Order stub - this path only
     * touches those members, and an anonymous double keeps the fixture honest
     * about that.
     */
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
