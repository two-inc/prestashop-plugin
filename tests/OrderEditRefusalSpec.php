<?php

declare(strict_types=1);

/**
 * TWO-26150: an admin edit is not sent when the order's live state or status is one the API's
 * edit handler refuses, such as an order invoiced in full or in part. The admin is told why instead.
 * A refused order edit leaves the order marked as not sent, as its amounts now differ from Two's;
 * a refused tracking number only notes the order.
 */
final class OrderEditRefusalSpec
{
    public static function runAll(): void
    {
        self::testEditIsSkippedWhenTheApiWouldRefuseIt();
        self::testMerchantOrderIdSyncIsNeverChecked();
    }

    private static function testEditIsSkippedWhenTheApiWouldRefuseIt(): void
    {
        $invoiced = 'Two has already invoiced all or part of this order, so this change was not sent to Two.';
        $closed = 'Two no longer accepts changes to this order (%s), so this change was not sent to Two.';
        // [state lookup response, notice (null: edit sent), description]
        $cases = [
            [['http_status' => 200, 'state' => 'VERIFIED', 'status' => 'APPROVED'], null, 'unfulfilled order sends the edit'],
            [['http_status' => 200, 'state' => 'CONFIRMED', 'status' => 'APPROVED'], null, 'confirmed order sends the edit'],
            [['http_status' => 200, 'state' => 'FULFILLED', 'status' => 'APPROVED'], $invoiced, 'fully fulfilled order skips the edit'],
            [['http_status' => 200, 'state' => 'CONFIRMED', 'status' => 'PARTIAL'], $invoiced, 'partially fulfilled order skips the edit'],
            [['http_status' => 200, 'state' => 'REFUNDED', 'status' => 'APPROVED'], $invoiced, 'refunded order skips the edit'],
            [['http_status' => 200, 'state' => 'CANCELLED', 'status' => 'APPROVED'], sprintf($closed, 'CANCELLED'), 'cancelled order skips the edit'],
            [['http_status' => 200, 'state' => 'VERIFIED', 'status' => 'PENDING'], sprintf($closed, 'PENDING'), 'unaccepted status skips the edit'],
            [['http_status' => 200], null, 'lookup without state or status still sends the edit'],
            [['http_status' => 500, 'error' => 'Server error'], null, 'refused lookup still sends the edit'],
            [['http_status' => 0, 'error' => 'Connection error'], null, 'failed lookup still sends the edit'],
        ];

        $failures = [];
        foreach ($cases as [$lookup, $notice, $description]) {
            // [hook, what a refusal records beyond the warning]
            foreach ([['hookActionOrderEdited', 'not sent: '], ['hookActionAdminOrdersTrackingNumberUpdate', 'note on 4201: ']] as [$hook, $refusalRecord]) {
                StubStore::reset();
                $module = self::module($lookup);
                $module->$hook(['order' => self::order()]);

                $want = ['GET /v1/order/two-order-uuid timeout 10'];
                if ($notice === null) {
                    $want[] = 'PUT /v1/order/two-order-uuid';
                    $want[] = 'synced';
                } else {
                    $want[] = 'warning: ' . $notice;
                    $want[] = $refusalRecord . $notice;
                }
                if ($module->calls !== $want) {
                    $failures[] = "$description ($hook):\n    want " . implode(' | ', $want) . "\n    got  " . implode(' | ', $module->calls);
                }
            }
        }
        TinyAssert::same([], $failures, "edit refused by the API\n  " . implode("\n  ", $failures));
    }

    private static function testMerchantOrderIdSyncIsNeverChecked(): void
    {
        StubStore::reset();
        $module = self::module(['http_status' => 200, 'state' => 'FULFILLED', 'status' => 'APPROVED']);
        $module->putTwoOrderUpdate(self::order(), $module->getTwoOrderPaymentData(4201), $payload, 'merchant_order_id');
        TinyAssert::same(['PUT /v1/order/two-order-uuid'], $module->calls, 'the merchant order id sync after confirmation reads no state and is sent');
    }

    private static function module(array $lookup): TwopaymentTestHarness
    {
        return new class ($lookup) extends TwopaymentTestHarness {
            public array $calls = [];
            private array $lookup;

            public function __construct(array $lookup)
            {
                parent::__construct();
                $this->lookup = $lookup;
            }

            public function getTwoOrderPaymentData($id_order)
            {
                return ['two_order_id' => 'two-order-uuid', 'two_order_reference' => 'ref-1'];
            }

            public function getTwoUpdateOrderData($order, $orderpaymentdata, $trigger = 'admin_edit')
            {
                return [
                    'line_items' => [],
                    'buyer' => ['company' => []],
                    'shipping_details' => ['carrier_name' => '', 'tracking_number' => ''],
                ];
            }

            public function getTwoBrandConfig($key)
            {
                return $key === 'product_name' ? 'Two' : parent::getTwoBrandConfig($key);
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                if ($method === 'GET') {
                    $this->calls[] = "GET $endpoint timeout $timeout";
                    return $this->lookup;
                }
                $this->calls[] = "$method $endpoint";
                return ['http_status' => 200, 'data' => []];
            }

            public function addTwoBackOfficeWarning($message)
            {
                $this->calls[] = 'warning: ' . $message;
                return true;
            }

            protected function addTwoOrderPrivateNote($idOrder, $text)
            {
                $this->calls[] = "note on $idOrder: $text";
            }

            public function recordTwoOrderSync($idOrder, $failure)
            {
                $this->calls[] = $failure === null ? 'synced' : 'not sent: ' . $failure;
            }
        };
    }

    private static function order(): object
    {
        return new class () {
            public bool $loaded = true;
            public int $id = 4201;
            public string $module = 'twopayment';

            public function getBrother(): array
            {
                return [];
            }

            public function getOrderPaymentCollection(): array
            {
                return [];
            }
        };
    }
}
