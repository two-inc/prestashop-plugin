<?php

declare(strict_types=1);

/**
 * TWO-27014: what the module's log says when the order postprocessing hook runs merchant subscribers. One line per
 * request names them in run order; a request with a body also stores a JSON trace of the payload before the first
 * subscriber and after each one, with the buyer's personal details redacted, listed on the error log page.
 *
 * Uses OrderPostprocessingSpec's fixture cart, harness and subscriber modes.
 */
final class OrderPostprocessingTraceSpec
{
    private const FIRST = 'twoorderpostprocessingtest';
    private const SECOND = 'twoorderpostprocessingsecond';

    // The buyer's personal values in OrderPostprocessingSpec::seed(), and the invoice email and order note set here.
    private const BUYER_VALUES = ['Pia', 'Sol', 'buyer@example.com', '666666601', 'Calle Uno 1', 'ap@example.com', 'Hook Shop SL', 'Ring the bell'];

    public static function runAll(): void
    {
        self::testInvocationIsLoggedOncePerRequest();
        self::testTraceHoldsThePayloadAfterEachSubscriber();
        self::testTraceNamesTheSubscriberThatThrew();
        self::testTraceNeverChangesThePayloadSent();
        self::testALoggingFailureNeverFailsOrMisreportsTheRequest();
        self::testRedactionKeepsAmountsAndDropsBuyerDetails();
        self::testRedactionRemovesEveryBuyerValueTheBuildersSend();
        self::testOversizedTraceShedsInItsDefinedOrder();
    }

    /**
     * Every request type, bodyless ones too: one always-on line naming the subscribers in run order, and one trace
     * per request with a body. No subscriber, no line and no trace.
     */
    private static function testInvocationIsLoggedOncePerRequest(): void
    {
        $drivers = [];
        foreach (OrderPostprocessingSpec::requestDrivers() as [$type, $trigger, , $driver]) {
            $drivers[$type . '/' . $trigger] = $driver;
        }
        $one = self::FIRST;
        $two = self::FIRST . ', ' . self::SECOND;
        // [driver, subscribers, subject, expected names (null: no line), traces, description]
        $cases = [
            ['order_intent/precheck', 1, 'cart 9701', $one, 1, 'an order intent pre-check'],
            ['order_intent/strict_intent', 1, 'cart 9701', $one, 1, 'an order intent at payment'],
            ['order_create/checkout', 1, 'cart 9701', $one, 1, 'an order create'],
            ['order_update/admin_edit', 1, 'order 9731', $one, 1, 'an order update on an admin edit'],
            ['order_update/tracking_number', 1, 'order 9731', $one, 1, 'an order update on a tracking number'],
            ['order_confirm/payment_return', 1, 'order 9731', $one, 0, 'a bodyless confirm'],
            ['capture/status_change', 1, 'order 9731', $one, 1, 'a capture'],
            ['refund/credit_slip', 1, 'order 9731', $one, 1, 'a partial refund'],
            ['cancel/status_change', 1, 'order 9731', $one, 0, 'a bodyless cancel'],
            ['cancel/attempt_persist_failed', 1, 'no cart', $one, 0, 'a best-effort cancel with no cart or order'],
            ['order_create/checkout', 2, 'cart 9701', $two, 1, 'an order create with two subscribers, in run order'],
            ['cancel/status_change', 2, 'order 9731', $two, 0, 'a bodyless cancel with two subscribers'],
            ['order_create/checkout', 0, 'cart 9701', null, 0, 'an order create with no subscriber'],
            ['cancel/status_change', 0, 'order 9731', null, 0, 'a bodyless cancel with no subscriber'],
        ];
        foreach ($cases as [$key, $subscribers, $subject, $names, $traces, $description]) {
            [$type, $trigger] = explode('/', $key);
            $cart = OrderPostprocessingSpec::seed(true);
            $module = OrderPostprocessingSpec::module();
            OrderPostprocessingSpec::harnessAsInstance($module);
            $calls = [];
            if ($subscribers > 0) {
                OrderPostprocessingSpec::subscribe('noop', $calls);
            }
            if ($subscribers > 1) {
                OrderPostprocessingSpec::subscribe('noop', $calls, self::SECOND);
            }
            $drivers[$key]($module, $cart);

            $lines = array_values(array_filter(PrestaShopLogger::$logs, static function ($entry) {
                return strpos($entry['message'], 'TwoPayment: The order postprocessing hook is invoking ') === 0;
            }));
            if ($names === null) {
                TinyAssert::count(0, $lines, $description . ': no invocation line');
            } else {
                $expected = 'TwoPayment: The order postprocessing hook is invoking ' . $subscribers . ' subscriber' . ($subscribers === 1 ? '' : 's')
                    . ' on the ' . $type . ' request (' . $trigger . ', ' . $subject . '), in this order: ' . $names . '.';
                TinyAssert::same([$expected], array_column($lines, 'message'), $description . ': one invocation line');
                TinyAssert::same([[1, true]], array_map(static function ($entry) {
                    return [$entry['severity'], $entry['allow_duplicate']];
                }, $lines), $description . ': informational, and kept when identical to an earlier one');
            }
            TinyAssert::count($traces, self::traces(), $description . ': trace records');
        }
    }

    /**
     * Two subscribers on an order create: the trace holds the payload before the first one and after each, in run
     * order, redacted, and decodes from the row as PrestaShop 1.7 stores it.
     */
    private static function testTraceHoldsThePayloadAfterEachSubscriber(): void
    {
        $cart = OrderPostprocessingSpec::seed(false, true);
        $module = OrderPostprocessingSpec::module();
        OrderPostprocessingSpec::harnessAsInstance($module);
        $calls = [];
        OrderPostprocessingSpec::subscribe('resplit', $calls);
        OrderPostprocessingSpec::subscribe('tag', $calls, self::SECOND);
        $module->getTwoNewOrderData('merchant-attempt-9701', $cart, OrderPostprocessingSpec::merchantUrls());

        $rows = self::traces();
        TinyAssert::count(1, $rows, 'one trace for the create');
        $row = $rows[0];
        TinyAssert::same([1, 9701], [$row['severity'], $row['object_id']], 'informational, filed under the cart');
        // 1.7 stores the message pSQL()-escaped.
        $trace = json_decode((string) TwoDiscrepancySnapshot::decodeStored(addslashes($row['message'])), true);
        TinyAssert::same(
            [
                'v' => 1,
                'request_type' => 'order_create',
                'trigger' => 'checkout',
                'id_cart' => 9701,
                'id_order' => 0,
                'module_version' => $module->version,
                'contract_version' => TwoOrderPostprocessing::CONTRACT_VERSION,
                'redacted' => true,
                'subscribers' => [self::FIRST, self::SECOND],
                'before' => TwoOrderPostprocessingTrace::redact($calls[0]['payload_in']),
                'steps' => [
                    ['subscriber' => self::FIRST, 'payload' => TwoOrderPostprocessingTrace::redact($calls[0]['payload_out'])],
                    ['subscriber' => self::SECOND, 'payload' => TwoOrderPostprocessingTrace::redact($calls[1]['payload_out'])],
                ],
            ],
            $trace,
            'the whole record'
        );
        TinyAssert::same('5.03', $trace['steps'][0]['payload']['line_items'][1]['tax_amount'], 'the first subscriber\'s re-split shows on its own step');
        TinyAssert::same(['', '', 'redacted'], [$trace['before']['order_note'], $trace['steps'][0]['payload']['order_note'], $trace['steps'][1]['payload']['order_note']], 'the second subscriber\'s note shows on its own step, redacted');
    }

    /**
     * A subscriber that throws: the trace is still written, names it with its exception's class and throw site and
     * never its message, and has a step only for each subscriber that returned. One that leaves no array is named.
     */
    private static function testTraceNamesTheSubscriberThatThrew(): void
    {
        // [first mode, second mode|null, expected threw subscriber|null, expected steps [subscriber => payload|left], description]
        $cases = [
            ['throws', null, self::FIRST, [], 'a lone subscriber that throws'],
            ['resplit', 'half_then_throw', self::SECOND, [self::FIRST => 'payload'], 'the second subscriber throws after a half edit'],
            ['non_array', null, null, [self::FIRST => 'NULL'], 'a subscriber that leaves no array'],
        ];
        foreach ($cases as [$first, $second, $threw, $steps, $description]) {
            $cart = OrderPostprocessingSpec::seed(false, true);
            $module = OrderPostprocessingSpec::module();
            OrderPostprocessingSpec::harnessAsInstance($module);
            $calls = [];
            OrderPostprocessingSpec::subscribe($first, $calls);
            if ($second !== null) {
                OrderPostprocessingSpec::subscribe($second, $calls, self::SECOND);
            }
            $code = null;
            try {
                $module->getTwoNewOrderData('merchant-attempt-9701', $cart, OrderPostprocessingSpec::merchantUrls());
            } catch (TwoOrderPostprocessingException $e) {
                $code = $e->getTwoCode();
            }
            TinyAssert::same(TwoOrderPostprocessing::CODE_HOOK_FAILED, $code, $description . ': the request still fails');
            $rows = self::traces();
            TinyAssert::count(1, $rows, $description . ': the trace is written');
            $trace = json_decode($rows[0]['message'], true);
            TinyAssert::same(3, $rows[0]['severity'], $description . ': at the severity of the failure it caused');
            $actual = [];
            foreach ($trace['steps'] as $step) {
                $actual[$step['subscriber']] = isset($step['left']) ? $step['left'] : (isset($step['payload']) ? 'payload' : null);
            }
            TinyAssert::same($steps, $actual, $description . ': a step for each subscriber that returned');
            if ($threw === null) {
                TinyAssert::false(isset($trace['threw']), $description . ': nothing threw');
                continue;
            }
            TinyAssert::same([$threw, 'RuntimeException'], [$trace['threw']['subscriber'], $trace['threw']['class']], $description . ': who threw what');
            TinyAssert::same(1, preg_match('#^tests/OrderPostprocessingSpec\.php:\d+$#', $trace['threw']['at']), $description . ': the throw site (' . $trace['threw']['at'] . ')');
            TinyAssert::false(strpos($rows[0]['message'], 'half way') !== false || strpos($rows[0]['message'], 'lives at') !== false, $description . ': never the message');
        }
    }

    /**
     * A subscriber that keeps a PHP reference into the payload shares that slot with every plain copy of it. The
     * trace copies by value, so its redaction never reaches the payload sent to Two, and a later subscriber writing
     * through the shared slot never changes an earlier subscriber's step.
     */
    private static function testTraceNeverChangesThePayloadSent(): void
    {
        // [first subscriber, second subscriber|null, path => expected in the payload sent, path => expected in step 0, description]
        $cases = [
            [static function (array $params, &$held): void {
                $held['ref'] = &$params['payload']['buyer'];
            }, null, ['/buyer/representative/email' => 'buyer@example.com'], ['/buyer/representative/email' => 'redacted'], 'a reference kept to the buyer'],
            [static function (array $params, &$held): void {
                $held['ref'] = &$params['payload']['buyer']['representative']['email'];
            }, null, ['/buyer/representative/email' => 'buyer@example.com'], ['/buyer/representative/email' => 'redacted'], 'a reference kept to the email itself'],
            [static function (array $params, &$held): void {
                $held['ref'] = &$params['payload']['line_items'][0];
            }, static function (array $params): void {
                $params['payload']['line_items'][0]['name'] = 'Renamed';
            }, ['/line_items/0/name' => 'Renamed'], ['/line_items/0/name' => 'Lamp'], 'a later subscriber writing through a kept reference'],
        ];
        foreach ($cases as [$first, $second, $sent, $stepZero, $description]) {
            $cart = OrderPostprocessingSpec::seed(true);
            $module = OrderPostprocessingSpec::module();
            OrderPostprocessingSpec::harnessAsInstance($module);
            // Kept in an array element: rebinding a by-reference parameter would not reach this variable.
            $held = [];
            Hook::$subscribers[TwoOrderPostprocessing::HOOK][self::FIRST] = static function (array $params) use ($first, &$held): void {
                $first($params, $held);
            };
            if ($second !== null) {
                Hook::$subscribers[TwoOrderPostprocessing::HOOK][self::SECOND] = $second;
            }
            $payload = $module->getTwoNewOrderData('merchant-attempt-9701', $cart, OrderPostprocessingSpec::merchantUrls());
            $trace = json_decode(self::traces()[0]['message'], true);
            foreach ($sent as $path => $expected) {
                TinyAssert::same($expected, self::at($payload, $path), $description . ': sent ' . $path);
            }
            foreach ($stepZero as $path => $expected) {
                TinyAssert::same($expected, self::at($trace['steps'][0]['payload'], $path), $description . ': the first step\'s ' . $path);
            }
            unset($held);
        }
    }

    /**
     * The module's own log failing, as a ps_log insert can, never fails the request, never reads as a subscriber's
     * throw, and never keeps a subscriber from running.
     */
    private static function testALoggingFailureNeverFailsOrMisreportsTheRequest(): void
    {
        $drivers = [];
        foreach (OrderPostprocessingSpec::requestDrivers() as [$type, $trigger, , $driver]) {
            $drivers[$type . '/' . $trigger] = $driver;
        }
        // [driver, log that fails, description]
        $cases = [
            ['order_create/checkout', '/is invoking/', 'the invocation line fails on a create'],
            ['order_create/checkout', '/' . TwoOrderPostprocessingTrace::LOG_OBJECT_TYPE . '/', 'the trace write fails on a create'],
            ['order_create/checkout', '/is invoking|' . TwoOrderPostprocessingTrace::LOG_OBJECT_TYPE . '/', 'both fail on a create'],
            ['cancel/status_change', '/is invoking/', 'the invocation line fails on a bodyless cancel'],
        ];
        foreach ($cases as [$key, $failOn, $description]) {
            $cart = OrderPostprocessingSpec::seed(true);
            $module = OrderPostprocessingSpec::module();
            OrderPostprocessingSpec::harnessAsInstance($module);
            $calls = [];
            OrderPostprocessingSpec::subscribe('tag', $calls);
            PrestaShopLogger::$failOn = $failOn;
            $error = null;
            try {
                $drivers[$key]($module, $cart);
            } catch (Throwable $e) {
                $error = get_class($e) . ': ' . $e->getMessage();
            }
            PrestaShopLogger::$failOn = null;
            TinyAssert::same(null, $error, $description . ': the request does not fail');
            TinyAssert::count(1, $calls, $description . ': the subscriber runs');
            $misreported = array_filter(PrestaShopLogger::$logs, static function ($entry) {
                return strpos($entry['message'], TwoOrderPostprocessing::CODE_HOOK_FAILED) !== false || strpos($entry['message'], 'hook threw') !== false;
            });
            TinyAssert::same([], array_values($misreported), $description . ': nothing reads as a subscriber failure');
        }
    }

    /**
     * Each field the builders send, after redaction: the buyer's names, email, phone and street lines are
     * "redacted"; amounts, tax, lines, the company, country, city and postal code are kept.
     */
    private static function testRedactionKeepsAmountsAndDropsBuyerDetails(): void
    {
        $payload = self::builtCreate();
        $redacted = TwoOrderPostprocessingTrace::redact($payload);
        $kept = 'kept';
        // [JSON pointer, expected after redaction ('kept': unchanged and not empty), description]
        $cases = [
            ['/buyer/representative/first_name', 'redacted', 'the representative\'s first name'],
            ['/buyer/representative/last_name', 'redacted', 'the representative\'s last name'],
            ['/buyer/representative/email', 'redacted', 'the representative\'s email'],
            ['/buyer/representative/phone_number', 'redacted', 'the representative\'s phone'],
            ['/billing_address/street_address', 'redacted', 'the billing street'],
            ['/shipping_address/street_address', 'redacted', 'the shipping street'],
            ['/invoice_details/invoice_emails', 'redacted', 'the invoice email'],
            ['/order_note', 'redacted', 'the buyer\'s free-text order note'],
            ['/buyer/company/company_name', 'redacted', 'the company name, a sole trader\'s own name'],
            ['/billing_address/organization_name', 'redacted', 'the billing organisation name'],
            ['/shipping_address/organization_name', 'redacted', 'the shipping organisation name'],
            ['/buyer/company/organization_number', $kept, 'the organisation number'],
            ['/buyer/company/country_prefix', $kept, 'the company country'],
            ['/billing_address/city', $kept, 'the billing city'],
            ['/billing_address/postal_code', $kept, 'the billing postal code'],
            ['/billing_address/country', $kept, 'the billing country'],
            ['/shipping_address/city', $kept, 'the shipping city'],
            ['/shipping_address/postal_code', $kept, 'the shipping postal code'],
            ['/shipping_address/country', $kept, 'the shipping country'],
            ['/gross_amount', $kept, 'the gross amount'],
            ['/net_amount', $kept, 'the net amount'],
            ['/tax_amount', $kept, 'the tax amount'],
            ['/discount_amount', $kept, 'the discount amount'],
            ['/currency', $kept, 'the currency'],
            ['/line_items/0/tax_rate', $kept, 'a product line\'s tax rate'],
            ['/line_items/0/net_amount', $kept, 'a product line\'s net amount'],
            ['/line_items/1/type', $kept, 'the shipping line\'s type'],
            ['/line_items/1/tax_amount', $kept, 'the shipping line\'s tax'],
            ['/line_items/1/discount_amount', $kept, 'the shipping line\'s discount'],
            ['/tax_subtotals/0/tax_rate', $kept, 'a tax subtotal\'s rate'],
            ['/shipping_details/carrier_name', $kept, 'the carrier'],
        ];
        foreach ($cases as [$path, $expected, $description]) {
            $before = self::at($payload, $path);
            TinyAssert::true($before !== null && $before !== '' && $before !== [], $description . ': the builder sends ' . $path);
            TinyAssert::same($expected === $kept ? $before : $expected, self::at($redacted, $path), $description . ': ' . $path);
        }
        // Every path the redaction changes is one of the listed ones, and the list holds no path the builder lacks.
        $changed = array_column(TwoOrderPostprocessing::diff($payload, $redacted), 'path');
        TinyAssert::same(TwoOrderPostprocessingTrace::BUYER_DETAIL_PATHS, array_values(array_intersect(TwoOrderPostprocessingTrace::BUYER_DETAIL_PATHS, $changed)), 'every listed path is redacted on a create');
        TinyAssert::same([], array_values(array_diff($changed, TwoOrderPostprocessingTrace::BUYER_DETAIL_PATHS)), 'nothing else is changed');

        // [payload, expected, description]
        $edges = [
            [['buyer' => ['representative' => ['email' => '', 'phone_number' => null]]], ['buyer' => ['representative' => ['email' => '', 'phone_number' => null]]], 'an empty or null detail shows as it is'],
            [['refund' => '1.00'], ['refund' => '1.00'], 'a payload without the fields is unchanged'],
            [['buyer' => 'flat'], ['buyer' => 'flat'], 'a subscriber\'s odd shape is left alone'],
            [[], [], 'an empty payload'],
            [['buyer' => (object) ['representative' => (object) ['email' => 'x@y.z', 'title' => 'Ms']]], ['buyer' => ['representative' => ['email' => 'redacted', 'title' => 'Ms']]], 'an object a subscriber left, read as the array Two receives'],
        ];
        foreach ($edges as [$input, $expected, $description]) {
            TinyAssert::same($expected, TwoOrderPostprocessingTrace::redact($input), 'redact: ' . $description);
        }
    }

    /**
     * Derived from the builders rather than the list: no buyer value the fixture holds survives redaction on any
     * request with a body.
     */
    private static function testRedactionRemovesEveryBuyerValueTheBuildersSend(): void
    {
        $cart = OrderPostprocessingSpec::seed(true);
        $module = OrderPostprocessingSpec::module();
        $payloads = OrderPostprocessingSpec::payloadsForGolden($module, $cart);
        $payloads['create_with_invoice_email_and_note'] = (string) json_encode(self::builtCreate());
        foreach ($payloads as $name => $json) {
            $encoded = (string) json_encode(TwoOrderPostprocessingTrace::redact(json_decode($json, true)));
            foreach (self::BUYER_VALUES as $value) {
                TinyAssert::false(strpos($encoded, $value) !== false, $name . ': ' . $value . ' is redacted');
            }
            foreach (['B12345678', 'Madrid', '28001'] as $value) {
                TinyAssert::same(strpos($json, $value) !== false, strpos($encoded, $value) !== false, $name . ': ' . $value . ' is kept wherever it is sent');
            }
            TinyAssert::true(strpos($encoded, 'Madrid') !== false, $name . ': sends a city, so the check above is not vacuous');
        }
    }

    /**
     * Over the size cap, a trace sheds in a defined order and lists what it shed, and always fits and decodes.
     */
    private static function testOversizedTraceShedsInItsDefinedOrder(): void
    {
        $steps = static function (int $n): array {
            $out = [];
            for ($i = $n - 1; $i >= 0; --$i) {
                $out[] = 'steps/' . $i . '/payload';
            }

            return $out;
        };
        // [lines, subscribers, description length, each subscriber rewrites every line's description, expected shed (null: none), description]
        $cases = [
            [2, 2, 600, false, null, 'a small trace is whole'],
            [20, 2, 600, false, ['steps/1/payload'], 'the last subscriber\'s payload goes first'],
            [30, 2, 600, false, $steps(2), 'then the one before it'],
            [50, 2, 600, false, array_merge($steps(2), ['before']), 'then the payload before the first subscriber'],
            [50, 5, 600, true, array_merge($steps(5), ['before', 'steps/*/diff']), 'then the diffs'],
            [1, 300, 10, false, array_merge($steps(300), ['before', 'steps/*/diff', 'all']), 'then everything but the header'],
        ];
        foreach ($cases as [$lines, $subscribers, $length, $rewrite, $expected, $description]) {
            $payload = ['gross_amount' => '1.00', 'merchant_reference' => 'ref', 'buyer' => ['representative' => ['email' => 'x@y.z']], 'line_items' => []];
            for ($l = 0; $l < $lines; ++$l) {
                $payload['line_items'][] = ['name' => 'Line ' . $l, 'description' => str_repeat('d', $length), 'net_amount' => '1.00'];
            }
            $names = [];
            for ($s = 0; $s < $subscribers; ++$s) {
                $names[] = $subscribers > 40 ? str_pad('subscriber' . $s, 120, 'x') : 'subscriber' . $s;
            }
            $trace = new TwoOrderPostprocessingTrace('order_create', 'checkout', 9701, 0, '2.7.21', $names, $payload);
            foreach ($names as $s => $name) {
                $module = new StubHookSubscriberModule($name);
                $trace->starting($module);
                $payload['merchant_reference'] = 'ref' . $s;
                $payload['buyer']['representative']['email'] = 's' . $s . '@y.z';
                foreach ($payload['line_items'] as $l => $line) {
                    $payload['line_items'][$l]['net_amount'] = (string) ($s + 2) . '.00';
                    if ($rewrite) {
                        $payload['line_items'][$l]['description'] = str_repeat((string) $s, $length);
                    }
                }
                $trace->finished($module, $payload);
            }
            $json = $trace->encode();
            TinyAssert::true(TwoDiscrepancySnapshot::fits($json), $description . ': fits (' . strlen(addslashes($json)) . ' bytes escaped)');
            $decoded = json_decode((string) TwoDiscrepancySnapshot::decodeStored(addslashes($json)), true);
            TinyAssert::true(is_array($decoded), $description . ': decodes as 1.7 stores it');
            TinyAssert::same($expected, isset($decoded['shed']) ? $decoded['shed'] : null, $description . ': shed (' . strlen(addslashes($json)) . ' bytes, got ' . json_encode($decoded['shed'] ?? null) . ')');
            if ($expected === null) {
                TinyAssert::same($trace->record(), $decoded, $description . ': the whole record');
                continue;
            }
            TinyAssert::same(['order_create', 'checkout', 9701], [$decoded['request_type'], $decoded['trigger'], $decoded['id_cart']], $description . ': the header stays');
            if (in_array('all', $expected, true)) {
                TinyAssert::false(isset($decoded['steps']) || isset($decoded['before']), $description . ': only the header');
                continue;
            }
            $last = $decoded['steps'][$subscribers - 1];
            TinyAssert::same(['subscriber' . ($subscribers - 1), $lines * ($rewrite ? 2 : 1) + 2], [$last['subscriber'], $last['changes']], $description . ': a shed step keeps its subscriber and count of changes');
            if (!in_array('steps/*/diff', $expected, true)) {
                $byPath = array_column($last['diff'], null, 'path');
                $diffRows = [
                    ['/line_items/0/net_amount', (string) $subscribers . '.00', (string) ($subscribers + 1) . '.00', 'an amount'],
                    ['/merchant_reference', 'ref' . ($subscribers - 2), 'ref' . ($subscribers - 1), 'a kept field outside the amounts, as an unshed step shows it'],
                    ['/buyer/representative/email', 'redacted', 'redacted', 'a buyer detail, redacted as in the payloads'],
                ];
                foreach ($diffRows as [$path, $before, $after, $what]) {
                    TinyAssert::same(['path' => $path, 'before' => $before, 'after' => $after], $byPath[$path] ?? null, $description . ': ' . $what . ' against the step before');
                }
            } else {
                TinyAssert::false(isset($last['diff']), $description . ': the diff is shed');
            }
            if (count($expected) === 1) {
                TinyAssert::same($trace->record()['steps'][0], $decoded['steps'][0], $description . ': the earlier step stays whole');
            }
        }
    }

    /**
     * An order create as the builder sends it, with the optional invoice email and the order note the buyer filled in.
     *
     * @return array
     */
    private static function builtCreate(): array
    {
        $cart = OrderPostprocessingSpec::seed(true);
        $module = OrderPostprocessingSpec::module();
        StubStore::$cartMessages[(int) $cart->id] = ['id_message' => 1, 'message' => 'Ring the bell'];
        Configuration::updateValue('PS_TWO_ENABLE_INVOICE_EMAIL', 1);
        Tools::setTestValue('two_invoice_email', 'ap@example.com');
        try {
            return $module->getTwoNewOrderData('merchant-attempt-9701', $cart, OrderPostprocessingSpec::merchantUrls());
        } finally {
            Tools::resetTestValues();
            Configuration::updateValue('PS_TWO_ENABLE_INVOICE_EMAIL', 0);
        }
    }

    /**
     * @return mixed null when the path is absent
     */
    private static function at(array $payload, string $path)
    {
        $node = $payload;
        foreach (explode('/', ltrim($path, '/')) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }

    /**
     * @return array<int,array{message:string,severity:int,object_type:?string,object_id:?int}>
     */
    private static function traces(): array
    {
        return array_values(array_filter(PrestaShopLogger::$logs, static function ($entry) {
            return $entry['object_type'] === TwoOrderPostprocessingTrace::LOG_OBJECT_TYPE;
        }));
    }
}
