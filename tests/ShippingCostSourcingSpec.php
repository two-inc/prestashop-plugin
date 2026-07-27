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
 */
final class ShippingCostSourcingSpec
{
    public static function runAll(): void
    {
        self::testCarrierlessCartKeepsShippingCostAndReconciles();
        self::testIncoherentCartIsRejectedWithSpecificMessage();
        self::testFreeShippingCarrierlessCartStillBuilds();
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
            'firstname' => 'Javier',
            'lastname' => 'Moreno',
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
     * survives into the payload and the submitted order total matches the cart
     * total. This is the LG regression - 29.00 of shipping used to disappear.
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
        // Rate mirrored from the UNROUNDED cart totals: exactly 21%, not the
        // 20.98% the 2dp pair would imply.
        TinyAssert::same('0.21', (string)$shipping['tax_rate']);
        TinyAssert::same('Shipping', (string)$shipping['name'], 'No carrier means the generic shipping label');

        // The submitted order total matches the cart total - the mismatch of
        // exactly the shipping cost is gone.
        TinyAssert::same('150.00', (string)$payload['gross_amount']);
        TinyAssert::same('123.97', (string)$payload['net_amount']);
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
