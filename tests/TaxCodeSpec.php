<?php

declare(strict_types=1);

/**
 * TWO-24877, TWO-26153 - tax codes on 0% lines.
 *
 * The mapped and unmapped payload rows (an unmapped line gets no code: the plugin picks none), the shared case
 * table, the buyer VAT number, then one row per kind of charge line (ecotax, wrapping, the buyer fee, discounts,
 * shipping by carrier and by the Default shipping tax code); then updates, the merchant country, the code list,
 * the settings form and the migration. The cart is OrderPostprocessingSpec's (a 100.00 lamp and 29.00 of shipping, Madrid invoice address)
 * with the lamp's and the carrier's tax rules groups at 0%, a delivery address of its own, and the merchant's
 * country set as the merchant record caches it.
 *
 * Rows naming a golden also compare the whole create payload with fixtures/tax-code-golden.json, which was written
 * by this spec on the base branch (TAX_CODE_GOLDEN_WRITE=1): those payloads must stay byte-identical.
 */
final class TaxCodeSpec
{
    private const CART = 9701;
    private const INVOICE = 9711;
    private const DELIVERY = 9712;
    private const PRODUCT = 9721;
    private const PRODUCT_GROUP = 9000 + 9721;
    private const CARRIER = 7701;
    private const CARRIER_GROUP = 7721;
    private const OTHER_CARRIER = 7702;
    private const OTHER_CARRIER_GROUP = 7722;
    private const DEFAULT_SHIPPING_GROUP = 7723;
    private const ECOTAX_GROUP = 7724;
    private const WRAPPING_GROUP = 7725;
    private const FEE_GROUP = 7726;
    private const ORDER = 9731;
    private const GOLDEN = __DIR__ . '/fixtures/tax-code-golden.json';
    private const COUNTRIES = [34 => 'ES', 8 => 'FR', 1 => 'DE', 21 => 'US', 17 => 'NO', 6 => 'NL', 148 => 'MC', 9 => 'GR', 117 => 'GB', 119 => 'CH'];
    private const RULE = 9801;
    private const OTHER_RULE = 9802;
    private const TAX_ZERO = 9811;
    private const TAX_21 = 9812;

    private const EXPORT = 'ES_IVA_EXPORT';
    private const INTRA = 'ES_IVA_INTRA_COMMUNITY';
    private const SERVICES = 'ES_IVA_INTRA_COMMUNITY_SERVICES';
    private const ART20 = 'ES_IVA_EXEMPT_ART20';
    private const ART22 = 'ES_IVA_EXEMPT_ART22';

    public static function runAll(): void
    {
        $golden = self::golden();
        $failures = [];
        foreach (self::rows() as $row) {
            self::collect($failures, $row[10], function () use ($row, $golden) {
                self::assertRow($row, $golden);
            });
        }
        foreach (self::caseRows() as $row) {
            self::collect($failures, end($row), function () use ($row) {
                self::assertCaseRow(...$row);
            });
        }
        foreach (self::vatRows() as $row) {
            self::collect($failures, end($row), function () use ($row) {
                self::assertVatRow(...$row);
            });
        }
        foreach (self::chargeRows() as [$setup, $expected, $description]) {
            self::collect($failures, $description, function () use ($setup, $expected, $description) {
                self::assertChargeRow($setup, $expected, $description);
            });
        }
        $tests = [
            'testUpdateKeepsPlacementCodes', 'testUpdateOfUnrecordedOrderResolvesNow', 'testUpdateReadsTheVatNumberLikeCreate',
            'testDescriptorMismatchSendsLinesUncoded', 'testMappingIsReadOnlyForAZeroLineAndFailsLoud',
            'testMerchantCountryIsStoredAndRefetchedOnce', 'testTaxCodeListHidesCodesNeedingAReason',
            'testTaxCodeListRetriesOnAFloor', 'testFormSaveValidatesPostedCodes', 'testOptionLabelShowsTheRateOnce',
            'testFormRowsFollowTheShopRules', 'testMigrationFansTheGroupCodeOut', 'testFallbackShippingTaxCodeInTheHookContext',
            'testUpdateSharesOnlyMappedCodesWithKeylessLines', 'testMigrationMovesEveryShopTier', 'testAnUnmovedGroupMappingStillReads',
            'testATruncatedFormIsRefused',
        ];
        foreach ($tests as $test) {
            self::collect($failures, $test, function () use ($test) {
                self::$test();
            });
        }
        if (getenv('TAX_CODE_GOLDEN_WRITE') === '1') {
            file_put_contents(self::GOLDEN, json_encode(self::$written, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }
        if ($failures !== []) {
            throw new RuntimeException(count($failures) . " row(s) failed:\n  - " . implode("\n  - ", $failures));
        }
    }

    private static function collect(array &$failures, string $name, callable $test): void
    {
        try {
            $test();
        } catch (Throwable $e) {
            $failures[] = $name . ': ' . $e->getMessage();
        }
    }

    /**
     * Columns: merchant country, cart ('goods', 'service' or 'mixed'), delivery country, delivery postcode, invoice
     * (buyer company) country optionally followed by a space and the invoice postcode, invoice VAT number, mapping (tax rules group => code), rate ('0' or '21'), the expected codes as
     * [lamp, (service product,) shipping], golden fixture name or null, description.
     *
     * @return array<int,array>
     */
    private static function rows(): array
    {
        $lamp = self::PRODUCT_GROUP;

        return [
            ['ES', 'goods', 'US', '10001', 'ES', '', [], '0', [null, null], null, 'an unmapped shop\'s export: no code, the plugin picks none'],
            ['ES', 'goods', 'ES', '35001', 'ES', '', [], '0', [null, null], null, 'an unmapped shop\'s order to the Canaries: no code'],
            ['ES', 'goods', 'FR', '75001', 'DE', 'DE123456789', [], '0', [null, null], null, 'an unmapped shop\'s EU buyer with a VAT number: no code'],
            ['ES', 'service', 'NO', '0150', 'NO', '', [], '0', [null, null], null, 'an unmapped shop\'s service outside the EU: no code'],
            ['ES', 'goods', 'FR', '75001', 'ES', '', [], '0', [null, null], 'goods-eu-dest-es-buyer', 'goods delivered to France for a Spanish buyer, unmapped: untouched'],
            ['ES', 'goods', 'ES', '28001', 'FR', '', [], '0', [null, null], 'goods-domestic', 'goods delivered in mainland Spain, unmapped: untouched'],
            ['ES', 'goods', 'ES', '28001', 'ES', '', [$lamp => self::ART20], '0', [self::ART20, null], null, 'a mapped product group: its code, and the unmapped shipping none'],
            ['ES', 'goods', 'US', '10001', 'ES', '', [$lamp => self::ART20], '0', [self::ART20, null], null, 'an unmapped group gets no code, even on an export'],
            ['ES', 'goods', 'ES', '28001', 'ES', '', [self::CARRIER_GROUP => self::ART22], '0', [null, self::ART22], null, 'shipping maps by its carrier\'s tax rules group'],
            ['DE', 'goods', 'US', '10001', 'DE', '', [], '0', [null, null], 'non-es-unmapped', 'non-Spanish merchant, unmapped: untouched'],
            ['DE', 'goods', 'US', '10001', 'DE', '', [$lamp => 'DE_ZERO'], '0', ['DE_ZERO', null], null, 'non-Spanish merchant, mapped: the mapping still applies'],
            ['ES', 'goods', 'US', '10001', 'ES', '', [$lamp => self::ART20], '21', [null, null], 'non-zero', 'lines at 21%: untouched, mapping or not'],
        ];
    }

    /**
     * The shared tax-code case table (TWO-26153), one row per case of the design's table, in its order, with the
     * merchant in ES. The lamp is taxed by PRODUCT_GROUP; a second, virtual product (column cart 'mixed') by
     * PRODUCT_GROUP + 1. PS_TAX_ADDRESS_TYPE is the delivery address, so the delivery address is the tax address.
     *
     * Columns: cart ('goods', 'service' or 'mixed'), discount (a 0% cart rule, the keyless line), rate ('0' or '21'),
     * billing (invoice country, optionally a space and postcode), tax address (delivery country and postcode), invoice
     * VAT number, the rules PRODUCT_GROUP has in the tax address's country (null for none, else a list of [id,
     * rate, zipcode_from, zipcode_to]), rows mapped (EX, NR of PRODUCT_GROUP; R0 the row of RULE, R1 of OTHER_RULE;
     * EX2 the exempt row of PRODUCT_GROUP + 1), the expected code of the lamp (or of the discount line when discount
     * is true), description. Rows after the design's 17 cover the rest of step 1 to 3's conditions.
     *
     * @return array<int,array>
     */
    private static function caseRows(): array
    {
        $zero = [[self::RULE, 0, '0', '0']];

        return [
            ['goods', false, '21', 'ES', 'ES 28001', '', [[self::RULE, 21, '0', '0']], ['EX' => self::INTRA], null, '1: non-0% lines are never touched'],
            ['goods', false, '0', 'DE', 'DE 10115', 'DE123', null, ['EX' => self::INTRA], self::INTRA, '2: step 1 exempt buyer'],
            ['goods', false, '0', 'DE', 'DE 10115', 'de 123', null, ['EX' => self::INTRA], self::INTRA, '3: VAT read as entered, any non-empty value counts'],
            ['goods', false, '0', 'DE', 'DE 10115', '   ', null, ['EX' => self::INTRA], null, '4: whitespace-only VAT is empty; NR on (none) gives no code'],
            ['goods', false, '0', 'DE', 'US 10001', 'DE123', $zero, ['EX' => self::INTRA, 'R0' => self::EXPORT], self::EXPORT, '5: export: tax address outside the EU skips step 1'],
            ['goods', false, '0', 'ES', 'ES 35001', '', [[self::RULE, 0, '35000', '35999']], ['R0' => self::EXPORT], self::EXPORT, '6: step 2 shop\'s 0% rule'],
            ['goods', false, '0', 'US', 'US 10001', '', null, ['NR' => self::EXPORT], self::EXPORT, '7: step 3 no-rule row'],
            ['goods', false, '0', 'DE', 'DE 10115', 'DE123', null, ['NR' => self::INTRA], null, '8: a matched row on (none) never falls through'],
            ['goods', false, '0', 'ES', 'ES 28001', 'ESB123', null, ['EX' => self::INTRA], null, '9: merchant-country buyer is never exempt'],
            ['goods', false, '0', 'MC', 'MC 98000', 'FR123', null, ['EX' => self::INTRA], self::INTRA, '10: Monaco is in the EU VAT area'],
            ['goods', false, '0', 'GB BT1 1AA', 'GB BT1 1AA', 'XI123', null, ['EX' => self::INTRA], self::INTRA, '11: Northern Ireland (GB + BT postcode) is in the EU VAT area'],
            ['goods', false, '0', 'GB SW1A 1AA', 'GB SW1A 1AA', 'GB123', null, ['EX' => self::INTRA, 'NR' => self::EXPORT], self::EXPORT, '12: Great Britain is outside'],
            ['goods', false, '0', 'CH', 'CH 8001', 'CHE123', null, ['EX' => self::INTRA, 'NR' => self::EXPORT], self::EXPORT, '13: Switzerland is outside'],
            ['service', false, '0', 'FR', 'FR 75001', 'FR123', null, ['EX' => self::SERVICES], self::SERVICES, '14: goods vs services comes from the merchant\'s per-group mapping'],
            ['goods', true, '0', 'DE', 'DE 10115', 'DE123', null, ['EX' => self::INTRA], self::INTRA, '15: step 4: shared code of the order\'s coded 0% lines'],
            ['mixed', true, '0', 'DE', 'DE 10115', 'DE123', null, ['EX' => self::INTRA, 'EX2' => self::SERVICES], null, '16: step 4: disagreeing codes give no code'],
            ['goods', true, '0', 'DE', 'DE 10115', '', null, ['EX' => self::INTRA], null, '17: step 4 with nothing to share'],
            ['goods', false, '0', 'ES', 'DE 10115', 'DE123', null, ['EX' => self::INTRA, 'NR' => self::ART20], self::ART20, 'step 1 needs the billing address abroad too: a Spanish billing address delivered to Germany is not exempt'],
            ['goods', false, '0', 'US', 'DE 10115', 'DE123', null, ['EX' => self::INTRA, 'NR' => self::EXPORT], self::EXPORT, 'step 1 needs the billing address in the EU VAT area: a US billing address delivered to Germany is not exempt'],
            ['goods', false, '0', 'ES', 'ES 28001', '', [[self::RULE, 21, '0', '0']], ['EX' => self::INTRA, 'R0' => self::ART20, 'NR' => self::EXPORT], null, 'a 0% line whose matched rule is 21% (taxes off, or another tax manager) gets no code'],
            ['goods', false, '0', 'ES', 'ES 35001', '', [[self::RULE, 21, '0', '0'], [self::OTHER_RULE, 0, '35000', '35999']], ['R0' => self::ART20, 'R1' => self::EXPORT], self::EXPORT, 'core\'s order picks the rule: the postcode range beats the country-wide rule'],
            ['goods', false, '21', 'ES', 'ES 28001', '', [[self::RULE, 21, '0', '0'], [self::OTHER_RULE, 0, '35000', '35999']], ['R1' => self::EXPORT], null, 'outside the postcode range the country-wide 21% rule applies, and the 21% line is untouched'],
        ];
    }

    private static function assertCaseRow(string $cart, bool $discount, string $rate, string $billing, string $taxAddress, string $vat, ?array $rules, array $rows, ?string $expected, string $description): void
    {
        [$taxCountry, $taxPostcode] = explode(' ', $taxAddress, 2);
        self::seed('ES', $cart, $taxCountry, $taxPostcode, $billing, [], $rate);
        Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_delivery');
        StubStore::$addresses[self::INVOICE]['vat_number'] = $vat;
        StubStore::$taxRulesGroups[self::PRODUCT_GROUP] = ['name' => 'Lamp', 'active' => 1];
        StubStore::$taxes[self::TAX_ZERO] = ['name' => 'IVA 0%', 'rate' => 0, 'active' => 1];
        StubStore::$taxes[self::TAX_21] = ['name' => 'IVA 21%', 'rate' => 21, 'active' => 1];
        foreach ($rules ?? [] as [$id, $ruleRate, $from, $to]) {
            StubStore::$taxRules[$id] = [
                'id_tax_rules_group' => self::PRODUCT_GROUP, 'id_country' => array_flip(self::COUNTRIES)[$taxCountry], 'id_state' => 0,
                'zipcode_from' => $from, 'zipcode_to' => $to, 'id_tax' => $ruleRate === 0 ? self::TAX_ZERO : self::TAX_21,
            ];
        }
        if ($discount) {
            self::addToTotals(Cart::ONLY_DISCOUNTS, 10.00, -1);
        }
        $keys = ['EX' => self::PRODUCT_GROUP . '|exempt', 'R0' => 'rule:' . self::RULE, 'R1' => 'rule:' . self::OTHER_RULE, 'NR' => self::PRODUCT_GROUP . '|none', 'EX2' => (self::PRODUCT_GROUP + 1) . '|exempt'];
        $map = [];
        foreach ($rows as $row => $code) {
            $map[$keys[$row]] = $code;
        }
        Configuration::updateValue('PS_TWO_TAX_CODE_MAP', json_encode($map));
        $payload = (new TwopaymentTestHarness())->getTwoNewOrderData('merchant-attempt-' . self::CART, new Cart(self::CART), self::merchantUrls());

        $line = $payload['line_items'][0];
        foreach ($payload['line_items'] as $candidate) {
            if ($discount && (float) $candidate['gross_amount'] < 0) {
                $line = $candidate;
            }
        }
        TinyAssert::true(!$discount || (float) $line['gross_amount'] < 0, $description . ': the cart has a discount line');
        TinyAssert::same($expected, $line['tax_code'] ?? null, $description . ' (got: ' . json_encode($line['tax_code'] ?? null) . ')');
    }

    /**
     * Case 18: the upgrade moves a group's code to its exempt row, its no-rule row and every 0% rule it has, and no
     * other group's rows; a mapping already stored as rows is left as it is.
     */
    private static function testMigrationFansTheGroupCodeOut(): void
    {
        self::seed('ES', 'goods', 'ES', '28001', 'ES', [], '0');
        StubStore::$taxes[self::TAX_ZERO] = ['name' => 'IVA 0%', 'rate' => 0, 'active' => 1];
        StubStore::$taxes[self::TAX_21] = ['name' => 'IVA 21%', 'rate' => 21, 'active' => 1];
        StubStore::$taxRules[8201] = ['id_tax_rules_group' => 8101, 'id_country' => 21, 'id_tax' => self::TAX_ZERO];
        StubStore::$taxRules[8202] = ['id_tax_rules_group' => 8101, 'id_country' => 34, 'id_tax' => self::TAX_21];
        StubStore::$taxRules[8203] = ['id_tax_rules_group' => 8101, 'id_country' => 34, 'zipcode_from' => '35000', 'zipcode_to' => '35999', 'id_tax' => 0];
        StubStore::$taxRules[8204] = ['id_tax_rules_group' => 8102, 'id_country' => 21, 'id_tax' => self::TAX_ZERO];
        Configuration::updateValue('PS_TWO_TAX_CODE_MAP', '{"8101":"ES_IVA_EXPORT"}');
        $module = new TwopaymentTestHarness();

        TinyAssert::same(1, $module->migrateTwoTaxCodeMapToRows(), 'one group moved');
        $moved = ['8101|exempt' => 'ES_IVA_EXPORT', '8101|none' => 'ES_IVA_EXPORT', 'rule:8201' => 'ES_IVA_EXPORT', 'rule:8203' => 'ES_IVA_EXPORT'];
        TinyAssert::same($moved, json_decode((string) Configuration::get('PS_TWO_TAX_CODE_MAP'), true), 'EX, NR and every 0% rule of the group take its code; the 21% rule and other groups none');
        TinyAssert::same(0, $module->migrateTwoTaxCodeMapToRows(), 'a mapping already in rows is not moved again');
        TinyAssert::same($moved, json_decode((string) Configuration::get('PS_TWO_TAX_CODE_MAP'), true), 'and is left as it is');
        Configuration::updateValue('PS_TWO_TAX_CODE_MAP', '');
        TinyAssert::same(0, $module->migrateTwoTaxCodeMapToRows(), 'a shop with no mapping has nothing to move');
    }

    /**
     * Step 4 on an update shares only the recorded codes steps 1 to 3 gave at placement, as placement itself does: a
     * stored code from anywhere else never joins the pool. Rows: the rows mapped, delivery country, invoice country, invoice VAT number,
     * a record written before provenance was kept (tax_codes_mapped dropped), expected discount code, description.
     */
    private static function testUpdateSharesOnlyMappedCodesWithKeylessLines(): void
    {
        $lamp = self::PRODUCT_GROUP;
        $rows = [
            [[], 'US', 'ES', '', false, null, 'an unmapped shop\'s export with goods, a service and a 0% discount: the discount gets no code'],
            [[$lamp . '|exempt' => self::INTRA], 'DE', 'DE', 'DE123', false, self::INTRA, 'a mapped goods group and an unmapped services group: the edit shares only the mapped code'],
            [[$lamp . '|none' => self::ART20, ($lamp + 1) . '|none' => self::ART22], 'ES', 'ES', '', true, null, 'a record from before provenance was kept whose lines disagree gives no code'],
            [[$lamp . '|none' => self::ART20, ($lamp + 1) . '|none' => self::ART20], 'ES', 'ES', '', true, self::ART20, 'a record from before provenance was kept whose lines agree shares their code'],
        ];
        foreach ($rows as [$map, $dest, $buyer, $vat, $legacy, $expected, $description]) {
            self::seed('ES', 'mixed', $dest, '10001', $buyer, [], '0');
            StubStore::$addresses[self::INVOICE]['vat_number'] = $vat;
            Configuration::updateValue('PS_TWO_TAX_CODE_MAP', $map === [] ? '' : json_encode($map));
            self::addToTotals(Cart::ONLY_DISCOUNTS, 10.00, -1);
            $module = new TwopaymentTestHarness();
            $created = $module->getTwoNewOrderData('merchant-attempt-' . self::CART, new Cart(self::CART), self::merchantUrls());
            $discount = static function (array $lines) {
                foreach ($lines as $line) {
                    if ((float) $line['gross_amount'] < 0) {
                        return $line['tax_code'] ?? null;
                    }
                }

                return 'no discount line';
            };
            TinyAssert::same($expected, $discount($created['line_items']), $description . ': at create');
            $record = json_decode((string) $module->getTwoDeclaredChargeRates(), true);
            if ($legacy) {
                unset($record['tax_codes_mapped']);
            }
            $decode = new ReflectionMethod(Twopayment::class, 'decodeTwoDeclaredChargeRates');
            $declared = $decode->invoke($module, ['two_declared_rates' => json_encode($record)]);
            $lines = [['tax_rate' => '0', 'gross_amount' => '100.00'], ['tax_rate' => '0', 'gross_amount' => '100.00'], ['tax_rate' => '0', 'gross_amount' => '-10.00']];
            $keys = [
                ['key' => 'product:' . self::PRODUCT, 'group' => $lamp, 'discount' => false],
                ['key' => 'product:' . (self::PRODUCT + 1), 'group' => $lamp + 1, 'discount' => false],
                ['key' => null, 'group' => 0, 'discount' => true],
            ];
            $apply = new ReflectionMethod(Twopayment::class, 'applyTwoTaxCodes');
            $updated = $apply->invoke(new TwopaymentTestHarness(), $lines, $keys, new Address(self::DELIVERY), new Address(self::INVOICE), $declared['tax_codes'], $declared['tax_codes_mapped']);
            TinyAssert::same($expected, $discount($updated), $description . ': on update (got: ' . json_encode($discount($updated)) . ')');
        }
    }

    /** The upgrade moves the global tier and every shop group and shop tier holding a mapping of its own. */
    private static function testMigrationMovesEveryShopTier(): void
    {
        self::seed('ES', 'goods', 'ES', '28001', 'ES', [], '0');
        StubStore::$multistore = true;
        StubStore::$shops = [1 => 1, 2 => 1, 3 => 2];
        Shop::setContext(Shop::CONTEXT_SHOP, 1);
        StubStore::$configuration['PS_TWO_TAX_CODE_MAP'] = '{"8101":"ES_IVA_EXPORT"}';
        StubStore::$configurationGroup[2]['PS_TWO_TAX_CODE_MAP'] = '{"8102":"ES_IVA_EXEMPT_ART20"}';
        StubStore::$configurationShop[2]['PS_TWO_TAX_CODE_MAP'] = '{"8103":"ES_IVA_EXEMPT_ART22"}';
        StubStore::$configurationShop[3]['PS_TWO_TAX_CODE_MAP'] = '{"8103|none":"ES_IVA_EXPORT"}';

        TinyAssert::same(3, (new TwopaymentTestHarness())->migrateTwoTaxCodeMapToRows(), 'three tiers moved');
        TinyAssert::same(['8101|exempt' => 'ES_IVA_EXPORT', '8101|none' => 'ES_IVA_EXPORT'], json_decode(StubStore::$configuration['PS_TWO_TAX_CODE_MAP'], true), 'the global tier');
        TinyAssert::same(['8102|exempt' => 'ES_IVA_EXEMPT_ART20', '8102|none' => 'ES_IVA_EXEMPT_ART20'], json_decode(StubStore::$configurationGroup[2]['PS_TWO_TAX_CODE_MAP'], true), 'a shop group tier');
        TinyAssert::same(['8103|exempt' => 'ES_IVA_EXEMPT_ART22', '8103|none' => 'ES_IVA_EXEMPT_ART22'], json_decode(StubStore::$configurationShop[2]['PS_TWO_TAX_CODE_MAP'], true), 'a shop tier');
        TinyAssert::same('{"8103|none":"ES_IVA_EXPORT"}', StubStore::$configurationShop[3]['PS_TWO_TAX_CODE_MAP'], 'a tier already in rows is left as it is');
        TinyAssert::false(isset(StubStore::$configurationShop[1]['PS_TWO_TAX_CODE_MAP']), 'a shop inheriting the global value gets no copy');

        // A tier that cannot be written is named, every other tier still moves, and the old value stays.
        StubStore::$configurationShop[2]['PS_TWO_TAX_CODE_MAP'] = '{"8103":"ES_IVA_EXEMPT_ART22"}';
        StubStore::$configuration['PS_TWO_TAX_CODE_MAP'] = '{"8101":"ES_IVA_EXPORT"}';
        StubStore::$configurationUpdateFailsOnce['PS_TWO_TAX_CODE_MAP'] = true;
        TinyAssert::throws(function () {
            (new TwopaymentTestHarness())->migrateTwoTaxCodeMapToRows();
        }, 'the tax code mapping of the global tier could not be written');
        TinyAssert::same('{"8101":"ES_IVA_EXPORT"}', StubStore::$configuration['PS_TWO_TAX_CODE_MAP'], 'the failed tier keeps its old value');
        TinyAssert::same(['8103|exempt' => 'ES_IVA_EXEMPT_ART22', '8103|none' => 'ES_IVA_EXEMPT_ART22'], json_decode(StubStore::$configurationShop[2]['PS_TWO_TAX_CODE_MAP'], true), 'the other tiers still move');
    }

    /** A mapping the upgrade did not move keeps coding orders, read as the rows it would become, and is logged. */
    private static function testAnUnmovedGroupMappingStillReads(): void
    {
        self::seed('ES', 'goods', 'US', '10001', 'ES', [], '0');
        Configuration::updateValue('PS_TWO_TAX_CODE_MAP', json_encode([(string) self::PRODUCT_GROUP => self::ART20]));
        $module = new TwopaymentTestHarness();
        TinyAssert::true($module->isTwoTaxCodeMapReadable(), 'the payment method is not withheld');
        $payload = $module->getTwoNewOrderData('merchant-attempt-' . self::CART, new Cart(self::CART), self::merchantUrls());
        TinyAssert::same(self::ART20, $payload['line_items'][0]['tax_code'] ?? null, 'the group\'s code still reaches its line');
        TinyAssert::true(self::logged('is still stored per tax rules group'), 'and the unmoved mapping is logged');
        $count = static function () {
            return count(array_filter(PrestaShopLogger::$logs, static function ($log) {
                return strpos(is_array($log) ? (string) reset($log) : (string) $log, 'is still stored per tax rules group') !== false;
            }));
        };
        $before = $count();
        (new TwopaymentTestHarness())->getTwoTaxCodeMap();
        TinyAssert::same($before, $count(), 'and not again on the next request the same day');
    }

    /** A post PHP cut short at max_input_vars is refused, never reported as saved. */
    private static function testATruncatedFormIsRefused(): void
    {
        self::seed('ES', 'goods', 'ES', '28001', 'ES', [], '0');
        StubStore::$taxRulesGroups = [8101 => ['name' => 'IVA 21%', 'active' => 1], 8102 => ['name' => 'IVA 10%', 'active' => 1]];
        $module = new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                return ['http_status' => 200, 'data' => TaxCodeSpec::codeList()];
            }

            public int $limit = 0;

            protected function getTwoMaxInputVars()
            {
                return $this->limit;
            }

            public function validate(): array
            {
                $this->errors = [];
                $this->validTwoOrderManagementFormValues();

                return $this->errors;
            }
        };
        // PHP keeps a post's first max_input_vars values and drops the rest, so a cut post is a prefix of the form's fields,
        // whose last is the end field. Rows: fields posted in form order (END is the end field), errors, description.
        $all = ['PS_TWO_ENABLE_TAX_SUBTOTALS', '8101_EXEMPT', '8101_NONE', '8102_EXEMPT', '8102_NONE'];
        $rows = [
            [array_merge($all, ['END']), 0, 'a whole post saves'],
            [$all, 1, 'a post with every row but no end field is refused'],
            [['PS_TWO_ENABLE_TAX_SUBTOTALS'], 1, 'a post cut before the first row is refused'],
            [['PS_TWO_ENABLE_TAX_SUBTOTALS', '8101_EXEMPT', '8101_NONE'], 1, 'a post cut mid-rows is refused'],
            [['PS_TWO_ENABLE_TAX_SUBTOTALS', 'END'], 0, 'a whole post of a form without the mapping saves'],
        ];
        foreach ($rows as [$posted, $errors, $description]) {
            $module->limit = 1000;
            Tools::resetTestValues();
            foreach ($posted as $field) {
                if ($field === 'END') {
                    Tools::setTestValue(Twopayment::ORDER_MANAGEMENT_FORM_END_FIELD, '1');
                } elseif ($field === 'PS_TWO_ENABLE_TAX_SUBTOTALS') {
                    Tools::setTestValue($field, '1');
                } else {
                    Tools::setTestValue('PS_TWO_TAX_CODE_MAP_' . $field, $field === '8102_NONE' ? 'ES_IVA_EXPORT' : '');
                }
            }
            $got = $module->validate();
            TinyAssert::same($errors, count($got), $description . ' (got: ' . json_encode($got) . ')');
            if ($errors > 0) {
                TinyAssert::true(strpos($got[0], 'at most 1000 per request, its max_input_vars setting') !== false, $description . ': the error names max_input_vars and its value');
            }
        }
        Tools::resetTestValues();
    }

    /**
     * The hook context's fallback_shipping_tax_code: the code steps 1 to 3 give a 0% line in the Default shipping tax
     * code's group at the cart's addresses. Rows: Default shipping tax code group set?, delivery country, invoice
     * country, invoice VAT number, rows mapped of that group, expected, description.
     */
    private static function testFallbackShippingTaxCodeInTheHookContext(): void
    {
        $group = self::DEFAULT_SHIPPING_GROUP;
        $rows = [
            [false, 'US', 'ES', '', [$group . '|none' => self::EXPORT], null, 'no Default shipping tax code: null'],
            [true, 'US', 'ES', '', [$group . '|none' => self::EXPORT], self::EXPORT, 'an export takes the group\'s no-rule row'],
            [true, 'DE', 'DE', 'DE123', [$group . '|exempt' => self::INTRA, $group . '|none' => self::EXPORT], self::INTRA, 'an EU buyer with a VAT number takes the exempt row'],
            [true, 'ES', 'ES', '', [$group . '|exempt' => self::INTRA], null, 'a matched row on (none): null'],
        ];
        foreach ($rows as [$set, $dest, $buyer, $vat, $map, $expected, $description]) {
            self::seed('ES', 'goods', $dest, '10001', $buyer, [], '0');
            Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_delivery');
            StubStore::$addresses[self::INVOICE]['vat_number'] = $vat;
            if ($set) {
                Configuration::updateValue('PS_TWO_SHIPPING_TAX_FALLBACK_ENABLED', '1');
                Configuration::updateValue('PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP', (string) $group);
                StubStore::$taxRulesGroups[$group] = ['name' => 'Shipping', 'active' => 1];
            }
            Configuration::updateValue('PS_TWO_TAX_CODE_MAP', json_encode($map));
            $context = (new TwopaymentTestHarness())->buildTwoOrderPostprocessingContext('order_create', 'spec', '/v1/order', new Cart(self::CART));
            TinyAssert::same($expected, $context['fallback_shipping_tax_code'], $description);
        }
    }

    /**
     * The buyer VAT number (TWO-26153): a Spanish merchant's create payload carries the invoice address VAT number as
     * buyer_vat_number, as entered with only leading and trailing whitespace trimmed, except for a Spanish buyer.
     * Columns: merchant country, delivery country, invoice (buyer company) country, invoice vat_number, delivery
     * vat_number, expected buyer_vat_number (null for the key absent), description.
     *
     * @return array<int,array>
     */
    private static function vatRows(): array
    {
        return [
            ['ES', 'FR', 'DE', 'DE123456789', '', 'DE123456789', 'a German buyer\'s VAT number is sent'],
            ['ES', 'FR', 'DE', '', '', null, 'no VAT number: no key'],
            ['ES', 'FR', 'DE', '   ', '', null, 'whitespace only is no VAT number: no key'],
            ['ES', 'FR', 'DE', 'ESB12345678', '', 'ESB12345678', 'a prefix naming the merchant\'s country is sent as entered'],
            ['ES', 'FR', 'GR', '123456789', '', '123456789', 'an unprefixed number gains no prefix'],
            ['ES', 'FR', 'FR', 'fr 123.456-789', '', 'fr 123.456-789', 'a lower-case prefix is not upper-cased'],
            ['ES', 'FR', 'GR', 'GR123456789', '', 'GR123456789', 'a GR prefix is not rewritten to EL'],
            ['ES', 'FR', 'DE', ' .- ', '', '.-', 'any non-empty trimmed value is a VAT number'],
            ['ES', 'FR', 'DE', '', 'DE123456789', null, 'the delivery address VAT number is never a source: only the invoice address'],
            ['ES', 'FR', 'DE', 'DE111111111', 'NL222222222', 'DE111111111', 'the invoice address VAT number wins over the delivery one'],
            ['ES', 'FR', 'ES', 'ESB12345678', '', null, 'a Spanish buyer\'s VAT number is never sent'],
            ['DE', 'FR', 'FR', 'FR123456789', '', null, 'a non-Spanish merchant never sends the VAT number'],
            ['', 'FR', 'DE', 'DE123456789', '', null, 'merchant country not known yet (a cold or failed record): no key'],
            ['ES', 'FR', 'DE', 'n/a', '', 'n/a', 'a non-empty "n/a" is sent as entered'],
            ['ES', 'FR', 'DE', ' DE123 ', '', 'DE123', 'leading and trailing spaces are trimmed'],
            ['ES', 'FR', 'DE', "\tDE123\n", '', 'DE123', 'leading and trailing tabs and newlines are trimmed'],
            ['ES', 'FR', 'DE', 'DE 123/456', '', 'DE 123/456', 'inner spaces and slashes are kept'],
            ['ES', 'FR', 'DE', 'vat: DE123456789', '', 'vat: DE123456789', 'a leading label is kept and not parsed out'],
        ];
    }

    private static function assertVatRow(string $merchant, string $dest, string $buyer, string $vat, string $deliveryVat, ?string $sent, string $description): void
    {
        self::seed($merchant, 'goods', $dest, '10001', $buyer, [], '0');
        StubStore::$addresses[self::INVOICE]['vat_number'] = $vat;
        StubStore::$addresses[self::DELIVERY]['vat_number'] = $deliveryVat;
        $payload = (new TwopaymentTestHarness())->getTwoNewOrderData('merchant-attempt-' . self::CART, new Cart(self::CART), self::merchantUrls());

        TinyAssert::same($sent, $payload['buyer_vat_number'] ?? null, $description . ' (got buyer_vat_number: ' . json_encode($payload['buyer_vat_number'] ?? null) . ')');
        TinyAssert::same($sent !== null, array_key_exists('buyer_vat_number', $payload), $description . ': the key is absent, never empty');
    }

    /**
     * Charge lines. Each row: a setup run after seeding a domestic 0% goods cart for a Spanish merchant, the expected
     * code of every line keyed by line (see codesByLine()), and a description.
     *
     * @return array<int,array>
     */
    private static function chargeRows(): array
    {
        $map = static function (array $map): void {
            self::storeGroupMap($map);
        };
        $ecotax = static function (): void {
            StubStore::$cartProducts[self::CART][0]['ecotax'] = 5.00;
            StubStore::$cartProducts[self::CART][0]['ecotax_tax_rate'] = 0.0;
            Configuration::updateValue('PS_ECOTAX_TAX_RULES_GROUP_ID', (string) self::ECOTAX_GROUP);
            StubStore::$taxRuleRates[self::ECOTAX_GROUP] = 0.0;
        };
        $wrapping = static function (): void {
            Configuration::updateValue('PS_GIFT_WRAPPING_TAX_RULES_GROUP', (string) self::WRAPPING_GROUP);
            StubStore::$taxRuleRates[self::WRAPPING_GROUP] = 0.0;
            self::addToTotals(Cart::ONLY_WRAPPING, 2.00, 1);
        };
        $fee = static function (): void {
            Configuration::updateValue('PS_TWO_SURCHARGE_TAX_RULES_GROUP', (string) self::FEE_GROUP);
        };
        $discount = static function (): void {
            self::addToTotals(Cart::ONLY_DISCOUNTS, 10.00, -1);
        };
        $abroad = static function (string $country): void {
            StubStore::$addresses[self::DELIVERY]['id_country'] = array_flip(self::COUNTRIES)[$country];
        };

        return [
            [function () use ($ecotax, $map) {
                $ecotax();
                $map([self::ECOTAX_GROUP => self::ART20]);
            }, ['Lamp' => null, 'Lamp - Ecotax' => self::ART20, 'Courier' => null], 'ecotax maps by the ecotax tax rules group'],
            [function () use ($wrapping, $map) {
                $wrapping();
                $map([self::WRAPPING_GROUP => self::ART20]);
            }, ['Lamp' => null, 'Courier' => null, 'Gift wrapping' => self::ART20], 'wrapping maps by the gift-wrapping tax rules group'],
            [function () use ($wrapping, $map) {
                $wrapping();
                $map([self::PRODUCT_GROUP => self::ART20, self::CARRIER_GROUP => self::ART20]);
            }, ['Lamp' => self::ART20, 'Courier' => self::ART20, 'Gift wrapping' => null], 'unmapped wrapping with a group of its own gets no code, though the other lines share one'],
            [function () use ($fee, $map) {
                $fee();
                $map([self::FEE_GROUP => self::ART20]);
            }, ['Lamp' => null, 'Courier' => null, 'Fee' => self::ART20], 'the buyer fee maps by its own tax rules group'],
            [function () use ($fee, $map) {
                $fee();
                $map([self::PRODUCT_GROUP => self::ART20, self::CARRIER_GROUP => self::ART20]);
            }, ['Lamp' => self::ART20, 'Courier' => self::ART20, 'Fee' => null], 'an unmapped fee gets no code, though the other lines share one'],
            [function () use ($discount, $map) {
                $discount();
                $map([self::PRODUCT_GROUP => self::ART20, self::CARRIER_GROUP => self::ART20]);
            }, ['Lamp' => self::ART20, 'Courier' => self::ART20, 'discount' => self::ART20], 'a 0% discount takes the one code its order\'s 0% lines share'],
            [function () use ($discount, $map) {
                $discount();
                $map([self::PRODUCT_GROUP => self::ART20]);
            }, ['Lamp' => self::ART20, 'Courier' => null, 'discount' => self::ART20], 'a 0% discount takes the code of the lines coded from the mapping; the unmapped shipping has none'],
            [function () use ($discount, $abroad) {
                $discount();
                $abroad('US');
            }, ['Lamp' => null, 'Courier' => null, 'discount' => null], 'an unmapped shop\'s export with a 0% discount: no line gets a code'],
            [function () use ($map) {
                StubStore::$carriers[self::CARRIER]['tax_rules_group_id'] = 0;
                Configuration::updateValue('PS_TWO_SHIPPING_TAX_FALLBACK_ENABLED', '1');
                Configuration::updateValue('PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP', (string) self::DEFAULT_SHIPPING_GROUP);
                StubStore::$taxRuleRates[self::DEFAULT_SHIPPING_GROUP] = 0.0;
                StubStore::$taxRulesGroups[self::DEFAULT_SHIPPING_GROUP] = ['name' => 'Shipping 0%', 'active' => 1];
                self::refreshCarrierInstances();
                $map([self::DEFAULT_SHIPPING_GROUP => self::ART22]);
            }, ['Lamp' => null, 'Courier' => self::ART22], 'shipping whose rate the Default shipping tax code supplied maps by that group'],
            [function () use ($map) {
                self::shipWith([self::OTHER_CARRIER]);
                $map([self::CARRIER_GROUP => self::ART20, self::OTHER_CARRIER_GROUP => self::ART22]);
            }, ['Lamp' => null, 'Courier' => self::ART22], 'shipping maps by the carrier the delivery option ships with, never a stale cart carrier'],
            [function () use ($map) {
                self::shipWith([self::CARRIER, self::OTHER_CARRIER]);
                $map([self::CARRIER_GROUP => self::ART20, self::OTHER_CARRIER_GROUP => self::ART22]);
            }, ['Lamp' => null, 'Courier' => null], 'carriers declaring different 0% groups give shipping no group: nothing mapped'],
        ];
    }

    /** @var array<string,array> fixtures written in TAX_CODE_GOLDEN_WRITE mode */
    private static array $written = [];

    private static function assertRow(array $row, array $golden): void
    {
        [$merchant, $cart, $destCountry, $destPostcode, $buyerCountry, $vat, $map, $rate, $expected, $goldenName, $description] = $row;
        self::seed($merchant, $cart, $destCountry, $destPostcode, $buyerCountry, $map, $rate);
        StubStore::$addresses[self::INVOICE]['vat_number'] = $vat;
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

    private static function assertChargeRow(callable $setup, array $expected, string $description): void
    {
        self::seed('ES', 'goods', 'ES', '28001', 'ES', [], '0');
        $setup();
        $module = new class extends TwopaymentTestHarness {
            public function buildTwoSurchargeLineItemForCart($cart, $gross_basis, $paymentTermDays = null, &$quoteUnavailable = null)
            {
                $quoteUnavailable = false;
                if ((string) Configuration::get('PS_TWO_SURCHARGE_TAX_RULES_GROUP') === '') {
                    return null;
                }

                return [
                    'name' => 'Fee', 'description' => 'Fee', 'gross_amount' => '3.00', 'net_amount' => '3.00',
                    'discount_amount' => '0.00', 'tax_amount' => '0.00', 'tax_class_name' => 'VAT 0.00%', 'tax_rate' => '0',
                    'unit_price' => '3.00', 'quantity' => 1, 'quantity_unit' => 'item', 'type' => 'SERVICE',
                ];
            }
        };
        // No cart surcharge line to sync, so the fee parity is logged rather than enforced.
        $payload = $module->getTwoNewOrderData('merchant-attempt-' . self::CART, new Cart(self::CART), self::merchantUrls(), false);
        $got = self::codesByLine($payload);
        TinyAssert::same($expected, $got, $description . ' (got: ' . json_encode($got) . ')');
    }

    private static function testUpdateKeepsPlacementCodes(): void
    {
        self::seed('ES', 'goods', 'US', '10001', 'ES', [self::PRODUCT_GROUP => self::EXPORT, self::CARRIER_GROUP => self::EXPORT], '0');
        $module = new TwopaymentTestHarness();
        $module->getTwoNewOrderData('merchant-attempt-' . self::CART, new Cart(self::CART), self::merchantUrls());
        $record = (string) $module->getTwoDeclaredChargeRates();
        TinyAssert::same(['product:' . self::PRODUCT => self::EXPORT, 'shipping' => self::EXPORT], json_decode($record, true)['tax_codes'] ?? null, 'placement records the codes it resolved');

        // The mapping is later cleared and the order edited to a domestic address: the codes placement resolved still go out.
        Configuration::updateValue('PS_TWO_TAX_CODE_MAP', '');
        StubStore::$addresses[self::DELIVERY]['id_country'] = 34;
        StubStore::$addresses[self::DELIVERY]['postcode'] = '28001';
        $payload = (new TwopaymentTestHarness())->getTwoUpdateOrderData(self::order(), self::paymentRow($record));
        TinyAssert::same([self::EXPORT, self::EXPORT], self::codes($payload), 'an update sends the codes placement resolved');
    }

    /**
     * An update resolves an unrecorded line's step 1 from the invoice address VAT number, as create does, and never
     * carries buyer_vat_number (TWO-26153). Rows: invoice vat_number, expected codes, description.
     */
    private static function testUpdateReadsTheVatNumberLikeCreate(): void
    {
        $rows = [
            ['DE123456789', [self::INTRA, self::INTRA], 'an update with a German VAT number takes the exempt rows'],
            ['', [null, null], 'an update with no VAT number falls on the unmapped no-rule rows: no code'],
        ];
        foreach ($rows as [$vat, $expected, $description]) {
            self::seed('ES', 'goods', 'FR', '75001', 'DE', [], '0');
            Configuration::updateValue('PS_TWO_TAX_CODE_MAP', json_encode([self::PRODUCT_GROUP . '|exempt' => self::INTRA, self::CARRIER_GROUP . '|exempt' => self::INTRA]));
            StubStore::$addresses[self::INVOICE]['vat_number'] = $vat;
            $payload = (new TwopaymentTestHarness())->getTwoUpdateOrderData(self::order(), self::paymentRow('{"shipping":[],"wrapping":null}'));
            TinyAssert::same($expected, self::codes($payload), $description . ' (got: ' . json_encode(self::codes($payload)) . ')');
            TinyAssert::false(array_key_exists('buyer_vat_number', $payload), $description . ': no buyer_vat_number on an update');
        }
    }

    private static function testUpdateOfUnrecordedOrderResolvesNow(): void
    {
        self::seed('ES', 'goods', 'US', '10001', 'ES', [self::PRODUCT_GROUP => self::EXPORT, self::CARRIER_GROUP => self::EXPORT], '0');
        $payload = (new TwopaymentTestHarness())->getTwoUpdateOrderData(self::order(), self::paymentRow('{"shipping":[],"wrapping":null}'));
        TinyAssert::same([self::EXPORT, self::EXPORT], self::codes($payload), 'an order placed before the codes were recorded resolves them on update');
    }

    private static function testDescriptorMismatchSendsLinesUncoded(): void
    {
        self::seed('ES', 'goods', 'US', '10001', 'ES', [], '0');
        $lines = [['tax_rate' => '0'], ['tax_rate' => '0']];
        $got = self::apply($lines, [['key' => 'product:1', 'group' => 0, 'discount' => false]], new Address(self::DELIVERY), new Address(self::INVOICE));
        TinyAssert::same($lines, $got, 'lines the descriptors do not describe go out uncoded, never refused');
        TinyAssert::true(self::logged('Tax codes not resolved - 1 line descriptors for 2 lines'), 'and the mismatch is logged');
    }

    private static function testMappingIsReadOnlyForAZeroLineAndFailsLoud(): void
    {
        $corrupt = '{"abc":"ES_IVA_EXPORT"}';
        self::seed('DE', 'goods', 'US', '10001', 'DE', [], '21');
        Configuration::updateValue('PS_TWO_TAX_CODE_MAP', $corrupt);
        $payload = (new TwopaymentTestHarness())->getTwoNewOrderData('merchant-attempt-' . self::CART, new Cart(self::CART), self::merchantUrls());
        TinyAssert::same([null, null], self::codes($payload), 'an order with no 0% line never reads the stored mapping');

        self::seed('ES', 'goods', 'ES', '28001', 'ES', [], '0');
        Configuration::updateValue('PS_TWO_TAX_CODE_MAP', $corrupt);
        TinyAssert::throws(function () {
            (new TwopaymentTestHarness())->getTwoNewOrderData('merchant-attempt-' . self::CART, new Cart(self::CART), self::merchantUrls());
        }, 'tax code mapping is unreadable');
    }

    private static function testMerchantCountryIsStoredAndRefetchedOnce(): void
    {
        self::seed('', 'goods', 'ES', '28001', 'ES', [], '0');
        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'merchant-1');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-1');
        $module = self::recordingModule(['http_status' => 200, 'id' => 'merchant-1', 'country_code' => 'es', 'invoice_distributed_by_merchant' => false]);
        TinyAssert::true($module->refreshMerchantRecord(), 'the merchant record is fetched');
        TinyAssert::same('ES', Configuration::get('PS_TWO_MERCHANT_COUNTRY'), 'the record\'s country_code is stored, upper-cased');

        // A record cached before the country was kept: refetched once, then not again inside the floor.
        Configuration::updateValue('PS_TWO_MERCHANT_COUNTRY', '');
        $module = self::recordingModule(['http_status' => 200, 'id' => 'merchant-1', 'country_code' => 'ES', 'invoice_distributed_by_merchant' => false]);
        TinyAssert::same('ES', $module->getTwoMerchantCountry(), 'a held record with no country is refetched for it');
        Configuration::updateValue('PS_TWO_MERCHANT_COUNTRY', '');
        TinyAssert::same('', $module->getTwoMerchantCountry(), 'inside the retry floor nothing is fetched');
        TinyAssert::same(1, count($module->requested), 'one fetch per floor');
    }

    private static function testTaxCodeListHidesCodesNeedingAReason(): void
    {
        self::seed('ES', 'goods', 'ES', '28001', 'ES', [], '0');
        $module = self::recordingModule(['http_status' => 200, 'data' => self::codeList()]);
        $codes = array_column((array) $module->getTwoTaxCodeOptions('ES'), 'code');
        TinyAssert::same(['ES_IVA_EXPORT', 'ES_IVA_EXEMPT_ART20', 'ES_IVA_STANDARD'], $codes, 'a code needing a caller-supplied reason is not offered');
        $module->getTwoTaxCodeOptions('ES');
        TinyAssert::same(['GET /v1/tax_codes/ES'], $module->requested, 'the list is fetched for the merchant country once, then served from cache');
    }

    private static function testTaxCodeListRetriesOnAFloor(): void
    {
        self::seed('ES', 'goods', 'ES', '28001', 'ES', [], '0');
        $module = self::recordingModule(['http_status' => 503]);
        $meta = null;
        TinyAssert::same(null, $module->getTwoTaxCodeOptions('ES', $meta), 'no list can be had');
        TinyAssert::same(503, $meta['http'], 'and the section is told why');
        $module->getTwoTaxCodeOptions('ES', $meta);
        TinyAssert::same(1, count($module->requested), 'a failed fetch is not retried on every page load');
    }

    /**
     * The mapping dropdown's option label. Rows: code, display name, rate, expected label, description.
     */
    private static function testOptionLabelShowsTheRateOnce(): void
    {
        $label = new ReflectionMethod(Twopayment::class, 'formatTwoTaxCodeOptionLabel');
        $module = new TwopaymentTestHarness();
        $rows = [
            ['ES_IVA_STANDARD', 'IVA General (21%)', 0.21, 'ES_IVA_STANDARD - IVA General (21%)', 'a name ending with its rate is not given it again'],
            ['FI_ALV_STANDARD', 'ALV Yleinen (25.5%)', 0.255, 'FI_ALV_STANDARD - ALV Yleinen (25.5%)', 'a decimal rate in the name counts too'],
            ['ES_IVA_ZERO', 'IVA Tipo Cero (0%)', 0.0, 'ES_IVA_ZERO - IVA Tipo Cero (0%)', 'a zero rate in the name counts too'],
            ['ES_IVA_EXPORT', 'Exportación', 0.0, 'ES_IVA_EXPORT - Exportación (0%)', 'a name without a rate is given it'],
            ['ES_IVA_EXEMPT_ART20', 'Exento - Artículo 20', 0.0, 'ES_IVA_EXEMPT_ART20 - Exento - Artículo 20 (0%)', 'a name ending in a number but no rate is given it'],
            ['XX_TEST', 'Test (B2B)', 0.1, 'XX_TEST - Test (B2B) (10%)', 'a name ending in a bracket that is not a rate is given it'],
        ];
        foreach ($rows as [$code, $name, $rate, $expected, $description]) {
            TinyAssert::same($expected, $label->invoke($module, ['code' => $code, 'display_name' => $name, 'rate' => $rate]), $description);
        }
    }

    /**
     * The Order management save, one select per row (TWO-26153). Rows: stored map, posted fields (by suffix after
     * PS_TWO_TAX_CODE_MAP_), expected result, description.
     */
    private static function testFormSaveValidatesPostedCodes(): void
    {
        $rows = [
            [[], ['8101_EXEMPT' => 'ES_IVA_EXPORT'], ['8101|exempt' => 'ES_IVA_EXPORT'], 'a listed code is saved on the exempt row'],
            [[], ['RULE_8201' => 'ES_IVA_EXPORT'], ['rule:8201' => 'ES_IVA_EXPORT'], 'a 0% tax rule has a row of its own'],
            [[], ['RULE_8202' => 'ES_IVA_EXPORT'], false, 'a rule above 0% has no row, so its field is not read'],
            [[], ['8101_NONE' => 'ES_IVA_EXEMPT_OTHER'], null, 'a code the list does not offer is refused'],
            [[], ['8101_NONE' => 'ES_IVA_STANDARD'], null, 'a listed code above 0% is refused on a 0% row'],
            [['8101|none' => 'ES_OLD_CODE'], ['8101_NONE' => 'ES_OLD_CODE', '8102_EXEMPT' => 'ES_IVA_EXPORT'], ['8101|none' => 'ES_OLD_CODE', '8102|exempt' => 'ES_IVA_EXPORT'], 'a stored code no longer listed survives an unrelated save'],
            [['8101|exempt' => 'ES_IVA_EXPORT', '8103|none' => 'ES_IVA_EXEMPT_ART20'], ['8101_EXEMPT' => ''], ['8103|none' => 'ES_IVA_EXEMPT_ART20'], '(none) removes a mapping, and a group not on the form keeps its own'],
            [['8101|exempt' => 'ES_IVA_EXPORT'], [], false, 'a form without the mapping writes nothing'],
        ];
        foreach ($rows as [$stored, $posted, $expected, $description]) {
            self::seed('ES', 'goods', 'ES', '28001', 'ES', [], '0');
            StubStore::$taxRulesGroups[8101] = ['name' => 'IVA 0%', 'active' => 1];
            StubStore::$taxRulesGroups[8102] = ['name' => 'IVA 21%', 'active' => 1];
            StubStore::$taxes[self::TAX_ZERO] = ['name' => 'IVA 0%', 'rate' => 0, 'active' => 1];
            StubStore::$taxes[self::TAX_21] = ['name' => 'IVA 21%', 'rate' => 21, 'active' => 1];
            StubStore::$taxRules[8201] = ['id_tax_rules_group' => 8101, 'id_country' => 34, 'id_tax' => self::TAX_ZERO];
            StubStore::$taxRules[8202] = ['id_tax_rules_group' => 8101, 'id_country' => 8, 'id_tax' => self::TAX_21];
            Configuration::updateValue('PS_TWO_TAX_CODE_MAP', $stored === [] ? '' : json_encode($stored));
            self::showSection();
            Tools::resetTestValues();
            foreach ($posted as $field => $code) {
                Tools::setTestValue('PS_TWO_TAX_CODE_MAP_' . $field, $code);
            }
            TinyAssert::same($expected, self::formModule()->exposePostedTwoTaxCodeMap(), $description);
        }
        Tools::resetTestValues();
    }

    /**
     * The form's rows for a group: the exempt buyer, each 0% rule labelled as the shop describes it, by country id
     * (a rule above 0% is not listed), then no rule.
     */
    private static function testFormRowsFollowTheShopRules(): void
    {
        self::seed('ES', 'goods', 'ES', '28001', 'ES', [], '0');
        StubStore::$taxRulesGroups = [8101 => ['name' => 'IVA 21%', 'active' => 1]];
        StubStore::$taxes[self::TAX_21] = ['name' => 'IVA 21%', 'rate' => 21, 'active' => 1];
        StubStore::$taxes[self::TAX_ZERO] = ['name' => 'IVA 0%', 'rate' => 0, 'active' => 1];
        StubStore::$taxRules[8201] = ['id_tax_rules_group' => 8101, 'id_country' => 34, 'zipcode_from' => '35000', 'zipcode_to' => '35999', 'id_tax' => 0];
        StubStore::$taxRules[8202] = ['id_tax_rules_group' => 8101, 'id_country' => 34, 'id_tax' => self::TAX_21];
        StubStore::$taxRules[8203] = ['id_tax_rules_group' => 8101, 'id_country' => 21, 'id_tax' => self::TAX_ZERO, 'description' => 'Exports'];
        $got = array_map(static function (array $row) {
            return [$row['field'], $row['key'], $row['label']];
        }, self::formModule()->exposeRows());
        TinyAssert::same([
            ['PS_TWO_TAX_CODE_MAP_8101_EXEMPT', '8101|exempt', 'VAT-registered buyer, other EU state'],
            ['PS_TWO_TAX_CODE_MAP_RULE_8203', 'rule:8203', 'US (IVA 0%) - Exports'],
            ['PS_TWO_TAX_CODE_MAP_RULE_8201', 'rule:8201', 'ES, 35000-35999 (No tax)'],
            ['PS_TWO_TAX_CODE_MAP_8101_NONE', '8101|none', 'No tax rule for the buyer\'s address (no tax charged, e.g. exports)'],
        ], $got, 'rows for the group (got: ' . json_encode($got) . ')');
    }

    private static function formModule(): TwopaymentTestHarness
    {
        return new class extends TwopaymentTestHarness {
            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                return ['http_status' => 200, 'data' => TaxCodeSpec::codeList(), 'metadata' => ['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['0']]];
            }

            public function exposePostedTwoTaxCodeMap()
            {
                return $this->getPostedTwoTaxCodeMap();
            }

            public function exposeRows()
            {
                return $this->getTwoTaxCodeMapRows();
            }
        };
    }

    /** The section shows its rows (TWO-26153): an API key with a fresh profile in ES, whose code list is mandatory. */
    private static function showSection(): void
    {
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-1');
        Configuration::updateValue('PS_TWO_TAX_CODE_PROFILE', json_encode([
            'slot' => TwopaymentTestHarness::recordKeyStampForTest('key-1'), 'merchant_id' => 'merchant-1', 'country' => 'ES',
            'fetched_at' => time(), 'tried_at' => time(), 'failure' => null,
        ]));
    }

    /** @return array<int,array> a /v1/tax_codes/ES response's data */
    public static function codeList(): array
    {
        return [
            ['code' => 'ES_IVA_EXPORT', 'display_name' => 'Exportación', 'rate' => '0', 'requires_exemption_reason' => true, 'exemption_reason_code' => 'VATEX-EU-G'],
            ['code' => 'ES_IVA_EXEMPT_ART20', 'display_name' => 'Exento art. 20', 'rate' => '0', 'requires_exemption_reason' => true, 'exemption_reason_code' => 'VATEX-EU-132'],
            ['code' => 'ES_IVA_EXEMPT_OTHER', 'display_name' => 'Exento otros', 'rate' => '0', 'requires_exemption_reason' => true, 'exemption_reason_code' => null],
            ['code' => 'ES_IVA_STANDARD', 'display_name' => 'General', 'rate' => '0.21', 'requires_exemption_reason' => false, 'exemption_reason_code' => null],
        ];
    }

    private static function recordingModule(array $response): TwopaymentTestHarness
    {
        return new class ($response) extends TwopaymentTestHarness {
            public array $requested = [];
            private array $response;

            public function __construct(array $response)
            {
                parent::__construct();
                $this->response = $response;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                $this->requested[] = $method . ' ' . $endpoint;

                return $this->response;
            }
        };
    }

    private static function apply(array $lines, array $keys, Address $delivery, Address $invoice): array
    {
        $module = new TwopaymentTestHarness();
        $method = new ReflectionMethod(Twopayment::class, 'applyTwoTaxCodes');

        return $method->invoke($module, $lines, $keys, $delivery, $invoice);
    }

    private static function seed(string $merchant, string $cart, string $destCountry, string $destPostcode, string $buyerCountry, array $map, string $rate): void
    {
        OrderPostprocessingSpec::seed(true);
        $ids = array_flip(self::COUNTRIES);
        foreach (self::COUNTRIES as $id => $iso) {
            StubStore::$countries[$id] = $iso;
        }
        [$buyerCountry, $buyerPostcode] = array_pad(explode(' ', $buyerCountry, 2), 2, null);
        StubStore::$addresses[self::INVOICE]['id_country'] = $ids[$buyerCountry];
        if ($buyerPostcode !== null) {
            StubStore::$addresses[self::INVOICE]['postcode'] = $buyerPostcode;
        }
        StubStore::$addresses[self::DELIVERY] = ['id_country' => $ids[$destCountry], 'postcode' => $destPostcode] + StubStore::$addresses[self::INVOICE];
        StubStore::$carts[self::CART]['id_address_delivery'] = self::DELIVERY;

        $zero = $rate === '0';
        StubStore::$cartProducts[self::CART] = [];
        self::addProduct(0, $cart === 'service', $zero);
        if ($cart === 'mixed') {
            self::addProduct(1, true, $zero);
        }
        StubStore::$taxRuleRates[self::CARRIER_GROUP] = $zero ? 0.0 : 21.0;
        StubStore::$carriers[self::OTHER_CARRIER] = ['name' => 'Courier', 'delay' => '', 'tax_rules_group_id' => self::OTHER_CARRIER_GROUP];
        StubStore::$taxRuleRates[self::OTHER_CARRIER_GROUP] = $zero ? 0.0 : 21.0;
        self::shipWith([self::CARRIER]);

        Configuration::updateValue('PS_TWO_MERCHANT_COUNTRY', $merchant);
        if ($map !== []) {
            self::storeGroupMap($map);
        }
    }

    /**
     * Store a mapping by tax rules group as a shop upgrading from it holds it, and move it to the rows as the 2.7.20
     * upgrade does: each group's code on its exempt row, its no-rule row and each 0% rule it has.
     *
     * @param array<int,string> $map
     */
    private static function storeGroupMap(array $map): void
    {
        Configuration::updateValue('PS_TWO_TAX_CODE_MAP', json_encode(array_combine(array_map('strval', array_keys($map)), $map)));
        (new TwopaymentTestHarness())->migrateTwoTaxCodeMapToRows();
    }

    private static function addProduct(int $n, bool $virtual, bool $zero, ?string $name = null): void
    {
        $product = [
            'id_product' => self::PRODUCT + $n, 'link_rewrite' => 'lamp', 'name' => $name ?? ($virtual && $n > 0 ? 'Service' : 'Lamp'),
            'description_short' => 'Lamp', 'manufacturer_name' => 'ACME', 'ean13' => '', 'upc' => '', 'total' => 100.00,
            'total_wt' => $zero ? 100.00 : 121.00, 'cart_quantity' => 1, 'rate' => $zero ? 0.0 : 21.0, 'price' => 100.00,
            'reduction' => 0, 'is_virtual' => $virtual ? 1 : 0,
        ];
        StubStore::$cartProducts[self::CART][] = $product;
        StubStore::$products[self::PRODUCT + $n]['id_tax_rules_group'] = self::PRODUCT_GROUP + $n;
        StubStore::$taxRuleRates[self::PRODUCT_GROUP + $n] = $zero ? 0.0 : 21.0;
        StubStore::$productCategories[self::PRODUCT + $n] = [['name' => 'General']];
        self::setTotals();
    }

    /**
     * Ship the cart with these carriers, splitting the 29.00 between them; the cart's own id_carrier stays CARRIER.
     *
     * @param int[] $carriers
     */
    private static function shipWith(array $carriers): void
    {
        $zero = (float) StubStore::$taxRuleRates[self::CARRIER_GROUP] === 0.0;
        $list = [];
        foreach ($carriers as $id) {
            $share = 29.00 / count($carriers);
            $list[$id] = ['price_with_tax' => $share, 'price_without_tax' => $zero ? $share : round($share / 1.21, 4), 'instance' => new Carrier($id)];
        }
        StubStore::$cartDeliveryOptionLists[self::CART] = [self::DELIVERY => [implode(',', $carriers) . ',' => ['carrier_list' => $list]]];
        self::setTotals();
    }

    private static function refreshCarrierInstances(): void
    {
        foreach (StubStore::$cartDeliveryOptionLists[self::CART] as $address => $options) {
            foreach ($options as $key => $option) {
                foreach (array_keys($option['carrier_list']) as $id) {
                    StubStore::$cartDeliveryOptionLists[self::CART][$address][$key]['carrier_list'][$id]['instance'] = new Carrier($id);
                }
            }
        }
    }

    private static function setTotals(): void
    {
        $net = 0.0;
        $gross = 0.0;
        foreach (StubStore::$cartProducts[self::CART] as $row) {
            $net += $row['total'];
            $gross += $row['total_wt'];
        }
        $zero = (float) (StubStore::$taxRuleRates[self::CARRIER_GROUP] ?? 0.0) === 0.0;
        $shippingNet = $zero ? 29.00 : 23.9669;
        StubStore::$cartTotals[self::CART] = [
            true => [Cart::ONLY_DISCOUNTS => 0.00, Cart::ONLY_SHIPPING => 29.00, Cart::BOTH => $gross + 29.00],
            false => [Cart::ONLY_DISCOUNTS => 0.00, Cart::ONLY_SHIPPING => $shippingNet, Cart::BOTH => $net + $shippingNet],
        ];
    }

    /** Add an untaxed amount of one total type, signed into the cart total. */
    private static function addToTotals(int $type, float $amount, int $sign): void
    {
        foreach ([true, false] as $withTax) {
            StubStore::$cartTotals[self::CART][$withTax][$type] = $amount;
            StubStore::$cartTotals[self::CART][$withTax][Cart::BOTH] += $sign * $amount;
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

    /**
     * Every line's tax_code by its name; a discount line is keyed 'discount'.
     *
     * @return array<string,string|null>
     */
    private static function codesByLine(array $payload): array
    {
        $codes = [];
        foreach ($payload['line_items'] as $line) {
            $key = $line['type'] === 'DIGITAL' && $line['name'] !== 'Gift wrapping' ? 'discount' : $line['name'];
            $codes[$key] = $line['tax_code'] ?? null;
        }

        return $codes;
    }

    private static function logged(string $fragment): bool
    {
        foreach (PrestaShopLogger::$logs as $log) {
            if (strpos(is_array($log) ? (string) reset($log) : (string) $log, $fragment) !== false) {
                return true;
            }
        }

        return false;
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
