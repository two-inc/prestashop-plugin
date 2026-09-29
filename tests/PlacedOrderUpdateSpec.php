<?php

declare(strict_types=1);

/**
 * TWO-26085: an order update (tracking save, admin edit) PUTs the order as placed, read from order_detail,
 * order_detail_tax, the order's own totals, order_cart_rule and order_invoice_tax, never from the live cart,
 * catalogue or config; the Two order it targets is the whole Two order, every order a multi-carrier cart split
 * into; and a save that changes nothing PUTs nothing.
 *
 * Placed: 2 x 10.00 at 25% plus 8.00 shipping at 25%. In every row the catalogue price then moves to 12.00,
 * so a payload read from the cart fails each of them, before the row's own change.
 */
final class PlacedOrderUpdateSpec
{
    private const CART = 9600;
    private const ORDER = 9601;
    private const SIBLING = 9611;
    private const PLACED = 'PHYSICAL 20.00/5.00/25.00@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.25';

    public static function runAll(): void
    {
        self::testUpdatesReplayThePlacedOrder();
    }

    private static function testUpdatesReplayThePlacedOrder(): void
    {
        $none = function (PlacedOrderStub $o): void {
        };
        $track = function (PlacedOrderStub $o): void {
            $o->shipping_number = 'TRACK-2';
        };
        $editQty = function (PlacedOrderStub $o): void {
            StubStore::$orderDetails[0]['product_quantity'] = 3;
            StubStore::$orderDetails[0]['total_price_tax_excl'] = '30.000000';
            StubStore::$orderDetails[0]['total_price_tax_incl'] = '37.500000';
            $o->total_paid_tax_excl += 10.00;
            $o->total_paid_tax_incl += 12.50;
        };
        $twoRates = function (PlacedOrderStub $o): void {
            StubStore::$taxRuleRates[511] = 15.0;
            self::addLine($o, 9502, 1, 40.00, 15.0, 511);
        };
        $wrapping = function (PlacedOrderStub $o): void {
            Configuration::updateValue('PS_GIFT_WRAPPING_TAX_RULES_GROUP', 510);
            $o->total_wrapping_tax_excl = 2.00;
            $o->total_wrapping_tax_incl = 2.50;
            self::addTotals($o, 2.00, 2.50);
            StubStore::$cartTotals[self::CART][false][Cart::ONLY_WRAPPING] = 2.00;
            StubStore::$cartTotals[self::CART][true][Cart::ONLY_WRAPPING] = 2.50;
        };
        $cases = [
            // [placement beyond the base order, change after the first save (returns the order the hook fires for, if not the base one), hook, want from the second save, description]
            [$none, $track, 'tracking', 'PUT ' . self::PLACED . ' = 35.00 NOK', 'catalogue price changed after placement'],
            [$none, function ($o) use ($track) {
                StubStore::$taxRuleRates[510] = 15.0;
                StubStore::$cartProducts[self::CART][0]['total_wt'] = 27.60;
                self::moveCart(0.00, -2.40);
                $track($o);
            }, 'tracking', 'PUT ' . self::PLACED . ' = 35.00 NOK', 'tax rule changed'],
            [$none, function ($o) use ($track) {
                StubStore::$cartTotals[self::CART][false][Cart::ONLY_SHIPPING] = 20.00;
                StubStore::$cartTotals[self::CART][true][Cart::ONLY_SHIPPING] = 25.00;
                self::moveCart(12.00, 15.00);
                $track($o);
            }, 'tracking', 'PUT ' . self::PLACED . ' = 35.00 NOK', 'carrier price changed'],
            [$none, function ($o) use ($track) {
                StubStore::$taxRuleRates[520] = 15.0;
                $track($o);
            }, 'tracking', 'PUT ' . self::PLACED . ' = 35.00 NOK', 'carrier tax rule changed'],
            [function ($o) {
                self::addCartRule($o, 'Spring', 5.00, 4.00, false);
            }, function ($o) use ($track) {
                StubStore::$cartTotals[self::CART][false][Cart::ONLY_DISCOUNTS] = 8.00;
                StubStore::$cartTotals[self::CART][true][Cart::ONLY_DISCOUNTS] = 10.00;
                self::moveCart(-4.00, -5.00);
                $track($o);
            }, 'tracking', 'PUT ' . self::PLACED . '; DIGITAL -4.00/-1.00/-5.00@0.25 = 30.00 NOK', 'cart rule edited'],
            [$none, $editQty, 'edit', 'PUT PHYSICAL 30.00/7.50/37.50@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.25 = 47.50 NOK, paid 47.50', 'admin line edit flows through'],
            [$none, $none, 'tracking', 'no PUT', 'tracking save with no change'],
            [function ($o) {
                StubStore::$orderDetails[0]['tax_rate'] = '0.000';
            }, $track, 'tracking', 'PUT ' . self::PLACED . ' = 35.00 NOK', 'PS 1.7: order_detail.tax_rate is 0.000, the rate lives in order_detail_tax'],
            [$twoRates, $track, 'tracking', 'PUT PHYSICAL 20.00/5.00/25.00@0.25; PHYSICAL 40.00/6.00/46.00@0.15; SHIPPING_FEE 8.00/2.00/10.00@0.25 = 81.00 NOK', 'multi-rate order'],
            [function ($o) use ($twoRates) {
                $twoRates($o);
                self::addCartRule($o, 'Ten pct', 7.10, 6.00, false);
            }, $track, 'tracking', 'PUT PHYSICAL 20.00/5.00/25.00@0.25; PHYSICAL 40.00/6.00/46.00@0.15; SHIPPING_FEE 8.00/2.00/10.00@0.25;'
                . ' DIGITAL -2.00/-0.50/-2.50@0.25; DIGITAL -4.00/-0.60/-4.60@0.15 = 73.90 NOK', 'percentage discount over two rates splits over the products, as core charged it'],
            [function ($o) {
                self::addCartRule($o, 'Free shipping', 10.00, 8.00, true);
            }, $track, 'tracking', 'PUT ' . self::PLACED . '; DIGITAL -8.00/-2.00/-10.00@0.25 = 25.00 NOK', 'free shipping'],
            [$wrapping, function ($o) use ($track) {
                StubStore::$cartTotals[self::CART][false][Cart::ONLY_WRAPPING] = 4.00;
                StubStore::$cartTotals[self::CART][true][Cart::ONLY_WRAPPING] = 5.00;
                self::moveCart(2.00, 2.50);
                $track($o);
            }, 'tracking', 'PUT ' . self::PLACED . '; DIGITAL 2.00/0.50/2.50@0.25 = 37.50 NOK', 'gift wrapping'],
            [function ($o) use ($wrapping) {
                $wrapping($o);
                StubStore::$orderInvoiceTaxes[self::ORDER] = [['type' => 'wrapping', 'id_tax' => 31, 'rate' => '25.000']];
            }, function ($o) use ($track) {
                Configuration::updateValue('PS_GIFT_WRAPPING_TAX_RULES_GROUP', 511);
                StubStore::$taxRuleRates[511] = 15.0;
                $track($o);
            }, 'tracking', 'PUT ' . self::PLACED . '; DIGITAL 2.00/0.50/2.50@0.25 = 37.50 NOK', 'gift wrapping at the invoiced rate after the wrapping tax group changed'],
            [function ($o) {
                $o->carrier_tax_rate = 0.0;
            }, $track, 'edit', 'no PUT, paid 35.00, logged TwoPayment: Order 9601 records shipping 8.00 net, 2.00 tax at carrier_tax_rate 0.000%, which do not agree: the order holds no usable shipping rate',
                'no stored shipping rate fails loud rather than resolving a live one'],
            [$none, function ($o) {
                // PS 8 AddProductToOrderHandler adds an order_carrier row for the new invoice, then OrderAmountUpdater sets the first row to the whole order's shipping.
                self::addLine($o, 9503, 1, 10.00, 25.0, 510);
                StubStore::$placedCarriers[self::ORDER][] = ['shipping_cost_tax_excl' => '8.000000', 'shipping_cost_tax_incl' => '10.000000'];
            }, 'edit', 'PUT PHYSICAL 20.00/5.00/25.00@0.25; PHYSICAL 10.00/2.50/12.50@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.25 = 47.50 NOK, paid 47.50',
                'PS 8 product added on a new invoice counts shipping once'],
            [self::splitCart(...), $track, 'tracking',
                'PUT PHYSICAL 20.00/5.00/25.00@0.25; PHYSICAL 40.00/6.00/46.00@0.15; SHIPPING_FEE 12.00/3.00/15.00@0.25 = 86.00 NOK',
                'multi-carrier split: the update carries both orders'],
            [self::splitCart(...), function ($o) {
                $sibling = StubStore::$placedOrders[self::SIBLING];
                StubStore::$orderDetails[1]['product_quantity'] = 2;
                StubStore::$orderDetails[1]['total_price_tax_excl'] = '80.000000';
                StubStore::$orderDetails[1]['total_price_tax_incl'] = '92.000000';
                $sibling->total_paid_tax_excl += 40.00;
                $sibling->total_paid_tax_incl += 46.00;

                return $sibling;
            }, 'edit', 'PUT PHYSICAL 20.00/5.00/25.00@0.25; PHYSICAL 80.00/12.00/92.00@0.15; SHIPPING_FEE 12.00/3.00/15.00@0.25 = 132.00 NOK, paid 132.00',
                'multi-carrier split: editing the other order PUTs the whole Two order and pays the whole reference'],
            [function ($o) {
                $o->module = 'ps_wirepayment';
                $o->payment = 'Bank wire';
                $o->payments = [self::payment('Bank wire', 35.00)];
            }, $editQty, 'edit', 'no PUT, paid 35.00', 'another module\'s order: its payment is untouched'],
            [function ($o) {
                $o->payments = [self::payment('Two', 20.00), self::payment('Bank wire', 15.00)];
            }, $editQty, 'edit', 'PUT PHYSICAL 30.00/7.50/37.50@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.25 = 47.50 NOK, paid 20.00+15.00', 'split payment is untouched'],        ];

        $failures = [];
        foreach ($cases as [$shape, $change, $hook, $expected, $description]) {
            $order = self::place();
            $shape($order);
            $module = new class () extends TwopaymentTestHarness {
                public array $puts = [];

                public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
                {
                    $this->puts[] = $payload;
                    return ['http_status' => 200];
                }
            };
            try {
                $module->hookActionAdminOrdersTrackingNumberUpdate(['order' => $order]);
                StubStore::$cartProducts[self::CART][0]['price'] = 12.00;
                StubStore::$cartProducts[self::CART][0]['total'] = 24.00;
                StubStore::$cartProducts[self::CART][0]['total_wt'] = 30.00;
                self::moveCart(4.00, 5.00);
                $target = $change($order, $module);
                $target = $target instanceof PlacedOrderStub ? $target : $order;
                $module->puts = [];
                PrestaShopLogger::reset();
                if ($hook === 'edit') {
                    $module->hookActionOrderEdited(['order' => $target]);
                } else {
                    $module->hookActionAdminOrdersTrackingNumberUpdate(['order' => $target]);
                }
                $actual = $module->puts === [] ? 'no PUT' : 'PUT ' . self::summarise(end($module->puts));
                if ($hook === 'edit') {
                    $actual .= ', paid ' . implode('+', array_map(fn ($p) => number_format((float) $p->amount, 2, '.', ''), $target->payments));
                }
                $errors = array_column(array_filter(PrestaShopLogger::$logs, fn ($l) => $l['severity'] === 3), 'message');
                if ($errors !== []) {
                    $actual .= ', logged ' . $errors[0];
                }
            } catch (Throwable $e) {
                $actual = 'throws ' . $e->getMessage();
            }
            if ($actual !== $expected) {
                $failures[] = $description . ":\n    want " . $expected . "\n    got  " . $actual;
            }
        }
        TinyAssert::same([], $failures, "second save after placement\n  " . implode("\n  ", $failures));
    }

    private static function summarise(array $payload): string
    {
        $lines = array_map(
            fn ($l) => $l['type'] . ' ' . $l['net_amount'] . '/' . $l['tax_amount'] . '/' . $l['gross_amount'] . '@' . $l['tax_rate'],
            $payload['line_items']
        );

        return implode('; ', $lines) . ' = ' . $payload['gross_amount'] . ' ' . $payload['currency'];
    }

    private static function place(): PlacedOrderStub
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        StubStore::$customers[9602] = ['email' => 'buyer@example.com', 'firstname' => 'Nora', 'lastname' => 'Berg', 'loaded' => true];
        StubStore::$currencies[578] = ['iso_code' => 'NOK', 'loaded' => true];
        Configuration::updateValue('PS_CURRENCY_DEFAULT', 578);
        StubStore::$countries[47] = 'NO';
        StubStore::$addresses[9603] = [
            'id_country' => 47, 'company' => 'Fjord AS', 'companyid' => '912345678', 'address1' => 'Testgata 1',
            'city' => 'Oslo', 'postcode' => '0150', 'phone' => '+4740000000', 'loaded' => true,
        ];
        StubStore::$carriers[701] = ['name' => 'Placed Carrier', 'max_delivery_days' => 3, 'tax_rules_group_id' => 520];
        StubStore::$taxRuleRates[520] = 25.0;
        StubStore::$taxRuleRates[510] = 25.0;
        StubStore::$carts[self::CART] = [
            'id_customer' => 9602, 'id_currency' => 578, 'id_address_invoice' => 9603,
            'id_address_delivery' => 9603, 'id_carrier' => 701, 'id_lang' => 1,
        ];
        StubStore::$cartProducts[self::CART] = [];
        StubStore::$cartTotals[self::CART] = [
            true => [Cart::BOTH => 0.0, Cart::ONLY_DISCOUNTS => 0.0, Cart::ONLY_SHIPPING => 0.0],
            false => [Cart::BOTH => 0.0, Cart::ONLY_DISCOUNTS => 0.0, Cart::ONLY_SHIPPING => 0.0],
        ];
        StubStore::$twoPaymentRows[self::ORDER] = [
            'id_order' => self::ORDER, 'two_order_id' => 'two-9601', 'two_order_reference' => 'ref-9601', 'two_day_on_invoice' => '30',
        ];

        $order = self::newOrder(self::ORDER, 701);
        $order->shipping_number = 'TRACK-1';
        self::addLine($order, 9501, 2, 10.00, 25.0, 510);
        self::addShipping($order, 8.00, 10.00);

        return $order;
    }

    private static function newOrder(int $id, int $carrier): PlacedOrderStub
    {
        $order = new PlacedOrderStub();
        $order->id = $id;
        $order->id_cart = self::CART;
        $order->id_carrier = $carrier;
        $order->id_currency = 578;
        $order->id_customer = 9602;
        $order->id_address_invoice = 9603;
        $order->id_address_delivery = 9603;
        $order->reference = 'PLACEDREF';
        $order->payments = [self::payment('Two', 0.0)];
        StubStore::$placedOrders[$id] = $order;

        return $order;
    }

    /** Core splits a cart whose products ship with different carriers into one order per carrier; only the first carries the Two row. */
    private static function splitCart(PlacedOrderStub $order): void
    {
        StubStore::$carriers[702] = ['name' => 'Second Carrier', 'max_delivery_days' => 5, 'tax_rules_group_id' => 520];
        StubStore::$taxRuleRates[511] = 15.0;
        $sibling = self::newOrder(self::SIBLING, 702);
        $sibling->payments = $order->payments;
        self::addLine($sibling, 9502, 1, 40.00, 15.0, 511);
        self::addShipping($sibling, 4.00, 5.00);
    }

    private static function payment(string $method, float $amount): object
    {
        return new class ($method, $amount) {
            public function __construct(public string $payment_method, public float $amount)
            {
            }

            public function save(): bool
            {
                return true;
            }
        };
    }

    /** One product row, in the live cart and as core records it on the order. */
    private static function addLine(PlacedOrderStub $order, int $pid, int $qty, float $unit, float $rate, int $group): void
    {
        $net = round($qty * $unit, 2);
        $gross = round($net * (1 + $rate / 100), 2);
        StubStore::$products[$pid] = ['id_tax_rules_group' => $group, 'link_rewrite' => 'item-' . $pid];
        StubStore::$cartProducts[self::CART][] = [
            'id_product' => $pid, 'link_rewrite' => 'item-' . $pid, 'name' => 'Item ' . $pid, 'description_short' => '',
            'manufacturer_name' => '', 'ean13' => '', 'upc' => '', 'total' => $net, 'total_wt' => $gross,
            'cart_quantity' => $qty, 'rate' => $rate, 'price' => $unit, 'reduction' => 0,
        ];
        StubStore::$orderDetails[] = [
            'id_order' => $order->id, 'id_order_detail' => count(StubStore::$orderDetails) + 1, 'product_id' => $pid,
            'product_name' => 'Item ' . $pid, 'product_quantity' => $qty, 'unit_price_tax_excl' => $unit,
            'total_price_tax_excl' => $net, 'total_price_tax_incl' => $gross, 'tax_rate' => (string) $rate, 'odt' => [$rate],
        ];
        self::addTotals($order, $net, $gross);
    }

    /** The order's shipping, at the carrier rate core records on it, and its order_carrier row. */
    private static function addShipping(PlacedOrderStub $order, float $net, float $gross): void
    {
        StubStore::$placedCarriers[$order->id] = [['shipping_cost_tax_excl' => (string) $net, 'shipping_cost_tax_incl' => (string) $gross]];
        $order->total_shipping_tax_excl = $net;
        $order->total_shipping_tax_incl = $gross;
        $order->carrier_tax_rate = 25.0;
        StubStore::$cartTotals[self::CART][false][Cart::ONLY_SHIPPING] += $net;
        StubStore::$cartTotals[self::CART][true][Cart::ONLY_SHIPPING] += $gross;
        self::addTotals($order, $net, $gross);
    }

    private static function addCartRule(PlacedOrderStub $order, string $name, float $gross, float $net, bool $freeShipping): void
    {
        StubStore::$orderCartRules[$order->id][] = [
            'name' => $name, 'value' => $gross, 'value_tax_excl' => $net, 'free_shipping' => $freeShipping ? '1' : '0',
        ];
        $order->total_discounts_tax_incl += $gross;
        StubStore::$cartTotals[self::CART][false][Cart::ONLY_DISCOUNTS] += $net;
        StubStore::$cartTotals[self::CART][true][Cart::ONLY_DISCOUNTS] += $gross;
        self::addTotals($order, -$net, -$gross);
    }

    private static function addTotals(PlacedOrderStub $order, float $net, float $gross): void
    {
        $order->total_paid_tax_excl = round($order->total_paid_tax_excl + $net, 2);
        $order->total_paid_tax_incl = round($order->total_paid_tax_incl + $gross, 2);
        self::moveCart($net, $gross);
    }

    /** The live cart's own total, kept consistent with its rows so a cart-sourced payload still builds. */
    private static function moveCart(float $net, float $gross): void
    {
        StubStore::$cartTotals[self::CART][false][Cart::BOTH] = round(StubStore::$cartTotals[self::CART][false][Cart::BOTH] + $net, 2);
        StubStore::$cartTotals[self::CART][true][Cart::BOTH] = round(StubStore::$cartTotals[self::CART][true][Cart::BOTH] + $gross, 2);
    }
}
