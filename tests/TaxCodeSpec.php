<?php

declare(strict_types=1);

/**
 * TWO-24877 - tax codes on 0% lines.
 *
 * One row per derivation rule of the README table, plus the mapping, the non-Spanish merchant and the non-zero
 * cases. The cart is OrderPostprocessingSpec's (a 100.00 lamp and 29.00 of shipping, Madrid invoice address) with
 * the lamp's and the carrier's tax rules groups at 0%, a delivery address of its own, and the merchant's country
 * set as the merchant record caches it.
 *
 * Rows marked golden also compare the whole create payload with fixtures/tax-code-golden.json, which was written
 * by this spec on the base branch (TAX_CODE_GOLDEN_WRITE=1): those payloads must stay byte-identical.
 */
final class TaxCodeSpec
{
    private const CART = 9701;
    private const INVOICE = 9711;
    private const DELIVERY = 9712;
    private const PRODUCT = 9721;
    private const PRODUCT_GROUP = 9000 + 9721;
    private const CARRIER_GROUP = 7721;
    private const ORDER = 9731;
    private const GOLDEN = __DIR__ . '/fixtures/tax-code-golden.json';
    private const COUNTRIES = [34 => 'ES', 8 => 'FR', 1 => 'DE', 21 => 'US', 17 => 'NO', 6 => 'NL'];

    private const EXPORT = 'ES_IVA_EXPORT';
    private const INTRA = 'ES_IVA_INTRA_COMMUNITY';
    private const REVERSE = 'ES_IVA_REVERSE_CHARGE';
    private const ART20 = 'ES_IVA_EXEMPT_ART20';

    public static function runAll(): void
    {
        $golden = self::golden();
        $failures = [];
        foreach (self::rows() as $row) {
            try {
                self::assertRow($row, $golden);
            } catch (Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }
        foreach (['testUpdateKeepsPlacementCodes', 'testUpdateOfUnrecordedOrderResolvesNow', 'testMappingIsStoredAndReadFailLoud', 'testTaxCodeListHidesCodesNeedingAReason'] as $test) {
            try {
                self::$test();
            } catch (Throwable $e) {
                $failures[] = $test . ': ' . $e->getMessage();
            }
        }
        if (getenv('TAX_CODE_GOLDEN_WRITE') === '1') {
            file_put_contents(self::GOLDEN, json_encode(self::$written, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }
        if ($failures !== []) {
            throw new RuntimeException(count($failures) . " row(s) failed:\n  - " . implode("\n  - ", $failures));
        }
    }

    /**
     * Columns: merchant country, cart ('goods', 'service' or 'mixed'), delivery country, delivery postcode, invoice
     * (buyer company) country, mapping of the lamp's group (or null), rate ('0' or '21'), the expected codes as
     * [lamp, (service product,) shipping], golden fixture name or null, description.
     *
     * @return array<int,array>
     */
    private static function rows(): array
    {
        return [
            ['ES', 'goods', 'US', '10001', 'ES', null, '0', [self::EXPORT, self::EXPORT], null, 'goods delivered outside the EU: export, and shipping follows the goods'],
            ['ES', 'goods', 'ES', '35001', 'ES', null, '0', [self::EXPORT, self::EXPORT], null, 'goods delivered to Las Palmas (35): export'],
            ['ES', 'goods', 'ES', '38001', 'ES', null, '0', [self::EXPORT, self::EXPORT], null, 'goods delivered to Tenerife (38): export'],
            ['ES', 'goods', 'ES', '51001', 'ES', null, '0', [self::EXPORT, self::EXPORT], null, 'goods delivered to Ceuta (51): export'],
            ['ES', 'goods', 'ES', '52001', 'ES', null, '0', [self::EXPORT, self::EXPORT], null, 'goods delivered to Melilla (52): export'],
            ['ES', 'goods', 'FR', '75001', 'DE', null, '0', [self::INTRA, self::INTRA], null, 'goods delivered to France for a German buyer: intra-community, the buyer need not match the destination'],
            ['ES', 'goods', 'FR', '75001', 'ES', null, '0', [null, null], 'goods-eu-dest-es-buyer', 'goods delivered to France for a Spanish buyer: nothing derived'],
            ['ES', 'goods', 'ES', '28001', 'FR', null, '0', [null, null], 'goods-domestic', 'goods delivered in mainland Spain, even for a French buyer: nothing derived'],
            ['ES', 'goods', 'ES', '07001', 'ES', null, '0', [null, null], null, 'goods delivered to the Balearics (07): domestic, nothing derived'],
            ['ES', 'service', 'ES', '28001', 'FR', null, '0', [self::REVERSE, self::REVERSE], null, 'a service for a French buyer: reverse charge, and shipping follows the services'],
            ['ES', 'service', 'FR', '75001', 'ES', null, '0', [null, null], null, 'a service for a Spanish buyer, delivered to France: nothing derived'],
            ['ES', 'service', 'NO', '0150', 'NO', null, '0', [null, null], null, 'a service for a buyer outside the EU: nothing derived'],
            ['ES', 'mixed', 'FR', '75001', 'NL', null, '0', [self::INTRA, self::REVERSE, self::INTRA], null, 'goods and a service to the Netherlands: each by its own rule, shipping follows the goods'],
            ['ES', 'goods', 'ES', '28001', 'ES', self::ART20, '0', [self::ART20, null], null, 'a mapped group: its code, and the unmapped shipping derives nothing domestic'],
            ['ES', 'goods', 'US', '10001', 'ES', self::ART20, '0', [self::ART20, self::EXPORT], null, 'mapping beats derivation'],
            ['DE', 'goods', 'US', '10001', 'DE', null, '0', [null, null], 'non-es-unmapped', 'non-Spanish merchant, unmapped: untouched'],
            ['DE', 'goods', 'US', '10001', 'DE', 'DE_ZERO', '0', ['DE_ZERO', null], null, 'non-Spanish merchant, mapped: the mapping still applies'],
            ['', 'goods', 'US', '10001', 'ES', null, '0', [null, null], null, 'merchant country not known yet: nothing derived'],
            ['ES', 'goods', 'US', '10001', 'ES', self::ART20, '21', [null, null], 'non-zero', 'lines at 21%: untouched, mapping or not'],
        ];
    }

    /** @var array<string,array> fixtures written in TAX_CODE_GOLDEN_WRITE mode */
    private static array $written = [];

    private static function assertRow(array $row, array $golden): void
    {
        [$merchant, $cart, $destCountry, $destPostcode, $buyerCountry, $mapped, $rate, $expected, $goldenName, $description] = $row;
        self::seed($merchant, $cart, $destCountry, $destPostcode, $buyerCountry, $mapped, $rate);
        $payload = (new TwopaymentTestHarness())->getTwoNewOrderData('merchant-attempt-' . self::CART, new Cart(self::CART), self::merchantUrls());

        TinyAssert::same($expected, self::codes($payload), $description . ' (got: ' . json_encode(self::codes($payload)) . ')');
        if ($goldenName !== null) {
            $pinned = self::pin($payload);
            self::$written[$goldenName] = $pinned;
            if (getenv('TAX_CODE_GOLDEN_WRITE') !== '1') {
                TinyAssert::same($golden[$goldenName] ?? null, $pinned, $description . ': the payload is not byte-identical to the golden');
            }
        }
    }

    private static function testUpdateKeepsPlacementCodes(): void
    {
        self::seed('ES', 'goods', 'US', '10001', 'ES', null, '0');
        $module = new TwopaymentTestHarness();
        $module->getTwoNewOrderData('merchant-attempt-' . self::CART, new Cart(self::CART), self::merchantUrls());
        $record = (string) $module->getTwoDeclaredChargeRates();
        TinyAssert::same(['product:' . self::PRODUCT => self::EXPORT, 'shipping' => self::EXPORT], json_decode($record, true)['tax_codes'] ?? null, 'placement records the codes it resolved');

        // The order is later edited to a domestic address: the codes placement resolved still go out.
        StubStore::$addresses[self::DELIVERY]['id_country'] = 34;
        StubStore::$addresses[self::DELIVERY]['postcode'] = '28001';
        $payload = (new TwopaymentTestHarness())->getTwoUpdateOrderData(self::order(), self::paymentRow($record));
        TinyAssert::same([self::EXPORT, self::EXPORT], self::codes($payload), 'an update sends the codes placement resolved');
    }

    private static function testUpdateOfUnrecordedOrderResolvesNow(): void
    {
        self::seed('ES', 'goods', 'US', '10001', 'ES', null, '0');
        $payload = (new TwopaymentTestHarness())->getTwoUpdateOrderData(self::order(), self::paymentRow('{"shipping":[],"wrapping":null}'));
        TinyAssert::same([self::EXPORT, self::EXPORT], self::codes($payload), 'an order placed before the codes were recorded resolves them on update');
    }

    private static function testMappingIsStoredAndReadFailLoud(): void
    {
        self::seed('ES', 'goods', 'ES', '28001', 'ES', null, '0');
        Configuration::updateValue('PS_TWO_TAX_CODE_MAP', '{"abc":"ES_IVA_EXPORT"}');
        TinyAssert::throws(function () {
            (new TwopaymentTestHarness())->getTwoNewOrderData('merchant-attempt-' . self::CART, new Cart(self::CART), self::merchantUrls());
        }, 'tax code mapping is unreadable');
    }

    private static function testTaxCodeListHidesCodesNeedingAReason(): void
    {
        self::seed('ES', 'goods', 'ES', '28001', 'ES', null, '0');
        $module = new class extends TwopaymentTestHarness {
            public array $requested = [];

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->requested[] = $method . ' ' . $endpoint;

                return ['http_status' => 200, 'data' => [
                    ['code' => 'ES_IVA_EXPORT', 'display_name' => 'Exportación', 'rate' => '0', 'requires_exemption_reason' => true, 'exemption_reason_code' => 'VATEX-EU-G'],
                    ['code' => 'ES_IVA_EXEMPT_OTHER', 'display_name' => 'Exento otros', 'rate' => '0', 'requires_exemption_reason' => true, 'exemption_reason_code' => null],
                    ['code' => 'ES_IVA_STANDARD', 'display_name' => 'General', 'rate' => '0.21', 'requires_exemption_reason' => false, 'exemption_reason_code' => null],
                ]];
            }
        };
        $codes = array_column((array) $module->getTwoTaxCodeOptions(), 'code');
        TinyAssert::same(['ES_IVA_EXPORT', 'ES_IVA_STANDARD'], $codes, 'a code needing a caller-supplied reason is not offered');
        $module->getTwoTaxCodeOptions();
        TinyAssert::same(['GET /v1/tax_codes/ES'], $module->requested, 'the list is fetched for the merchant country once, then served from cache');
    }

    private static function seed(string $merchant, string $cart, string $destCountry, string $destPostcode, string $buyerCountry, ?string $mapped, string $rate): void
    {
        OrderPostprocessingSpec::seed(true);
        $ids = array_flip(self::COUNTRIES);
        foreach (self::COUNTRIES as $id => $iso) {
            StubStore::$countries[$id] = $iso;
        }
        StubStore::$addresses[self::INVOICE]['id_country'] = $ids[$buyerCountry];
        StubStore::$addresses[self::DELIVERY] = ['id_country' => $ids[$destCountry], 'postcode' => $destPostcode] + StubStore::$addresses[self::INVOICE];
        StubStore::$carts[self::CART]['id_address_delivery'] = self::DELIVERY;

        $zero = $rate === '0';
        $lines = [$cart === 'service' ? 1 : 0];
        if ($cart === 'mixed') {
            $lines[] = 1;
        }
        $products = [];
        foreach ($lines as $n => $virtual) {
            $product = StubStore::$cartProducts[self::CART][0];
            $product['id_product'] = self::PRODUCT + $n;
            $product['total_wt'] = $zero ? 100.00 : 121.00;
            $product['is_virtual'] = $virtual;
            $products[] = $product;
            StubStore::$products[self::PRODUCT + $n]['id_tax_rules_group'] = self::PRODUCT_GROUP + $n;
            StubStore::$taxRuleRates[self::PRODUCT_GROUP + $n] = $zero ? 0.0 : 21.0;
            StubStore::$productCategories[self::PRODUCT + $n] = [['name' => 'General']];
        }
        StubStore::$cartProducts[self::CART] = $products;
        StubStore::$taxRuleRates[self::CARRIER_GROUP] = $zero ? 0.0 : 21.0;
        $productsNet = 100.00 * count($products);
        $productsGross = ($zero ? 100.00 : 121.00) * count($products);
        $shippingNet = $zero ? 29.00 : 23.9669;
        foreach (StubStore::$cartDeliveryOptionLists[self::CART] as $address => $options) {
            unset(StubStore::$cartDeliveryOptionLists[self::CART][$address]);
            foreach ($options as $key => $option) {
                foreach ($option['carrier_list'] as $id => $carrier) {
                    $option['carrier_list'][$id]['price_without_tax'] = $shippingNet;
                }
                StubStore::$cartDeliveryOptionLists[self::CART][self::DELIVERY][$key] = $option;
            }
        }
        StubStore::$cartTotals[self::CART] = [
            true => [Cart::ONLY_DISCOUNTS => 0.00, Cart::ONLY_SHIPPING => 29.00, Cart::BOTH => $productsGross + 29.00],
            false => [Cart::ONLY_DISCOUNTS => 0.00, Cart::ONLY_SHIPPING => $shippingNet, Cart::BOTH => $productsNet + $shippingNet],
        ];

        Configuration::updateValue('PS_TWO_MERCHANT_COUNTRY', $merchant);
        if ($mapped !== null) {
            Configuration::updateValue('PS_TWO_TAX_CODE_MAP', json_encode([(string) self::PRODUCT_GROUP => $mapped]));
        }
    }

    /**
     * The tax_code of every product line, then of the shipping line; null where a line carries none.
     *
     * @return array<int,string|null>
     */
    private static function codes(array $payload): array
    {
        $codes = [];
        $shipping = [];
        foreach ($payload['line_items'] as $line) {
            if ($line['type'] === 'SHIPPING_FEE') {
                $shipping[] = $line['tax_code'] ?? null;
            } elseif ($line['type'] === 'PHYSICAL') {
                $codes[] = $line['tax_code'] ?? null;
            }
        }

        return array_merge($codes, $shipping);
    }

    private static function order(): PlacedOrderStub
    {
        $order = PlacedOrderStub::fromCart(self::ORDER, self::CART);
        $order->reference = 'TAXREF';
        StubStore::$placedOrders = [$order];

        return $order;
    }

    private static function paymentRow(string $declared): array
    {
        return ['two_order_id' => 'two-order-9731', 'two_order_reference' => 'ref', 'two_day_on_invoice' => '30', 'two_declared_rates' => $declared];
    }

    private static function pin(array $payload): array
    {
        $payload['merchant_reference'] = 'PINNED';
        $payload['shipping_details']['expected_delivery_date'] = 'PINNED';

        return $payload;
    }

    private static function golden(): array
    {
        if (getenv('TAX_CODE_GOLDEN_WRITE') === '1' || !is_file(self::GOLDEN)) {
            return [];
        }

        return (array) json_decode((string) file_get_contents(self::GOLDEN), true);
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
}
