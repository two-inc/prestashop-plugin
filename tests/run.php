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
        self::testGetTwoTaxSubtotalsNormalizesTaxRateToTwoDecimals();
        self::testGetTwoProductItemsUsesAppliedTaxRateWhenConfiguredRateDiffers();
        self::testGetTwoProductItemsSplitsEcotaxIntoServiceLine();
        self::testGetTwoNewOrderDataSupportsFivePointFivePercentVat();
        self::testGetTwoNewOrderDataIncludesGiftWrappingLine();
        self::testGetTwoNewOrderDataSnapsGiftWrappingRateToCanonicalContext();
        self::testGetTwoProductItemsSnapsMinorRateDriftToKnownProductContexts();
        self::testGetTwoProductItemsSpanishFallbackDefaultsToTwentyOnePercentWithoutKnownContext();
        self::testGetTwoNewOrderDataOmitsTopLevelTaxRate();
        self::testGetTwoNewOrderDataOmitsTaxSubtotalsWhenDisabled();
        self::testGetTwoIntentOrderDataOmitsTopLevelTaxRateAndOmitsTaxSubtotalsWhenDisabled();
        self::testGetTwoNewOrderDataThrowsWhenLineItemsFailFormulaValidation();
        self::testGetTwoNewOrderDataThrowsWhenCartTotalsMismatchIsMaterial();
        self::testGetTwoIntentOrderDataContinuesWhenCartTotalsDoNotReconcile();
        self::testGetTwoNewOrderDataAllowsTwoCentReconciliationDrift();
        self::testGetTwoNewOrderDataAllowsTwoCentBoundaryForLargeTotals();
        self::testGetTwoNewOrderDataBlocksThreeCentReconciliationDrift();
        self::testGetTwoNewOrderDataIncludesShippingAndDiscountLineItemsWhenReconciled();
        self::testGetTwoNewOrderDataFallbackFreeShippingUsesShippingTaxContext();
        self::testGetTwoNewOrderDataUsesCartRuleMonetaryValuesForDiscountLines();
        self::testGetTwoNewOrderDataHandlesMixedCartRuleMetadataWithPartialFallback();
        self::testGetTwoNewOrderDataMixedMetadataKeepsCompleteRuleValuesAndFreeShippingContext();
        self::testGetTwoNewOrderDataSpanishOddDecimalsKeepCanonicalTwentyOneDiscountRates();
        self::testGetTwoNewOrderDataSpanishTinyPartialFallbackKeepsCanonicalRates();
        self::testGetTwoNewOrderDataSnapsSmallDiscountRateToCanonicalContext();
        self::testGetTwoNewOrderDataKeepsDiscountTaxFormulaForLargeRoundedDiscounts();
        self::testMerchantCase1BuildsExpectedOrderPayload();
        self::testMerchantCase2BlocksOnInconsistentOrderTotals();
        self::testMerchantCase3BuildsSimpleOrderPayload();
        self::testGetTwoRequestHeadersSkipApiKeyForOrderIntent();
        self::testCheckTwoOrderIntentApprovalAtPaymentDeclinesEvenWhenFrontendCookieSaysApproved();
        self::testCheckTwoOrderIntentApprovalAtPaymentAllowsApprovedResponse();
        self::testCheckTwoOrderIntentApprovalAtPaymentHandlesProviderNetworkFailure();
        self::testCheckTwoOrderIntentApprovalAtPaymentBlocksOnStrictReconciliationDrift();
        self::testExtractTwoProviderGrossAmountForValidationSupportsRootAndNestedPayloads();
        self::testCreateTwoLocalOrderAfterProviderVerificationRecoversExistingOrderOnRace();
        self::testCreateTwoLocalOrderAfterProviderVerificationFailsWhenNoRecoverableOrderExists();
        self::testCancelTwoOrderBestEffortReturnsTrueOnSuccessAndFalseOnFailure();
        self::testSnapshotHashIgnoresTaxRateChangesBeyondTwoDecimals();
        self::testBuildTwoOrderCreateIdempotencyKeyIsDeterministicForSameSnapshot();
        self::testHasTwoOrderRebindingConflictDetectsMismatchedTwoOrderIds();
        self::testIsTwoAttemptCallbackAuthorizedWithMatchingKey();
        self::testIsTwoAttemptCallbackAuthorizedFallsBackToContextCustomerKeyWhenRequestKeyMissing();
        self::testIsTwoAttemptCallbackAuthorizedRejectsMismatchedKeys();
        self::testGetTwoBuyerPortalUrlUsesEnvironmentSpecificBuyerDomains();
        self::testResolveTwoAttemptOrderIdForCancellationPrefersAttemptOrderId();
        self::testResolveTwoAttemptOrderIdForCancellationFallsBackToCartOrderId();
        self::testShouldBlockTwoAttemptConfirmationByStatusOnlyForCancelled();
        self::testIsTwoAttemptStatusTerminalMatchesCancelledGuard();
        self::testGetTwoCancelledOrderStatusIdUsesConfiguredFallbackChain();
        self::testHasTwoProviderOrderMappingRequiresNonEmptyTwoOrderId();
        self::testSyncLocalOrderStatusFromTwoStateCancelsOnlyWhenProviderCancelled();
        self::testIsTwoOrderCancelledResponseRequires2xxAndCancelledState();
        self::testShouldBlockTwoFulfillmentByTwoStateOnlyForCancelled();
        self::testShouldBlockTwoStatusTransitionByCancelledStateCoversVerifiedAndFulfillment();
        self::testIsTwoOrderFulfillableStateRequiresConfirmed();
        self::testAddTwoBackOfficeWarningAppendsUniqueWarning();
        self::testAddTwoBackOfficeWarningReturnsFalseWhenNoController();
        self::testApplyTwoCancelledOrderStateProfileToStatusObjectUsesConfiguredCancelledState();
        self::testForceTwoCancelledOrderHistoryStateBeforeInsertRewritesPendingStatus();
        self::testGetTwoCheckoutCompanyDataUsesAddressVatNumberForAnyCountry();
        self::testGetTwoCheckoutCompanyDataPrefersCurrentAddressOrgNumberOverSessionCompany();
        self::testGetTwoCheckoutCompanyDataUsesValidatedCookieFallback();
        self::testGetTwoCheckoutCompanyDataClearsStaleCookieOnCountryMismatch();
        self::testGetTwoCheckoutCompanyDataIgnoresStaleCookieWhenAddressCompanyChangesSameCountry();
        self::testSaveGeneralFormDoesNotChangeSslVerificationFlag();
        self::testSaveOtherFormUpdatesSslVerificationFlag();
        self::testOtherSettingsFormDoesNotExposeOrderIntentToggle();
        self::testHookActionAdminControllerSetMediaRegistersCssOnModuleConfigPage();
        self::testHookActionAdminControllerSetMediaSkipsUnrelatedAdminPage();
        self::testHookPaymentOptionsBlocksWhenAccountTypeMissingInStrictMode();
        self::testHookPaymentOptionsBlocksNonBusinessWhenAccountTypePresent();
        self::testHookPaymentOptionsAllowsTwoCoveredCurrencies();
        self::testHookPaymentOptionsBlocksUnsupportedCurrency();
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
        self::testGetTwoProductItemsThrowsOnNegativeDiscount();
        self::testGetTwoProductItemsThrowsOnNegativeReduction();
        self::testGetTwoProductItemsAllowsPositiveDiscount();
        self::testGetTwoProductItemsToleratesUnitPriceRoundingDrift();
        self::testBrandConfigLoadsAndIsAccessibleFromModule();
        self::testAvailabilityGateAbsentForTwoBrand();
        self::testAvailabilityGateComparesNetAndHidesBelowMinimum();
        self::testAvailabilityGateConvertsViaPrestashopRates();
        self::testAvailabilityGateFailsClosedWithoutRate();
        self::testAvailabilityGateChecksBillingCountry();
        self::testAvailabilityGateGrossBasis();
        self::testMerchantMinimumGatesAlone();
        self::testPlatformMinimumConvertsToDefaultCurrency();
        self::testBrandPayloadIdentityOmittedForTwoBrand();
        self::testBrandPayloadIdentityAppliedWhenBrandSetsIt();
        self::testExtractOrgNumberFromAddressKeepsNonCountryPrefixVatNumber();
        self::testExtractOrgNumberFromAddressStripsMatchingCountryPrefixVatNumber();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
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

    private static function testGetTwoTaxSubtotalsNormalizesTaxRateToTwoDecimals(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $lineItems = [
            ['tax_rate' => '0.205', 'net_amount' => '100.00', 'tax_amount' => '20.50'],
            ['tax_rate' => '0.205', 'net_amount' => '50.00', 'tax_amount' => '10.25'],
            ['tax_rate' => '0.21', 'net_amount' => '200.00', 'tax_amount' => '42.00'],
        ];

        $subtotals = $module->getTwoTaxSubtotals($lineItems);

        TinyAssert::count(1, $subtotals);
        TinyAssert::same('0.21', $subtotals[0]['tax_rate']);
        TinyAssert::same('350.00', $subtotals[0]['taxable_amount']);
        TinyAssert::same('72.75', $subtotals[0]['tax_amount']);
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

    private static function testGetTwoProductItemsSplitsEcotaxIntoServiceLine(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $cart = new Cart(11);
        $cart->id_lang = 1;
        $cart->id_carrier = 999;

        StubStore::$cartProducts[11] = [[
            'id_product' => 777,
            'link_rewrite' => 'eco-product',
            'name' => 'Eco Product',
            'description_short' => 'Eco friendly',
            'manufacturer_name' => 'Green Co',
            'ean13' => '',
            'upc' => '',
            'total' => 110.00,
            'total_wt' => 131.55,
            'cart_quantity' => 1,
            'rate' => 21.0,
            'price' => 110.00,
            'reduction' => 0,
            'ecotax' => 10.00,
            'ecotax_tax_rate' => 5.5,
        ]];

        StubStore::$productCategories[777] = [['name' => 'Accessories']];
        StubStore::$images[777] = ['id_image' => 9011];

        $items = $module->getTwoProductItems($cart);

        TinyAssert::count(2, $items);
        TinyAssert::same('PHYSICAL', $items[0]['type']);
        TinyAssert::same('100.00', (string)$items[0]['net_amount']);
        TinyAssert::same('121.00', (string)$items[0]['gross_amount']);
        TinyAssert::same('21.00', (string)$items[0]['tax_amount']);
        TinyAssert::same('0.21', (string)$items[0]['tax_rate']);

        TinyAssert::same('SERVICE', $items[1]['type']);
        TinyAssert::same('10.00', (string)$items[1]['net_amount']);
        TinyAssert::same('10.55', (string)$items[1]['gross_amount']);
        TinyAssert::same('0.55', (string)$items[1]['tax_amount']);
        TinyAssert::same('0.055', (string)$items[1]['tax_rate']);
    }

    private static function testGetTwoProductItemsThrowsOnNegativeDiscount(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $cart = new Cart(12);
        $cart->id_lang = 1;
        $cart->id_carrier = 999;

        // quantity * unit_price (100.00) < net total (105.00) -> negative discount,
        // previously clamped to 0, now surfaced as an error
        StubStore::$cartProducts[12] = [[
            'id_product' => 888,
            'link_rewrite' => 'mispriced-product',
            'name' => 'Mispriced Product',
            'description_short' => 'Inconsistent pricing data',
            'manufacturer_name' => 'Acme',
            'ean13' => '',
            'upc' => '',
            'total' => 105.00,
            'total_wt' => 127.05,
            'cart_quantity' => 1,
            'rate' => 21.0,
            'price' => 100.00,
            'reduction' => 0,
        ]];

        StubStore::$productCategories[888] = [['name' => 'Misc']];
        StubStore::$images[888] = ['id_image' => 9012];

        TinyAssert::throws(
            static function () use ($module, $cart): void {
                $module->getTwoProductItems($cart);
            },
            'Negative discount calculated for product 888'
        );

        TinyAssert::count(1, PrestaShopLogger::$logs);
        TinyAssert::same(3, PrestaShopLogger::$logs[0]['severity']);
        TinyAssert::true(
            strpos(PrestaShopLogger::$logs[0]['message'], '100 = 100 < net total 105') !== false,
            'Log line must include the computed amounts: ' . PrestaShopLogger::$logs[0]['message']
        );
    }

    private static function testGetTwoProductItemsThrowsOnNegativeReduction(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $cart = new Cart(13);
        $cart->id_lang = 1;
        $cart->id_carrier = 999;

        // No unit price -> reduction branch; a negative reduction is a data
        // inconsistency, previously zeroed silently, now surfaced as an error
        StubStore::$cartProducts[13] = [[
            'id_product' => 889,
            'link_rewrite' => 'negative-reduction-product',
            'name' => 'Negative Reduction Product',
            'description_short' => 'Inconsistent reduction data',
            'manufacturer_name' => 'Acme',
            'ean13' => '',
            'upc' => '',
            'total' => 100.00,
            'total_wt' => 121.00,
            'cart_quantity' => 1,
            'rate' => 21.0,
            'reduction' => -2.00,
        ]];

        StubStore::$productCategories[889] = [['name' => 'Misc']];
        StubStore::$images[889] = ['id_image' => 9013];

        TinyAssert::throws(
            static function () use ($module, $cart): void {
                $module->getTwoProductItems($cart);
            },
            'Negative reduction for product 889'
        );

        TinyAssert::count(1, PrestaShopLogger::$logs);
        TinyAssert::same(3, PrestaShopLogger::$logs[0]['severity']);
    }

    private static function testBrandConfigLoadsAndIsAccessibleFromModule(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $brand = $module->getTwoBrand();

        TinyAssert::same('two', $brand['code']);
        TinyAssert::same('Two', $brand['product_name']);
        TinyAssert::same('Two - BNPL for businesses', $brand['display_name']);
        // The Two brand must not declare payload identity (payload parity
        // with the pre-brand-config module)
        TinyAssert::same('', $brand['vendor_name']);
    }

    private static function testBrandPayloadIdentityOmittedForTwoBrand(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $payload = $module->applyTwoBrandPayloadIdentityForTests(['currency' => 'EUR']);

        TinyAssert::same(['currency' => 'EUR'], $payload);
    }

    private static function testBrandPayloadIdentityAppliedWhenBrandSetsIt(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $module->setTwoBrand([
            'vendor_name' => 'Partner Vendor',
        ]);

        $payload = $module->applyTwoBrandPayloadIdentityForTests(['currency' => 'EUR']);

        TinyAssert::same('Partner Vendor', $payload['vendor_name']);
        // brand_tag is deliberately NOT a payload field: the Magento
        // reference uses it only as a checkout-URL query param.
        TinyAssert::false(isset($payload['brand_tag']));
    }

    private static function gateFixture(array $gate, float $netTotal, int $idCurrency, int $idCountry): array
    {
        $module = new TwopaymentTestHarness();
        $module->setTwoBrand(['availability_gate' => $gate]);

        $cart = new Cart(21);
        $cart->id_currency = $idCurrency;
        StubStore::$cartTotals[21][false][Cart::BOTH] = $netTotal;

        $address = new Address();
        $address->id_country = $idCountry;

        return [$module, $cart, $address];
    }

    private static function testAvailabilityGateAbsentForTwoBrand(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $cart = new Cart(20);
        $address = new Address();

        TinyAssert::true($module->isTwoBrandAvailabilityGateSatisfied($cart, $address));
    }

    private static function testAvailabilityGateComparesNetAndHidesBelowMinimum(): void
    {
        self::reset();
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'conversion_rate' => 1.0];
        $gate = ['min_order_amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net', 'billing_countries' => ['NL']];
        // 56 => BE in the stub country table; add NL
        StubStore::$countries[100] = 'NL';

        [$module, $cart, $address] = self::gateFixture($gate, 249.99, 978, 100);
        TinyAssert::false($module->isTwoBrandAvailabilityGateSatisfied($cart, $address));

        // The minimum is inclusive and compares NET
        [$module, $cart, $address] = self::gateFixture($gate, 250.0, 978, 100);
        TinyAssert::true($module->isTwoBrandAvailabilityGateSatisfied($cart, $address));
    }

    private static function testAvailabilityGateConvertsViaPrestashopRates(): void
    {
        self::reset();
        // Shop default currency EUR (rate 1.0); cart in GBP at 0.85 per EUR
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'conversion_rate' => 1.0];
        StubStore::$currencies[826] = ['iso_code' => 'GBP', 'conversion_rate' => 0.85];
        StubStore::$countries[100] = 'NL';
        $gate = ['min_order_amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net', 'billing_countries' => ['NL']];

        // GBP 230 net -> EUR 270.59: passes only because of conversion
        [$module, $cart, $address] = self::gateFixture($gate, 230.0, 826, 100);
        TinyAssert::true($module->isTwoBrandAvailabilityGateSatisfied($cart, $address));

        // GBP 200 net -> EUR 235.29: hidden
        [$module, $cart, $address] = self::gateFixture($gate, 200.0, 826, 100);
        TinyAssert::false($module->isTwoBrandAvailabilityGateSatisfied($cart, $address));
    }

    private static function testAvailabilityGateFailsClosedWithoutRate(): void
    {
        self::reset();
        // SEK exists but the gate currency EUR has no stub entry: no rate
        StubStore::$currencies[752] = ['iso_code' => 'SEK', 'conversion_rate' => 11.0];
        StubStore::$countries[100] = 'NL';
        $gate = ['min_order_amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net', 'billing_countries' => ['NL']];

        [$module, $cart, $address] = self::gateFixture($gate, 10000.0, 752, 100);
        TinyAssert::false($module->isTwoBrandAvailabilityGateSatisfied($cart, $address));
    }

    private static function testAvailabilityGateChecksBillingCountry(): void
    {
        self::reset();
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'conversion_rate' => 1.0];
        $gate = ['min_order_amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net', 'billing_countries' => ['NL']];

        // 47 => NO in the stub country table
        [$module, $cart, $address] = self::gateFixture($gate, 300.0, 978, 47);
        TinyAssert::false($module->isTwoBrandAvailabilityGateSatisfied($cart, $address));
    }

    private static function testAvailabilityGateGrossBasis(): void
    {
        self::reset();
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'conversion_rate' => 1.0];
        StubStore::$countries[100] = 'NL';
        $gate = ['min_order_amount' => 250.0, 'currency' => 'EUR', 'basis' => 'gross', 'billing_countries' => ['NL']];

        // Gross basis compares the with-tax total
        [$module, $cart, $address] = self::gateFixture($gate, 0.0, 978, 100);
        StubStore::$cartTotals[21][true][Cart::BOTH] = 250.0;
        TinyAssert::true($module->isTwoBrandAvailabilityGateSatisfied($cart, $address));
        StubStore::$cartTotals[21][true][Cart::BOTH] = 249.99;
        TinyAssert::false($module->isTwoBrandAvailabilityGateSatisfied($cart, $address));
    }

    private static function testMerchantMinimumGatesAlone(): void
    {
        self::reset();
        // No brand gate (Two): the merchant's own minimum gates, gross,
        // in the shop default currency
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'conversion_rate' => 1.0];
        StubStore::$configuration['PS_CURRENCY_DEFAULT'] = 978;
        StubStore::$configuration['PS_TWO_MERCHANT_MIN_ORDER'] = '500';
        $module = new TwopaymentTestHarness();

        $cart = new Cart(22);
        $cart->id_currency = 978;
        $address = new Address();

        StubStore::$cartTotals[22][true][Cart::BOTH] = 499.0;
        TinyAssert::false($module->isTwoBrandAvailabilityGateSatisfied($cart, $address));

        StubStore::$cartTotals[22][true][Cart::BOTH] = 500.0;
        TinyAssert::true($module->isTwoBrandAvailabilityGateSatisfied($cart, $address));
    }

    private static function testPlatformMinimumConvertsToDefaultCurrency(): void
    {
        self::reset();
        // Shop default GBP; brand minimum 250 EUR; EUR rate 1.16 per GBP
        // -> floor 215.52 GBP. Missing rate -> null (validation skips,
        // checkout gate fails closed instead).
        StubStore::$currencies[826] = ['iso_code' => 'GBP', 'conversion_rate' => 1.0];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'conversion_rate' => 1.16];
        StubStore::$configuration['PS_CURRENCY_DEFAULT'] = 826;
        $module = new TwopaymentTestHarness();

        $gate = ['min_order_amount' => 250.0, 'currency' => 'EUR', 'basis' => 'net', 'billing_countries' => ['NL']];
        TinyAssert::same(215.52, $module->getTwoPlatformMinimumInDefaultCurrencyForTests($gate));

        // Same currency: no conversion
        StubStore::$configuration['PS_CURRENCY_DEFAULT'] = 978;
        TinyAssert::same(250.0, $module->getTwoPlatformMinimumInDefaultCurrencyForTests($gate));

        // No rate for the brand currency
        unset(StubStore::$currencies[978]);
        StubStore::$configuration['PS_CURRENCY_DEFAULT'] = 826;
        TinyAssert::same(null, $module->getTwoPlatformMinimumInDefaultCurrencyForTests($gate));
    }

    private static function testGetTwoProductItemsAllowsPositiveDiscount(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $cart = new Cart(14);
        $cart->id_lang = 1;
        $cart->id_carrier = 999;

        // quantity * unit_price (100.00) > net total (95.00) -> 5.00 discount,
        // the healthy discounted-cart path must keep working
        StubStore::$cartProducts[14] = [[
            'id_product' => 890,
            'link_rewrite' => 'discounted-product',
            'name' => 'Discounted Product',
            'description_short' => 'Cart-rule discounted',
            'manufacturer_name' => 'Acme',
            'ean13' => '',
            'upc' => '',
            'total' => 95.00,
            'total_wt' => 114.95,
            'cart_quantity' => 1,
            'rate' => 21.0,
            'price' => 100.00,
            'reduction' => 0,
        ]];

        StubStore::$productCategories[890] = [['name' => 'Misc']];
        StubStore::$images[890] = ['id_image' => 9014];

        $items = $module->getTwoProductItems($cart);

        TinyAssert::count(1, $items);
        TinyAssert::same('5.00', (string)$items[0]['discount_amount']);
        TinyAssert::same('95.00', (string)$items[0]['net_amount']);
        TinyAssert::count(0, PrestaShopLogger::$logs);
    }

    private static function testGetTwoProductItemsToleratesUnitPriceRoundingDrift(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $cart = new Cart(15);
        $cart->id_lang = 1;
        $cart->id_carrier = 999;

        // PrestaShop stores unit prices at 6dp: 3 x 8.344 = 25.032, line total
        // rounded to 25.03. Deriving the discount from the ROUNDED unit price
        // (3 x 8.34 = 25.02) would manufacture a phantom -0.01 discount and
        // fail the cart; deriving at full precision yields 0.00.
        StubStore::$cartProducts[15] = [[
            'id_product' => 891,
            'link_rewrite' => 'six-decimal-product',
            'name' => 'Six Decimal Product',
            'description_short' => 'Unit price with 3rd-decimal precision',
            'manufacturer_name' => 'Acme',
            'ean13' => '',
            'upc' => '',
            'total' => 25.03,
            'total_wt' => 30.29,
            'cart_quantity' => 3,
            'rate' => 21.0,
            'price' => 8.344,
            'reduction' => 0,
        ]];

        StubStore::$productCategories[891] = [['name' => 'Misc']];
        StubStore::$images[891] = ['id_image' => 9015];

        $items = $module->getTwoProductItems($cart);

        TinyAssert::count(1, $items);
        TinyAssert::same('0.00', (string)$items[0]['discount_amount']);
        TinyAssert::same('25.03', (string)$items[0]['net_amount']);
        TinyAssert::count(0, PrestaShopLogger::$logs);
    }

    private static function testGetTwoNewOrderDataSupportsFivePointFivePercentVat(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

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

        $payload = $module->getTwoNewOrderData('merchant-attempt-7001', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        TinyAssert::same('105.50', $payload['gross_amount']);
        TinyAssert::same('100.00', $payload['net_amount']);
        TinyAssert::same('5.50', $payload['tax_amount']);
        // Two-brand payload parity: the brand layer must not add identity
        // keys to a real built payload (empty vendor_name stays omitted)
        TinyAssert::false(isset($payload['vendor_name']));
        TinyAssert::false(isset($payload['brand_tag']));
        TinyAssert::same('0.055', $payload['line_items'][0]['tax_rate']);
    }

    private static function testGetTwoNewOrderDataIncludesGiftWrappingLine(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        StubStore::$customers[7002] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Luis',
            'lastname' => 'Ramos',
            'secure_key' => 'secure-key-7002',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[7201] = [
            'id_country' => 34,
            'company' => 'Acme ES S.L.',
            'companyid' => 'B12345678',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];
        StubStore::$addresses[7202] = StubStore::$addresses[7201];

        $cart = new Cart(7002);
        $cart->id_customer = 7002;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 7201;
        $cart->id_address_delivery = 7202;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[7002] = [[
            'id_product' => 9302,
            'link_rewrite' => 'gift-product',
            'name' => 'Gift Product',
            'description_short' => 'Gift product test',
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
        StubStore::$productCategories[9302] = [['name' => 'Gifts']];
        StubStore::$images[9302] = ['id_image' => 9302];
        StubStore::$cartTotals[7002] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.0,
                Cart::BOTH => 123.42,
                Cart::ONLY_WRAPPING => 2.42,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.0,
                Cart::BOTH => 102.00,
                Cart::ONLY_WRAPPING => 2.00,
            ],
            'average_products_tax_rate' => 21.0,
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-7002', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        TinyAssert::same('123.42', $payload['gross_amount']);
        TinyAssert::same('102.00', $payload['net_amount']);
        TinyAssert::same('21.42', $payload['tax_amount']);

        $hasWrappingLine = false;
        foreach ($payload['line_items'] as $lineItem) {
            if ((string) ($lineItem['name'] ?? '') === 'Gift wrapping') {
                $hasWrappingLine = true;
                TinyAssert::same('2.42', (string) $lineItem['gross_amount']);
                TinyAssert::same('2.00', (string) $lineItem['net_amount']);
                TinyAssert::same('0.42', (string) $lineItem['tax_amount']);
            }
        }

        TinyAssert::true($hasWrappingLine);
    }

    private static function testGetTwoNewOrderDataSnapsGiftWrappingRateToCanonicalContext(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        StubStore::$customers[7003] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Lena',
            'lastname' => 'Garcia',
            'secure_key' => 'secure-key-7003',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[7301] = [
            'id_country' => 34,
            'company' => 'Acme ES S.L.',
            'companyid' => 'B12345678',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];
        StubStore::$addresses[7302] = StubStore::$addresses[7301];

        $cart = new Cart(7003);
        $cart->id_customer = 7003;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 7301;
        $cart->id_address_delivery = 7302;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[7003] = [[
            'id_product' => 9303,
            'link_rewrite' => 'gift-product-canonical',
            'name' => 'Gift Product Canonical',
            'description_short' => 'Gift product test',
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
        StubStore::$productCategories[9303] = [['name' => 'Gifts']];
        StubStore::$images[9303] = ['id_image' => 9303];
        StubStore::$cartTotals[7003] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.0,
                Cart::BOTH => 123.99,
                Cart::ONLY_WRAPPING => 2.99,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.0,
                Cart::BOTH => 102.47,
                Cart::ONLY_WRAPPING => 2.47,
            ],
            'average_products_tax_rate' => 21.0,
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-7003', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        $wrappingLine = null;
        foreach ($payload['line_items'] as $lineItem) {
            if ((string)($lineItem['name'] ?? '') === 'Gift wrapping') {
                $wrappingLine = $lineItem;
                break;
            }
        }

        TinyAssert::true(is_array($wrappingLine), 'Expected gift wrapping line');
        TinyAssert::same('2.99', (string)$wrappingLine['gross_amount']);
        TinyAssert::same('2.47', (string)$wrappingLine['net_amount']);
        TinyAssert::same('0.52', (string)$wrappingLine['tax_amount']);
        TinyAssert::same('0.21', (string)$wrappingLine['tax_rate']);
    }

    private static function testGetTwoProductItemsSnapsMinorRateDriftToKnownProductContexts(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $cart = new Cart(12);
        $cart->id_lang = 1;
        $cart->id_carrier = 0;

        StubStore::$cartProducts[12] = [
            [
                'id_product' => 8801,
                'link_rewrite' => 'anchor-product',
                'name' => 'Anchor Product',
                'description_short' => 'Anchor',
                'manufacturer_name' => 'ACME',
                'ean13' => '',
                'upc' => '',
                'total' => 100.00,
                'total_wt' => 121.00,
                'cart_quantity' => 1,
                'rate' => 21.0,
                'price' => 100.00,
                'reduction' => 0,
            ],
            [
                'id_product' => 8802,
                'link_rewrite' => 'drift-product',
                'name' => 'Drift Product',
                'description_short' => 'Drift',
                'manufacturer_name' => 'ACME',
                'ean13' => '',
                'upc' => '',
                'total' => 2.47,
                'total_wt' => 2.99,
                'cart_quantity' => 1,
                // Intentionally missing rate field to simulate amount-derived drift.
                'price' => 2.47,
                'reduction' => 0,
            ],
        ];

        StubStore::$productCategories[8801] = [['name' => 'General']];
        StubStore::$productCategories[8802] = [['name' => 'General']];
        StubStore::$images[8801] = ['id_image' => 8801];
        StubStore::$images[8802] = ['id_image' => 8802];

        $items = $module->getTwoProductItems($cart);

        TinyAssert::count(2, $items);
        TinyAssert::same('0.21', (string)$items[0]['tax_rate']);
        TinyAssert::same('0.21', (string)$items[1]['tax_rate']);
    }

    private static function testGetTwoProductItemsSpanishFallbackDefaultsToTwentyOnePercentWithoutKnownContext(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[7401] = [
            'id_country' => 34,
            'company' => 'Acme ES S.L.',
            'companyid' => 'B12345678',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];
        StubStore::$addresses[7402] = StubStore::$addresses[7401];

        $cart = new Cart(14);
        $cart->id_lang = 1;
        $cart->id_carrier = 0;
        $cart->id_address_invoice = 7401;
        $cart->id_address_delivery = 7402;

        StubStore::$cartProducts[14] = [[
            'id_product' => 8811,
            'link_rewrite' => 'es-fallback-product',
            'name' => 'ES fallback product',
            'description_short' => 'Fallback',
            'manufacturer_name' => 'ACME',
            'ean13' => '',
            'upc' => '',
            'total' => 2.47,
            'total_wt' => 2.99,
            'cart_quantity' => 1,
            // Intentionally no rate field to force amount-derived unresolved context.
            'price' => 2.47,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[8811] = [['name' => 'General']];
        StubStore::$images[8811] = ['id_image' => 8811];

        $items = $module->getTwoProductItems($cart);

        TinyAssert::count(1, $items);
        TinyAssert::same('0.21', (string)$items[0]['tax_rate']);
    }

    private static function testGetTwoNewOrderDataOmitsTopLevelTaxRate(): void
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

        TinyAssert::false(isset($payload['tax_rate']));
        TinyAssert::true(isset($payload['tax_subtotals']));
        TinyAssert::same('100.00', $payload['net_amount']);
        TinyAssert::same('20.50', $payload['tax_amount']);
        TinyAssert::same('120.50', $payload['gross_amount']);
    }

    private static function testGetTwoNewOrderDataOmitsTaxSubtotalsWhenDisabled(): void
    {
        self::reset();
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

        TinyAssert::false(isset($payload['tax_subtotals']));
        TinyAssert::false(isset($payload['tax_rate']));
    }

    private static function testGetTwoIntentOrderDataOmitsTopLevelTaxRateAndOmitsTaxSubtotalsWhenDisabled(): void
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
        TinyAssert::false(isset($payloadWithSubtotals['tax_rate']));
        TinyAssert::true(isset($payloadWithSubtotals['tax_subtotals']));
        TinyAssert::true(isset($payloadWithSubtotals['billing_address']));
        TinyAssert::true(isset($payloadWithSubtotals['shipping_address']));
        TinyAssert::same('ES', $payloadWithSubtotals['billing_address']['country']);
        TinyAssert::same('ES', $payloadWithSubtotals['shipping_address']['country']);

        StubStore::$configuration['PS_TWO_ENABLE_TAX_SUBTOTALS'] = 0;
        $payloadWithoutSubtotals = $module->getTwoIntentOrderData($cart, $customer, $currency, $address);
        TinyAssert::false(isset($payloadWithoutSubtotals['tax_rate']));
        TinyAssert::false(isset($payloadWithoutSubtotals['tax_subtotals']));
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

    private static function testGetTwoNewOrderDataThrowsWhenCartTotalsMismatchIsMaterial(): void
    {
        self::reset();

        $lineItems = [
            [
                'name' => 'TV LG 4K UHD',
                'description' => 'Product',
                'gross_amount' => '1609.76',
                'net_amount' => '1330.38',
                'discount_amount' => '0.00',
                'tax_amount' => '279.38',
                'tax_class_name' => 'VAT 21.00%',
                'tax_rate' => '0.21',
                'unit_price' => '665.19',
                'quantity' => 2,
                'quantity_unit' => 'pcs',
                'image_url' => '',
                'product_page_url' => '',
                'type' => 'PHYSICAL',
                'details' => ['brand' => 'LG', 'barcodes' => [], 'categories' => []],
            ],
            [
                'name' => 'Envio gratis (+1 mas)',
                'description' => 'Discount',
                'gross_amount' => '-137.90',
                'net_amount' => '-114.81',
                'discount_amount' => '0.00',
                'tax_amount' => '-23.09',
                'tax_class_name' => 'VAT 20.11%',
                'tax_rate' => '0.2011',
                'unit_price' => '-114.81',
                'quantity' => 1,
                'quantity_unit' => 'item',
                'image_url' => '',
                'product_page_url' => '',
                'type' => 'DIGITAL',
                'details' => ['brand' => null, 'barcodes' => [], 'categories' => []],
            ],
        ];

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

        StubStore::$customers[490] = [
            'email' => 'support@two.inc',
            'firstname' => 'two',
            'lastname' => 'support',
            'secure_key' => 'secure-key-reconcile',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[910] = [
            'id_country' => 34,
            'company' => 'ORDER IN TECH',
            'companyid' => 'B01588177',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];
        StubStore::$addresses[911] = StubStore::$addresses[910];

        $cart = new Cart(490);
        $cart->id_customer = 490;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 910;
        $cart->id_address_delivery = 911;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[490] = [['id_product' => 1, 'cart_quantity' => 1]];
        StubStore::$cartTotals[490] = [
            true => [
                Cart::ONLY_DISCOUNTS => 137.90,
                Cart::BOTH => 1518.10,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 114.81,
                Cart::BOTH => 1254.63,
            ],
            'average_products_tax_rate' => 21.0,
        ];

        TinyAssert::throws(function () use ($module, $cart): void {
            $module->getTwoNewOrderData('merchant-attempt-reconcile', $cart, [
                'merchant_confirmation_url' => 'https://shop.local/confirm',
                'merchant_cancel_order_url' => 'https://shop.local/cancel',
                'merchant_edit_order_url' => '',
                'merchant_order_verification_failed_url' => '',
                'merchant_invoice_url' => '',
                'merchant_shipping_document_url' => '',
            ]);
        }, 'Order totals do not reconcile with cart totals');
    }

    private static function testGetTwoIntentOrderDataContinuesWhenCartTotalsDoNotReconcile(): void
    {
        self::reset();

        $lineItems = [[
            'name' => 'Widget',
            'description' => 'Product',
            'gross_amount' => '121.00',
            'net_amount' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '21.00',
            'tax_class_name' => 'VAT 21.00%',
            'tax_rate' => '0.210000',
            'unit_price' => '100.00',
            'quantity' => 1,
            'quantity_unit' => 'pcs',
            'image_url' => '',
            'product_page_url' => '',
            'type' => 'PHYSICAL',
            'details' => ['brand' => null, 'barcodes' => [], 'categories' => []],
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

        StubStore::$customers[591] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Ana',
            'lastname' => 'Garcia',
            'secure_key' => 'secure-key-591',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[990] = [
            'id_country' => 34,
            'company' => 'ACME S.L.',
            'companyid' => 'B12345678',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];

        $cart = new Cart(591);
        $cart->id_customer = 591;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 990;
        $cart->id_address_delivery = 990;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[591] = [['id_product' => 1, 'cart_quantity' => 1]];
        StubStore::$cartTotals[591] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::BOTH => 121.10,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::BOTH => 100.00,
            ],
            'average_products_tax_rate' => 21.0,
        ];

        $customer = new Customer(591);
        $currency = new Currency(978);
        $address = new Address(990);

        $payload = $module->getTwoIntentOrderData($cart, $customer, $currency, $address);
        TinyAssert::same('121.00', $payload['gross_amount'], 'Expected order intent payload to be built even when cart drift exceeds create-order tolerance');
    }

    private static function testGetTwoNewOrderDataAllowsTwoCentReconciliationDrift(): void
    {
        self::reset();

        $lineItems = [[
            'name' => 'Widget',
            'description' => 'Product',
            'gross_amount' => '121.00',
            'net_amount' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '21.00',
            'tax_class_name' => 'VAT 21.00%',
            'tax_rate' => '0.210000',
            'unit_price' => '100.00',
            'quantity' => 1,
            'quantity_unit' => 'pcs',
            'image_url' => '',
            'product_page_url' => '',
            'type' => 'PHYSICAL',
            'details' => ['brand' => null, 'barcodes' => [], 'categories' => []],
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

        StubStore::$customers[592] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Ana',
            'lastname' => 'Garcia',
            'secure_key' => 'secure-key-592',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[992] = [
            'id_country' => 34,
            'company' => 'ACME S.L.',
            'companyid' => 'B12345678',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];
        StubStore::$addresses[993] = StubStore::$addresses[992];

        $cart = new Cart(592);
        $cart->id_customer = 592;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 992;
        $cart->id_address_delivery = 993;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[592] = [['id_product' => 1, 'cart_quantity' => 1]];
        StubStore::$cartTotals[592] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::BOTH => 121.02,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::BOTH => 100.00,
            ],
            'average_products_tax_rate' => 21.0,
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-592', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        TinyAssert::same('121.00', $payload['gross_amount'], 'Expected payload to be built when drift is within 2 cents');
        TinyAssert::same('21.00', $payload['tax_amount'], 'Expected line-derived tax total to remain unchanged');
    }

    private static function testGetTwoNewOrderDataBlocksThreeCentReconciliationDrift(): void
    {
        self::reset();

        $lineItems = [[
            'name' => 'Widget',
            'description' => 'Product',
            'gross_amount' => '121.00',
            'net_amount' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '21.00',
            'tax_class_name' => 'VAT 21.00%',
            'tax_rate' => '0.210000',
            'unit_price' => '100.00',
            'quantity' => 1,
            'quantity_unit' => 'pcs',
            'image_url' => '',
            'product_page_url' => '',
            'type' => 'PHYSICAL',
            'details' => ['brand' => null, 'barcodes' => [], 'categories' => []],
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

        StubStore::$customers[593] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Ana',
            'lastname' => 'Garcia',
            'secure_key' => 'secure-key-593',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[994] = [
            'id_country' => 34,
            'company' => 'ACME S.L.',
            'companyid' => 'B12345678',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];
        StubStore::$addresses[995] = StubStore::$addresses[994];

        $cart = new Cart(593);
        $cart->id_customer = 593;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 994;
        $cart->id_address_delivery = 995;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[593] = [['id_product' => 1, 'cart_quantity' => 1]];
        StubStore::$cartTotals[593] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::BOTH => 121.03,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::BOTH => 100.00,
            ],
            'average_products_tax_rate' => 21.0,
        ];

        TinyAssert::throws(function () use ($module, $cart): void {
            $module->getTwoNewOrderData('merchant-attempt-593', $cart, [
                'merchant_confirmation_url' => 'https://shop.local/confirm',
                'merchant_cancel_order_url' => 'https://shop.local/cancel',
                'merchant_edit_order_url' => '',
                'merchant_order_verification_failed_url' => '',
                'merchant_invoice_url' => '',
                'merchant_shipping_document_url' => '',
            ]);
        }, 'Order totals do not reconcile with cart totals');
    }

    private static function testGetTwoNewOrderDataAllowsTwoCentBoundaryForLargeTotals(): void
    {
        self::reset();

        $lineItems = [[
            'name' => 'Large Ticket Item',
            'description' => 'Product',
            'gross_amount' => '8145.11',
            'net_amount' => '6736.22',
            'discount_amount' => '0.00',
            'tax_amount' => '1408.89',
            'tax_class_name' => 'VAT 20.92%',
            'tax_rate' => '0.209152',
            'unit_price' => '6736.22',
            'quantity' => 1,
            'quantity_unit' => 'pcs',
            'image_url' => '',
            'product_page_url' => '',
            'type' => 'PHYSICAL',
            'details' => ['brand' => null, 'barcodes' => [], 'categories' => []],
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

        StubStore::$customers[594] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Ana',
            'lastname' => 'Garcia',
            'secure_key' => 'secure-key-594',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[996] = [
            'id_country' => 34,
            'company' => 'ACME S.L.',
            'companyid' => 'B12345678',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];
        StubStore::$addresses[997] = StubStore::$addresses[996];

        $cart = new Cart(594);
        $cart->id_customer = 594;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 996;
        $cart->id_address_delivery = 997;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[594] = [['id_product' => 1, 'cart_quantity' => 1]];
        StubStore::$cartTotals[594] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::BOTH => 8145.13,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::BOTH => 6736.22,
            ],
            'average_products_tax_rate' => 20.9152,
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-594', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        TinyAssert::same('8145.11', $payload['gross_amount'], 'Expected payload build at exact 2-cent boundary for large totals');
    }

    private static function testGetTwoNewOrderDataIncludesShippingAndDiscountLineItemsWhenReconciled(): void
    {
        self::reset();

        $module = new TwopaymentTestHarness();

        StubStore::$customers[491] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Juan',
            'lastname' => 'Gonzalez',
            'secure_key' => 'secure-key-491',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[920] = [
            'id_country' => 34,
            'company' => 'ORDER IN TECH',
            'companyid' => 'B01588177',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];
        StubStore::$addresses[921] = StubStore::$addresses[920];

        StubStore::$carriers[31] = [
            'name' => 'Carrier',
            'delay' => '',
            'shipping_method' => Carrier::SHIPPING_METHOD_PRICE,
            'tax_rules_group_id' => 7,
        ];
        StubStore::$taxRuleRates[7] = 21.0;

        $cart = new Cart(491);
        $cart->id_customer = 491;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 920;
        $cart->id_address_delivery = 921;
        $cart->id_carrier = 31;
        $cart->id_lang = 1;

        StubStore::$cartProducts[491] = [[
            'id_product' => 777,
            'link_rewrite' => 'tv-lg',
            'name' => 'TV LG 4K UHD',
            'description_short' => 'TV',
            'manufacturer_name' => 'LG',
            'ean13' => '',
            'upc' => '',
            'total' => 1320.66,
            'total_wt' => 1598.00,
            'cart_quantity' => 2,
            'rate' => 21.0,
            'price' => 660.33,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[777] = [['name' => 'TV']];
        StubStore::$images[777] = ['id_image' => 9901];
        StubStore::$cartShipping[491] = [
            true => 58.00,
            false => 47.93,
        ];
        StubStore::$cartTotals[491] = [
            true => [
                Cart::ONLY_DISCOUNTS => 137.90,
                Cart::BOTH => 1518.10,
                Cart::ONLY_SHIPPING => 0.00,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 113.96,
                Cart::BOTH => 1254.63,
                Cart::ONLY_SHIPPING => 0.00,
            ],
            'average_products_tax_rate' => 21.0,
        ];
        StubStore::$cartRules[491] = [
            ['name' => 'Envio gratis', 'code' => '', 'value' => -58.00, 'reduction_percent' => 0, 'reduction_amount' => 58.00, 'free_shipping' => 1],
            ['name' => 'Promo cruzada| 5%', 'code' => '', 'value' => -79.90, 'reduction_percent' => 5, 'reduction_amount' => 79.90, 'free_shipping' => 0],
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-491', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        $lineItems = $payload['line_items'];
        TinyAssert::true(count($lineItems) >= 3, 'Expected product + shipping + discount lines');

        $hasShipping = false;
        $hasDiscount = false;
        $lineGross = 0.0;
        foreach ($lineItems as $line) {
            if (isset($line['type']) && $line['type'] === 'SHIPPING_FEE') {
                $hasShipping = true;
            }
            if (isset($line['gross_amount']) && (float)$line['gross_amount'] < 0) {
                $hasDiscount = true;
            }
            $lineGross = round($lineGross + (float)$line['gross_amount'], 2);
        }

        TinyAssert::true($hasShipping, 'Expected SHIPPING_FEE line item');
        TinyAssert::true($hasDiscount, 'Expected negative discount line item');
        TinyAssert::same('1518.10', $payload['gross_amount'], 'Expected reconciled gross total');
        TinyAssert::same(1518.10, $lineGross, 'Expected line item gross sum to match order gross');
    }

    private static function testGetTwoNewOrderDataFallbackFreeShippingUsesShippingTaxContext(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        StubStore::$customers[494] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Sara',
            'lastname' => 'Iglesias',
            'secure_key' => 'secure-key-494',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[942] = [
            'id_country' => 34,
            'company' => 'Fallback Shop S.L.',
            'companyid' => 'B12345678',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];
        StubStore::$addresses[943] = StubStore::$addresses[942];
        StubStore::$countries[34] = 'ES';

        StubStore::$carriers[34] = [
            'name' => 'Carrier',
            'delay' => '',
            'shipping_method' => Carrier::SHIPPING_METHOD_PRICE,
            'tax_rules_group_id' => 7,
        ];
        StubStore::$taxRuleRates[7] = 21.0;

        $cart = new Cart(494);
        $cart->id_customer = 494;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 942;
        $cart->id_address_delivery = 943;
        $cart->id_carrier = 34;
        $cart->id_lang = 1;

        StubStore::$cartProducts[494] = [
            [
                'id_product' => 779,
                'link_rewrite' => 'zero-tax-item',
                'name' => 'Zero Tax Item',
                'description_short' => 'Zero',
                'manufacturer_name' => 'ACME',
                'ean13' => '',
                'upc' => '',
                'total' => 100.00,
                'total_wt' => 100.00,
                'cart_quantity' => 1,
                'rate' => 0.0,
                'price' => 100.00,
                'reduction' => 0,
            ],
            [
                'id_product' => 780,
                'link_rewrite' => 'taxed-item',
                'name' => 'Taxed Item',
                'description_short' => 'Taxed',
                'manufacturer_name' => 'ACME',
                'ean13' => '',
                'upc' => '',
                'total' => 200.00,
                'total_wt' => 242.00,
                'cart_quantity' => 1,
                'rate' => 21.0,
                'price' => 200.00,
                'reduction' => 0,
            ],
        ];
        StubStore::$productCategories[779] = [['name' => 'General']];
        StubStore::$productCategories[780] = [['name' => 'General']];
        StubStore::$images[779] = ['id_image' => 9903];
        StubStore::$images[780] = ['id_image' => 9904];
        StubStore::$cartShipping[494] = [
            true => 116.00,
            false => 95.87,
        ];
        StubStore::$cartTotals[494] = [
            true => [
                Cart::ONLY_DISCOUNTS => 116.00,
                Cart::BOTH => 342.00,
                Cart::ONLY_SHIPPING => 0.00,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 95.87,
                Cart::BOTH => 300.00,
                Cart::ONLY_SHIPPING => 0.00,
            ],
            'average_products_tax_rate' => 21.0,
        ];

        // Intentionally omit rule-level tax-excluded metadata to force fallback path.
        StubStore::$cartRules[494] = [
            ['name' => 'free shipping rule', 'code' => 'free-ship', 'value' => -116.00, 'reduction_amount' => 116.00, 'free_shipping' => 1],
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-494', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        $discountLines = [];
        foreach ($payload['line_items'] as $line) {
            if (isset($line['gross_amount']) && (float)$line['gross_amount'] < 0) {
                $discountLines[] = $line;
            }
        }

        TinyAssert::count(1, $discountLines);
        TinyAssert::same('-116.00', (string)$discountLines[0]['gross_amount']);
        TinyAssert::same('-95.87', (string)$discountLines[0]['net_amount']);
        TinyAssert::same('-20.13', (string)$discountLines[0]['tax_amount']);
        TinyAssert::same('0.21', (string)$discountLines[0]['tax_rate']);
    }

    private static function testGetTwoNewOrderDataKeepsDiscountTaxFormulaForLargeRoundedDiscounts(): void
    {
        self::reset();

        $module = new TwopaymentTestHarness();

        StubStore::$customers[492] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Ana',
            'lastname' => 'Lopez',
            'secure_key' => 'secure-key-492',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[930] = [
            'id_country' => 34,
            'company' => 'ORDER IN TECH',
            'companyid' => 'B01588177',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];
        StubStore::$addresses[931] = StubStore::$addresses[930];

        StubStore::$carriers[32] = [
            'name' => 'Carrier',
            'delay' => '',
            'shipping_method' => Carrier::SHIPPING_METHOD_PRICE,
            'tax_rules_group_id' => 7,
        ];
        StubStore::$taxRuleRates[7] = 21.0;

        $cart = new Cart(492);
        $cart->id_customer = 492;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 930;
        $cart->id_address_delivery = 931;
        $cart->id_carrier = 32;
        $cart->id_lang = 1;

        StubStore::$cartProducts[492] = [[
            'id_product' => 778,
            'link_rewrite' => 'bulk-product',
            'name' => 'Bulk Product',
            'description_short' => 'Bulk',
            'manufacturer_name' => 'ACME',
            'ean13' => '',
            'upc' => '',
            'total' => 3000.00,
            'total_wt' => 3630.00,
            'cart_quantity' => 1,
            'rate' => 21.0,
            'price' => 3000.00,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[778] = [['name' => 'Bulk']];
        StubStore::$images[778] = ['id_image' => 9902];
        StubStore::$cartShipping[492] = [
            true => 121.00,
            false => 100.00,
        ];
        StubStore::$cartTotals[492] = [
            true => [
                Cart::ONLY_DISCOUNTS => 709.25,
                Cart::BOTH => 3041.75,
                Cart::ONLY_SHIPPING => 0.00,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 586.25,
                Cart::BOTH => 2513.75,
                Cart::ONLY_SHIPPING => 0.00,
            ],
            'average_products_tax_rate' => 21.0,
        ];
        StubStore::$cartRules[492] = [
            ['name' => 'free shipping rule', 'code' => '', 'value' => -121.00, 'reduction_percent' => 0, 'reduction_amount' => 121.00, 'free_shipping' => 1],
            ['name' => 'bulk promo', 'code' => '', 'value' => -588.25, 'reduction_percent' => 0, 'reduction_amount' => 588.25, 'free_shipping' => 0],
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-492', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        $discountLine = null;
        foreach ($payload['line_items'] as $line) {
            if (isset($line['gross_amount']) && (float)$line['gross_amount'] < 0) {
                $discountLine = $line;
                break;
            }
        }

        TinyAssert::true($discountLine !== null, 'Expected a discount line item');
        $lineTax = (float)$discountLine['tax_amount'];
        $lineNet = (float)$discountLine['net_amount'];
        $lineRate = (float)$discountLine['tax_rate'];
        $diff = abs($lineTax - ($lineNet * $lineRate));
        TinyAssert::true($diff <= 0.02, 'Expected discount line tax formula to remain within tolerance, diff=' . $diff);
    }

    private static function testGetTwoNewOrderDataUsesCartRuleMonetaryValuesForDiscountLines(): void
    {
        self::reset();

        $module = new TwopaymentTestHarness();

        StubStore::$customers[493] = [
            'email' => 'buyer@example.com',
            'firstname' => 'John',
            'lastname' => 'Jones',
            'secure_key' => 'secure-key-493',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[34] = 'ES';
        StubStore::$countries[826] = 'GB';
        StubStore::$addresses[940] = [
            'id_country' => 34,
            'company' => 'SPAIN',
            'companyid' => 'J13936695',
            'address1' => 'Billing here CALLE DALIA, 10 215',
            'city' => 'BENALMADENA',
            'postcode' => '29639',
            'phone' => '666666668',
            'loaded' => true,
        ];
        StubStore::$addresses[941] = [
            'id_country' => 826,
            'company' => 'SPAIN LTD',
            'companyid' => '06922947',
            'address1' => 'Shipping here 20-22 Wenlock Road',
            'city' => 'London',
            'postcode' => 'N1 7GU',
            'phone' => '666666668',
            'loaded' => true,
        ];

        StubStore::$carriers[33] = [
            'name' => 'My carrier',
            'delay' => 'Delivery next day!',
            'shipping_method' => Carrier::SHIPPING_METHOD_WEIGHT,
            'tax_rules_group_id' => 7,
        ];
        StubStore::$taxRuleRates[7] = 21.0;

        $cart = new Cart(493);
        $cart->id_customer = 493;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 940;
        $cart->id_address_delivery = 941;
        $cart->id_carrier = 33;
        $cart->id_lang = 1;

        StubStore::$cartProducts[493] = [
            [
                'id_product' => 8201,
                'link_rewrite' => 'hummingbird-printed-sweater',
                'name' => 'Hummingbird printed sweater',
                'description_short' => 'Sweater',
                'manufacturer_name' => 'Studio Design',
                'ean13' => '',
                'upc' => '',
                'total' => 430.80,
                'total_wt' => 430.80,
                'cart_quantity' => 12,
                'rate' => 0.0,
                'price' => 35.90,
                'reduction' => 0,
            ],
            [
                'id_product' => 8202,
                'link_rewrite' => 'hummingbird-notebook',
                'name' => 'Hummingbird notebook',
                'description_short' => 'Notebook',
                'manufacturer_name' => 'Graphic Corner',
                'ean13' => '',
                'upc' => '',
                'total' => 10832.23,
                'total_wt' => 13107.00,
                'cart_quantity' => 17,
                'rate' => 21.0,
                'price' => 637.19,
                'reduction' => 0,
            ],
        ];
        StubStore::$productCategories[8201] = [['name' => 'Women']];
        StubStore::$productCategories[8202] = [['name' => 'Stationery']];
        StubStore::$images[8201] = ['id_image' => 8201];
        StubStore::$images[8202] = ['id_image' => 8202];
        StubStore::$cartShipping[493] = [
            true => 116.00,
            false => 95.87,
        ];
        StubStore::$cartTotals[493] = [
            true => [
                Cart::ONLY_DISCOUNTS => 731.99,
                Cart::BOTH => 13138.40,
                Cart::ONLY_SHIPPING => 0.00,
                Cart::ONLY_WRAPPING => 216.59,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 608.99,
                Cart::BOTH => 10928.91,
                Cart::ONLY_SHIPPING => 0.00,
                Cart::ONLY_WRAPPING => 179.00,
            ],
            'average_products_tax_rate' => 21.0,
        ];
        StubStore::$cartRules[493] = [
            [
                'name' => 'free shipping rule',
                'code' => 'free-ship',
                'value' => -58.00,
                'value_real' => 58.00,
                'value_tax_exc' => 48.252911813643927,
            ],
            [
                'name' => 'discount-rule',
                'code' => 'discount-rule',
                'value' => -673.99,
                'value_real' => 673.99,
                'value_tax_exc' => 560.73885440931781,
            ],
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-493', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        $discountLines = [];
        foreach ($payload['line_items'] as $line) {
            if (isset($line['gross_amount']) && (float)$line['gross_amount'] < 0) {
                $discountLines[] = $line;
            }
        }

        TinyAssert::true(count($discountLines) >= 2, 'Expected discount lines to be represented in payload');

        $aggregatedByRule = [];
        foreach ($discountLines as $line) {
            $lineName = (string)$line['name'];
            $baseName = preg_replace('/\s+\(VAT\s+[^)]+\)$/', '', $lineName);
            if (!isset($aggregatedByRule[$baseName])) {
                $aggregatedByRule[$baseName] = ['net' => 0.0, 'gross' => 0.0];
            }
            $aggregatedByRule[$baseName]['net'] += (float)$line['net_amount'];
            $aggregatedByRule[$baseName]['gross'] += (float)$line['gross_amount'];

            // Discount rates must stay on canonical cart tax contexts for provider compatibility.
            $lineRate = (float)$line['tax_rate'];
            $isCanonicalRate = abs($lineRate - 0.0) <= 0.000001 || abs($lineRate - 0.21) <= 0.000001;
            TinyAssert::true($isCanonicalRate, 'Expected discount tax_rate to stay on canonical contexts (0 or 0.21), got: ' . $line['tax_rate']);
        }

        TinyAssert::true(isset($aggregatedByRule['free shipping rule']), 'Expected free shipping rule discount line');
        TinyAssert::true(isset($aggregatedByRule['discount-rule']), 'Expected discount-rule discount line');
        TinyAssert::same('-48.25', number_format($aggregatedByRule['free shipping rule']['net'], 2, '.', ''), 'Expected canonical net discount from cart rule value_tax_exc');
        TinyAssert::same('-560.74', number_format($aggregatedByRule['discount-rule']['net'], 2, '.', ''), 'Expected canonical net discount from cart rule value_tax_exc');
        TinyAssert::same('-58.00', number_format($aggregatedByRule['free shipping rule']['gross'], 2, '.', ''), 'Expected canonical gross discount from cart rule value_real');
        TinyAssert::same('-673.99', number_format($aggregatedByRule['discount-rule']['gross'], 2, '.', ''), 'Expected canonical gross discount from cart rule value_real');
    }

    private static function testGetTwoNewOrderDataHandlesMixedCartRuleMetadataWithPartialFallback(): void
    {
        self::reset();

        $module = new TwopaymentTestHarness();

        StubStore::$customers[497] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Marta',
            'lastname' => 'Perez',
            'secure_key' => 'secure-key-497',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[952] = [
            'id_country' => 34,
            'company' => 'SPAIN',
            'companyid' => 'E20468708',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '666666668',
            'loaded' => true,
        ];
        StubStore::$addresses[953] = StubStore::$addresses[952];

        $cart = new Cart(497);
        $cart->id_customer = 497;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 952;
        $cart->id_address_delivery = 953;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[497] = [[
            'id_product' => 8302,
            'link_rewrite' => 'single-taxed-product-2',
            'name' => 'Single taxed product 2',
            'description_short' => 'Product',
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
        StubStore::$productCategories[8302] = [['name' => 'General']];
        StubStore::$images[8302] = ['id_image' => 8302];
        StubStore::$cartShipping[497] = [true => 0.00, false => 0.00];
        StubStore::$cartTotals[497] = [
            true => [
                Cart::ONLY_DISCOUNTS => 30.00,
                Cart::BOTH => 91.00,
                Cart::ONLY_SHIPPING => 0.00,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 24.79,
                Cart::BOTH => 75.21,
                Cart::ONLY_SHIPPING => 0.00,
            ],
            'average_products_tax_rate' => 21.0,
        ];
        StubStore::$cartRules[497] = [
            [
                'name' => 'fixed-10',
                'code' => 'fixed-10',
                'value' => -10.00,
                'value_real' => 10.00,
                'value_tax_exc' => 8.26,
            ],
            [
                'name' => 'percent-metadata-missing',
                'code' => 'percent-metadata-missing',
                'value' => -20.00,
                'value_real' => 20.00,
                // Missing net metadata should fallback only for unresolved remainder.
            ],
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-497', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        $discountLines = [];
        $discountGross = 0.0;
        $fixedTenGross = 0.0;
        $fixedTenNet = 0.0;
        $fixedTenRates = [];
        foreach ($payload['line_items'] as $line) {
            if (!isset($line['gross_amount']) || (float)$line['gross_amount'] >= 0) {
                continue;
            }
            $discountLines[] = $line;
            $discountGross = round($discountGross + (float)$line['gross_amount'], 2);
            if ((string)$line['name'] === 'fixed-10') {
                $fixedTenGross = round($fixedTenGross + (float)$line['gross_amount'], 2);
                $fixedTenNet = round($fixedTenNet + (float)$line['net_amount'], 2);
                $fixedTenRates[] = (string)$line['tax_rate'];
            }
        }

        TinyAssert::true(count($discountLines) >= 2, 'Expected mixed cart-rule metadata to produce rule-scoped + fallback discount lines');
        TinyAssert::same('-10.00', number_format($fixedTenGross, 2, '.', ''), 'Expected complete fixed rule gross to remain canonical');
        TinyAssert::same('-8.26', number_format($fixedTenNet, 2, '.', ''), 'Expected complete fixed rule net to remain canonical');
        TinyAssert::same('0.21', (string)reset($fixedTenRates), 'Expected complete fixed rule tax rate to remain canonical');
        TinyAssert::same('-30.00', number_format($discountGross, 2, '.', ''), 'Expected total discount gross to remain fully reconciled');
    }

    private static function testGetTwoNewOrderDataMixedMetadataKeepsCompleteRuleValuesAndFreeShippingContext(): void
    {
        self::reset();

        $module = new TwopaymentTestHarness();

        StubStore::$customers[596] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Nora',
            'lastname' => 'Vega',
            'secure_key' => 'secure-key-596',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[996] = [
            'id_country' => 34,
            'company' => 'SPAIN',
            'companyid' => 'E20468708',
            'address1' => 'Calle Norte 1',
            'city' => 'Madrid',
            'postcode' => '28006',
            'phone' => '666666673',
            'loaded' => true,
        ];
        StubStore::$addresses[997] = StubStore::$addresses[996];
        StubStore::$carriers[596] = [
            'name' => 'Carrier 596',
            'delay' => '',
            'shipping_method' => Carrier::SHIPPING_METHOD_PRICE,
            'tax_rules_group_id' => 96,
        ];
        StubStore::$taxRuleRates[96] = 21.0;

        $cart = new Cart(596);
        $cart->id_customer = 596;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 996;
        $cart->id_address_delivery = 997;
        $cart->id_carrier = 596;
        $cart->id_lang = 1;

        StubStore::$cartProducts[596] = [[
            'id_product' => 8396,
            'link_rewrite' => 'mixed-free-shipping-case',
            'name' => 'Mixed free shipping case',
            'description_short' => 'Product',
            'manufacturer_name' => 'ACME',
            'ean13' => '',
            'upc' => '',
            'total' => 100.00,
            'total_wt' => 110.00,
            'cart_quantity' => 1,
            'rate' => 10.0,
            'price' => 100.00,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[8396] = [['name' => 'General']];
        StubStore::$images[8396] = ['id_image' => 8396];
        StubStore::$cartShipping[596] = [true => 12.10, false => 10.00];
        StubStore::$cartTotals[596] = [
            true => [
                Cart::ONLY_DISCOUNTS => 23.10,
                Cart::BOTH => 99.00,
                Cart::ONLY_SHIPPING => 12.10,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 20.00,
                Cart::BOTH => 90.00,
                Cart::ONLY_SHIPPING => 10.00,
            ],
            'average_products_tax_rate' => 10.0,
        ];
        StubStore::$cartRules[596] = [
            [
                'name' => 'fixed-voucher-11',
                'code' => 'fixed-voucher-11',
                'value' => -11.00,
                'value_real' => 11.00,
                'value_tax_exc' => 10.00,
                'free_shipping' => 0,
            ],
            [
                'name' => 'free-shipping-missing-net',
                'code' => 'free-shipping-missing-net',
                'value' => -12.10,
                'value_real' => 12.10,
                'free_shipping' => 1,
                // Missing value_tax_exc should fallback on shipping context only.
            ],
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-596', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        $fixedGross = 0.0;
        $fixedNet = 0.0;
        $freeShippingGross = 0.0;
        $freeShippingNet = 0.0;
        $freeShippingRate = '';
        $discountGross = 0.0;
        foreach ($payload['line_items'] as $line) {
            if (!isset($line['gross_amount']) || (float)$line['gross_amount'] >= 0) {
                continue;
            }
            $discountGross = round($discountGross + (float)$line['gross_amount'], 2);

            $lineName = (string)$line['name'];
            if (strpos($lineName, 'fixed-voucher-11') === 0) {
                $fixedGross = round($fixedGross + (float)$line['gross_amount'], 2);
                $fixedNet = round($fixedNet + (float)$line['net_amount'], 2);
            }
            if (strpos($lineName, 'free-shipping-missing-net') === 0) {
                $freeShippingGross = round($freeShippingGross + (float)$line['gross_amount'], 2);
                $freeShippingNet = round($freeShippingNet + (float)$line['net_amount'], 2);
                $freeShippingRate = (string)$line['tax_rate'];
            }
        }

        TinyAssert::same('-11.00', number_format($fixedGross, 2, '.', ''), 'Expected complete non-shipping rule gross to remain canonical');
        TinyAssert::same('-10.00', number_format($fixedNet, 2, '.', ''), 'Expected complete non-shipping rule net to remain canonical');
        TinyAssert::same('-12.10', number_format($freeShippingGross, 2, '.', ''), 'Expected unresolved free-shipping gross to stay on shipping context');
        TinyAssert::same('-10.00', number_format($freeShippingNet, 2, '.', ''), 'Expected unresolved free-shipping net to stay on shipping context');
        TinyAssert::same('0.21', $freeShippingRate, 'Expected unresolved free-shipping rate to follow shipping VAT context');
        TinyAssert::same('-23.10', number_format($discountGross, 2, '.', ''), 'Expected total discount gross to remain reconciled');
    }

    private static function testGetTwoNewOrderDataSpanishOddDecimalsKeepCanonicalTwentyOneDiscountRates(): void
    {
        self::reset();

        $module = new TwopaymentTestHarness();

        StubStore::$customers[597] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Luis',
            'lastname' => 'Marin',
            'secure_key' => 'secure-key-597',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[998] = [
            'id_country' => 34,
            'company' => 'SPAIN',
            'companyid' => 'E20468708',
            'address1' => 'Calle Uno 1',
            'city' => 'Madrid',
            'postcode' => '28007',
            'phone' => '666666674',
            'loaded' => true,
        ];
        StubStore::$addresses[999] = StubStore::$addresses[998];
        StubStore::$carriers[597] = [
            'name' => 'Carrier 597',
            'delay' => '',
            'shipping_method' => Carrier::SHIPPING_METHOD_PRICE,
            'tax_rules_group_id' => 97,
        ];
        StubStore::$taxRuleRates[97] = 21.0;

        $cart = new Cart(597);
        $cart->id_customer = 597;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 998;
        $cart->id_address_delivery = 999;
        $cart->id_carrier = 597;
        $cart->id_lang = 1;

        StubStore::$cartProducts[597] = [[
            'id_product' => 8397,
            'link_rewrite' => 'odd-decimal-es-product',
            'name' => 'Odd decimal ES product',
            'description_short' => 'Product',
            'manufacturer_name' => 'ACME',
            'ean13' => '',
            'upc' => '',
            'total' => 551.83,
            'total_wt' => 667.72,
            'cart_quantity' => 1,
            'rate' => 21.0,
            'price' => 551.83,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[8397] = [['name' => 'General']];
        StubStore::$images[8397] = ['id_image' => 8397];
        StubStore::$cartShipping[597] = [true => 2.99, false => 2.47];
        StubStore::$cartTotals[597] = [
            true => [
                Cart::ONLY_DISCOUNTS => 36.38,
                Cart::BOTH => 634.33,
                Cart::ONLY_SHIPPING => 2.99,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 30.07,
                Cart::BOTH => 524.23,
                Cart::ONLY_SHIPPING => 2.47,
            ],
            'average_products_tax_rate' => 21.0,
        ];
        StubStore::$cartRules[597] = [
            [
                'name' => 'Envío gratis',
                'code' => 'free-shipping-es',
                'value' => -2.99,
                'value_real' => 2.99,
                'value_tax_exc' => 2.47,
                'free_shipping' => 1,
            ],
            [
                'name' => 'Promo cruzada| 5%',
                'code' => 'promo-cruzada-5',
                'value' => -33.39,
                'value_real' => 33.39,
                'value_tax_exc' => 27.60,
                'free_shipping' => 0,
            ],
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-597', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        TinyAssert::same('634.33', (string)$payload['gross_amount']);
        TinyAssert::same('524.23', (string)$payload['net_amount']);
        TinyAssert::same('110.10', (string)$payload['tax_amount']);

        $aggregatedByRule = [];
        foreach ($payload['line_items'] as $line) {
            if (!isset($line['gross_amount']) || (float)$line['gross_amount'] >= 0) {
                continue;
            }
            $lineName = (string)$line['name'];
            $baseName = preg_replace('/\s+\(VAT\s+[^)]+\)$/', '', $lineName);
            if (!isset($aggregatedByRule[$baseName])) {
                $aggregatedByRule[$baseName] = ['gross' => 0.0, 'net' => 0.0];
            }
            $aggregatedByRule[$baseName]['gross'] = round($aggregatedByRule[$baseName]['gross'] + (float)$line['gross_amount'], 2);
            $aggregatedByRule[$baseName]['net'] = round($aggregatedByRule[$baseName]['net'] + (float)$line['net_amount'], 2);

            $lineRate = (float)$line['tax_rate'];
            TinyAssert::true(abs($lineRate - 0.21) <= 0.000001, 'Expected ES discount tax rate to be canonical 0.21, got: ' . $line['tax_rate']);
        }

        TinyAssert::same('-2.99', number_format($aggregatedByRule['Envío gratis']['gross'], 2, '.', ''));
        TinyAssert::same('-2.47', number_format($aggregatedByRule['Envío gratis']['net'], 2, '.', ''));
        TinyAssert::same('-33.39', number_format($aggregatedByRule['Promo cruzada| 5%']['gross'], 2, '.', ''));
        TinyAssert::same('-27.60', number_format($aggregatedByRule['Promo cruzada| 5%']['net'], 2, '.', ''));
    }

    private static function testGetTwoNewOrderDataSpanishTinyPartialFallbackKeepsCanonicalRates(): void
    {
        self::reset();

        $module = new TwopaymentTestHarness();

        StubStore::$customers[598] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Iria',
            'lastname' => 'Paz',
            'secure_key' => 'secure-key-598',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[1000] = [
            'id_country' => 34,
            'company' => 'SPAIN',
            'companyid' => 'E20468708',
            'address1' => 'Calle Dos 2',
            'city' => 'Madrid',
            'postcode' => '28008',
            'phone' => '666666675',
            'loaded' => true,
        ];
        StubStore::$addresses[1001] = StubStore::$addresses[1000];
        StubStore::$carriers[598] = [
            'name' => 'Carrier 598',
            'delay' => '',
            'shipping_method' => Carrier::SHIPPING_METHOD_PRICE,
            'tax_rules_group_id' => 98,
        ];
        StubStore::$taxRuleRates[98] = 21.0;

        $cart = new Cart(598);
        $cart->id_customer = 598;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 1000;
        $cart->id_address_delivery = 1001;
        $cart->id_carrier = 598;
        $cart->id_lang = 1;

        StubStore::$cartProducts[598] = [[
            'id_product' => 8398,
            'link_rewrite' => 'tiny-partial-fallback-product',
            'name' => 'Tiny partial fallback product',
            'description_short' => 'Product',
            'manufacturer_name' => 'ACME',
            'ean13' => '',
            'upc' => '',
            'total' => 11.55,
            'total_wt' => 13.98,
            'cart_quantity' => 7,
            'rate' => 21.0,
            'price' => 1.65,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[8398] = [['name' => 'General']];
        StubStore::$images[8398] = ['id_image' => 8398];
        StubStore::$cartShipping[598] = [true => 1.21, false => 1.00];
        StubStore::$cartTotals[598] = [
            true => [
                Cart::ONLY_DISCOUNTS => 1.23,
                Cart::BOTH => 13.96,
                Cart::ONLY_SHIPPING => 1.21,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 1.02,
                Cart::BOTH => 11.53,
                Cart::ONLY_SHIPPING => 1.00,
            ],
            'average_products_tax_rate' => 21.0,
        ];
        StubStore::$cartRules[598] = [
            [
                'name' => 'tiny-fixed-2c',
                'code' => 'tiny-fixed-2c',
                'value' => -0.02,
                'value_real' => 0.02,
                'value_tax_exc' => 0.02,
                'free_shipping' => 0,
            ],
            [
                'name' => 'tiny-free-shipping',
                'code' => 'tiny-free-shipping',
                'value' => -1.21,
                'value_real' => 1.21,
                'free_shipping' => 1,
                // Missing net metadata by design.
            ],
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-598', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        TinyAssert::same('13.96', (string)$payload['gross_amount']);
        TinyAssert::same('11.53', (string)$payload['net_amount']);
        TinyAssert::same('2.43', (string)$payload['tax_amount']);

        $tinyFixedGross = 0.0;
        $tinyFixedNet = 0.0;
        $tinyFreeShippingGross = 0.0;
        $tinyFreeShippingNet = 0.0;
        $tinyFreeShippingRate = '';
        foreach ($payload['line_items'] as $line) {
            if (!isset($line['gross_amount']) || (float)$line['gross_amount'] >= 0) {
                continue;
            }

            $lineName = (string)$line['name'];
            if (strpos($lineName, 'tiny-fixed-2c') === 0) {
                $tinyFixedGross = round($tinyFixedGross + (float)$line['gross_amount'], 2);
                $tinyFixedNet = round($tinyFixedNet + (float)$line['net_amount'], 2);
            }
            if (strpos($lineName, 'tiny-free-shipping') === 0) {
                $tinyFreeShippingGross = round($tinyFreeShippingGross + (float)$line['gross_amount'], 2);
                $tinyFreeShippingNet = round($tinyFreeShippingNet + (float)$line['net_amount'], 2);
                $tinyFreeShippingRate = (string)$line['tax_rate'];
            }
        }

        TinyAssert::same('-0.02', number_format($tinyFixedGross, 2, '.', ''), 'Expected tiny complete rule gross to remain exact');
        TinyAssert::same('-0.02', number_format($tinyFixedNet, 2, '.', ''), 'Expected tiny complete rule net to remain exact');
        TinyAssert::same('-1.21', number_format($tinyFreeShippingGross, 2, '.', ''), 'Expected tiny unresolved free-shipping gross to stay on shipping context');
        TinyAssert::same('-1.00', number_format($tinyFreeShippingNet, 2, '.', ''), 'Expected tiny unresolved free-shipping net to stay on shipping context');
        TinyAssert::same('0.21', $tinyFreeShippingRate, 'Expected tiny unresolved free-shipping rate to stay canonical 0.21');
    }

    private static function testGetTwoNewOrderDataSnapsSmallDiscountRateToCanonicalContext(): void
    {
        self::reset();

        $module = new TwopaymentTestHarness();

        StubStore::$customers[496] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Eva',
            'lastname' => 'Garcia',
            'secure_key' => 'secure-key-496',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[950] = [
            'id_country' => 34,
            'company' => 'SPAIN',
            'companyid' => 'E20468708',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '666666668',
            'loaded' => true,
        ];
        StubStore::$addresses[951] = StubStore::$addresses[950];

        $cart = new Cart(496);
        $cart->id_customer = 496;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 950;
        $cart->id_address_delivery = 951;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[496] = [
            [
                'id_product' => 8301,
                'link_rewrite' => 'single-taxed-product',
                'name' => 'Single taxed product',
                'description_short' => 'Product',
                'manufacturer_name' => 'ACME',
                'ean13' => '',
                'upc' => '',
                'total' => 100.00,
                'total_wt' => 121.00,
                'cart_quantity' => 1,
                'rate' => 21.0,
                'price' => 100.00,
                'reduction' => 0,
            ],
        ];
        StubStore::$productCategories[8301] = [['name' => 'General']];
        StubStore::$images[8301] = ['id_image' => 8301];
        StubStore::$cartShipping[496] = [true => 0.00, false => 0.00];
        StubStore::$cartTotals[496] = [
            true => [
                Cart::ONLY_DISCOUNTS => 4.69,
                Cart::BOTH => 116.31,
                Cart::ONLY_SHIPPING => 0.00,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 3.87,
                Cart::BOTH => 96.13,
                Cart::ONLY_SHIPPING => 0.00,
            ],
            'average_products_tax_rate' => 21.0,
        ];
        StubStore::$cartRules[496] = [
            [
                'name' => 'discount-rule-1',
                'code' => 'discount-rule-1',
                'value' => -4.69,
                'value_real' => 4.69,
                'value_tax_exc' => 3.87,
            ],
        ];

        $payload = $module->getTwoNewOrderData('merchant-attempt-496', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        $discountLine = null;
        foreach ($payload['line_items'] as $line) {
            if ((string)$line['name'] === 'discount-rule-1') {
                $discountLine = $line;
                break;
            }
        }

        TinyAssert::true($discountLine !== null, 'Expected discount-rule-1 line item');
        TinyAssert::same('0.21', (string)$discountLine['tax_rate'], 'Expected small discount line to snap to canonical 0.21 rate');
    }

    private static function testMerchantCase1BuildsExpectedOrderPayload(): void
    {
        self::reset();

        $module = new TwopaymentTestHarness();

        StubStore::$customers[6101] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Cliente',
            'lastname' => 'Uno',
            'secure_key' => 'secure-key-6101',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[7101] = [
            'id_country' => 34,
            'company' => 'SPAIN',
            'companyid' => 'E20468708',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '666666668',
            'loaded' => true,
        ];
        StubStore::$addresses[7102] = StubStore::$addresses[7101];
        StubStore::$carriers[710] = [
            'name' => 'My carrier',
            'delay' => 'Delivery next day!',
            'shipping_method' => Carrier::SHIPPING_METHOD_WEIGHT,
            'tax_rules_group_id' => 7,
        ];
        StubStore::$taxRuleRates[7] = 21.0;

        $cart = new Cart(6101);
        $cart->id_customer = 6101;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 7101;
        $cart->id_address_delivery = 7102;
        $cart->id_carrier = 710;
        $cart->id_lang = 1;

        StubStore::$cartProducts[6101] = [[
            'id_product' => 9101,
            'link_rewrite' => 'tv-lg-4k',
            'name' => 'TV LG 4K UHD, SmartTV con IA, 164 cm (65")',
            'description_short' => 'TV',
            'manufacturer_name' => 'LG',
            'ean13' => '',
            'upc' => '',
            'total' => 1320.66,
            'total_wt' => 1598.00,
            'cart_quantity' => 2,
            'rate' => 21.0,
            'price' => 660.33,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[9101] = [['name' => 'TV']];
        StubStore::$images[9101] = ['id_image' => 9101];
        StubStore::$cartShipping[6101] = [
            true => 58.00,
            false => 47.93,
        ];
        StubStore::$cartTotals[6101] = [
            true => [
                Cart::ONLY_DISCOUNTS => 137.90,
                Cart::BOTH => 1518.10,
                Cart::ONLY_SHIPPING => 0.00,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 113.96,
                Cart::BOTH => 1254.63,
                Cart::ONLY_SHIPPING => 0.00,
            ],
            'average_products_tax_rate' => 21.0,
        ];
        StubStore::$cartRules[6101] = [
            [
                'name' => 'Envío gratis',
                'code' => 'free-shipping',
                'value' => -58.00,
                'value_real' => 58.00,
                'value_tax_exc' => 47.93,
                'free_shipping' => 1,
            ],
            [
                'name' => 'Promo cruzada| 5%',
                'code' => 'cross-promo',
                'value' => -79.90,
                'value_real' => 79.90,
                'value_tax_exc' => 66.03,
                'free_shipping' => 0,
            ],
        ];

        $payload = $module->getTwoNewOrderData('merchant-case-1', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        TinyAssert::same('1518.10', (string)$payload['gross_amount']);
        TinyAssert::same('1254.63', (string)$payload['net_amount']);
        TinyAssert::same('263.47', (string)$payload['tax_amount']);

        $shippingSeen = false;
        $freeShippingDiscountSeen = false;
        $promoDiscountSeen = false;
        foreach ($payload['line_items'] as $line) {
            if ((string)$line['type'] === 'SHIPPING_FEE') {
                $shippingSeen = true;
                TinyAssert::same('58.00', (string)$line['gross_amount']);
            }
            if ((string)$line['name'] === 'Envío gratis') {
                $freeShippingDiscountSeen = true;
                TinyAssert::same('-58.00', (string)$line['gross_amount']);
            }
            if ((string)$line['name'] === 'Promo cruzada| 5%') {
                $promoDiscountSeen = true;
                TinyAssert::same('-79.90', (string)$line['gross_amount']);
            }
        }
        TinyAssert::true($shippingSeen, 'Expected shipping line');
        TinyAssert::true($freeShippingDiscountSeen, 'Expected free shipping discount line');
        TinyAssert::true($promoDiscountSeen, 'Expected promo discount line');
    }

    private static function testMerchantCase2BlocksOnInconsistentOrderTotals(): void
    {
        self::reset();

        $module = new TwopaymentTestHarness();

        StubStore::$customers[6102] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Cliente',
            'lastname' => 'Dos',
            'secure_key' => 'secure-key-6102',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[7201] = [
            'id_country' => 34,
            'company' => 'SPAIN',
            'companyid' => 'E20468708',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '666666668',
            'loaded' => true,
        ];
        StubStore::$addresses[7202] = StubStore::$addresses[7201];
        StubStore::$carriers[720] = [
            'name' => 'Carrier',
            'delay' => '',
            'shipping_method' => Carrier::SHIPPING_METHOD_PRICE,
            'tax_rules_group_id' => 7,
        ];
        StubStore::$taxRuleRates[7] = 21.0;

        $cart = new Cart(6102);
        $cart->id_customer = 6102;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 7201;
        $cart->id_address_delivery = 7202;
        $cart->id_carrier = 720;
        $cart->id_lang = 1;

        StubStore::$cartProducts[6102] = [[
            'id_product' => 9201,
            'link_rewrite' => 'lg-projector',
            'name' => 'LG CineBeam LED Projector with SmartTV WebOS',
            'description_short' => 'Projector',
            'manufacturer_name' => 'LG',
            'ean13' => '',
            'upc' => '',
            'total' => 548.53,
            'total_wt' => 663.72,
            'cart_quantity' => 1,
            'rate' => 21.0,
            'price' => 548.53,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[9201] = [['name' => 'Projectors']];
        StubStore::$images[9201] = ['id_image' => 9201];
        StubStore::$cartShipping[6102] = [
            true => 2.99,
            false => 2.47,
        ];
        // Intentionally inconsistent with line totals to mimic merchant case 2.
        StubStore::$cartTotals[6102] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::BOTH => 25610.36,
                Cart::ONLY_SHIPPING => 2.99,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::BOTH => 21165.59,
                Cart::ONLY_SHIPPING => 2.47,
            ],
            'average_products_tax_rate' => 21.0,
        ];

        TinyAssert::throws(function () use ($module, $cart) {
            $module->getTwoNewOrderData('merchant-case-2', $cart, [
                'merchant_confirmation_url' => 'https://shop.local/confirm',
                'merchant_cancel_order_url' => 'https://shop.local/cancel',
                'merchant_edit_order_url' => '',
                'merchant_order_verification_failed_url' => '',
                'merchant_invoice_url' => '',
                'merchant_shipping_document_url' => '',
            ]);
        }, 'Order totals do not reconcile with cart totals');
    }

    private static function testMerchantCase3BuildsSimpleOrderPayload(): void
    {
        self::reset();

        $module = new TwopaymentTestHarness();

        StubStore::$customers[6103] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Cliente',
            'lastname' => 'Tres',
            'secure_key' => 'secure-key-6103',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[7301] = [
            'id_country' => 34,
            'company' => 'SPAIN',
            'companyid' => 'E20468708',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '666666668',
            'loaded' => true,
        ];
        StubStore::$addresses[7302] = StubStore::$addresses[7301];
        StubStore::$carriers[730] = [
            'name' => 'Carrier',
            'delay' => '',
            'shipping_method' => Carrier::SHIPPING_METHOD_PRICE,
            'tax_rules_group_id' => 7,
        ];
        StubStore::$taxRuleRates[7] = 21.0;

        $cart = new Cart(6103);
        $cart->id_customer = 6103;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 7301;
        $cart->id_address_delivery = 7302;
        $cart->id_carrier = 730;
        $cart->id_lang = 1;

        StubStore::$cartProducts[6103] = [[
            'id_product' => 9301,
            'link_rewrite' => 'lg-xboom',
            'name' => 'LG XBOOM High Voltage Speaker, 1000W',
            'description_short' => 'Speaker',
            'manufacturer_name' => 'LG',
            'ean13' => '',
            'upc' => '',
            'total' => 409.24,
            'total_wt' => 495.18,
            'cart_quantity' => 1,
            'rate' => 21.0,
            'price' => 409.24,
            'reduction' => 0,
        ]];
        StubStore::$productCategories[9301] = [['name' => 'Audio']];
        StubStore::$images[9301] = ['id_image' => 9301];
        StubStore::$cartShipping[6103] = [
            true => 2.99,
            false => 2.47,
        ];
        StubStore::$cartTotals[6103] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::BOTH => 498.17,
                Cart::ONLY_SHIPPING => 2.99,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::BOTH => 411.71,
                Cart::ONLY_SHIPPING => 2.47,
            ],
            'average_products_tax_rate' => 21.0,
        ];

        $payload = $module->getTwoNewOrderData('merchant-case-3', $cart, [
            'merchant_confirmation_url' => 'https://shop.local/confirm',
            'merchant_cancel_order_url' => 'https://shop.local/cancel',
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        ]);

        TinyAssert::same('498.17', (string)$payload['gross_amount']);
        TinyAssert::same('411.71', (string)$payload['net_amount']);
        TinyAssert::same('86.46', (string)$payload['tax_amount']);

        $hasProduct = false;
        $hasShipping = false;
        foreach ($payload['line_items'] as $line) {
            if ((string)$line['type'] === 'PHYSICAL') {
                $hasProduct = true;
            }
            if ((string)$line['type'] === 'SHIPPING_FEE') {
                $hasShipping = true;
                TinyAssert::same('2.99', (string)$line['gross_amount']);
                TinyAssert::same('0.21', (string)$line['tax_rate']);
            }
        }
        TinyAssert::true($hasProduct, 'Expected product line');
        TinyAssert::true($hasShipping, 'Expected shipping line');
    }

    private static function testGetTwoRequestHeadersSkipApiKeyForOrderIntent(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $orderIntentHeaders = $module->getTwoRequestHeaders('/v1/order_intent');
        $orderIntentHeadersWithExtras = $module->getTwoRequestHeaders(
            '/v1/order_intent',
            ['Authorization: Bearer should-not-leak', 'X-API-Key: should-not-leak']
        );
        $createOrderHeaders = $module->getTwoRequestHeaders('/v1/order');

        $orderIntentHasApiKey = false;
        foreach ($orderIntentHeaders as $header) {
            if (strpos($header, 'X-API-Key:') === 0) {
                $orderIntentHasApiKey = true;
                break;
            }
        }

        $orderIntentHasAuthHeaders = false;
        foreach ($orderIntentHeadersWithExtras as $header) {
            if (
                strpos($header, 'X-API-Key:') === 0 ||
                strpos($header, 'Authorization:') === 0 ||
                strpos($header, 'Proxy-Authorization:') === 0
            ) {
                $orderIntentHasAuthHeaders = true;
                break;
            }
        }

        $createOrderHasApiKey = false;
        foreach ($createOrderHeaders as $header) {
            if (strpos($header, 'X-API-Key:') === 0) {
                $createOrderHasApiKey = true;
                break;
            }
        }

        TinyAssert::false($orderIntentHasApiKey, 'Order intent headers must not include X-API-Key');
        TinyAssert::false($orderIntentHasAuthHeaders, 'Order intent headers must not include auth headers');
        TinyAssert::true($createOrderHasApiKey, 'Create order headers must include X-API-Key');
    }

    private static function testCheckTwoOrderIntentApprovalAtPaymentDeclinesEvenWhenFrontendCookieSaysApproved(): void
    {
        self::reset();

        $module = new class extends TwopaymentTestHarness {
            public function getTwoIntentOrderData($cart, $customer, $currency, $address)
            {
                return ['currency' => 'EUR'];
            }

            protected function shouldRunStrictOrderIntentParityAtPayment()
            {
                return false;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                return [
                    'http_status' => 200,
                    'approved' => false,
                ];
            }
        };

        $module->context->cookie->two_order_intent_approved = '1';
        $module->context->cookie->two_order_intent_timestamp = (string) time();

        $result = $module->checkTwoOrderIntentApprovalAtPayment(new Cart(1), new Customer(), new Currency(), new Address());

        TinyAssert::false($result['approved'], 'Expected backend decline to override frontend cookie telemetry');
        TinyAssert::same('declined', $result['status']);
    }

    private static function testCheckTwoOrderIntentApprovalAtPaymentAllowsApprovedResponse(): void
    {
        self::reset();

        $module = new class extends TwopaymentTestHarness {
            public function getTwoIntentOrderData($cart, $customer, $currency, $address)
            {
                return ['currency' => 'EUR'];
            }

            protected function shouldRunStrictOrderIntentParityAtPayment()
            {
                return false;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                return [
                    'http_status' => 200,
                    'approved' => true,
                    'message' => 'ok',
                ];
            }
        };

        $result = $module->checkTwoOrderIntentApprovalAtPayment(new Cart(1), new Customer(), new Currency(), new Address());

        TinyAssert::true($result['approved']);
        TinyAssert::same('approved', $result['status']);
    }

    private static function testCheckTwoOrderIntentApprovalAtPaymentHandlesProviderNetworkFailure(): void
    {
        self::reset();

        $module = new class extends TwopaymentTestHarness {
            public function getTwoIntentOrderData($cart, $customer, $currency, $address)
            {
                return ['currency' => 'EUR'];
            }

            protected function shouldRunStrictOrderIntentParityAtPayment()
            {
                return false;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                return [
                    'http_status' => 0,
                    'error' => 'Connection error',
                    'error_message' => 'Unable to connect',
                ];
            }
        };

        $result = $module->checkTwoOrderIntentApprovalAtPayment(new Cart(1), new Customer(), new Currency(), new Address());

        TinyAssert::false($result['approved']);
        TinyAssert::same('provider_unavailable', $result['status']);
    }

    private static function testCheckTwoOrderIntentApprovalAtPaymentBlocksOnStrictReconciliationDrift(): void
    {
        self::reset();

        $lineItems = [[
            'name' => 'Widget',
            'description' => 'Product',
            'gross_amount' => '121.00',
            'net_amount' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '21.00',
            'tax_class_name' => 'VAT 21.00%',
            'tax_rate' => '0.21',
            'unit_price' => '100.00',
            'quantity' => 1,
            'quantity_unit' => 'pcs',
            'image_url' => '',
            'product_page_url' => '',
            'type' => 'PHYSICAL',
            'details' => ['brand' => null, 'barcodes' => [], 'categories' => []],
        ]];

        $module = new class($lineItems) extends TwopaymentTestHarness {
            private array $forcedLineItems;
            public bool $providerCalled = false;

            public function __construct(array $forcedLineItems)
            {
                parent::__construct();
                $this->forcedLineItems = $forcedLineItems;
            }

            public function getTwoProductItems($cart)
            {
                return $this->forcedLineItems;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                $this->providerCalled = true;
                return [
                    'http_status' => 200,
                    'approved' => true,
                ];
            }
        };

        StubStore::$customers[781] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Ana',
            'lastname' => 'Garcia',
            'secure_key' => 'secure-key-781',
            'loaded' => true,
        ];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[1781] = [
            'id_country' => 34,
            'company' => 'ACME S.L.',
            'companyid' => 'B12345678',
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34910000000',
            'loaded' => true,
        ];

        $cart = new Cart(781);
        $cart->id_customer = 781;
        $cart->id_currency = 978;
        $cart->id_address_invoice = 1781;
        $cart->id_address_delivery = 1781;
        $cart->id_carrier = 0;
        $cart->id_lang = 1;

        StubStore::$cartProducts[781] = [['id_product' => 1, 'cart_quantity' => 1]];
        StubStore::$cartTotals[781] = [
            true => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::BOTH => 121.20,
            ],
            false => [
                Cart::ONLY_DISCOUNTS => 0.00,
                Cart::BOTH => 100.00,
            ],
            'average_products_tax_rate' => 21.0,
        ];

        $result = $module->checkTwoOrderIntentApprovalAtPayment($cart, new Customer(781), new Currency(978), new Address(1781));

        TinyAssert::false($result['approved']);
        TinyAssert::same('reconciliation_mismatch', $result['status']);
        TinyAssert::false($module->providerCalled);
    }

    private static function testCreateTwoLocalOrderAfterProviderVerificationRecoversExistingOrderOnRace(): void
    {
        self::reset();

        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$customers[882] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Luis',
            'lastname' => 'Ramos',
            'secure_key' => 'secure-key-882',
            'loaded' => true,
        ];

        $cart = new Cart(882);
        $cart->id_customer = 882;
        $cart->id_currency = 978;

        $module = new class extends TwopaymentTestHarness {
            public function validateOrder(
                $id_cart,
                $id_order_state,
                $amount_paid,
                $payment_method = 'Unknown',
                $message = null,
                $extra_vars = [],
                $currency_special = null,
                $dont_touch_amount = false,
                $secure_key = false,
                ?Shop $shop = null,
                ?string $order_reference = null
            ) {
                throw new Exception('Cart cannot be loaded or an order has already been placed using this cart');
            }

            public function getTwoOrderIdByCart($id_cart)
            {
                return 445;
            }
        };

        $result = $module->createTwoLocalOrderAfterProviderVerification(
            $cart,
            new Customer(882),
            1,
            121.00
        );

        TinyAssert::true($result['success']);
        TinyAssert::same(445, (int) $result['id_order']);
        TinyAssert::true($result['recovered_existing']);
    }

    private static function testCreateTwoLocalOrderAfterProviderVerificationFailsWhenNoRecoverableOrderExists(): void
    {
        self::reset();

        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$customers[883] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Luis',
            'lastname' => 'Ramos',
            'secure_key' => 'secure-key-883',
            'loaded' => true,
        ];

        $cart = new Cart(883);
        $cart->id_customer = 883;
        $cart->id_currency = 978;

        $module = new class extends TwopaymentTestHarness {
            public function validateOrder(
                $id_cart,
                $id_order_state,
                $amount_paid,
                $payment_method = 'Unknown',
                $message = null,
                $extra_vars = [],
                $currency_special = null,
                $dont_touch_amount = false,
                $secure_key = false,
                ?Shop $shop = null,
                ?string $order_reference = null
            ) {
                throw new Exception('cart exception');
            }

            public function getTwoOrderIdByCart($id_cart)
            {
                return 0;
            }
        };

        $result = $module->createTwoLocalOrderAfterProviderVerification(
            $cart,
            new Customer(883),
            1,
            121.00
        );

        TinyAssert::false($result['success']);
        TinyAssert::same(0, (int) $result['id_order']);
        TinyAssert::false($result['recovered_existing']);
    }

    private static function testCancelTwoOrderBestEffortReturnsTrueOnSuccessAndFalseOnFailure(): void
    {
        self::reset();

        $successModule = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                return ['http_status' => 200];
            }
        };
        TinyAssert::true($successModule->cancelTwoOrderBestEffort('two-success', 'test'));

        $failureModule = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
            {
                return ['http_status' => 500];
            }
        };
        TinyAssert::false($failureModule->cancelTwoOrderBestEffort('two-failure', 'test'));
    }

    private static function testExtractTwoProviderGrossAmountForValidationSupportsRootAndNestedPayloads(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::same(121.10, $module->extractTwoProviderGrossAmountForValidation(['gross_amount' => '121.10']));
        TinyAssert::same(1518.10, $module->extractTwoProviderGrossAmountForValidation(['data' => ['gross_amount' => '1518.10']]));
        TinyAssert::same(null, $module->extractTwoProviderGrossAmountForValidation(['gross_amount' => '']));
    }

    private static function testSnapshotHashIgnoresTaxRateChangesBeyondTwoDecimals(): void
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

        TinyAssert::same($hashA, $hashB);
    }

    private static function testBuildTwoOrderCreateIdempotencyKeyIsDeterministicForSameSnapshot(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $cart = new Cart(991);
        $cart->id_customer = 123;

        $keyA = $module->buildTwoOrderCreateIdempotencyKey($cart, 'snapshot-hash-1');
        $keyB = $module->buildTwoOrderCreateIdempotencyKey($cart, 'snapshot-hash-1');
        $keyC = $module->buildTwoOrderCreateIdempotencyKey($cart, 'snapshot-hash-2');

        TinyAssert::same($keyA, $keyB);
        TinyAssert::notSame($keyA, $keyC);
    }

    private static function testHasTwoOrderRebindingConflictDetectsMismatchedTwoOrderIds(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::true($module->hasTwoOrderRebindingConflict([
            'two_order_id' => 'two-existing-1',
        ], 'two-incoming-2'));

        TinyAssert::false($module->hasTwoOrderRebindingConflict([
            'two_order_id' => 'two-existing-1',
        ], 'two-existing-1'));

        TinyAssert::false($module->hasTwoOrderRebindingConflict([
            'two_order_id' => '',
        ], 'two-incoming-2'));
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

    private static function testGetTwoBuyerPortalUrlUsesEnvironmentSpecificBuyerDomains(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'production');
        TinyAssert::same('https://buyer.two.inc/login', $module->getTwoBuyerPortalUrl());

        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'development');
        TinyAssert::same('https://buyer.sandbox.two.inc/login', $module->getTwoBuyerPortalUrl());

        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'staging');
        TinyAssert::same('https://buyer.sandbox.two.inc/login', $module->getTwoBuyerPortalUrl());
    }

    private static function testResolveTwoAttemptOrderIdForCancellationPrefersAttemptOrderId(): void
    {
        self::reset();
        $module = new class extends TwopaymentTestHarness {
            public function getTwoOrderIdByCart($id_cart)
            {
                return 777;
            }
        };

        $attempt = [
            'id_order' => 321,
            'id_cart' => 123,
        ];

        TinyAssert::same(321, $module->resolveTwoAttemptOrderIdForCancellation($attempt));
    }

    private static function testResolveTwoAttemptOrderIdForCancellationFallsBackToCartOrderId(): void
    {
        self::reset();
        $module = new class extends TwopaymentTestHarness {
            public function getTwoOrderIdByCart($id_cart)
            {
                return ((int)$id_cart === 123) ? 654 : 0;
            }
        };

        $attempt = [
            'id_order' => 0,
            'id_cart' => 123,
        ];

        TinyAssert::same(654, $module->resolveTwoAttemptOrderIdForCancellation($attempt));
    }

    private static function testShouldBlockTwoAttemptConfirmationByStatusOnlyForCancelled(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::true($module->shouldBlockTwoAttemptConfirmationByStatus('CANCELLED'));
        TinyAssert::true($module->shouldBlockTwoAttemptConfirmationByStatus('cancelled'));
        TinyAssert::false($module->shouldBlockTwoAttemptConfirmationByStatus('CREATED'));
        TinyAssert::false($module->shouldBlockTwoAttemptConfirmationByStatus('CONFIRMED'));
    }

    private static function testIsTwoAttemptStatusTerminalMatchesCancelledGuard(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::true($module->isTwoAttemptStatusTerminal('CANCELLED'));
        TinyAssert::false($module->isTwoAttemptStatusTerminal('CONFIRMED'));
    }

    private static function testGetTwoCancelledOrderStatusIdUsesConfiguredFallbackChain(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        Configuration::updateValue('PS_TWO_OS_CANCELLED', 901);
        Configuration::updateValue('PS_TWO_OS_CANCELLED_MAP', 902);
        Configuration::updateValue('PS_OS_CANCELED', 903);
        TinyAssert::same(901, $module->getTwoCancelledOrderStatusId());

        Configuration::updateValue('PS_TWO_OS_CANCELLED', 0);
        TinyAssert::same(902, $module->getTwoCancelledOrderStatusId());

        Configuration::updateValue('PS_TWO_OS_CANCELLED_MAP', 0);
        TinyAssert::same(903, $module->getTwoCancelledOrderStatusId());
    }

    private static function testHasTwoProviderOrderMappingRequiresNonEmptyTwoOrderId(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::false($module->hasTwoProviderOrderMapping(false));
        TinyAssert::false($module->hasTwoProviderOrderMapping([]));
        TinyAssert::false($module->hasTwoProviderOrderMapping([
            'two_order_id' => '',
        ]));
        TinyAssert::false($module->hasTwoProviderOrderMapping([
            'two_order_id' => '   ',
        ]));
        TinyAssert::true($module->hasTwoProviderOrderMapping([
            'two_order_id' => 'two-order-123',
        ]));
    }

    private static function testSyncLocalOrderStatusFromTwoStateCancelsOnlyWhenProviderCancelled(): void
    {
        self::reset();
        $module = new class extends TwopaymentTestHarness {
            public $calls = [];

            public function changeOrderStatus($id_order, $id_order_status)
            {
                $this->calls[] = [(int)$id_order, (int)$id_order_status];
                return true;
            }
        };

        Configuration::updateValue('PS_TWO_OS_CANCELLED', 901);
        TinyAssert::true($module->syncLocalOrderStatusFromTwoState(55, 'CANCELLED'));
        TinyAssert::count(1, $module->calls);
        TinyAssert::same([55, 901], $module->calls[0]);

        TinyAssert::false($module->syncLocalOrderStatusFromTwoState(56, 'CONFIRMED'));
        TinyAssert::count(1, $module->calls);
    }

    private static function testIsTwoOrderCancelledResponseRequires2xxAndCancelledState(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::true($module->isTwoOrderCancelledResponse([
            'http_status' => 200,
            'state' => 'CANCELLED',
        ]));

        TinyAssert::false($module->isTwoOrderCancelledResponse([
            'http_status' => 200,
            'state' => 'CONFIRMED',
        ]));

        TinyAssert::false($module->isTwoOrderCancelledResponse([
            'http_status' => 500,
            'state' => 'CANCELLED',
        ]));

        TinyAssert::false($module->isTwoOrderCancelledResponse([], 200));
    }

    private static function testShouldBlockTwoFulfillmentByTwoStateOnlyForCancelled(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::true($module->shouldBlockTwoFulfillmentByTwoState('CANCELLED'));
        TinyAssert::true($module->shouldBlockTwoFulfillmentByTwoState('cancelled'));
        TinyAssert::false($module->shouldBlockTwoFulfillmentByTwoState('CONFIRMED'));
        TinyAssert::false($module->shouldBlockTwoFulfillmentByTwoState(''));
    }

    private static function testShouldBlockTwoStatusTransitionByCancelledStateCoversVerifiedAndFulfillment(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT', 901);
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode([4]));
        Configuration::updateValue('PS_OS_SHIPPING', 4);

        TinyAssert::true($module->shouldBlockTwoStatusTransitionByCancelledState(901));
        TinyAssert::true($module->shouldBlockTwoStatusTransitionByCancelledState(4));
        TinyAssert::false($module->shouldBlockTwoStatusTransitionByCancelledState(99));
    }

    private static function testIsTwoOrderFulfillableStateRequiresConfirmed(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::true($module->isTwoOrderFulfillableState('CONFIRMED'));
        TinyAssert::true($module->isTwoOrderFulfillableState('confirmed'));
        TinyAssert::false($module->isTwoOrderFulfillableState('CANCELLED'));
        TinyAssert::false($module->isTwoOrderFulfillableState('VERIFIED'));
    }

    private static function testAddTwoBackOfficeWarningAppendsUniqueWarning(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $module->context->controller = (object) ['warnings' => []];

        TinyAssert::true($module->addTwoBackOfficeWarning('Fulfillment blocked warning'));
        TinyAssert::count(1, $module->context->controller->warnings);

        TinyAssert::true($module->addTwoBackOfficeWarning('Fulfillment blocked warning'));
        TinyAssert::count(1, $module->context->controller->warnings);
    }

    private static function testAddTwoBackOfficeWarningReturnsFalseWhenNoController(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $module->context->controller = null;

        TinyAssert::false($module->addTwoBackOfficeWarning('Fulfillment blocked warning'));
    }

    private static function testApplyTwoCancelledOrderStateProfileToStatusObjectUsesConfiguredCancelledState(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue('PS_TWO_OS_CANCELLED', 901);

        $status = (object) [
            'id' => 4,
            'shipped' => 1,
            'logable' => 1,
        ];

        TinyAssert::true($module->applyTwoCancelledOrderStateProfileToStatusObject($status, 1));
        TinyAssert::same(901, (int)$status->id);
        TinyAssert::same(0, (int)$status->shipped);
        TinyAssert::same(0, (int)$status->logable);
    }

    private static function testForceTwoCancelledOrderHistoryStateBeforeInsertRewritesPendingStatus(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        Configuration::updateValue('PS_TWO_OS_CANCELLED', 901);

        $history = (object) [
            'id_order_state' => 4,
            'logable' => 1,
        ];

        $order = new class {
            public $loaded = true;
            public $id_lang = 1;
            public $current_state = 4;
            public $valid = true;
            public $updated = false;

            public function update()
            {
                $this->updated = true;
                return true;
            }
        };

        TinyAssert::true($module->forceTwoCancelledOrderHistoryStateBeforeInsert($history, $order, 'two-order-1', 'provider', 'CANCELLED'));
        TinyAssert::same(901, (int)$history->id_order_state);
        TinyAssert::same(901, (int)$order->current_state);
        TinyAssert::false((bool)$order->valid);
        TinyAssert::true($order->updated);
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

    private static function testGetTwoCheckoutCompanyDataPrefersCurrentAddressOrgNumberOverSessionCompany(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // Stale session from previously selected UK address/company
        $module->context->cookie->two_company_name = 'CHEESE AND BEES LTD';
        $module->context->cookie->two_company_id = 'SC806781';
        $module->context->cookie->two_company_country = 'GB';
        $module->context->cookie->two_company_address_id = '28';

        // Current selected address is Spanish and has org number in VAT field
        StubStore::$countries[34] = 'ES';
        StubStore::$addresses[29] = [
            'id_country' => 34,
            'company' => 'Queso y Abejas S.L.',
            'vat_number' => 'ESB12345678',
            'loaded' => true,
        ];

        $address = new Address(29);
        $data = $module->getTwoCheckoutCompanyData($address);

        TinyAssert::same('Queso y Abejas S.L.', $data['company_name']);
        TinyAssert::same('B12345678', $data['organization_number']);
        TinyAssert::same('ES', $data['country_iso']);
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

    private static function testGetTwoCheckoutCompanyDataIgnoresStaleCookieWhenAddressCompanyChangesSameCountry(): void
    {
        self::reset();
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

        TinyAssert::same('Beta Industrial S.L.', $data['company_name']);
        TinyAssert::same('', $data['organization_number']);
        TinyAssert::same('ES', $data['country_iso']);
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
        Configuration::updateValue('PS_TWO_ENABLE_TAX_SUBTOTALS', 1);
        Tools::setTestValue('PS_TWO_DISABLE_SSL_VERIFY', 1);
        Tools::setTestValue('PS_TWO_ENABLE_TAX_SUBTOTALS', 0);

        $module->saveOtherForTest();

        TinyAssert::same(1, (int) Configuration::get('PS_TWO_DISABLE_SSL_VERIFY'));
        TinyAssert::same(0, (int) Configuration::get('PS_TWO_ENABLE_TAX_SUBTOTALS'));
    }

    private static function testOtherSettingsFormDoesNotExposeOrderIntentToggle(): void
    {
        self::reset();
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

        TinyAssert::false(in_array('PS_TWO_ENABLE_ORDER_INTENT', $inputNames, true));
        TinyAssert::true(in_array('PS_TWO_ENABLE_TAX_SUBTOTALS', $inputNames, true));
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

    private static function testHookPaymentOptionsBlocksWhenAccountTypeMissingInStrictMode(): void
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
        $cart->id_currency = 978;
        $module->context->cart = $cart;
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$moduleCurrencies['twopayment'] = [['id_currency' => 978]];

        $options = $module->hookPaymentOptions([]);

        TinyAssert::same(0, count($options));
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
        $cart->id_currency = 978;
        $module->context->cart = $cart;
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$moduleCurrencies['twopayment'] = [['id_currency' => 978]];

        $options = $module->hookPaymentOptions([]);

        TinyAssert::same(0, count($options));
    }

    private static function testHookPaymentOptionsAllowsTwoCoveredCurrencies(): void
    {
        self::reset();
        $module = new class extends TwopaymentTestHarness {
            protected function getTwoPaymentOption()
            {
                return (object) ['method' => 'two'];
            }
        };

        $module->active = true;
        Configuration::updateValue('PS_TWO_USE_ACCOUNT_TYPE', 0);
        StubStore::$countries[826] = 'GB';
        StubStore::$addresses[904] = [
            'id_country' => 826,
            'company' => 'Acme UK Ltd',
            'vat_number' => 'GB123456789',
            'loaded' => true,
        ];

        $covered = [
            578 => 'NOK',
            826 => 'GBP',
            752 => 'SEK',
            840 => 'USD',
            208 => 'DKK',
            978 => 'EUR',
        ];

        foreach ($covered as $idCurrency => $iso) {
            StubStore::$currencies[$idCurrency] = ['iso_code' => $iso, 'loaded' => true];
            StubStore::$moduleCurrencies['twopayment'] = [['id_currency' => $idCurrency]];

            $cart = new Cart(504 + $idCurrency);
            $cart->id_address_invoice = 904;
            $cart->id_currency = $idCurrency;
            $module->context->cart = $cart;

            $options = $module->hookPaymentOptions([]);
            TinyAssert::same(1, count($options), 'Expected covered currency to be allowed: ' . $iso);
        }
    }

    private static function testHookPaymentOptionsBlocksUnsupportedCurrency(): void
    {
        self::reset();
        $module = new class extends TwopaymentTestHarness {
            protected function getTwoPaymentOption()
            {
                return (object) ['method' => 'two'];
            }
        };

        $module->active = true;
        Configuration::updateValue('PS_TWO_USE_ACCOUNT_TYPE', 0);
        StubStore::$countries[826] = 'GB';
        StubStore::$addresses[903] = [
            'id_country' => 826,
            'company' => 'Acme UK Ltd',
            'vat_number' => 'GB123456789',
            'loaded' => true,
        ];
        StubStore::$currencies[392] = ['iso_code' => 'JPY', 'loaded' => true];
        StubStore::$moduleCurrencies['twopayment'] = [['id_currency' => 392]];

        $cart = new Cart(503);
        $cart->id_address_invoice = 903;
        $cart->id_currency = 392;
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
                'status' => 'CANCELLED',
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
        TinyAssert::true(strpos(StubStore::$dbLastExecuteS[0], '`status`') !== false);
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

    private static function testExtractOrgNumberFromAddressKeepsNonCountryPrefixVatNumber(): void
    {
        self::reset();
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

        TinyAssert::same('SC806781', $orgNumber);
    }

    private static function testExtractOrgNumberFromAddressStripsMatchingCountryPrefixVatNumber(): void
    {
        self::reset();
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

        TinyAssert::same('123456789', $orgNumber);
    }
}

require __DIR__ . '/CustomerAddressFormatterOverrideSpec.php';

$tests = [
    'OrderBuilderSpec::runAll' => [OrderBuilderSpec::class, 'runAll'],
    'CustomerAddressFormatterOverrideSpec::runAll' => [CustomerAddressFormatterOverrideSpec::class, 'runAll'],
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
