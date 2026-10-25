<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/TwoInvoiceUploadService.php';

/**
 * TWO-26658: every write to Two's API sends an `Idempotency-Key` header naming one logical operation. A retry or
 * re-trigger of the operation sends the same key and every other operation a different one, built from durable ids
 * (plus a payload hash where the operation has no record of its own), never from time or randomness. Keys are
 * lowercase and at most 100 characters. A merchant's custom header can never set or replace it.
 */
final class IdempotencyKeySpec
{
    private const TWO_ORDER = '3f2a9c1e-7b4d-4e8a-9c2f-1a2b3c4d5e6f';
    private const CART = 4242;

    public static function runAll(): void
    {
        self::testOrderRequestKeys();
        self::testCallSiteKeys();
        self::testOrderEditKeyIsOnePerEdit();
        self::testFullRefundKeyIsStableAndApartFromRemainder();
        self::testMerchantHeaderCannotSetKey();
        self::testOrderWritesLogTheirKey();
        self::testEveryLiteralWriteCarriesAKey();
    }

    /** A module double that records each request's method, endpoint and header lines instead of sending it. */
    private static function recordingModule(): Twopayment
    {
        return new class extends TwopaymentTestHarness {
            /** @var array<int, array{method: string, endpoint: string, headers: array}> */
            public $sent = [];

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->sent[] = ['method' => (string) $method, 'endpoint' => (string) $endpoint, 'headers' => (array) $additional_headers];

                return ['http_status' => 0];
            }

            public function getTwoUpdateOrderData($order, $orderpaymentdata, $trigger = 'admin_edit', $twoOrder = null)
            {
                return ['gross_amount' => isset($orderpaymentdata['test_gross']) ? $orderpaymentdata['test_gross'] : '100.00'];
            }
        };
    }

    private static function assertKeyShape(string $key, string $desc): void
    {
        TinyAssert::true(strlen($key) <= 100, $desc . ': at most 100 characters, got ' . strlen($key));
        TinyAssert::same(strtolower($key), $key, $desc . ': lowercase');
        TinyAssert::true((bool) preg_match('/^[\x21-\x7E]+\z/', $key), $desc . ': printable ASCII with no spaces');
    }

    /**
     * Confirm, fulfil and cancel get the order's key for that operation from sendTwoOrderRequest(); a refund sends
     * only the key its caller built.
     */
    private static function testOrderRequestKeys(): void
    {
        $id = self::TWO_ORDER;
        $refundKey = 'partial_refund_' . $id . '_slip_7';
        // [request type, endpoint, headers passed, expected header lines, description]
        $cases = [
            [TwoOrderPostprocessing::REQUEST_ORDER_CONFIRM, "/v1/order/$id/confirm", [], ["Idempotency-Key: confirm_$id"], 'confirm: confirm_<two order id>'],
            [TwoOrderPostprocessing::REQUEST_CAPTURE, "/v1/order/$id/fulfillments", [], ["Idempotency-Key: fulfil_$id"], 'whole-order fulfil: fulfil_<two order id>'],
            [TwoOrderPostprocessing::REQUEST_CANCEL, "/v1/order/$id/cancel", [], ["Idempotency-Key: cancel_$id"], 'cancel: cancel_<two order id>'],
            [TwoOrderPostprocessing::REQUEST_REFUND, "/v1/order/$id/refund", ["Idempotency-Key: $refundKey"], ["Idempotency-Key: $refundKey"], 'refund: the caller key, nothing added'],
            [TwoOrderPostprocessing::REQUEST_CANCEL, "/v1/order/$id/cancel", ['Idempotency-Key: cancel_custom'], ['Idempotency-Key: cancel_custom'], 'a caller key is never doubled'],
        ];
        foreach ($cases as [$type, $endpoint, $passed, $expected, $desc]) {
            StubStore::reset();
            $module = self::recordingModule();
            $module->sendTwoOrderRequest($type, 'spec', $endpoint, [], 'POST', null, null, $passed);

            TinyAssert::count(1, $module->sent, $desc . ': one request');
            TinyAssert::same($expected, $module->sent[0]['headers'], $desc);
            self::assertKeyShape((string) Twopayment::findTwoIdempotencyKey($module->sent[0]['headers']), $desc);
        }
    }

    /** The keys the other writes build at their call sites. */
    private static function testCallSiteKeys(): void
    {
        $upper = strtoupper(self::TWO_ORDER);
        $tokenMint = new ReflectionMethod(TwoSoleTrader::class, 'buildTokenMintHeaders');
        $tokenMint->setAccessible(true);
        $rates = new ReflectionMethod(Twopayment::class, 'fetchTwoMerchantFeeRatesLive');
        $rates->setAccessible(true);
        $upload = new ReflectionMethod(TwoInvoiceUploadService::class, 'requestSignedUploadUrl');
        $upload->setAccessible(true);

        // [produces the request's header lines from the module, expected key (or /pattern/), description]
        $cases = [
            [static function (Twopayment $m): array {
                return [Twopayment::buildTwoIdempotencyHeader($m->buildTwoOrderCreateIdempotencyKey(strtoupper(self::TWO_ORDER)))];
            }, 'create_' . self::TWO_ORDER, 'order create: create_<attempt token>, lowercased'],
            [static function (Twopayment $m) use ($tokenMint): array {
                return $tokenMint->invoke(null, $m, '/registry/v1/delegation');
            }, 'registry_delegation_' . self::CART, 'sole-trader token mint: <service>_delegation_<cart id>'],
            [static function (Twopayment $m) use ($tokenMint): array {
                return $tokenMint->invoke(null, $m, '/autofill/v1/delegation');
            }, 'autofill_delegation_' . self::CART, 'autofill token mint: a different key from the registry mint'],
            [static function (Twopayment $m) use ($rates): array {
                $rates->invoke($m, 30, 'NO');

                return $m->sent[0]['headers'];
            }, '/^merchant_rates_[0-9a-f]{16}$/', 'merchant fee rates: merchant_rates_<payload hash>'],
            [static function (Twopayment $m) use ($upload, $upper): array {
                $upload->invoke(new TwoInvoiceUploadService($m), $upper, 3);

                return $m->sent[0]['headers'];
            }, 'invoice_upload_' . self::TWO_ORDER . '_3', 'invoice upload URL: invoice_upload_<invoice id>_<index>'],
        ];
        foreach ($cases as [$send, $expected, $desc]) {
            StubStore::reset();
            Context::getContext()->cart = new Cart(self::CART);
            $module = self::recordingModule();
            $key = Twopayment::findTwoIdempotencyKey($send($module));

            TinyAssert::true($key !== null, $desc . ': sends Idempotency-Key');
            if ($expected[0] === '/') {
                TinyAssert::true((bool) preg_match($expected, (string) $key), $desc . ': ' . $key . ' matches ' . $expected);
            } else {
                TinyAssert::same($expected, $key, $desc);
            }
            self::assertKeyShape((string) $key, $desc);
        }
    }

    /**
     * An edit's key is edit_<two order id>_<hash of this payload after the last one Two accepted>: a retry repeats
     * it, a different payload changes it, and reverting to an earlier payload is a new edit.
     */
    private static function testOrderEditKeyIsOnePerEdit(): void
    {
        $send = static function (string $gross, string $previous): string {
            StubStore::reset();
            $module = self::recordingModule();
            $order = new Order(91);
            $module->putTwoOrderUpdate($order, ['two_order_id' => self::TWO_ORDER, 'two_update_hash' => $previous, 'test_gross' => $gross], $sent, 'buyer_confirmation');
            TinyAssert::same('PUT', $module->sent[0]['method'], 'the edit is a PUT');

            return (string) Twopayment::findTwoIdempotencyKey($module->sent[0]['headers']);
        };
        $first = $send('100.00', 'hash-a');
        // [key, the key it is compared with, whether they are equal, description]
        $cases = [
            [$send('100.00', 'hash-a'), $first, true, 'a retry of the same edit repeats its key'],
            [$send('120.00', 'hash-a'), $first, false, 'a different payload is a different edit'],
            [$send('100.00', 'hash-b'), $first, false, 'the same payload after a different accepted edit (a revert) is a new edit'],
        ];
        TinyAssert::true((bool) preg_match('/^edit_' . self::TWO_ORDER . '_[0-9a-f]{16}$/', $first), 'edit key shape: ' . $first);
        self::assertKeyShape($first, 'edit');
        foreach ($cases as [$key, $other, $equal, $desc]) {
            TinyAssert::same($equal, $key === $other, $desc);
        }
    }

    /**
     * The body-less full refund is keyed by what it refunds, so a retried status change repeats the key, and it
     * never shares a key with a remainder refund, even one with no slips before it and the same amount.
     */
    private static function testFullRefundKeyIsStableAndApartFromRemainder(): void
    {
        $id = self::TWO_ORDER;
        $full = Twopayment::buildTwoFullRefundIdempotencyKey($id, '250.00');
        // [key, the key it is compared with, whether they are equal, description]
        $cases = [
            [Twopayment::buildTwoFullRefundIdempotencyKey($id, 250), $full, true, 'a retried full refund repeats its key'],
            [Twopayment::buildTwoFullRefundIdempotencyKey($id, '199.99'), $full, false, 'a different gross is a different refund'],
            [Twopayment::buildTwoRefundRemainderIdempotencyKey($id, '|250.00'), $full, false, 'a remainder with no slips and the same amount'],
            [Twopayment::buildTwoRefundRemainderIdempotencyKey($id, '7|250.00'), $full, false, 'a remainder after a slip'],
            [Twopayment::buildTwoRefundRemainderIdempotencyKey($id, '7|50.00'), 'refund_remainder_' . $id . '_' . md5('7|50.00'), true, 'the remainder key is unchanged'],
        ];
        TinyAssert::true((bool) preg_match('/^refund_remainder_' . $id . '_[0-9a-f]{32}$/', $full), 'full refund key shape: ' . $full);
        self::assertKeyShape($full, 'full refund');
        foreach ($cases as [$key, $other, $equal, $desc]) {
            TinyAssert::same($equal, $key === $other, $desc);
        }
    }

    /** A merchant custom header row can never set the key, in either spelling, whatever its casing. */
    private static function testMerchantHeaderCannotSetKey(): void
    {
        // [stored header name, description]
        $cases = [
            ['Idempotency-Key', 'the header Two reads'],
            ['IDEMPOTENCY-KEY', 'the header Two reads, uppercased'],
            ['X-Idempotency-Key', 'the prefixed spelling'],
        ];
        foreach ($cases as [$name, $desc]) {
            StubStore::reset();
            Configuration::updateValue(Twopayment::CONFIG_CUSTOM_HEADERS, json_encode([
                ['name' => $name, 'value' => 'merchant-fixed', 'send_from_browser' => true],
                ['name' => 'X-Good', 'value' => 'good', 'send_from_browser' => false],
            ]));
            $headers = (new TwopaymentTestHarness())->getTwoRequestHeaders('/v1/order', [Twopayment::buildTwoIdempotencyHeader('create_attempt-1')]);

            TinyAssert::same('create_attempt-1', Twopayment::findTwoIdempotencyKey($headers), $desc . ': the module key is sent');
            TinyAssert::false(in_array($name . ':merchant-fixed', $headers, true), $desc . ': the merchant row is not sent');
            TinyAssert::true(in_array('X-Good:good', $headers, true), $desc . ': other rows still are');
            TinyAssert::false(array_key_exists(strtolower($name), array_change_key_case(Twopayment::getTwoBrowserCustomHeaders())), $desc . ': nor reaches the browser');
        }
    }

    /**
     * Every order write logs the key it went out with, Debug Mode or not; a quote does not, as Debug Mode logs its
     * headers. The call goes to a port nothing listens on, so it fails to connect.
     */
    private static function testOrderWritesLogTheirKey(): void
    {
        $id = self::TWO_ORDER;
        // [method, endpoint, key, whether the key is logged, description]
        $cases = [
            ['POST', '/v1/order', 'create_attempt-1', true, 'order create'],
            ['PUT', "/v1/order/$id", "edit_{$id}_0123456789abcdef", true, 'order edit'],
            ['POST', "/v1/order/$id/confirm", "confirm_$id", true, 'confirm'],
            ['POST', "/v1/order/$id/refund", "refund_remainder_{$id}_abc", true, 'refund'],
            ['POST', '/v1/pricing/order/fee', 'fee_quote_0123456789abcdef', false, 'a fee quote'],
            ['POST', '/v1/order_intent', 'order_intent_1_0123456789abcdef', false, 'an order intent check'],
        ];
        foreach ($cases as [$method, $endpoint, $key, $logged, $desc]) {
            StubStore::reset();
            PrestaShopLogger::reset();
            $module = new class extends TwopaymentTestHarness {
                public function getTwoCheckoutHostUrl()
                {
                    return 'http://127.0.0.1:9';
                }
            };
            $module->setTwoPaymentRequest($endpoint, ['a' => 1], $method, [Twopayment::buildTwoIdempotencyHeader($key)], 2);

            $lines = array_values(array_filter(PrestaShopLogger::$logs, static function (array $entry) use ($key): bool {
                return strpos($entry['message'], 'Idempotency-Key ' . $key) !== false;
            }));
            TinyAssert::count($logged ? 1 : 0, $lines, $desc . ': key log lines');
            if ($logged) {
                TinyAssert::same('TwoPayment: ' . $method . ' ' . $endpoint . ' sent with Idempotency-Key ' . $key . ' (HTTP 0)', $lines[0]['message'], $desc . ': log line');
            }
        }
    }

    /**
     * Regression guard: every setTwoPaymentRequest() call with a literal POST or PUT, and every refund sent through
     * sendTwoOrderRequest(), passes an idempotency header. Calls with a variable method are the wrappers.
     */
    private static function testEveryLiteralWriteCarriesAKey(): void
    {
        $root = dirname(__DIR__);
        $files = array_merge([$root . '/twopayment.php'], glob($root . '/classes/*.php'), glob($root . '/controllers/front/*.php'));
        $checked = 0;
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match_all('/->(setTwoPaymentRequest|sendTwoOrderRequest)\((.*?)\);/s', $source, $calls, PREG_SET_ORDER);
            foreach ($calls as [$call, $function, $args]) {
                $write = $function === 'setTwoPaymentRequest'
                    ? (bool) preg_match("/'(POST|PUT|PATCH|DELETE)'/", $args)
                    : strpos($args, 'REQUEST_REFUND') !== false;
                if (!$write) {
                    continue;
                }
                $checked++;
                TinyAssert::true(
                    strpos($args, 'Idempotency') !== false,
                    basename($file) . ': a write sends no idempotency key: ' . preg_replace('/\s+/', ' ', substr($call, 0, 160))
                );
            }
        }
        TinyAssert::true($checked >= 9, 'the scan found the writes, got ' . $checked);
    }
}
