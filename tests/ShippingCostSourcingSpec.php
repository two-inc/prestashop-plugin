<?php

declare(strict_types=1);

/**
 * TWO-25161 - shipping-cost sourcing must not depend on a Carrier object.
 *
 * A merchant who prices shipping through symbolic "logistics carriers" leaves
 * `id_carrier = 0` on a cart that still carries a real shipping cost. The old
 * builder keyed the SHIPPING_FEE line off `new Carrier($cart->id_carrier)`, so
 * that cost silently vanished from the payload and the cart-vs-lines
 * reconciliation gate rejected the order with an order-total mismatch of
 * exactly the shipping amount.
 *
 * The cart is now the authority (Cart::getOrderTotal(..., Cart::ONLY_SHIPPING)).
 * `id_carrier = 0` is still NOT blessed: the protection that matters - the
 * cart's totals must be internally coherent - is unchanged, and a genuinely
 * incoherent cart is still refused, now with a message that names the amounts.
 *
 * The shipping tax RATE is never derived from those amounts. It is relayed from
 * the tax-rules group declared by the carriers in the cart's own selected
 * delivery option, which stay enumerable even with `id_carrier = 0`. When no
 * such carrier exists at all (PrestaShop's carrier_list = [0 => 0] sentinel)
 * the order is refused rather than shipped with a guessed rate.
 */
final class ShippingCostSourcingSpec
{
    public static function runAll(): void
    {
        self::testCarrierlessCartKeepsShippingCostAndReconciles();
        self::testIncoherentCartIsRejectedWithSpecificMessage();
        self::testFreeShippingCarrierlessCartStillBuilds();
        self::testUnloadableCarrierRefusesRatherThanGuessTheRate();
        self::testMixedDeclaredRatesSplitIntoOneLinePerRate();
        self::testStaleIdCarrierOnMultiAddressCartStillSplitsPerRate();
    }

    /**
     * Core-shaped delivery-option fixture: one address, one option key, one
     * entry per carrier in `carrier_list` with a loaded `Carrier` instance -
     * exactly what Cart::getDeliveryOptionList() hands back.
     *
     * @param array<int,array{net:float,gross:float,group:int}> $carriers By carrier id
     */
    private static function seedDeliveryOption(int $cartId, int $addressId, array $carriers): void
    {
        $carrierList = [];
        $optionKey = '';
        foreach ($carriers as $idCarrier => $spec) {
            StubStore::$carriers[$idCarrier] = [
                'name' => 'Carrier ' . $idCarrier,
                'delay' => '',
                'tax_rules_group_id' => $spec['group'],
            ];
            $carrierList[$idCarrier] = [
                'price_with_tax' => $spec['gross'],
                'price_without_tax' => $spec['net'],
                'instance' => new Carrier($idCarrier),
            ];
            $optionKey .= $idCarrier . ',';
        }

        StubStore::$cartDeliveryOptionLists[$cartId] = [
            $addressId => [$optionKey => ['carrier_list' => $carrierList]],
        ];
    }

    /**
     * Same fixture, merged in rather than replacing: a ship-to-multiple-
     * addresses cart has one delivery-option entry per address, which is the
     * shape core hands back and the shape `id_carrier` cannot represent.
     *
     * @param array<int,array{net:float,gross:float,group:int}> $carriers By carrier id
     */
    private static function seedDeliveryOptionForAddress(int $cartId, int $addressId, array $carriers): void
    {
        $existing = StubStore::$cartDeliveryOptionLists[$cartId] ?? [];
        self::seedDeliveryOption($cartId, $addressId, $carriers);
        StubStore::$cartDeliveryOptionLists[$cartId] = $existing + StubStore::$cartDeliveryOptionLists[$cartId];
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

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
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

    /**
     * Single 21% product line, 100.00 net / 121.00 gross, on a cart with NO
     * carrier at all (StubStore::$carriers left empty, so `new Carrier(0)` is
     * an unloaded object exactly like a shop whose shipping is priced outside
     * the carrier table).
     */
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

    /**
     * (a) id_carrier = 0 with a non-zero shipping total: the shipping cost
     * survives into the payload, the submitted order total matches the cart
     * total, and the rate is the one DECLARED by the carrier in the cart's
     * selected delivery option - not one computed from the amounts.
     */
    private static function testCarrierlessCartKeepsShippingCostAndReconciles(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        self::seedCommonFixtures(9101, 9111);
        $cart = new Cart(9101);
        $cart->id_customer = 9101;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 9111;
        $cart->id_address_delivery = 9111;
        $cart->id_lang = 1;
        // The whole point: no carrier is selectable on this cart.
        $cart->id_carrier = 0;
        self::seedProductLine($cart, 9121);

        // The carrier that priced the shipping is still enumerable from the
        // cart's delivery option even though id_carrier is 0, and it declares
        // tax-rules group 7101 = 21%.
        StubStore::$taxRuleRates[7101] = 21.0;
        self::seedDeliveryOption(9101, 9111, [
            7001 => ['net' => 23.97, 'gross' => 29.00, 'group' => 7101],
        ]);

        // 29.00 gross shipping at 21% -> 23.9669 net (unrounded, as PrestaShop
        // reports it). Cart total 121.00 + 29.00 = 150.00 gross / 123.9669 net.
        StubStore::$cartTotals[9101] = [
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

        $payload = $module->getTwoNewOrderData('merchant-attempt-9101', $cart, self::merchantUrls());

        $shippingLines = [];
        foreach ($payload['line_items'] as $line) {
            if ((string)($line['type'] ?? '') === 'SHIPPING_FEE') {
                $shippingLines[] = $line;
            }
        }

        TinyAssert::count(1, $shippingLines);
        $shipping = $shippingLines[0];
        TinyAssert::same('29.00', (string)$shipping['gross_amount'], 'Shipping gross must come from the cart, not a Carrier');
        TinyAssert::same('23.97', (string)$shipping['net_amount']);
        TinyAssert::same('5.03', (string)$shipping['tax_amount']);
        // DECLARED, not derived: 21% comes from the carrier's tax-rules group.
        // The 2dp amounts on this line imply 20.98% (5.03 / 23.97), so a
        // derivation would have relayed the wrong rate here.
        TinyAssert::same('0.21', (string)$shipping['tax_rate']);
        TinyAssert::same('Shipping', (string)$shipping['name'], 'No carrier on the cart means the generic shipping label');

        // The resolved carrier and tax-rules-group IDs are logged, so the case
        // a live cart hits is confirmable from the shop log.
        TinyAssert::true(
            self::loggedContains('carrier=7001 tax_rules_group=7101 rate=21%'),
            'The resolved carrier and tax-rules-group IDs must be logged'
        );

        // The submitted order total matches the cart total - the mismatch of
        // exactly the shipping cost is gone.
        TinyAssert::same('150.00', (string)$payload['gross_amount']);
        TinyAssert::same('123.97', (string)$payload['net_amount']);
    }

    /**
     * (d) The genuine hole, closed loudly: a product with no available carrier
     * makes PrestaShop set carrier_list = [0 => 0], so `new Carrier(0)` is
     * unloadable and no tax-rules group exists - yet getPackageShippingCost()
     * still prices the shipping through its internal default-carrier fallback.
     * No rate may be derived, guessed, or silently sent as 0 there.
     */
    private static function testUnloadableCarrierRefusesRatherThanGuessTheRate(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        self::seedCommonFixtures(9104, 9114);
        $cart = new Cart(9104);
        $cart->id_customer = 9104;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 9114;
        $cart->id_address_delivery = 9114;
        $cart->id_lang = 1;
        $cart->id_carrier = 0;
        self::seedProductLine($cart, 9124);

        // PrestaShop's no-available-carrier sentinel, verbatim.
        StubStore::$cartDeliveryOptionLists[9104] = [
            9114 => ['0,' => ['carrier_list' => [0 => 0]]],
        ];

        StubStore::$cartTotals[9104] = [
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

        TinyAssert::throws(
            static function () use ($module, $cart): void {
                $module->getTwoNewOrderData('merchant-attempt-9104', $cart, self::merchantUrls());
            },
            'No deliverable carrier for the cart shipping cost: PrestaShop reports no available carrier ' .
            '(carrier_list = [0 => 0]) for this cart, so there is no declared shipping tax-rules group to relay'
        );

        // The refusal names the condition and the numbers behind it.
        TinyAssert::true(
            self::loggedContains('cart 9104, id_carrier=0, shipping=29.00'),
            'The refusal must log the cart, id_carrier and shipping amount'
        );
    }

    /**
     * (e) A delivery option spanning carriers with DIFFERENT declared rates is
     * split into one SHIPPING_FEE line per rate, weighted by the per-carrier
     * nets already in carrier_list. No blended rate, no arbitrary pick, and the
     * per-rate amounts still sum to the cart's own shipping total.
     */
    private static function testMixedDeclaredRatesSplitIntoOneLinePerRate(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        self::seedCommonFixtures(9105, 9115);
        $cart = new Cart(9105);
        $cart->id_customer = 9105;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 9115;
        $cart->id_address_delivery = 9115;
        $cart->id_lang = 1;
        // Two carriers in one option means core cannot put a single carrier id
        // on the cart, which is precisely the mixed-rate case.
        $cart->id_carrier = 0;
        self::seedProductLine($cart, 9125);

        StubStore::$taxRuleRates[7201] = 21.0;
        StubStore::$taxRuleRates[7202] = 10.0;
        self::seedDeliveryOption(9105, 9115, [
            7011 => ['net' => 10.00, 'gross' => 12.10, 'group' => 7201],
            7012 => ['net' => 16.00, 'gross' => 17.60, 'group' => 7202],
        ]);

        // Shipping 29.70 gross / 26.00 net; cart 121.00 + 29.70 = 150.70 gross,
        // 100.00 + 26.00 = 126.00 net.
        StubStore::$cartTotals[9105] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 29.70,
                Cart::BOTH => 150.70,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 26.00,
                Cart::BOTH => 126.00,
            ],
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-9105', $cart, self::merchantUrls());

        $shippingLines = [];
        foreach ($payload['line_items'] as $line) {
            if ((string)($line['type'] ?? '') === 'SHIPPING_FEE') {
                $shippingLines[] = $line;
            }
        }

        TinyAssert::count(2, $shippingLines, 'Mixed declared rates must emit one shipping line per rate');

        $byRate = [];
        $grossSum = 0.0;
        $netSum = 0.0;
        $taxSum = 0.0;
        foreach ($shippingLines as $line) {
            $byRate[(string)$line['tax_rate']] = $line;
            $grossSum += (float)$line['gross_amount'];
            $netSum += (float)$line['net_amount'];
            $taxSum += (float)$line['tax_amount'];
        }

        TinyAssert::true(isset($byRate['0.21']), 'The 21% carrier must keep its own declared rate');
        TinyAssert::true(isset($byRate['0.1']), 'The 10% carrier must keep its own declared rate');
        TinyAssert::same('10.00', (string)$byRate['0.21']['net_amount']);
        TinyAssert::same('2.10', (string)$byRate['0.21']['tax_amount']);
        TinyAssert::same('16.00', (string)$byRate['0.1']['net_amount']);
        TinyAssert::same('1.60', (string)$byRate['0.1']['tax_amount']);

        // Per-rate amounts sum to the cart's authoritative shipping total.
        TinyAssert::same('29.70', number_format($grossSum, 2, '.', ''));
        TinyAssert::same('26.00', number_format($netSum, 2, '.', ''));
        TinyAssert::same('3.70', number_format($taxSum, 2, '.', ''));

        // Both carriers and both groups are logged.
        TinyAssert::true(
            self::loggedContains('carrier=7011 tax_rules_group=7201 rate=21%; carrier=7012 tax_rules_group=7202 rate=10%'),
            'Both resolved carriers and tax-rules groups must be logged'
        );

        TinyAssert::same('150.70', (string)$payload['gross_amount']);
        TinyAssert::same('126.00', (string)$payload['net_amount']);
    }

    /**
     * (f) The stale-`id_carrier` case: core's Cart::setDeliveryOption() only
     * recomputes `id_carrier` when the cart has a SINGLE delivery option, so a
     * ship-to-multiple-addresses cart keeps a non-zero, loadable `id_carrier`
     * while its shipping total spans several tax-rules groups.
     *
     * Gating the per-rate split on carrier loadability applied that one
     * carrier's declared group to the whole shipping total, and - the reason
     * this is not a cosmetic edge case - the resulting blend lands INSIDE the
     * 2-cent reconciliation tolerance, so it passed silently and put a wrong
     * declared rate on a real invoice. Here: 25.00 at 21% plus 1.00 at 20% is
     * 5.45 tax, while 26.00 at a flat 21% implies 5.46 - one cent of divergence,
     * well inside TAX_FORMULA_TOLERANCE.
     *
     * The decision must come from how many groups the delivery option spans,
     * never from whether `id_carrier` loads.
     */
    private static function testStaleIdCarrierOnMultiAddressCartStillSplitsPerRate(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        self::seedCommonFixtures(9106, 9116);
        $cart = new Cart(9106);
        $cart->id_customer = 9106;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 9116;
        $cart->id_address_delivery = 9116;
        $cart->id_lang = 1;
        self::seedProductLine($cart, 9126);

        StubStore::$taxRuleRates[7301] = 21.0;
        StubStore::$taxRuleRates[7302] = 20.0;
        // Two packages, one per delivery address, each with its own carrier and
        // its own declared tax-rules group.
        self::seedDeliveryOption(9106, 9116, [
            7021 => ['net' => 25.00, 'gross' => 30.25, 'group' => 7301],
        ]);
        self::seedDeliveryOptionForAddress(9106, 9117, [
            7022 => ['net' => 1.00, 'gross' => 1.20, 'group' => 7302],
        ]);

        // The stale value core left behind: carrier 7021 loads, and its 21% is
        // the rate the old gate would have applied to all 26.00.
        $cart->id_carrier = 7021;

        // Shipping 31.45 gross / 26.00 net (5.25 + 0.20 tax).
        StubStore::$cartTotals[9106] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 31.45,
                Cart::BOTH => 152.45,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 26.00,
                Cart::BOTH => 126.00,
            ],
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-9106', $cart, self::merchantUrls());

        $shippingLines = [];
        foreach ($payload['line_items'] as $line) {
            if ((string)($line['type'] ?? '') === 'SHIPPING_FEE') {
                $shippingLines[] = $line;
            }
        }

        TinyAssert::count(
            2,
            $shippingLines,
            'A stale id_carrier must not collapse a multi-group delivery option into one rate'
        );

        $byRate = [];
        $grossSum = 0.0;
        $netSum = 0.0;
        $taxSum = 0.0;
        foreach ($shippingLines as $line) {
            $byRate[(string)$line['tax_rate']] = $line;
            $grossSum += (float)$line['gross_amount'];
            $netSum += (float)$line['net_amount'];
            $taxSum += (float)$line['tax_amount'];
        }

        TinyAssert::true(isset($byRate['0.21']), 'The 21% package keeps its own declared rate');
        TinyAssert::true(isset($byRate['0.2']), 'The 20% package keeps its own declared rate');
        TinyAssert::same('25.00', (string)$byRate['0.21']['net_amount']);
        TinyAssert::same('5.25', (string)$byRate['0.21']['tax_amount']);
        TinyAssert::same('1.00', (string)$byRate['0.2']['net_amount']);
        TinyAssert::same('0.20', (string)$byRate['0.2']['tax_amount']);

        // Cent-exact against PrestaShop's own shipping totals, so nothing hides
        // in the reconciliation tolerance.
        TinyAssert::same('31.45', number_format($grossSum, 2, '.', ''));
        TinyAssert::same('26.00', number_format($netSum, 2, '.', ''));
        TinyAssert::same('5.45', number_format($taxSum, 2, '.', ''));

        // Both carriers were resolved from the delivery option even though
        // id_carrier pointed at one of them.
        TinyAssert::true(
            self::loggedContains('carrier=7021 tax_rules_group=7301 rate=21%'),
            'The delivery option carrier list must be walked, not id_carrier'
        );
        TinyAssert::true(
            self::loggedContains('carrier=7022 tax_rules_group=7302 rate=20%'),
            'The second package carrier must be resolved too'
        );

        TinyAssert::same('152.45', (string)$payload['gross_amount']);
        TinyAssert::same('126.00', (string)$payload['net_amount']);
    }

    /**
     * (b) A genuinely incoherent cart (line items + shipping + tax do not add
     * up to the cart's own order total) is still refused - and the refusal
     * names the amounts instead of the old opaque one-liner.
     */
    private static function testIncoherentCartIsRejectedWithSpecificMessage(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        self::seedCommonFixtures(9102, 9112);
        $cart = new Cart(9102);
        $cart->id_customer = 9102;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 9112;
        $cart->id_address_delivery = 9112;
        $cart->id_lang = 1;
        $cart->id_carrier = 0;
        self::seedProductLine($cart, 9122);

        // Cart claims a 150.00 gross total but reports NO shipping and no
        // discount - 29.00 of it is attributable to nothing the cart exposes.
        // Nothing can build a payload that reconciles with that.
        StubStore::$cartTotals[9102] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 0.00,
                Cart::BOTH => 150.00,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 0.00,
                Cart::BOTH => 123.97,
            ],
        ];

        TinyAssert::throws(
            static function () use ($module, $cart): void {
                $module->getTwoNewOrderData('merchant-attempt-9102', $cart, self::merchantUrls());
            },
            'Order totals do not reconcile with cart totals: cart total 150.00 vs order lines 121.00 (difference 29.00)'
        );
    }

    /**
     * (c) A carrier-less cart that genuinely has no shipping cost (free
     * shipping) still builds - no phantom shipping line, totals still match.
     */
    private static function testFreeShippingCarrierlessCartStillBuilds(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        self::seedCommonFixtures(9103, 9113);
        $cart = new Cart(9103);
        $cart->id_customer = 9103;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 9113;
        $cart->id_address_delivery = 9113;
        $cart->id_lang = 1;
        $cart->id_carrier = 0;
        self::seedProductLine($cart, 9123);

        StubStore::$cartTotals[9103] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 0.00,
                Cart::BOTH => 121.00,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::ONLY_SHIPPING => 0.00,
                Cart::BOTH => 100.00,
            ],
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-9103', $cart, self::merchantUrls());

        foreach ($payload['line_items'] as $line) {
            TinyAssert::notSame('SHIPPING_FEE', (string)($line['type'] ?? ''), 'A zero-shipping cart must emit no shipping line');
        }
        TinyAssert::same('121.00', (string)$payload['gross_amount']);
        TinyAssert::same('100.00', (string)$payload['net_amount']);
    }
}
