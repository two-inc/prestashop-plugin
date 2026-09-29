<?php

declare(strict_types=1);

/**
 * TWO-26085: an order update (tracking save, admin edit) PUTs the order as placed, read from order_detail,
 * order_detail_tax, order_carrier, order_cart_rule and the order's wrapping, never from the live cart; and a
 * save that changes nothing PUTs nothing.
 *
 * Placed: 2 x 10.00 at 25% plus 8.00 shipping at 25%. In every row the catalogue price then moves to 12.00,
 * so a payload read from the cart fails each of them, before the row's own change.
 */
final class PlacedOrderUpdateSpec
{
    private const CART = 9600;
    private const ORDER = 9601;
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
        $cases = [
            // [placement beyond the base order, change after the first save, hook, want from the second save, description]
            [$none, $track, 'tracking', 'PUT ' . self::PLACED . ' = 35.00', 'catalogue price changed after placement'],
            [$none, function ($o) use ($track) {
                StubStore::$taxRuleRates[510] = 15.0;
                StubStore::$cartProducts[self::CART][0]['total_wt'] = 27.60;
                self::moveCart(0.00, -2.40);
                $track($o);
            }, 'tracking', 'PUT ' . self::PLACED . ' = 35.00', 'tax rule changed'],
            [$none, function ($o) use ($track) {
                StubStore::$cartTotals[self::CART][false][Cart::ONLY_SHIPPING] = 20.00;
                StubStore::$cartTotals[self::CART][true][Cart::ONLY_SHIPPING] = 25.00;
                self::moveCart(12.00, 15.00);
                $track($o);
            }, 'tracking', 'PUT ' . self::PLACED . ' = 35.00', 'carrier price changed'],
            [function ($o) {
                self::addCartRule($o, 'Spring', 5.00, 4.00, false);
            }, function ($o) use ($track) {
                StubStore::$cartTotals[self::CART][false][Cart::ONLY_DISCOUNTS] = 8.00;
                StubStore::$cartTotals[self::CART][true][Cart::ONLY_DISCOUNTS] = 10.00;
                self::moveCart(-4.00, -5.00);
                $track($o);
            }, 'tracking', 'PUT ' . self::PLACED . '; DIGITAL -4.00/-1.00/-5.00@0.25 = 30.00', 'cart rule edited'],
            [$none, function ($o) {
                StubStore::$orderDetails[0]['product_quantity'] = 3;
                StubStore::$orderDetails[0]['total_price_tax_excl'] = '30.000000';
                StubStore::$orderDetails[0]['total_price_tax_incl'] = '37.500000';
                $o->total_paid_tax_excl += 10.00;
                $o->total_paid_tax_incl += 12.50;
            }, 'edit', 'PUT PHYSICAL 30.00/7.50/37.50@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.25 = 47.50, paid 47.50', 'admin line edit flows through'],
            [$none, $none, 'tracking', 'no PUT', 'tracking save with no change'],
            [function ($o) {
                StubStore::$orderDetails[0]['tax_rate'] = '0.000';
            }, $track, 'tracking', 'PUT ' . self::PLACED . ' = 35.00', 'PS 1.7: order_detail.tax_rate is 0.000, the rate lives in order_detail_tax'],
            [function ($o) {
                StubStore::$taxRuleRates[511] = 15.0;
                self::addLine($o, 9502, 1, 40.00, 15.0, 511);
            }, $track, 'tracking', 'PUT PHYSICAL 20.00/5.00/25.00@0.25; PHYSICAL 40.00/6.00/46.00@0.15; SHIPPING_FEE 8.00/2.00/10.00@0.25 = 81.00', 'multi-rate order'],
            [function ($o) {
                self::addCartRule($o, 'Free shipping', 10.00, 8.00, true);
            }, $track, 'tracking', 'PUT ' . self::PLACED . '; DIGITAL -8.00/-2.00/-10.00@0.25 = 25.00', 'free shipping'],
            [function ($o) {
                Configuration::updateValue('PS_GIFT_WRAPPING_TAX_RULES_GROUP', 510);
                $o->total_wrapping_tax_excl = 2.00;
                $o->total_wrapping_tax_incl = 2.50;
                self::addTotals($o, 2.00, 2.50);
                StubStore::$cartTotals[self::CART][false][Cart::ONLY_WRAPPING] = 2.00;
                StubStore::$cartTotals[self::CART][true][Cart::ONLY_WRAPPING] = 2.50;
            }, function ($o) use ($track) {
                StubStore::$cartTotals[self::CART][false][Cart::ONLY_WRAPPING] = 4.00;
                StubStore::$cartTotals[self::CART][true][Cart::ONLY_WRAPPING] = 5.00;
                self::moveCart(2.00, 2.50);
                $track($o);
            }, 'tracking', 'PUT ' . self::PLACED . '; DIGITAL 2.00/0.50/2.50@0.25 = 37.50', 'gift wrapping'],
        ];

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
                $change($order);
                $module->puts = [];
                if ($hook === 'edit') {
                    $module->hookActionOrderEdited(['order' => $order]);
                } else {
                    $module->hookActionAdminOrdersTrackingNumberUpdate(['order' => $order]);
                }
                $actual = $module->puts === [] ? 'no PUT' : 'PUT ' . self::summarise(end($module->puts));
                if ($hook === 'edit') {
                    $actual .= ', paid ' . number_format((float) $order->payments[0]->amount, 2, '.', '');
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

        return implode('; ', $lines) . ' = ' . $payload['gross_amount'];
    }

    private static function place(): PlacedOrderStub
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        StubStore::$customers[9602] = ['email' => 'buyer@example.com', 'firstname' => 'Nora', 'lastname' => 'Berg', 'loaded' => true];
        StubStore::$currencies[578] = ['iso_code' => 'NOK', 'loaded' => true];
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

        $order = new PlacedOrderStub();
        $order->id = self::ORDER;
        $order->id_cart = self::CART;
        $order->id_carrier = 701;
        $order->id_currency = 578;
        $order->id_customer = 9602;
        $order->id_address_invoice = 9603;
        $order->id_address_delivery = 9603;
        $order->shipping_number = 'TRACK-1';
        $order->payments = [new class () {
            public $amount = 0.0;

            public function save(): bool
            {
                return true;
            }
        }];
        self::addLine($order, 9501, 2, 10.00, 25.0, 510);
        StubStore::$placedCarriers[self::ORDER] = [['shipping_cost_tax_excl' => '8.000000', 'shipping_cost_tax_incl' => '10.000000']];
        $order->carrier_tax_rate = 25.0;
        StubStore::$cartTotals[self::CART][false][Cart::ONLY_SHIPPING] = 8.00;
        StubStore::$cartTotals[self::CART][true][Cart::ONLY_SHIPPING] = 10.00;
        self::addTotals($order, 8.00, 10.00);

        return $order;
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
            'id_order' => self::ORDER, 'id_order_detail' => count(StubStore::$orderDetails) + 1, 'product_id' => $pid,
            'product_name' => 'Item ' . $pid, 'product_quantity' => $qty, 'unit_price_tax_excl' => $unit,
            'total_price_tax_excl' => $net, 'total_price_tax_incl' => $gross, 'tax_rate' => (string) $rate, 'odt' => [$rate],
        ];
        self::addTotals($order, $net, $gross);
    }

    private static function addCartRule(PlacedOrderStub $order, string $name, float $gross, float $net, bool $freeShipping): void
    {
        StubStore::$orderCartRules[self::ORDER][] = [
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
