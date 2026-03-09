<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OrderBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        StubStore::reset();
    }

    public function testValidateTwoLineItemsRejectsBrokenTaxFormula(): void
    {
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

        self::assertFalse($module->validateTwoLineItems($lineItems));
    }

    public function testGetTwoTaxSubtotalsKeepsDecimalTaxRatePrecision(): void
    {
        $module = new TwopaymentTestHarness();

        $lineItems = [
            ['tax_rate' => '0.205', 'net_amount' => '100.00', 'tax_amount' => '20.50'],
            ['tax_rate' => '0.205', 'net_amount' => '50.00', 'tax_amount' => '10.25'],
            ['tax_rate' => '0.21', 'net_amount' => '200.00', 'tax_amount' => '42.00'],
        ];

        $subtotals = $module->getTwoTaxSubtotals($lineItems);

        self::assertSame('0.205', $subtotals[0]['tax_rate']);
        self::assertSame('150.00', $subtotals[0]['taxable_amount']);
        self::assertSame('30.75', $subtotals[0]['tax_amount']);

        self::assertSame('0.21', $subtotals[1]['tax_rate']);
        self::assertSame('200.00', $subtotals[1]['taxable_amount']);
        self::assertSame('42.00', $subtotals[1]['tax_amount']);
    }

    public function testGetTwoProductItemsUsesAppliedTaxRateWhenConfiguredRateDiffers(): void
    {
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

        self::assertCount(1, $items);
        self::assertSame('0.205', $items[0]['tax_rate']);
        self::assertSame('20.50', $items[0]['tax_amount']);
        self::assertSame('120.50', $items[0]['gross_amount']);
    }

    public function testGetTwoNewOrderDataOmitsTopLevelTaxRate(): void
    {
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

        self::assertArrayNotHasKey('tax_rate', $payload);
        self::assertArrayHasKey('tax_subtotals', $payload);
        self::assertSame('100.00', $payload['net_amount']);
        self::assertSame('20.50', $payload['tax_amount']);
        self::assertSame('120.50', $payload['gross_amount']);
    }

    public function testGetTwoNewOrderDataOmitsTaxSubtotalsWhenDisabled(): void
    {
        StubStore::$configuration['PS_TWO_ENABLE_TAX_SUBTOTALS'] = 0;

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

        StubStore::$customers[401] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Juan',
            'lastname' => 'Gonzalez',
            'secure_key' => 'secure-key',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[601] = [
            'id_country' => 34,
            'company' => 'Acme S.L.',
            'companyid' => 'B12345678',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];
        StubStore::$addresses[602] = StubStore::$addresses[601];

        $cart = new Cart(155);
        $cart->id_customer = 401;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 601;
        $cart->id_address_delivery = 602;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[155] = [['id_product' => 601, 'cart_quantity' => 1]];
        StubStore::$cartTotals[155] = [
            true => [Cart::ONLY_DISCOUNTS => 0.0],
            false => [Cart::ONLY_DISCOUNTS => 0.0],
            'average_products_tax_rate' => 21.0,
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-155', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        self::assertArrayNotHasKey('tax_subtotals', $payload);
        self::assertArrayNotHasKey('tax_rate', $payload);
    }

    public function testGetTwoIntentOrderDataOmitsTopLevelTaxRateAndOmitsTaxSubtotalsWhenDisabled(): void
    {
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
        };

        StubStore::$customers[402] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Ana',
            'lastname' => 'Lopez',
            'secure_key' => 'secure-key-intent',
            'loaded' => true,
        ];
        StubStore::$currencies[840] = ['iso_code' => 'USD', 'loaded' => true];
        StubStore::$addresses[603] = [
            'id_country' => 34,
            'company' => 'Acme S.L.',
            'companyid' => 'B12345678',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];

        $cart = new Cart(156);
        $cart->id_customer = 402;
        $cart->id_currency = 840;
        $cart->id_address_invoice = 603;
        $cart->id_address_delivery = 603;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[156] = [['id_product' => 602, 'cart_quantity' => 1]];
        StubStore::$cartTotals[156] = [
            true => [Cart::ONLY_DISCOUNTS => 0.0],
            false => [Cart::ONLY_DISCOUNTS => 0.0],
            'average_products_tax_rate' => 21.0,
        ];

        $customer = new Customer(402);
        $currency = new Currency(840);
        $address = new Address(603);

        $payloadWithSubtotals = $module->getTwoIntentOrderData($cart, $customer, $currency, $address);
        self::assertArrayNotHasKey('tax_rate', $payloadWithSubtotals);
        self::assertArrayHasKey('tax_subtotals', $payloadWithSubtotals);

        StubStore::$configuration['PS_TWO_ENABLE_TAX_SUBTOTALS'] = 0;
        $payloadWithoutSubtotals = $module->getTwoIntentOrderData($cart, $customer, $currency, $address);
        self::assertArrayNotHasKey('tax_rate', $payloadWithoutSubtotals);
        self::assertArrayNotHasKey('tax_subtotals', $payloadWithoutSubtotals);
    }

    public function testGetTwoNewOrderDataThrowsWhenLineItemsFailFormulaValidation(): void
    {
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

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid line item formulas');

        $module->getTwoNewOrderData('merchant-attempt-56', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);
    }

    public function testSnapshotHashChangesWhenTaxRateChangesBeyondTwoDecimals(): void
    {
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

        self::assertNotSame($hashA, $hashB);
    }

    public function testIsTwoAttemptCallbackAuthorizedWithMatchingKey(): void
    {
        $module = new TwopaymentTestHarness();

        $attempt = [
            'id_customer' => 77,
            'customer_secure_key' => 'secure-key-77',
        ];

        self::assertTrue($module->isTwoAttemptCallbackAuthorized($attempt, 'secure-key-77'));
    }

    public function testIsTwoAttemptCallbackAuthorizedFallsBackToContextCustomerKeyWhenRequestKeyMissing(): void
    {
        $module = new TwopaymentTestHarness();

        $attempt = [
            'id_customer' => 99,
            'customer_secure_key' => 'secure-key-99',
        ];

        self::assertTrue($module->isTwoAttemptCallbackAuthorized($attempt, '', 99, 'secure-key-99'));
    }

    public function testIsTwoAttemptCallbackAuthorizedRejectsMismatchedKeys(): void
    {
        $module = new TwopaymentTestHarness();

        $attempt = [
            'id_customer' => 42,
            'customer_secure_key' => 'secure-key-42',
        ];

        self::assertFalse($module->isTwoAttemptCallbackAuthorized($attempt, 'invalid-key', 42, 'secure-key-42'));
        self::assertFalse($module->isTwoAttemptCallbackAuthorized($attempt, '', 41, 'secure-key-42'));
    }

    public function testGetTwoCheckoutCompanyDataUsesAddressVatNumberForAnyCountry(): void
    {
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

        self::assertSame('Acme UK Ltd', $data['company_name']);
        self::assertSame('123456789', $data['organization_number']);
        self::assertSame('GB', $data['country_iso']);
    }

    public function testGetTwoCheckoutCompanyDataPrefersCurrentAddressOrgNumberOverSessionCompany(): void
    {
        $module = new TwopaymentTestHarness();

        $module->context->cookie->two_company_name = 'CHEESE AND BEES LTD';
        $module->context->cookie->two_company_id = 'SC806781';
        $module->context->cookie->two_company_country = 'GB';
        $module->context->cookie->two_company_address_id = '28';

        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[29] = [
            'id_country' => 34,
            'company' => 'Queso y Abejas S.L.',
            'vat_number' => 'ESB12345678',
            'loaded' => true,
        ];

        $address = new Address(29);
        $data = $module->getTwoCheckoutCompanyData($address);

        self::assertSame('Queso y Abejas S.L.', $data['company_name']);
        self::assertSame('B12345678', $data['organization_number']);
        self::assertSame('ES', $data['country_iso']);
    }

    public function testGetTwoCheckoutCompanyDataUsesValidatedCookieFallback(): void
    {
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

        self::assertSame('Acme ES S.L.', $data['company_name']);
        self::assertSame('B12345678', $data['organization_number']);
        self::assertSame('ES', $data['country_iso']);
    }

    public function testGetTwoCheckoutCompanyDataClearsStaleCookieOnCountryMismatch(): void
    {
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

        self::assertSame('', $data['company_name']);
        self::assertSame('', $data['organization_number']);
        self::assertSame('ES', $data['country_iso']);
        self::assertFalse(isset($module->context->cookie->two_company_name));
        self::assertFalse(isset($module->context->cookie->two_company_id));
        self::assertFalse(isset($module->context->cookie->two_company_country));
    }

    public function testGetTwoCheckoutCompanyDataIgnoresStaleCookieWhenAddressCompanyChangesSameCountry(): void
    {
        $module = new TwopaymentTestHarness();
        $module->context->cookie->two_company_name = 'Acme ES S.L.';
        $module->context->cookie->two_company_id = 'B12345678';
        $module->context->cookie->two_company_country = 'ES';
        $module->context->cookie->two_company_address_id = '999';

        StubStore::$addresses[804] = [
            'id_country' => 34,
            'company' => 'Beta Industrial S.L.',
            'loaded' => true,
        ];

        $address = new Address(804);
        $data = $module->getTwoCheckoutCompanyData($address);

        self::assertSame('Beta Industrial S.L.', $data['company_name']);
        self::assertSame('', $data['organization_number']);
        self::assertSame('ES', $data['country_iso']);
    }

    public function testSaveGeneralFormDoesNotChangeSslVerificationFlag(): void
    {
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

        self::assertSame(1, (int) Configuration::get('PS_TWO_DISABLE_SSL_VERIFY'));
    }

    public function testSaveOtherFormUpdatesSslVerificationFlag(): void
    {
        $module = new class extends TwopaymentTestHarness {
            public function saveOtherForTest(): void
            {
                $this->saveTwoOtherFormValues();
            }
        };

        Configuration::updateValue('PS_TWO_DISABLE_SSL_VERIFY', 0);
        Configuration::updateValue('PS_TWO_ENABLE_TAX_SUBTOTALS', 1);
        Tools::setTestValue('PS_TWO_DISABLE_SSL_VERIFY', 1);
        Tools::setTestValue('PS_TWO_ENABLE_TAX_SUBTOTALS', 0);

        $module->saveOtherForTest();

        self::assertSame(1, (int) Configuration::get('PS_TWO_DISABLE_SSL_VERIFY'));
        self::assertSame(0, (int) Configuration::get('PS_TWO_ENABLE_TAX_SUBTOTALS'));
    }

    public function testOtherSettingsFormDoesNotExposeOrderIntentToggle(): void
    {
        $module = new class extends TwopaymentTestHarness {
            public function getOtherFormForTest(): array
            {
                return $this->getTwoOtherForm();
            }
        };

        $form = $module->getOtherFormForTest();
        $inputNames = array_map(function ($field) {
            return isset($field['name']) ? (string) $field['name'] : '';
        }, $form['form']['input']);

        self::assertNotContains('PS_TWO_ENABLE_ORDER_INTENT', $inputNames);
        self::assertContains('PS_TWO_ENABLE_TAX_SUBTOTALS', $inputNames);
    }

    public function testHookActionAdminControllerSetMediaRegistersCssOnModuleConfigPage(): void
    {
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

        self::assertCount(1, $controller->styles);
        self::assertSame('module-twopayment-admin-css', $controller->styles[0]['id']);
    }

    public function testHookActionAdminControllerSetMediaSkipsUnrelatedAdminPage(): void
    {
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

        self::assertCount(0, $controller->styles);
    }

    public function testHookPaymentOptionsAllowsBusinessFallbackWhenAccountTypeMissing(): void
    {
        $module = new class extends TwopaymentTestHarness {
            protected function getTwoPaymentOption()
            {
                return (object) ['method' => 'two'];
            }
        };

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

        self::assertCount(1, $options);
    }

    public function testHookPaymentOptionsBlocksNonBusinessWhenAccountTypePresent(): void
    {
        $module = new class extends TwopaymentTestHarness {
            protected function getTwoPaymentOption()
            {
                return (object) ['method' => 'two'];
            }
        };

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

        self::assertCount(0, $options);
    }

    public function testMergeTwoPaymentTermFallbackUsesFallbackWhenMissing(): void
    {
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

        self::assertSame('45', (string) $merged['two_day_on_invoice']);
        self::assertSame('EOM', (string) $merged['two_payment_term_type']);
    }

    public function testMergeTwoPaymentTermFallbackKeepsExistingValues(): void
    {
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

        self::assertSame('30', (string) $merged['two_day_on_invoice']);
        self::assertSame('STANDARD', (string) $merged['two_payment_term_type']);
    }

    public function testShouldExposeTwoInvoiceActionsRequiresFulfilledState(): void
    {
        $module = new TwopaymentTestHarness();

        self::assertTrue($module->shouldExposeTwoInvoiceActions(['two_order_state' => 'FULFILLED']));
        self::assertFalse($module->shouldExposeTwoInvoiceActions(['two_order_state' => 'VERIFIED']));
        self::assertFalse($module->shouldExposeTwoInvoiceActions(['two_order_state' => 'CONFIRMED']));
        self::assertFalse($module->shouldExposeTwoInvoiceActions(['two_order_state' => '']));
    }

    public function testResolveTwoPaymentTermsFromOrderResponseUsesEndOfMonthAsEom(): void
    {
        $module = new TwopaymentTestHarness();

        $response = [
            'terms' => [
                'duration_days' => 60,
                'duration_days_calculated_from' => 'END_OF_MONTH',
            ],
        ];

        $resolved = $module->resolveTwoPaymentTermsFromOrderResponse($response, '30', 'STANDARD');

        self::assertSame('60', (string)$resolved['two_day_on_invoice']);
        self::assertSame('EOM', (string)$resolved['two_payment_term_type']);
    }

    public function testResolveTwoPaymentTermsFromOrderResponseFallsBackToStandardForUnsupportedScheme(): void
    {
        $module = new TwopaymentTestHarness();

        $response = [
            'terms' => [
                'duration_days' => 45,
                'duration_days_calculated_from' => 'END_OF_WEEK',
            ],
        ];

        $resolved = $module->resolveTwoPaymentTermsFromOrderResponse($response, '30', 'EOM');

        self::assertSame('45', (string)$resolved['two_day_on_invoice']);
        self::assertSame('STANDARD', (string)$resolved['two_payment_term_type']);
    }

    public function testSyncTwoAdminOrderPaymentDataFromProviderPullsLatestTermsFromTwo(): void
    {
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

        self::assertSame('60', (string)$synced['two_day_on_invoice']);
        self::assertSame('EOM', (string)$synced['two_payment_term_type']);
        self::assertSame('CONFIRMED', (string)$synced['two_order_state']);
        self::assertSame('MR-123', (string)$synced['two_order_reference']);
        self::assertSame(55, (int)$module->lastSavedOrderId);
        self::assertSame('60', (string)$module->lastSavedPaymentData['two_day_on_invoice']);
    }

    public function testSyncTwoAdminOrderPaymentDataFromProviderSupportsNestedDataEnvelope(): void
    {
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

        self::assertSame('60', (string)$synced['two_day_on_invoice']);
        self::assertSame('STANDARD', (string)$synced['two_payment_term_type']);
        self::assertSame('MR-456', (string)$synced['two_order_reference']);
        self::assertSame(56, (int)$module->lastSavedOrderId);
    }

    public function testSyncTwoAdminOrderPaymentDataFromProviderRecoversMissingTwoOrderIdFromAttempt(): void
    {
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

        self::assertSame('two-789', (string)$synced['two_order_id']);
        self::assertSame('60', (string)$synced['two_day_on_invoice']);
        self::assertSame('STANDARD', (string)$synced['two_payment_term_type']);
        self::assertSame(57, (int)$module->lastSavedOrderId);
    }

    public function testGetLatestTwoCheckoutAttemptByOrderSelectsTwoOrderIdForFallbackRecovery(): void
    {
        StubStore::reset();
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

        self::assertIsArray($latest);
        self::assertSame('two-fallback-1', (string)$latest['two_order_id']);
        self::assertNotEmpty(StubStore::$dbLastExecuteS);
        self::assertStringContainsString('`two_order_id`', StubStore::$dbLastExecuteS[0]);
        self::assertStringContainsString('`id_order` = 57', StubStore::$dbLastExecuteS[0]);
    }

    public function testGetTwoValidatedSessionCompanyDataRejectsCountryMismatch(): void
    {
        $module = new TwopaymentTestHarness();
        $module->context->cookie->two_company_name = 'Acme Ltd';
        $module->context->cookie->two_company_id = 'NO123';
        $module->context->cookie->two_company_country = 'NO';

        $data = $module->getTwoValidatedSessionCompanyData('ES');

        self::assertSame('', $data['company_name']);
        self::assertSame('', $data['organization_number']);
        self::assertFalse(isset($module->context->cookie->two_company_name));
        self::assertFalse(isset($module->context->cookie->two_company_id));
        self::assertFalse(isset($module->context->cookie->two_company_country));
    }

    public function testGetTwoValidatedSessionCompanyDataRejectsLegacySessionWithoutCountryMarker(): void
    {
        $module = new TwopaymentTestHarness();
        $module->context->cookie->two_company_name = 'Acme Ltd';
        $module->context->cookie->two_company_id = 'NO123';

        $data = $module->getTwoValidatedSessionCompanyData('ES');

        self::assertSame('', $data['company_name']);
        self::assertSame('', $data['organization_number']);
        self::assertFalse(isset($module->context->cookie->two_company_name));
        self::assertFalse(isset($module->context->cookie->two_company_id));
    }

    public function testBuildTwoApiResponseLogSummaryRedactsNestedProviderPayload(): void
    {
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

        self::assertSame(400, $summary['http_status']);
        self::assertSame('two-order-1', $summary['two_order_id']);
        self::assertSame('CREATED', $summary['two_order_state']);
        self::assertSame('PENDING', $summary['two_order_status']);
        self::assertSame('merchant-ref-1', $summary['two_order_reference']);
        self::assertSame('validation_error', $summary['error']);
        self::assertFalse(isset($summary['data']));
        self::assertFalse(isset($summary['invoice_url']));
    }

    public function testGetTwoErrorMessageReturnsHttpFallbackForNonJsonProviderErrors(): void
    {
        $module = new TwopaymentTestHarness();

        $message = $module->getTwoErrorMessage([
            'http_status' => 502,
            'data' => null,
        ]);

        self::assertSame('Two response code 502', $message);
    }

    public function testGetTwoErrorMessageReadsNestedDataMessage(): void
    {
        $module = new TwopaymentTestHarness();

        $message = $module->getTwoErrorMessage([
            'http_status' => 400,
            'data' => [
                'error_message' => 'Validation failed',
            ],
        ]);

        self::assertSame('Validation failed', $message);
    }

    public function testGetTwoErrorMessageIgnoresSuccessMessagePayload(): void
    {
        $module = new TwopaymentTestHarness();

        $message = $module->getTwoErrorMessage([
            'http_status' => 200,
            'message' => 'Order confirmed',
        ]);

        self::assertNull($message);
    }

    public function testGetTwoProductItemsSkipsEmptyBarcodeEntries(): void
    {
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

        self::assertCount(1, $items);
        self::assertSame([], $items[0]['details']['barcodes']);
    }

    public function testExtractOrgNumberFromAddressKeepsNonCountryPrefixVatNumber(): void
    {
        $module = new TwopaymentTestHarness();

        StubStore::$countries[826] = 'GB';
        StubStore::$addresses[812] = [
            'id_country' => 826,
            'company' => 'Cheese Box Ltd',
            'vat_number' => 'SC806781',
            'loaded' => true,
        ];

        $address = new Address(812);
        $orgNumber = $module->extractOrgNumberFromAddress($address, 'GB');

        self::assertSame('SC806781', $orgNumber);
    }

    public function testExtractOrgNumberFromAddressStripsMatchingCountryPrefixVatNumber(): void
    {
        $module = new TwopaymentTestHarness();

        StubStore::$countries[826] = 'GB';
        StubStore::$addresses[813] = [
            'id_country' => 826,
            'company' => 'Cheese Box Ltd',
            'vat_number' => 'GB123456789',
            'loaded' => true,
        ];

        $address = new Address(813);
        $orgNumber = $module->extractOrgNumberFromAddress($address, 'GB');

        self::assertSame('123456789', $orgNumber);
    }
}
