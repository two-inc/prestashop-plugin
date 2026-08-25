<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/orderintent.php';

/**
 * TWO-25503: keeping the company a buyer picked on a BILLING address that
 * differs from their shipping one.
 *
 * Two server-side halves of that loss, both reproduced live before being fixed.
 *
 * The stamp: the browser sends the address a selection belongs to, and sends 0
 * when that address does not exist yet - a billing address still being typed.
 * Substituting the cart's address for that 0 stamped the selection with the
 * SHIPPING address, and every address-switch guard downstream then read it as a
 * switch and threw the selection away.
 *
 * The store-back: when a guard did decline, the resolver fell through to the
 * address, which carries a company NAME and no organisation number - and the
 * handler persisted that name-only result over the stored record, destroying the
 * organisation number for the rest of the checkout.
 */
final class BillingCompanyCaptureSpec
{
    private const SHIPPING_ADDRESS_ID = 9821;

    private const BILLING_ADDRESS_ID = 9822;

    private const CART_ID = 4481;

    private const CUSTOMER_ID = 9823;

    private const CURRENCY_ID = 826;

    private const COUNTRY_ID = 17;

    public static function runAll(): void
    {
        self::testExplicitZeroAddressIsStoredAsUnknown();
        self::testMissingAddressParamStillFallsBackToTheCart();
        self::testANewSelectionOverwritesThePreviousAddressStamp();
        self::testNameOnlyResolutionDoesNotDestroyTheStoredNumber();
        self::testAResolvedNumberStillOverwritesTheStoredOne();
    }

    /**
     * The reported case: the buyer is typing a billing address that has no id
     * yet, so the selection must be stored unstamped rather than stamped with
     * the shipping address the cart still names.
     */
    private static function testExplicitZeroAddressIsStoredAsUnknown(): void
    {
        $cookie = self::runSaveCompany(['id_address' => '0']);

        TinyAssert::same(
            '0',
            (string) $cookie->two_company_address_id,
            'an explicit id_address=0 must be stored as unknown, not as the cart address'
        );
        TinyAssert::same('55667788', (string) $cookie->two_company_id);
    }

    /**
     * Older cached JS sends no `id_address` at all, and the cart fallback is
     * what that case has always relied on.
     */
    private static function testMissingAddressParamStillFallsBackToTheCart(): void
    {
        $cookie = self::runSaveCompany([]);

        TinyAssert::same(
            (string) self::SHIPPING_ADDRESS_ID,
            (string) $cookie->two_company_address_id,
            'a missing id_address must still take the cart fallback'
        );
    }

    /**
     * A new selection replaces the whole record. Leaving the key out let the
     * PREVIOUS selection's stamp survive beside a company it was never captured
     * against.
     */
    private static function testANewSelectionOverwritesThePreviousAddressStamp(): void
    {
        $cookie = self::seedSession((string) self::SHIPPING_ADDRESS_ID, '00445790');
        self::runSaveCompany(['id_address' => '0'], $cookie);

        TinyAssert::same(
            '0',
            (string) $cookie->two_company_address_id,
            'the previous selection address must not survive a new selection'
        );
    }

    private static function testNameOnlyResolutionDoesNotDestroyTheStoredNumber(): void
    {
        $cookie = self::runCheckOrderIntentAgainstADifferentBillingAddress();

        TinyAssert::same(
            '55667788',
            (string) $cookie->two_company_id,
            'a name-only resolution must leave the stored organisation number alone'
        );
    }

    /**
     * The guard is about EMPTY numbers only - a real resolved number must still
     * replace the stored one, or a buyer switching company would keep the old.
     */
    private static function testAResolvedNumberStillOverwritesTheStoredOne(): void
    {
        $cookie = self::runCheckOrderIntentAgainstADifferentBillingAddress('99887766');

        TinyAssert::same(
            '99887766',
            (string) $cookie->two_company_id,
            'a resolved organisation number must still be stored'
        );
    }

    /* ---- fixtures ---- */

    private static function seedSession(string $addressId, string $companyId): Cookie
    {
        $cookie = new Cookie();
        $cookie->two_company_name = 'Billing Trading Ltd';
        $cookie->two_company_id = $companyId;
        $cookie->two_company_country = 'GB';
        $cookie->two_company_address_id = $addressId;
        $cookie->two_company_cart_id = (string) self::CART_ID;
        Context::getContext()->cookie = $cookie;

        return $cookie;
    }

    private static function seedStore(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
        StubStore::$countries[self::COUNTRY_ID] = 'GB';
        StubStore::$currencies[self::CURRENCY_ID] = ['iso_code' => 'GBP', 'loaded' => true];
        StubStore::$customers[self::CUSTOMER_ID] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Sam',
            'lastname' => 'Reed',
            'secure_key' => 'secure-key-9823',
            'loaded' => true,
        ];
        foreach ([self::SHIPPING_ADDRESS_ID => 'Shipping Trading Ltd', self::BILLING_ADDRESS_ID => 'Billing Trading Ltd'] as $id => $company) {
            StubStore::$addresses[$id] = [
                'id_country' => self::COUNTRY_ID,
                'company' => $company,
                // The plugin never writes the organisation number onto the
                // address, which is why the address tier can only ever answer
                // with a name.
                'dni' => '',
                'address1' => '1 High Street',
                'city' => 'London',
                'postcode' => 'EC1A 1BB',
                'phone' => '+447000000000',
                'loaded' => true,
            ];
        }
    }

    private static function attachCart(int $invoiceAddressId): void
    {
        StubStore::$carts[self::CART_ID] = [
            'id_customer' => self::CUSTOMER_ID,
            'id_currency' => self::CURRENCY_ID,
            'id_address_invoice' => $invoiceAddressId,
            'id_address_delivery' => self::SHIPPING_ADDRESS_ID,
            'id_carrier' => 0,
            'id_lang' => 1,
        ];
        Context::getContext()->cart = new Cart(self::CART_ID);
    }

    /**
     * @param array<string,string> $params request values beyond the company pair
     */
    private static function runSaveCompany(array $params, ?Cookie $cookie = null): Cookie
    {
        self::seedStore();
        $cookie = $cookie ?? self::seedSession('', '');
        Context::getContext()->cookie = $cookie;
        self::attachCart(self::SHIPPING_ADDRESS_ID);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        Tools::setTestValue('ajax', 1);
        Tools::setTestValue('action', 'saveCompany');
        Tools::setTestValue('token', Tools::getToken(false));
        Tools::setTestValue('company', 'Billing Trading Ltd');
        Tools::setTestValue('companyid', '55667788');
        Tools::setTestValue('country', 'GB');
        foreach ($params as $key => $value) {
            Tools::setTestValue($key, $value);
        }

        $controller = self::makeController();
        try {
            $controller->ajaxProcessSaveCompany();
        } catch (StubOrderIntentResponded $responded) {
        }

        return $cookie;
    }

    /**
     * The live shape: a selection stamped with the shipping address while the
     * cart is now billing a different one, so the session tier is declined and
     * the address tier answers with a name and no number.
     */
    private static function runCheckOrderIntentAgainstADifferentBillingAddress(string $addressDni = ''): Cookie
    {
        self::seedStore();
        StubStore::$addresses[self::BILLING_ADDRESS_ID]['dni'] = $addressDni;
        $cookie = self::seedSession((string) self::SHIPPING_ADDRESS_ID, '55667788');
        self::attachCart(self::BILLING_ADDRESS_ID);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        Tools::setTestValue('ajax', 1);
        Tools::setTestValue('action', 'checkOrderIntent');
        Tools::setTestValue('token', Tools::getToken(false));
        Tools::setTestValue('id_address_invoice', self::BILLING_ADDRESS_ID);

        $controller = self::makeController();
        try {
            $controller->ajaxProcessCheckOrderIntent();
        } catch (StubOrderIntentResponded $responded) {
        }

        return $cookie;
    }

    private static function makeController()
    {
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
        $controller->module = new class extends TwopaymentTestHarness {
            /** Keeps the payload build off the network - the cookie is what is under test. */
            public function getTwoIntentOrderData($cart, $customer, $currency, $address)
            {
                return [
                    'gross_amount' => '120.00',
                    'net_amount' => '100.00',
                    'tax_amount' => '20.00',
                    'discount_amount' => '0.00',
                    'currency' => 'GBP',
                    'invoice_type' => 'FUNDED_INVOICE',
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
        };

        return $controller;
    }
}
