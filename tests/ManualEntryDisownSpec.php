<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/orderintent.php';

/**
 * TWO-26775: after the buyer enters their company manually, the organisation
 * number they walked away from never reaches the order again.
 *
 * Every carrier of the manual-entry signal is driven here on its own, because
 * each exists for the case where the others were lost: the browser's clear
 * request, the address form's save, and the order-intent check.
 */
final class ManualEntryDisownSpec
{
    private const ADDRESS_ID = 7311;

    private const CART_ID = 7312;

    private const CUSTOMER_ID = 7313;

    private const CURRENCY_ID = 826;

    private const COUNTRY_ID = 17;

    private const OLD_ID = '12345678';

    private const OLD_NAME = 'Example Trading Ltd';

    public static function runAll(): void
    {
        self::testEachCarrierRefusesTheOldNumber();
        self::testAPickLiftsTheRefusal();
        self::testAddressSaveInManualEntryNeverStoresTheOldNumber();
        self::testManualIntentRefusesTheOldNumberFromTheForm();
        self::testAHandTypedNumberIsNeverRefused();
        self::testHousekeepingClearsKeepTheRefusal();
    }

    /**
     * After each carrier alone, the record holds no number and the saved address's
     * `dni` (still the old number) is not handed back by the resolver. The plain
     * clear row is the control: without the manual signal the address is still the
     * fallback, as before.
     */
    private static function testEachCarrierRefusesTheOldNumber(): void
    {
        $cases = [
            ['manual clear request', function () {
                self::runClear(['manual' => '1', 'companyid' => self::OLD_ID]);
            }, ''],
            ['manual clear request that sends no number', function () {
                self::runClear(['manual' => '1']);
            }, ''],
            ['address save flagged as manual entry, name unchanged', function () {
                self::runAddressSave(self::OLD_NAME, '', true);
            }, ''],
            ['order-intent check flagged as manual entry', function () {
                self::runIntent(['manual_entry' => '1', 'company' => self::OLD_NAME, 'companyid' => '']);
            }, ''],
            ['plain clear (not manual entry): address stays the fallback', function () {
                self::runClear([]);
            }, self::OLD_ID],
        ];

        foreach ($cases as [$description, $carrier, $expected]) {
            self::seed('12-345-678');
            $carrier();

            TinyAssert::same('', (string) TwoSessionRecord::get('company', 'id'), $description . ': the record kept a number');
            $resolved = self::module()->getTwoCheckoutCompanyData(new Address(self::ADDRESS_ID));
            TinyAssert::same(
                $expected === '' ? '' : '12-345-678',
                $resolved['organization_number'],
                $description . ': the resolver answered from the saved address'
            );
        }
    }

    /** Picking a company again is the one thing that lifts the refusal. */
    private static function testAPickLiftsTheRefusal(): void
    {
        self::seed(self::OLD_ID);
        self::runClear(['manual' => '1', 'companyid' => self::OLD_ID]);
        self::runController('saveCompany', ['company' => self::OLD_NAME, 'companyid' => self::OLD_ID, 'country' => 'GB']);

        TinyAssert::false(TwoSessionRecord::has('company', 'disowned_id'), 'a pick must lift the manual-entry refusal');
        $resolved = self::module()->getTwoCheckoutCompanyData(new Address(self::ADDRESS_ID));
        TinyAssert::same(self::OLD_ID, $resolved['organization_number'], 'the re-picked company must reach the order');
    }

    /**
     * The address form in manual entry can still carry the old number in its hidden
     * field (a `dni` copied across at submit). A different number is the buyer's
     * own and is kept.
     */
    private static function testAddressSaveInManualEntryNeverStoresTheOldNumber(): void
    {
        $cases = [
            ['old number posted by a manual-entry form', self::OLD_ID, ''],
            ['old number, differently formatted', '1234-5678', ''],
            ['buyer\'s own different number', '99887766', '99887766'],
        ];
        foreach ($cases as [$description, $posted, $expected]) {
            self::seed('');
            self::runAddressSave('Hand Typed Ltd', $posted, true);

            TinyAssert::same($expected, (string) TwoSessionRecord::get('company', 'id'), $description);
            TinyAssert::same('Hand Typed Ltd', (string) TwoSessionRecord::get('company', 'name'), $description . ': name');
        }
    }

    /** A manual-entry intent check posting the old number does not send it to Two, nor store it. */
    private static function testManualIntentRefusesTheOldNumberFromTheForm(): void
    {
        self::seed(self::OLD_ID);
        self::runClear(['manual' => '1', 'companyid' => self::OLD_ID]);
        $controller = self::runIntent(['manual_entry' => '1', 'company' => self::OLD_NAME, 'companyid' => self::OLD_ID]);

        TinyAssert::same('incomplete_company', (string) ($controller->emitted[0]['status'] ?? ''), 'the intent must not run on the refused number');
        TinyAssert::same('', (string) TwoSessionRecord::get('company', 'id'), 'the intent must not store the refused number');
    }

    /**
     * After the clear, the buyer types their own number and saves the address.
     * Each later flag-only carrier leaves that number alone and keeps the old one
     * refused, so the intent runs on the typed number and never on the old one,
     * even from a cart address still holding it.
     */
    private static function testAHandTypedNumberIsNeverRefused(): void
    {
        $cases = [
            // [dni on the cart's address, description]
            ['99887766', 'address holds the typed number: the intent runs on it'],
            [self::OLD_ID, 'address still holds the old number: the intent runs on the typed one'],
        ];
        foreach ($cases as [$dni, $description]) {
            self::seed($dni);
            self::runClear(['manual' => '1', 'companyid' => self::OLD_ID]);
            self::runAddressSave('Hand Typed Ltd', '99887766', true);
            self::runAddressSave('Hand Typed Ltd', '99887766', true);
            TinyAssert::same('99887766', (string) TwoSessionRecord::get('company', 'id'), $description . ': the typed number left the record');
            TinyAssert::same(self::OLD_ID, (string) TwoSessionRecord::get('company', 'disowned_id'), $description . ': an address save moved the refusal');

            $controller = self::runIntent(['manual_entry' => '1', 'company' => 'Hand Typed Ltd', 'companyid' => '']);

            TinyAssert::same('', (string) ($controller->emitted[0]['status'] ?? ''), $description . ': the intent was refused');
            TinyAssert::same(self::OLD_ID, (string) TwoSessionRecord::get('company', 'disowned_id'), $description . ': the intent moved the refusal');
            $sent = (string) json_encode($controller->emitted);
            TinyAssert::false(strpos($sent, self::OLD_ID) !== false, $description . ': the old number went out in the intent');
            TinyAssert::true(strpos($sent, '99887766') !== false, $description . ': the typed number did not go out');
        }
    }

    /**
     * The resolver's own clears (a record with no country marker, a country
     * change) and a plain clear request keep the refusal: only a pick lifts it.
     */
    private static function testHousekeepingClearsKeepTheRefusal(): void
    {
        $cases = [
            ['resolver drops a record with no country marker', function () {
                TwoSessionRecord::set('company', 'name', 'Hand Typed Ltd');
                TwoSessionRecord::set('company', 'id', '99887766');
                self::module()->getTwoCheckoutCompanyData(new Address(self::ADDRESS_ID));
            }],
            ['resolver drops a record for another country', function () {
                TwoSessionRecord::set('company', 'name', 'Hand Typed Ltd');
                TwoSessionRecord::set('company', 'id', '99887766');
                TwoSessionRecord::set('company', 'country', 'NO');
                self::module()->getTwoCheckoutCompanyData(new Address(self::ADDRESS_ID));
            }],
            ['plain clear request', function () {
                self::runClear([]);
            }],
        ];
        foreach ($cases as [$description, $act]) {
            self::seed(self::OLD_ID);
            self::runClear(['manual' => '1', 'companyid' => self::OLD_ID]);
            $act();

            TinyAssert::same(self::OLD_ID, (string) TwoSessionRecord::get('company', 'disowned_id'), $description . ': the refusal was dropped');
            $resolved = self::module()->getTwoCheckoutCompanyData(new Address(self::ADDRESS_ID));
            TinyAssert::same('', $resolved['organization_number'], $description . ': the old number came back from the address');
        }
    }

    /* ---- fixtures ---- */

    private static function seed(string $dni): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
        StubStore::$cartRecords = [];
        StubStore::$countries[self::COUNTRY_ID] = 'GB';
        StubStore::$currencies[self::CURRENCY_ID] = ['iso_code' => 'GBP', 'loaded' => true];
        StubStore::$addresses[self::ADDRESS_ID] = [
            'id_customer' => self::CUSTOMER_ID,
            'id_country' => self::COUNTRY_ID,
            'company' => self::OLD_NAME,
            'dni' => $dni,
            'address1' => '1 High Street',
            'city' => 'London',
            'postcode' => 'EC1A 1BB',
            'phone' => '+447000000000',
            'loaded' => true,
        ];
        StubStore::$customers[self::CUSTOMER_ID] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Sam',
            'lastname' => 'Reed',
            'secure_key' => 'secure-key-7313',
            'loaded' => true,
        ];
        StubStore::$carts[self::CART_ID] = [
            'id_customer' => self::CUSTOMER_ID,
            'id_currency' => self::CURRENCY_ID,
            'id_address_invoice' => self::ADDRESS_ID,
            'id_address_delivery' => self::ADDRESS_ID,
            'id_carrier' => 0,
            'id_lang' => 1,
        ];
        Context::getContext()->cookie = new Cookie();
        Context::getContext()->cart = new Cart(self::CART_ID);
        TwoSessionRecord::set('company', 'name', self::OLD_NAME);
        TwoSessionRecord::set('company', 'id', self::OLD_ID);
        TwoSessionRecord::set('company', 'country', 'GB');
        TwoSessionRecord::set('company', 'address_id', (string) self::ADDRESS_ID);
        // What saveCompany writes beside a pick.
        TwoSessionRecord::set('company', 'picked_id', self::OLD_ID);
        TwoSessionRecord::set('company', 'cart', (string) self::CART_ID);
    }

    private static function module(): TwopaymentTestHarness
    {
        return new TwopaymentTestHarness();
    }

    private static function runClear(array $values): void
    {
        self::runController('clearCompany', $values);
    }

    private static function runAddressSave(string $company, string $companyId, bool $manual): void
    {
        Tools::resetTestValues();
        if ($companyId !== '') {
            Tools::setTestValue('companyid', $companyId);
        }
        if ($manual) {
            Tools::setTestValue('two_manual_entry', '1');
        }
        $address = new stdClass();
        $address->company = $company;
        $address->id = self::ADDRESS_ID;
        $address->id_country = self::COUNTRY_ID;
        self::module()->hookActionCustomerAddressSave(['address' => $address]);
    }

    private static function runIntent(array $values)
    {
        return self::runController('checkOrderIntent', $values + ['id_address_invoice' => self::ADDRESS_ID], true);
    }

    private static function runController(string $action, array $values, bool $stubPayload = false)
    {
        Tools::resetTestValues();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Tools::setTestValue('ajax', 1);
        Tools::setTestValue('action', $action);
        Tools::setTestValue('token', Tools::getToken(false));
        foreach ($values as $key => $value) {
            Tools::setTestValue($key, $value);
        }

        $controller = new class extends TwopaymentOrderintentModuleFrontController {
            /** @var array<int,array> */
            public array $emitted = [];

            public function sendJsonResponse($content)
            {
                $decoded = json_decode((string) $content, true);
                $this->emitted[] = is_array($decoded) ? $decoded : ['raw' => $content];

                throw new StubOrderIntentResponded('order intent response sent');
            }
        };
        $controller->module = $stubPayload
            ? new class extends TwopaymentTestHarness {
                public function getTwoIntentOrderData($cart, $customer, $currency, $address)
                {
                    return [
                        'gross_amount' => '120.00',
                        'net_amount' => '100.00',
                        'tax_amount' => '20.00',
                        'discount_amount' => '0.00',
                        'currency' => 'GBP',
                        'buyer' => ['company' => [
                            'company_name' => (string) $address->company,
                            'country_prefix' => 'GB',
                            'organization_number' => (string) $address->companyid,
                            'website' => '',
                        ]],
                        'billing_address' => ['country' => 'GB', 'city' => 'London'],
                        'shipping_address' => ['country' => 'GB', 'city' => 'London'],
                        'line_items' => [],
                    ];
                }
            }
            : new TwopaymentTestHarness();

        try {
            $controller->postProcess();
        } catch (StubOrderIntentResponded $responded) {
        }

        return $controller;
    }
}
