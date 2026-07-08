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
        self::testSlipOnNonTwoOrderMakesNoApiCall();
        self::testGrossAmountSumsProductsAndShippingTaxIncl();
        self::testPayloadBuilderFormatsAmountAsTwoDecimalString();
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

            public function __construct(int $id, float $products, float $shipping, int $idOrder)
            {
                $this->id = $id;
                $this->id_order = $idOrder;
                $this->total_products_tax_incl = $products;
                $this->total_shipping_tax_incl = $shipping;
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

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->requests[] = [
                    'endpoint' => $endpoint,
                    'payload' => $payload,
                    'method' => $method,
                    'headers' => $additional_headers,
                ];

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
        TinyAssert::same(['X-Idempotency-Key: partial_refund_slip_742'], $refunds[0]['headers']);
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
        TinyAssert::same(['X-Idempotency-Key: partial_refund_slip_801'], $refunds[0]['headers']);
        TinyAssert::same(['X-Idempotency-Key: partial_refund_slip_802'], $refunds[1]['headers']);
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
}
