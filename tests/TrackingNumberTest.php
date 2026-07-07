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
    }

    public function testTrackingNumberEmptyWhenNoneSet(): void
    {
        $module = new TwopaymentTestHarness();
        StubStore::$orderCarriers[78] = ['tracking_number' => ''];

        self::assertSame('', $module->getTwoOrderTrackingNumber($this->makeOrder(78)));
        self::assertSame('', $module->getTwoOrderTrackingNumber($this->makeOrder(0)));
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
                return null;
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
        $module = new class () extends TwopaymentTestHarness {
            public array $requests = [];

            public function getTwoOrderPaymentData($id_order)
            {
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

        self::assertSame([], $module->requests);
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
