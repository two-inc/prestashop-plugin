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
        self::testGetTwoCheckoutCompanyDataUsesAddressVatNumberForAnyCountry();
        self::testGetTwoCheckoutCompanyDataUsesValidatedCookieFallback();
        self::testGetTwoCheckoutCompanyDataClearsStaleCookieOnCountryMismatch();
        self::testSaveGeneralFormDoesNotChangeSslVerificationFlag();
        self::testSaveOtherFormUpdatesSslVerificationFlag();
        self::testHookActionAdminControllerSetMediaRegistersCssOnModuleConfigPage();
        self::testHookActionAdminControllerSetMediaSkipsUnrelatedAdminPage();
        self::testHookPaymentOptionsAllowsBusinessFallbackWhenAccountTypeMissing();
        self::testHookPaymentOptionsBlocksNonBusinessWhenAccountTypePresent();
        self::testMergeTwoPaymentTermFallbackUsesFallbackWhenMissing();
        self::testMergeTwoPaymentTermFallbackKeepsExistingValues();
        self::testShouldExposeTwoInvoiceActionsRequiresFulfilledState();
        self::testResolveTwoPaymentTermsFromOrderResponseUsesEndOfMonthAsEom();
        self::testResolveTwoPaymentTermsFromOrderResponseFallsBackToStandardForUnsupportedScheme();
        self::testSyncTwoAdminOrderPaymentDataFromProviderPullsLatestTermsFromTwo();
        self::testSyncTwoAdminOrderPaymentDataFromProviderSupportsNestedDataEnvelope();
        self::testSyncTwoAdminOrderPaymentDataFromProviderRecoversMissingTwoOrderIdFromAttempt();
        self::testGetLatestTwoCheckoutAttemptByOrderSelectsTwoOrderIdForFallbackRecovery();
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

    private static function testGetTwoCheckoutCompanyDataUsesAddressVatNumberForAnyCountry(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        StubStore::$countries[826] = 'GB';
        StubStore::$addresses[801] = [
            'id_country' => 826,
            'company' => 'Acme UK Ltd',
            'vat_number' => 'GB123456789',
            'loaded' => true,
        ];

        $address = new Address(801);
        $data = $module->getTwoCheckoutCompanyData($address);

        TinyAssert::same('Acme UK Ltd', $data['company_name']);
        TinyAssert::same('123456789', $data['organization_number']);
        TinyAssert::same('GB', $data['country_iso']);
    }

    private static function testGetTwoCheckoutCompanyDataUsesValidatedCookieFallback(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $module->context->cookie->two_company_name = 'Acme ES S.L.';
        $module->context->cookie->two_company_id = 'B12345678';
        $module->context->cookie->two_company_country = 'ES';

        StubStore::$addresses[802] = [
            'id_country' => 34,
            'company' => '',
            'loaded' => true,
        ];

        $address = new Address(802);
        $data = $module->getTwoCheckoutCompanyData($address);

        TinyAssert::same('Acme ES S.L.', $data['company_name']);
        TinyAssert::same('B12345678', $data['organization_number']);
        TinyAssert::same('ES', $data['country_iso']);
    }

    private static function testGetTwoCheckoutCompanyDataClearsStaleCookieOnCountryMismatch(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $module->context->cookie->two_company_name = 'Acme Norge';
        $module->context->cookie->two_company_id = 'NO123';
        $module->context->cookie->two_company_country = 'NO';

        StubStore::$addresses[803] = [
            'id_country' => 34,
            'company' => '',
            'loaded' => true,
        ];

        $address = new Address(803);
        $data = $module->getTwoCheckoutCompanyData($address);

        TinyAssert::same('', $data['company_name']);
        TinyAssert::same('', $data['organization_number']);
        TinyAssert::same('ES', $data['country_iso']);
        TinyAssert::false(isset($module->context->cookie->two_company_name));
        TinyAssert::false(isset($module->context->cookie->two_company_id));
        TinyAssert::false(isset($module->context->cookie->two_company_country));
    }

    private static function testSaveGeneralFormDoesNotChangeSslVerificationFlag(): void
    {
        self::reset();
        $module = new class extends TwopaymentTestHarness {
            public function saveGeneralForTest(): void
            {
                $this->saveTwoGeneralFormValues();
            }
        };

        Configuration::updateValue('PS_TWO_DISABLE_SSL_VERIFY', 1);
        Tools::setTestValue('PS_TWO_DISABLE_SSL_VERIFY', 0);
        Tools::setTestValue('PS_TWO_ENVIRONMENT', 'development');
        Tools::setTestValue('PS_TWO_TITLE_1', 'Two title');
        Tools::setTestValue('PS_TWO_SUB_TITLE_1', 'Two subtitle');
        Tools::setTestValue('PS_TWO_MERCHANT_SHORT_NAME', 'merchant');
        Tools::setTestValue('PS_TWO_MERCHANT_API_KEY', 'api-key');

        $module->saveGeneralForTest();

        TinyAssert::same(1, (int) Configuration::get('PS_TWO_DISABLE_SSL_VERIFY'));
    }

    private static function testSaveOtherFormUpdatesSslVerificationFlag(): void
    {
        self::reset();
        $module = new class extends TwopaymentTestHarness {
            public function saveOtherForTest(): void
            {
                $this->saveTwoOtherFormValues();
            }
        };

        Configuration::updateValue('PS_TWO_DISABLE_SSL_VERIFY', 0);
        Tools::setTestValue('PS_TWO_DISABLE_SSL_VERIFY', 1);

        $module->saveOtherForTest();

        TinyAssert::same(1, (int) Configuration::get('PS_TWO_DISABLE_SSL_VERIFY'));
    }

    private static function testHookActionAdminControllerSetMediaRegistersCssOnModuleConfigPage(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $controller = new class {
            public $controller_name = 'AdminModules';
            public $php_self = 'module';
            public $styles = [];

            public function registerStylesheet($id, $path, $options = [])
            {
                $this->styles[] = [
                    'id' => $id,
                    'path' => $path,
                    'options' => $options,
                ];
            }
        };

        $module->context->controller = $controller;
        Tools::setTestValue('configure', 'twopayment');
        Tools::setTestValue('controller', 'AdminModules');

        $module->hookActionAdminControllerSetMedia();

        TinyAssert::same(1, count($controller->styles));
        TinyAssert::same('module-twopayment-admin-css', $controller->styles[0]['id']);
    }

    private static function testHookActionAdminControllerSetMediaSkipsUnrelatedAdminPage(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $controller = new class {
            public $controller_name = 'AdminProducts';
            public $php_self = 'products';
            public $styles = [];

            public function registerStylesheet($id, $path, $options = [])
            {
                $this->styles[] = [
                    'id' => $id,
                    'path' => $path,
                    'options' => $options,
                ];
            }
        };

        $module->context->controller = $controller;
        Tools::setTestValue('configure', 'othermodule');
        Tools::setTestValue('controller', 'AdminProducts');

        $module->hookActionAdminControllerSetMedia();

        TinyAssert::same(0, count($controller->styles));
    }

    private static function testHookPaymentOptionsAllowsBusinessFallbackWhenAccountTypeMissing(): void
    {
        self::reset();
        $module = new class extends TwopaymentTestHarness {
            protected function getTwoPaymentOption()
            {
                return (object) ['method' => 'two'];
            }
        };

        $module->active = true;
        Configuration::updateValue('PS_TWO_USE_ACCOUNT_TYPE', 1);
        StubStore::$countries[826] = 'GB';
        StubStore::$addresses[901] = [
            'id_country' => 826,
            'company' => 'Acme UK Ltd',
            'vat_number' => 'GB123456789',
            'loaded' => true,
        ];

        $cart = new Cart(501);
        $cart->id_address_invoice = 901;
        $module->context->cart = $cart;

        $options = $module->hookPaymentOptions([]);

        TinyAssert::same(1, count($options));
    }

    private static function testHookPaymentOptionsBlocksNonBusinessWhenAccountTypePresent(): void
    {
        self::reset();
        $module = new class extends TwopaymentTestHarness {
            protected function getTwoPaymentOption()
            {
                return (object) ['method' => 'two'];
            }
        };

        $module->active = true;
        Configuration::updateValue('PS_TWO_USE_ACCOUNT_TYPE', 1);
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[902] = [
            'id_country' => 34,
            'company' => 'Acme ES S.L.',
            'dni' => 'B12345678',
            'account_type' => 'private',
            'loaded' => true,
        ];

        $cart = new Cart(502);
        $cart->id_address_invoice = 902;
        $module->context->cart = $cart;

        $options = $module->hookPaymentOptions([]);

        TinyAssert::same(0, count($options));
    }

    private static function testMergeTwoPaymentTermFallbackUsesFallbackWhenMissing(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $base = [
            'id_order' => 11,
            'two_day_on_invoice' => '',
            'two_payment_term_type' => '',
        ];
        $fallback = [
            'two_day_on_invoice' => '45',
            'two_payment_term_type' => 'EOM',
        ];

        $merged = $module->mergeTwoPaymentTermFallback($base, $fallback);

        TinyAssert::same('45', (string) $merged['two_day_on_invoice']);
        TinyAssert::same('EOM', (string) $merged['two_payment_term_type']);
    }

    private static function testMergeTwoPaymentTermFallbackKeepsExistingValues(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $base = [
            'id_order' => 12,
            'two_day_on_invoice' => '30',
            'two_payment_term_type' => 'STANDARD',
        ];
        $fallback = [
            'two_day_on_invoice' => '60',
            'two_payment_term_type' => 'EOM',
        ];

        $merged = $module->mergeTwoPaymentTermFallback($base, $fallback);

        TinyAssert::same('30', (string) $merged['two_day_on_invoice']);
        TinyAssert::same('STANDARD', (string) $merged['two_payment_term_type']);
    }

    private static function testShouldExposeTwoInvoiceActionsRequiresFulfilledState(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::true($module->shouldExposeTwoInvoiceActions(['two_order_state' => 'FULFILLED']));
        TinyAssert::false($module->shouldExposeTwoInvoiceActions(['two_order_state' => 'VERIFIED']));
        TinyAssert::false($module->shouldExposeTwoInvoiceActions(['two_order_state' => 'CONFIRMED']));
        TinyAssert::false($module->shouldExposeTwoInvoiceActions(['two_order_state' => '']));
    }

    private static function testResolveTwoPaymentTermsFromOrderResponseUsesEndOfMonthAsEom(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $response = [
            'terms' => [
                'duration_days' => 60,
                'duration_days_calculated_from' => 'END_OF_MONTH',
            ],
        ];

        $resolved = $module->resolveTwoPaymentTermsFromOrderResponse($response, '30', 'STANDARD');

        TinyAssert::same('60', (string)$resolved['two_day_on_invoice']);
        TinyAssert::same('EOM', (string)$resolved['two_payment_term_type']);
    }

    private static function testResolveTwoPaymentTermsFromOrderResponseFallsBackToStandardForUnsupportedScheme(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $response = [
            'terms' => [
                'duration_days' => 45,
                'duration_days_calculated_from' => 'END_OF_WEEK',
            ],
        ];

        $resolved = $module->resolveTwoPaymentTermsFromOrderResponse($response, '30', 'EOM');

        TinyAssert::same('45', (string)$resolved['two_day_on_invoice']);
        TinyAssert::same('STANDARD', (string)$resolved['two_payment_term_type']);
    }

    private static function testSyncTwoAdminOrderPaymentDataFromProviderPullsLatestTermsFromTwo(): void
    {
        self::reset();
        $module = new class extends TwopaymentTestHarness {
            public $lastSavedOrderId = null;
            public $lastSavedPaymentData = null;

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                if ($method === 'GET' && $endpoint === '/v1/order/two-123') {
                    return [
                        'http_status' => Twopayment::HTTP_STATUS_OK,
                        'id' => 'two-123',
                        'merchant_reference' => 'MR-123',
                        'state' => 'CONFIRMED',
                        'status' => 'PENDING',
                        'invoice_url' => 'https://two.test/invoice/123',
                        'invoice_details' => ['id' => 'inv-123'],
                        'terms' => [
                            'type' => 'NET_TERMS',
                            'duration_days' => 60,
                            'duration_days_calculated_from' => 'END_OF_MONTH',
                        ],
                    ];
                }

                return ['http_status' => 500];
            }

            public function setTwoOrderPaymentData($id_order, $payment_data)
            {
                $this->lastSavedOrderId = (int)$id_order;
                $this->lastSavedPaymentData = $payment_data;
            }

            public function syncAdminDataForTest($id_order, $twopaymentdata)
            {
                return $this->syncTwoAdminOrderPaymentDataFromProvider($id_order, $twopaymentdata);
            }
        };

        $base = [
            'id_order' => 55,
            'two_order_id' => 'two-123',
            'two_order_reference' => '',
            'two_order_state' => 'VERIFIED',
            'two_order_status' => 'APPROVED',
            'two_day_on_invoice' => '',
            'two_payment_term_type' => '',
            'two_invoice_url' => '',
            'two_invoice_id' => '',
        ];

        $synced = $module->syncAdminDataForTest(55, $base);

        TinyAssert::same('60', (string)$synced['two_day_on_invoice']);
        TinyAssert::same('EOM', (string)$synced['two_payment_term_type']);
        TinyAssert::same('CONFIRMED', (string)$synced['two_order_state']);
        TinyAssert::same('MR-123', (string)$synced['two_order_reference']);
        TinyAssert::same(55, (int)$module->lastSavedOrderId);
        TinyAssert::same('60', (string)$module->lastSavedPaymentData['two_day_on_invoice']);
    }

    private static function testSyncTwoAdminOrderPaymentDataFromProviderSupportsNestedDataEnvelope(): void
    {
        self::reset();
        $module = new class extends TwopaymentTestHarness {
            public $lastSavedOrderId = null;
            public $lastSavedPaymentData = null;

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                if ($method === 'GET' && $endpoint === '/v1/order/two-456') {
                    return [
                        'http_status' => Twopayment::HTTP_STATUS_OK,
                        'data' => [
                            'id' => 'two-456',
                            'merchant_reference' => 'MR-456',
                            'state' => 'CONFIRMED',
                            'status' => 'PENDING',
                            'invoice_url' => 'https://two.test/invoice/456',
                            'invoice_details' => ['id' => 'inv-456'],
                            'terms' => [
                                'type' => 'NET_TERMS',
                                'duration_days' => 60,
                                'duration_days_calculated_from' => null,
                            ],
                        ],
                    ];
                }

                return ['http_status' => 500];
            }

            public function setTwoOrderPaymentData($id_order, $payment_data)
            {
                $this->lastSavedOrderId = (int)$id_order;
                $this->lastSavedPaymentData = $payment_data;
            }

            public function syncAdminDataForTest($id_order, $twopaymentdata)
            {
                return $this->syncTwoAdminOrderPaymentDataFromProvider($id_order, $twopaymentdata);
            }
        };

        $base = [
            'id_order' => 56,
            'two_order_id' => 'two-456',
            'two_order_reference' => '',
            'two_order_state' => '',
            'two_order_status' => '',
            'two_day_on_invoice' => '',
            'two_payment_term_type' => '',
            'two_invoice_url' => '',
            'two_invoice_id' => '',
        ];

        $synced = $module->syncAdminDataForTest(56, $base);

        TinyAssert::same('60', (string)$synced['two_day_on_invoice']);
        TinyAssert::same('STANDARD', (string)$synced['two_payment_term_type']);
        TinyAssert::same('MR-456', (string)$synced['two_order_reference']);
        TinyAssert::same(56, (int)$module->lastSavedOrderId);
    }

    private static function testSyncTwoAdminOrderPaymentDataFromProviderRecoversMissingTwoOrderIdFromAttempt(): void
    {
        self::reset();
        $module = new class extends TwopaymentTestHarness {
            public $lastSavedOrderId = null;
            public $lastSavedPaymentData = null;

            protected function getLatestTwoCheckoutAttemptByOrder($id_order)
            {
                return array(
                    'two_order_id' => 'two-789',
                );
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                if ($method === 'GET' && $endpoint === '/v1/order/two-789') {
                    return [
                        'http_status' => Twopayment::HTTP_STATUS_OK,
                        'id' => 'two-789',
                        'merchant_reference' => 'MR-789',
                        'state' => 'CONFIRMED',
                        'status' => 'PENDING',
                        'terms' => [
                            'type' => 'NET_TERMS',
                            'duration_days' => 60,
                            'duration_days_calculated_from' => null,
                        ],
                    ];
                }

                return ['http_status' => 500];
            }

            public function setTwoOrderPaymentData($id_order, $payment_data)
            {
                $this->lastSavedOrderId = (int)$id_order;
                $this->lastSavedPaymentData = $payment_data;
            }

            public function syncAdminDataForTest($id_order, $twopaymentdata)
            {
                return $this->syncTwoAdminOrderPaymentDataFromProvider($id_order, $twopaymentdata);
            }
        };

        $base = [
            'id_order' => 57,
            'two_order_id' => '',
            'two_order_reference' => '',
            'two_order_state' => '',
            'two_order_status' => '',
            'two_day_on_invoice' => '',
            'two_payment_term_type' => '',
            'two_invoice_url' => '',
            'two_invoice_id' => '',
        ];

        $synced = $module->syncAdminDataForTest(57, $base);

        TinyAssert::same('two-789', (string)$synced['two_order_id']);
        TinyAssert::same('60', (string)$synced['two_day_on_invoice']);
        TinyAssert::same('STANDARD', (string)$synced['two_payment_term_type']);
        TinyAssert::same(57, (int)$module->lastSavedOrderId);
    }

    private static function testGetLatestTwoCheckoutAttemptByOrderSelectsTwoOrderIdForFallbackRecovery(): void
    {
        self::reset();
        StubStore::$dbExecuteSResponses[] = array(
            array(
                'two_order_id' => 'two-fallback-1',
                'two_day_on_invoice' => '60',
                'two_payment_term_type' => 'STANDARD',
                'two_order_state' => 'CONFIRMED',
                'two_order_status' => 'PENDING',
                'two_invoice_url' => '',
                'two_invoice_id' => '',
            ),
        );

        $module = new class extends TwopaymentTestHarness {
            public function getLatestAttemptForTest($id_order)
            {
                return $this->getLatestTwoCheckoutAttemptByOrder($id_order);
            }
        };

        $latest = $module->getLatestAttemptForTest(57);

        TinyAssert::true(is_array($latest));
        TinyAssert::same('two-fallback-1', (string)$latest['two_order_id']);
        TinyAssert::true(!empty(StubStore::$dbLastExecuteS));
        TinyAssert::true(strpos(StubStore::$dbLastExecuteS[0], '`two_order_id`') !== false);
        TinyAssert::true(strpos(StubStore::$dbLastExecuteS[0], '`id_order` = 57') !== false);
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
