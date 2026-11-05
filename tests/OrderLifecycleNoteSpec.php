<?php

declare(strict_types=1);

/**
 * TWO-27107: every fulfil and cancel outcome of a status change is written to
 * the order's private notes as well as the module log, so the merchant sees it
 * on the order page. Every row runs, and a failure names each row that failed.
 */
final class OrderLifecycleNoteSpec
{
    private const ORDER = 7901;
    private const CUSTOMER = 7902;
    private const CART = 7903;
    private const TWO_ORDER = 'two-order-7904';
    private const FULFILLED = 5;
    private const CANCELLED = 6;

    public static function runAll(): void
    {
        $hookCode = 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED';
        // [new status, stored Two state, state Two's GET answers (null = unreadable),
        //  response to the fulfil or cancel POST, expected note, description]
        $rows = [
            [self::FULFILLED, 'CONFIRMED', 'CONFIRMED', ['http_status' => 200, 'fulfilled_order' => ['id' => 'ful-1']], 'Two order two-order-7904 fulfilled (ful-1).', 'a fulfil that goes through'],
            [self::FULFILLED, 'CONFIRMED', 'CONFIRMED', ['http_status' => 400, 'message' => 'Order is not complete'], 'Two refused to fulfil order two-order-7904: Order is not complete. Save the status again to retry.', 'a fulfil Two refuses, with its reason'],
            [self::FULFILLED, 'CONFIRMED', 'CONFIRMED', ['http_status' => 0, 'error' => $hookCode, 'error_code' => $hookCode, 'error_message' => $hookCode], 'Two order two-order-7904 not fulfilled: the module did not send the request (' . $hookCode . '). See the module log, then save the status again to retry.', 'a fulfil a subscriber stopped never reached Two'],
            [self::FULFILLED, 'CONFIRMED', 'CONFIRMED', ['http_status' => 0, 'error' => 'Connection error', 'error_message' => 'Unable to connect to Two API'], 'Two order two-order-7904 not fulfilled: Two could not be reached. Save the status again to retry.', 'a fulfil that never reached Two is not a refusal'],
            [self::FULFILLED, 'CONFIRMED', null, [], 'Two order two-order-7904 not fulfilled: Two could not be asked for its state. Save the status again to retry.', 'a fulfil when the order cannot be read'],
            [self::FULFILLED, 'CONFIRMED', 'VERIFIED', [], 'Two holds order two-order-7904 as VERIFIED, so it was not fulfilled.', 'a fulfil of an order not yet confirmed'],
            [self::FULFILLED, 'CONFIRMED', 'FULFILLED', [], 'Two order two-order-7904 is already FULFILLED.', 'a status re-saved on a fulfilled order is not a failure'],
            [self::FULFILLED, 'CANCELLED', 'CANCELLED', [], 'Two holds order two-order-7904 as CANCELLED, so it was not fulfilled.', 'a fulfil of an order stored as cancelled'],
            [self::CANCELLED, 'CONFIRMED', 'CANCELLED', ['http_status' => 200], 'Two order two-order-7904 cancelled.', 'a cancel that goes through'],
            [self::CANCELLED, 'CONFIRMED', 'CONFIRMED', ['http_status' => 200], 'Two order two-order-7904 not cancelled. Two holds it as CONFIRMED. Save the status again to retry.', 'a cancel Two did not apply, with no reason given'],
            [self::CANCELLED, 'CONFIRMED', 'CONFIRMED', ['http_status' => 503, 'message' => 'Service unavailable'], 'Two order two-order-7904 not cancelled. Two holds it as CONFIRMED. Save the status again to retry. Two gave this reason: Service unavailable.', 'a cancel Two refused carries its reason'],
            [self::CANCELLED, 'CONFIRMED', 'FULFILLED', ['http_status' => 400, 'message' => 'Order is fulfilled'], 'Two order two-order-7904 not cancelled. Two holds it as FULFILLED. A fulfilled order is refunded, not cancelled. Two gave this reason: Order is fulfilled.', 'a fulfilled order is never told to retry the cancel'],
            [self::CANCELLED, 'CONFIRMED', 'PARTIALLY_FULFILLED', ['http_status' => 400], 'Two order two-order-7904 not cancelled. Two holds it as PARTIALLY_FULFILLED. A fulfilled order is refunded, not cancelled.', 'nor is a partly fulfilled one'],
            [self::CANCELLED, 'CONFIRMED', 'CONFIRMED', ['http_status' => 0, 'error' => $hookCode, 'error_code' => $hookCode, 'error_message' => $hookCode], 'Two order two-order-7904 not cancelled. Two holds it as CONFIRMED. Save the status again to retry. The module did not send the cancel (' . $hookCode . ').', 'a cancel a subscriber stopped is not credited to Two'],
            [self::CANCELLED, 'CONFIRMED', 'CONFIRMED', ['http_status' => 0, 'error' => 'Connection error', 'error_message' => 'Unable to connect to Two API'], 'Two order two-order-7904 not cancelled. Two holds it as CONFIRMED. Save the status again to retry. The cancel could not reach Two.', 'a cancel that never reached Two is not credited to Two'],
            [self::CANCELLED, 'CONFIRMED', null, ['http_status' => 200], 'Two order two-order-7904 not cancelled, and Two could not be asked for its state. Save the status again to retry.', 'a cancel when the order cannot be read afterwards'],
            [self::CANCELLED, 'CANCELLED', 'CANCELLED', [], 'Two order two-order-7904 is already cancelled.', 'a cancel of an order already cancelled at Two'],
        ];
        $failures = [];
        foreach ($rows as [$status, $stored, $twoState, $fulfilResponse, $expected, $description]) {
            try {
                $module = self::module($stored, $twoState, $fulfilResponse);
                $module->hookActionOrderStatusUpdate(['id_order' => self::ORDER, 'newOrderStatus' => new OrderState($status)]);
                TinyAssert::same([$expected], $module->notes, $description);
            } catch (Throwable $e) {
                $failures[] = $description . ': ' . $e->getMessage();
            }
        }
        if ($failures !== []) {
            throw new RuntimeException(count($failures) . " row(s) failed:\n  - " . implode("\n  - ", $failures));
        }
    }

    private static function module(string $stored, ?string $twoState, array $fulfilResponse): TwopaymentTestHarness
    {
        StubStore::reset();
        Configuration::updateValue('PS_TWO_OS_CANCELLED_MAP', self::CANCELLED);
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode([self::FULFILLED]));
        StubStore::$customers[self::CUSTOMER] = ['email' => 'buyer@example.com', 'firstname' => 'Eva', 'lastname' => 'Martin', 'secure_key' => 'k', 'loaded' => true];
        StubStore::$carts[self::CART] = ['id_customer' => self::CUSTOMER, 'id_currency' => 978, 'id_address_invoice' => 0, 'id_address_delivery' => 0, 'id_carrier' => 0, 'id_lang' => 1];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$orders[self::ORDER] = ['id_cart' => self::CART, 'id_customer' => self::CUSTOMER, 'total_paid' => 100.0, 'module' => 'twopayment'];
        StubStore::$twoPaymentRows[self::ORDER] = [
            'id_order' => self::ORDER, 'two_order_id' => self::TWO_ORDER, 'two_order_reference' => 'ref', 'two_order_state' => $stored,
            'two_order_status' => 'APPROVED', 'two_day_on_invoice' => '30', 'two_payment_term_type' => 'STANDARD', 'two_invoice_url' => '', 'two_invoice_id' => null,
        ];

        return new class ($twoState, $fulfilResponse) extends TwopaymentTestHarness {
            /** @var string[] */
            public array $notes = [];
            private ?string $twoState;
            private array $fulfilResponse;

            public function __construct(?string $twoState, array $fulfilResponse)
            {
                parent::__construct();
                $this->twoState = $twoState;
                $this->fulfilResponse = $fulfilResponse;
            }

            public function sendTwoOrderRequest($requestType, $trigger, $endpoint, array $payload, $method, $cart = null, $order = null, array $headers = array(), &$sentPayload = null, $checks = null, $twoOrder = null)
            {
                if (substr((string) $endpoint, -13) === '/fulfillments' || substr((string) $endpoint, -7) === '/cancel') {
                    return $this->fulfilResponse;
                }

                return ['http_status' => 200];
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                if ($this->twoState === null) {
                    return [];
                }

                return ['http_status' => 200, 'id' => 'two-order-7904', 'state' => $this->twoState, 'status' => 'APPROVED', 'merchant_reference' => 'ref', 'invoice_url' => ''];
            }

            protected function addTwoOrderPrivateNote($idOrder, $text)
            {
                $this->notes[] = $text;
            }

            protected function refreshMerchantRecordIfDue()
            {
            }

            public function isMerchantInvoiceDistributed()
            {
                return false;
            }
        };
    }
}
