<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/confirmation.php';

/**
 * The LEGACY confirmation path (attempt without a stored snapshot hash) must
 * run the SAME fail-closed surcharge parity gate as the hashed path before
 * validateOrder charges the buyer. Core PaymentModule::validateOrder performs
 * NO cart-vs-payload fee comparison itself, so a bare surcharge self-heal
 * adjacent to the charge (the previous behaviour) would let a genuine,
 * un-healable divergence create an order whose PrestaShop total differs from
 * the Two invoice.
 *
 * These specs drive the real controller (handleAttemptTokenConfirmation via
 * reflection - it is private) over the stub core, with the module's payload
 * builders REAL so the actual parity gate in buildTwoOrderPricingData is what
 * decides the outcome.
 */
final class ConfirmationLegacyParitySpec
{
    private const CART_ID = 8301;
    private const CUSTOMER_ID = 8001;
    private const ORDER_ID = 7777;
    private const PRODUCT_NET = 100.00;
    private const PRODUCT_GROSS = 105.50;

    public static function runAll(): void
    {
        self::testLegacyNoHashAttemptFailsClosedOnGenuineDivergence();
        self::testLegacyNoHashAttemptProceedsWhenParityHolds();
    }

    /* ---- fixtures ---- */

    private static function makeAttempt(): array
    {
        return [
            'attempt_token' => 'attempt-legacy-1',
            'id_cart' => self::CART_ID,
            'id_customer' => self::CUSTOMER_ID,
            'id_order' => 0,
            'two_order_id' => 'two-order-legacy-1',
            'two_order_reference' => 'ref-legacy-1',
            'two_order_state' => '',
            'two_order_status' => '',
            'two_day_on_invoice' => '30',
            'two_payment_term_type' => 'STANDARD',
            'two_invoice_url' => '',
            'two_invoice_id' => '',
            'cart_snapshot_hash' => '', // <- the legacy/degraded case under test
            'merchant_order_id' => 'merchant-legacy-1',
            'customer_secure_key' => 'secure-key-8001',
            'status' => 'CREATED',
        ];
    }

    /**
     * Module double: provider I/O and attempt persistence are canned, but
     * every payload/pricing/parity method is the REAL implementation.
     */
    private static function makeModule(): Twopayment
    {
        StubStore::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '5');
        Configuration::updateValue('PS_TWO_SURCHARGE_TAX_RATE', '25');
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS, '[30]');
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', '1');
        Configuration::updateValue('PS_TWO_OS_AWAITING_VERIFICATION', 12);
        Configuration::updateValue('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT', 13);

        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[33] = 'FR';
        StubStore::$addresses[8201] = [
            'id_country' => 33,
            'company' => 'Acme FR SAS',
            'companyid' => 'FR123456789',
            'address1' => '10 Rue de Paris',
            'city' => 'Paris',
            'postcode' => '75001',
            'phone' => '+33100000000',
            'loaded' => true,
        ];
        StubStore::$addresses[8202] = StubStore::$addresses[8201];
        StubStore::$customers[self::CUSTOMER_ID] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Eva',
            'lastname' => 'Martin',
            'secure_key' => 'secure-key-8001',
            'loaded' => true,
        ];
        StubStore::$carts[self::CART_ID] = [
            'id_customer' => self::CUSTOMER_ID,
            'id_currency' => 978,
            'id_address_invoice' => 8201,
            'id_address_delivery' => 8202,
            'id_carrier' => 0,
            'id_lang' => 1,
        ];
        StubStore::$cartProducts[self::CART_ID] = [[
            'id_product' => 9401,
            'link_rewrite' => 'plain-item',
            'name' => 'Plain item',
            'description_short' => '',
            'manufacturer_name' => '',
            'ean13' => '',
            'upc' => '',
            'cart_quantity' => 1,
            'price' => self::PRODUCT_NET,
            'total' => self::PRODUCT_NET,
            'total_wt' => self::PRODUCT_GROSS,
            'rate' => 5.5,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[9401] = [['name' => 'Books']];
        StubStore::$images[9401] = ['id_image' => 9401];
        StubStore::$cartTotals[self::CART_ID] = [
            true => [Cart::ONLY_DISCOUNTS => 0.0, Cart::BOTH => self::PRODUCT_GROSS],
            false => [Cart::ONLY_DISCOUNTS => 0.0, Cart::BOTH => self::PRODUCT_NET],
            'average_products_tax_rate' => 5.5,
        ];
        StubStore::$orders[self::ORDER_ID] = [
            'id_cart' => self::CART_ID,
            'id_customer' => self::CUSTOMER_ID,
            'total_paid' => 110.78,
        ];

        return new class(self::makeAttempt()) extends TwopaymentTestHarness {
            public array $attempt;
            /** @var array<int,array{status:string,extra:array}> */
            public array $statusUpdates = [];
            public int $createOrderCalls = 0;
            /** Simulates a self-heal that cannot reconcile (broken tax setup). */
            public bool $syncDisabled = false;

            public function syncTwoSurchargeCartLine($cart, $selected, $syncSeq = null)
            {
                if ($this->syncDisabled) {
                    return ['success' => false, 'changed' => false, 'present' => true];
                }
                return parent::syncTwoSurchargeCartLine($cart, $selected, $syncSeq);
            }

            public function __construct(array $attempt)
            {
                parent::__construct();
                $this->attempt = $attempt;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $endpoint = (string) $endpoint;
                if ($method === 'GET' && strpos($endpoint, '/v1/order/') === 0) {
                    // Provider order already CONFIRMED: the flow skips the
                    // confirm round-trip and heads straight for validateOrder.
                    return [
                        'http_status' => 200,
                        'id' => $this->attempt['two_order_id'],
                        'state' => 'CONFIRMED',
                        'status' => 'APPROVED',
                        'gross_amount' => '110.78',
                        'merchant_reference' => 'ref-legacy-1',
                    ];
                }
                if (strpos($endpoint, '/cancel') !== false || $method === 'PUT') {
                    return ['http_status' => 200];
                }
                // Fee quote for the surcharge line (5% of 105.50 = 5.28).
                return ['http_status' => 200, 'buyer_fee_share' => '5.28', 'currency' => 'EUR'];
            }

            public function getTwoCheckoutAttempt($attempt_token)
            {
                return $this->attempt;
            }

            public function updateTwoCheckoutAttemptStatus($attempt_token, $status, $extra_data = array())
            {
                $this->statusUpdates[] = ['status' => (string) $status, 'extra' => (array) $extra_data];
                $this->attempt['status'] = (string) $status;
                return true;
            }

            public function isTwoAttemptCallbackAuthorized($attempt, $provided_secure_key = '', $context_customer_id = 0, $context_customer_secure_key = '')
            {
                return true;
            }

            public function resolveTwoAttemptOrderIdForCancellation($attempt)
            {
                return 0;
            }

            public function getTwoOrderIdByCart($cart_id)
            {
                return 0;
            }

            public function getTwoOrderPaymentData($order_id)
            {
                return false;
            }

            public function createTwoLocalOrderAfterProviderVerification($cart, $customer, $initial_status, $provider_gross_amount)
            {
                $this->createOrderCalls++;
                return ['success' => true, 'id_order' => 7777];
            }

            public function setTwoOrderPaymentData($order_id, $payment_data)
            {
                return true;
            }

            public function getTwoUpdateOrderData($order, $payment_data = null)
            {
                return [];
            }

            public function setTwoCheckoutAttemptMerchantOrderId($attempt_token, $merchant_order_id)
            {
                return true;
            }

            public function changeOrderStatus($order_id, $status)
            {
                return true;
            }

            public function syncLocalOrderStatusFromTwoState($order_id, $state)
            {
                return true;
            }

            public function cancelTwoOrderBestEffort($two_order_id, $reason = '')
            {
                return true;
            }
        };
    }

    private static function makeController(Twopayment $module): TwopaymentConfirmationModuleFrontController
    {
        Context::getContext()->cookie->two_payment_term = 30;
        $controller = new TwopaymentConfirmationModuleFrontController();
        $controller->module = $module;
        return $controller;
    }

    private static function runConfirmation(TwopaymentConfirmationModuleFrontController $controller): StubRedirect
    {
        $handle = new ReflectionMethod($controller, 'handleAttemptTokenConfirmation');
        $handle->setAccessible(true);
        try {
            $handle->invoke($controller, 'attempt-legacy-1');
        } catch (StubRedirect $redirect) {
            return $redirect;
        }
        throw new RuntimeException('Confirmation flow ended without redirecting');
    }

    private static function lastStatus(Twopayment $module): string
    {
        $updates = $module->statusUpdates;
        return $updates === [] ? '' : (string) end($updates)['status'];
    }

    /* ---- specs ---- */

    private static function testLegacyNoHashAttemptFailsClosedOnGenuineDivergence(): void
    {
        $module = self::makeModule();

        // Seed the fee line, then corrupt it to a stale amount and disable
        // the self-heal (a sync that cannot reconcile, e.g. broken tax
        // setup) - the same genuine-divergence construction as the
        // order-create parity gate spec, now arriving via the LEGACY
        // confirmation path.
        $cart = new Cart(self::CART_ID);
        Context::getContext()->cart = $cart;
        $module->syncTwoSurchargeCartLine($cart, true);
        foreach (StubStore::$cartProducts[self::CART_ID] as $i => $row) {
            if ((int) $row['id_product'] === (int) Configuration::get('PS_TWO_SURCHARGE_PRODUCT_ID')) {
                $netDelta = 2.00 - (float) $row['total'];
                $grossDelta = 2.50 - (float) $row['total_wt'];
                StubStore::$cartProducts[self::CART_ID][$i]['total'] = 2.00;
                StubStore::$cartProducts[self::CART_ID][$i]['total_wt'] = 2.50;
                StubStore::$cartTotals[self::CART_ID][false][Cart::BOTH] = round(
                    (float) StubStore::$cartTotals[self::CART_ID][false][Cart::BOTH] + $netDelta,
                    2
                );
                StubStore::$cartTotals[self::CART_ID][true][Cart::BOTH] = round(
                    (float) StubStore::$cartTotals[self::CART_ID][true][Cart::BOTH] + $grossDelta,
                    2
                );
            }
        }
        $module->syncDisabled = true;

        $controller = self::makeController($module);
        $redirect = self::runConfirmation($controller);

        TinyAssert::same(0, $module->createOrderCalls, 'no order may be created past a failed parity gate');
        TinyAssert::same('FAILED', self::lastStatus($module), 'attempt must be marked FAILED');
        TinyAssert::true(strpos($redirect->getMessage(), 'controller=order') !== false, 'buyer returned to checkout');
    }

    private static function testLegacyNoHashAttemptProceedsWhenParityHolds(): void
    {
        $module = self::makeModule();

        // Healthy cart: the buyer selected Two, the fee line synced normally.
        $cart = new Cart(self::CART_ID);
        Context::getContext()->cart = $cart;
        $module->syncTwoSurchargeCartLine($cart, true);

        $controller = self::makeController($module);
        $redirect = self::runConfirmation($controller);

        TinyAssert::same(1, $module->createOrderCalls, 'order creation must proceed when parity holds');
        TinyAssert::same('CONFIRMED', self::lastStatus($module), 'attempt confirmed');
        TinyAssert::true(strpos($redirect->getMessage(), 'order-confirmation') !== false, 'buyer lands on order confirmation');
    }
}
