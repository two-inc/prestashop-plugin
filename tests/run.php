<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

final class TinyAssert
{
    public static function same($expected, $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message !== '' ? $message : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public static function true($value, string $message = ''): void
    {
        if ($value !== true) {
            throw new RuntimeException($message !== '' ? $message : 'Expected true, got ' . var_export($value, true));
        }
    }

    public static function false($value, string $message = ''): void
    {
        if ($value !== false) {
            throw new RuntimeException($message !== '' ? $message : 'Expected false, got ' . var_export($value, true));
        }
    }

    public static function count(int $expected, array $actual, string $message = ''): void
    {
        if (count($actual) !== $expected) {
            throw new RuntimeException($message !== '' ? $message : 'Expected count ' . $expected . ', got ' . count($actual));
        }
    }

    public static function notSame($left, $right, string $message = ''): void
    {
        if ($left === $right) {
            throw new RuntimeException($message !== '' ? $message : 'Expected values to be different, got ' . var_export($left, true));
        }
    }

    public static function throws(callable $callback, string $expectedMessage): void
    {
        try {
            $callback();
        } catch (Exception $e) {
            if ($expectedMessage !== '' && strpos($e->getMessage(), $expectedMessage) === false) {
                throw new RuntimeException('Expected exception message containing "' . $expectedMessage . '", got "' . $e->getMessage() . '"');
            }
            return;
        }

        throw new RuntimeException('Expected exception was not thrown');
    }
}

final class OrderBuilderSpec
{
    public static function runAll(): void
    {
        self::testValidateTwoLineItemsRejectsBrokenTaxFormula();
        self::testGetTwoTaxSubtotalsKeepsDecimalTaxRatePrecision();
        self::testGetTwoProductItemsUsesAppliedTaxRateWhenConfiguredRateDiffers();
        self::testGetTwoNewOrderDataComputesOrderTaxRateFromTotals();
        self::testGetTwoNewOrderDataThrowsWhenLineItemsFailFormulaValidation();
        self::testSnapshotHashChangesWhenTaxRateChangesBeyondTwoDecimals();
        self::testIsTwoAttemptCallbackAuthorizedWithMatchingKey();
        self::testIsTwoAttemptCallbackAuthorizedFallsBackToContextCustomerKeyWhenRequestKeyMissing();
        self::testIsTwoAttemptCallbackAuthorizedRejectsMismatchedKeys();
        self::testGetTwoValidatedSessionCompanyDataRejectsCountryMismatch();
        self::testGetTwoValidatedSessionCompanyDataRejectsLegacySessionWithoutCountryMarker();
        self::testBuildTwoApiResponseLogSummaryRedactsNestedProviderPayload();
        self::testGetTwoErrorMessageReturnsHttpFallbackForNonJsonProviderErrors();
        self::testGetTwoErrorMessageReadsNestedDataMessage();
        self::testGetTwoErrorMessageIgnoresSuccessMessagePayload();
        self::testGetTwoProductItemsSkipsEmptyBarcodeEntries();
    }

    private static function reset(): void
    {
        StubStore::reset();
    }

    private static function testValidateTwoLineItemsRejectsBrokenTaxFormula(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $lineItems = [[
            'name' => 'TV',
            'net_amount' => '100.00',
            'tax_amount' => '15.00',
            'tax_rate' => '0.21',
            'unit_price' => '100.00',
            'quantity' => 1,
            'discount_amount' => '0.00',
        ]];

        TinyAssert::false($module->validateTwoLineItems($lineItems));
    }

    private static function testGetTwoTaxSubtotalsKeepsDecimalTaxRatePrecision(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $lineItems = [
            ['tax_rate' => '0.205', 'net_amount' => '100.00', 'tax_amount' => '20.50'],
            ['tax_rate' => '0.205', 'net_amount' => '50.00', 'tax_amount' => '10.25'],
            ['tax_rate' => '0.21', 'net_amount' => '200.00', 'tax_amount' => '42.00'],
        ];

        $subtotals = $module->getTwoTaxSubtotals($lineItems);

        TinyAssert::same('0.205', $subtotals[0]['tax_rate']);
        TinyAssert::same('150.00', $subtotals[0]['taxable_amount']);
        TinyAssert::same('30.75', $subtotals[0]['tax_amount']);
        TinyAssert::same('0.21', $subtotals[1]['tax_rate']);
        TinyAssert::same('200.00', $subtotals[1]['taxable_amount']);
        TinyAssert::same('42.00', $subtotals[1]['tax_amount']);
    }

    private static function testGetTwoProductItemsUsesAppliedTaxRateWhenConfiguredRateDiffers(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $cart = new Cart(10);
        $cart->id_lang = 1;
        $cart->id_carrier = 999;

        StubStore::$cartProducts[10] = [[
            'id_product' => 501,
            'link_rewrite' => 'smart-tv',
            'name' => 'Smart TV',
            'description_short' => 'Test description',
            'manufacturer_name' => 'LG',
            'ean13' => '1234567890123',
            'upc' => '012345678905',
            'total' => 100.00,
            'total_wt' => 120.50,
            'cart_quantity' => 1,
            'rate' => 21.0,
            'price' => 100.00,
            'reduction' => 0,
        ]];

        StubStore::$productCategories[501] = [['name' => 'Electronics']];
        StubStore::$images[501] = ['id_image' => 9001];

        $items = $module->getTwoProductItems($cart);

        TinyAssert::count(1, $items);
        TinyAssert::same('0.205', $items[0]['tax_rate']);
        TinyAssert::same('20.50', $items[0]['tax_amount']);
        TinyAssert::same('120.50', $items[0]['gross_amount']);
    }

    private static function testGetTwoNewOrderDataComputesOrderTaxRateFromTotals(): void
    {
        self::reset();

        $lineItems = [[
            'name' => 'Widget',
            'description' => 'Test',
            'gross_amount' => '120.50',
            'net_amount' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '20.50',
            'tax_class_name' => 'VAT 20.50%',
            'tax_rate' => '0.205',
            'unit_price' => '100.00',
            'quantity' => 1,
            'quantity_unit' => 'pcs',
            'image_url' => '',
            'product_page_url' => '',
            'type' => 'PHYSICAL',
            'details' => ['brand' => 'Brand', 'barcodes' => [], 'categories' => []],
        ]];

        $module = new class($lineItems) extends TwopaymentTestHarness {
            private array $forcedLineItems;

            public function __construct(array $forcedLineItems)
            {
                parent::__construct();
                $this->forcedLineItems = $forcedLineItems;
            }

            public function getTwoProductItems($cart)
            {
                return $this->forcedLineItems;
            }

            public function buildTermsPayload()
            {
                return ['type' => 'NET_TERMS', 'duration_days' => 30];
            }
        };

        StubStore::$customers[301] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Juan',
            'lastname' => 'Gonzalez',
            'secure_key' => 'secure-key',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[401] = [
            'id_country' => 34,
            'company' => 'Acme S.L.',
            'companyid' => 'B12345678',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];
        StubStore::$addresses[402] = [
            'id_country' => 34,
            'company' => 'Acme S.L.',
            'companyid' => 'B12345678',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];

        $cart = new Cart(55);
        $cart->id_customer = 301;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 401;
        $cart->id_address_delivery = 402;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[55] = [['id_product' => 501, 'cart_quantity' => 1]];
        StubStore::$cartTotals[55] = [
            true => [Cart::ONLY_DISCOUNTS => 0.0],
            false => [Cart::ONLY_DISCOUNTS => 0.0],
            'average_products_tax_rate' => 21.0,
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-55', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        TinyAssert::same('0.205', $payload['tax_rate']);
        TinyAssert::same('100.00', $payload['net_amount']);
        TinyAssert::same('20.50', $payload['tax_amount']);
        TinyAssert::same('120.50', $payload['gross_amount']);
    }

    private static function testGetTwoNewOrderDataThrowsWhenLineItemsFailFormulaValidation(): void
    {
        self::reset();

        $module = new class extends TwopaymentTestHarness {
            public function getTwoProductItems($cart)
            {
                return [[
                    'name' => 'Broken line',
                    'net_amount' => '100.00',
                    'tax_amount' => '10.00',
                    'tax_rate' => '0.21',
                    'unit_price' => '100.00',
                    'quantity' => 1,
                    'discount_amount' => '0.00',
                ]];
            }

            public function buildTermsPayload()
            {
                return ['type' => 'NET_TERMS', 'duration_days' => 30];
            }
        };

        StubStore::$customers[302] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Maria',
            'lastname' => 'Lopez',
            'secure_key' => 'secure-key-2',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[501] = [
            'id_country' => 34,
            'company' => 'Acme S.L.',
            'companyid' => 'B12345678',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];
        StubStore::$addresses[502] = StubStore::$addresses[501];

        $cart = new Cart(56);
        $cart->id_customer = 302;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 501;
        $cart->id_address_delivery = 502;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[56] = [['id_product' => 1, 'cart_quantity' => 1]];
        StubStore::$cartTotals[56] = [
            true => [Cart::ONLY_DISCOUNTS => 0.0],
            false => [Cart::ONLY_DISCOUNTS => 0.0],
            'average_products_tax_rate' => 21.0,
        ];

        TinyAssert::throws(function () use ($module, $cart): void {
            $module->getTwoNewOrderData('merchant-attempt-56', $cart, [
                'merchant_confirmation_url' => 'https://shop.local/confirm',
                'merchant_cancel_order_url' => 'https://shop.local/cancel',
                'merchant_edit_order_url' => '',
                'merchant_order_verification_failed_url' => '',
                'merchant_invoice_url' => '',
                'merchant_shipping_document_url' => '',
            ]);
        }, 'Invalid line item formulas');
    }

    private static function testSnapshotHashChangesWhenTaxRateChangesBeyondTwoDecimals(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $cart = new stdClass();
        $cart->id = 77;
        $cart->id_customer = 1;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 1;
        $cart->id_address_delivery = 1;
        $cart->id_carrier = 0;

        $basePayload = [
            'currency' => 'EUR',
            'gross_amount' => '120.50',
            'net_amount' => '100.00',
            'tax_amount' => '20.50',
            'discount_amount' => '0.00',
            'line_items' => [[
                'type' => 'PHYSICAL',
                'quantity' => 1,
                'unit_price' => '100.00',
                'net_amount' => '100.00',
                'tax_amount' => '20.50',
                'gross_amount' => '120.50',
                'discount_amount' => '0.00',
                'tax_rate' => '0.205',
            ]],
            'tax_subtotals' => [[
                'tax_rate' => '0.205',
                'taxable_amount' => '100.00',
                'tax_amount' => '20.50',
            ]],
        ];

        $changedPayload = $basePayload;
        $changedPayload['line_items'][0]['tax_rate'] = '0.206';
        $changedPayload['tax_subtotals'][0]['tax_rate'] = '0.206';

        $hashA = $module->calculateTwoCheckoutSnapshotHash($cart, $basePayload);
        $hashB = $module->calculateTwoCheckoutSnapshotHash($cart, $changedPayload);

        TinyAssert::notSame($hashA, $hashB);
    }

    private static function testIsTwoAttemptCallbackAuthorizedWithMatchingKey(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $attempt = [
            'id_customer' => 77,
            'customer_secure_key' => 'secure-key-77',
        ];

        TinyAssert::true($module->isTwoAttemptCallbackAuthorized($attempt, 'secure-key-77'));
    }

    private static function testIsTwoAttemptCallbackAuthorizedFallsBackToContextCustomerKeyWhenRequestKeyMissing(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $attempt = [
            'id_customer' => 99,
            'customer_secure_key' => 'secure-key-99',
        ];

        TinyAssert::true($module->isTwoAttemptCallbackAuthorized($attempt, '', 99, 'secure-key-99'));
    }

    private static function testIsTwoAttemptCallbackAuthorizedRejectsMismatchedKeys(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $attempt = [
            'id_customer' => 42,
            'customer_secure_key' => 'secure-key-42',
        ];

        TinyAssert::false($module->isTwoAttemptCallbackAuthorized($attempt, 'invalid-key', 42, 'secure-key-42'));
        TinyAssert::false($module->isTwoAttemptCallbackAuthorized($attempt, '', 41, 'secure-key-42'));
    }

    private static function testGetTwoValidatedSessionCompanyDataRejectsCountryMismatch(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $module->context->cookie->two_company_name = 'Acme Ltd';
        $module->context->cookie->two_company_id = 'NO123';
        $module->context->cookie->two_company_country = 'NO';

        $data = $module->getTwoValidatedSessionCompanyData('ES');

        TinyAssert::same('', $data['company_name']);
        TinyAssert::same('', $data['organization_number']);
        TinyAssert::false(isset($module->context->cookie->two_company_name));
        TinyAssert::false(isset($module->context->cookie->two_company_id));
        TinyAssert::false(isset($module->context->cookie->two_company_country));
    }

    private static function testGetTwoValidatedSessionCompanyDataRejectsLegacySessionWithoutCountryMarker(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $module->context->cookie->two_company_name = 'Acme Ltd';
        $module->context->cookie->two_company_id = 'NO123';

        $data = $module->getTwoValidatedSessionCompanyData('ES');

        TinyAssert::same('', $data['company_name']);
        TinyAssert::same('', $data['organization_number']);
        TinyAssert::false(isset($module->context->cookie->two_company_name));
        TinyAssert::false(isset($module->context->cookie->two_company_id));
    }

    private static function testBuildTwoApiResponseLogSummaryRedactsNestedProviderPayload(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $summary = $module->buildTwoApiResponseLogSummary([
            'http_status' => 400,
            'id' => 'two-order-1',
            'state' => 'CREATED',
            'status' => 'PENDING',
            'merchant_reference' => 'merchant-ref-1',
            'error' => 'validation_error',
            'data' => [
                'invoice_url' => 'https://sensitive.example/invoice',
                'buyer' => ['email' => 'buyer@example.com'],
            ],
        ]);

        TinyAssert::same(400, $summary['http_status']);
        TinyAssert::same('two-order-1', $summary['two_order_id']);
        TinyAssert::same('CREATED', $summary['two_order_state']);
        TinyAssert::same('PENDING', $summary['two_order_status']);
        TinyAssert::same('merchant-ref-1', $summary['two_order_reference']);
        TinyAssert::same('validation_error', $summary['error']);
        TinyAssert::false(isset($summary['data']));
        TinyAssert::false(isset($summary['invoice_url']));
    }

    private static function testGetTwoErrorMessageReturnsHttpFallbackForNonJsonProviderErrors(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $message = $module->getTwoErrorMessage([
            'http_status' => 502,
            'data' => null,
        ]);

        TinyAssert::same('Two response code 502', $message);
    }

    private static function testGetTwoErrorMessageReadsNestedDataMessage(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $message = $module->getTwoErrorMessage([
            'http_status' => 400,
            'data' => [
                'error_message' => 'Validation failed',
            ],
        ]);

        TinyAssert::same('Validation failed', $message);
    }

    private static function testGetTwoErrorMessageIgnoresSuccessMessagePayload(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $message = $module->getTwoErrorMessage([
            'http_status' => 200,
            'message' => 'Order confirmed',
        ]);

        TinyAssert::same(null, $message);
    }

    private static function testGetTwoProductItemsSkipsEmptyBarcodeEntries(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $cart = new Cart(811);
        $cart->id_lang = 1;
        $cart->id_carrier = 999;

        StubStore::$cartProducts[811] = [[
            'id_product' => 701,
            'link_rewrite' => 'office-chair',
            'name' => 'Office Chair',
            'description_short' => 'Ergonomic chair',
            'manufacturer_name' => 'Acme',
            'ean13' => '',
            'upc' => '',
            'total' => 100.00,
            'total_wt' => 121.00,
            'cart_quantity' => 1,
            'rate' => 21.0,
            'price' => 100.00,
            'reduction' => 0,
        ]];

        StubStore::$productCategories[701] = [['name' => 'Furniture']];
        StubStore::$images[701] = ['id_image' => 9901];

        $items = $module->getTwoProductItems($cart);

        TinyAssert::count(1, $items);
        TinyAssert::same([], $items[0]['details']['barcodes']);
    }
}

$tests = [
    'OrderBuilderSpec::runAll' => [OrderBuilderSpec::class, 'runAll'],
];

$failed = 0;
foreach ($tests as $name => $callable) {
    try {
        $callable();
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "FAIL {$name}: {$e->getMessage()}\n");
    }
}

if ($failed > 0) {
    exit(1);
}

echo "All tests passed.\n";
