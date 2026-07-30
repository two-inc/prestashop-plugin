<?php

declare(strict_types=1);

/**
 * Offset pricing fee (buyer surcharge) + brand-driven rounding relay.
 * TWO-24752 (offset fee) and TWO-24893 (rounding basis + brand step).
 *
 * Covers: buyer_fee_share payload construction per term, the rounding relay
 * and its edge cases, the fee-quote fetch (fail-soft), fee-line construction
 * with the merchant-selected TaxRulesGroup's destination-resolved rate
 * (CONFIG_SURCHARGE_TAX_RULES_GROUP via getTwoSurchargeTaxRateForCart — the
 * pricing-preview response's total_fee_tax_rate is never a source), and
 * end-to-end injection into the order payload including a rounding-boundary
 * amount.
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
        self::testPaymentTermCheckboxLabelsNeverCarrySurchargePreview();
        self::testSurchargeGridRendersEveryOfferableTermRowWithVisibilityState();
        self::testFetchTermFeeFailsSoftOnHttpError();
        self::testFetchTermFeeFailsSoftOnCurrencyMismatch();
        self::testFetchTermFeeParsesSuccess();
        self::testSurchargeTaxRulesGroupIdReadsAdminConfig();
        self::testSurchargeTaxRateForCartResolvesDestinationThroughCoreGates();
        self::testSurchargeTaxRulesGroupOptionsNeverDropTheConfiguredSelection();
        self::testSurchargeTaxRulesGroupOptionsNeverOfferTheNeverTaxedSentinel();
        self::testNeverTaxedPredicateMatchesTheSentinelOnly();
        self::testNeverTaxedNoticeReportsAStoredSentinel();
        self::testSurchargeTaxRulesGroupFormDefaultRequiresExplicitChoice();
        self::testSurchargeTaxTreatmentRequiredWhenSurchargesEnabled();
        self::testSurchargeCapOfZeroIsRefusedOnSave();
        self::testConfiguredZeroCapIsRelayedVerbatimAndAbsenceMeansUncapped();
        self::testMonetaryMembersAreRoundedToTwoDecimalPlaces();
        self::testUpgrade250FlagsFlatRateShopsForTaxReselection();
        self::testSurchargeTaxMigrationNoticeLifecycle();
        self::testSurchargeLineItemUsesSelectedGroupDestinationRate();
        self::testSurchargeLineItemIgnoresApiTaxRateEntirely();
        self::testSurchargeLineItemDisabledReturnsNull();
        self::testSurchargeLineItemRoundsTaxOnBoundary();
        self::testSurchargeLineItemTaxRateSelfConsistentAtHighPrecision();
        self::testSurchargeLineItemHonorsExplicitTermOverride();
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
            // limit null, not 0: a limit of 0 is now a real configured cap
            // (relayed as cap => 0). Absence is what means "no cap".
            'grid' => [30 => ['percentage' => 2.5, 'fixed' => 0, 'limit' => null]],
            'rounding_basis' => 'none',
            'rounding_step' => null,
        ];
        $share = TwoSurchargeCalculator::buildBuyerFeeShare($settings, 30, 30, false);
        TinyAssert::same(2.5, $share['percentage']);
        TinyAssert::same('buyer_pays', $share['surcharge_basis']);
        TinyAssert::false(isset($share['surcharge']), 'percentage-only must not send a fixed surcharge');
        TinyAssert::false(isset($share['cap']), 'no cap when no limit is configured');
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
            'grid' => [30 => ['percentage' => 1.5, 'fixed' => 2.0, 'limit' => null]],
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
        // Default (no config, brand label null): term-naming default label.
        TinyAssert::same('Payment terms fee - 30 days', $module->getTwoSurchargeLineLabel(30));
        TinyAssert::same('Payment terms fee - 60 days', $module->getTwoSurchargeLineLabel(60));
        // Merchant template with %s term substitution still wins over the default.
        Configuration::updateValue('PS_TWO_SURCHARGE_LINE_DESC', 'Financing fee (%s days)');
        TinyAssert::same('Financing fee (30 days)', $module->getTwoSurchargeLineLabel(30));
    }

    /**
     * Regression: the admin "Available Payment Terms" checkbox
     * labels (buildPaymentTermCheckboxQuery) must never carry a buyer
     * surcharge preview, even when a non-zero surcharge is configured. That
     * screen is where the merchant picks which terms to OFFER, not a place
     * to preview what the BUYER will be charged - conflating the two showed
     * the wrong fee concept next to each term. (The configured-rate preview
     * itself has since been removed everywhere: the checkout chip now shows
     * a loading indicator until the real quoted amount resolves.)
     */
    private static function testPaymentTermCheckboxLabelsNeverCarrySurchargePreview(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1);
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_60', 1);
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2.5');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_60', '5');

        $module = new class extends TwopaymentTestHarness {
            public function buildPaymentTermCheckboxQueryPublic(): array
            {
                return $this->buildPaymentTermCheckboxQuery();
            }
        };

        $rows = $module->buildPaymentTermCheckboxQueryPublic();
        TinyAssert::true(count($rows) > 0, 'expected at least one offered term');
        foreach ($rows as $row) {
            TinyAssert::false(
                strpos($row['name'], '(') !== false,
                'checkbox label must not carry a surcharge preview: ' . $row['name']
            );
            // The label is the plain "%d days" text plus an EMPTY merchant-fee
            // placeholder span - populated client-side from the merchant-rates
            // AJAX endpoint (fetchTwoMerchantFeeRates), never pre-injected
            // server-side, and never sourced from the buyer surcharge config.
            TinyAssert::same(
                sprintf('%d days', (int) $row['id'])
                    . ' <span class="two-term-fee text-muted" data-term="' . (int) $row['id'] . '"></span>',
                $row['name']
            );
        }
    }

    /**
     * Regression: the admin per-term surcharge grid renders a row for EVERY
     * offerable term (not just the saved/available subset), so the admin JS
     * can show/hide rows live as term checkboxes are toggled. Initial
     * visibility (inline display:none) mirrors getAvailablePaymentTerms():
     * checkbox config truthy AND valid for the current term type.
     */
    private static function testSurchargeGridRendersEveryOfferableTermRowWithVisibilityState(): void
    {
        $harness = static function (): object {
            return new class extends TwopaymentTestHarness {
                public function getTwoSurchargeGridHtmlPublic(): string
                {
                    return $this->getTwoSurchargeGridHtml();
                }
            };
        };
        $rowPattern = static function (int $days, bool $visible): string {
            $style = $visible ? '' : ' style="display:none"';
            return '<tr class="two-surcharge-row two-surcharge-row-' . $days . ' '
                . (in_array($days, Twopayment::EOM_PAYMENT_TERMS_OPTIONS, true) ? 'two-term-both' : 'two-term-standard')
                . '" data-term="' . $days . '"' . $style . '>';
        };

        // STANDARD type: every offerable term gets a row; only checked terms
        // start visible.
        self::reset();
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1);
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_60', 1);
        $html = $harness()->getTwoSurchargeGridHtmlPublic();
        foreach (Twopayment::PAYMENT_TERMS_OPTIONS as $days) {
            $days = (int) $days;
            $visible = in_array($days, [30, 60], true);
            TinyAssert::true(
                strpos($html, $rowPattern($days, $visible)) !== false,
                'expected ' . ($visible ? 'visible' : 'hidden') . ' grid row for ' . $days . ' days'
            );
            // Inputs exist for every offerable term regardless of visibility.
            TinyAssert::true(
                strpos($html, 'name="PS_TWO_SURCHARGE_PCT_' . $days . '"') !== false,
                'expected percentage input for ' . $days . ' days'
            );
        }

        // EOM type: a checked non-EOM term (90) starts hidden; a checked EOM
        // term (30) starts visible; an unchecked EOM term (45) starts hidden.
        self::reset();
        Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', 'EOM');
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1);
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_90', 1);
        $html = $harness()->getTwoSurchargeGridHtmlPublic();
        TinyAssert::true(strpos($html, $rowPattern(30, true)) !== false, 'checked EOM term row must start visible');
        TinyAssert::true(strpos($html, $rowPattern(45, false)) !== false, 'unchecked EOM term row must start hidden');
        TinyAssert::true(strpos($html, $rowPattern(90, false)) !== false, 'checked non-EOM term row must start hidden under EOM');
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

    private static function testSurchargeTaxRulesGroupIdReadsAdminConfig(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        // Blank/unset/garbage/negative → 0, PrestaShop's "No tax" sentinel
        // (fail-safe: never tax the fee on a selection the merchant did not
        // make).
        TinyAssert::same(0, $module->getTwoSurchargeTaxRulesGroupId(), 'unset config must yield 0 (No tax)');
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '');
        TinyAssert::same(0, $module->getTwoSurchargeTaxRulesGroupId(), 'blank config must yield 0');
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, 'abc');
        TinyAssert::same(0, $module->getTwoSurchargeTaxRulesGroupId(), 'non-numeric config must yield 0');
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '-5');
        TinyAssert::same(0, $module->getTwoSurchargeTaxRulesGroupId(), 'negative config must yield 0');

        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '400');
        TinyAssert::same(400, $module->getTwoSurchargeTaxRulesGroupId(), 'stored group id is returned as int');
    }

    private static function testSurchargeTaxRateForCartResolvesDestinationThroughCoreGates(): void
    {
        self::reset();
        StubStore::$addresses[900] = ['id_country' => 34, 'loaded' => true]; // ES
        StubStore::$addresses[901] = ['id_country' => 47, 'loaded' => true]; // NO - group has no rule
        StubStore::$taxRuleRates[400] = [34 => 21.0];
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '400');
        $module = new TwopaymentTestHarness();
        $cart = new Cart(1);
        $cart->id_address_invoice = 900;
        $cart->id_address_delivery = 901;

        // Covered destination resolves the group's rate as a fraction.
        TinyAssert::same(0.21, $module->getTwoSurchargeTaxRateForCart($cart), 'covered destination resolves the group rate');

        // Destination without a matching rule -> 0 (core zero-rating).
        $cart->id_address_invoice = 901;
        TinyAssert::same(0.0, $module->getTwoSurchargeTaxRateForCart($cart), 'no rule for the destination must resolve 0');
        $cart->id_address_invoice = 900;

        // PS_TAX_ADDRESS_TYPE=delivery: the DELIVERY address is the tax
        // destination, exactly like core cart pricing.
        Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_delivery');
        TinyAssert::same(0.0, $module->getTwoSurchargeTaxRateForCart($cart), 'delivery-address tax destination must be honoured');
        Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_invoice');

        // Shop-wide PS_TAX off zeroes the rate (core Tax::excludeTaxeOption).
        Configuration::updateValue('PS_TAX', 0);
        TinyAssert::same(0.0, $module->getTwoSurchargeTaxRateForCart($cart), 'PS_TAX disabled must zero the rate');
        Configuration::updateValue('PS_TAX', 1);

        // vatnumber-module B2B exemption: foreign VAT number + management on.
        StubStore::$addresses[900]['vat_number'] = 'NO999999999';
        Configuration::updateValue('VATNUMBER_MANAGEMENT', 1);
        Configuration::updateValue('VATNUMBER_COUNTRY', 8); // shop country != buyer country
        TinyAssert::same(0.0, $module->getTwoSurchargeTaxRateForCart($cart), 'VAT-exempt B2B buyer must resolve 0');
        Configuration::updateValue('VATNUMBER_MANAGEMENT', 0);
        TinyAssert::same(0.21, $module->getTwoSurchargeTaxRateForCart($cart), 'exemption only applies when the vatnumber module manages it');

        // "No tax" sentinel (id 0) resolves 0 for every destination.
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '0');
        TinyAssert::same(0.0, $module->getTwoSurchargeTaxRateForCart($cart), 'group id 0 must always resolve 0');
    }

    /** Harness exposing the protected config-form helpers under test. */
    private static function makeConfigHarness(): TwopaymentTestHarness
    {
        return new class extends TwopaymentTestHarness {
            public function optionsForTest(): array
            {
                return $this->getTwoSurchargeTaxRulesGroupOptions();
            }

            public function formDefaultForTest(): string
            {
                return $this->getTwoSurchargeTaxRulesGroupFormDefault();
            }

            public function saveSurchargeFormForTest(): void
            {
                $this->saveTwoSurchargeFormValues();
            }

            public function validateSurchargeFormForTest(): array
            {
                $this->errors = [];
                $this->validTwoSurchargeFormValues();

                return $this->errors;
            }
        };
    }

    /**
     * The currently-configured group must stay in the dropdown even when
     * deactivated: if it dropped out, the browser would submit the first
     * option (the unselected placeholder) on the next unrelated settings
     * save and silently unset the treatment.
     */
    private static function testSurchargeTaxRulesGroupOptionsNeverDropTheConfiguredSelection(): void
    {
        self::reset();
        StubStore::$taxRulesGroups[400] = ['name' => 'Standard rate', 'active' => 1];
        StubStore::$taxRulesGroups[500] = ['name' => 'Retired rate', 'active' => 0];
        StubStore::$taxRulesGroups[600] = ['name' => 'Unused retired rate', 'active' => 0];
        $module = self::makeConfigHarness();

        // Configured group deactivated -> injected with an "(inactive)" tag.
        // Ids are strings, and the unselected placeholder ('') leads.
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '500');
        $options = $module->optionsForTest();
        $byId = [];
        foreach ($options as $option) {
            TinyAssert::true(is_string($option['id']), 'option ids must be strings (PHP 7 template loose == would conflate \'\' with 0)');
            $byId[$option['id']] = (string) $option['name'];
        }
        TinyAssert::same(['-- Select surcharge tax treatment --', 'Standard rate', 'Retired rate (inactive)'], array_values($byId), 'placeholder leads; deactivated configured group must stay selectable, flagged inactive');
        TinyAssert::same(['', '400', '500'], array_map('strval', array_keys($byId)), 'inactive groups that are NOT configured stay hidden; the never-taxed sentinel is never offered');

        // Configured group active -> plain listing, no duplicate, no tag.
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '400');
        $options = $module->optionsForTest();
        TinyAssert::count(2, $options);
        TinyAssert::same('Standard rate', (string) $options[1]['name'], 'active configured group is listed once, untagged');

        // Configured group deleted entirely -> nothing to inject (runtime
        // already fails safe to an untaxed fee for a nonexistent group).
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '999');
        TinyAssert::count(2, $module->optionsForTest());
    }

    /**
     * The never-taxed treatment (PrestaShop's core "No tax" sentinel, tax
     * rules group pseudo-id 0) is NEVER in the dropdown - not for a fresh
     * shop, and not for a shop that already stores it. TWO-25279: there is no
     * grandfathering, so a stored sentinel is reported by
     * getTwoSurchargeNeverTaxedNotice() rather than re-offered.
     *
     * Also proves the option list is filtered through the shared predicate
     * rather than merely relying on core to omit the sentinel: a core row
     * carrying id 0 is dropped.
     */
    private static function testSurchargeTaxRulesGroupOptionsNeverOfferTheNeverTaxedSentinel(): void
    {
        self::reset();
        StubStore::$taxRulesGroups[400] = ['name' => 'Standard rate', 'active' => 1];
        $module = self::makeConfigHarness();

        foreach (['', '0', ' 0 ', '400'] as $stored) {
            Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, $stored);
            $ids = array_map('strval', array_column($module->optionsForTest(), 'id'));
            TinyAssert::true(!in_array('0', $ids, true), 'the never-taxed sentinel must never be offered (stored: "' . $stored . '")');
        }

        // A core row for the sentinel itself is filtered out by the shared
        // predicate, so the option list cannot drift from the save guard.
        self::reset();
        StubStore::$taxRulesGroups[400] = ['name' => 'Standard rate', 'active' => 1];
        StubStore::$taxRulesGroups[0] = ['name' => 'No tax', 'active' => 1];
        $module = self::makeConfigHarness();
        $ids = array_map('strval', array_column($module->optionsForTest(), 'id'));
        TinyAssert::same(['', '400'], $ids, 'a core row carrying the sentinel id is dropped by the shared predicate');
    }

    /**
     * The shared predicate every enforcement site delegates to. Only the
     * sentinel is never-taxed; unselected and garbage are a DIFFERENT state
     * with a different message, and must not be reported as never-taxed.
     */
    private static function testNeverTaxedPredicateMatchesTheSentinelOnly(): void
    {
        $module = new TwopaymentTestHarness();

        // Every shape the checkout resolver would in fact leave untaxed:
        // numeric, floored at 0. Includes '0.5' and '-5', which
        // getTwoSurchargeTaxRulesGroupId() also collapses to 0.
        foreach (['0', ' 0 ', '0.0', '00', 0, '-0', '0.5', '-5'] as $neverTaxed) {
            TinyAssert::true($module->isTwoSurchargeNeverTaxedTreatment($neverTaxed), 'must read as never-taxed: ' . var_export($neverTaxed, true));
        }
        // Unselected / non-numeric is a DIFFERENT state, and booleans are not
        // a treatment at all (Configuration::get returns false when unset).
        foreach (['', '  ', 'abc', '400', 400, false, true, null, []] as $other) {
            TinyAssert::false($module->isTwoSurchargeNeverTaxedTreatment($other), 'must NOT read as never-taxed: ' . var_export($other, true));
        }
    }

    /**
     * Fail-loud half of the enforce-only rescope: a shop still storing the
     * never-taxed sentinel gets a visible error naming the consequence, not
     * a silently placeholder-looking dropdown. The migration nag cannot do
     * this job - it self-retires the moment ANY value is stored.
     */
    private static function testNeverTaxedNoticeReportsAStoredSentinel(): void
    {
        self::reset();
        $module = self::makeConfigHarness();

        TinyAssert::same('', $module->getTwoSurchargeNeverTaxedNotice(), 'unset config is unselected, not never-taxed');
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '');
        TinyAssert::same('', $module->getTwoSurchargeNeverTaxedNotice(), 'blank config is unselected, not never-taxed');
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '400');
        TinyAssert::same('', $module->getTwoSurchargeNeverTaxedNotice(), 'a real group is not never-taxed');

        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '0');
        $notice = $module->getTwoSurchargeNeverTaxedNotice();
        TinyAssert::true($notice !== '', 'a stored sentinel must be reported');
        TinyAssert::true(strpos($notice, 'UNTAXED') !== false, 'the notice spells out the consequence');
        TinyAssert::true($notice === $module->getTwoSurchargeNeverTaxedNotice(), 'the notice persists - it must not self-retire like the migration nag');
        TinyAssert::same('0', (string) Configuration::get(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP), 'reporting must not silently rewrite the merchant tax config');
    }

    /**
     * Unsaved config pre-selects NOTHING - the dropdown renders on the
     * unselected placeholder (''). Never an auto-default: not
     * Product::getIdTaxRulesGroupMostUsed() (full-catalog COUNT/GROUP BY on
     * every config page render), and not "No tax" either - the merchant
     * must pick explicitly. A stored selection ("No tax" included) is the
     * pre-selection.
     */
    private static function testSurchargeTaxRulesGroupFormDefaultRequiresExplicitChoice(): void
    {
        self::reset();
        $module = self::makeConfigHarness();

        TinyAssert::same('', $module->formDefaultForTest(), 'unset config must stay on the unselected placeholder');
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '');
        TinyAssert::same('', $module->formDefaultForTest(), 'blank config must stay on the unselected placeholder');
        // A stored never-taxed sentinel is reported as-is rather than
        // rewritten (TWO-25279): the value is not in the option list, so the
        // select renders the placeholder, and
        // getTwoSurchargeNeverTaxedNotice() is what tells the merchant why.
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '0');
        TinyAssert::same('0', $module->formDefaultForTest(), 'a stored sentinel is reported unchanged, never silently rewritten');
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '400');
        TinyAssert::same('400', $module->formDefaultForTest(), 'stored selection is the pre-selection');
    }

    /**
     * While surcharges are enabled (type !== 'none') the save is blocked
     * server-side until an explicit tax treatment is submitted: the
     * unselected placeholder ('' / absent) is a validation error, never a
     * silent "No tax" fallback. With surcharges disabled no selection is
     * required, and an invalid submission is stored as '' (unselected),
     * not coerced to 0.
     */
    private static function testSurchargeTaxTreatmentRequiredWhenSurchargesEnabled(): void
    {
        self::reset();
        Tools::resetTestValues();
        StubStore::$taxRulesGroups[400] = ['name' => 'Standard rate', 'active' => 1];
        $module = self::makeConfigHarness();

        // Neutralise the unrelated grid checks (the Tools stub defaults
        // absent keys to null, unlike core's false).
        foreach (['PCT', 'FIXED', 'CAP'] as $suffix) {
            Tools::setTestValue('PS_TWO_SURCHARGE_' . $suffix . '_30', '');
        }

        // Enabled + nothing submitted -> blocked.
        Tools::setTestValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        $errors = $module->validateSurchargeFormForTest();
        TinyAssert::count(1, $errors);
        TinyAssert::true(strpos((string) $errors[0], 'Select a surcharge tax treatment') !== false, 'error names the missing selection');

        // Enabled + blank submitted (the placeholder) -> blocked.
        Tools::setTestValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '');
        TinyAssert::count(1, $module->validateSurchargeFormForTest());

        // Enabled + whitespace submitted -> blocked.
        Tools::setTestValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, ' ');
        TinyAssert::count(1, $module->validateSurchargeFormForTest());

        // Enabled + the never-taxed sentinel -> blocked outright, with its
        // OWN message (TWO-25279). No already-stored exemption: the shop is
        // told to pick a real group, not allowed to re-save the sentinel.
        Tools::setTestValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '0');
        $errors = $module->validateSurchargeFormForTest();
        TinyAssert::count(1, $errors);
        TinyAssert::true(strpos((string) $errors[0], 'untaxed in every country') !== false, 'the never-taxed refusal names the consequence, not just "not one of your groups"');
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '0');
        TinyAssert::count(1, $module->validateSurchargeFormForTest(), 'a shop already storing the sentinel is still refused');
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '');

        // Enabled + existing group -> allowed.
        Tools::setTestValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '400');
        TinyAssert::count(0, $module->validateSurchargeFormForTest());

        // Enabled + nonexistent group -> blocked (pre-existing rule intact).
        Tools::setTestValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '999');
        TinyAssert::count(1, $module->validateSurchargeFormForTest());

        // Enabled + decimal/negative -> blocked, never truncated into a
        // selection the merchant did not make.
        Tools::setTestValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '0.5');
        TinyAssert::count(1, $module->validateSurchargeFormForTest());
        Tools::setTestValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '-5');
        TinyAssert::count(1, $module->validateSurchargeFormForTest());

        // Disabled -> no selection required, save allowed.
        Tools::resetTestValues();
        Tools::setTestValue('PS_TWO_SURCHARGE_TYPE', 'none');
        TinyAssert::count(0, $module->validateSurchargeFormForTest());

        // Disabled + garbage submitted -> stored '' (unselected), never 0.
        Tools::setTestValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, 'abc');
        $module->saveSurchargeFormForTest();
        TinyAssert::same('', (string) Configuration::get(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP), 'garbage never coerces to "No tax"');
        Tools::resetTestValues();
    }

    /**
     * A cap of exactly 0 is refused on save (TWO-25289). It bounds the WHOLE
     * fee - the percentage and the fixed fee together - so it silently wipes a
     * configured fixed fee too, and the intent it gets mistaken for is
     * expressible directly with 0% and a 0 fixed fee. A BLANK cap stays valid
     * and still means "no cap".
     */
    private static function testSurchargeCapOfZeroIsRefusedOnSave(): void
    {
        self::reset();
        Tools::resetTestValues();
        StubStore::$taxRulesGroups[400] = ['name' => 'Standard rate', 'active' => 1];
        $module = self::makeConfigHarness();

        // Surcharges enabled with a valid tax treatment, so the only thing
        // under test here is the grid.
        Tools::setTestValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Tools::setTestValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '400');
        foreach (['PCT', 'FIXED', 'CAP'] as $suffix) {
            Tools::setTestValue('PS_TWO_SURCHARGE_' . $suffix . '_30', '');
        }

        // Blank cap -> allowed, and still means "no cap".
        TinyAssert::count(0, $module->validateSurchargeFormForTest(), 'a blank cap must stay valid');

        // A cap of 0, however it is typed -> blocked, with an error that says
        // what to do instead. A SUB-CENT cap goes with it: the calculator
        // rounds the cap to 2dp before sending, so 0.001 would pass an
        // exact-zero check and then arrive as a hard cap of 0.00 - the very
        // outcome being refused, one step later.
        foreach (['0', '0.0', '0.00', '00', '0.001', '0.004'] as $zero) {
            Tools::setTestValue('PS_TWO_SURCHARGE_CAP_30', $zero);
            $errors = $module->validateSurchargeFormForTest();
            TinyAssert::count(1, $errors);
            TinyAssert::true(
                strpos((string) $errors[0], 'cannot be 0') !== false,
                sprintf('a cap typed as "%s" must be refused, naming zero as the problem', $zero)
            );
        }

        // 0.005 rounds UP to 0.01, so it survives: the boundary is where the
        // rounding lands, not an arbitrary threshold.
        Tools::setTestValue('PS_TWO_SURCHARGE_CAP_30', '0.005');
        TinyAssert::count(0, $module->validateSurchargeFormForTest(), 'a cap that rounds UP to 0.01 must survive');

        // A positive cap -> allowed. And a zero PERCENTAGE with a zero FIXED
        // fee is allowed too: that pair is exactly what the error tells the
        // merchant to use instead.
        Tools::setTestValue('PS_TWO_SURCHARGE_CAP_30', '9');
        Tools::setTestValue('PS_TWO_SURCHARGE_PCT_30', '0');
        Tools::setTestValue('PS_TWO_SURCHARGE_FIXED_30', '0');
        TinyAssert::count(0, $module->validateSurchargeFormForTest(), 'a positive cap with 0% and 0 fixed must be valid');
        Tools::resetTestValues();
    }

    /**
     * The settings read must keep ABSENT and ZERO distinguishable, and the
     * calculator must relay a configured zero rather than dropping it. An
     * unset Configuration key reads back as false and (float) false is 0.0,
     * so the naive cast conflated the two - and the calculator's old `> 0`
     * filter then turned a configured 0 into "no cap", relaying the
     * percentage UNCAPPED. That is the overcharge TWO-25289 closes.
     */
    private static function testConfiguredZeroCapIsRelayedVerbatimAndAbsenceMeansUncapped(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'fixed_and_percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2.5');
        Configuration::updateValue('PS_TWO_SURCHARGE_FIXED_30', '3');
        Configuration::updateValue('PS_TWO_SURCHARGE_CAP_30', '0');
        $module = new TwopaymentTestHarness();
        $settings = $module->getTwoSurchargeSettings();
        TinyAssert::same(0.0, $settings['grid'][30]['limit'], 'a stored 0 is a real cap, not an absence');
        $share = $module->buildTwoBuyerFeeShare(30);
        TinyAssert::true(array_key_exists('cap', $share), 'a configured zero cap must not be dropped from the payload');
        TinyAssert::same(0.0, $share['cap'], 'a configured zero cap is relayed as 0, never as "no cap"');

        // Blank cap -> null through the settings read, and no `cap` key at
        // all on the wire, which is what "uncapped" means.
        Configuration::updateValue('PS_TWO_SURCHARGE_CAP_30', '');
        $settings = $module->getTwoSurchargeSettings();
        TinyAssert::same(null, $settings['grid'][30]['limit'], 'a blank cap reads back as null, not 0.0');
        $share = $module->buildTwoBuyerFeeShare(30);
        TinyAssert::false(array_key_exists('cap', $share), 'an unconfigured cap sends no cap key');

        // Non-numeric is absent too, NOT a zero cap. Without the is_numeric
        // gate it would cast to 0.0 and become a real cap of zero, silently
        // suppressing the whole fee on a shop that was previously uncapped.
        Configuration::updateValue('PS_TWO_SURCHARGE_CAP_30', 'abc');
        $settings = $module->getTwoSurchargeSettings();
        TinyAssert::same(null, $settings['grid'][30]['limit'], 'a non-numeric stored cap is absent, not 0.0');
        TinyAssert::false(
            array_key_exists('cap', $module->buildTwoBuyerFeeShare(30)),
            'a non-numeric stored cap must never be relayed as a zero cap'
        );

        // A NEGATIVE stored cap is absent as well. Letting a zero through must
        // not also let a negative through.
        Configuration::updateValue('PS_TWO_SURCHARGE_CAP_30', '-10');
        $settings = $module->getTwoSurchargeSettings();
        TinyAssert::same(null, $settings['grid'][30]['limit'], 'a negative stored cap is absent, not -10.0');
        TinyAssert::false(
            array_key_exists('cap', $module->buildTwoBuyerFeeShare(30)),
            'a negative stored cap must never be relayed'
        );
    }

    /**
     * The pricing API refuses a monetary value finer than two decimal places
     * rather than rounding it, so an over-precise configured amount was
     * rejected upstream and surfaced to the buyer as a generic error
     * (TWO-25289).
     */
    private static function testMonetaryMembersAreRoundedToTwoDecimalPlaces(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'fixed_and_percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2.5');
        Configuration::updateValue('PS_TWO_SURCHARGE_FIXED_30', '10.999');
        Configuration::updateValue('PS_TWO_SURCHARGE_CAP_30', '20.005');
        $module = new TwopaymentTestHarness();
        $share = $module->buildTwoBuyerFeeShare(30);
        TinyAssert::same(11.0, $share['surcharge'], 'the fixed fee is rounded to 2dp before the request');
        TinyAssert::same(20.01, $share['cap'], 'the cap is rounded to 2dp before the request');
    }

    /**
     * upgrade-2.5.0.php: a shop upgrading with the pre-release flat rate
     * (PS_TWO_SURCHARGE_TAX_RATE) configured and no TaxRulesGroup selected
     * gets a logged warning + the persistent "needs re-selection" flag - it
     * must never pass silently. Shops without the flat rate, or that already
     * selected a group, are untouched.
     */
    private static function testUpgrade250FlagsFlatRateShopsForTaxReselection(): void
    {
        require_once __DIR__ . '/../upgrade/upgrade-2.5.0.php';

        // Flat rate set, group unset -> warning log + persistent flag.
        self::reset();
        PrestaShopLogger::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TAX_RATE', '25');
        $module = new TwopaymentTestHarness();
        TinyAssert::true(upgrade_module_2_5_0($module));
        TinyAssert::same('1', (string) Configuration::get(Twopayment::CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE), 'flat-rate shop without a group selection must be flagged');
        TinyAssert::count(1, PrestaShopLogger::$logs);
        TinyAssert::same(2, PrestaShopLogger::$logs[0]['severity'], 'logged as a warning');
        TinyAssert::true(strpos(PrestaShopLogger::$logs[0]['message'], 'UNTAXED') !== false, 'log spells out the consequence');
        // The log must name the field the merchant will actually see
        // (TWO-25279 renamed it), or the instruction sends them looking for a
        // setting that no longer exists under that name.
        TinyAssert::true(strpos(PrestaShopLogger::$logs[0]['message'], 'Surcharge Tax Treatment') !== false, 'log names the field by its current admin label');

        // Group already selected -> no flag, no log.
        self::reset();
        PrestaShopLogger::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TAX_RATE', '25');
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '400');
        TinyAssert::true(upgrade_module_2_5_0($module));
        TinyAssert::false((bool) Configuration::get(Twopayment::CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE), 'a shop that already selected a group is not nagged');
        TinyAssert::count(0, PrestaShopLogger::$logs);

        // No flat rate (fresh install / never configured) -> untouched.
        self::reset();
        PrestaShopLogger::reset();
        TinyAssert::true(upgrade_module_2_5_0($module));
        TinyAssert::false((bool) Configuration::get(Twopayment::CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE));
        TinyAssert::count(0, PrestaShopLogger::$logs);

        // Blank flat rate counts as unset.
        self::reset();
        PrestaShopLogger::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TAX_RATE', '  ');
        TinyAssert::true(upgrade_module_2_5_0($module));
        TinyAssert::false((bool) Configuration::get(Twopayment::CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE));
        TinyAssert::count(0, PrestaShopLogger::$logs);
    }

    /**
     * The post-upgrade notice is persistent (re-renders on every config
     * page load) until the merchant makes a selection: it self-retires if a
     * selection exists, and any explicit surcharge-settings save - "No tax"
     * included - clears the flag.
     */
    private static function testSurchargeTaxMigrationNoticeLifecycle(): void
    {
        self::reset();
        Tools::resetTestValues();
        $module = self::makeConfigHarness();

        // No flag -> no notice.
        TinyAssert::same('', $module->getTwoSurchargeTaxMigrationNotice());

        // Flag + no selection -> notice, and it PERSISTS across renders.
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE, '1');
        $notice = $module->getTwoSurchargeTaxMigrationNotice();
        TinyAssert::true($notice !== '', 'flagged shop must see the warning');
        TinyAssert::true(strpos($notice, 'NOT taxed') !== false, 'warning spells out the consequence');
        TinyAssert::true($module->getTwoSurchargeTaxMigrationNotice() !== '', 'warning persists until a selection is saved');

        // Selection appears (any save path) -> notice self-retires and the
        // flag is cleared.
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '400');
        TinyAssert::same('', $module->getTwoSurchargeTaxMigrationNotice());
        TinyAssert::false((bool) Configuration::get(Twopayment::CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE), 'self-retire clears the flag');

        // A save WITHOUT a submitted group stores '' (still unselected) and
        // does NOT retire the nag - it is accurate until a real choice is
        // made. No silent "No tax" fallback.
        self::reset();
        Tools::resetTestValues();
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE, '1');
        $module->saveSurchargeFormForTest();
        TinyAssert::same('', (string) Configuration::get(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP), 'save without a submitted group must stay unselected, never silently "No tax"');
        TinyAssert::same('1', (string) Configuration::get(Twopayment::CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE), 'an unselected save must NOT retire the nag');
        TinyAssert::true($module->getTwoSurchargeTaxMigrationNotice() !== '', 'notice persists after an unselected save');

        // A never-taxed submission can no longer be persisted at all
        // (TWO-25279), so it stores '' and does NOT retire the nag - this is
        // the surcharges-disabled path, where validTwoSurchargeFormValues
        // never runs and the save guard is the only enforcement.
        Tools::setTestValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '0');
        $module->saveSurchargeFormForTest();
        Tools::resetTestValues();
        TinyAssert::same('', (string) Configuration::get(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP), 'a never-taxed submission must never be persisted');
        TinyAssert::same('1', (string) Configuration::get(Twopayment::CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE), 'a refused submission must NOT retire the nag');

        // A real group submission stores and retires the nag.
        Tools::setTestValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '400');
        StubStore::$taxRulesGroups[400] = ['name' => 'Standard rate', 'active' => 1];
        $module->saveSurchargeFormForTest();
        Tools::resetTestValues();
        TinyAssert::same('400', (string) Configuration::get(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP), 'a real group submission is stored');
        TinyAssert::false((bool) Configuration::get(Twopayment::CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE), 'a real selection retires the nag');
        TinyAssert::same('', $module->getTwoSurchargeTaxMigrationNotice());
    }

    private static function testSurchargeLineItemUsesSelectedGroupDestinationRate(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2');
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '400');
        StubStore::$taxRuleRates[400] = [34 => 25.0];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[900] = ['id_country' => 34, 'loaded' => true];
        $module = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                // The response carries a WILDLY different total_fee_tax_rate —
                // it must have zero influence on the line's tax: the selected
                // group's destination rate is the only source.
                return [
                    'http_status' => 200,
                    'buyer_fee_share' => '5.00',
                    'total_fee_tax_rate' => '99',
                    'currency' => 'EUR',
                ];
            }
        };
        $cart = new Cart(1);
        $cart->id_currency = 978;
        $cart->id_address_invoice = 900;
        $line = $module->buildTwoSurchargeLineItemForCart($cart, 100.0);
        TinyAssert::same('SERVICE', $line['type']);
        TinyAssert::same('Payment terms fee - 30 days', $line['name']);
        TinyAssert::same('5.00', $line['net_amount']);
        TinyAssert::same('0.25', $line['tax_rate'], 'tax rate comes from the selected group, never the pricing response');
        TinyAssert::same('1.25', $line['tax_amount']);
        TinyAssert::same('6.25', $line['gross_amount']);
    }

    private static function testSurchargeLineItemIgnoresApiTaxRateEntirely(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2');
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$addresses[900] = ['id_country' => 34, 'loaded' => true];
        $module = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                // Response has a rate but no tax rules group is selected: the
                // line must be untaxed (0), proving the response rate is dead.
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
        TinyAssert::same('5.00', $line['net_amount']);
        TinyAssert::same('0', $line['tax_rate'], 'no selected group means untaxed line even when the response carries a rate');
        TinyAssert::same('0.00', $line['tax_amount']);
        TinyAssert::same('5.00', $line['gross_amount']);
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
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '400');
        StubStore::$taxRuleRates[400] = [34 => 25.0];
        StubStore::$addresses[900] = ['id_country' => 34, 'loaded' => true];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        $module = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                // net 10.10 * 0.25 = 2.525 → lands exactly on a rounding
                // boundary; must round half-up to 2.53 and keep gross = net+tax.
                return [
                    'http_status' => 200,
                    'buyer_fee_share' => '10.10',
                    'currency' => 'EUR',
                ];
            }
        };
        $cart = new Cart(1);
        $cart->id_currency = 978;
        $cart->id_address_invoice = 900;
        $line = $module->buildTwoSurchargeLineItemForCart($cart, 500.0);
        TinyAssert::same('10.10', $line['net_amount']);
        TinyAssert::same('2.53', $line['tax_amount']);
        TinyAssert::same('12.63', $line['gross_amount']);
        // The constructed line must satisfy the Two line-item formulas.
        TinyAssert::true($module->validateTwoLineItems([$line]));
    }

    private static function testSurchargeLineItemTaxRateSelfConsistentAtHighPrecision(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2');
        // High-precision group rate (21.0098% → 0.210098): TAX_RATE_PRECISION
        // (6dp) now carries the full fraction in the sent payload. Tax must
        // be computed from the SENT rate, else the line fails
        // validateTwoLineItems and is dropped.
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '400');
        StubStore::$taxRuleRates[400] = [34 => 21.0098];
        StubStore::$addresses[900] = ['id_country' => 34, 'loaded' => true];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        $module = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                return [
                    'http_status' => 200,
                    'buyer_fee_share' => '1000.00',
                    'currency' => 'EUR',
                ];
            }
        };
        $cart = new Cart(1);
        $cart->id_currency = 978;
        $cart->id_address_invoice = 900;
        $line = $module->buildTwoSurchargeLineItemForCart($cart, 5000.0);
        TinyAssert::same('0.210098', $line['tax_rate'], 'full 6dp rate survives the sent precision');
        TinyAssert::same('210.10', $line['tax_amount'], 'tax computed from the sent rate');
        TinyAssert::same('1210.10', $line['gross_amount']);
        TinyAssert::true($module->validateTwoLineItems([$line]), 'high-precision fee tax rate must not silently drop the line');
    }

    private static function testSurchargeLineItemHonorsExplicitTermOverride(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1);
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_60', 1);
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '2');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_60', '4');
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        // No buyer cookie set, so getSelectedPaymentTerm() would default to 30 —
        // the explicit override (the update/admin path's persisted term) must win.
        $module = new class extends TwopaymentTestHarness {
            public $capturedDays = null;
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->capturedDays = isset($payload['order_terms']['duration_days'])
                    ? $payload['order_terms']['duration_days']
                    : null;
                return ['http_status' => 200, 'buyer_fee_share' => '5.00', 'total_fee_tax_rate' => '0.25', 'currency' => 'EUR'];
            }
        };
        $cart = new Cart(1);
        $cart->id_currency = 978;
        $line = $module->buildTwoSurchargeLineItemForCart($cart, 100.0, 60);
        TinyAssert::same(60, $module->capturedDays, 'explicit term override must reach the fee quote, not the default term');
        TinyAssert::same('SERVICE', $line['type']);
    }

    private static function testOrderPayloadInjectsSurchargeLineAndBumpsTotals(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', 'percentage');
        Configuration::updateValue('PS_TWO_SURCHARGE_PCT_30', '5');
        // Merchant-selected group: FR (country 33, the cart's destination)
        // at 25%.
        Configuration::updateValue(Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP, '400');
        StubStore::$taxRuleRates[400] = [33 => 25.0];

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
        // Declared-rate relay (TWO-24880): product rate from its tax-rules
        // group at the cart's tax address, never the row's 'rate' field.
        StubStore::$products[9301]['id_tax_rules_group'] = 500;
        StubStore::$taxRuleRates[500] = 5.5;
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
                // No total_fee_tax_rate in the response: the fee line's tax
                // must come from the admin config alone.
                return [
                    'http_status' => 200,
                    'buyer_fee_share' => '5.00',
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
            return isset($item['type']) && $item['type'] === 'SERVICE' && $item['name'] === 'Payment terms fee - 30 days';
        }));
        TinyAssert::count(1, $feeLines);
        TinyAssert::same('5.00', $feeLines[0]['net_amount']);
        TinyAssert::same('0.25', $feeLines[0]['tax_rate']);
        TinyAssert::same('6.25', $feeLines[0]['gross_amount']);
    }
}
