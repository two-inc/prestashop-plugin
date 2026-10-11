<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/tax-admin-cases.php';

/**
 * TWO-26153 - the "Tax codes for 0% lines" section of the Order management form: when it shows, what it says, Clear,
 * and that rendering and saving it never changes what an order sends.
 *
 * The cases are tests/fixtures/tax-admin-cases.php, which the render baseline (dev/tax-admin-baseline.sh) also runs on
 * the base commit.
 */
final class TaxCodeAdminSpec
{
    private const N1 = TwoTaxCodeSection::NOTICE_SAVED_VALID;
    private const N2 = TwoTaxCodeSection::NOTICE_SAVED_INVALID;
    private const N3 = TwoTaxCodeSection::NOTICE_UNREADABLE;
    private const W1 = TwoTaxCodeSection::NOTICE_CHECKOUT_STALE;

    public static function runAll(): void
    {
        $failures = [];
        $expected = self::expected();
        foreach (TaxAdminCases::all() as [$name, $setup, $responses]) {
            self::collect($failures, $name, function () use ($name, $setup, $responses, $expected) {
                self::assertCase($name, $setup, $responses, $expected[$name]);
            });
            self::collect($failures, $name . ' (payloads)', function () use ($name, $setup, $responses) {
                self::assertPayloadsUnchanged($name, $setup, $responses);
            });
        }
        $tests = [
            'testNoRegistryIsCachedLikeAnAnswer', 'testListOutageIsNotRetriedInsideTheFloor', 'testProfileFailureLogsOncePerFloor',
            'testKeySwapIsResolvedAgain', 'testEnvironmentSwapIsResolvedAgain', 'testStaleCheckoutStillSavesAListedCode',
            'testNoKeyMakesNoRequest', 'testAllShopsContextFetchesNothing', 'testClearWinsOverPostedRows',
            'testHiddenSectionIgnoresPostedRows', 'testClearOnAShopWithItsOwnMap', 'testClearOnAShopInheritingTheGlobalMap',
            'testEachShopResolvesItsOwnKey', 'testClearMakesAnUnreadableMapReadable', 'testTableOffersOnlyZeroRatedCodes', 'testSelectsFitTheLongestCode',
            'testClearedPayloadEqualsTheUnmappedGolden', 'testMandatoryMeansANumericZeroRate', 'testClearRendersTheClearedMap', 'testRefusedClearKeepsThePostedPicks',
            'testListFloorIsPerCountry', 'testFailedListForAnotherCountryKeepsTheLastGoodOne', 'testKeySwappedSaveUsesTheProfileCountry',
            'testGeneralKeySaveRendersWithTheSavedKey', 'testRefreshButtonClearsAStoredLookupFailure', 'testGeneralKeySaveRunsTheButtonsRefresh',
        ];
        foreach ($tests as $test) {
            self::collect($failures, $test, function () use ($test) {
                self::$test();
            });
        }
        Tools::resetTestValues();
        if ($failures !== []) {
            throw new RuntimeException(count($failures) . " case(s) failed:\n  - " . implode("\n  - ", $failures));
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
     * Columns: view, notices in order, Clear offered, description.
     *
     * @return array<string,array>
     */
    private static function expected(): array
    {
        $rows = TwoTaxCodeSection::VIEW_ROWS;
        $hidden = TwoTaxCodeSection::VIEW_HIDDEN;
        $notice = TwoTaxCodeSection::VIEW_NOTICE;
        $profileError = TwoTaxCodeSection::VIEW_PROFILE_ERROR;

        return [
            'es-mandatory' => [$rows, [], false, 'ES, mandatory at "0": the rows'],
            'es-mandatory-0.00' => [$rows, [], false, 'ES, mandatory at "0.00": the rows'],
            'gb-nothing-saved' => [$hidden, [], false, 'GB with nothing saved: nothing at all'],
            'gb-es-map' => [$notice, [self::N2], true, 'GB holding Spanish codes: N2 and Clear'],
            'gb-zero-codes' => [$notice, [self::N1], true, 'GB holding its own 0% codes: N1 and Clear'],
            'gb-standard-code' => [$notice, [self::N2], true, 'GB holding a 20% code: N2 and Clear'],
            'gb-empty-object' => [$hidden, [], false, 'GB with {} stored: nothing'],
            'gb-unreadable' => [$notice, [self::N3], true, 'GB with an unreadable map: N3 and Clear'],
            'es-unreadable' => [$rows, [self::N3], true, 'ES with an unreadable map: N3 and Clear above the rows'],
            'no-key-unreadable' => [$hidden, [], false, 'no key wins over an unreadable map'],
            'it-not-supported' => [$hidden, [], false, 'a country with no registry: hidden'],
            'list-outage-cold' => [TwoTaxCodeSection::VIEW_LIST_ERROR, [], false, 'list outage with nothing cached: E2'],
            'list-outage-warm' => [$rows, [], false, 'list outage with an old answer cached: shown from it'],
            'verify-503' => [$profileError, [], false, 'verify 503: E1'],
            'verify-401' => [$profileError, [], false, 'verify 401: E1b'],
            'verify-transport' => [$profileError, [], false, 'verify unreachable: E1'],
            'merchant-without-country' => [$profileError, [], false, 'merchant 200 without country_code: E1'],
            'key-swapped' => [$rows, [self::W1], false, 'a key swapped without a save: resolved again, and W1'],
            'environment-swapped' => [$rows, [self::W1], false, 'an environment swapped without a save: resolved again, and W1'],
            'stale-merchant-id' => [$rows, [self::W1], false, 'checkout holds no merchant id: W1'],
            'no-key' => [$hidden, [], false, 'no key: hidden'],
            'no-mandatory' => [$rows, [], false, 'another country stubbed mandatory: the rows, with the exempt column (option A)'],
            'checkout-country-unknown' => [$rows, [], false, 'checkout learns its country on the same render: no W1'],
            'multistore-all-shops' => [TwoTaxCodeSection::VIEW_SELECT_SHOP, [], false, 'all shops: S1'],
            'multistore-shop' => [$rows, [], false, 'one shop of a multistore: the rows'],
        ];
    }

    private static function assertCase(string $name, callable $setup, array $responses, array $expected): void
    {
        [$view, $notices, $clear, $description] = $expected;
        $setup();
        $module = self::module($responses);
        $html = self::render($module);
        $state = self::state($module);
        TinyAssert::same($view, $state['view'], $description . ': view');
        TinyAssert::same($notices, $state['notices'], $description . ': notices');
        TinyAssert::same($clear, $state['clear'], $description . ': Clear');
        TinyAssert::same($view === TwoTaxCodeSection::VIEW_HIDDEN, $html === null, $description . ': nothing rendered exactly when hidden');
        if ($html !== null) {
            TinyAssert::same($view === TwoTaxCodeSection::VIEW_ROWS, strpos($html, 'id="two-tax-code-grid"') !== false, $description . ': the table renders only with the rows');
            TinyAssert::same($clear, strpos($html, 'name="' . Twopayment::TAX_CODES_CLEAR_FIELD . '"') !== false, $description . ': the Clear checkbox');
        }
        if ($name === 'verify-401') {
            TinyAssert::true(strpos((string) $html, 'Two rejected the stored API key; fix it on the General tab.') !== false, $description . ': E1b copy (got: ' . $html . ')');
        }
        if ($name === 'verify-503') {
            TinyAssert::true(strpos((string) $html, 'Could not read your merchant details from Two (HTTP 503); try again in a few minutes.') !== false, $description . ': E1 copy (got: ' . $html . ')');
        }
        if ($name === 'gb-es-map') {
            TinyAssert::true(strpos((string) $html, 'Some saved tax codes are not valid on 0% lines for your country (GB), so Two refuses orders that use them.') !== false, $description . ': N2 copy (got: ' . $html . ')');
        }
        if ($name === 'es-mandatory') {
            TinyAssert::true(strpos((string) $html, 'Two requires a tax code on every 0% line for your country (ES): choose one for each case below.') !== false, $description . ': intro copy');
            TinyAssert::true(strpos((string) $html, 'href="https://github.com/two-inc/prestashop-plugin#tax-codes-for-0-lines"') !== false, $description . ': the help link');
        }
    }

    /**
     * Render and save the section for a case, and check that the create, update, refund and hook-context payloads of
     * a 0% cart are the same after as before (the isolation proof's payload capture). The case's map is moved onto
     * the cart's tax rules group so its codes reach the payload.
     */
    private static function assertPayloadsUnchanged(string $name, callable $setup, array $responses): void
    {
        $setup();
        $config = [StubStore::$configuration, StubStore::$configurationGroup, StubStore::$configurationShop, StubStore::$multistore, StubStore::$shops, StubStore::$shopContext, StubStore::$contextShopId, StubStore::$contextShopGroupId];
        self::seedCart();
        [StubStore::$configuration, StubStore::$configurationGroup, StubStore::$configurationShop, StubStore::$multistore, StubStore::$shops, StubStore::$shopContext, StubStore::$contextShopId, StubStore::$contextShopGroupId] = $config;
        Configuration::updateValue('PS_TAX_ADDRESS_TYPE', 'id_address_delivery');
        foreach ([[0, 0], [1, 0], [0, 1], [0, 2]] as [$group, $shop]) {
            $raw = $group ? (StubStore::$configurationGroup[$group]['PS_TWO_TAX_CODE_MAP'] ?? null) : ($shop ? (StubStore::$configurationShop[$shop]['PS_TWO_TAX_CODE_MAP'] ?? null) : (StubStore::$configuration['PS_TWO_TAX_CODE_MAP'] ?? null));
            if (is_string($raw)) {
                $moved = str_replace('"8101|', '"' . self::cartGroup() . '|', $raw);
                if ($group) {
                    StubStore::$configurationGroup[$group]['PS_TWO_TAX_CODE_MAP'] = $moved;
                } elseif ($shop) {
                    StubStore::$configurationShop[$shop]['PS_TWO_TAX_CODE_MAP'] = $moved;
                } else {
                    StubStore::$configuration['PS_TWO_TAX_CODE_MAP'] = $moved;
                }
            }
        }
        // Compared as JSON: the hook context carries the cart object, a fresh instance per build.
        $capture = static function () use ($responses) {
            return json_encode(self::payloads($responses), JSON_PRESERVE_ZERO_FRACTION);
        };
        $before = $capture();
        if ($name === 'es-mandatory') {
            TinyAssert::true(strpos($before, '"tax_code":"ES_IVA_EXPORT"') !== false, $name . ': the saved codes reach the payloads compared');
        }
        $module = self::module($responses);
        self::render($module);
        $rendered = $capture();
        TinyAssert::same($before, $rendered, $name . ': rendering the section changed a payload (before: ' . $before . ', after: ' . $rendered . ')');
        self::postRenderedRows($module);
        self::save($module);
        Tools::resetTestValues();
        if ($name === 'es-unreadable') {
            // Saving the shown rows over an unreadable map stores what they show, every row on (none): the merchant
            // re-saving the mapping, so the shop now sends the unmapped payload.
            self::assertUnmappedGolden((string) Configuration::get('PS_TWO_TAX_CODE_MAP'), $name . ': saving the rows as rendered');

            return;
        }
        $after = $capture();
        TinyAssert::same($before, $after, $name . ': saving the section as rendered changed a payload (before: ' . $before . ', after: ' . $after . ')');
    }

    /** A Spanish buyer's 0% export cart, as TaxCodeSpec seeds it. */
    private static function seedCart(): void
    {
        $seed = new ReflectionMethod(TaxCodeSpec::class, 'seed');
        $seed->invoke(null, 'ES', 'goods', 'US', '10001', 'ES', [], '0');
    }

    private static function cartGroup(): int
    {
        return 9000 + 9721;
    }

    /** @return array<string,mixed> create, update, refund lines and hook context, as an order would send them now */
    private static function payloads(array $responses): array
    {
        $module = self::module($responses);
        $urls = ['merchant_confirmation_url' => 'https://shop.local/confirm', 'merchant_cancel_order_url' => 'https://shop.local/cancel', 'merchant_edit_order_url' => '', 'merchant_order_verification_failed_url' => '', 'merchant_invoice_url' => '', 'merchant_shipping_document_url' => ''];
        try {
            $create = $module->getTwoNewOrderData('merchant-attempt-9701', new Cart(9701), $urls);
            $create['merchant_reference'] = 'PINNED';
            $create['shipping_details']['expected_delivery_date'] = 'PINNED';
        } catch (Exception $e) {
            $create = 'refused: ' . $e->getMessage();
        }
        $order = PlacedOrderStub::fromCart(9731, 9701);
        $order->reference = 'TAXREF';
        StubStore::$placedOrders = [$order];
        try {
            $update = (new TaxAdminFixtureModule())->getTwoUpdateOrderData($order, ['two_order_id' => 'two-order-9731', 'two_order_reference' => 'ref', 'two_day_on_invoice' => '30', 'two_declared_rates' => '{"shipping":[],"wrapping":null}']);
            unset($update['shipping_details']['expected_delivery_date']);
        } catch (Exception $e) {
            $update = 'refused: ' . $e->getMessage();
        }
        $twoOrder = ['line_items' => [['id' => 'line-1', 'tax_rate' => '0', 'gross_amount' => '100.00', 'net_amount' => '100.00', 'tax_amount' => '0.00', 'tax_code' => 'ES_IVA_EXPORT']], 'refunds' => []];
        $refund = (new TaxAdminFixtureModule())->buildTwoRefundLineItems([['tax_rate' => '0', 'taxable_amount' => '10.00', 'tax_amount' => '0.00']], $twoOrder, [], 'Spec');
        try {
            $context = (new TaxAdminFixtureModule())->buildTwoOrderPostprocessingContext('order_create', 'spec', '/v1/order', new Cart(9701));
        } catch (Exception $e) {
            $context = 'refused: ' . $e->getMessage();
        }

        return ['create' => $create, 'update' => $update, 'refund' => $refund, 'context' => $context];
    }

    /** Post every select the section rendered with the value it rendered selected, and the end field. */
    private static function postRenderedRows(TaxAdminFixtureModule $module): void
    {
        Tools::resetTestValues();
        Tools::setTestValue(Twopayment::ORDER_MANAGEMENT_FORM_END_FIELD, '1');
        Tools::setTestValue('PS_TWO_ENABLE_TAX_SUBTOTALS', '1');
        $html = self::render($module);
        if ($html === null) {
            return;
        }
        preg_match_all('#<select name="([^"]+)"[^>]*>(.*?)</select>#s', $html, $selects, PREG_SET_ORDER);
        foreach ($selects as [, $field, $options]) {
            $value = preg_match('#<option value="([^"]*)" selected="selected">#', $options, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES) : '';
            Tools::setTestValue($field, $value);
        }
    }

    private static function module(array $responses): TaxAdminFixtureModule
    {
        $module = new TaxAdminFixtureModule();
        $module->responses = $responses;

        return $module;
    }

    /** @return string|null the section's HTML as the Order management form carries it; null when absent */
    private static function render(TaxAdminFixtureModule $module): ?string
    {
        $form = new ReflectionMethod(Twopayment::class, 'getTwoOrderManagementForm');
        foreach ($form->invoke($module)['form']['input'] as $input) {
            if ($input['name'] === 'PS_TWO_TAX_CODES_SECTION') {
                // The module's form.tpl gives the section the panel's full width, label above (Q82).
                TinyAssert::same(true, $input['two_full_width'] ?? null, 'the tax code section spans the full panel width');

                return $input['html_content'];
            }
        }

        return null;
    }

    private static function state(TaxAdminFixtureModule $module, bool $forSave = false): array
    {
        $state = new ReflectionMethod(Twopayment::class, 'getTwoTaxCodeSectionState');

        return $state->invoke($module, $forSave);
    }

    /** @return string[] validation errors; the save runs only without any, as the module's postProcess() does */
    private static function save(TaxAdminFixtureModule $module): array
    {
        return $module->saveOrderManagement();
    }

    /** @return array the named case's [setup, responses] */
    private static function fixture(string $name): array
    {
        foreach (TaxAdminCases::all() as [$case, $setup, $responses]) {
            if ($case === $name) {
                return [$setup, $responses];
            }
        }
        throw new RuntimeException('no case ' . $name);
    }

    private static function requests(TaxAdminFixtureModule $module, string $prefix): int
    {
        return count(array_filter($module->requested, static function ($request) use ($prefix) {
            return strpos($request, $prefix) === 0;
        }));
    }

    private static function testNoRegistryIsCachedLikeAnAnswer(): void
    {
        [$setup, $responses] = self::fixture('it-not-supported');
        $setup();
        $module = self::module($responses);
        self::render($module);
        self::render($module);
        TinyAssert::same(1, self::requests($module, 'GET /v1/tax_codes/IT'), 'one list request, then the 400 is served from cache');
    }

    private static function testListOutageIsNotRetriedInsideTheFloor(): void
    {
        [$setup, $responses] = self::fixture('list-outage-cold');
        $setup();
        $module = self::module($responses);
        TinyAssert::true(strpos((string) self::render($module), 'Could not check whether Two requires tax codes for your country (ES, HTTP 503); try again in a few minutes.') !== false, 'E2 names the country and the status');
        self::render($module);
        TinyAssert::same(1, self::requests($module, 'GET /v1/tax_codes/'), 'no retry inside the floor');
    }

    /** A list fetched for one country moments ago does not hold back the list for the next one. */
    private static function testListFloorIsPerCountry(): void
    {
        [$setup, $responses] = self::fixture('es-mandatory');
        $setup();
        self::render(self::module($responses));
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-2');
        $module = self::module(TaxAdminCases::healthy('GB', 'merchant-2'));
        self::render($module);
        TinyAssert::same(1, self::requests($module, 'GET /v1/tax_codes/GB'), 'the new country\'s list is fetched at once');
        TinyAssert::same(TwoTaxCodeSection::VIEW_NOTICE, self::state($module)['view'], 'and the section shows from it, not E2');
    }

    /** A failed fetch for another country leaves the last good list alone, so swapping back mid-outage still shows it. */
    private static function testFailedListForAnotherCountryKeepsTheLastGoodOne(): void
    {
        [$setup, $responses] = self::fixture('es-mandatory');
        $setup();
        self::render(self::module($responses));
        $outage = static function ($country, $merchantId) {
            $responses = TaxAdminCases::healthy($country, $merchantId);
            $responses['codes'] = array();

            return $responses;
        };
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-2');
        $module = self::module($outage('GB', 'merchant-2'));
        self::render($module);
        TinyAssert::same(TwoTaxCodeSection::VIEW_LIST_ERROR, self::state($module)['view'], 'the GB list is down: E2');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-1');
        $module = self::module($outage('ES', 'merchant-1'));
        self::render($module);
        TinyAssert::same(TwoTaxCodeSection::VIEW_ROWS, self::state($module)['view'], 'back on ES mid-outage: the rows from its last good list, not E2');
    }

    private static function testProfileFailureLogsOncePerFloor(): void
    {
        [$setup, $responses] = self::fixture('verify-503');
        $setup();
        $module = self::module($responses);
        self::render($module);
        self::render($module);
        $logged = array_values(array_filter(PrestaShopLogger::$logs, static function ($log) {
            return strpos($log['message'], 'TwoPayment: Tax codes: merchant lookup failed at verify_api_key') === 0;
        }));
        TinyAssert::same(1, count($logged), 'one resolver line per floor (got: ' . json_encode(PrestaShopLogger::$logs) . ')');
        TinyAssert::same(2, $logged[0]['severity'], 'at level 2');
        TinyAssert::false(strpos($logged[0]['message'], 'key-1') !== false, 'never with the key');
        TinyAssert::same(1, self::requests($module, 'GET /v1/merchant/verify_api_key'), 'one verify call per floor');
    }

    private static function testKeySwapIsResolvedAgain(): void
    {
        [$setup, $responses] = self::fixture('es-mandatory');
        $setup();
        self::render(self::module($responses));
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-2');
        $module = self::module(TaxAdminCases::healthy('ES', 'merchant-2'));
        self::render($module);
        TinyAssert::same(1, self::requests($module, 'GET /v1/merchant/verify_api_key'), 'a new key misses the profile');
        TinyAssert::same('merchant-2', json_decode((string) Configuration::get('PS_TWO_TAX_CODE_PROFILE'), true)['merchant_id'], 'and resolves its own merchant');
        TinyAssert::same('merchant-1', Configuration::get('PS_TWO_MERCHANT_ID'), 'checkout\'s merchant id is never written');
        TinyAssert::same([self::W1], self::state($module)['notices'], 'W1 says checkout has not caught up');
    }

    private static function testEnvironmentSwapIsResolvedAgain(): void
    {
        [$setup, $responses] = self::fixture('es-mandatory');
        $setup();
        self::render(self::module($responses));
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'production');
        $module = self::module($responses);
        self::render($module);
        TinyAssert::same(1, self::requests($module, 'GET /v1/merchant/verify_api_key'), 'another environment misses the profile');
    }

    private static function testStaleCheckoutStillSavesAListedCode(): void
    {
        [$setup, $responses] = self::fixture('stale-merchant-id');
        $setup();
        $module = self::module($responses);
        self::render($module);
        Tools::resetTestValues();
        Tools::setTestValue(Twopayment::ORDER_MANAGEMENT_FORM_END_FIELD, '1');
        Tools::setTestValue('PS_TWO_TAX_CODE_MAP_8101_EXEMPT', 'ES_IVA_INTRA_COMMUNITY');
        TinyAssert::same([], self::save($module), 'no validation error');
        TinyAssert::same('{"8101|exempt":"ES_IVA_INTRA_COMMUNITY"}', Configuration::get('PS_TWO_TAX_CODE_MAP'), 'W1 does not block saving a listed 0% code');
    }

    /** Checkout knows no country for a swapped key, so the save checks codes against the section's own profile. */
    private static function testKeySwappedSaveUsesTheProfileCountry(): void
    {
        [$setup, $responses] = self::fixture('key-swapped');
        $setup();
        $module = self::module($responses);
        self::render($module);
        TinyAssert::same('', $module->getTwoMerchantCountry(), 'checkout has no country for the new key');
        Tools::resetTestValues();
        Tools::setTestValue(Twopayment::ORDER_MANAGEMENT_FORM_END_FIELD, '1');
        Tools::setTestValue('PS_TWO_TAX_CODE_MAP_8101_NONE', 'ES_IVA_EXPORT');
        TinyAssert::same([], self::save($module), 'no validation error');
        TinyAssert::same('{"8101|none":"ES_IVA_EXPORT"}', Configuration::get('PS_TWO_TAX_CODE_MAP'), 'a code listed for the profile\'s country is saved');
        Tools::resetTestValues();
    }

    private static function testNoKeyMakesNoRequest(): void
    {
        [$setup, $responses] = self::fixture('no-key');
        $setup();
        $module = self::module($responses);
        self::render($module);
        TinyAssert::same([], $module->requested, 'no key: zero requests');
    }

    private static function testAllShopsContextFetchesNothing(): void
    {
        [$setup, $responses] = self::fixture('multistore-all-shops');
        $setup();
        $module = self::module($responses);
        TinyAssert::true(strpos((string) self::render($module), 'Select a single shop to see its tax code settings.') !== false, 'S1');
        TinyAssert::same([], $module->requested, 'no profile or list request');
    }

    private static function testClearWinsOverPostedRows(): void
    {
        [$setup, $responses] = self::fixture('es-mandatory');
        $setup();
        $module = self::module($responses);
        self::render($module);
        Tools::resetTestValues();
        Tools::setTestValue(Twopayment::ORDER_MANAGEMENT_FORM_END_FIELD, '1');
        Tools::setTestValue(Twopayment::TAX_CODES_CLEAR_FIELD, '1');
        Tools::setTestValue('PS_TWO_TAX_CODE_MAP_8101_EXEMPT', 'NOT_A_CODE');
        Tools::setTestValue('PS_TWO_TAX_CODE_MAP_8102_NONE', 'ES_IVA_EXPORT');
        TinyAssert::same([], self::save($module), 'rows are not validated');
        TinyAssert::same('{}', Configuration::get('PS_TWO_TAX_CODE_MAP'), 'the map is {}');
    }

    private static function testHiddenSectionIgnoresPostedRows(): void
    {
        [$setup, $responses] = self::fixture('gb-zero-codes');
        $setup();
        $stored = Configuration::get('PS_TWO_TAX_CODE_MAP');
        $module = self::module($responses);
        Tools::resetTestValues();
        Tools::setTestValue(Twopayment::ORDER_MANAGEMENT_FORM_END_FIELD, '1');
        Tools::setTestValue('PS_TWO_TAX_CODE_MAP_8101_NONE', 'NOT_A_CODE');
        Tools::setTestValue('PS_TWO_TAX_CODE_MAP_8102_NONE', '');
        TinyAssert::same([], self::save($module), 'a stale tab posting rows gets no validation error');
        TinyAssert::same($stored, Configuration::get('PS_TWO_TAX_CODE_MAP'), 'and the map is untouched');
    }

    private static function testClearOnAShopWithItsOwnMap(): void
    {
        [$setup, $responses] = self::fixture('multistore-shop');
        $setup();
        $global = Configuration::get('PS_TWO_TAX_CODE_MAP');
        Configuration::updateValue('PS_TWO_TAX_CODE_MAP', '{"8101|none":"ES_IVA_EXPORT"}', false, 1, 1);
        Configuration::updateValue('PS_TWO_TAX_CODE_MAP', '{"8102|none":"ES_IVA_EXPORT"}', false, 1, 2);
        self::clear($responses);
        TinyAssert::same('{}', StubStore::$configurationShop[1]['PS_TWO_TAX_CODE_MAP'], 'shop A\'s own row is {}');
        TinyAssert::same($global, StubStore::$configuration['PS_TWO_TAX_CODE_MAP'], 'the global map is untouched');
        TinyAssert::same('{"8102|none":"ES_IVA_EXPORT"}', StubStore::$configurationShop[2]['PS_TWO_TAX_CODE_MAP'], 'shop B is untouched');
    }

    private static function testClearOnAShopInheritingTheGlobalMap(): void
    {
        [$setup, $responses] = self::fixture('multistore-shop');
        $setup();
        $global = Configuration::get('PS_TWO_TAX_CODE_MAP');
        self::clear($responses);
        TinyAssert::same([], (new TaxAdminFixtureModule())->getTwoTaxCodeMap(), 'shop A reads an empty map');
        Shop::setContext(Shop::CONTEXT_SHOP, 2);
        TinyAssert::same($global, Configuration::get('PS_TWO_TAX_CODE_MAP'), 'shop B still reads the global map');
        TinyAssert::true((new TaxAdminFixtureModule())->getTwoTaxCodeMap() !== [], 'and orders there still use it');
    }

    private static function testEachShopResolvesItsOwnKey(): void
    {
        [$setup] = self::fixture('multistore-shop');
        $setup();
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-b', false, 1, 2);
        self::render(self::module(TaxAdminCases::healthy('ES', 'merchant-1')));
        Shop::setContext(Shop::CONTEXT_SHOP, 2);
        self::render(self::module(TaxAdminCases::healthy('ES', 'merchant-b')));
        TinyAssert::same('merchant-1', json_decode((string) StubStore::$configurationShop[1]['PS_TWO_TAX_CODE_PROFILE'], true)['merchant_id'], 'shop A\'s profile at its own tier');
        TinyAssert::same('merchant-b', json_decode((string) StubStore::$configurationShop[2]['PS_TWO_TAX_CODE_PROFILE'], true)['merchant_id'], 'shop B\'s profile at its own tier');
        TinyAssert::false(isset(StubStore::$configuration['PS_TWO_TAX_CODE_PROFILE']), 'nothing at the global tier');
    }

    private static function testClearMakesAnUnreadableMapReadable(): void
    {
        [$setup, $responses] = self::fixture('gb-unreadable');
        $setup();
        TinyAssert::false((new TaxAdminFixtureModule())->isTwoTaxCodeMapReadable(), 'unreadable before');
        self::clear($responses);
        TinyAssert::true((new TaxAdminFixtureModule())->isTwoTaxCodeMapReadable(), 'readable after Clear');
    }

    /** The dropdowns offer (none) and the 0% codes; a stored code that is not one is still shown, marked. */
    /** Every select is wide enough for the longest code it may hold, a stored one included, so no code clips closed. */
    private static function testSelectsFitTheLongestCode(): void
    {
        $cases = [
            [null, 'calc(25ch + 3em)', 'the longest listed code, ES_IVA_INTRA_COMMUNITY (22 x 1.1)'],
            ['{"8101|none":"ES_IVA_RETIRED_INTRA_COMMUNITY"}', 'calc(33ch + 3em)', 'a longer stored code it keeps, marked (30 x 1.1)'],
        ];
        foreach ($cases as [$map, $width, $description]) {
            [$setup, $responses] = self::fixture('es-mandatory');
            $setup();
            if ($map !== null) {
                Configuration::updateValue('PS_TWO_TAX_CODE_MAP', $map);
            }
            $html = (string) self::render(self::module($responses));
            $selects = preg_match_all('#<select #', $html);
            TinyAssert::true($selects > 0, $description . ': the table has selects');
            TinyAssert::same($selects, substr_count($html, 'style="min-width:' . $width . ';"'), $description . ': every select');
        }
    }

    private static function testTableOffersOnlyZeroRatedCodes(): void
    {
        [$setup, $responses] = self::fixture('es-mandatory');
        $setup();
        Configuration::updateValue('PS_TWO_TAX_CODE_MAP', '{"8101|none":"ES_IVA_STANDARD","rule:8201":"ES_RETIRED"}');
        $html = (string) self::render(self::module($responses));
        $rows = [
            ['<option value="ES_IVA_EXPORT">', true, 'a 0% code is offered'],
            ['<option value="ES_IVA_STANDARD">', false, 'a 21% code is not offered where it is not stored'],
            ['<option value="ES_IVA_STANDARD" selected="selected">ES_IVA_STANDARD (not valid on 0% lines)</option>', true, 'a stored 21% code shows, marked not valid'],
            ['<option value="ES_RETIRED" selected="selected">ES_RETIRED (no longer listed)</option>', true, 'a stored unlisted code shows, marked'],
            ['US (IVA 0%) - Exports', true, 'a 0% rule is labelled as the shop describes it'],
            ['No 0% tax rule in this group', true, 'a group with no 0% rule says so'],
            ['<th scope="col">VAT-registered buyer, other EU state</th>', true, 'the exempt column'],
            ['<th scope="col">No tax rule for the buyer&#039;s address (no tax charged, e.g. exports)</th>', true, 'the no-rule column'],
            ['class="text-warning" style="font-size:90%;margin-top:2px;">ES_IVA_STANDARD: not valid on 0% lines</div>', true, 'the not-valid marker shows under its select, whatever the column width'],
            ['>ES_RETIRED: no longer listed</div>', true, 'the unlisted marker shows under its select'],
            ['aria-label="Books: No tax rule for the buyer&#039;s address (no tax charged, e.g. exports)" aria-describedby="PS_TWO_TAX_CODE_MAP_8101_NONE_MARKER"', true, 'a select is named by group and column, and described by its marker'],
            ['aria-label="Books: Your 0% tax rules: US (IVA 0%) - Exports"', true, 'a rule\'s select is named by its rule too'],
            ['table-layout:fixed', false, 'columns size to their content'],
        ];
        foreach ($rows as [$needle, $present, $description]) {
            TinyAssert::same($present, strpos($html, $needle) !== false, $description);
        }
    }

    /** After Clear, the create payload equals TaxCodeSpec's unmapped golden. */
    private static function testClearedPayloadEqualsTheUnmappedGolden(): void
    {
        $seed = new ReflectionMethod(TaxCodeSpec::class, 'seed');
        $seed->invoke(null, 'ES', 'goods', 'ES', '28001', 'FR', [], '0');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-1');
        Configuration::updateValue('PS_TWO_TAX_CODE_MAP', '{"' . self::cartGroup() . '|none":"ES_IVA_EXPORT","' . self::cartGroup() . '|exempt":"ES_IVA_EXPORT"}');
        self::clear(TaxAdminCases::healthy('ES'));
        self::assertUnmappedGolden((string) Configuration::get('PS_TWO_TAX_CODE_MAP'), 'the cleared shop');
    }

    /**
     * A shop holding $map (its rows on tax rules group 8101 moved onto the cart's) sends TaxCodeSpec's unmapped
     * golden for a domestic Spanish 0% cart.
     */
    private static function assertUnmappedGolden(string $map, string $description): void
    {
        $seed = new ReflectionMethod(TaxCodeSpec::class, 'seed');
        $seed->invoke(null, 'ES', 'goods', 'ES', '28001', 'FR', [], '0');
        Configuration::updateValue('PS_TWO_TAX_CODE_MAP', str_replace('"8101|', '"' . self::cartGroup() . '|', $map));
        $pin = new ReflectionMethod(TaxCodeSpec::class, 'pin');
        $urls = new ReflectionMethod(TaxCodeSpec::class, 'merchantUrls');
        $payload = $pin->invoke(null, (new TwopaymentTestHarness())->getTwoNewOrderData('merchant-attempt-9701', new Cart(9701), $urls->invoke(null)));
        $golden = json_decode((string) file_get_contents(__DIR__ . '/fixtures/tax-code-golden.json'), true)['goods-domestic'];
        TinyAssert::same($golden, json_decode((string) json_encode($payload), true), $description . ' sends the unmapped payload (map: ' . $map . ')');
    }

    private static function testMandatoryMeansANumericZeroRate(): void
    {
        $rows = [
            [['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['0']], true, '"0"'],
            [['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['0.00']], true, '"0.00"'],
            [['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['21']], false, 'a mandatory rate above 0'],
            [['mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => ['zero']], false, 'a non-numeric rate'],
            [['mandatory_tax_codes_enabled' => false, 'mandatory_tax_rates' => ['0']], false, 'not enabled'],
            [null, false, 'no metadata'],
        ];
        foreach ($rows as [$metadata, $expected, $description]) {
            TinyAssert::same($expected, TwoTaxCodeSection::isMandatoryAtZero($metadata), $description);
        }
    }

    /** The page saves and renders in one request: after Clear the rows show the cleared map, not the posted picks. */
    private static function testClearRendersTheClearedMap(): void
    {
        [$setup, $responses] = self::fixture('es-mandatory');
        $setup();
        $module = self::module($responses);
        self::render($module);
        Tools::resetTestValues();
        Tools::setTestValue(Twopayment::ORDER_MANAGEMENT_FORM_END_FIELD, '1');
        Tools::setTestValue(Twopayment::TAX_CODES_CLEAR_FIELD, '1');
        Tools::setTestValue('PS_TWO_TAX_CODE_MAP_8101_NONE', 'ES_IVA_EXPORT');
        TinyAssert::same([], self::save($module), 'Clear saves');
        $html = (string) self::render($module);
        TinyAssert::same(0, preg_match('#<option value="[^"]+" selected="selected">#', $html), 'no row shows a code Clear discarded');
        $output = new ReflectionProperty(Twopayment::class, 'output');
        TinyAssert::true(strpos((string) $output->getValue($module), 'Saved tax codes cleared; choose codes again.') !== false, 'the save confirms the Clear');
        Tools::resetTestValues();
    }

    /** Clear ticked on a save refused for another reason discarded nothing, so the merchant's picks stay on the form. */
    private static function testRefusedClearKeepsThePostedPicks(): void
    {
        [$setup, $responses] = self::fixture('es-mandatory');
        $setup();
        $module = self::module($responses);
        self::render($module);
        Tools::resetTestValues();
        Tools::setTestValue(Twopayment::TAX_CODES_CLEAR_FIELD, '1');
        Tools::setTestValue('PS_TWO_TAX_CODE_MAP_8101_EXEMPT', 'ES_IVA_EXEMPT_ART20');
        TinyAssert::true(self::save($module) !== [], 'a cut post is refused');
        $html = (string) self::render($module);
        TinyAssert::true(preg_match('#<option value="ES_IVA_EXEMPT_ART20" selected="selected">#', $html) === 1, 'the posted pick is shown, not the stored one');
        Tools::resetTestValues();
    }

    /** Render once, then save with Clear ticked. */
    private static function clear(array $responses): void
    {
        $module = self::module($responses);
        self::render($module);
        Tools::resetTestValues();
        Tools::setTestValue(Twopayment::ORDER_MANAGEMENT_FORM_END_FIELD, '1');
        Tools::setTestValue(Twopayment::TAX_CODES_CLEAR_FIELD, '1');
        TinyAssert::same([], self::save($module), 'Clear saves');
        Tools::resetTestValues();
    }

    /**
     * TWO-26304: a General save with a new key renders every tab in the same request. Two answers the new merchant's
     * record only for its own key, so a call still carrying the old key gets the 404 the section then shows.
     */
    private static function testGeneralKeySaveRendersWithTheSavedKey(): void
    {
        [$setup] = self::fixture('es-mandatory');
        $setup();
        $module = new class extends TaxAdminFixtureModule {
            /** @var string[] the X-API-Key each /v1/merchant/<id> call carried */
            public $merchantKeys = array();

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                if (strpos($endpoint, '/v1/merchant/') === 0) {
                    $sent = '';
                    foreach ($this->getTwoRequestHeaders($endpoint) as $line) {
                        if (stripos($line, 'X-API-Key:') === 0) {
                            $sent = substr($line, strlen('X-API-Key:'));
                        }
                    }
                    $this->merchantKeys[] = $sent;
                    if ($sent !== 'key-2') {
                        $this->requested[] = $method . ' ' . $endpoint;

                        return array('http_status' => 404, 'error' => 'merchant not found');
                    }
                }

                return parent::setTwoPaymentRequest($endpoint, $payload, $method, $additional_headers, $timeout);
            }

            public function refreshTwoFxRates()
            {
                return false;
            }
        };
        $module->responses = TaxAdminCases::healthy('ES', 'merchant-2');
        // The copy the constructor took before the save.
        $module->api_key = 'key-1';
        Tools::setTestValue('PS_TWO_MERCHANT_API_KEY', 'key-2');
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'staging');
        Tools::setTestValue('PS_TWO_ENVIRONMENT', 'staging');
        foreach (array('validTwoGeneralFormValues', 'saveTwoGeneralFormValues') as $step) {
            $method = new ReflectionMethod(Twopayment::class, $step);
            $method->setAccessible(true);
            $method->invoke($module);
        }
        $html = (string) self::render($module);
        Tools::resetTestValues();

        TinyAssert::same('key-2', (string) Configuration::get('PS_TWO_MERCHANT_API_KEY'), 'the save stores the new key');
        TinyAssert::true($module->merchantKeys !== array(), 'the save and the render read the merchant record');
        TinyAssert::same(array(), array_values(array_diff($module->merchantKeys, array('key-2'))), 'every merchant record call after the save carries the saved key');
        TinyAssert::same(TwoTaxCodeSection::VIEW_ROWS, self::state($module)['view'], 'the tax code section renders its rows');
        TinyAssert::false(strpos($html, 'HTTP 404') !== false, 'and no 404 notice (got: ' . $html . ')');
    }

    /** TWO-26304: "Refresh merchant profile" drops a stored lookup failure and its floor, so the next render asks again. */
    private static function testRefreshButtonClearsAStoredLookupFailure(): void
    {
        [$setup, $responses] = self::fixture('verify-503');
        $setup();
        self::render(self::module($responses));
        $module = new class extends TaxAdminFixtureModule {
            /** @var array|null */
            public $response = null;

            protected function respondTwoRefreshMerchantRecord(array $payload)
            {
                $this->response = $payload;
            }
        };
        $module->responses = TaxAdminCases::healthy('ES');
        $html = (string) self::render($module);
        TinyAssert::true(strpos($html, 'HTTP 503') !== false, 'inside the floor the stored failure is shown (got: ' . $html . ')');

        $module->ajaxProcessRefreshMerchantRecord();
        $stored = json_decode((string) Configuration::get('PS_TWO_TAX_CODE_PROFILE'), true);
        $module->requested = array();
        $html = (string) self::render($module);

        TinyAssert::same(true, $module->response['success'] ?? null, 'the refresh succeeds');
        TinyAssert::same(array(0, null), array($stored['tried_at'], $stored['failure']), 'the refresh drops the failure and the floor');
        TinyAssert::same(1, self::requests($module, 'GET /v1/merchant/verify_api_key'), 'the next render looks the merchant up again');
        TinyAssert::same(TwoTaxCodeSection::VIEW_ROWS, self::state($module)['view'], 'and shows the rows');
    }

    /** TWO-26304: a General save that changes the key runs the same refresh as the button. */
    private static function testGeneralKeySaveRunsTheButtonsRefresh(): void
    {
        [$setup] = self::fixture('es-mandatory');
        $setup();
        $module = new class extends TaxAdminFixtureModule {
            /** @var int */
            public $profileRefreshes = 0;

            protected function refreshTwoMerchantProfile()
            {
                $this->profileRefreshes++;

                return parent::refreshTwoMerchantProfile();
            }

            public function refreshTwoFxRates()
            {
                return false;
            }
        };
        $module->responses = TaxAdminCases::healthy('ES', 'merchant-2');
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'staging');
        Tools::setTestValue('PS_TWO_ENVIRONMENT', 'staging');
        Tools::setTestValue('PS_TWO_MERCHANT_API_KEY', 'key-2');
        foreach (array('validTwoGeneralFormValues', 'saveTwoGeneralFormValues') as $step) {
            $method = new ReflectionMethod(Twopayment::class, $step);
            $method->setAccessible(true);
            $method->invoke($module);
        }
        Tools::resetTestValues();

        TinyAssert::same(1, $module->profileRefreshes, 'the save refreshes through the button\'s function');
    }
}
