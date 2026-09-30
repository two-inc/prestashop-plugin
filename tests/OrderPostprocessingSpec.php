<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/orderintent.php';
require_once __DIR__ . '/integration/fixtures/twoorderpostprocessingtest/twoorderpostprocessingtest.php';

/**
 * TWO-26092: the order postprocessing hook (actionTwoOrderPostprocessing).
 *
 * The fixture cart is the one the contract exists for: a 21% product at
 * 100.00 net and 29.00 of shipping the shop charged no tax on, on a "No tax"
 * carrier, so the plugin's own checks pass on what it builds. The worked
 * example re-splits that shipping to 23.97 net + 5.03 tax. The plugin sends
 * whatever a subscriber returns; Two's API validates it.
 *
 * The golden payloads were taken from the builder before the hook existed, with
 * payloadsForGolden(); a deliberate builder change regenerates them the same way.
 */
final class OrderPostprocessingSpec
{
    private const CART = 9701;
    private const ADDRESS = 9711;
    private const CARRIER = 7701;
    private const CARRIER_GROUP = 7721;
    private const PRODUCT = 9721;
    private const ORDER = 9731;
    private const TWO_ORDER = 'two-order-9731';
    private const GOLDEN = __DIR__ . '/fixtures/order-postprocessing-golden.json';

    public static function runAll(): void
    {
        self::testNoSubscriberPayloadsAreByteIdentical();
        self::testHookEditsAreSentAsReturned();
        self::testSubscriberCodeBugsFailTheRequest();
        self::testBuilderGatesRefuseBeforeTheHook();
        self::testSubscriberThrowingGetsTheGenericCheckoutMessage();
        self::testDispatchKeepsCoreSemanticsButNotItsSwallow();
        self::testAdminEditSurvivesAThrowingSubscriber();
        self::testApiRejectionReachesTheLogAndTheOrder();
        self::testOrderIntentRelayBuildsThePayloadItself();
        self::testEachRequestTypeFiresExactlyOnce();
        self::testNoChangeKeepsTodaysOutcomeOnEveryRequestType();
        self::testRecomputeTotalsHelper();
        self::testDebugLogCarriesARedactedDiff();
        self::testHookRowIsCreatedOnceAndNeverSubscribed();
        self::testReadmeExampleIsTheFixturesOwnCode();
        self::testEveryOrderSendGoesThroughTheChoke();
    }

    /* ---- fixtures ---- */

    /**
     * @param bool $shippingTaxed true: the shop taxed shipping at 21% (29.00 incl / 23.97 excl)
     * @param bool $noTaxCarrier true: the carrier's tax rules group is "No tax", so untaxed shipping reconciles
     */
    public static function seed(bool $shippingTaxed, bool $noTaxCarrier = false): Cart
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Hook::$execLists = [];
        StubStore::$customers[self::CART] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Pia',
            'lastname' => 'Sol',
            'secure_key' => 'secure-key-9701',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[self::ADDRESS] = [
            'id_customer' => self::CART,
            'id_country' => 34,
            'company' => 'Hook Shop SL',
            'companyid' => 'B12345678',
            'address1' => 'Calle Uno 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '666666601',
            'loaded' => true,
        ];
        StubStore::$cartProducts[self::CART] = [[
            'id_product' => self::PRODUCT,
            'link_rewrite' => 'lamp',
            'name' => 'Lamp',
            'description_short' => 'Lamp',
            'manufacturer_name' => 'ACME',
            'ean13' => '',
            'upc' => '',
            'total' => 100.00,
            'total_wt' => 121.00,
            'cart_quantity' => 1,
            'rate' => 21.0,
            'price' => 100.00,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[self::PRODUCT] = [['name' => 'General']];
        StubStore::$images[self::PRODUCT] = ['id_image' => self::PRODUCT];
        StubStore::$products[self::PRODUCT]['id_tax_rules_group'] = 9000 + self::PRODUCT;
        StubStore::$taxRuleRates[9000 + self::PRODUCT] = 21.0;
        StubStore::$taxRuleRates[self::CARRIER_GROUP] = 21.0;
        StubStore::$taxRulesGroups[self::CARRIER_GROUP] = ['name' => 'VAT 21%', 'active' => 1];
        StubStore::$carriers[self::CARRIER] = ['name' => 'Courier', 'delay' => '', 'tax_rules_group_id' => $noTaxCarrier ? 0 : self::CARRIER_GROUP];
        $shippingNet = $shippingTaxed ? 23.9669 : 29.00;
        StubStore::$cartDeliveryOptionLists[self::CART] = [
            self::ADDRESS => [self::CARRIER . ',' => ['carrier_list' => [self::CARRIER => [
                'price_with_tax' => 29.00,
                'price_without_tax' => $shippingNet,
                'instance' => new Carrier(self::CARRIER),
            ]]]],
        ];
        StubStore::$cartTotals[self::CART] = [
            true => [Cart::ONLY_DISCOUNTS => 0.00, Cart::ONLY_SHIPPING => 29.00, Cart::BOTH => 150.00],
            false => [Cart::ONLY_DISCOUNTS => 0.00, Cart::ONLY_SHIPPING => $shippingNet, Cart::BOTH => 100.00 + $shippingNet],
        ];
        StubStore::$carts[self::CART] = [
            'id_customer' => self::CART,
            'id_currency' => 978,
            'id_address_invoice' => self::ADDRESS,
            'id_address_delivery' => self::ADDRESS,
            'id_carrier' => self::CARRIER,
            'id_lang' => 1,
        ];
        StubStore::$orders[self::ORDER] = ['id_cart' => self::CART, 'id_customer' => self::CART, 'id_carrier' => self::CARRIER, 'module' => 'twopayment'];

        return new Cart(self::CART);
    }

    private static function merchantUrls(): array
    {
        return [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ];
    }

    private static function order(): PlacedOrderStub
    {
        $order = PlacedOrderStub::fromCart(self::ORDER, self::CART);
        $order->reference = 'HOOKREF';
        StubStore::$placedOrders = [$order];

        return $order;
    }

    /**
     * A harness recording each send. GET answers a CONFIRMED-then-FULFILLED Two order.
     */
    private static function module(): TwopaymentTestHarness
    {
        return new class extends TwopaymentTestHarness {
            /** @var array<int,array{endpoint:string,payload:mixed,method:string}> */
            public array $sent = [];
            public string $twoState = 'CONFIRMED';
            /** @var array|null the answer to every PUT, when set */
            public ?array $putAnswer = null;
            /** @var string[] */
            public array $notes = [];

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                if ($method !== 'GET') {
                    $this->sent[] = ['endpoint' => $endpoint, 'payload' => $payload, 'method' => $method];
                }
                if ($method === 'PUT' && $this->putAnswer !== null) {
                    return $this->putAnswer;
                }
                if (strpos($endpoint, '/refund') !== false) {
                    return ['http_status' => 201, 'id' => 'refund-1'];
                }
                if (strpos($endpoint, '/fulfillments') !== false) {
                    return ['http_status' => 201, 'fulfilled_order' => ['id' => 'fulfilled-1']];
                }
                if ($endpoint === '/v1/order_intent') {
                    return ['http_status' => 200, 'approved' => true];
                }

                return [
                    'http_status' => 200,
                    'id' => 'two-order-9731',
                    'state' => $this->twoState,
                    'status' => 'APPROVED',
                    'merchant_reference' => 'ref',
                    'gross_amount' => '150.00',
                    'currency' => 'EUR',
                    'invoice_url' => '',
                    'refunds' => [],
                ];
            }

            public function getTwoOrderPaymentData($id_order)
            {
                return [
                    'two_order_id' => 'two-order-9731',
                    'two_order_reference' => 'ref',
                    'two_order_state' => $this->twoState,
                    'two_order_status' => 'APPROVED',
                    'two_day_on_invoice' => '30',
                    'two_payment_term_type' => 'STANDARD',
                    'two_invoice_url' => '',
                    'two_invoice_id' => null,
                ];
            }

            public function setTwoOrderPaymentData($id_order, $payment_data)
            {
                return true;
            }

            /** @var string[] */
            public array $warnings = [];

            public function addTwoBackOfficeWarning($message)
            {
                $this->warnings[] = (string) $message;

                return true;
            }

            protected function addTwoOrderPrivateNote($idOrder, $text)
            {
                $this->notes[] = (string) $text;
            }
        };
    }

    /**
     * Register a subscriber that records every call and applies one named behaviour.
     *
     * @param array<int,array> $calls
     */
    private static function subscribe(string $mode, array &$calls, string $module = 'twoorderpostprocessingtest'): void
    {
        Hook::$subscribers[TwoOrderPostprocessing::HOOK][$module] = static function (array $params) use ($mode, &$calls): void {
            $calls[] = ['context' => $params['context'], 'payload_in' => $params['payload']];
            self::apply($mode, $params);
            $calls[count($calls) - 1]['payload_out'] = $params['payload'];
        };
    }

    /**
     * The fixture behaviours; tests/integration/fixtures/twoorderpostprocessingtest carries the same set.
     */
    private static function apply(string $mode, array &$params): void
    {
        switch ($mode) {
            case 'noop':
                return;
            case 'tag':
                if ($params['payload'] !== []) {
                    $params['payload']['order_note'] = 'postprocessed';
                }

                return;
            case 'throws':
                throw new RuntimeException('buyer@example.com lives at Calle Uno 1');
            case 'non_array':
                $params['payload'] = null;

                return;
            case 'not_json':
                $params['payload']['order_note'] = "\xB1\x31";

                return;
            case 'body_on_cancel':
                if ($params['context']['request_type'] === TwoOrderPostprocessing::REQUEST_CANCEL) {
                    $params['payload']['reason'] = 'added';
                }

                return;
            case 'half_then_throw':
                $params['payload']['line_items'][0]['net_amount'] = '0.00';
                throw new RuntimeException('half way');
            case 'buyer_email':
                $params['payload']['buyer']['representative']['email'] = 'someone@example.com';

                return;
        }

        // Every remaining mode is the fixture's own: the README re-split, then any deliberate break.
        $before = $params['payload'];
        // The "No tax" carrier gives no rate, so the merchant's module supplies its own, as the fixture does.
        $rate = (float) $params['context']['shipping_tax_rate'] > 0 ? (float) $params['context']['shipping_tax_rate'] : 0.21;
        $params['payload'] = Twoorderpostprocessingtest::resplitShipping($params['payload'], $rate);
        Twoorderpostprocessingtest::breakOnPurpose($mode, $params['payload'], $before);
    }

    private static function harnessAsInstance(TwopaymentTestHarness $module): void
    {
        StubStore::$moduleInstances['twopayment'] = $module;
    }

    /**
     * The builders' output with the two clock-derived fields pinned, for a byte comparison.
     *
     * @return array<string,string>
     */
    public static function payloadsForGolden(TwopaymentTestHarness $module, Cart $cart): array
    {
        $customer = new Customer($cart->id_customer);
        $currency = new Currency($cart->id_currency);
        $address = new Address($cart->id_address_invoice);
        $strict = new ReflectionMethod(Twopayment::class, 'buildTwoIntentOrderData');
        $payloads = [
            'intent' => $module->getTwoIntentOrderData($cart, $customer, $currency, $address),
            'strict_intent' => $strict->invoke($module, $cart, $customer, $currency, $address, true),
            'create' => $module->getTwoNewOrderData('merchant-attempt-9701', $cart, self::merchantUrls()),
            'update' => $module->getTwoUpdateOrderData(self::order(), ['two_order_id' => self::TWO_ORDER, 'two_order_reference' => 'ref', 'two_day_on_invoice' => '30']),
        ];
        $out = [];
        foreach ($payloads as $name => $payload) {
            if (isset($payload['merchant_reference']) && $name === 'create') {
                $payload['merchant_reference'] = 'PINNED';
            }
            if (isset($payload['shipping_details']['expected_delivery_date'])) {
                $payload['shipping_details']['expected_delivery_date'] = 'PINNED';
            }
            $out[$name] = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        }

        return $out;
    }

    /* ---- specs ---- */

    /**
     * With no subscriber, and with one that edits nothing, every builder
     * returns byte for byte what it returned before the hook existed. The
     * golden was produced from the base commit by the same method.
     */
    private static function testNoSubscriberPayloadsAreByteIdentical(): void
    {
        $golden = json_decode((string) file_get_contents(self::GOLDEN), true);
        foreach (['none' => null, 'a subscriber that edits nothing' => 'noop'] as $label => $mode) {
            $cart = self::seed(true);
            $module = new TwopaymentTestHarness();
            self::harnessAsInstance($module);
            $calls = [];
            if ($mode !== null) {
                self::subscribe($mode, $calls);
            }
            foreach (self::payloadsForGolden($module, $cart) as $name => $json) {
                TinyAssert::same($golden[$name], $json, $label . ': ' . $name . ' payload must be byte-identical to the pre-hook builder');
            }
        }
    }

    /**
     * Whatever a subscriber returns is what goes out, however it leaves the
     * arithmetic: Two's API validates the payload, not the plugin. Each row is
     * an order create (returned to the checkout, which sends it) and an admin
     * edit (sent here).
     */
    private static function testHookEditsAreSentAsReturned(): void
    {
        // [mode, expected order totals [net, tax, gross], description]
        $cases = [
            ['resplit', ['123.97', '26.03', '150.00'], 'the README re-split with recomputed totals'],
            ['gross_change', ['133.97', '28.13', '162.10'], 'a changed gross'],
            ['off_by_cent', ['123.97', '26.03', '150.00'], 'a line whose net + tax no longer equals gross'],
            ['stale_totals', ['129.00', '21.00', '150.00'], 'lines re-split but the order totals left as they were'],
            ['stale_subtotals', ['123.97', '26.03', '150.00'], 'totals recomputed but tax_subtotals left stale'],
        ];
        foreach ($cases as [$mode, $totals, $description]) {
            $cart = self::seed(false, true);
            $module = self::module();
            self::harnessAsInstance($module);
            $calls = [];
            self::subscribe($mode, $calls);

            $payload = $module->getTwoNewOrderData('merchant-attempt-9701', $cart, self::merchantUrls());
            TinyAssert::same($calls[0]['payload_out'], $payload, $description . ': order create returns what the subscriber left');
            TinyAssert::same($totals, [$payload['net_amount'], $payload['tax_amount'], $payload['gross_amount']], $description . ': order totals as declared');

            $module->hookActionOrderEdited(['order' => self::order()]);
            TinyAssert::count(1, $module->sent, $description . ': the admin edit is sent');
            TinyAssert::same($calls[1]['payload_out'], $module->sent[0]['payload'], $description . ': the admin edit sends what the subscriber left');
            TinyAssert::same([], $module->warnings, $description . ': no warning');
        }
    }

    /**
     * A subscriber that throws, or leaves something that is not a
     * JSON-encodable array, has a code bug rather than a declaration: that
     * request fails with the one code, and nothing is sent.
     */
    private static function testSubscriberCodeBugsFailTheRequest(): void
    {
        // [mode, expected log detail, description]
        $cases = [
            ['throws', 'a subscriber threw RuntimeException at ', 'a subscriber that throws'],
            ['non_array', 'a subscriber left NULL where the payload array belongs', 'a subscriber that replaces the payload with a non-array'],
            ['not_json', 'a subscriber left a payload that cannot be JSON-encoded (Malformed UTF-8', 'a subscriber that leaves bytes JSON cannot encode'],
        ];
        foreach ($cases as [$mode, $detail, $description]) {
            $cart = self::seed(false, true);
            $module = self::module();
            self::harnessAsInstance($module);
            $calls = [];
            self::subscribe($mode, $calls);
            $error = null;
            try {
                $module->getTwoNewOrderData('merchant-attempt-9701', $cart, self::merchantUrls());
            } catch (Exception $e) {
                $error = $e;
            }
            TinyAssert::same(TwoOrderPostprocessing::CODE_HOOK_FAILED, $error instanceof TwoOrderPostprocessingException ? $error->getTwoCode() : ($error === null ? null : get_class($error)), $description . ': refused with the code');
            TinyAssert::false($error instanceof TwoCheckoutAmountException, $description . ': never relayed to the buyer');
            TinyAssert::true(self::logged(TwoOrderPostprocessing::CODE_HOOK_FAILED . ' - the order_create request (checkout) was not sent: ' . $detail), $description . ': the log line');
            TinyAssert::false(self::logged('Calle Uno'), $description . ': a subscriber\'s exception message is never logged');
        }
    }

    /**
     * The plugin's own checks run on what it builds, before the hook: a cart
     * they refuse is refused as it always was, the subscriber never runs, and
     * the discrepancy snapshot names the gate.
     */
    private static function testBuilderGatesRefuseBeforeTheHook(): void
    {
        // [setup, expected message fragment, snapshot gate, description]
        $cases = [
            ['shipping', 'Declared tax rate diverges from applied tax amounts for shipping', 'declared_rate', 'untaxed shipping on a 21% carrier group'],
            ['product', 'Declared tax rate diverges from applied tax amounts for product', 'declared_rate', 'a product whose declared rate its amounts contradict'],
        ];
        foreach ($cases as [$setup, $expected, $gate, $description]) {
            $cart = self::seed($setup !== 'shipping');
            if ($setup === 'product') {
                StubStore::$taxRuleRates[9000 + self::PRODUCT] = 10.0;
            }
            $module = self::module();
            self::harnessAsInstance($module);
            $calls = [];
            self::subscribe('resplit', $calls);
            $error = null;
            try {
                $module->getTwoNewOrderData('merchant-attempt-9701', $cart, self::merchantUrls());
            } catch (Exception $e) {
                $error = $e;
            }
            TinyAssert::true($error !== null && strpos($error->getMessage(), $expected) !== false, $description . ': today\'s refusal, got ' . ($error === null ? 'none' : '"' . $error->getMessage() . '"'));
            TinyAssert::count(0, $calls, $description . ': the subscriber never runs');
            $snapshots = array_values(array_filter(PrestaShopLogger::$logs, static function ($l) {
                return $l['object_type'] === TwoDiscrepancySnapshot::LOG_OBJECT_TYPE;
            }));
            TinyAssert::count(1, $snapshots, $description . ': one discrepancy snapshot');
            TinyAssert::same($gate, json_decode($snapshots[0]['message'], true)['gate']['name'] ?? null, $description . ': the snapshot names the gate');
        }
    }

    /**
     * A throwing subscriber refuses the order with the plugin's existing
     * generic message at payment submit; its exception message (buyer data)
     * reaches neither the buyer nor the log.
     */
    private static function testSubscriberThrowingGetsTheGenericCheckoutMessage(): void
    {
        $cart = self::seed(true);
        $module = self::module();
        self::harnessAsInstance($module);
        $calls = [];
        self::subscribe('throws', $calls);

        $result = $module->checkTwoOrderIntentApprovalAtPayment($cart, new Customer(self::CART), new Currency(978), new Address(self::ADDRESS));

        TinyAssert::false($result['approved'], 'refused');
        TinyAssert::same('payload_error', $result['status'], 'status');
        TinyAssert::same('Unable to process your order with Two payment.', $result['message'], 'the existing generic checkout message');
        TinyAssert::same([], $module->sent, 'nothing is sent to Two');
        TinyAssert::true(self::logged(TwoOrderPostprocessing::CODE_HOOK_FAILED), 'the merchant log names the code');
        TinyAssert::false(self::logged('Calle Uno'), 'the subscriber\'s exception message is never logged');
    }

    /**
     * Core's Hook::exec() discards a subscriber's Exception outside debug mode
     * on 8 and 9, so the module calls each subscriber itself. Everything else
     * about which subscribers run, and in what order, stays core's.
     */
    private static function testDispatchKeepsCoreSemanticsButNotItsSwallow(): void
    {
        // [second subscriber mode|null, setup, expected: null passes | code | message fragment, fires, description]
        $cases = [
            ['half_then_throw', null, TwoOrderPostprocessing::CODE_HOOK_FAILED, 2, 'a throw after a half edit refuses rather than send the half-edited payload'],
            ['record', null, null, 2, 'a second subscriber sees the first one\'s edits, in position order'],
            [null, 'non_native_off', null, 0, '"Disable non PrestaShop modules" keeps a subscriber from running, as core does'],
            [null, 'inactive', null, 0, 'a disabled subscriber module does not run'],
        ];
        foreach ($cases as [$second, $setup, $expected, $fires, $description]) {
            $cart = self::seed(false, true);
            $module = self::module();
            self::harnessAsInstance($module);
            $calls = [];
            self::subscribe('resplit', $calls);
            if ($second !== null) {
                self::subscribe($second, $calls, 'twoorderpostprocessingsecond');
            }
            if ($setup === 'non_native_off') {
                Configuration::updateValue('PS_DISABLE_NON_NATIVE_MODULE', 1);
            }
            if ($setup === 'inactive') {
                StubStore::$moduleInstances['twoorderpostprocessingtest'] = new StubHookSubscriberModule('twoorderpostprocessingtest');
                StubStore::$moduleInstances['twoorderpostprocessingtest']->active = false;
            }
            $error = null;
            try {
                $module->getTwoNewOrderData('merchant-attempt-9701', $cart, self::merchantUrls());
            } catch (Exception $e) {
                $error = $e;
            }
            TinyAssert::count($fires, $calls, $description . ': subscriber calls');
            if ($expected === null) {
                TinyAssert::same(null, $error === null ? null : $error->getMessage(), $description . ': accepted');
                if ($fires === 2) {
                    TinyAssert::same($calls[0]['payload_out'], $calls[1]['payload_in'], $description . ': the chain');
                }
                continue;
            }
            TinyAssert::same($expected, $error instanceof TwoOrderPostprocessingException ? $error->getTwoCode() : ($error === null ? null : get_class($error) . ': ' . $error->getMessage()), $description . ': code');
        }
    }

    /**
     * Core has saved an admin order edit before its hook runs, so a throwing
     * subscriber there must not reach core's order-edit AJAX as a 500.
     */
    private static function testAdminEditSurvivesAThrowingSubscriber(): void
    {
        self::seed(true);
        $module = self::module();
        self::harnessAsInstance($module);
        $calls = [];
        self::subscribe('throws', $calls);
        $thrown = null;
        try {
            $module->hookActionOrderEdited(['order' => self::order()]);
        } catch (Throwable $e) {
            $thrown = get_class($e) . ': ' . $e->getMessage();
        }
        TinyAssert::same(null, $thrown, 'nothing escapes into core\'s order-edit request');
        TinyAssert::same([], $module->sent, 'the update is not sent');
        TinyAssert::true(in_array('This order edit was saved in PrestaShop but was not sent to the invoice provider. Do not repeat the edit. Please contact support.', $module->warnings, true), 'the merchant is told not to repeat the edit');
        TinyAssert::true(self::logged('order edit saved but not sent to Two - ' . TwoOrderPostprocessing::CODE_HOOK_FAILED), 'the log names the code');
        TinyAssert::true(count($module->notes) === 1 && strpos($module->notes[0], 'not sent to the invoice provider: ' . TwoOrderPostprocessing::CODE_HOOK_FAILED . ': a subscriber threw RuntimeException') !== false, 'the order says why');
    }

    /**
     * The browser's order-intent relay sends what the server builds from the
     * session cart, through the hook, never a posted payload.
     * Only the buyer's company fields and address choice come from the
     * browser, and only once validated.
     */
    private static function testOrderIntentRelayBuildsThePayloadItself(): void
    {
        $tampered = json_encode([
            'gross_amount' => '1.00', 'net_amount' => '1.00', 'tax_amount' => '0.00', 'currency' => 'EUR',
            'buyer' => ['company' => ['company_name' => 'Someone Else AS', 'organization_number' => '999999999', 'country_prefix' => 'NO']],
            'line_items' => [['name' => 'x', 'gross_amount' => '1.00']],
        ]);
        // [posted fields, expected: 'sent' | error_code, sent organisation number, description]
        $cases = [
            [['payload' => $tampered], 'sent', 'B12345678', 'a posted payload is ignored: amounts, lines and company are the server\'s'],
            [['payload' => $tampered, 'company' => 'Posted Co SL', 'companyid' => 'B87654321'], 'sent', 'B87654321', 'the company comes from the buyer fields, not the payload'],
            [['company' => ['x'], 'companyid' => 'B1'], 'invalid_request', null, 'a company field that is not a string is refused'],
            [['company' => "Evil\nCo", 'companyid' => 'B1'], 'invalid_request', null, 'a control character is refused'],
            [['company' => 'Co', 'companyid' => str_repeat('9', 65)], 'invalid_request', null, 'an overlong organisation number is refused'],
            [['id_address_invoice' => '9712'], 'INVALID_REQUEST', null, 'another customer\'s address is refused'],
            [['id_address_invoice' => '12 OR 1=1'], 'invalid_request', null, 'an address id that is not a number is refused'],
        ];
        foreach ($cases as [$post, $expected, $orgNumber, $description]) {
            $cart = self::seed(true);
            StubStore::$addresses[9712] = ['id_customer' => 4242] + StubStore::$addresses[self::ADDRESS];
            $module = self::module();
            self::harnessAsInstance($module);
            $body = self::relay($module, $cart, $post);
            if ($expected !== 'sent') {
                TinyAssert::same($expected, $body['error_code'] ?? null, $description . ': refused');
                TinyAssert::same([], $module->sent, $description . ': nothing sent');
                continue;
            }
            TinyAssert::count(1, $module->sent, $description . ': one send');
            $built = $module->getTwoIntentOrderData($cart, new Customer(self::CART), new Currency(978), self::buyerAddress($post));
            TinyAssert::same(['/v1/order_intent', $built], [$module->sent[0]['endpoint'], $module->sent[0]['payload']], $description . ': the server-built payload');
            TinyAssert::same(['150.00', $orgNumber], [$module->sent[0]['payload']['gross_amount'], $module->sent[0]['payload']['buyer']['company']['organization_number']], $description . ': amount and company');
        }

        // The preview asks Two exactly what payment submit asks, hook included.
        foreach (['none' => null, 'the README re-split' => 'resplit'] as $label => $mode) {
            $cart = self::seed($mode === null, $mode !== null);
            $module = self::module();
            self::harnessAsInstance($module);
            $calls = [];
            if ($mode !== null) {
                self::subscribe($mode, $calls);
            }
            self::relay($module, $cart, []);
            $module->checkTwoOrderIntentApprovalAtPayment($cart, new Customer(self::CART), new Currency(978), new Address(self::ADDRESS));
            TinyAssert::count(2, $module->sent, $label . ': preview and payment both send');
            TinyAssert::same($module->sent[1]['payload'], $module->sent[0]['payload'], $label . ': the preview equals the payment-time intent');
        }
    }

    /**
     * @return array the relayed body (the CLI keeps no status once output has started)
     */
    private static function relay(TwopaymentTestHarness $module, Cart $cart, array $post): array
    {
        Tools::resetTestValues();
        Tools::setTestValue('token', 'token');
        foreach ($post as $key => $value) {
            Tools::setTestValue($key, $value);
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Context::getContext()->cart = $cart;
        $controller = new class extends TwopaymentOrderintentModuleFrontController {
            public array $emitted = [];

            public function sendJsonResponse($content)
            {
                $this->emitted[] = json_decode((string) $content, true);

                throw new StubOrderIntentResponded('responded');
            }
        };
        $controller->module = $module;
        try {
            $controller->ajaxProcessOrderIntent();
        } catch (StubOrderIntentResponded $e) {
        }

        return $controller->emitted[0] ?? [];
    }

    private static function buyerAddress(array $post): Address
    {
        $address = new Address(self::ADDRESS);
        if (isset($post['companyid'])) {
            $address->company = (string) $post['company'];
            $address->companyid = (string) $post['companyid'];
        }

        return $address;
    }

    /**
     * Every request type fires the hook exactly once, with the contract's
     * context, and sends exactly what the subscribers left.
     */
    /**
     * One driver per request type and trigger.
     *
     * @return array<int,array{0:string,1:string,2:int,3:callable,4:string}> [request_type, trigger, sends, driver, description]
     */
    private static function requestDrivers(): array
    {
        return [
            ['order_intent', 'precheck', 0, static function ($m, $c) {
                return $m->getTwoIntentOrderData($c, new Customer(self::CART), new Currency(978), new Address(self::ADDRESS));
            }, 'order intent pre-check (built for the checkout and its relay)'],
            ['order_intent', 'strict_intent', 1, static function ($m, $c) {
                return $m->checkTwoOrderIntentApprovalAtPayment($c, new Customer(self::CART), new Currency(978), new Address(self::ADDRESS));
            }, 'order intent at payment submit'],
            ['order_create', 'checkout', 0, static function ($m, $c) {
                return $m->getTwoNewOrderData('merchant-attempt-9701', $c, self::merchantUrls());
            }, 'order create build'],
            ['order_create', 'snapshot_hash', 0, static function ($m, $c) {
                return $m->getTwoNewOrderData('merchant-attempt-9701', $c, self::merchantUrls(), false, 'snapshot_hash');
            }, 'order create rebuilt for the confirmation hash'],
            ['order_update', 'admin_edit', 1, static function ($m) {
                $order = self::order();
                $m->hookActionOrderEdited(['order' => $order]);
            }, 'order update on an admin edit'],
            ['order_update', 'tracking_number', 1, static function ($m) {
                $order = self::order();
                $m->hookActionAdminOrdersTrackingNumberUpdate(['order' => $order]);
            }, 'order update on a tracking number'],
            ['order_confirm', 'payment_return', 1, static function ($m) {
                $order = self::order();
                $m->confirmTwoOrder(self::TWO_ORDER, 'payment_return', null, $order);
            }, 'order confirm'],
            ['capture', 'status_change', 1, static function ($m) {
                Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode([41]));
                $status = new OrderState(41);
                $status->name = 'Shipped';
                $m->hookActionOrderStatusUpdate(['id_order' => self::ORDER, 'newOrderStatus' => $status]);
            }, 'capture on the fulfilment status'],
            ['refund', 'status_change', 1, static function ($m) {
                $m->twoState = 'FULFILLED';
                Configuration::updateValue('PS_TWO_OS_REFUNDED_MAP', 42);
                $status = new OrderState(42);
                $status->name = 'Refunded';
                $m->hookActionOrderStatusUpdate(['id_order' => self::ORDER, 'newOrderStatus' => $status]);
            }, 'full refund on the refunded status'],
            ['refund', 'credit_slip', 1, static function ($m) {
                $order = self::order();
                $m->twoState = 'FULFILLED';
                $m->hookActionOrderSlipAdd(['order' => $order, 'order_slip' => (object) ['id' => 77, 'id_order' => self::ORDER, 'total_products_tax_incl' => 30.0, 'total_shipping_tax_incl' => 0.0]]);
            }, 'partial refund on a credit slip'],
            ['cancel', 'status_change', 1, static function ($m) {
                Configuration::updateValue('PS_TWO_OS_CANCELLED_MAP', 43);
                $status = new OrderState(43);
                $m->hookActionOrderStatusUpdate(['id_order' => self::ORDER, 'newOrderStatus' => $status]);
            }, 'cancel on the cancelled status'],
            ['cancel', 'attempt_persist_failed', 1, static function ($m) {
                $m->cancelTwoOrderBestEffort(self::TWO_ORDER, 'attempt_persist_failed');
            }, 'best-effort cancel'],
        ];
    }

    private static function testEachRequestTypeFiresExactlyOnce(): void
    {
        $cases = self::requestDrivers();
        $keys = ['request_type', 'trigger', 'endpoint', 'cart', 'order', 'shipping_tax_rate', 'fallback_shipping_tax_rate', 'contract_version'];
        foreach ($cases as [$type, $trigger, $sends, $driver, $description]) {
            $cart = self::seed(true);
            $module = self::module();
            self::harnessAsInstance($module);
            $calls = [];
            self::subscribe('tag', $calls);
            $driver($module, $cart);

            TinyAssert::count(1, $calls, $description . ': fires exactly once');
            $context = $calls[0]['context'];
            TinyAssert::same($keys, array_keys($context), $description . ': context keys');
            TinyAssert::same([$type, $trigger, 1], [$context['request_type'], $context['trigger'], $context['contract_version']], $description . ': request type, trigger, contract version');
            $sent = array_values(array_filter($module->sent, static function ($r) {
                return strpos($r['endpoint'], '/v1/order') === 0;
            }));
            TinyAssert::count($sends, $sent, $description . ': sends');
            if ($sends === 1) {
                TinyAssert::same($context['endpoint'], $sent[0]['endpoint'], $description . ': context endpoint is the one sent to');
                TinyAssert::same($calls[0]['payload_out'], $sent[0]['payload'], $description . ': sends exactly what the subscribers left');
            }
            if (in_array($type, ['order_intent', 'order_create', 'order_update'], true)) {
                TinyAssert::same(0.21, $context['shipping_tax_rate'], $description . ': the carrier group\'s 21% at the tax address');
                TinyAssert::true($context['cart'] instanceof Cart, $description . ': the cart');
            }
        }

        // A body a subscriber gives a body-less request goes out too: Two's API judges it.
        self::seed(true);
        $module = self::module();
        self::harnessAsInstance($module);
        $calls = [];
        self::subscribe('body_on_cancel', $calls);
        $module->sendTwoOrderRequest(TwoOrderPostprocessing::REQUEST_CANCEL, 'status_change', '/v1/order/x/cancel', [], 'POST');
        TinyAssert::same([['endpoint' => '/v1/order/x/cancel', 'payload' => ['reason' => 'added'], 'method' => 'POST']], $module->sent, 'a cancel given a body sends it as returned');

        // The default shipping tax code is the fallback rate; unset, it is null.
        $cart = self::seed(true);
        $module = new TwopaymentTestHarness();
        TinyAssert::same(null, $module->buildTwoOrderPostprocessingContext('order_create', 'spec', '/v1/order', $cart)['fallback_shipping_tax_rate'], 'no fallback rate unless one is configured');
        StubStore::$carriers[self::CARRIER]['tax_rules_group_id'] = 0;
        TinyAssert::same(0.0, $module->buildTwoOrderPostprocessingContext('order_create', 'spec', '/v1/order', new Cart(self::CART))['shipping_tax_rate'], 'a "No tax" carrier group is 0');
        StubStore::$carts[self::CART]['id_carrier'] = 0;
        TinyAssert::same(null, $module->buildTwoOrderPostprocessingContext('order_create', 'spec', '/v1/order', new Cart(self::CART))['shipping_tax_rate'], 'no carrier is null');
    }

    /**
     * With no subscriber changing the payload, every request type sends the
     * same bytes and gets the same accept or refuse outcome as with no
     * subscriber at all, on a cart the module's own checks pass and on one
     * they refuse. A subscriber that changes one field sends exactly that
     * change and nothing else.
     */
    private static function testNoChangeKeepsTodaysOutcomeOnEveryRequestType(): void
    {
        foreach (self::requestDrivers() as [$type, $trigger, , $driver, $description]) {
            foreach ([[true, 'a cart the checks pass'], [false, 'a cart the checks refuse']] as [$taxed, $cartLabel]) {
                $label = $description . ', ' . $cartLabel;
                $none = self::outcome(null, $taxed, $driver);
                TinyAssert::same($none, self::outcome('noop', $taxed, $driver), $label . ': a subscriber that changes nothing');
                if ($taxed) {
                    TinyAssert::same(null, $none[0], $label . ': accepted');
                    TinyAssert::same(self::outcome(null, $taxed, $driver, 'order_note'), self::outcome('tag', $taxed, $driver, 'order_note'), $label . ': a subscriber that only sets the order note changes nothing else');
                }
            }
        }
    }

    /**
     * @return array{0:?string,1:mixed,2:array} [refusal, what the driver returned, sends], clock fields pinned
     */
    private static function outcome(?string $mode, bool $shippingTaxed, callable $driver, ?string $drop = null): array
    {
        $cart = self::seed($shippingTaxed);
        $module = self::module();
        self::harnessAsInstance($module);
        $calls = [];
        if ($mode !== null) {
            self::subscribe($mode, $calls);
        }
        $error = null;
        $returned = null;
        try {
            $returned = $driver($module, $cart);
        } catch (Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
        }
        $pin = static function ($value) use (&$pin, $drop) {
            if (!is_array($value)) {
                return $value;
            }
            unset($value[$drop ?? '']);
            foreach ($value as $key => $item) {
                $value[$key] = in_array($key, ['merchant_reference', 'expected_delivery_date', 'timestamp'], true) ? 'PINNED' : $pin($item);
            }

            return $value;
        };

        return [$error, $pin($returned), $pin($module->sent)];
    }

    /**
     * recomputeTwoOrderTotals(): opt-in, and exactly the builder's own arithmetic.
     */
    private static function testRecomputeTotalsHelper(): void
    {
        $line = static function (string $net, string $tax, string $rate): array {
            return ['net_amount' => $net, 'tax_amount' => $tax, 'gross_amount' => number_format((float) $net + (float) $tax, 2, '.', ''), 'tax_rate' => $rate];
        };
        // [payload in, expected [net, tax, gross], expected tax_subtotals (null: key absent), description]
        $cases = [
            [['line_items' => [$line('100.00', '21.00', '0.21'), $line('23.97', '5.03', '0.21')], 'tax_subtotals' => []], ['123.97', '26.03', '150.00'], [['tax_rate' => '0.21', 'taxable_amount' => '123.97', 'tax_amount' => '26.03']], 'the worked example re-split: one 21% bucket'],
            [['line_items' => [$line('100.00', '21.00', '0.21'), $line('29.00', '0.00', '0')], 'tax_subtotals' => []], ['129.00', '21.00', '150.00'], [['tax_rate' => 0, 'taxable_amount' => '29.00', 'tax_amount' => '0.00'], ['tax_rate' => '0.21', 'taxable_amount' => '100.00', 'tax_amount' => '21.00']], 'before the re-split: a 0% and a 21% bucket, the 0% rate an int as the builder has always sent it'],
            [['line_items' => [$line('100.00', '21.00', '0.21')]], ['100.00', '21.00', '121.00'], null, 'tax_subtotals are not added to a payload that sends none'],
            [['amount' => '10.00', 'currency' => 'EUR'], [null, null, null], null, 'a payload with no lines is returned unchanged'],
        ];
        self::seed(true);
        $module = new TwopaymentTestHarness();
        foreach ($cases as [$in, $totals, $subtotals, $description]) {
            $out = $module->recomputeTwoOrderTotals($in);
            TinyAssert::same($totals, [$out['net_amount'] ?? null, $out['tax_amount'] ?? null, $out['gross_amount'] ?? null], $description . ': totals');
            TinyAssert::same($subtotals, $out['tax_subtotals'] ?? null, $description . ': tax_subtotals');
            TinyAssert::same($in['line_items'] ?? null, $out['line_items'] ?? null, $description . ': lines untouched');
        }

        // Identity on what the builder built: the helper and the builder cannot drift apart.
        $cart = self::seed(true);
        $module = new TwopaymentTestHarness();
        $built = $module->getTwoNewOrderData('merchant-attempt-9701', $cart, self::merchantUrls());
        TinyAssert::same($built, $module->recomputeTwoOrderTotals($built), 'recomputing an unedited payload changes nothing');
    }

    /**
     * Debug Mode logs what the hook changed, for support: amounts and lines
     * by value, anything else (buyer data) by path only. Outside Debug Mode,
     * and when nothing changed, it logs nothing.
     */
    private static function testDebugLogCarriesARedactedDiff(): void
    {
        // [mode, debug, expected in the log (null: no diff line), description]
        $cases = [
            ['resplit', 1, '{"path":"/line_items/1/tax_amount","before":"0.00","after":"5.03"}', 'a re-split is logged by value'],
            ['buyer_email', 1, '{"path":"/buyer/representative/email","before":"redacted","after":"redacted"}', 'a buyer field is logged by path only'],
            ['resplit', 0, null, 'nothing outside Debug Mode'],
            ['noop', 1, null, 'nothing when nothing changed'],
        ];
        foreach ($cases as [$mode, $debug, $expected, $description]) {
            $cart = self::seed(false, true);
            Configuration::updateValue('PS_TWO_DEBUG_MODE', $debug);
            $module = new TwopaymentTestHarness();
            self::harnessAsInstance($module);
            $calls = [];
            self::subscribe($mode, $calls);
            $module->getTwoNewOrderData('merchant-attempt-9701', $cart, self::merchantUrls());
            $line = 'The order postprocessing hook changed the order_create request (checkout). Diff: {"subscribers":["twoorderpostprocessingtest"],"changes":';
            TinyAssert::same($expected !== null, self::logged($line), $description . ': the diff line');
            if ($expected !== null) {
                TinyAssert::true(self::logged($expected), $description . ': ' . $expected);
            }
            TinyAssert::false(self::logged('someone@example.com'), $description . ': never a buyer value');
        }
    }

    /**
     * When Two refuses a payload, the API's own words reach the module log and
     * the order's private message, not the buyer-facing rewrite of them.
     */
    private static function testApiRejectionReachesTheLogAndTheOrder(): void
    {
        $answer = ['http_status' => 400, 'error_code' => 'SCHEMA_ERROR', 'error_message' => 'Validation error', 'error_details' => 'gross_amount: value_error, must equal net_amount + tax_amount'];
        $expected = 'HTTP 400 SCHEMA_ERROR | Validation error | gross_amount: value_error, must equal net_amount + tax_amount';
        // [driver, expected log fragment, description]
        $cases = [
            ['hookActionOrderEdited', 'order edit saved but not sent to Two - ' . $expected, 'an admin edit'],
            ['hookActionAdminOrdersTrackingNumberUpdate', 'tracking number update was not accepted by Two', 'a tracking number'],
        ];
        foreach ($cases as [$hook, $log, $description]) {
            self::seed(false, true);
            $module = self::module();
            $module->putAnswer = $answer;
            self::harnessAsInstance($module);
            $calls = [];
            self::subscribe('stale_totals', $calls);
            $module->$hook(['order' => self::order()]);
            TinyAssert::count(1, $module->sent, $description . ': sent');
            TinyAssert::true(self::logged($log) && self::logged($expected), $description . ': the API\'s words in the log');
            TinyAssert::same(['This change was saved in PrestaShop but was not sent to the invoice provider: ' . $expected], $module->notes, $description . ': the API\'s words on the order');
        }
    }

    private static function testHookRowIsCreatedOnceAndNeverSubscribed(): void
    {
        self::seed(true);
        $module = new TwopaymentTestHarness();
        TinyAssert::true($module->installTwoOrderPostprocessingHook(), 'install creates the row');
        TinyAssert::true($module->installTwoOrderPostprocessingHook(), 'and is idempotent');
        TinyAssert::same([TwoOrderPostprocessing::HOOK => 1], Hook::$ids, 'exactly one row');
        TinyAssert::false(in_array(TwoOrderPostprocessing::HOOK, StubStore::$registerHookCalls, true), 'the module never subscribes to its own hook');
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.18.php';
        Hook::$ids = [];
        TinyAssert::true(upgrade_module_2_7_18($module), 'the upgrade script creates it on existing shops');
        TinyAssert::same([TwoOrderPostprocessing::HOOK => 1], Hook::$ids, 'the upgrade row');
    }

    /**
     * The README re-split, the CI fixture and this spec run one implementation.
     */
    private static function testReadmeExampleIsTheFixturesOwnCode(): void
    {
        $method = new ReflectionMethod(Twoorderpostprocessingtest::class, 'resplitShipping');
        $lines = array_slice(file((string) $method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
        $source = implode('', array_map(static function (string $line): string {
            return preg_replace('/^ {4}/', '', $line);
        }, $lines));
        TinyAssert::true(strpos((string) file_get_contents(dirname(__DIR__) . '/README.md'), $source) !== false, 'README carries the fixture\'s resplitShipping() verbatim');
    }

    /**
     * CI allowlist: a new order send, or a builder that stops firing the hook,
     * goes red here before any real engine is involved.
     */
    private static function testEveryOrderSendGoesThroughTheChoke(): void
    {
        $root = dirname(__DIR__);
        $files = array_merge([$root . '/twopayment.php'], glob($root . '/controllers/front/*.php'), glob($root . '/classes/*.php'));
        // [file, function]: each sends a payload its builder already postprocessed.
        $allowedSenders = [
            'twopayment.php::checkTwoOrderIntentApprovalAtPayment' => 'buildTwoIntentOrderData(strict) postprocesses',
            'twopayment.php::putTwoOrderUpdate' => 'getTwoUpdateOrderData postprocesses',
            'payment.php::postProcess' => 'getTwoNewOrderData postprocesses',
            'orderintent.php::ajaxProcessOrderIntent' => 'buildBuyerOrderIntent() builds it through getTwoIntentOrderData, which postprocesses',
        ];
        $builders = ['buildTwoIntentOrderData', 'getTwoNewOrderData', 'getTwoUpdateOrderData', 'sendTwoOrderRequest'];
        $found = [];
        $productItemCallers = [];
        $postprocessingFunctions = [];
        foreach ($files as $file) {
            foreach (self::calls((string) file_get_contents($file)) as [$function, $callee, $args]) {
                $where = basename($file) . '::' . $function;
                if ($callee === 'setTwoPaymentRequest' && strpos($args, "'/v1/order") !== false && strpos($args, "'GET'") === false) {
                    $found[$where] = true;
                }
                if ($callee === 'getTwoProductItems') {
                    $productItemCallers[$where] = true;
                }
                if ($callee === 'postprocessOrderRequest') {
                    $postprocessingFunctions[$function] = true;
                }
            }
        }
        foreach (array_keys($found) as $where) {
            TinyAssert::true(isset($allowedSenders[$where]), $where . ' sends an order request outside the order postprocessing choke');
        }
        TinyAssert::same([], array_values(array_diff(array_keys($allowedSenders), array_keys($found))), 'an allowlisted sender no longer sends, prune it: ' . implode(', ', array_diff(array_keys($allowedSenders), array_keys($found))));
        foreach ($builders as $builder) {
            TinyAssert::true(isset($postprocessingFunctions[$builder]), $builder . ' must fire the hook through postprocessOrderRequest()');
        }
        TinyAssert::same(['twopayment.php::computeTwoOrderPricingData', 'twopayment.php::reconcileTwoSurchargeCartLine'], array_keys($productItemCallers), 'getTwoProductItems() is called only by the pricing builder and the surcharge fee basis');
    }

    /**
     * Method calls as [enclosing function, callee, argument source], by tokenizer.
     *
     * @return array<int,array{0:string,1:string,2:string}>
     */
    private static function calls(string $source): array
    {
        $tokens = token_get_all($source);
        $out = [];
        $function = '';
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }
            if ($tokens[$i][0] === T_FUNCTION) {
                for ($j = $i + 1; $j < $count && is_array($tokens[$j]) && $tokens[$j][0] !== T_STRING; $j++) {
                }
                if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $function = $tokens[$j][1];
                }
                continue;
            }
            if ($tokens[$i][0] !== T_STRING || $i < 1 || !is_array($tokens[$i - 1]) || $tokens[$i - 1][0] !== T_OBJECT_OPERATOR) {
                continue;
            }
            $j = $i + 1;
            if (($tokens[$j] ?? null) !== '(') {
                continue;
            }
            $depth = 0;
            $args = '';
            for (; $j < $count; $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                $depth += $text === '(' ? 1 : ($text === ')' ? -1 : 0);
                $args .= $text;
                if ($depth === 0) {
                    break;
                }
            }
            $out[] = [$function, $tokens[$i][1], $args];
        }

        return $out;
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
}
