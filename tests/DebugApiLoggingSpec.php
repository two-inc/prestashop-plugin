<?php

declare(strict_types=1);

/**
 * TWO-26340: Debug Mode logs each API call as the WooCommerce plugin does. With Debug Mode on, one log line per call
 * names the method and endpoint and carries the request's body, headers and query parameters and the response's
 * body, headers and status code, at error severity for a connection error or HTTP 400 and above. The API key and
 * the custom header values are redacted. With Debug Mode off nothing is logged, since the bodies carry buyer data.
 */
final class DebugApiLoggingSpec
{
    public static function runAll(): void
    {
        self::testExchangeLog();
        self::testEveryCallIsLogged();
        self::testLogSurvivesCoreStorage();
    }

    /**
     * Core strips tags from a log message and ps_log.message is a 64KB TEXT: a body with markup keeps it, escaped,
     * and a long call is cut to fit. Columns: response body, whether the line is cut, a fragment it must keep.
     */
    private static function testLogSurvivesCoreStorage(): void
    {
        $cases = [
            ['{"message":"<b>bad</b> request"}', false, '\\u003Cb\\u003Ebad', 'markup survives the stripping, escaped'],
            [json_encode(['lines' => str_repeat('é', 40000)]), true, 'TwoPayment: POST /v1/order - ', 'a long call is cut to fit'],
        ];
        foreach ($cases as [$body, $cut, $kept, $desc]) {
            StubStore::reset();
            PrestaShopLogger::reset();
            StubStore::$configuration['PS_TWO_DEBUG_MODE'] = 1;
            $method = new ReflectionMethod(Twopayment::class, 'logTwoApiExchange');
            $method->invoke(new TwopaymentTestHarness(), 'POST', '/v1/order', [], [], $body, 400, [], '');

            TinyAssert::count(1, PrestaShopLogger::$logs, $desc . ': one log line');
            $message = PrestaShopLogger::$logs[0]['message'];
            TinyAssert::true(strpos($message, $kept) !== false, $desc . ': keeps ' . $kept);
            TinyAssert::same($cut, substr($message, -6) === ' [cut]', $desc . ': cut');
            TinyAssert::true(strlen(addslashes($message)) <= TwoDiscrepancySnapshot::MAX_BYTES, $desc . ': fits the log');
            TinyAssert::true(mb_check_encoding($message, 'UTF-8'), $desc . ': still UTF-8');
        }
    }

    private static function testExchangeLog(): void
    {
        $payload = ['buyer' => ['email' => 'buyer@example.com'], 'gross_amount' => '12.50'];
        $headers = ['Content-Type: application/json; charset=utf-8', 'X-API-Key:secret-key', 'X-Firewall:firewall-secret', 'X-Vendor-Name:Shop One'];
        // [debug, response body, status, curl error, expected severity (null: no log), expected message start, expected response, description]
        $cases = [
            [0, '{"id":"o-1"}', 201, '', null, null, null, 'debug off: nothing is logged'],
            [1, '{"id":"o-1"}', 201, '', 1, 'TwoPayment: POST /v1/order - ', ['body' => ['id' => 'o-1'], 'headers' => ['X-Trace' => 't-1'], 'status_code' => 201], 'debug on, accepted: informative'],
            [1, '{"message":"bad"}', 400, '', 3, 'TwoPayment: POST /v1/order - ', ['body' => ['message' => 'bad'], 'headers' => ['X-Trace' => 't-1'], 'status_code' => 400], 'debug on, HTTP 400: error'],
            [1, 'Bad Gateway', 502, '', 3, 'TwoPayment: POST /v1/order - ', ['body' => 'Bad Gateway', 'headers' => ['X-Trace' => 't-1'], 'status_code' => 502], 'debug on, a body that is not JSON: logged raw'],
            [1, false, 0, 'Connection refused', 3, 'TwoPayment: POST /v1/order: cURL error: Connection refused - ', ['body' => null, 'headers' => null, 'status_code' => null], 'debug on, connection error: error, no response'],
        ];

        foreach ($cases as [$debug, $body, $status, $curlError, $severity, $start, $response, $desc]) {
            StubStore::reset();
            PrestaShopLogger::reset();
            StubStore::$configuration['PS_TWO_DEBUG_MODE'] = $debug;
            StubStore::$configuration[Twopayment::CONFIG_CUSTOM_HEADERS] = json_encode([['name' => 'X-Firewall', 'value' => 'firewall-secret']]);
            $module = new TwopaymentTestHarness();

            $method = new ReflectionMethod(Twopayment::class, 'logTwoApiExchange');
            $method->invoke($module, 'POST', '/v1/order', $payload, $headers, $body, $status, new ArrayObject(['X-Trace' => 't-1']), $curlError);

            if ($severity === null) {
                TinyAssert::count(0, PrestaShopLogger::$logs, $desc);
                continue;
            }
            TinyAssert::count(1, PrestaShopLogger::$logs, $desc . ': one log line');
            $entry = PrestaShopLogger::$logs[0];
            TinyAssert::same($severity, $entry['severity'], $desc . ': severity');
            TinyAssert::same($start, substr($entry['message'], 0, strlen($start)), $desc . ': message, got ' . $entry['message']);
            TinyAssert::false(strpos($entry['message'], 'secret') !== false, $desc . ': no secret in the log');
            $context = json_decode(substr($entry['message'], strlen($start)), true);
            TinyAssert::same($payload, $context['request']['body'], $desc . ': request body');
            TinyAssert::same([
                'Content-Type' => 'application/json; charset=utf-8',
                'X-API-Key' => '[REDACTED]',
                'X-Firewall' => '[REDACTED]',
                'X-Vendor-Name' => 'Shop One',
            ], $context['request']['headers'], $desc . ': request headers');
            TinyAssert::same($module->getTwoClientParams(), $context['request']['params'], $desc . ': request params');
            TinyAssert::same($response, $context['response'], $desc . ': response');
        }
    }

    /**
     * setTwoPaymentRequest() logs every call through logTwoApiExchange(), whatever its method: a call to a port
     * nothing listens on fails to connect, and with Debug Mode on is logged as a connection error with its body.
     */
    private static function testEveryCallIsLogged(): void
    {
        // [debug, method, payload, description]
        $cases = [
            [1, 'POST', ['marker' => 'post-body'], 'debug on: a POST is logged'],
            [1, 'GET', [], 'debug on: a GET is logged'],
            [0, 'POST', ['marker' => 'post-body'], 'debug off: nothing but the connection error is logged'],
        ];

        foreach ($cases as [$debug, $httpMethod, $payload, $desc]) {
            StubStore::reset();
            PrestaShopLogger::reset();
            StubStore::$configuration['PS_TWO_DEBUG_MODE'] = $debug;
            $module = new class extends TwopaymentTestHarness {
                public function getTwoCheckoutHostUrl()
                {
                    return 'http://127.0.0.1:9';
                }
            };

            $response = $module->setTwoPaymentRequest('/v1/order', $payload, $httpMethod, [], 2);

            TinyAssert::same(0, $response['http_status'], $desc . ': no connection');
            $logged = array_values(array_filter(PrestaShopLogger::$logs, static function (array $entry) use ($httpMethod): bool {
                return strpos($entry['message'], 'TwoPayment: ' . $httpMethod . ' /v1/order: cURL error: ') === 0;
            }));
            TinyAssert::count($debug, $logged, $desc . ': exchange log lines');
            if ($debug === 1) {
                TinyAssert::same(3, $logged[0]['severity'], $desc . ': severity');
                TinyAssert::same($payload === [] ? false : true, strpos($logged[0]['message'], 'post-body') !== false, $desc . ': the body is logged');
                TinyAssert::false(strpos($logged[0]['message'], 'test-api-key') !== false, $desc . ': the API key is redacted');
            }
            foreach (PrestaShopLogger::$logs as $entry) {
                if ($debug === 0) {
                    TinyAssert::false(strpos($entry['message'], 'post-body') !== false, $desc . ': no body is logged');
                }
            }
        }
    }
}
