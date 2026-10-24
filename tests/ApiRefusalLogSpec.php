<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/orderintent.php';

final class StubRefusalRelayResponded extends RuntimeException
{
}

/**
 * TWO-26611: every Two API refusal at checkout records Two's reason, with the cart it was
 * refused for, in the module log the merchant reads on the Two error log tab. The buyer keeps
 * the module's own generic wording and never sees Two's text.
 */
final class ApiRefusalLogSpec
{
    private const CART_ID = 26611;

    private const DETAIL = 'Line item tax_code is required when tax_rate is 0.000000 for ES orders';

    public static function runAll(): void
    {
        self::testOnlyAnAnswerOfFourHundredOrAboveIsLogged();
        self::testIntentAtPaymentSubmitKeepsTwosReasonOffTheStorefront();
        self::testRelayedCheckoutCallsLogTheirRefusal();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
    }

    /** @return array<int,array{message:string,severity:int,object_type:?string,object_id:?int}> */
    private static function refusals(string $refused): array
    {
        return array_values(array_filter(PrestaShopLogger::$logs, function ($entry) use ($refused) {
            return strpos((string) $entry['message'], 'TwoPayment: Two refused ' . $refused . ' for ') === 0;
        }));
    }

    private static function assertLogged(string $refused, string $fragment, string $description): void
    {
        $refusals = self::refusals($refused);
        TinyAssert::count(1, $refusals, 'one refusal logged: ' . $description);
        TinyAssert::same(3, $refusals[0]['severity'], 'logged at error severity: ' . $description);
        TinyAssert::same('Cart', $refusals[0]['object_type'], 'logged against the cart: ' . $description);
        TinyAssert::same(self::CART_ID, $refusals[0]['object_id'], 'logged against this cart: ' . $description);
        TinyAssert::true(strpos($refusals[0]['message'], 'cart ' . self::CART_ID) !== false, 'the log names the cart: ' . $description);
        TinyAssert::true(strpos($refusals[0]['message'], $fragment) !== false, 'the log carries Two\'s reason: ' . $description);
    }

    private static function testOnlyAnAnswerOfFourHundredOrAboveIsLogged(): void
    {
        // [response, whether it is a refusal, description].
        $cases = [
            [['http_status' => 400, 'error_details' => self::DETAIL], true, 'a 400 is a refusal'],
            [['http_status' => 500, 'error_message' => 'Internal error'], true, 'a 500 is a refusal'],
            [['http_status' => 0, 'error_message' => 'Unable to connect'], false, 'no answer is already logged as a connection error'],
            [['http_status' => 200, 'approved' => false], false, 'a decline is an answer, not a refusal'],
            [['http_status' => 399], false, 'below 400 is not a refusal'],
            [null, false, 'no response at all is not a refusal'],
        ];
        foreach ($cases as [$response, $refusal, $description]) {
            self::reset();
            $logged = (new TwopaymentTestHarness())->logTwoApiRefusal('order intent', 'cart ' . self::CART_ID, $response, self::CART_ID);
            TinyAssert::same($refusal, $logged, 'reported: ' . $description);
            TinyAssert::count($refusal ? 1 : 0, self::refusals('order intent'), 'logged: ' . $description);
        }
    }

    private static function testIntentAtPaymentSubmitKeepsTwosReasonOffTheStorefront(): void
    {
        $generic = 'Invoice purchase with Two is not available for this order.';
        // [intent response, buyer message, a fragment the log must carry (null: none), description].
        $cases = [
            [['http_status' => 400, 'error_code' => 'SCHEMA_ERROR', 'error_details' => self::DETAIL],
                $generic, self::DETAIL, 'Two\'s reason stays off the storefront'],
            [['http_status' => 422, 'error_trace_id' => 'trace-26611', 'error_json' => [['loc' => ['buyer', 'representative', 'phone_number'], 'msg' => 'bad']]],
                'Please enter a valid Phone number to pay on invoice.', 'trace-26611', 'a field the buyer can correct is named in the module\'s words'],
            [['http_status' => 500, 'error_message' => 'Internal error'],
                'Payment provider temporarily unavailable. Please try again later.', 'HTTP 500 Internal error', 'a server error is logged too'],
            [['http_status' => 0, 'error_message' => 'Unable to connect'],
                'Connection error with payment provider. Please try again.', null, 'no answer is not a refusal'],
        ];
        foreach ($cases as [$response, $buyerMessage, $fragment, $description]) {
            self::reset();
            $module = new class extends TwopaymentTestHarness {
                /** @var array */
                public $response = [];

                public function getTwoIntentOrderData($cart, $customer, $currency, $address)
                {
                    return ['currency' => 'EUR'];
                }

                protected function shouldRunStrictOrderIntentParityAtPayment()
                {
                    return false;
                }

                public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
                {
                    return $this->response;
                }
            };
            $module->response = $response;

            $result = $module->checkTwoOrderIntentApprovalAtPayment(new Cart(self::CART_ID), new Customer(), new Currency(), new Address());

            TinyAssert::false($result['approved'], 'not approved: ' . $description);
            TinyAssert::same($buyerMessage, $result['message'], 'buyer message: ' . $description);
            if ($fragment === null) {
                TinyAssert::count(0, self::refusals('order intent at payment submit'), 'no refusal logged: ' . $description);
                continue;
            }
            self::assertLogged('order intent at payment submit', $fragment, $description);
        }
    }

    private static function testRelayedCheckoutCallsLogTheirRefusal(): void
    {
        // [what the relay names, response, a fragment the log must carry (null: none), description].
        $cases = [
            ['order intent', ['http_status' => 400, 'data' => ['error_details' => self::DETAIL], 'error_details' => self::DETAIL],
                self::DETAIL, 'an order intent refusal is logged'],
            ['company lookup', ['http_status' => 404, 'data' => ['error_code' => 'NOT_FOUND'], 'error_code' => 'NOT_FOUND'],
                'HTTP 404 NOT_FOUND', 'a company lookup refusal is logged'],
            ['order intent', ['http_status' => 200, 'data' => ['approved' => true], 'approved' => true],
                null, 'an answer is not logged'],
            [null, ['http_status' => 400, 'data' => ['error_code' => 'INVALID_REQUEST']],
                null, 'a refusal the module made up itself is not Two\'s'],
        ];
        foreach ($cases as [$refused, $response, $fragment, $description]) {
            self::reset();
            Context::getContext()->cart = new Cart(self::CART_ID);
            $controller = new class extends TwopaymentOrderintentModuleFrontController {
                /** @var array<int,mixed> */
                public array $emitted = [];

                public function sendJsonResponse($content)
                {
                    $this->emitted[] = json_decode((string) $content, true);

                    throw new StubRefusalRelayResponded('relayed');
                }
            };
            $controller->module = new TwopaymentTestHarness();
            $relay = new ReflectionMethod($controller, 'relayTwoApiResponse');
            try {
                // @: the relay sets the HTTP status, which a CLI run that has printed cannot.
                @$relay->invoke($controller, $response, $refused);
            } catch (StubRefusalRelayResponded $e) {
            }

            TinyAssert::same([$response['data']], $controller->emitted, 'the body is relayed untouched: ' . $description);
            if ($fragment === null) {
                TinyAssert::count(0, array_filter(PrestaShopLogger::$logs, function ($entry) {
                    return strpos((string) $entry['message'], 'TwoPayment: Two refused ') === 0;
                }), 'no refusal logged: ' . $description);
                continue;
            }
            self::assertLogged((string) $refused, $fragment, $description);
        }
    }
}
