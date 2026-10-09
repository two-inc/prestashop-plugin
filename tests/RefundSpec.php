<?php

declare(strict_types=1);

/**
 * TWO-24759 - partial refunds via PrestaShop credit slips
 * (hookActionOrderSlipAdd -> POST /v1/order/{id}/refund).
 */
final class RefundSpec
{
    public static function runAll(): void
    {
        self::testPartialRefundPayloadHasCorrectAmount();
        self::testIdempotencyKeyUsesSlipId();
        self::testSequentialSlipsSameAmountIssueTwoDistinctCalls();
        self::testFullAmountSlipAfterStatusRefundIsSuppressed();
        self::testAmountExceedingRemainingBalanceIsRejected();
        self::testMissingGrossAmountFailsClosed();
        self::testEmptyCurrencyFailsClosed();
        self::testSlipOnNonTwoOrderMakesNoApiCall();
        self::testGrossAmountSumsProductsAndShippingTaxIncl();
        self::testPayloadBuilderFormatsAmountAsTwoDecimalString();
        self::testPartialRefundSendsTaxSubtotalsFromStoredSlip();
        self::testHookResolvesSlipAsCorePassesIt();
        self::testRefundedStatusAfterPartialsRefundsTheRemainder();
        self::testFailureAfterClaimTellsTheMerchant();
        self::testWhatTheHookSentIsWhatIsRecorded();
        self::testSlipsAreSentAsLinesOfTheTwoOrder();
        self::testRemainderIsSentAsLinesOfTheTwoOrder();
        self::testTheHookSeesAndCanEditTheLines();
        self::testSubtotalsThatCannotBeItemised();
        self::testSlipShippingCheckIsTheDefaultHandlers();
    }

    /**
     * TWO-26274: refunded shipping against the Default shipping tax code it was placed at is a shop-match check, the
     * module's default handler. With no merchant handler the slip is not sent, as before. A merchant handler makes
     * it stand down, and the slip is split at the declared rate with the tax as refunded, then sent as the handler
     * returns it; a handler that opts back in gets the refusal, and the slip is not sent.
     * Columns: handler (null: none), helper, expected tax_subtotals (null: not sent), description.
     */
    private static function testSlipShippingCheckIsTheDefaultHandlers(): void
    {
        $split = [['0.150000', '10.00', '2.50'], ['0.200000', '25.00', '5.00']];
        $resplit = static function (array &$p): void {
            $p['tax_subtotals'][0]['tax_rate'] = '0.250000';
        };
        $cases = [
            [null, false, null, 'no merchant handler: not sent'],
            [static function (array &$p): void {
            }, false, $split, 'a merchant handler that changes nothing: sent at the declared rate, the tax as refunded'],
            [$resplit, false, [['0.250000', '10.00', '2.50'], ['0.200000', '25.00', '5.00']], 'a merchant handler that re-rates the shipping: sent as returned'],
            [$resplit, true, null, 'a merchant handler that opts back in: not sent'],
        ];
        foreach ($cases as $i => [$edit, $helper, $expected, $desc]) {
            StubStore::reset();
            StubStore::$dbExecuteSResponses = [[self::line(25.00, 30.00, '20.000')]];
            $module = self::makeModule(self::fulfilledOrder(500.00), ['two_order_id' => 'two-order-uuid', 'two_declared_rates' => '{"shipping":[{"rate":0.15,"net_weight":10}],"shipping_rate_provided":false}']);
            $module->readSlipLinesFromDb = true;
            if ($edit !== null) {
                Hook::$subscribers[TwoOrderPostprocessing::HOOK]['refundspecsubscriber'] = static function (array $params) use ($edit, $helper, $module): void {
                    if ($helper) {
                        $module->runTwoShopMatchChecks($params['payload']);
                    }
                    $edit($params['payload']);
                };
            }
            $order = self::makeOrder();
            $order->carrier_tax_rate = 0.0;
            $slip = self::makeSlip(650 + $i, 30.00, 12.50);
            $slip->total_shipping_tax_excl = 10.00;
            $slip->total_products_tax_excl = 25.00;
            $slip->order_slip_type = 2;
            PrestaShopLogger::reset();

            $module->hookActionOrderSlipAdd(['order' => $order, 'order_slip' => $slip]);

            $refunds = $module->refundCalls();
            if ($expected === null) {
                TinyAssert::count(0, $refunds, $desc);
                TinyAssert::count(1, $module->notSent, $desc . ': the merchant is told the slip was not sent');
                TinyAssert::true(self::logged('does not reconcile with the Default shipping tax code it was placed at') && self::logged('Partial refund skipped - could not build tax subtotals from the credit slip'), $desc . ': the logs it always wrote');
                continue;
            }
            TinyAssert::count(1, $refunds, $desc);
            $got = array_map(static function ($t) {
                return [$t['tax_rate'], $t['taxable_amount'], $t['tax_amount']];
            }, $refunds[0]['payload']['tax_subtotals']);
            TinyAssert::same($expected, $got, $desc . ': got ' . json_encode($got));
            TinyAssert::true(self::logged('The refund request (credit_slip) has an order postprocessing hook handler (refundspecsubscriber): the shop-match checks are delegated to it'), $desc . ': the delegation log line');
        }
        Hook::$subscribers = [];
    }

    private static function logged(string $needle): bool
    {
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos((string) $entry['message'], $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * TWO-26143: subtotals lines cannot describe go without lines, so the lines never disagree with the amount.
     * Columns: tax subtotals, expected [id, net, tax, gross] lines (null: none), description.
     */
    private static function testSubtotalsThatCannotBeItemised(): void
    {
        $sub = static function (string $rate, string $taxable, string $tax): array {
            return ['taxable_amount' => $taxable, 'tax_amount' => $tax, 'tax_rate' => $rate];
        };
        $twoOrder = ['line_items' => [self::twoLine('p1', 'PHYSICAL', '0.2', '100.00'), self::twoLine('p2', 'PHYSICAL', '0.1', '100.00')]];
        $cases = [
            [[$sub('0.200000', '50.00', '10.00'), $sub('0.100000', '10.00', '1.00')], [['p1', '50.00', '10.00', '60.00'], ['p2', '10.00', '1.00', '11.00']], 'both rates itemised'],
            [[$sub('0.200000', '50.00', '10.00'), $sub('0.100000', '-10.00', '-1.00')], null, 'a negative rate: no lines, since lines for the other rate alone would not sum to the amount'],
        ];
        foreach ($cases as [$subtotals, $expected, $desc]) {
            StubStore::reset();
            $module = self::makeModule(self::fulfilledOrder(500.00));
            $module->merchantCountry = 'NO';
            $got = $module->buildTwoRefundLineItems($subtotals, $twoOrder, [], 'Spec');
            TinyAssert::same($expected, $got === null ? null : self::sentLines(['line_items' => $got], $twoOrder['line_items']), $desc . ': got ' . json_encode($got));
        }
    }

    /** A Two order line as the order GET returns it; a null code is a line placed without one. */
    private static function twoLine(string $id, string $type, string $rate, string $net, ?string $code = 'default'): array
    {
        $line = ['id' => $id, 'type' => $type, 'tax_rate' => $rate, 'net_amount' => $net, 'gross_amount' => number_format((float)$net * (1 + (float)$rate), 2, '.', '')];
        if ($code !== null) {
            $line['tax_code'] = $code === 'default' ? 'CODE-' . $id : $code;
        }
        return $line;
    }

    /** A refund Two reports, crediting order lines: [line id => gross]. */
    private static function twoRefund(string $total, array $lines): array
    {
        $refund = ['total_amount' => $total, 'line_items' => []];
        foreach ($lines as $id => $gross) {
            $refund['line_items'][] = ['prototype_id' => $id, 'gross_amount' => $gross];
        }
        return $refund;
    }

    /** The refund lines sent, as [id, net, tax, gross]; null when none were sent. */
    private static function sentLines(array $payload, array $twoLines = []): ?array
    {
        if (!isset($payload['line_items'])) {
            return null;
        }
        $rates = array_column($twoLines, 'tax_rate', 'id');
        return array_map(static function ($l) use ($rates) {
            TinyAssert::same(['id', 'quantity', 'unit_price', 'discount_amount', 'net_amount', 'tax_amount', 'gross_amount'], array_keys($l), 'only amounts are overridden: name, rate and tax_code are inherited');
            TinyAssert::same($l['net_amount'], $l['unit_price'], 'one unit at the net');
            if (isset($rates[$l['id']])) {
                TinyAssert::true(abs((float)$l['tax_amount'] - (float)$l['net_amount'] * (float)$rates[$l['id']]) <= 0.01, 'tax is net x rate: ' . json_encode($l));
            }
            return [$l['id'], $l['net_amount'], $l['tax_amount'], $l['gross_amount']];
        }, $payload['line_items']);
    }

    /**
     * TWO-26143: a credit slip is sent as lines of the order Two holds, each referencing its parent line's id so it
     * inherits that line's tax_code, with the lines summing to the amount exactly. Columns: slip lines; slip fields
     * (products tax incl / excl, amount, order_slip_type); slip shipping tax incl / excl and carrier rate; the Two
     * order's lines; expected [id, net, tax, gross] lines (null: sent without lines, and logged); description.
     */
    private static function testSlipsAreSentAsLinesOfTheTwoOrder(): void
    {
        $es = [self::twoLine('p1', 'PHYSICAL', '0', '100.00'), self::twoLine('s1', 'SHIPPING_FEE', '0', '10.00')];
        $mixed = [self::twoLine('p1', 'PHYSICAL', '0.25', '100.00'), self::twoLine('p2', 'PHYSICAL', '0.25', '300.00'), self::twoLine('p3', 'PHYSICAL', '0.15', '40.00'), self::twoLine('s1', 'SHIPPING_FEE', '0.15', '20.00'), self::twoLine('d1', 'DIGITAL', '0.25', '-10.00')];
        $es0 = [self::twoLine('p1', 'PHYSICAL', '0', '100.00'), self::twoLine('p2', 'PHYSICAL', '0', '100.00')];
        $a = self::line(100.00, 125.00, '25.000');
        $b = self::line(40.00, 46.00, '15.000');
        $cases = [
            [[self::line(30.00, 30.00, '0.000')], [30.00], 0.0, 0.0, 0.0, $es, [['p1', '30.00', '0.00', '30.00']], '0% product slip: the product line, its tax_code inherited'],
            [[self::line(30.00, 30.00, '0.000')], [30.00], 5.00, 5.00, 0.0, $es, [['p1', '30.00', '0.00', '30.00'], ['s1', '5.00', '0.00', '5.00']], '0% products and shipping: each to its own line'],
            [[$a, $b], [171.00], 23.00, 20.00, 15.0, $mixed, [['p3', '40.00', '6.00', '46.00'], ['s1', '20.00', '3.00', '23.00'], ['p1', '25.00', '6.25', '31.25'], ['p2', '75.00', '18.75', '93.75']], 'two rates with shipping: by rate, then kind, then net; the discount line is never refunded'],
            [[$a], [125.00, 100.00, 50.00, 2], 0.0, 0.0, 0.0, $mixed, [['p1', '10.00', '2.50', '12.50'], ['p2', '30.00', '7.50', '37.50']], 'specific amount: pro-rated over the rate\'s lines'],
            [[$a, $b], [171.00, 130.00, 171.00, 1], 0.0, 0.0, 0.0, $mixed, [['p3', '37.66', '5.65', '43.31'], ['p1', '23.54', '5.88', '29.42'], ['p2', '70.62', '17.65', '88.27']], 'voucher excluded: the reduced subtotals pro-rated'],
            [[self::line(100.00, 100.00, '0.000')], [100.00, 100.00, 40.00, 2], 0.0, 0.0, 0.0, [self::twoLine('p1', 'PHYSICAL', '0', '100.00'), self::twoLine('p2', 'PHYSICAL', '0', '300.00')], [['p1', '10.00', '0.00', '10.00'], ['p2', '30.00', '0.00', '30.00']], 'specific amount over two 0% lines with different codes: a share per line, so each keeps its own code'],
            [[$b], [46.00], 0.0, 0.0, 0.0, [self::twoLine('s1', 'SHIPPING_FEE', '0.15', '40.00')], [['s1', '40.00', '6.00', '46.00']], 'no line of the slip\'s kind at the rate: the rate\'s other lines take it'],
            [[$a, $b], [171.00], 0.0, 0.0, 0.0, [self::twoLine('p1', 'PHYSICAL', '0.25', '100.00')], null, 'a rate with no line at Two: sent without lines'],
            [[$a], [125.00], 0.0, 0.0, 0.0, [], null, 'no lines in the Two order: sent without lines'],
            [[self::line(30.00, 30.00, '0.000')], [30.00], 0.0, 0.0, 0.0, [self::twoLine('p1', 'PHYSICAL', '0', '100.00', null)], null, 'a 0% line placed without a tax code: sent without lines, as before'],
            [[self::line(100.00, 114.98, '9.975,5.000', '1')], [114.98], 0.0, 0.0, 0.0, [self::twoLine('p1', 'PHYSICAL', '0.1498', '200.00')], [['p1', '100.00', '14.98', '114.98']], 'a combined rate rounded as placement rounds it matches its line'],
            [[self::line(25.00, 30.00, '20.000'), self::line(0.0, 0.0, '10.000')], [30.00], 0.0, 0.0, 0.0, [self::twoLine('p1', 'PHYSICAL', '0.2', '100.00')], [['p1', '25.00', '5.00', '30.00']], 'a rate refunding nothing: no line, and no subtotal'],
            [[self::line(50.00, 50.00, '0.000')], [50.00], 0.0, 0.0, 0.0, ['lines' => $es0, 'refunds' => [self::twoRefund('-100.00', ['p1' => '-100.00'])]], [['p2', '50.00', '0.00', '50.00']], 'a line already refunded in full takes nothing'],
            [[self::line(30.00, 30.00, '0.000')], [30.00], 0.0, 0.0, 0.0, ['lines' => [$es0[0]], 'refunds' => [self::twoRefund('-80.00', ['p1' => '-80.00'])]], null, 'more than the rate\'s lines have left: sent without lines'],
            [[self::line(30.00, 30.00, '0.000')], [30.00], 5.00, 5.00, 0.0, ['lines' => [$es0[0], self::twoLine('s1', 'SHIPPING_FEE', '0', '20.00')], 'refunds' => [self::twoRefund('-80.00', ['p1' => '-80.00'])]], [['p1', '20.00', '0.00', '20.00'], ['s1', '15.00', '0.00', '15.00']], 'a line is never credited more than it has left: the excess goes to the rate\'s other lines'],
            [[self::line(30.00, 30.00, '0.000')], [30.00], 0.0, 0.0, 0.0, ['lines' => [$es0[0], self::twoLine('s1', 'SHIPPING_FEE', '0', '20.00')], 'refunds' => [self::twoRefund('-80.00', ['p1' => '-80.00'])]], [['p1', '20.00', '0.00', '20.00'], ['s1', '10.00', '0.00', '10.00']], 'a capped kind\'s excess goes to a line of another kind'],
            [[], [0.0], 11.50, 10.00, 14.975, [self::twoLine('s1', 'SHIPPING_FEE', '0.1498', '20.00')], [['s1', '10.00', '1.50', '11.50']], 'a combined carrier rate rounded as placement rounds it matches the shipping line'],
            [[self::line(30.00, 30.00, '0.000')], [30.00], 0.0, 0.0, 0.0, ['lines' => [self::twoLine('p1', 'PHYSICAL', '0', '100.00', null)], 'country' => 'NO'], [['p1', '30.00', '0.00', '30.00']], 'a known non-Spanish merchant\'s uncoded 0% line still takes its share'],
            [[self::line(25.00, 30.00, '20.000')], [30.00, 25.00, 30.00, 2, '{"shipping":[],"shipping_rate_provided":false}'], 12.50, 10.00, 0.0, [self::twoLine('p1', 'PHYSICAL', '0.2', '100.00'), self::twoLine('s1', 'SHIPPING_FEE', '0', '20.00')], null, 'tax refunded at 0%: sent without lines, as before'],
            [[], [0.0, null, null, 0, '{"shipping":[{"rate":0.21,"net_weight":1}],"shipping_rate_provided":true}'], 110.00, 100.00, 0.0, [self::twoLine('s1', 'SHIPPING_FEE', '0.21', '200.00')], null, 'shipping declared at 21% but charged at 10%: sent without lines, as before'],
            [[], [0.0, null, null, 0, '{"shipping":[{"rate":0.21,"net_weight":0.5},{"rate":0,"net_weight":0.5}],"shipping_rate_provided":true}'], 11.07, 10.01, 0.0, [self::twoLine('s0', 'SHIPPING_FEE', '0', '50.00'), self::twoLine('s21', 'SHIPPING_FEE', '0.21', '50.00')], [['s0', '5.01', '0.00', '5.01'], ['s21', '5.01', '1.05', '6.06']], 'a rounding cent of tax parked on a 0% shipping class is still itemised'],
            [[self::line(30.00, 30.00, '0.000')], [30.00], 0.0, 0.0, 0.0, ['lines' => [$es0[0], self::twoLine('s1', 'SHIPPING_FEE', '0', '20.00'), self::twoLine('s2', 'SHIPPING_FEE', '0', '5.00')], 'refunds' => [self::twoRefund('-80.00', ['p1' => '-80.00'])]], [['p1', '20.00', '0.00', '20.00'], ['s1', '8.00', '0.00', '8.00'], ['s2', '2.00', '0.00', '2.00']], 'a capped kind\'s excess goes to the lines of another kind by what each has left'],
        ];

        foreach ($cases as $i => [$lines, $fields, $shipIncl, $shipExcl, $carrierRate, $twoLines, $expected, $desc]) {
            StubStore::reset();
            PrestaShopLogger::$logs = [];
            StubStore::$dbExecuteSResponses = [$lines];
            $twoOrder = self::fulfilledOrder(1000.00, $twoLines['refunds'] ?? [], 'EUR');
            $country = $twoLines['country'] ?? null;
            $twoLines = $twoLines['lines'] ?? $twoLines;
            $twoOrder['line_items'] = $twoLines;
            $module = self::makeModule($twoOrder, ['two_order_id' => 'two-order-uuid', 'two_declared_rates' => $fields[4] ?? null]);
            $module->merchantCountry = $country;
            $module->readSlipLinesFromDb = true;
            $order = self::makeOrder();
            $order->carrier_tax_rate = $carrierRate;
            $slip = self::makeSlip(650 + $i, $fields[0], $shipIncl);
            $slip->total_shipping_tax_excl = $shipExcl;
            $slip->total_products_tax_excl = $fields[1] ?? null;
            $slip->amount = $fields[2] ?? null;
            $slip->order_slip_type = $fields[3] ?? 0;

            $module->hookActionOrderSlipAdd(['order' => $order, 'order_slip' => $slip]);

            $refunds = $module->refundCalls();
            TinyAssert::count(1, $refunds, $desc . ': sent either way');
            TinyAssert::count(0, $module->notSent, $desc . ': the merchant is not told to refund it in the portal');
            $payload = $refunds[0]['payload'];
            $got = self::sentLines($payload, $twoLines);
            TinyAssert::same($expected, $got, $desc . ': got ' . json_encode($got));
            $logged = strpos(implode(' | ', array_column(PrestaShopLogger::$logs, 'message')), 'without line items') !== false;
            TinyAssert::same($expected === null, $logged, $desc . ': a refund sent without lines is logged');
            TinyAssert::count($expected === null ? 1 : 0, $module->privateNotes, $desc . ': the order notes a refund sent without lines');
            if ($got !== null) {
                TinyAssert::same($payload['amount'], number_format(array_sum(array_column($got, 3)), 2, '.', ''), $desc . ': lines sum to the amount');
                foreach ($payload['tax_subtotals'] as $t) {
                    TinyAssert::true((float)$t['taxable_amount'] + (float)$t['tax_amount'] != 0.0, $desc . ': no subtotal for a rate with no line');
                }
            }
        }
    }

    /**
     * TWO-26143: the Refunded remainder is sent as lines too, each rate's share spread over that rate's lines at Two.
     * Columns: the Two order's lines, expected [id, net, tax, gross] lines (null: sent without lines), description.
     */
    private static function testRemainderIsSentAsLinesOfTheTwoOrder(): void
    {
        $sub = static function (string $rate, string $taxable, string $tax): array {
            return ['taxable_amount' => $taxable, 'tax_amount' => $tax, 'tax_rate' => $rate];
        };
        $cases = [
            [[self::twoLine('p1', 'PHYSICAL', '0.25', '100.00'), self::twoLine('p2', 'PHYSICAL', '0.15', '10.00'), self::twoLine('s1', 'SHIPPING_FEE', '0.15', '10.00')], [['p2', '10.00', '1.50', '11.50'], ['s1', '10.00', '1.50', '11.50'], ['p1', '60.00', '15.00', '75.00']], 'remainder over two rates, by each line\'s net'],
            [[self::twoLine('p1', 'PHYSICAL', '0.25', '100.00')], null, 'a rate with no line at Two: sent without lines'],
            [[self::twoLine('p1', 'PHYSICAL', '0.25', '60.00'), self::twoLine('p2', 'PHYSICAL', '0.15', '20.00'), self::twoLine('p3', 'PHYSICAL', '0.25', '40.00')], [['p2', '20.00', '3.00', '23.00'], ['p1', '20.00', '5.00', '25.00'], ['p3', '40.00', '10.00', '50.00']], 'what the slip credited a line is not credited again'],
        ];
        foreach ($cases as [$twoLines, $expected, $desc]) {
            StubStore::reset();
            StubStore::$orders[5100] = ['module' => 'twopayment'];
            StubStore::$configuration['PS_TWO_OS_REFUNDED_MAP'] = 7;
            $twoOrder = self::fulfilledOrder(148.00, [self::twoRefund('-50.00', ['p1' => '-50.00'])]);
            $twoOrder['state'] = 'REFUNDED';
            $twoOrder['line_items'] = $twoLines;
            $module = self::makeModule($twoOrder);
            $module->placedSubtotals = [$sub('0.250000', '100.00', '25.00'), $sub('0.150000', '20.00', '3.00')];
            $module->refundRows[1] = ['id_order_slip' => 1, 'status' => 'SENT', 'amount' => '50.00', 'tax_subtotals' => [$sub('0.250000', '40.00', '10.00')]];
            $status = new OrderState();
            $status->id = 7;
            $status->name = 'Refunded';

            $module->hookActionOrderStatusUpdate(['id_order' => 5100, 'newOrderStatus' => $status]);

            $calls = $module->refundCalls();
            TinyAssert::count(1, $calls, $desc);
            TinyAssert::same('98.00', $calls[0]['payload']['amount'], $desc . ': amount');
            TinyAssert::same($expected, self::sentLines($calls[0]['payload'], $twoLines), $desc . ': got ' . json_encode($calls[0]['payload']));
            TinyAssert::count($expected === null ? 1 : 0, $module->privateNotes, $desc . ': the order notes a refund sent without lines');
        }
    }

    /**
     * TWO-26143: the order postprocessing hook receives the lines, and what it returns is what is sent and recorded.
     * Columns: the subscriber's edit, expected lines sent, expected recorded amount, description.
     */
    private static function testTheHookSeesAndCanEditTheLines(): void
    {
        $cases = [
            [static function (array &$p): void {
            }, [['p1', '30.00', '0.00', '30.00']], '30.00', 'untouched: the lines are sent'],
            [static function (array &$p): void {
                $p['line_items'][0]['name'] = 'Renamed';
            }, [['p1', '30.00', '0.00', '30.00', 'Renamed']], '30.00', 'a subscriber renames a line: sent as edited'],
            [static function (array &$p): void {
                unset($p['line_items']);
            }, null, '30.00', 'a subscriber drops the lines: sent without them'],
        ];
        foreach ($cases as [$edit, $expected, $recorded, $desc]) {
            StubStore::reset();
            $seen = null;
            Hook::$subscribers[TwoOrderPostprocessing::HOOK]['refundspecsubscriber'] = static function (array $params) use ($edit, &$seen): void {
                $seen = $params['payload']['line_items'] ?? null;
                $edit($params['payload']);
            };
            $twoOrder = self::fulfilledOrder(100.00, [], 'EUR');
            $twoOrder['line_items'] = [self::twoLine('p1', 'PHYSICAL', '0', '100.00')];
            $module = self::makeModule($twoOrder);

            $module->hookActionOrderSlipAdd(['order' => self::makeOrder(), 'orderSlipCreated' => self::makeSlip(906, 30.00)]);

            TinyAssert::count(1, $seen ?? [], $desc . ': the hook saw the lines');
            $payload = $module->refundCalls()[0]['payload'];
            $got = isset($payload['line_items']) ? array_map(static function ($l) {
                return array_values(array_filter([$l['id'], $l['net_amount'], $l['tax_amount'], $l['gross_amount'], $l['name'] ?? null], static function ($v) {
                    return $v !== null;
                }));
            }, $payload['line_items']) : null;
            TinyAssert::same($expected, $got, $desc . ': got ' . json_encode($got));
            TinyAssert::same('SENT', $module->refundRows[906]['status'], $desc . ': recorded SENT');
            TinyAssert::count($expected === null ? 1 : 0, $module->privateNotes, $desc . ': the order notes a refund sent without lines');
            TinyAssert::same($recorded, (string) $module->refundRows[906]['amount'], $desc . ': recorded amount');
        }
        Hook::$subscribers = [];
    }

    /**
     * Order stub: the hook only touches id, module, id_currency and the
     * Validate::isLoadedObject 'loaded' flag.
     */
    private static function makeOrder(int $id = 5100, string $module = 'twopayment'): object
    {
        return new class ($id, $module) {
            public bool $loaded = true;
            public int $id;
            public $module;
            public int $id_currency = 826;
            public $carrier_tax_rate = 0;
            public $round_type = null;

            public function __construct(int $id, string $module)
            {
                $this->id = $id;
                $this->module = $module;
            }
        };
    }

    /**
     * Credit-slip stub carrying the tax-inclusive totals the hook reads.
     */
    private static function makeSlip(int $id, float $products, float $shipping = 0.0, int $idOrder = 5100): object
    {
        return new class ($id, $products, $shipping, $idOrder) {
            public int $id;
            public int $id_order;
            public $total_products_tax_incl;
            public $total_shipping_tax_incl;
            public $total_shipping_tax_excl;
            public $total_products_tax_excl;
            public $amount;
            public $order_slip_type;

            public function __construct(int $id, float $products, float $shipping, int $idOrder)
            {
                $this->id = $id;
                $this->id_order = $idOrder;
                $this->total_products_tax_incl = $products;
                $this->total_shipping_tax_incl = $shipping;
                $this->total_shipping_tax_excl = $shipping;
            }
        };
    }

    /**
     * Harness recording every setTwoPaymentRequest call. GET returns the
     * supplied Two-order snapshot; POST /refund returns a 201 success.
     */
    private static function makeModule(array $twoOrder, ?array $paymentData = ['two_order_id' => 'two-order-uuid']): object
    {
        return new class ($twoOrder, $paymentData) extends TwopaymentTestHarness {
            public array $requests = [];
            /** False: one 0%-rate slip line per slip; true: read the Db stub (TWO-26093). */
            public bool $readSlipLinesFromDb = false;
            /** The twopayment_refund rows, keyed by slip id ('r<n>' for a remainder), and the order's stored slips by id. */
            public array $refundRows = [];
            public array $storedSlips = [];
            /** Throw from the Two order read, as an unexpected failure after a slip is claimed would. */
            public bool $throwOnRead = false;
            /** Throw from the order read that follows an accepted refund. */
            public bool $throwOnReadAfterRefund = false;
            /** Throw from recording a refund outcome. */
            public bool $throwOnRecord = false;
            /** What getTwoUpdateOrderData() reports as the order's tax_subtotals. */
            public array $placedSubtotals = [];
            private array $twoOrder;
            private $paymentData;

            public function __construct(array $twoOrder, $paymentData)
            {
                parent::__construct();
                $this->twoOrder = $twoOrder;
                $this->paymentData = $paymentData;
            }

            public function getTwoOrderPaymentData($id_order)
            {
                return $this->paymentData;
            }

            public function setTwoOrderPaymentData($id_order, $payment_data)
            {
                return true;
            }

            /** The merchant's country; null reads it as the module does. */
            public ?string $merchantCountry = null;

            public function getTwoMerchantCountry()
            {
                return $this->merchantCountry ?? parent::getTwoMerchantCountry();
            }

            /** @var string[] the order's private notes */
            public array $privateNotes = [];

            protected function addTwoOrderPrivateNote($idOrder, $text)
            {
                $this->privateNotes[] = $text;
            }

            /** @var array[] flagTwoCreditSlipNotSent() calls: [id_order, slip id, reason] */
            public array $notSent = [];

            protected function flagTwoCreditSlipNotSent($idOrder, $slipId, $reason)
            {
                $this->notSent[] = [$idOrder, $slipId, $reason];
            }

            protected function claimTwoCreditSlip($id_order, $id_order_slip)
            {
                if (isset($this->refundRows[$id_order_slip])) {
                    return false;
                }
                $this->refundRows[$id_order_slip] = ['id_order_slip' => $id_order_slip, 'status' => 'CLAIMED', 'amount' => null, 'tax_subtotals' => []];
                return true;
            }

            protected function claimTwoNewestUnsentSlip($id_order)
            {
                $ids = array_keys($this->storedSlips);
                rsort($ids);
                foreach ($ids as $id) {
                    if ($this->claimTwoCreditSlip($id_order, $id)) {
                        return $id;
                    }
                }
                return 0;
            }

            protected function loadTwoOrderSlip($id_order_slip)
            {
                return $this->storedSlips[$id_order_slip] ?? null;
            }

            protected function recordTwoRefundOutcome($id_order, $id_order_slip, $status, $payload, $reason)
            {
                if ($this->throwOnRecord) {
                    throw new RuntimeException('record failed');
                }
                $key = $id_order_slip ?? 'r' . count($this->refundRows);
                $this->refundRows[$key] = [
                    'id_order_slip' => $id_order_slip,
                    'status' => $status,
                    'amount' => $payload['amount'] ?? null,
                    'tax_subtotals' => $payload['tax_subtotals'] ?? [],
                ];
            }

            protected function getTwoSentRefunds($id_order)
            {
                return array_values(array_filter($this->refundRows, static function ($row) {
                    return $row['status'] === 'SENT';
                }));
            }

            public function getTwoUpdateOrderData($order, $orderpaymentdata, $trigger = 'admin_edit')
            {
                return ['tax_subtotals' => $this->placedSubtotals];
            }

            public function getTwoCreditSlipTaxLines($slip)
            {
                if ($this->readSlipLinesFromDb) {
                    return parent::getTwoCreditSlipTaxLines($slip);
                }
                $amount = (string)$slip->total_products_tax_incl;
                return $amount > 0 ? [['amount_tax_excl' => $amount, 'amount_tax_incl' => $amount, 'rate' => '0.000']] : [];
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->requests[] = [
                    'endpoint' => $endpoint,
                    'payload' => $payload,
                    'method' => $method,
                    'headers' => $additional_headers,
                ];

                if ($this->throwOnRead && $method === 'GET') {
                    throw new RuntimeException('read failed');
                }
                if ($this->throwOnReadAfterRefund && $method === 'GET' && $this->refundCalls() !== []) {
                    throw new Error('refresh failed');
                }
                if ($method === 'POST' && strpos($endpoint, '/refund') !== false) {
                    return ['http_status' => 201, 'id' => 'refund-uuid'];
                }

                // GET order snapshot (initial check + post-refund refresh).
                return $this->twoOrder;
            }

            /** @return array<int, array> the POST /refund calls only */
            public function refundCalls(): array
            {
                return array_values(array_filter($this->requests, static function ($r) {
                    return $r['method'] === 'POST' && strpos($r['endpoint'], '/refund') !== false;
                }));
            }
        };
    }

    private static function fulfilledOrder(float $gross, array $refunds = [], string $currency = 'GBP'): array
    {
        return [
            'id' => 'two-order-uuid',
            'state' => 'FULFILLED',
            'status' => 'APPROVED',
            'gross_amount' => (string)$gross,
            'currency' => $currency,
            'refunds' => $refunds,
        ];
    }

    private static function testPartialRefundPayloadHasCorrectAmount(): void
    {
        StubStore::reset();
        $module = self::makeModule(self::fulfilledOrder(100.00));

        $module->hookActionOrderSlipAdd([
            'order' => self::makeOrder(),
            'order_slip' => self::makeSlip(501, 30.00),
        ]);

        $refunds = $module->refundCalls();
        TinyAssert::count(1, $refunds);
        TinyAssert::same('/v1/order/two-order-uuid/refund', $refunds[0]['endpoint']);
        TinyAssert::same('30.00', $refunds[0]['payload']['amount']);
        TinyAssert::same('GBP', $refunds[0]['payload']['currency']);
        // Simple amount+currency payload - no line-item breakdown.
        TinyAssert::false(isset($refunds[0]['payload']['line_items']));
    }

    private static function testIdempotencyKeyUsesSlipId(): void
    {
        StubStore::reset();
        $module = self::makeModule(self::fulfilledOrder(100.00));

        $module->hookActionOrderSlipAdd([
            'order' => self::makeOrder(),
            'order_slip' => self::makeSlip(742, 25.00),
        ]);

        $refunds = $module->refundCalls();
        TinyAssert::count(1, $refunds);
        TinyAssert::same(['X-Idempotency-Key: partial_refund_two-order-uuid_slip_742'], $refunds[0]['headers']);
    }

    private static function testSequentialSlipsSameAmountIssueTwoDistinctCalls(): void
    {
        StubStore::reset();
        // gross 100, no prior refunds: two 30.00 slips are both within balance.
        // Distinct slip IDs must yield distinct idempotency keys (no collision
        // by amount).
        $module = self::makeModule(self::fulfilledOrder(100.00));

        $module->hookActionOrderSlipAdd([
            'order' => self::makeOrder(),
            'order_slip' => self::makeSlip(801, 30.00),
        ]);
        $module->hookActionOrderSlipAdd([
            'order' => self::makeOrder(),
            'order_slip' => self::makeSlip(802, 30.00),
        ]);

        $refunds = $module->refundCalls();
        TinyAssert::count(2, $refunds);
        TinyAssert::same('30.00', $refunds[0]['payload']['amount']);
        TinyAssert::same('30.00', $refunds[1]['payload']['amount']);
        TinyAssert::same(['X-Idempotency-Key: partial_refund_two-order-uuid_slip_801'], $refunds[0]['headers']);
        TinyAssert::same(['X-Idempotency-Key: partial_refund_two-order-uuid_slip_802'], $refunds[1]['headers']);
        TinyAssert::notSame($refunds[0]['headers'][0], $refunds[1]['headers'][0]);
    }

    private static function testFullAmountSlipAfterStatusRefundIsSuppressed(): void
    {
        StubStore::reset();
        // The status-change full-refund path already refunded the whole order
        // (refund total_amount is negative at Two). A concurrent full-amount
        // credit slip must NOT double-refund: remaining balance is zero.
        $module = self::makeModule(self::fulfilledOrder(100.00, [['total_amount' => '-100.00']]));

        $module->hookActionOrderSlipAdd([
            'order' => self::makeOrder(),
            'order_slip' => self::makeSlip(900, 100.00),
        ]);

        TinyAssert::count(0, $module->refundCalls());
    }

    private static function testAmountExceedingRemainingBalanceIsRejected(): void
    {
        StubStore::reset();
        // gross 100, already refunded 80 -> remaining 20. A 50.00 slip exceeds
        // the remaining refundable balance and must be rejected.
        $module = self::makeModule(self::fulfilledOrder(100.00, [['total_amount' => '-80.00']]));

        $module->hookActionOrderSlipAdd([
            'order' => self::makeOrder(),
            'order_slip' => self::makeSlip(910, 50.00),
        ]);

        TinyAssert::count(0, $module->refundCalls());

        // But a slip within the remaining balance still goes through.
        $ok = self::makeModule(self::fulfilledOrder(100.00, [['total_amount' => '-80.00']]));
        $ok->hookActionOrderSlipAdd([
            'order' => self::makeOrder(),
            'order_slip' => self::makeSlip(911, 20.00),
        ]);
        TinyAssert::count(1, $ok->refundCalls());
        TinyAssert::same('20.00', $ok->refundCalls()[0]['payload']['amount']);
    }

    private static function testMissingGrossAmountFailsClosed(): void
    {
        StubStore::reset();
        // Two GET returns a valid order (has id + refundable state) but NO
        // gross_amount. The balance guard cannot be evaluated, so the refund
        // must be refused (fail closed) - NOT posted with an unbounded amount.
        $degraded = [
            'id' => 'two-order-uuid',
            'state' => 'FULFILLED',
            'status' => 'APPROVED',
            'currency' => 'GBP',
            'refunds' => [],
            // gross_amount deliberately absent
        ];
        $module = self::makeModule($degraded);

        $module->hookActionOrderSlipAdd([
            'order' => self::makeOrder(),
            'order_slip' => self::makeSlip(930, 40.00),
        ]);

        // GET was made, but no /refund POST.
        TinyAssert::count(0, $module->refundCalls());
    }

    private static function testEmptyCurrencyFailsClosed(): void
    {
        StubStore::reset();
        // Two GET carries an empty currency and the order's currency cannot be
        // resolved (no StubStore currency for id 826 -> Currency not loaded).
        // Posting {currency: ''} risks a wrong-currency refund, so fail closed.
        $noCurrency = self::fulfilledOrder(100.00, [], '');
        $module = self::makeModule($noCurrency);

        $module->hookActionOrderSlipAdd([
            'order' => self::makeOrder(),
            'order_slip' => self::makeSlip(940, 30.00),
        ]);

        TinyAssert::count(0, $module->refundCalls());
    }

    private static function testSlipOnNonTwoOrderMakesNoApiCall(): void
    {
        StubStore::reset();
        // No Two payment row for this order -> nothing to refund at Two, and
        // no order snapshot should even be fetched.
        $module = self::makeModule(self::fulfilledOrder(100.00), null);

        $module->hookActionOrderSlipAdd([
            'order' => self::makeOrder(),
            'order_slip' => self::makeSlip(920, 40.00),
        ]);

        TinyAssert::count(0, $module->requests);
    }

    private static function testGrossAmountSumsProductsAndShippingTaxIncl(): void
    {
        StubStore::reset();
        $module = self::makeModule(self::fulfilledOrder(500.00));

        TinyAssert::same(125.50, $module->getTwoCreditSlipGrossAmount(self::makeSlip(1, 100.00, 25.50)));
        TinyAssert::same(100.00, $module->getTwoCreditSlipGrossAmount(self::makeSlip(1, 100.00, 0.0)));
    }

    private static function testPayloadBuilderFormatsAmountAsTwoDecimalString(): void
    {
        StubStore::reset();
        $module = self::makeModule(self::fulfilledOrder(500.00));

        $payload = $module->buildTwoPartialRefundPayload(7.5, 'NOK');
        TinyAssert::same('7.50', $payload['amount']);
        TinyAssert::same('NOK', $payload['currency']);
    }

    /**
     * One stored slip line: order_slip_detail amounts, the order_detail_tax
     * rates (comma-separated percentages) and order_detail.tax_computation_method.
     * `rate` is the plain sum the query used to return, so a row that fails
     * on the old code fails on its behaviour rather than on a missing key.
     */
    private static function line(float $excl, float $incl, string $rates, string $method = '0'): array
    {
        $sum = array_sum(array_map('floatval', explode(',', $rates)));

        return ['amount_tax_excl' => (string)$excl, 'amount_tax_incl' => (string)$incl, 'placed_rates' => $rates, 'tax_computation_method' => $method, 'rate' => (string)$sum];
    }

    /**
     * TWO-26093: Two's partial-refund contract requires tax_subtotals, built
     * from the stored slip. Columns: slip lines; slip fields (products tax
     * incl / excl, amount, order_slip_type); slip shipping tax incl / excl;
     * order carrier_tax_rate; expected amount and [tax_rate, taxable_amount,
     * tax_amount] entries (null: not sent, and the merchant is told).
     */
    private static function testPartialRefundSendsTaxSubtotalsFromStoredSlip(): void
    {
        $a = self::line(100.00, 125.00, '25.000');
        $b = self::line(40.00, 46.00, '15.000');
        $cases = [
            [[self::line(25.00, 30.00, '20.000')], [30.00], 0.0, 0.0, 0.0, '30.00', [['0.200000', '25.00', '5.00']], 'single-rate slip'],
            [[self::line(50.00, 60.00, '20.000'), self::line(20.00, 21.00, '5.500')], [81.00], 12.00, 10.00, 20.0, '93.00', [['0.055000', '20.00', '1.00'], ['0.200000', '60.00', '12.00']], 'multi-rate slip with shipping'],
            [[], [0.0], 12.00, 10.00, 20.0, '12.00', [['0.200000', '10.00', '2.00']], 'shipping-only slip'],
            [[self::line(8.333333, 10.00, '20.000')], [10.00], 0.0, 0.0, 0.0, '10.00', [['0.200000', '8.33', '1.67']], 'partial quantity, 1 of 3 units'],
            [[self::line(50.00, 60.00, '20.000')], [54.00, 50.00, 50.00, 1], 0.0, 0.0, 0.0, '54.00', [['0.200000', '45.00', '9.00']], 'voucher excluded, entered tax excl: core reduces products tax incl'],
            [[$a, $b], [171.00, 130.00, 171.00, 1], 0.0, 0.0, 0.0, '161.00', [['0.150000', '37.66', '5.65'], ['0.250000', '94.15', '23.54']], 'voucher excluded, entered tax incl: core reduces products tax excl only'],
            [[$a], [125.00, 100.00, 50.00, 2], 0.0, 0.0, 0.0, '50.00', [['0.250000', '40.00', '10.00']], 'specific amount'],
            [[$a], [125.00, 100.00, 60.00, 2], 23.00, 20.00, 15.0, '83.00', [['0.150000', '20.00', '3.00'], ['0.250000', '48.00', '12.00']], 'specific amount plus shipping'],
            [[$a], [125.00, 100.00, 100.00, 2], 23.00, 20.00, 15.0, '148.00', [['0.150000', '20.00', '3.00'], ['0.250000', '100.00', '25.00']], 'products plus shipping, entered tax excl: amount is the excl total, not a chosen one'],
            [[self::line(100.00, 115.50, '10.000,5.000', '2')], [115.50], 0.0, 0.0, 0.0, '115.50', [['0.155000', '100.00', '15.50']], 'compound taxes multiply, not add'],
            [[self::line(100.00, 115.00, '10.000,5.000', '1')], [115.00], 0.0, 0.0, 0.0, '115.00', [['0.150000', '100.00', '15.00']], 'combined taxes add'],
            [array_fill(0, 4, self::line(8.38, 10.05, '20.000')), [40.20, 33.50, 40.20, 0, null, 3], 0.0, 0.0, 0.0, '40.20', [['0.200000', '33.52', '6.68']], 'ROUND_TOTAL, entered tax incl: a cent per line of rounding is not a voucher'],
            [array_fill(0, 3, self::line(10.00, 12.00, '20.000')), [36.00, 29.97, 36.00, 1, null, 1], 0.0, 0.0, 0.0, '35.97', [['0.200000', '29.98', '5.99']], 'ROUND_ITEM, entered tax incl: a 0.03 voucher on 3 lines is a voucher, not rounding'],
            [[self::line(25.00, 30.00, '20.000')], [30.00, 25.00, 30.00, 2, '{"shipping":[{"rate":0.25,"net_weight":10}]}'], 12.50, 10.00, 0.0, '42.50', [['0.200000', '25.00', '5.00'], ['0.250000', '10.00', '2.50']], 'carrier-less order: shipping at the rate declared at placement'],
            [[self::line(25.00, 30.00, '20.000')], [30.00, 25.00, 30.00, 2], 12.50, 10.00, 0.0, '42.50', [['0.000000', '10.00', '2.50'], ['0.200000', '25.00', '5.00']], 'placed before the record, no shipping rate reconciles: sent at the rate the order records'],
            // TWO-26117: what placement recorded about the shipping rate decides.
            [[self::line(25.00, 30.00, '20.000')], [30.00, 25.00, 30.00, 2, '{"shipping":[],"shipping_rate_provided":false}'], 12.50, 10.00, 0.0, '42.50', [['0.000000', '10.00', '2.50'], ['0.200000', '25.00', '5.00']], 'no rate and no Default shipping tax code at placement: 0% with the tax refunded'],
            [[self::line(25.00, 30.00, '20.000')], [30.00, 25.00, 30.00, 2, '{"shipping":[{"rate":0.25,"net_weight":10}],"shipping_rate_provided":false}'], 12.50, 10.00, 0.0, '42.50', [['0.200000', '25.00', '5.00'], ['0.250000', '10.00', '2.50']], 'no rate, placed at the Default shipping tax code: that rate'],
            [[self::line(25.00, 30.00, '20.000')], [30.00, 25.00, 30.00, 2, '{"shipping":[{"rate":0.15,"net_weight":10}],"shipping_rate_provided":false}'], 12.50, 10.00, 0.0, null, null, 'no rate, the Default shipping tax code it was placed at does not reconcile: not sent'],
            [[self::line(25.00, 30.00, '20.000')], [30.00, 25.00, 30.00, 2, '{"shipping":[{"rate":0.15,"net_weight":10}],"shipping_rate_provided":true}'], 12.50, 10.00, 0.0, '42.50', [['0.150000', '10.00', '2.50'], ['0.200000', '25.00', '5.00']], 'a provided rate that does not reconcile goes out as is'],
            [[], [30.00], 0.0, 0.0, 0.0, null, null, 'no stored lines fails closed'],
            [[], [125.00, 100.00, 50.00, 2], 0.0, 0.0, 0.0, null, null, 'specific amount with no lines to apportion over fails closed'],
        ];

        foreach ($cases as $i => [$lines, $fields, $shipIncl, $shipExcl, $carrierRate, $amount, $expected, $desc]) {
            StubStore::reset();
            StubStore::$dbExecuteSResponses = [$lines];
            $module = self::makeModule(self::fulfilledOrder(500.00), ['two_order_id' => 'two-order-uuid', 'two_declared_rates' => $fields[4] ?? null]);
            $module->readSlipLinesFromDb = true;
            $order = self::makeOrder();
            $order->carrier_tax_rate = $carrierRate;
            $order->round_type = $fields[5] ?? null;
            $slip = self::makeSlip(600 + $i, $fields[0], $shipIncl);
            $slip->total_shipping_tax_excl = $shipExcl;
            $slip->total_products_tax_excl = $fields[1] ?? null;
            $slip->amount = $fields[2] ?? null;
            $slip->order_slip_type = $fields[3] ?? 0;

            $module->hookActionOrderSlipAdd(['order' => $order, 'order_slip' => $slip]);

            $refunds = $module->refundCalls();
            if ($expected === null) {
                TinyAssert::count(0, $refunds, $desc);
                TinyAssert::count(1, $module->notSent, $desc . ': the merchant is told the slip was not sent');
                continue;
            }
            TinyAssert::count(1, $refunds, $desc);
            $payload = $refunds[0]['payload'];
            TinyAssert::same($amount, $payload['amount'], $desc . ': amount');
            TinyAssert::true(isset($payload['tax_subtotals']), $desc . ': tax_subtotals missing');
            $got = array_map(static function ($t) {
                return [$t['tax_rate'], $t['taxable_amount'], $t['tax_amount']];
            }, $payload['tax_subtotals']);
            TinyAssert::same($expected, $got, $desc . ": got " . json_encode($got));
            $sum = array_sum(array_map(static function ($t) {
                return (float)$t['taxable_amount'] + (float)$t['tax_amount'];
            }, $payload['tax_subtotals']));
            TinyAssert::same($payload['amount'], number_format($sum, 2, '.', ''), $desc . ': subtotals sum to amount');
        }
    }

    /**
     * TWO-26093: core never passes order_slip. 1.7.x and 8.x pass only
     * order/productList/qtyList, 9.x adds orderSlipCreated. Each row fires
     * the hook once per params entry against the order's stored slips.
     * Columns: params per call, stored slip ids, expected refunded slip ids.
     */
    private static function testHookResolvesSlipAsCorePassesIt(): void
    {
        $core17 = ['productList' => [], 'qtyList' => []];
        $cases = [
            [[['order_slip' => self::makeSlip(701, 30.00)]], [], [701], 'explicit order_slip'],
            [[['orderSlipCreated' => self::makeSlip(702, 30.00)]], [], [702], 'PS 9 orderSlipCreated'],
            [[$core17], [703], [703], 'PS 1.7/8 params: newest slip of the order'],
            [[$core17], [], [], 'PS 1.7/8 params, no stored slip'],
            [[$core17, $core17], [801, 802], [802, 801], 'two slips created together are sent once each, not the newest twice'],
            [[$core17, $core17], [803], [803], 'a second call finds no unsent slip'],
            [[['orderSlipCreated' => self::makeSlip(804, 30.00)], ['orderSlipCreated' => self::makeSlip(804, 30.00)]], [], [804], 'a passed slip already handled is not sent again'],
        ];

        foreach ($cases as [$calls, $stored, $expectedSlipIds, $desc]) {
            StubStore::reset();
            $module = self::makeModule(self::fulfilledOrder(500.00));
            foreach ($stored as $id) {
                $module->storedSlips[$id] = self::makeSlip($id, 30.00);
            }

            foreach ($calls as $params) {
                $module->hookActionOrderSlipAdd(['order' => self::makeOrder()] + $params);
            }

            $got = array_map(static function ($r) {
                return (int)substr($r['headers'][0], strlen('X-Idempotency-Key: partial_refund_two-order-uuid_slip_'));
            }, $module->refundCalls());
            TinyAssert::same($expectedSlipIds, $got, $desc . ': got ' . json_encode($got));
        }
    }

    /**
     * TWO-26093: marking an order Refunded after credit slips were sent
     * refunds what is left, split by what Two holds per rate less what the
     * slips refunded. Columns: refunds recorded as sent, refunds Two reports,
     * Two order gross, the order's tax_subtotals at Two, expected POST body
     * (null: no call; [] the body-less full refund).
     */
    private static function testRefundedStatusAfterPartialsRefundsTheRemainder(): void
    {
        $sub = static function (string $rate, string $taxable, string $tax): array {
            return ['taxable_amount' => $taxable, 'tax_amount' => $tax, 'tax_rate' => $rate];
        };
        $slip = static function (int $id, string $amount, array $subtotals): array {
            return ['id_order_slip' => $id, 'status' => 'SENT', 'amount' => $amount, 'tax_subtotals' => $subtotals];
        };
        $placed = [$sub('0.250000', '100.00', '25.00'), $sub('0.150000', '20.00', '3.00')];
        $cases = [
            [[$slip(1, '125.00', [$sub('0.250000', '100.00', '25.00')])], ['-125.00'], 148.00, $placed, ['amount' => '23.00', 'currency' => 'GBP', 'tax_subtotals' => [$sub('0.150000', '20.00', '3.00')]], 'remainder after one slip'],
            [[$slip(1, '50.00', [$sub('0.250000', '40.00', '10.00')])], ['-50.00'], 148.00, $placed, ['amount' => '98.00', 'currency' => 'GBP', 'tax_subtotals' => [$sub('0.150000', '20.00', '3.00'), $sub('0.250000', '60.00', '15.00')]], 'remainder over two rates'],
            [[$slip(1, '148.00', $placed)], ['-148.00'], 148.00, $placed, null, 'nothing left: no call'],
            [[$slip(1, '125.00', [$sub('0.250000', '100.00', '25.00')])], ['-125.00', '-23.00'], 148.00, $placed, null, 'the rest already refunded in the portal: no call'],
            [[], ['-50.00'], 148.00, $placed, ['amount' => '98.00', 'currency' => 'GBP', 'tax_subtotals' => [$sub('0.150000', '13.24', '1.99'), $sub('0.250000', '66.22', '16.55')]], 'refunded in the portal only, no slips sent: the rest is still refunded'],
            [[], [], 148.00, $placed, [], 'no slips sent: the body-less full refund, as before'],
        ];

        foreach ($cases as [$sent, $twoRefunds, $gross, $placedSubtotals, $expected, $desc]) {
            StubStore::reset();
            StubStore::$orders[5100] = ['module' => 'twopayment'];
            StubStore::$configuration['PS_TWO_OS_REFUNDED_MAP'] = 7;
            $refunds = array_map(static function ($amount) {
                return ['total_amount' => $amount];
            }, $twoRefunds);
            $twoOrder = self::fulfilledOrder($gross, $refunds);
            $twoOrder['state'] = empty($refunds) ? 'FULFILLED' : 'REFUNDED';
            $module = self::makeModule($twoOrder);
            $module->placedSubtotals = $placedSubtotals;
            foreach ($sent as $row) {
                $module->refundRows[$row['id_order_slip']] = $row;
            }
            $status = new OrderState();
            $status->id = 7;
            $status->name = 'Refunded';

            $module->hookActionOrderStatusUpdate(['id_order' => 5100, 'newOrderStatus' => $status]);

            $calls = $module->refundCalls();
            if ($expected === null) {
                TinyAssert::count(0, $calls, $desc);
                continue;
            }
            TinyAssert::count(1, $calls, $desc);
            TinyAssert::same($expected, $calls[0]['payload'], $desc . ': got ' . json_encode($calls[0]['payload']));
        }
    }

    /**
     * TWO-26093: once a slip is claimed no later call sends it. Invariants: a slip Two accepted is SENT and the
     * merchant is never told to refund it in the portal; any other outcome reaches the merchant, even when
     * recording it fails. Columns: harness failure switches, expected refund calls, recorded status, notes.
     */
    private static function testFailureAfterClaimTellsTheMerchant(): void
    {
        $cases = [
            [['throwOnRead'], 0, 'NOT_SENT', 1, 'read failed', 'failure before Two accepts it: not sent, merchant told'],
            [['throwOnReadAfterRefund'], 1, 'SENT', 0, 'refresh failed', 'refresh fails after Two accepted it: sent, no portal note'],
            [['throwOnRead', 'throwOnRecord'], 0, 'CLAIMED', 1, 'record failed', 'recording fails too: the merchant is still told'],
            [['throwOnReadAfterRefund', 'throwOnRecord'], 1, 'CLAIMED', 0, 'record failed', 'accepted, recording fails: still no portal note'],
        ];
        foreach ($cases as [$switches, $calls, $status, $notes, $logged, $desc]) {
            StubStore::reset();
            PrestaShopLogger::$logs = [];
            $module = self::makeModule(self::fulfilledOrder(100.00));
            foreach ($switches as $switch) {
                $module->{$switch} = true;
            }

            $module->hookActionOrderSlipAdd(['order' => self::makeOrder(), 'orderSlipCreated' => self::makeSlip(905, 30.00)]);

            TinyAssert::count($calls, $module->refundCalls(), $desc . ': refund calls');
            TinyAssert::count($notes, $module->notSent, $desc . ': not-sent notes');
            TinyAssert::same($status, $module->refundRows[905]['status'], $desc . ': recorded status');
            $messages = implode(' | ', array_column(PrestaShopLogger::$logs, 'message'));
            TinyAssert::true(strpos($messages, $logged) !== false, $desc . ': logged');
        }
    }

    /**
     * TWO-26092: a subscriber's edits to a credit slip refund are what Two received, so they are what is recorded
     * SENT and what the Refunded remainder is computed from. Columns: the slip edit, expected recorded amount and
     * tax_subtotals, expected remainder body, description.
     */
    private static function testWhatTheHookSentIsWhatIsRecorded(): void
    {
        $sub = static function (string $rate, string $taxable, string $tax): array {
            return ['taxable_amount' => $taxable, 'tax_amount' => $tax, 'tax_rate' => $rate];
        };
        $placed = [$sub('0.250000', '100.00', '25.00'), $sub('0.150000', '20.00', '3.00')];
        $cases = [
            [static function (array &$p): void {
                $p['amount'] = '20.00';
                $p['tax_subtotals'] = [['taxable_amount' => '20.00', 'tax_amount' => '0.00', 'tax_rate' => '0.000000']];
            }, ['20.00', [$sub('0.000000', '20.00', '0.00')]], '128.00', 'a subscriber that lowers the slip: the remainder is what Two still holds'],
            [static function (array &$p): void {
                $p['tax_subtotals'] = [['taxable_amount' => '24.00', 'tax_amount' => '6.00', 'tax_rate' => '0.250000']];
            }, ['30.00', [$sub('0.250000', '24.00', '6.00')]], [$sub('0.150000', '20.00', '3.00'), $sub('0.250000', '76.00', '19.00')], 'a re-split slip: the remainder splits against what Two received'],
        ];
        foreach ($cases as [$edit, $recorded, $remainder, $desc]) {
            StubStore::reset();
            StubStore::$orders[5100] = ['module' => 'twopayment'];
            StubStore::$configuration['PS_TWO_OS_REFUNDED_MAP'] = 7;
            Hook::$subscribers[TwoOrderPostprocessing::HOOK]['refundspecsubscriber'] = static function (array $params) use ($edit): void {
                if ($params['context']['trigger'] === 'credit_slip') {
                    $edit($params['payload']);
                }
            };
            $module = self::makeModule(self::fulfilledOrder(148.00));
            $module->placedSubtotals = $placed;

            $module->hookActionOrderSlipAdd(['order' => self::makeOrder(), 'orderSlipCreated' => self::makeSlip(905, 30.00)]);
            $row = $module->refundRows[905];
            TinyAssert::same($recorded, [(string) $row['amount'], $row['tax_subtotals']], $desc . ': recorded SENT');

            $status = new OrderState();
            $status->id = 7;
            $status->name = 'Refunded';
            $module->hookActionOrderStatusUpdate(['id_order' => 5100, 'newOrderStatus' => $status]);
            $calls = $module->refundCalls();
            TinyAssert::count(2, $calls, $desc . ': the slip, then the remainder');
            $body = $calls[1]['payload'];
            TinyAssert::same($remainder, is_string($remainder) ? $body['amount'] : $body['tax_subtotals'], $desc . ': got ' . json_encode($body));
        }
        Hook::$subscribers = [];
    }
}
