<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * TWO-24762 — tracking number sourcing and the admin tracking-update hook.
 */
final class TrackingNumberTest extends TestCase
{
    protected function setUp(): void
    {
        StubStore::reset();
    }

    /**
     * Order-shaped stub: getTwoOrderTrackingNumber and the hook handler
     * only touch these members, and Twopayment does not type-hint Order.
     */
    private function makeOrder(int $idOrderCarrier, string $shippingNumber = '', string $module = 'twopayment'): object
    {
        return new class ($idOrderCarrier, $shippingNumber, $module) {
            public bool $loaded = true;
            public int $id = 4201;
            public int $id_cart = 0;
            public int $id_carrier = 0;
            public $module;
            public $shipping_number;
            private int $idOrderCarrier;

            public function __construct(int $idOrderCarrier, string $shippingNumber, string $module)
            {
                $this->idOrderCarrier = $idOrderCarrier;
                $this->shipping_number = $shippingNumber;
                $this->module = $module;
            }

            public function getIdOrderCarrier(): int
            {
                return $this->idOrderCarrier;
            }
        };
    }

    public function testTrackingNumberComesFromOrderCarrier(): void
    {
        $module = new TwopaymentTestHarness();
        StubStore::$orderCarriers[77] = ['tracking_number' => 'PN123456789SE'];

        self::assertSame('PN123456789SE', $module->getTwoOrderTrackingNumber($this->makeOrder(77)));
    }

    public function testTrackingNumberFallsBackToLegacyShippingNumber(): void
    {
        $module = new TwopaymentTestHarness();

        // No order_carrier row loaded: legacy Order::$shipping_number mirror wins.
        self::assertSame('LEGACY-1', $module->getTwoOrderTrackingNumber($this->makeOrder(0, 'LEGACY-1')));

        // Legacy fallback is trimmed too.
        self::assertSame('LEGACY-2', $module->getTwoOrderTrackingNumber($this->makeOrder(0, '  LEGACY-2  ')));

        // id_order_carrier set but the row no longer loads (deleted row):
        // fall back to the legacy mirror.
        self::assertSame('LEGACY-3', $module->getTwoOrderTrackingNumber($this->makeOrder(99, 'LEGACY-3')));
    }

    public function testTrackingNumberEmptyWhenNoneSet(): void
    {
        $module = new TwopaymentTestHarness();
        StubStore::$orderCarriers[78] = ['tracking_number' => ''];

        self::assertSame('', $module->getTwoOrderTrackingNumber($this->makeOrder(78)));
        self::assertSame('', $module->getTwoOrderTrackingNumber($this->makeOrder(0)));
    }

    public function testTrackingNumberCarrierRecordWinsOverLegacyMirror(): void
    {
        $module = new TwopaymentTestHarness();

        // order_carrier is canonical: it wins when both are set, and a
        // loaded-but-empty row must NOT fall through to the stale legacy
        // mirror (that is how a cleared tracking number gets cleared at
        // Two instead of resurrecting an old value).
        StubStore::$orderCarriers[79] = ['tracking_number' => 'FRESH-1'];
        self::assertSame('FRESH-1', $module->getTwoOrderTrackingNumber($this->makeOrder(79, 'STALE-1')));

        StubStore::$orderCarriers[80] = ['tracking_number' => ''];
        self::assertSame('', $module->getTwoOrderTrackingNumber($this->makeOrder(80, 'STALE-1')));

        // '0' is a value, not an absence; whitespace is trimmed away.
        StubStore::$orderCarriers[81] = ['tracking_number' => '0'];
        self::assertSame('0', $module->getTwoOrderTrackingNumber($this->makeOrder(81, 'STALE-1')));

        StubStore::$orderCarriers[82] = ['tracking_number' => '  PN1  '];
        self::assertSame('PN1', $module->getTwoOrderTrackingNumber($this->makeOrder(82)));
    }

    public function testTrackingHookPutsOrderUpdateForTwoOrders(): void
    {
        $module = new class () extends TwopaymentTestHarness {
            public array $requests = [];

            public function getTwoOrderPaymentData($id_order)
            {
                return ['two_order_id' => 'two-order-uuid', 'two_order_reference' => 'ref-1'];
            }

            public function getTwoUpdateOrderData($order, $orderpaymentdata)
            {
                return ['marker' => 'update-body'];
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                $this->requests[] = [$endpoint, $payload, $method];
                return ['http_status' => 200, 'data' => []];
            }
        };

        $module->hookActionAdminOrdersTrackingNumberUpdate(['order' => $this->makeOrder(77)]);

        self::assertCount(1, $module->requests);
        self::assertSame('/v1/order/two-order-uuid', $module->requests[0][0]);
        self::assertSame(['marker' => 'update-body'], $module->requests[0][1]);
        self::assertSame('PUT', $module->requests[0][2]);
    }

    public function testTrackingHookIgnoresForeignAndMissingOrders(): void
    {
        // Gated orders must be rejected BEFORE any Two lookup: asserting
        // on the lookup (not just the request) keeps this test meaningful
        // even though the handler's try/catch would swallow downstream
        // failures of a deleted gate.
        $module = new class () extends TwopaymentTestHarness {
            public array $lookups = [];
            public array $requests = [];

            public function getTwoOrderPaymentData($id_order)
            {
                $this->lookups[] = $id_order;
                return ['two_order_id' => 'two-order-uuid'];
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                $this->requests[] = $endpoint;
                return null;
            }
        };

        $module->hookActionAdminOrdersTrackingNumberUpdate(['order' => $this->makeOrder(77, '', 'ps_checkpayment')]);
        $module->hookActionAdminOrdersTrackingNumberUpdate([]);

        $unloaded = $this->makeOrder(77);
        $unloaded->loaded = false;
        $module->hookActionAdminOrdersTrackingNumberUpdate(['order' => $unloaded]);

        self::assertSame([], $module->lookups);
        self::assertSame([], $module->requests);
    }

    public function testTrackingHookSurvivesOrderDataBuildFailure(): void
    {
        // Legacy orders can have purged/emptied carts; the push is
        // best-effort and must never break the admin action that saved
        // the tracking number.
        $module = new class () extends TwopaymentTestHarness {
            public array $requests = [];

            public function getTwoOrderPaymentData($id_order)
            {
                return ['two_order_id' => 'two-order-uuid'];
            }

            public function getTwoUpdateOrderData($order, $orderpaymentdata)
            {
                throw new Exception('Cart is empty or invalid');
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                $this->requests[] = $endpoint;
                return null;
            }
        };

        $module->hookActionAdminOrdersTrackingNumberUpdate(['order' => $this->makeOrder(77)]);

        self::assertSame([], $module->requests);
    }

    public function testTrackingHookWarnsAdminWhenTwoRejectsTheEdit(): void
    {
        $module = new class () extends TwopaymentTestHarness {
            public array $warnings = [];

            public function getTwoOrderPaymentData($id_order)
            {
                return ['two_order_id' => 'two-order-uuid'];
            }

            public function getTwoUpdateOrderData($order, $orderpaymentdata)
            {
                return ['gross_amount' => '105.50'];
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                // The shape a post-fulfilment rejection comes back as.
                return ['http_status' => 400, 'data' => ['error_message' => 'Order cannot be edited']];
            }

            public function addTwoBackOfficeWarning($message)
            {
                $this->warnings[] = $message;
                return true;
            }
        };

        $module->hookActionAdminOrdersTrackingNumberUpdate(['order' => $this->makeOrder(77)]);

        self::assertCount(1, $module->warnings);

        // And a 2xx acceptance stays quiet.
        $accepted = new class () extends TwopaymentTestHarness {
            public array $warnings = [];

            public function getTwoOrderPaymentData($id_order)
            {
                return ['two_order_id' => 'two-order-uuid'];
            }

            public function getTwoUpdateOrderData($order, $orderpaymentdata)
            {
                return ['gross_amount' => '105.50'];
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                return ['http_status' => 200, 'data' => []];
            }

            public function addTwoBackOfficeWarning($message)
            {
                $this->warnings[] = $message;
                return true;
            }
        };

        $accepted->hookActionAdminOrdersTrackingNumberUpdate(['order' => $this->makeOrder(77)]);

        self::assertSame([], $accepted->warnings);
    }

    public function testUpdateOrderDataCarriesTrackingAndOrderCarrier(): void
    {
        // End-to-end wiring: the payload builder must source the tracking
        // number from order_carrier and the carrier name from the ORDER's
        // carrier, not the stale cart carrier.
        $module = new TwopaymentTestHarness();

        StubStore::$customers[7301] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Nora',
            'lastname' => 'Berg',
            'secure_key' => 'secure-key-7301',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[47] = 'NO';
        StubStore::$addresses[7311] = [
            'id_country' => 47,
            'company' => 'Fjord AS',
            'companyid' => '912345678',
            'address1' => 'Testgata 1',
            'city' => 'Oslo',
            'postcode' => '0150',
            'phone' => '+4740000000',
            'loaded' => true,
        ];
        StubStore::$addresses[7312] = StubStore::$addresses[7311];
        StubStore::$carts[7300] = [
            'id_customer' => 7301,
            'id_currency' => 978,
            'id_address_invoice' => 7311,
            'id_address_delivery' => 7312,
            'id_carrier' => 601, // stale cart carrier — must NOT win
            'id_lang' => 1,
        ];
        StubStore::$cartProducts[7300] = [[
            'id_product' => 9401,
            'link_rewrite' => 'tracked-item',
            'name' => 'Tracked item',
            'description_short' => 'Tracked item',
            'manufacturer_name' => 'ACME',
            'ean13' => '',
            'upc' => '',
            'total' => 100.00,
            'total_wt' => 125.00,
            'cart_quantity' => 1,
            'rate' => 25.0,
            'price' => 100.00,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[9401] = [['name' => 'Gear']];
        StubStore::$images[9401] = ['id_image' => 9401];
        StubStore::$cartTotals[7300] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.0,
                Cart::BOTH => 125.00,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.0,
                Cart::BOTH => 100.00,
            ],
            'average_products_tax_rate' => 25.0,
        ];
        StubStore::$carriers[601] = ['name' => 'Stale Cart Carrier', 'max_delivery_days' => 3];
        StubStore::$carriers[602] = ['name' => 'Order Carrier Express', 'max_delivery_days' => 5];
        StubStore::$orderCarriers[91] = ['tracking_number' => 'NO123456789'];

        $order = $this->makeOrder(91);
        $order->id_cart = 7300;
        $order->id_carrier = 602;

        $payload = $module->getTwoUpdateOrderData($order, [
            'two_order_id' => 'two-order-uuid',
            'two_order_reference' => 'ref-7300',
        ]);

        self::assertSame('NO123456789', $payload['shipping_details']['tracking_number']);
        self::assertSame('Order Carrier Express', $payload['shipping_details']['carrier_name']);
        self::assertSame('125.00', $payload['gross_amount']);
    }

    public function testTrackingHookSilentWhenTwoOrderIdIsEmpty(): void
    {
        // A payment row with an empty two_order_id has no Two order to
        // edit: stay silent instead of PUTting to /v1/order/ and warning
        // the admin about an order Two never knew about.
        $module = new class () extends TwopaymentTestHarness {
            public array $requests = [];
            public array $warnings = [];

            public function getTwoOrderPaymentData($id_order)
            {
                return ['two_order_id' => ''];
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                $this->requests[] = $endpoint;
                return null;
            }

            public function addTwoBackOfficeWarning($message)
            {
                $this->warnings[] = $message;
                return true;
            }
        };

        $module->hookActionAdminOrdersTrackingNumberUpdate(['order' => $this->makeOrder(77)]);

        self::assertSame([], $module->requests);
        self::assertSame([], $module->warnings);
    }

    public function testTrackingHookSilentWhenOrderHasNoTwoRecord(): void
    {
        $module = new class () extends TwopaymentTestHarness {
            public array $requests = [];

            public function getTwoOrderPaymentData($id_order)
            {
                return null;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                $this->requests[] = $endpoint;
                return null;
            }
        };

        $module->hookActionAdminOrdersTrackingNumberUpdate(['order' => $this->makeOrder(77)]);

        self::assertSame([], $module->requests);
    }
}
