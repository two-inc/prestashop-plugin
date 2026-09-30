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
            [[self::line(25.00, 30.00, '20.000')], [30.00, 25.00, 30.00, 2], 12.50, 10.00, 0.0, null, null, 'shipping no stored or configured rate reconciles with fails closed'],
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
}
