<?php

declare(strict_types=1);

/**
 * TWO-24877 - tax codes on 0% lines.
 *
 * One row per derivation rule of the README table, plus the mapping, the non-Spanish merchant and the non-zero
 * cases; then one row per kind of charge line (ecotax, wrapping, the buyer fee, discounts, shipping by carrier and
 * by the Default shipping tax code); then the address fallbacks, the merchant country, the code list and the
 * settings form. The cart is OrderPostprocessingSpec's (a 100.00 lamp and 29.00 of shipping, Madrid invoice address)
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
    private const COUNTRIES = [34 => 'ES', 8 => 'FR', 1 => 'DE', 21 => 'US', 17 => 'NO', 6 => 'NL', 148 => 'MC'];

    private const EXPORT = 'ES_IVA_EXPORT';
    private const INTRA = 'ES_IVA_INTRA_COMMUNITY_GOODS';
    private const SERVICES = 'ES_IVA_INTRA_COMMUNITY_SERVICES';
    private const NON_EU = 'ES_IVA_NON_EU_SERVICES';
    private const ART20 = 'ES_IVA_EXEMPT_ART20';
    private const ART22 = 'ES_IVA_EXEMPT_ART22';

    public static function runAll(): void
    {
        $golden = self::golden();
        $failures = [];
        foreach (self::rows() as $row) {
            self::collect($failures, $row[9], function () use ($row, $golden) {
                self::assertRow($row, $golden);
            });
        }
        foreach (self::chargeRows() as [$setup, $expected, $description]) {
            self::collect($failures, $description, function () use ($setup, $expected, $description) {
                self::assertChargeRow($setup, $expected, $description);
            });
        }
        $tests = [
            'testUpdateKeepsPlacementCodes', 'testUpdateOfUnrecordedOrderResolvesNow', 'testAddressFallbacks',
            'testDescriptorMismatchSendsLinesUncoded', 'testMappingIsReadOnlyForAZeroLineAndFailsLoud',
            'testMerchantCountryIsStoredAndRefetchedOnce', 'testTaxCodeListHidesCodesNeedingAReason',
            'testTaxCodeListRetriesOnAFloor', 'testFormSaveValidatesPostedCodes',
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
     * (buyer company) country optionally followed by a space and the invoice postcode, mapping (tax rules group => code), rate ('0' or '21'), the expected codes as
     * [lamp, (service product,) shipping], golden fixture name or null, description.
     *
     * @return array<int,array>
     */
    private static function rows(): array
    {
        $lamp = self::PRODUCT_GROUP;

        return [
            ['ES', 'goods', 'US', '10001', 'ES', [], '0', [self::EXPORT, self::EXPORT], null, 'goods delivered outside the EU: export, and shipping follows the goods'],
            ['ES', 'goods', 'ES', '35001', 'ES', [], '0', [self::EXPORT, self::EXPORT], null, 'goods delivered to Las Palmas (35): export'],
            ['ES', 'goods', 'ES', '38001', 'ES', [], '0', [self::EXPORT, self::EXPORT], null, 'goods delivered to Tenerife (38): export'],
            ['ES', 'goods', 'ES', '51001', 'ES', [], '0', [self::EXPORT, self::EXPORT], null, 'goods delivered to Ceuta (51): export'],
            ['ES', 'goods', 'ES', '52001', 'ES', [], '0', [self::EXPORT, self::EXPORT], null, 'goods delivered to Melilla (52): export'],
            ['ES', 'goods', 'FR', '75001', 'DE', [], '0', [self::INTRA, self::INTRA], null, 'goods delivered to France for a German buyer: intra-community, the buyer need not match the destination'],
            ['ES', 'goods', 'MC', '98000', 'FR', [], '0', [self::INTRA, self::INTRA], null, 'goods delivered to Monaco count as France: intra-community, not export'],
            ['ES', 'goods', 'FR', '75001', 'ES', [], '0', [null, null], 'goods-eu-dest-es-buyer', 'goods delivered to France for a Spanish buyer: nothing derived'],
            ['ES', 'goods', 'ES', '28001', 'FR', [], '0', [null, null], 'goods-domestic', 'goods delivered in mainland Spain, even for a French buyer: nothing derived'],
            ['ES', 'goods', 'ES', '07001', 'ES', [], '0', [null, null], null, 'goods delivered to the Balearics (07): domestic, nothing derived'],
            ['ES', 'service', 'ES', '28001', 'FR', [], '0', [self::SERVICES, self::SERVICES], null, 'a service for a French buyer: intra-community services, and shipping follows the services'],
            ['ES', 'service', 'FR', '75001', 'ES', [], '0', [null, null], null, 'a service for a Spanish buyer, delivered to France: nothing derived'],
            ['ES', 'service', 'NO', '0150', 'NO', [], '0', [self::NON_EU, self::NON_EU], null, 'a service for a buyer outside the EU: non-EU services'],
            ['ES', 'service', 'ES', '28001', 'US', [], '0', [self::NON_EU, self::NON_EU], null, 'a service for a buyer outside the EU, delivered in Spain: non-EU services'],
            ['ES', 'service', 'ES', '28001', 'ES 35001', [], '0', [self::NON_EU, self::NON_EU], null, 'a service for a buyer invoiced in Las Palmas (35): non-EU services'],
            ['ES', 'service', 'ES', '28001', 'ES 38001', [], '0', [self::NON_EU, self::NON_EU], null, 'a service for a buyer invoiced in Tenerife (38): non-EU services'],
            ['ES', 'service', 'ES', '28001', 'ES 51001', [], '0', [self::NON_EU, self::NON_EU], null, 'a service for a buyer invoiced in Ceuta (51): non-EU services'],
            ['ES', 'service', 'ES', '28001', 'ES 52001', [], '0', [self::NON_EU, self::NON_EU], null, 'a service for a buyer invoiced in Melilla (52): non-EU services'],
            ['ES', 'service', 'ES', '35001', 'ES 28001', [], '0', [null, null], null, 'a service delivered to the Canaries for a mainland buyer: nothing derived'],
            ['ES', 'goods', 'ES', '28001', 'ES 35001', [], '0', [null, null], null, 'goods delivered in mainland Spain for a buyer invoiced in the Canaries: nothing derived'],
            ['ES', 'mixed', 'FR', '75001', 'NL', [], '0', [self::INTRA, self::SERVICES, self::INTRA], null, 'goods and a service to the Netherlands: each by its own rule, shipping follows the goods'],
            ['ES', 'goods', 'ES', '28001', 'ES', [$lamp => self::ART20], '0', [self::ART20, null], null, 'a mapped product group: its code, and the unmapped shipping derives nothing domestic'],
            ['ES', 'goods', 'US', '10001', 'ES', [$lamp => self::ART20], '0', [self::ART20, self::EXPORT], null, 'mapping beats derivation'],
            ['ES', 'goods', 'ES', '28001', 'ES', [self::CARRIER_GROUP => self::ART22], '0', [null, self::ART22], null, 'shipping maps by its carrier\'s tax rules group'],
            ['DE', 'goods', 'US', '10001', 'DE', [], '0', [null, null], 'non-es-unmapped', 'non-Spanish merchant, unmapped: untouched'],
            ['DE', 'goods', 'US', '10001', 'DE', [$lamp => 'DE_ZERO'], '0', ['DE_ZERO', null], null, 'non-Spanish merchant, mapped: the mapping still applies'],
            ['', 'goods', 'US', '10001', 'ES', [], '0', [null, null], null, 'merchant country not known yet: nothing derived'],
            ['ES', 'goods', 'US', '10001', 'ES', [$lamp => self::ART20], '21', [null, null], 'non-zero', 'lines at 21%: untouched, mapping or not'],
        ];
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
            Configuration::updateValue('PS_TWO_TAX_CODE_MAP', json_encode(array_map('strval', array_combine(array_map('strval', array_keys($map)), $map))));
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
        $service = static function (string $buyer): void {
            StubStore::$cartProducts[self::CART][0]['is_virtual'] = 1;
            StubStore::$addresses[self::INVOICE]['id_country'] = array_flip(self::COUNTRIES)[$buyer];
        };
        $abroad = static function (string $country): void {
            StubStore::$addresses[self::DELIVERY]['id_country'] = array_flip(self::COUNTRIES)[$country];
        };

        return [
            [function () use ($ecotax, $map) {
                $ecotax();
                $map([self::ECOTAX_GROUP => self::ART20]);
            }, ['Lamp' => null, 'Lamp - Ecotax' => self::ART20, 'Courier' => null], 'ecotax maps by the ecotax tax rules group'],
            [function () use ($ecotax, $service, $abroad) {
                $ecotax();
                $service('NL');
                $abroad('FR');
                self::addProduct(1, false, true, 'Crate');
            }, ['Lamp' => self::SERVICES, 'Lamp - Ecotax' => self::SERVICES, 'Crate' => self::INTRA, 'Courier' => self::INTRA], 'ecotax on a service follows its product, not the order\'s goods'],
            [function () use ($wrapping, $map) {
                $wrapping();
                $map([self::WRAPPING_GROUP => self::ART20]);
            }, ['Lamp' => null, 'Courier' => null, 'Gift wrapping' => self::ART20], 'wrapping maps by the gift-wrapping tax rules group'],
            [function () use ($wrapping, $abroad) {
                $wrapping();
                $abroad('US');
            }, ['Lamp' => self::EXPORT, 'Courier' => self::EXPORT, 'Gift wrapping' => self::EXPORT], 'unmapped wrapping follows the goods'],
            [function () use ($fee, $map) {
                $fee();
                $map([self::FEE_GROUP => self::ART20]);
            }, ['Lamp' => null, 'Courier' => null, 'Fee' => self::ART20], 'the buyer fee maps by its own tax rules group'],
            [function () use ($fee, $service) {
                $fee();
                $service('FR');
            }, ['Lamp' => self::SERVICES, 'Courier' => self::SERVICES, 'Fee' => self::SERVICES], 'an unmapped fee on a services-only order follows the services'],
            [function () use ($discount, $map) {
                $discount();
                $map([self::PRODUCT_GROUP => self::ART20, self::CARRIER_GROUP => self::ART20]);
            }, ['Lamp' => self::ART20, 'Courier' => self::ART20, 'discount' => self::ART20], 'a 0% discount takes the one code its order\'s 0% lines share'],
            [function () use ($discount, $map) {
                $discount();
                $map([self::PRODUCT_GROUP => self::ART20]);
            }, ['Lamp' => self::ART20, 'Courier' => null, 'discount' => null], 'with no shared code a 0% discount derives like a charge: nothing domestic'],
            [function () use ($discount, $abroad) {
                self::addServiceProduct();
                $discount();
                $abroad('FR');
                StubStore::$addresses[self::INVOICE]['id_country'] = array_flip(self::COUNTRIES)['NL'];
            }, ['Lamp' => self::INTRA, 'Service' => self::SERVICES, 'Courier' => self::INTRA, 'discount' => self::INTRA], 'with no shared code a 0% discount follows the goods: intra-community'],
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
        [$merchant, $cart, $destCountry, $destPostcode, $buyerCountry, $map, $rate, $expected, $goldenName, $description] = $row;
        self::seed($merchant, $cart, $destCountry, $destPostcode, $buyerCountry, $map, $rate);
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
        self::seed('ES', 'goods', 'US', '10001', 'ES', [], '0');
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
        self::seed('ES', 'goods', 'US', '10001', 'ES', [], '0');
        $payload = (new TwopaymentTestHarness())->getTwoUpdateOrderData(self::order(), self::paymentRow('{"shipping":[],"wrapping":null}'));
        TinyAssert::same([self::EXPORT, self::EXPORT], self::codes($payload), 'an order placed before the codes were recorded resolves them on update');
    }

    /**
     * BUYER falls back to the invoice country when no country_prefix is sent; DEST to the invoice address when there
     * is no delivery address. Rows: buyer country sent, delivery address id, goods?, expected, description.
     */
    private static function testAddressFallbacks(): void
    {
        self::seed('ES', 'goods', 'ES', '28001', 'FR', [], '0');
        StubStore::$addresses[self::INVOICE]['postcode'] = '75001';
        $rows = [
            ['', self::DELIVERY, false, self::SERVICES, 'a service with no country_prefix follows the invoice (French) country'],
            ['ES', self::DELIVERY, false, null, 'a service follows the country_prefix sent, not the invoice country'],
            ['ES', 0, true, null, 'goods with no delivery address follow the invoice address (France), and a Spanish buyer derives nothing'],
            ['DE', 0, true, self::INTRA, 'goods with no delivery address follow the invoice address: France for a German buyer'],
            ['DE', self::DELIVERY, true, null, 'goods follow the delivery address (Madrid) when there is one'],
        ];
        foreach ($rows as [$buyer, $delivery, $goods, $expected, $description]) {
            $lines = self::apply(
                [['tax_rate' => '0']],
                [['key' => 'product:1', 'group' => 0, 'goods' => $goods, 'discount' => false]],
                new Address($delivery),
                new Address(self::INVOICE),
                $buyer
            );
            TinyAssert::same($expected, $lines[0]['tax_code'] ?? null, $description);
        }
    }

    private static function testDescriptorMismatchSendsLinesUncoded(): void
    {
        self::seed('ES', 'goods', 'US', '10001', 'ES', [], '0');
        $lines = [['tax_rate' => '0'], ['tax_rate' => '0']];
        $got = self::apply($lines, [['key' => 'product:1', 'group' => 0, 'goods' => true, 'discount' => false]], new Address(self::DELIVERY), new Address(self::INVOICE), 'ES');
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
        $codes = array_column((array) $module->getTwoTaxCodeOptions(), 'code');
        TinyAssert::same(['ES_IVA_EXPORT', 'ES_IVA_EXEMPT_ART20', 'ES_IVA_STANDARD'], $codes, 'a code needing a caller-supplied reason is not offered');
        $module->getTwoTaxCodeOptions();
        TinyAssert::same(['GET /v1/tax_codes/ES'], $module->requested, 'the list is fetched for the merchant country once, then served from cache');
    }

    private static function testTaxCodeListRetriesOnAFloor(): void
    {
        self::seed('ES', 'goods', 'ES', '28001', 'ES', [], '0');
        $module = self::recordingModule(['http_status' => 503]);
        $error = '';
        TinyAssert::same(null, $module->getTwoTaxCodeOptions($error), 'no list can be had');
        TinyAssert::true(strpos($error, 'HTTP 503') !== false, 'and the notice says why: ' . $error);
        $module->getTwoTaxCodeOptions($error);
        TinyAssert::same(1, count($module->requested), 'a failed fetch is not retried on every page load');
    }

    /**
     * The Order management save. Rows: stored map, posted fields, expected result, description.
     */
    private static function testFormSaveValidatesPostedCodes(): void
    {
        $g1 = 8101;
        $g2 = 8102;
        $g3 = 8103;
        $rows = [
            [[], [$g1 => 'ES_IVA_EXPORT'], [$g1 => 'ES_IVA_EXPORT'], 'a listed code is saved'],
            [[], [$g1 => 'ES_IVA_EXEMPT_OTHER'], null, 'a code the list does not offer is refused'],
            [[$g1 => 'ES_OLD_CODE'], [$g1 => 'ES_OLD_CODE', $g2 => 'ES_IVA_EXPORT'], [$g1 => 'ES_OLD_CODE', $g2 => 'ES_IVA_EXPORT'], 'a stored code no longer listed survives an unrelated save'],
            [[$g1 => 'ES_IVA_EXPORT', $g3 => 'ES_IVA_EXEMPT_ART20'], [$g1 => ''], [$g3 => 'ES_IVA_EXEMPT_ART20'], '(none) removes a mapping, and a group not on the form keeps its own'],
            [[$g1 => 'ES_IVA_EXPORT'], [], false, 'a form without the mapping writes nothing'],
        ];
        foreach ($rows as [$stored, $posted, $expected, $description]) {
            self::seed('ES', 'goods', 'ES', '28001', 'ES', [], '0');
            StubStore::$taxRulesGroups[$g1] = ['name' => 'IVA 0%', 'active' => 1];
            StubStore::$taxRulesGroups[$g2] = ['name' => 'IVA 21%', 'active' => 1];
            Configuration::updateValue('PS_TWO_TAX_CODE_MAP', $stored === [] ? '' : json_encode($stored));
            Tools::resetTestValues();
            foreach ($posted as $group => $code) {
                Tools::setTestValue('PS_TWO_TAX_CODE_MAP_' . $group, $code);
            }
            $module = new class extends TwopaymentTestHarness {
                public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
                {
                    return ['http_status' => 200, 'data' => TaxCodeSpec::codeList()];
                }

                public function exposePostedTwoTaxCodeMap()
                {
                    return $this->getPostedTwoTaxCodeMap();
                }
            };
            TinyAssert::same($expected, $module->exposePostedTwoTaxCodeMap(), $description);
        }
        Tools::resetTestValues();
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

    private static function apply(array $lines, array $keys, Address $delivery, Address $invoice, string $buyer): array
    {
        $module = new TwopaymentTestHarness();
        $method = new ReflectionMethod(Twopayment::class, 'applyTwoTaxCodes');

        return $method->invoke($module, $lines, $keys, $delivery, $invoice, $buyer, $invoice);
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
            Configuration::updateValue('PS_TWO_TAX_CODE_MAP', json_encode(array_combine(array_map('strval', array_keys($map)), $map)));
        }
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

    private static function addServiceProduct(): void
    {
        self::addProduct(1, true, true);
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
