<?php

declare(strict_types=1);

/**
 * TWO-26092: the order postprocessing hook (actionTwoOrderPostprocessing).
 *
 * The fixture cart is the one the contract exists for: a 21% product at
 * 100.00 net and 29.00 of shipping the shop charged no tax on, on a carrier
 * whose tax rules group declares 21%. The worked example re-splits that
 * shipping to 23.97 net + 5.03 tax.
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
        self::testGatesOnTheWorkedExampleCart();
        self::testSubscriberThrowingGetsTheGenericCheckoutMessage();
        self::testDispatchKeepsCoreSemanticsButNotItsSwallow();
        self::testAdminEditSurvivesAThrowingSubscriber();
        self::testEachRequestTypeFiresExactlyOnce();
        self::testRecomputeTotalsHelper();
        self::testSnapshotRecordsTheHook();
        self::testHookRowIsCreatedOnceAndNeverSubscribed();
        self::testEveryOrderSendGoesThroughTheChoke();
    }

    /* ---- fixtures ---- */

    /**
     * @param bool $shippingTaxed true: the shop taxed shipping at 21% (29.00 incl / 23.97 excl)
     */
    public static function seed(bool $shippingTaxed): Cart
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
        StubStore::$carriers[self::CARRIER] = ['name' => 'Courier', 'delay' => '', 'tax_rules_group_id' => self::CARRIER_GROUP];
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

    private static function order(): object
    {
        return new class (self::ORDER, self::CART, self::CARRIER) {
            public bool $loaded = true;
            public $module = 'twopayment';
            public int $id;
            public int $id_cart;
            public int $id_carrier;
            public int $id_lang = 1;
            public string $shipping_number = '';

            public function __construct(int $id, int $idCart, int $idCarrier)
            {
                $this->id = $id;
                $this->id_cart = $idCart;
                $this->id_carrier = $idCarrier;
            }

            public function getIdOrderCarrier(): int
            {
                return 0;
            }

            public function getOrderPaymentCollection(): array
            {
                return [];
            }
        };
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

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                if ($method !== 'GET') {
                    $this->sent[] = ['endpoint' => $endpoint, 'payload' => $payload, 'method' => $method];
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
        $module = Module::getInstanceByName('twopayment');
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

        // Every remaining mode starts from the README's shipping re-split.
        $rate = $params['context']['shipping_tax_rate'];
        foreach ($params['payload']['line_items'] as &$line) {
            if ($line['type'] !== 'SHIPPING_FEE' || (float) $line['tax_amount'] != 0.0) {
                continue;
            }
            $gross = (float) $line['gross_amount'];
            $net = round($gross / (1 + $rate), 2);
            $line['net_amount'] = number_format($net, 2, '.', '');
            $line['tax_amount'] = number_format($gross - $net, 2, '.', '');
            $line['unit_price'] = $line['net_amount'];
            $line['tax_rate'] = (string) $rate;
            $line['tax_class_name'] = 'VAT ' . number_format($rate * 100, 2) . '%';
            if ($mode === 'off_by_cent') {
                $line['net_amount'] = number_format($net - 0.01, 2, '.', '');
                $line['unit_price'] = $line['net_amount'];
            }
        }
        unset($line);
        if ($mode === 'stale_totals') {
            return;
        }
        if ($mode === 'gross_change') {
            $params['payload']['line_items'][] = [
                'name' => 'Handling', 'description' => '', 'gross_amount' => '12.10', 'net_amount' => '10.00',
                'discount_amount' => '0.00', 'tax_amount' => '2.10', 'tax_class_name' => 'VAT 21.00%',
                'tax_rate' => '0.21', 'unit_price' => '10.00', 'quantity' => 1, 'quantity_unit' => 'item',
                'image_url' => '', 'product_page_url' => '', 'type' => 'SERVICE',
            ];
        }
        $stale = $params['payload']['tax_subtotals'];
        $params['payload'] = $module->recomputeTwoOrderTotals($params['payload']);
        if ($mode === 'stale_subtotals') {
            $params['payload']['tax_subtotals'] = $stale;
        }
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
     * The worked example cart, one subscriber behaviour per row, on order create.
     */
    private static function testGatesOnTheWorkedExampleCart(): void
    {
        // [mode, expected: null passes | TWO_ORDER_POSTPROCESSING_* code | exception message fragment, description]
        $cases = [
            ['none', 'Declared tax rate diverges from applied tax amounts for shipping', 'no subscriber: the declared 21% against untaxed shipping refuses as it always has'],
            ['noop', 'Declared tax rate diverges from applied tax amounts for shipping', 'a subscriber that edits nothing: still today\'s refusal'],
            ['resplit', null, 'the README re-split with recomputed totals is accepted'],
            ['gross_change', null, 'a subscriber may change gross; the plugin accepts what it declares'],
            ['off_by_cent', TwoOrderPostprocessing::CODE_LINE_INCONSISTENT, 'a line whose net + tax no longer equals gross'],
            ['stale_totals', TwoOrderPostprocessing::CODE_TOTALS_INCONSISTENT, 'lines re-split but the order totals left as they were'],
            ['stale_subtotals', TwoOrderPostprocessing::CODE_SUBTOTALS_INCONSISTENT, 'totals recomputed but tax_subtotals left stale'],
            ['throws', TwoOrderPostprocessing::CODE_HOOK_FAILED, 'a subscriber that throws'],
            ['non_array', TwoOrderPostprocessing::CODE_HOOK_FAILED, 'a subscriber that replaces the payload with a non-array'],
        ];
        foreach ($cases as [$mode, $expected, $description]) {
            $cart = self::seed(false);
            $module = new TwopaymentTestHarness();
            self::harnessAsInstance($module);
            $calls = [];
            if ($mode !== 'none') {
                self::subscribe($mode, $calls);
            }
            $payload = null;
            $error = null;
            try {
                $payload = $module->getTwoNewOrderData('merchant-attempt-9701', $cart, self::merchantUrls());
            } catch (Exception $e) {
                $error = $e;
            }

            if ($expected === null) {
                TinyAssert::same(null, $error === null ? null : $error->getMessage(), $description . ': accepted');
                $shipping = array_values(array_filter($payload['line_items'], static function ($l) {
                    return $l['type'] === 'SHIPPING_FEE';
                }))[0];
                TinyAssert::same(['23.97', '5.03', '29.00'], [$shipping['net_amount'], $shipping['tax_amount'], $shipping['gross_amount']], $description . ': shipping re-split');
                $expectTotals = $mode === 'gross_change' ? ['133.97', '28.13', '162.10'] : ['123.97', '26.03', '150.00'];
                TinyAssert::same($expectTotals, [$payload['net_amount'], $payload['tax_amount'], $payload['gross_amount']], $description . ': order totals');
                TinyAssert::same([['tax_rate' => '0.21', 'taxable_amount' => $expectTotals[0], 'tax_amount' => $expectTotals[1]]], $payload['tax_subtotals'], $description . ': one 21% bucket');
                TinyAssert::true(self::logged('so the comparison against the cart totals was skipped'), $description . ': the cart comparison skip is logged');
                continue;
            }
            TinyAssert::true($error !== null, $description . ': refused');
            if (strpos($expected, 'TWO_ORDER_POSTPROCESSING_') === 0) {
                TinyAssert::true($error instanceof TwoOrderPostprocessingException, $description . ': a named refusal, got ' . get_class($error));
                TinyAssert::same($expected, $error->getTwoCode(), $description . ': code');
                TinyAssert::false($error instanceof TwoCheckoutAmountException, $description . ': never relayed to the buyer');
                TinyAssert::true(self::logged($expected . ' - refused the order_create request (checkout)'), $description . ': the merchant log names the code');
            } else {
                TinyAssert::false($error instanceof TwoOrderPostprocessingException, $description . ': today\'s exception, not a named one');
                TinyAssert::true(strpos($error->getMessage(), $expected) !== false, $description . ': got "' . $error->getMessage() . '"');
            }
        }

        // The diff of a totals refusal points at exactly what the subscriber changed.
        self::seed(false);
        $module = new TwopaymentTestHarness();
        self::harnessAsInstance($module);
        $calls = [];
        self::subscribe('stale_totals', $calls);
        try {
            $module->getTwoNewOrderData('merchant-attempt-9701', new Cart(self::CART), self::merchantUrls());
        } catch (TwoOrderPostprocessingException $e) {
        }
        foreach (['/line_items/1/net_amount', '/line_items/1/tax_amount'] as $path) {
            TinyAssert::true(self::logged('"path":"' . $path . '"'), 'the refusal log diff names ' . $path);
        }
        TinyAssert::false(self::logged('"path":"/net_amount"'), 'the order totals the subscriber forgot are not in the diff');
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
            [null, 'non_native_off', 'Declared tax rate diverges', 0, '"Disable non PrestaShop modules" keeps a subscriber from running, as core does'],
            [null, 'inactive', 'Declared tax rate diverges', 0, 'a disabled subscriber module does not run'],
        ];
        foreach ($cases as [$second, $setup, $expected, $fires, $description]) {
            $cart = self::seed(false);
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
                TinyAssert::same($calls[0]['payload_out'], $calls[1]['payload_in'], $description . ': the chain');
                continue;
            }
            TinyAssert::true($error !== null, $description . ': refused');
            if ($expected === TwoOrderPostprocessing::CODE_HOOK_FAILED) {
                TinyAssert::same($expected, $error instanceof TwoOrderPostprocessingException ? $error->getTwoCode() : get_class($error) . ': ' . $error->getMessage(), $description . ': code');
            } else {
                TinyAssert::true(strpos($error->getMessage(), $expected) !== false, $description . ': today\'s refusal, got "' . $error->getMessage() . '"');
            }
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
    }

    /**
     * Every request type fires the hook exactly once, with the contract's
     * context, and sends exactly what the subscribers left.
     */
    private static function testEachRequestTypeFiresExactlyOnce(): void
    {
        $order = self::order();
        // [request_type, trigger, sends, driver, description]
        $cases = [
            ['order_intent', 'precheck', 0, static function ($m, $c) {
                $m->getTwoIntentOrderData($c, new Customer(self::CART), new Currency(978), new Address(self::ADDRESS));
            }, 'order intent pre-check (payload handed to the browser)'],
            ['order_intent', 'strict_intent', 1, static function ($m, $c) {
                $m->checkTwoOrderIntentApprovalAtPayment($c, new Customer(self::CART), new Currency(978), new Address(self::ADDRESS));
            }, 'order intent at payment submit'],
            ['order_create', 'checkout', 0, static function ($m, $c) {
                $m->getTwoNewOrderData('merchant-attempt-9701', $c, self::merchantUrls());
            }, 'order create build'],
            ['order_create', 'snapshot_hash', 0, static function ($m, $c) {
                $m->getTwoNewOrderData('merchant-attempt-9701', $c, self::merchantUrls(), false, 'snapshot_hash');
            }, 'order create rebuilt for the confirmation hash'],
            ['order_update', 'admin_edit', 1, static function ($m) use ($order) {
                $m->hookActionOrderEdited(['order' => $order]);
            }, 'order update on an admin edit'],
            ['order_update', 'tracking_number', 1, static function ($m) use ($order) {
                $m->hookActionAdminOrdersTrackingNumberUpdate(['order' => $order]);
            }, 'order update on a tracking number'],
            ['order_confirm', 'payment_return', 1, static function ($m) use ($order) {
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
            ['refund', 'credit_slip', 1, static function ($m) use ($order) {
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

        // A subscriber that gives a body-less request a body stops it before the wire.
        self::seed(true);
        $module = self::module();
        self::harnessAsInstance($module);
        $calls = [];
        self::subscribe('body_on_cancel', $calls);
        $response = $module->sendTwoOrderRequest(TwoOrderPostprocessing::REQUEST_CANCEL, 'status_change', '/v1/order/x/cancel', [], 'POST');
        TinyAssert::same([], $module->sent, 'a cancel given a body is not sent');
        TinyAssert::same([0, TwoOrderPostprocessing::CODE_BODY_NOT_ACCEPTED], [$response['http_status'], $response['error_code']], 'the caller sees an unsent request carrying the code');

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
     * The discrepancy snapshot carries the order_postprocessing block: in the
     * Debug Mode baseline of a changed order that passed, and in a refusal.
     */
    private static function testSnapshotRecordsTheHook(): void
    {
        // [mode, debug, expected snapshot rows, outcome, cart_reconciliation, gate, description]
        $cases = [
            ['resplit', 1, 1, 'changed', 'skipped_payload_changed', null, 'a changed order that passed leaves a baseline with the block'],
            ['stale_totals', 0, 1, 'refused:' . TwoOrderPostprocessing::CODE_TOTALS_INCONSISTENT, null, 'order_postprocessing', 'a named refusal leaves one snapshot named after the hook'],
            ['resplit', 0, 0, null, null, null, 'a changed order that passed writes nothing outside debug mode'],
        ];
        foreach ($cases as [$mode, $debug, $rows, $outcome, $reconciliation, $gate, $description]) {
            $cart = self::seed(false);
            Configuration::updateValue('PS_TWO_DEBUG_MODE', $debug);
            $module = new TwopaymentTestHarness();
            self::harnessAsInstance($module);
            $calls = [];
            self::subscribe($mode, $calls);
            try {
                $module->getTwoNewOrderData('merchant-attempt-9701', $cart, self::merchantUrls());
            } catch (Exception $e) {
            }
            $snapshots = array_values(array_filter(PrestaShopLogger::$logs, static function ($l) {
                return $l['object_type'] === TwoDiscrepancySnapshot::LOG_OBJECT_TYPE;
            }));
            TinyAssert::count($rows, $snapshots, $description . ': snapshot rows');
            if ($rows === 0) {
                continue;
            }
            $snapshot = json_decode($snapshots[0]['message'], true);
            $block = $snapshot['order_postprocessing'];
            TinyAssert::same(2, $snapshot['v'], $description . ': schema version');
            TinyAssert::same([true, 'order_create', 'checkout', ['twoorderpostprocessingtest'], true, $outcome], [$block['fired'], $block['request_type'], $block['trigger'], $block['subscribers'], $block['changed'], $outcome !== null ? $block['outcome'] : null], $description . ': block');
            TinyAssert::same($reconciliation, $block['cart_reconciliation'], $description . ': cart_reconciliation');
            TinyAssert::same($gate, $snapshot['gate']['name'] ?? null, $description . ': gate');
            TinyAssert::true(in_array(['path' => '/line_items/1/tax_amount', 'before' => '0.00', 'after' => '5.03'], $block['diff'], true), $description . ': the diff names the re-split shipping tax');
            $shipping = $snapshot['sent_line_items'][1];
            TinyAssert::same('5.03', $shipping['tax_amount'], $description . ': sent_line_items are post-hook');
        }

        // Buyer data a subscriber edits is withheld from the diff; its path is kept.
        self::seed(true);
        $module = new TwopaymentTestHarness();
        self::harnessAsInstance($module);
        $calls = [];
        self::subscribe('buyer_email', $calls);
        $module->getTwoNewOrderData('merchant-attempt-9701', new Cart(self::CART), self::merchantUrls());
        TinyAssert::true(self::logged('{"path":"/buyer/representative/email","before":"redacted","after":"redacted"}'), 'a buyer field is logged by path only');
        TinyAssert::false(self::logged('someone@example.com'), 'never by value');
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
            'twopayment.php::hookActionOrderEdited' => 'getTwoUpdateOrderData postprocesses',
            'twopayment.php::hookActionAdminOrdersTrackingNumberUpdate' => 'getTwoUpdateOrderData postprocesses',
            'payment.php::postProcess' => 'getTwoNewOrderData postprocesses',
            'confirmation.php::syncTwoMerchantOrderId' => 'getTwoUpdateOrderData postprocesses',
            'orderintent.php::ajaxProcessOrderIntent' => 'relays the post-hook payload ajaxProcessCheckOrderIntent built',
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
