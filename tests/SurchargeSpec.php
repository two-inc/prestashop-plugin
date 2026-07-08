<?php

declare(strict_types=1);

/**
 * Offset pricing fee (buyer surcharge) + brand-driven rounding relay.
 * TWO-24752 (offset fee) and TWO-24893 (rounding basis + brand step).
 *
 * Covers: buyer_fee_share payload construction per term, the rounding relay
 * and its edge cases, the fee-quote fetch (fail-soft), fee-line construction
 * with API tax-rate passthrough, and end-to-end injection into the order
 * payload including a rounding-boundary amount.
 */
final class SurchargeSpec
{
    public static function runAll(): void
    {
        self::testBuildBuyerFeeShareReturnsNullWhenDisabled();
        self::testBuildBuyerFeeSharePercentageOnly();
        self::testBuildBuyerFeeShareFixedOnlyOmitsPercentageCapAndRounding();
        self::testBuildBuyerFeeShareFixedAndPercentage();
        self::testBuildBuyerFeeShareCapOnlyWithPositiveLimit();
        self::testBuildBuyerFeeShareRoundingOmittedForFixedOnly();
        self::testBuildBuyerFeeShareDifferentialAddsReferenceTerms();
        self::testBuildBuyerFeeShareDifferentialReferenceTermsHonorsEndOfMonth();
        self::testBuildRoundingMapsBasisAndKeepsStep();
        self::testBuildRoundingOmittedForNoneUnmappedOrNonPositiveStep();
        self::testBuildTermsBlockEndOfMonth();
        self::testNormalizeTypeFallsBackToNone();
        self::testGetSurchargeSettingsReadsConfigGrid();
        self::testBuildTwoBuyerFeeShareWiresConfigAndDefaultTerm();
        self::testRoundingStepOptionsAreBrandDrivenSortedAndFormatted();
        self::testSurchargeLineLabelTemplateBrandAndDefault();
        self::testFetchTermFeeFailsSoftOnHttpError();
        self::testFetchTermFeeFailsSoftOnCurrencyMismatch();
        self::testFetchTermFeeParsesSuccess();
        self::testSurchargeLineItemPassesThroughApiTaxRate();
        self::testSurchargeLineItemDisabledReturnsNull();
        self::testSurchargeLineItemRoundsTaxOnBoundary();
        self::testOrderPayloadInjectsSurchargeLineAndBumpsTotals();
    }

    private static function reset(): void
    {
        StubStore::reset();
    }

    /* ---- TwoSurchargeCalculator (pure) ---- */

    private static function testBuildBuyerFeeShareReturnsNullWhenDisabled(): void
    {
        TinyAssert::same(null, TwoSurchargeCalculator::buildBuyerFeeShare(['type' => 'none'], 30, 30, false));
        TinyAssert::same(null, TwoSurchargeCalculator::buildBuyerFeeShare(['type' => 'garbage'], 30, 30, false));
    }

    private static function testBuildBuyerFeeSharePercentageOnly(): void
    {
        $settings = [
            'type' => 'percentage',
            'differential' => false,
            'grid' => [30 => ['percentage' => 2.5, 'fixed' => 0, 'limit' => 0]],
            'rounding_basis' => 'none',
            'rounding_step' => null,
        ];
        $share = TwoSurchargeCalculator::buildBuyerFeeShare($settings, 30, 30, false);
        TinyAssert::same(2.5, $share['percentage']);
        TinyAssert::same('buyer_pays', $share['surcharge_basis']);
        TinyAssert::false(isset($share['surcharge']), 'percentage-only must not send a fixed surcharge');
        TinyAssert::false(isset($share['cap']), 'no cap when limit is 0');
        TinyAssert::false(isset($share['rounding']), 'no rounding block when basis is none');
        TinyAssert::false(isset($share['reference_terms']), 'no reference_terms outside differential mode');
    }

    private static function testBuildBuyerFeeShareFixedOnlyOmitsPercentageCapAndRounding(): void
    {
        $settings = [
            'type' => 'fixed',
            'differential' => false,
            'grid' => [30 => ['percentage' => 5, 'fixed' => 4.5, 'limit' => 10]],
            'rounding_basis' => 'up',
            'rounding_step' => 1.0,
        ];
        $share = TwoSurchargeCalculator::buildBuyerFeeShare($settings, 30, 30, false);
        TinyAssert::same(0.0, $share['percentage'], 'fixed-only sends 0.0 so the API 100% default never applies');
        TinyAssert::same(4.5, $share['surcharge']);
        TinyAssert::false(isset($share['cap']), 'fixed-only must not leak a stored cap');
        TinyAssert::false(isset($share['rounding']), 'fixed-only must not leak a stored rounding');
    }

    private static function testBuildBuyerFeeShareFixedAndPercentage(): void
    {
        $settings = [
            'type' => 'fixed_and_percentage',
            'differential' => false,
            'grid' => [30 => ['percentage' => 1.5, 'fixed' => 2.0, 'limit' => 0]],
            'rounding_basis' => 'none',
            'rounding_step' => null,
        ];
        $share = TwoSurchargeCalculator::buildBuyerFeeShare($settings, 30, 30, false);
        TinyAssert::same(1.5, $share['percentage']);
        TinyAssert::same(2.0, $share['surcharge']);
    }

    private static function testBuildBuyerFeeShareCapOnlyWithPositiveLimit(): void
    {
        $settings = [
            'type' => 'percentage',
            'differential' => false,
            'grid' => [30 => ['percentage' => 3, 'fixed' => 0, 'limit' => 12.5]],
            'rounding_basis' => 'none',
            'rounding_step' => null,
        ];
        $share = TwoSurchargeCalculator::buildBuyerFeeShare($settings, 30, 30, false);
        TinyAssert::same(12.5, $share['cap']);
    }

    private static function testBuildBuyerFeeShareRoundingOmittedForFixedOnly(): void
    {
        $settings = [
            'type' => 'fixed',
            'differential' => false,
            'grid' => [30 => ['fixed' => 3.0]],
            'rounding_basis' => 'standard',
            'rounding_step' => 0.5,
        ];
        $share = TwoSurchargeCalculator::buildBuyerFeeShare($settings, 30, 30, false);
        TinyAssert::false(isset($share['rounding']), 'rounding is percentage-modes only');
    }

    private static function testBuildBuyerFeeShareDifferentialAddsReferenceTerms(): void
    {
        $settings = [
            'type' => 'percentage',
            'differential' => true,
            'grid' => [60 => ['percentage' => 4]],
            'rounding_basis' => 'none',
            'rounding_step' => null,
        ];
        $share = TwoSurchargeCalculator::buildBuyerFeeShare($settings, 60, 30, false);
        TinyAssert::same(['type' => 'NET_TERMS', 'duration_days' => 30], $share['reference_terms']);
    }

    private static function testBuildBuyerFeeShareDifferentialReferenceTermsHonorsEndOfMonth(): void
    {
        $settings = [
            'type' => 'percentage',
            'differential' => true,
            'grid' => [60 => ['percentage' => 4]],
            'rounding_basis' => 'none',
            'rounding_step' => null,
        ];
        $share = TwoSurchargeCalculator::buildBuyerFeeShare($settings, 60, 30, true);
        TinyAssert::same('END_OF_MONTH', $share['reference_terms']['duration_days_calculated_from']);
    }

    private static function testBuildRoundingMapsBasisAndKeepsStep(): void
    {
        TinyAssert::same(['step' => 1.0, 'basis' => 'UP'], TwoSurchargeCalculator::buildRounding('up', 1.0));
        TinyAssert::same(['step' => 0.5, 'basis' => 'DOWN'], TwoSurchargeCalculator::buildRounding('down', 0.5));
        TinyAssert::same(['step' => 10.0, 'basis' => 'STANDARD'], TwoSurchargeCalculator::buildRounding('standard', 10.0));
    }

    private static function testBuildRoundingOmittedForNoneUnmappedOrNonPositiveStep(): void
    {
        TinyAssert::same(null, TwoSurchargeCalculator::buildRounding('none', 1.0));
        TinyAssert::same(null, TwoSurchargeCalculator::buildRounding('sideways', 1.0));
        TinyAssert::same(null, TwoSurchargeCalculator::buildRounding('up', null));
        TinyAssert::same(null, TwoSurchargeCalculator::buildRounding('up', 0.0));
        TinyAssert::same(null, TwoSurchargeCalculator::buildRounding('up', -1.0));
    }

    private static function testBuildTermsBlockEndOfMonth(): void
    {
        TinyAssert::same(['type' => 'NET_TERMS', 'duration_days' => 45], TwoSurchargeCalculator::buildTermsBlock(45, false));
        TinyAssert::same(
            ['type' => 'NET_TERMS', 'duration_days' => 45, 'duration_days_calculated_from' => 'END_OF_MONTH'],
            TwoSurchargeCalculator::buildTermsBlock(45, true)
        );
    }

    private static function testNormalizeTypeFallsBackToNone(): void
    {
        TinyAssert::same('percentage', TwoSurchargeCalculator::normalizeType('percentage'));
        TinyAssert::same('none', TwoSurchargeCalculator::normalizeType(''));
        TinyAssert::same('none', TwoSurchargeCalculator::normalizeType('wat'));
    }

    /* ---- Module wiring ---- */

    private static function testGetSurchargeSettingsReadsConfigGrid(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'fixed_and_percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_DIFFERENTIAL', 1);
        Configuration::updateValue('PS_TWO_SURCHARGE_ROUNDING_BASIS', 'up');
        Configuration::updateValue('PS_TWO_SURCHARGE_ROUNDING_STEP', '1.00');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2.5');
        Configuration::updateValue('PS_TWO_SURCHARGE_FIXED_30', '3');
        Configuration::updateValue('PS_TWO_SURCHARGE_CAP_30', '9');
        $module = new TwopaymentTestHarness();
        $settings = $module->getTwoSurchargeSettings();
        TinyAssert::same('fixed_and_percentage', $settings['type']);
        TinyAssert::true($settings['enabled']);
        TinyAssert::true($settings['differential']);
        TinyAssert::same(1.0, $settings['rounding_step']);
        TinyAssert::same(2.5, $settings['grid'][30]['percentage']);
        TinyAssert::same(3.0, $settings['grid'][30]['fixed']);
        TinyAssert::same(9.0, $settings['grid'][30]['limit']);
    }

    private static function testBuildTwoBuyerFeeShareWiresConfigAndDefaultTerm(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_DIFFERENTIAL', 1);
        Configuration::updateValue('PS_TWO_SURCHARGE_ROUNDING_BASIS', 'standard');
        Configuration::updateValue('PS_TWO_SURCHARGE_ROUNDING_STEP', '0.50');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2');
        $module = new TwopaymentTestHarness();
        $share = $module->buildTwoBuyerFeeShare(30);
        TinyAssert::same(2.0, $share['percentage']);
        TinyAssert::same(['step' => 0.5, 'basis' => 'STANDARD'], $share['rounding']);
        // Only term 30 is offered, so the differential reference term is 30.
        TinyAssert::same(['type' => 'NET_TERMS', 'duration_days' => 30], $share['reference_terms']);
    }

    private static function testRoundingStepOptionsAreBrandDrivenSortedAndFormatted(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $options = $module->getTwoRoundingStepOptions();
        TinyAssert::same(['0.10', '0.50', '1.00', '5.00', '10.00'], array_keys($options));
        TinyAssert::same('10.00', $options['10.00']);
    }

    private static function testSurchargeLineLabelTemplateBrandAndDefault(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        // Default (no config, brand label null).
        TinyAssert::same('Service charge', $module->getTwoSurchargeLineLabel(30));
        // Merchant template with %s term substitution.
        Configuration::updateValue('PS_TWO_SURCHARGE_LINE_DESC', 'Financing fee (%s days)');
        TinyAssert::same('Financing fee (30 days)', $module->getTwoSurchargeLineLabel(30));
    }

    private static function testFetchTermFeeFailsSoftOnHttpError(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2');
        $module = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                return ['http_status' => 500, 'error' => 'boom'];
            }
        };
        TinyAssert::same(null, $module->fetchTwoTermFee(30, 100.0, 'NO', 'NOK'));
    }

    private static function testFetchTermFeeFailsSoftOnCurrencyMismatch(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2');
        $module = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                return ['http_status' => 200, 'buyer_fee_share' => '5.00', 'currency' => 'SEK'];
            }
        };
        TinyAssert::same(null, $module->fetchTwoTermFee(30, 100.0, 'NO', 'NOK'));
    }

    private static function testFetchTermFeeParsesSuccess(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2');
        $module = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                return [
                    'http_status' => 200,
                    'buyer_fee_share' => '7.50',
                    'total_fee_tax_rate' => '0.25',
                    'currency' => 'NOK',
                ];
            }
        };
        $fee = $module->fetchTwoTermFee(30, 100.0, 'NO', 'NOK');
        TinyAssert::same('7.50', $fee['buyer_fee_share']);
        TinyAssert::same('0.25', $fee['total_fee_tax_rate']);
        TinyAssert::same('NOK', $fee['currency']);
    }

    private static function testSurchargeLineItemPassesThroughApiTaxRate(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2');
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[900] = ['id_country' => 34, 'loaded' => true];
        $module = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                // API returns the tax rate as a percentage form (25) — the fee
                // line must normalise to the decimal convention, never zero.
                return [
                    'http_status' => 200,
                    'buyer_fee_share' => '5.00',
                    'total_fee_tax_rate' => '25',
                    'currency' => 'EUR',
                ];
            }
        };
        $cart = new Cart(1);
        $cart->id_currency = 978;
        $cart->id_address_invoice = 900;
        $line = $module->buildTwoSurchargeLineItemForCart($cart, 100.0);
        TinyAssert::same('SERVICE', $line['type']);
        TinyAssert::same('Service charge', $line['name']);
        TinyAssert::same('5.00', $line['net_amount']);
        TinyAssert::same('0.25', $line['tax_rate'], 'API tax rate passes through (percentage normalised), never hard-coded zero');
        TinyAssert::same('1.25', $line['tax_amount']);
        TinyAssert::same('6.25', $line['gross_amount']);
    }

    private static function testSurchargeLineItemDisabledReturnsNull(): void
    {
        self::reset();
        $module = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                throw new RuntimeException('must not call the pricing API when surcharge disabled');
            }
        };
        $cart = new Cart(1);
        $cart->id_currency = 978;
        TinyAssert::same(null, $module->buildTwoSurchargeLineItemForCart($cart, 100.0));
    }

    private static function testSurchargeLineItemRoundsTaxOnBoundary(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2');
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        $module = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                // net 10.10 * 0.25 = 2.525 → lands exactly on a rounding
                // boundary; must round half-up to 2.53 and keep gross = net+tax.
                return [
                    'http_status' => 200,
                    'buyer_fee_share' => '10.10',
                    'total_fee_tax_rate' => '0.25',
                    'currency' => 'EUR',
                ];
            }
        };
        $cart = new Cart(1);
        $cart->id_currency = 978;
        $line = $module->buildTwoSurchargeLineItemForCart($cart, 500.0);
        TinyAssert::same('10.10', $line['net_amount']);
        TinyAssert::same('2.53', $line['tax_amount']);
        TinyAssert::same('12.63', $line['gross_amount']);
        // The constructed line must satisfy the Two line-item formulas.
        TinyAssert::true($module->validateTwoLineItems([$line]));
    }

    private static function testOrderPayloadInjectsSurchargeLineAndBumpsTotals(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '5');

        StubStore::$customers[7001] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Eva',
            'lastname' => 'Martin',
            'secure_key' => 'secure-key-7001',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[7101] = [
            'id_country' => 33,
            'company' => 'Acme FR SAS',
            'companyid' => 'FR123456789',
            'address1' => '10 Rue de Paris',
            'city' => 'Paris',
            'postcode' => '75001',
            'phone' => '+33100000000',
            'loaded' => true,
        ];
        StubStore::$addresses[7102] = StubStore::$addresses[7101];
        StubStore::$countries[33] = 'FR';

        $cart = new Cart(7001);
        $cart->id_customer = 7001;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 7101;
        $cart->id_address_delivery = 7102;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[7001] = [[
            'id_product' => 9301,
            'link_rewrite' => 'reduced-vat-item',
            'name' => 'Reduced VAT item',
            'description_short' => 'Reduced VAT test',
            'manufacturer_name' => 'ACME',
            'ean13' => '',
            'upc' => '',
            'total' => 100.00,
            'total_wt' => 105.50,
            'cart_quantity' => 1,
            'rate' => 5.5,
            'price' => 100.00,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[9301] = [['name' => 'Books']];
        StubStore::$images[9301] = ['id_image' => 9301];
        StubStore::$cartTotals[7001] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.0,
                Cart::BOTH => 105.50,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.0,
                Cart::BOTH => 100.00,
            ],
            'average_products_tax_rate' => 5.5,
        ];

        $module = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                return [
                    'http_status' => 200,
                    'buyer_fee_share' => '5.00',
                    'total_fee_tax_rate' => '0.25',
                    'currency' => 'EUR',
                ];
            }
        };

        $payload = $module->getTwoNewOrderData('merchant-attempt-7001', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        // Fee line appended: product (105.50) + fee gross (6.25) = 111.75.
        TinyAssert::same('111.75', $payload['gross_amount']);
        TinyAssert::same('105.00', $payload['net_amount']);
        TinyAssert::same('6.75', $payload['tax_amount']);

        $feeLines = array_values(array_filter($payload['line_items'], function ($item) {
            return isset($item['type']) && $item['type'] === 'SERVICE' && $item['name'] === 'Service charge';
        }));
        TinyAssert::count(1, $feeLines);
        TinyAssert::same('5.00', $feeLines[0]['net_amount']);
        TinyAssert::same('0.25', $feeLines[0]['tax_rate']);
        TinyAssert::same('6.25', $feeLines[0]['gross_amount']);
    }
}
