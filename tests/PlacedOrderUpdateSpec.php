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
        self::testHashColumnIsAddedAndAFailureIsLogged();
    }

    private static function testHashColumnIsAddedAndAFailureIsLogged(): void
    {
        $alters = fn () => count(preg_grep('/ALTER TABLE `ps_twopayment` ADD `(two_update_hash` VARCHAR\(32\)|two_declared_rates` TEXT) NULL/', StubStore::$dbExecuted));
        StubStore::reset();
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.17.php';
        $module = new TwopaymentTestHarness();
        TinyAssert::true(upgrade_module_2_7_17($module), 'the upgrade script must report success');
        TinyAssert::true(upgrade_module_2_7_17($module), 'a re-run must still succeed');
        TinyAssert::same(2, $alters(), 'the upgrade adds each column once');

        StubStore::reset();
        $module = new TwopaymentTestHarness();
        $module->setTwoOrderPaymentData(self::ORDER, [
            'two_order_id' => 'two-9601', 'two_order_reference' => 'ref', 'two_order_state' => 'VERIFIED', 'two_order_status' => 'APPROVED',
            'two_day_on_invoice' => '30', 'two_invoice_url' => '', 'two_declared_rates' => '{"shipping":[],"wrapping":0.25}',
        ]);
        $module->setTwoOrderPaymentData(self::ORDER, [
            'two_order_id' => 'two-9601', 'two_order_reference' => 'ref', 'two_order_state' => 'FULFILLED', 'two_order_status' => 'APPROVED',
            'two_day_on_invoice' => '30', 'two_invoice_url' => '',
        ]);
        TinyAssert::same(pSQL('{"shipping":[],"wrapping":0.25}'), StubStore::$twoPaymentRows[self::ORDER]['two_declared_rates'], 'the declared rates persist, and a status write leaves them');

        StubStore::reset();
        PrestaShopLogger::reset();
        StubStore::$dbFailOn = ['/ADD `two_update_hash`/'];
        $ensure = new ReflectionMethod(Twopayment::class, 'ensureTwoPaymentColumns');
        $columns = $ensure->invoke(new TwopaymentTestHarness());
        StubStore::$dbFailOn = [];
        TinyAssert::false(in_array('two_update_hash', $columns, true), 'a refused ALTER reports the column missing');
        TinyAssert::same(
            ['TwoPayment: Failed to add column two_update_hash to ps_twopayment - its value cannot be persisted on this shop, and this column will be omitted from writes rather than failing them'],
            array_column(array_filter(PrestaShopLogger::$logs, fn ($l) => $l['severity'] === 3), 'message'),
            'a refused ALTER is logged'
        );
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
        // TWO-26274: a merchant handler on the order postprocessing hook, editing each payload with $edit; with
        // $helper it first opts back in to the module's shop-match checks on the payload as built.
        $handler = function (callable $edit, ?TwopaymentTestHarness $helper = null): void {
            Hook::$subscribers[TwoOrderPostprocessing::HOOK]['merchanthandler'] = static function (array $params) use ($edit, $helper): void {
                if ($helper !== null) {
                    $helper->runTwoShopMatchChecks($params['payload']);
                }
                $params['payload'] = $edit($params['payload']);
            };
        };
        $atRate = function (string $type, string $rate): callable {
            return function (array $payload) use ($type, $rate): array {
                foreach ($payload['line_items'] as &$line) {
                    if ($line['type'] === $type) {
                        $line['tax_rate'] = $rate;
                    }
                }
                unset($line);

                return $payload;
            };
        };
        $unchanged = function (array $payload): array {
            return $payload;
        };
        $placedAtUnreconciledDefault = function (PlacedOrderStub $o): void {
            $o->carrier_tax_rate = 0.0;
            self::declare([[0.15, 8.00]], null, false);
            StubStore::$taxRuleRates[520] = 15.0;
        };
        $wrapping = function (PlacedOrderStub $o): void {
            Configuration::updateValue('PS_GIFT_WRAPPING_TAX_RULES_GROUP', 510);
            $o->total_wrapping_tax_excl = 2.00;
            $o->total_wrapping_tax_incl = 2.50;
            self::addTotals($o, 2.00, 2.50);
            StubStore::$cartTotals[self::CART][false][Cart::ONLY_WRAPPING] = 2.00;
            StubStore::$cartTotals[self::CART][true][Cart::ONLY_WRAPPING] = 2.50;
        };
        // Placed at a 10% declared wrapping rate, invoiced at 12%, configured at 15%: none reconciles with 2.00 + 0.50.
        $wrappingUnreconciled = function (PlacedOrderStub $o) use ($wrapping): void {
            $wrapping($o);
            self::declare([], 0.10);
            StubStore::$orderInvoiceTaxes[self::ORDER] = [['type' => 'wrapping', 'id_tax' => 33, 'rate' => '12.000']];
            Configuration::updateValue('PS_GIFT_WRAPPING_TAX_RULES_GROUP', 511);
            StubStore::$taxRuleRates[511] = 15.0;
        };
        // Keeps a line's rate as built and fits its tax and gross to it.
        $fitTax = function (string $type): callable {
            return function (array $payload) use ($type): array {
                foreach ($payload['line_items'] as &$line) {
                    if ($line['type'] === $type) {
                        $line['tax_amount'] = number_format(round((float) $line['net_amount'] * (float) $line['tax_rate'], 2), 2, '.', '');
                        $line['gross_amount'] = number_format((float) $line['net_amount'] + (float) $line['tax_amount'], 2, '.', '');
                    }
                }
                unset($line);

                return (new TwopaymentTestHarness())->recomputeTwoOrderTotals($payload);
            };
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
            [function ($o) use ($wrapping) {
                $wrapping($o);
                self::declare([], 0.25);
            }, function ($o) use ($track) {
                Configuration::updateValue('PS_GIFT_WRAPPING_TAX_RULES_GROUP', 511);
                StubStore::$taxRuleRates[511] = 15.0;
                $track($o);
            }, 'tracking', 'PUT ' . self::PLACED . '; DIGITAL 2.00/0.50/2.50@0.25 = 37.50 NOK', 'gift wrapping at the declared rate before any invoice'],
            [function ($o) use ($wrapping) {
                $wrapping($o);
                StubStore::$orderInvoiceTaxes[self::ORDER] = [
                    ['type' => 'wrapping', 'id_order_invoice' => 1, 'id_tax' => 31, 'rate' => '25.000'],
                    ['type' => 'wrapping', 'id_order_invoice' => 2, 'id_tax' => 32, 'rate' => '12.000'],
                ];
            }, $track, 'edit', 'no PUT, paid 37.50, logged TwoPayment: Order 9601 invoices record gift wrapping at different rates (25%, 12%): the order holds no single wrapping rate, marked not sent',
                'invoices disagreeing on the wrapping rate fail loud'],
            [$wrapping, function ($o) use ($track) {
                Configuration::updateValue('PS_GIFT_WRAPPING_TAX_RULES_GROUP', 511);
                StubStore::$taxRuleRates[511] = 15.0;
                $track($o);
            }, 'edit', 'no PUT, paid 37.50, logged TwoPayment: Order 9601 records gift wrapping 2.00 net, 0.50 tax, which no stored or configured rate reconciles with (invoiced none, declared at placement none, configured 15%), marked not sent', 'gift wrapping no stored or configured rate reconciles with: refused'],
            [$wrapping, function ($o) use ($track, $handler, $atRate) {
                Configuration::updateValue('PS_GIFT_WRAPPING_TAX_RULES_GROUP', 511);
                StubStore::$taxRuleRates[511] = 15.0;
                $handler($atRate('DIGITAL', '0.25'));
                $track($o);
            }, 'edit', 'PUT PHYSICAL 20.00/5.00/25.00@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.25; DIGITAL 2.00/0.50/2.50@0.25 = 37.50 NOK, paid 37.50', 'the same, with a merchant handler that declares the rate the amounts carry: sent as returned'],
            // No candidate reconciles, and the invoice's rate differs from the one declared at placement: a merchant handler
            // is shown the declared rate the create sent (here it keeps that rate and fits the tax to it).
            [$wrappingUnreconciled, function ($o) use ($track) {
                $track($o);
            }, 'edit', 'no PUT, paid 37.50, logged TwoPayment: Order 9601 records gift wrapping 2.00 net, 0.50 tax, which no stored or configured rate reconciles with (invoiced 12%, declared at placement 10%, configured 15%), marked not sent', 'gift wrapping at an invoiced rate other than the declared one, neither reconciling, no merchant handler: refused'],
            [$wrappingUnreconciled, function ($o) use ($track, $handler, $fitTax) {
                $handler($fitTax('DIGITAL'));
                $track($o);
            }, 'edit', 'PUT PHYSICAL 20.00/5.00/25.00@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.25; DIGITAL 2.00/0.20/2.20@0.1 = 37.20 NOK, paid 37.50', 'the same, with a merchant handler: the wrapping line at the declared rate'],
            [function ($o) {
                // PaymentModule writes carrier_tax_rate only when a Carrier loads, so a carrier-less order records 0.000.
                $o->id_carrier = 0;
                StubStore::$carts[self::CART]['id_carrier'] = 0;
                $o->carrier_tax_rate = 0.0;
                self::enableDefaultShippingTaxCode(520);
            }, $track, 'tracking', 'PUT ' . self::PLACED . ' = 35.00 NOK', 'carrier-less order at the Default shipping tax code'],
            [function ($o) {
                $o->carrier_tax_rate = 0.0;
                self::declare([[0.25, 8.00]]);
            }, $track, 'tracking', 'PUT ' . self::PLACED . ' = 35.00 NOK', 'no carrier_tax_rate: the rates declared at placement'],
            [function ($o) {
                self::declare([[0.15, 8.00]]);
            }, $track, 'tracking', 'PUT ' . self::PLACED . ' = 35.00 NOK', 'carrier_tax_rate wins over the declared rates'],
            [function ($o) {
                $o->carrier_tax_rate = 0.0;
                self::declare([[0.15, 8.00]]);
                StubStore::$taxRuleRates[511] = 15.0;
                self::enableDefaultShippingTaxCode(511);
            }, $track, 'edit', 'PUT PHYSICAL 20.00/5.00/25.00@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.15 = 35.00 NOK, paid 35.00',
                'placed before the record, no shipping rate reconciles: the declared rate goes out as is'],
            // TWO-26117: what placement recorded decides, never today's carrier or config.
            [function ($o) {
                $o->carrier_tax_rate = 0.0;
                self::declare([], null, false);
                self::enableDefaultShippingTaxCode(520);
            }, $track, 'tracking', 'PUT PHYSICAL 20.00/5.00/25.00@0.25; SHIPPING_FEE 8.00/2.00/10.00@0 = 35.00 NOK', 'no rate and no Default shipping tax code at placement: 0% with the tax charged, whatever the config says now'],
            [function ($o) {
                $o->carrier_tax_rate = 0.0;
                self::declare([[0.25, 8.00]], null, false);
            }, $track, 'tracking', 'PUT ' . self::PLACED . ' = 35.00 NOK', 'no rate, placed at the Default shipping tax code: that rate, checked'],
            [function ($o) {
                $o->carrier_tax_rate = 0.0;
                self::declare([[0.15, 8.00]], null, false);
                StubStore::$taxRuleRates[520] = 15.0;
            }, $track, 'edit', 'no PUT, paid 35.00, logged TwoPayment: Declared tax rate does not reconcile with applied amounts for shipping (Placed Carrier).'
                . ' Declared rate=15%, net=8.00, applied tax=2.00, expected tax at declared rate=1.20. Check the tax rules configured for this line (tax rules group, address-specific rules)., marked not sent', 'no rate, the Default shipping tax code it was placed at does not reconcile: refused'],
            [function ($o) {
                $o->carrier_tax_rate = 0.0;
                self::declare([[0.15, 8.00]], null, true);
                self::enableDefaultShippingTaxCode(520);
            }, $track, 'tracking', 'PUT PHYSICAL 20.00/5.00/25.00@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.15 = 35.00 NOK', 'a rate the carrier provided that does not reconcile goes out as is, never swapped for the config'],
            [$placedAtUnreconciledDefault, function ($o) use ($handler, $unchanged) {
                $handler($unchanged);
            }, 'edit', 'PUT PHYSICAL 20.00/5.00/25.00@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.15 = 35.00 NOK, paid 35.00', 'the same, with a merchant handler that leaves the line: the shop-match check stands down, and the line goes to Two as it is, whose API judges its arithmetic (TWO-26283)'],
            [$placedAtUnreconciledDefault, function ($o) use ($handler, $atRate) {
                $handler($atRate('SHIPPING_FEE', '0.25'));
            }, 'edit', 'PUT PHYSICAL 20.00/5.00/25.00@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.25 = 35.00 NOK, paid 35.00', 'the same, with a merchant handler that declares the rate the amounts carry: sent as returned'],
            [$placedAtUnreconciledDefault, function ($o, $module) use ($handler, $atRate) {
                $handler($atRate('SHIPPING_FEE', '0.25'), $module);
            }, 'edit', 'no PUT, paid 35.00, logged TwoPayment: Declared tax rate does not reconcile with applied amounts for shipping (Placed Carrier).'
                . ' Declared rate=15%, net=8.00, applied tax=2.00, expected tax at declared rate=1.20. Check the tax rules configured for this line (tax rules group, address-specific rules)., marked not sent', 'the same, with a merchant handler that opts back in to the shop-match checks: refused as the module refuses it'],
            [function ($o) {
                $o->carrier_tax_rate = 0.0;
                $o->total_paid_tax_incl -= 0.40;
                self::declare([[0.25, 4.00], [0.15, 4.00]]);
            }, $track, 'tracking', 'PUT PHYSICAL 20.00/5.00/25.00@0.25; SHIPPING_FEE 4.00/1.00/5.00@0.25; SHIPPING_FEE 4.00/0.60/4.60@0.15 = 34.60 NOK',
                'shipping declared over two rates at placement'],
            [function ($o) {
                // Carrier-priced free shipping records none, while total-level rounding leaves a cent between the rows and the total.
                $o->total_paid_tax_excl -= 8.00;
                $o->total_paid_tax_incl -= 9.99;
                $o->total_shipping_tax_excl = 0.0;
                $o->total_shipping_tax_incl = 0.0;
            }, $track, 'tracking', 'PUT PHYSICAL 20.00/5.00/25.00@0.25 = 25.00 NOK', 'free shipping with a rounding cent sends no shipping line'],
            [$none, function ($o) {
                StubStore::$twoPaymentRows[self::ORDER]['two_not_sent_at'] = '2026-09-30 10:00:00';
            }, 'tracking', 'no PUT', 'an unchanged tracking save clears a stale not-sent marker'],
            [$none, function ($o) {
                // PS 8 AddProductToOrderHandler adds an order_carrier row for the new invoice, then OrderAmountUpdater sets the first row to the whole order's shipping.
                self::addLine($o, 9503, 1, 10.00, 25.0, 510);
                StubStore::$placedCarriers[self::ORDER][] = ['shipping_cost_tax_excl' => '8.000000', 'shipping_cost_tax_incl' => '10.000000'];
            }, 'edit', 'PUT PHYSICAL 20.00/5.00/25.00@0.25; PHYSICAL 10.00/2.50/12.50@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.25 = 47.50 NOK, paid 47.50',
                'PS 8 product added on a new invoice counts shipping once'],
            [$none, function ($o) {
                StubStore::$products[9501]['description_short'] = 'Rewritten catalogue copy';
                StubStore::$products[9501]['manufacturer_name'] = 'Renamed brand';
                StubStore::$images[9501] = ['id_image' => 7];
            }, 'tracking', 'no PUT', 'catalogue text and image changes alone PUT nothing'],
            [self::splitCart(...), $track, 'tracking',
                'PUT PHYSICAL 20.00/5.00/25.00@0.25; PHYSICAL 40.00/6.00/46.00@0.15; SHIPPING_FEE 12.00/3.00/15.00@0.25 = 86.00 NOK',
                'multi-carrier split: the update carries both orders'],
            [function ($o) {
                self::splitCart($o);
                // PS 9 stores the whole cart's shipping on every order of the split, while each order's total charges its own.
                foreach (StubStore::$placedOrders as $placed) {
                    $placed->total_shipping_tax_excl = 12.00;
                    $placed->total_shipping_tax_incl = 15.00;
                }
            }, $track, 'tracking',
                'PUT PHYSICAL 20.00/5.00/25.00@0.25; PHYSICAL 40.00/6.00/46.00@0.15; SHIPPING_FEE 12.00/3.00/15.00@0.25 = 86.00 NOK',
                'PS 9 multi-carrier split: shipping as each order charged it, not the whole cart\'s on each'],
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
            }, $editQty, 'edit', 'PUT PHYSICAL 30.00/7.50/37.50@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.25 = 47.50 NOK, paid 20.00+15.00', 'split payment is untouched'],
            [function ($o) {
                StubStore::$orderDetails[0]['ecotax'] = 1.00;
                StubStore::$orderDetails[0]['ecotax_tax_rate'] = 25.0;
            }, function ($o) use ($track) {
                Configuration::updateValue('PS_ECOTAX_TAX_RULES_GROUP_ID', 511);
                StubStore::$taxRuleRates[511] = 15.0;
                $track($o);
            }, 'tracking', 'PUT PHYSICAL 18.00/4.50/22.50@0.25; SERVICE 2.00/0.50/2.50@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.25 = 35.00 NOK', 'ecotax at its recorded rate'],
            [$twoRates, function ($o) {
                array_shift(StubStore::$orderDetails);
                $o->total_paid_tax_excl -= 20.00;
                $o->total_paid_tax_incl -= 25.00;
            }, 'edit', 'PUT PHYSICAL 40.00/6.00/46.00@0.15; SHIPPING_FEE 8.00/2.00/10.00@0.25 = 56.00 NOK, paid 56.00', 'product removed'],
            [$none, function ($o) use ($track) {
                StubStore::$twoPaymentRows[self::ORDER]['two_update_hash'] = null;
                $track($o);
            }, 'tracking', 'PUT ' . self::PLACED . ' = 35.00 NOK', 'no stored hash PUTs'],
            [$none, function ($o, $module) use ($editQty, $track) {
                $editQty($o);
                $module->hookActionOrderEdited(['order' => $o]);
                $track($o);
            }, 'tracking', 'PUT PHYSICAL 30.00/7.50/37.50@0.25; SHIPPING_FEE 8.00/2.00/10.00@0.25 = 47.50 NOK', 'admin edit then tracking carries both'],
            [$none, function ($o, $module) use ($editQty) {
                $editQty($o);
                $module->hookActionOrderEdited(['order' => $o]);
            }, 'tracking', 'no PUT', 'admin edit then an unchanged tracking save PUTs nothing more'],
            [function ($o) {
                StubStore::$currencies[978] = ['iso_code' => 'EUR', 'conversion_rate' => 0.085, 'loaded' => true];
                StubStore::$carts[self::CART]['id_currency'] = 978;
                $o->id_currency = 978;
            }, $track, 'tracking', 'PUT ' . self::PLACED . ' = 35.00 EUR', 'order in a currency other than the shop default'],
        ];

        $failures = [];
        foreach ($cases as [$shape, $change, $hook, $expected, $description]) {
            $order = self::place();
            $shape($order);
            $module = new class () extends TwopaymentTestHarness {
                public array $puts = [];

                public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
                {
                    // The order's state lookup is not a PUT; with a merchant handler it is read before the build (TWO-26282).
                    if ($method !== 'GET') {
                        $this->puts[] = $payload;
                    }
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
                if (!empty(StubStore::$twoPaymentRows[self::ORDER]['two_not_sent_at'])) {
                    $actual .= ', marked not sent';
                }
                // The snapshot describes a cart being priced; an update prices the placed order, so it writes none.
                if (array_filter(PrestaShopLogger::$logs, fn ($l) => ($l['object_type'] ?? null) === TwoDiscrepancySnapshot::LOG_OBJECT_TYPE) !== []) {
                    $actual .= ', snapshot written';
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

    /** The shipping classes [[rate, net weight], ...] and wrapping rate the create payload declared, as the Two row keeps them. */
    /** @param bool|null $provided whether a carrier provided the shipping rate at placement; null for a row written before TWO-26117 */
    private static function declare(array $shipping, ?float $wrapping = null, ?bool $provided = null): void
    {
        $rates = [
            'shipping' => array_map(fn ($c) => ['rate' => $c[0], 'net_weight' => $c[1]], $shipping),
            'wrapping' => $wrapping,
        ];
        if ($provided !== null) {
            $rates['shipping_rate_provided'] = $provided;
        }
        StubStore::$twoPaymentRows[self::ORDER]['two_declared_rates'] = json_encode($rates);
    }

    private static function enableDefaultShippingTaxCode(int $group): void
    {
        StubStore::$taxRulesGroups[$group] = ['name' => 'Group ' . $group, 'active' => 1];
        Configuration::updateValue('PS_TWO_SHIPPING_TAX_FALLBACK_ENABLED', '1');
        Configuration::updateValue('PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP', (string) $group);
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
