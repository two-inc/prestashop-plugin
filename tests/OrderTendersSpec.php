<?php

declare(strict_types=1);

/**
 * TWO-26326: an order's tenders (a gift card, store credit: anything that has
 * already paid part of the order without being an order line), declared on
 * actionTwoOrderTenders and sent to Two as prepaid_amount.
 *
 * The create and update cases use OrderPostprocessingSpec's cart (150.00 gross:
 * a 121.00 product and 29.00 of shipping), the refund cases RefundSpec's
 * harness.
 */
final class OrderTendersSpec
{
    private const MODULE = 'twotendertest';

    public static function runAll(): void
    {
        self::testCreateDeclaresTendersAsPrepaid();
        self::testNoTenderAndLegacyBodiesAreUnchanged();
        self::testEditAlwaysResendsPrepaid();
        self::testBadDeclarationsAreRefused();
        self::testCartRuleVoucherIsNeverATender();
        self::testValidateOrderAmountIsTheCartTotal();
        self::testCreditSlipSplitsAmountAndPrepaid();
        self::testRefundedRemainderCarriesThePrepaidLeft();
        self::testRecomputeHelperKeepsThePrepaidAmount();
        self::testPlacementRecordsThePrepaidTwoHolds();
        self::testRefundedAfterAWhollyPrepaidRefundSendsTheRest();
        self::testSlipsInARowDrawDownTheTenders();
    }

    /**
     * Slips one after another: each prepaid part comes off what Two still holds of the tenders, and a slip returning
     * more than is left is refused before it is sent.
     */
    private static function testSlipsInARowDrawDownTheTenders(): void
    {
        $held = [];
        // [slip, returned, expected [amount, prepaid] or null when refused]
        foreach ([[50.00, 20.00, ['30.00', '20.00']], [50.00, 10.00, ['40.00', '10.00']], [20.00, 5.00, null]] as $i => [$slip, $returned, $expected]) {
            StubStore::reset();
            PrestaShopLogger::$logs = [];
            Hook::$subscribers = [];
            $twoOrder = RefundSpec::fulfilledOrder(120.00, $held);
            $twoOrder['prepaid_amount'] = '30.00';
            $module = RefundSpec::makeModule($twoOrder, ['two_order_id' => 'two-order-uuid', 'two_declared_rates' => '{"prepaid":"30.00"}']);
            self::declare(static function (array $params) use ($returned): array {
                return $params['order_slip'] !== null ? [self::giftCard($returned)] : [];
            });
            $module->hookActionOrderSlipAdd(['order' => RefundSpec::makeOrder(), 'order_slip' => RefundSpec::makeSlip(700 + $i, $slip)]);
            $refunds = $module->refundCalls();
            if ($expected === null) {
                TinyAssert::count(0, $refunds);
                TinyAssert::count(1, $module->notSent);
                continue;
            }
            TinyAssert::same($expected, [$refunds[0]['payload']['amount'], $refunds[0]['payload']['prepaid_amount']], 'slip ' . ($i + 1));
            $refund = RefundSpec::twoRefund('-' . number_format($slip, 2, '.', ''), []);
            $refund['prepaid_amount'] = '-' . $expected[1];
            $held[] = $refund;
        }
    }

    /**
     * A portal refund made wholly from the prepaid part leaves no refunded amount, but Two's state reads REFUNDED:
     * marking the order Refunded still refunds what is outstanding.
     */
    private static function testRefundedAfterAWhollyPrepaidRefundSendsTheRest(): void
    {
        StubStore::reset();
        PrestaShopLogger::$logs = [];
        Hook::$subscribers = [];
        StubStore::$orders[5100] = ['module' => 'twopayment'];
        StubStore::$configuration['PS_TWO_OS_REFUNDED_MAP'] = 7;
        $portal = RefundSpec::twoRefund('-30.00', ['p1' => '-30.00']);
        $portal['prepaid_amount'] = '-30.00';
        $twoOrder = RefundSpec::fulfilledOrder(120.00, [$portal]);
        $twoOrder['prepaid_amount'] = '30.00';
        $twoOrder['state'] = 'REFUNDED';
        $module = RefundSpec::makeModule($twoOrder, ['two_order_id' => 'two-order-uuid', 'two_declared_rates' => '{"prepaid":"30.00"}']);
        $module->placedSubtotals = [['taxable_amount' => '150.00', 'tax_amount' => '0.00', 'tax_rate' => '0.000000']];
        $status = new OrderState();
        $status->id = 7;
        $status->name = 'Refunded';
        $module->hookActionOrderStatusUpdate(['id_order' => 5100, 'newOrderStatus' => $status]);
        $refunds = $module->refundCalls();
        TinyAssert::count(1, $refunds);
        TinyAssert::same(['120.00', '0.00'], [$refunds[0]['payload']['amount'], $refunds[0]['payload']['prepaid_amount'] ?? null], 'the outstanding amount is refunded');
    }

    /**
     * The row records the prepaid amount Two holds at confirmation, else the one the body sent after the subscribers,
     * never the one the module built before them; none when it is zero.
     */
    private static function testPlacementRecordsThePrepaidTwoHolds(): void
    {
        $strip = static function (array $params): array {
            return array_diff_key($params['payload'], array_flip(['invoice_amount', 'prepaid_amount', 'payable_amount']));
        };
        $add = static function (array $params): array {
            return $params['payload'] + ['prepaid_amount' => '30.00'];
        };
        // [tender, subscriber edit, Two's response, expected prepaid recorded (null: none), description]
        $cases = [
            [30.00, null, ['prepaid_amount' => '30.00'], '30.00', 'Two holds the tenders'],
            [30.00, null, ['data' => ['prepaid_amount' => '25.00']], '25.00', 'Two holds another amount, nested'],
            [30.00, null, ['prepaid_amount' => '0.00'], null, 'Two holds none'],
            [0.0, $add, [], '30.00', 'a subscriber added prepaid, Two says nothing'],
            [30.00, $strip, [], null, 'a subscriber stripped prepaid, Two says nothing'],
        ];
        foreach ($cases as [$tender, $edit, $response, $expected, $description]) {
            $cart = OrderPostprocessingSpec::seed(true);
            $module = new TwopaymentTestHarness();
            OrderPostprocessingSpec::harnessAsInstance($module);
            self::declare(static function () use ($tender): array {
                return [self::giftCard($tender)];
            });
            if ($edit !== null) {
                Hook::$subscribers[TwoOrderPostprocessing::HOOK]['twoorderpostprocessingtest'] = static function (array $params) use ($edit): void {
                    $params['payload'] = $edit($params);
                };
            }
            self::createFor($module, $cart);
            Hook::$subscribers = [];
            $record = json_decode((string) $module->getTwoDeclaredChargeRates($response), true);
            TinyAssert::same($expected, is_array($record) ? ($record['prepaid'] ?? null) : null, $description);
        }
        TinyAssert::true(strpos((string) file_get_contents(__DIR__ . '/../controllers/front/confirmation.php'), 'getTwoDeclaredChargeRates($response)') !== false, 'the confirmation writes the row from Two\'s response');
    }

    /**
     * recomputeTwoOrderTotals() on a body with tenders: net and tax follow the lines, the prepaid amount stays, and
     * invoice, payable and gross follow from them. A body without prepaid_amount is as before.
     */
    private static function testRecomputeHelperKeepsThePrepaidAmount(): void
    {
        $module = new TwopaymentTestHarness();
        $line = ['net_amount' => '100.00', 'tax_amount' => '21.00', 'gross_amount' => '121.00', 'tax_rate' => '0.21'];
        $body = $module->recomputeTwoOrderTotals(['line_items' => [$line], 'gross_amount' => '1.00', 'net_amount' => '1.00', 'tax_amount' => '0.00', 'prepaid_amount' => '30.00']);
        self::assertAmounts(['gross_amount' => '91.00', 'net_amount' => '100.00', 'tax_amount' => '21.00', 'invoice_amount' => '121.00', 'prepaid_amount' => '30.00', 'payable_amount' => '91.00'], $body, 'with prepaid');
        $plain = $module->recomputeTwoOrderTotals(['line_items' => [$line], 'gross_amount' => '1.00', 'net_amount' => '1.00', 'tax_amount' => '0.00']);
        self::assertAmounts(['gross_amount' => '121.00', 'net_amount' => '100.00', 'tax_amount' => '21.00'], $plain, 'without prepaid');
    }

    /**
     * A tender module: $declare(params) returns the entries it appends.
     */
    private static function declare(callable $declare): void
    {
        Hook::$subscribers[TwoOrderTenders::HOOK][self::MODULE] = static function (array $params) use ($declare): void {
            foreach ($declare($params) as $entry) {
                $params['tenders'][] = $entry;
            }
        };
    }

    private static function giftCard(float $amount): array
    {
        return ['type' => 'gift_card', 'label' => 'Card 1234', 'amount' => $amount, 'source' => 'tendertest'];
    }

    private static function createFor(TwopaymentTestHarness $module, Cart $cart): array
    {
        return $module->getTwoNewOrderData('merchant-attempt-9701', $cart, OrderPostprocessingSpec::merchantUrls());
    }

    private static function updateFor(TwopaymentTestHarness $module, ?string $declaredRates): array
    {
        $row = ['two_order_id' => 'two-order-9731', 'two_order_reference' => 'ref', 'two_day_on_invoice' => '30'];
        if ($declaredRates !== null) {
            $row['two_declared_rates'] = $declaredRates;
        }

        return $module->getTwoUpdateOrderData(OrderPostprocessingSpec::order(), $row);
    }

    private static function amounts(array $body): array
    {
        $amounts = array_intersect_key($body, array_flip(['gross_amount', 'net_amount', 'tax_amount', 'invoice_amount', 'prepaid_amount', 'payable_amount']));
        ksort($amounts);

        return $amounts;
    }

    private static function assertAmounts(array $expected, array $body, string $description): void
    {
        ksort($expected);
        TinyAssert::same($expected, self::amounts($body), $description);
    }

    /**
     * Lines, net and tax stay the full order; invoice = net + tax; payable, and gross, the rest. The subscribers see
     * the tenders, and the row records the prepaid amount.
     */
    private static function testCreateDeclaresTendersAsPrepaid(): void
    {
        $cart = OrderPostprocessingSpec::seed(true);
        $module = new TwopaymentTestHarness();
        OrderPostprocessingSpec::harnessAsInstance($module);
        $plain = self::createFor($module, $cart);

        self::declare(static function (): array {
            return [self::giftCard(30.00), ['type' => 'store_credit', 'label' => 'Credit', 'amount' => '5.50', 'source' => 'tendertest']];
        });
        $seen = null;
        Hook::$subscribers[TwoOrderPostprocessing::HOOK]['twoorderpostprocessingtest'] = static function (array $params) use (&$seen): void {
            $seen = $params['context']['order_tenders'];
        };
        $body = self::createFor($module, $cart);
        unset(Hook::$subscribers[TwoOrderTenders::HOOK], Hook::$subscribers[TwoOrderPostprocessing::HOOK]);

        $invoice = number_format((float) $plain['net_amount'] + (float) $plain['tax_amount'], 2, '.', '');
        self::assertAmounts([
            'gross_amount' => number_format((float) $invoice - 35.50, 2, '.', ''),
            'net_amount' => $plain['net_amount'],
            'tax_amount' => $plain['tax_amount'],
            'invoice_amount' => $invoice,
            'prepaid_amount' => '35.50',
            'payable_amount' => number_format((float) $invoice - 35.50, 2, '.', ''),
        ], $body, 'create: tenders as prepaid');
        TinyAssert::same($plain['line_items'], $body['line_items'], 'create: lines stay at full price');
        TinyAssert::same([
            ['type' => 'gift_card', 'label' => 'Card 1234', 'amount' => 30.0, 'source' => 'tendertest'],
            ['type' => 'store_credit', 'label' => 'Credit', 'amount' => 5.5, 'source' => 'tendertest'],
        ], $seen, 'create: the subscribers see the tenders in order_tenders');
        TinyAssert::same('35.50', json_decode((string) $module->getTwoDeclaredChargeRates(['gross_amount' => '114.50']), true)['prepaid'] ?? null, 'create: the row records the prepaid amount sent');
    }

    /**
     * With no tenders, a declared zero, or an order placed before tenders were declared, every body is the one the
     * builder sent before (the golden), and a no-tender create records no prepaid amount.
     */
    private static function testNoTenderAndLegacyBodiesAreUnchanged(): void
    {
        $golden = json_decode((string) file_get_contents(__DIR__ . '/fixtures/order-postprocessing-golden.json'), true);
        $cases = [
            [null, 'no tender module'],
            [static function (): array {
                return [];
            }, 'a tender module declaring nothing'],
            [static function (): array {
                return [self::giftCard(0.0)];
            }, 'a tender of zero'],
        ];
        foreach ($cases as [$declare, $description]) {
            $cart = OrderPostprocessingSpec::seed(true);
            $module = new TwopaymentTestHarness();
            OrderPostprocessingSpec::harnessAsInstance($module);
            if ($declare !== null) {
                self::declare($declare);
            }
            foreach (OrderPostprocessingSpec::payloadsForGolden($module, $cart) as $name => $json) {
                TinyAssert::same(md5($golden[$name]), md5($json), $description . ': ' . $name . ' body hash');
            }
            TinyAssert::false(array_key_exists('prepaid', (array) json_decode((string) $module->getTwoDeclaredChargeRates(), true)), $description . ': no prepaid recorded');
            unset(Hook::$subscribers[TwoOrderTenders::HOOK]);
        }

        // Placed before tenders were declared (no prepaid in the row): its edit is sent as it always was.
        foreach ([null => 'no row record', '{"shipping":[],"wrapping":null}' => 'a record without prepaid', '{"prepaid":"0.00"}' => 'prepaid 0'] as $rates => $description) {
            OrderPostprocessingSpec::seed(true);
            $module = new TwopaymentTestHarness();
            OrderPostprocessingSpec::harnessAsInstance($module);
            self::declare(static function (): array {
                return [self::giftCard(30.00)];
            });
            $body = self::updateFor($module, $rates === '' ? null : (string) $rates);
            $body['shipping_details']['expected_delivery_date'] = 'PINNED';
            TinyAssert::same(md5($golden['update']), md5((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)), 'legacy edit, ' . $description . ': body hash');
            unset(Hook::$subscribers[TwoOrderTenders::HOOK]);
        }
    }

    /**
     * An order placed with prepaid always declares it on an edit, even once its tenders are gone: an edit without it
     * resets Two's prepaid amount to zero.
     */
    private static function testEditAlwaysResendsPrepaid(): void
    {
        foreach ([[30.00, '30.00'], [0.0, '0.00']] as [$tender, $prepaid]) {
            OrderPostprocessingSpec::seed(true);
            $module = new TwopaymentTestHarness();
            OrderPostprocessingSpec::harnessAsInstance($module);
            $plain = self::updateFor($module, null);
            self::declare(static function () use ($tender): array {
                return [self::giftCard($tender)];
            });
            $body = self::updateFor($module, '{"prepaid":"30.00"}');
            unset(Hook::$subscribers[TwoOrderTenders::HOOK]);
            $invoice = (float) $plain['net_amount'] + (float) $plain['tax_amount'];
            $payable = number_format($invoice - (float) $prepaid, 2, '.', '');
            self::assertAmounts([
                'gross_amount' => $payable,
                'net_amount' => $plain['net_amount'],
                'tax_amount' => $plain['tax_amount'],
                'invoice_amount' => number_format($invoice, 2, '.', ''),
                'prepaid_amount' => $prepaid,
                'payable_amount' => $payable,
            ], $body, 'edit with tenders of ' . $prepaid);
        }
    }

    /**
     * A declaration with a bad shape or a negative or non-numeric amount is refused with a log line naming no label;
     * an unknown type is reported as other.
     */
    private static function testBadDeclarationsAreRefused(): void
    {
        $cart = OrderPostprocessingSpec::seed(true);
        OrderPostprocessingSpec::harnessAsInstance(new TwopaymentTestHarness());
        self::declare(static function (): array {
            return [
                ['type' => 'gift_card', 'label' => 'SECRET-A', 'amount' => -5, 'source' => 'tendertest'],
                ['type' => 'gift_card', 'label' => 'SECRET-B', 'amount' => 'abc', 'source' => 'tendertest'],
                ['type' => 'gift_card', 'label' => 'SECRET-C', 'source' => 'tendertest'],
                'not an entry',
                ['type' => 'voucher', 'label' => 'Points', 'amount' => '12.345', 'source' => 'tendertest'],
            ];
        });
        $tenders = (new TwopaymentTestHarness())->getTwoOrderTenders($cart, null);
        unset(Hook::$subscribers[TwoOrderTenders::HOOK]);

        TinyAssert::same([['type' => 'other', 'label' => 'Points', 'amount' => 12.35, 'source' => 'tendertest']], $tenders, 'only the valid entry is kept');
        $refusals = array_values(array_filter(PrestaShopLogger::$logs, static function (array $log): bool {
            return strpos($log['message'], 'A declared tender was refused') !== false;
        }));
        TinyAssert::count(4, $refusals);
        foreach ($refusals as $log) {
            TinyAssert::false(strpos($log['message'], 'SECRET') !== false, 'a refusal never logs the label');
        }
    }

    /**
     * A cart rule is a discount line already: a gift-card-like voucher never becomes a tender, so its amount is not
     * counted twice.
     */
    private static function testCartRuleVoucherIsNeverATender(): void
    {
        $cart = OrderPostprocessingSpec::seed(true);
        $module = new TwopaymentTestHarness();
        OrderPostprocessingSpec::harnessAsInstance($module);
        StubStore::$cartRules[$cart->id] = [['id_cart_rule' => 77, 'name' => 'Gift card', 'code' => 'GIFT-30', 'value' => -30.00, 'value_real' => 30.00, 'value_tax_exc' => 30.00]];
        foreach ([null, static function (): array {
            return [];
        }] as $declare) {
            if ($declare !== null) {
                self::declare($declare);
            }
            TinyAssert::same([], $module->getTwoOrderTenders($cart, null), 'a voucher is not a tender');
            TinyAssert::same([], $module->buildTwoOrderPostprocessingContext('order_create', 'checkout', '/v1/order', $cart)['order_tenders'], 'a voucher is not in order_tenders');
            unset(Hook::$subscribers[TwoOrderTenders::HOOK]);
        }
    }

    /**
     * Two's gross is what it funds; the cart total PrestaShop checks the payment against holds the tenders too.
     */
    private static function testValidateOrderAmountIsTheCartTotal(): void
    {
        $module = new TwopaymentTestHarness();
        foreach ([
            [['gross_amount' => '120.00'], 120.0, 'no prepaid'],
            [['gross_amount' => '120.00', 'prepaid_amount' => '0.00'], 120.0, 'prepaid 0'],
            [['gross_amount' => '120.00', 'prepaid_amount' => '30.00'], 150.0, 'prepaid 30'],
            [['data' => ['gross_amount' => '120.00', 'prepaid_amount' => '30.00']], 150.0, 'nested'],
        ] as [$response, $expected, $description]) {
            TinyAssert::same($expected, $module->extractTwoProviderGrossAmountForValidation($response), $description);
        }
    }

    /**
     * amount is what the shop refunded through Two (the slip less what it returned to the tenders); prepaid is the rest
     * of the lines, up to the tenders left at Two. A refund's total_amount at Two includes its prepaid part, so the
     * balance guard counts only the amounts.
     */
    private static function testCreditSlipSplitsAmountAndPrepaid(): void
    {
        $earlier = RefundSpec::twoRefund('-100.00', []);
        $earlier['prepaid_amount'] = '-25.00';
        // [placed prepaid, Two's refunds, slip, returned to tenders, expected [amount, prepaid] or null when unchanged, description]
        $cases = [
            ['30.00', [], 50.00, 20.00, ['30.00', '20.00'], 'partial refund'],
            ['30.00', [], 50.00, 35.00, 'refused', 'returns more than the tenders paid: refused'],
            ['30.00', [$earlier], 50.00, 6.00, 'refused', 'returns more than is left at Two: refused'],
            ['30.00', [], 150.00, 30.00, ['120.00', '30.00'], 'full refund'],
            ['30.00', [$earlier], 50.00, 5.00, ['45.00', '5.00'], 'after a refund that used the tenders: the guard counts amounts'],
            [null, [], 50.00, 20.00, null, 'placed before tenders: unchanged'],
        ];
        foreach ($cases as [$placed, $refunds, $slip, $returned, $expected, $description]) {
            StubStore::reset();
            PrestaShopLogger::$logs = [];
            Hook::$subscribers = [];
            $twoOrder = RefundSpec::fulfilledOrder(120.00, $refunds);
            $twoOrder['prepaid_amount'] = '30.00';
            $module = RefundSpec::makeModule($twoOrder, ['two_order_id' => 'two-order-uuid'] + ($placed !== null ? ['two_declared_rates' => '{"prepaid":"' . $placed . '"}'] : []));
            self::declare(static function (array $params) use ($returned): array {
                return $params['order_slip'] !== null ? [self::giftCard($returned)] : [self::giftCard(30.00)];
            });
            $module->hookActionOrderSlipAdd(['order' => RefundSpec::makeOrder(), 'order_slip' => RefundSpec::makeSlip(601, $slip)]);
            $refunds = $module->refundCalls();
            if ($expected === 'refused') {
                TinyAssert::count(0, $refunds);
                TinyAssert::same([[5100, 601, 'it returns more to gift cards or store credit than is left of what they paid']], $module->notSent, $description . ': the order says why');
                continue;
            }
            TinyAssert::count(1, $refunds);
            $payload = $refunds[0]['payload'];
            if ($expected === null) {
                TinyAssert::same(number_format($slip, 2, '.', ''), $payload['amount'], $description . ': amount');
                TinyAssert::false(array_key_exists('prepaid_amount', $payload), $description . ': no prepaid');
                continue;
            }
            TinyAssert::same($expected, [$payload['amount'], $payload['prepaid_amount']], $description);
            $lines = array_sum(array_map(static function (array $t): float {
                return (float) $t['taxable_amount'] + (float) $t['tax_amount'];
            }, $payload['tax_subtotals']));
            TinyAssert::same(number_format($slip, 2, '.', ''), number_format($lines, 2, '.', ''), $description . ': the lines are the slip');
        }
    }

    /**
     * Marked Refunded after a slip: the outstanding amount is refunded with what is left of the tenders beside it.
     */
    private static function testRefundedRemainderCarriesThePrepaidLeft(): void
    {
        StubStore::reset();
        PrestaShopLogger::$logs = [];
        Hook::$subscribers = [];
        StubStore::$orders[5100] = ['module' => 'twopayment'];
        StubStore::$configuration['PS_TWO_OS_REFUNDED_MAP'] = 7;
        $earlier = RefundSpec::twoRefund('-50.00', ['p1' => '-50.00']);
        $earlier['prepaid_amount'] = '-20.00';
        $twoOrder = RefundSpec::fulfilledOrder(120.00, [$earlier]);
        $twoOrder['prepaid_amount'] = '30.00';
        $twoOrder['state'] = 'REFUNDED';
        $module = RefundSpec::makeModule($twoOrder, ['two_order_id' => 'two-order-uuid', 'two_declared_rates' => '{"prepaid":"30.00"}']);
        $module->placedSubtotals = [['taxable_amount' => '150.00', 'tax_amount' => '0.00', 'tax_rate' => '0.000000']];
        $module->refundRows[1] = ['id_order_slip' => 1, 'status' => 'SENT', 'amount' => '30.00', 'tax_subtotals' => [['taxable_amount' => '50.00', 'tax_amount' => '0.00', 'tax_rate' => '0.000000']]];
        $status = new OrderState();
        $status->id = 7;
        $status->name = 'Refunded';
        $module->hookActionOrderStatusUpdate(['id_order' => 5100, 'newOrderStatus' => $status]);
        $refunds = $module->refundCalls();
        TinyAssert::count(1, $refunds);
        TinyAssert::same(['90.00', '10.00'], [$refunds[0]['payload']['amount'], $refunds[0]['payload']['prepaid_amount'] ?? null], 'remainder: amount and prepaid left');
    }
}
