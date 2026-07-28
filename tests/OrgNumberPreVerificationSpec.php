<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/orderintent.php';

/**
 * TWO-25206: the plugin no longer pre-verifies an organization number found on
 * the buyer's saved address.
 *
 * It used to call GET /companies/v2/company?q=<orgnum> on the buyer-blocking
 * order-intent path and block checkout when that lookup came back empty. The
 * call was redundant - Two validates the org number's format and resolves it
 * against the company registry on the very same /v1/order_intent request, and
 * overwrites the company name from the registry, so anything resolved locally
 * was discarded - and it was worse than redundant: it used the FUZZY search
 * endpoint while order intent uses the exact by-org-number one, so a company
 * Two resolves fine could fail the pre-check and hard-block the buyer; a slow
 * provider was indistinguishable from "no such company"; and a lone fuzzy hit
 * was accepted even when its org number differed from the one searched.
 *
 * Contract under test:
 *  - An org number on the address is handed to Two AS-IS, with no local lookup.
 *  - The only company identity that can end up cached for the buyer is the one
 *    their own address carries - the old lookup could cache a DIFFERENT company
 *    when the fuzzy search returned a single non-matching hit.
 *  - An org number with no company name beside it is a complete identity and
 *    must not be blocked - Two supplies the name.
 *  - The two existing prompts are unchanged for the cases that still warrant
 *    them: a company name with no org number, and neither.
 *  - The removed helpers stay removed (a direct guard against reverting this).
 */
final class OrgNumberPreVerificationSpec
{
    private const CART_ID = 9701;
    private const CUSTOMER_ID = 9702;
    private const ADDRESS_ID = 9703;
    private const ES_COUNTRY_ID = 724;

    private const SEARCH_PROMPT = 'To pay with Two, go back to your billing address and search for your ' .
        'company name. Select your company from the results to verify your business.';
    private const NAME_PROMPT = 'To pay with Two, go back to your billing address and enter your company ' .
        'name in the Company field.';

    public static function runAll(): void
    {
        self::testAddressOrgNumberIsSentUnverified();
        self::testAddressOrgNumberWithoutCompanyNameIsNotBlocked();
        self::testOnlyTheAddressOwnCompanyCanBeCached();
        self::testCompanyNameWithoutOrgNumberStillPromptsForSearch();
        self::testNoCompanyDataAtAllStillPromptsForCompanyName();
        self::testCompanySearchSelectionStillShortCircuits();
        self::testPreVerificationHelpersAreGone();
    }

    /**
     * The removal itself. Reverting any part of it - the helper, its memo
     * cookie, or the caller - puts these methods back on the module.
     */
    private static function testPreVerificationHelpersAreGone(): void
    {
        foreach (
            [
                'verifyCompanyByOrgNumber',
                'getTwoVerifiedCompanyForOrgNumber',
                'buildTwoCompanyVerifyCacheKey',
                'hasTwoCompanyVerifyMiss',
                'recordTwoCompanyVerifyMiss',
                'clearTwoCompanyVerifyMiss',
                'extractOrganizationNumber',
            ] as $method
        ) {
            TinyAssert::false(
                method_exists('Twopayment', $method),
                'Twopayment::' . $method . '() is the removed org-number pre-verification (TWO-25206) ' .
                'and must not come back - Two verifies the org number on the order-intent request'
            );
        }

        TinyAssert::false(
            defined('Twopayment::COMPANY_VERIFY_MISS_CACHE_TTL'),
            'the pre-verification miss-cache TTL existed only to serve the removed lookup'
        );

        // extractOrgNumberFromAddress() is deliberately NOT removed: it is how a
        // saved-address org number reaches Two, on this path and on the payload
        // path (Twopayment::getCompanyDataWithFallbacks()).
        TinyAssert::true(
            method_exists('Twopayment', 'extractOrgNumberFromAddress'),
            'the address org-number extraction is still the source of the org number'
        );
    }

    /**
     * A saved address carrying an org number resolves without any company
     * lookup, and the org number reaches the payload exactly as stored.
     */
    private static function testAddressOrgNumberIsSentUnverified(): void
    {
        $controller = self::makeController(['company' => 'Tienda Ejemplo SL', 'dni' => 'B12345678']);

        self::runHandler($controller);

        self::assertPayloadWasBuilt($controller, 'a resolvable address must not be answered with a prompt');
        TinyAssert::same('B12345678', $controller->module->seenCompanyId);
        TinyAssert::same('Tienda Ejemplo SL', $controller->module->seenCompanyName);
        TinyAssert::same(0, $controller->module->outboundCalls, 'the handler must reach no Two endpoint on this path');
    }

    /**
     * The regression the old gate would have introduced. Two resolves the
     * company NAME from the registry and overwrites whatever the plugin sent,
     * so an org number on its own is a usable identity. Blocking it here would
     * strand a buyer whose saved address has a fiscal number but no company.
     */
    private static function testAddressOrgNumberWithoutCompanyNameIsNotBlocked(): void
    {
        $controller = self::makeController(['company' => '', 'dni' => 'B12345678']);

        self::runHandler($controller);

        self::assertPayloadWasBuilt(
            $controller,
            'an org number with no local company name must reach Two, not a prompt'
        );
        TinyAssert::same('B12345678', $controller->module->seenCompanyId);
        TinyAssert::same('', $controller->module->seenCompanyName);
    }

    /**
     * The old pre-check's worst defect: when the fuzzy search returned no exact
     * match but exactly one result, it returned THAT company - a different
     * organization number from the one searched - and the caller cached it in
     * the two_company_* session cookie as the buyer's verified identity. With no
     * lookup left, the only company identity that can be cached is the one the
     * buyer's own address carries.
     */
    private static function testOnlyTheAddressOwnCompanyCanBeCached(): void
    {
        $controller = self::makeController(['company' => 'Tienda Ejemplo SL', 'dni' => 'B12345678']);

        self::runHandler($controller);

        $cookie = Context::getContext()->cookie;
        TinyAssert::same(
            'B12345678',
            (string) ($cookie->two_company_id ?? ''),
            'no org number other than the address\'s own may be cached for this buyer'
        );
        TinyAssert::same(
            'Tienda Ejemplo SL',
            (string) ($cookie->two_company_name ?? ''),
            'no company name other than the address\'s own may be cached for this buyer'
        );
        TinyAssert::same('ES', (string) ($cookie->two_company_country ?? ''));
    }

    /**
     * Unchanged prompt #1: the address names a company but no field holds an org
     * number, so there is nothing for Two to resolve. This branch never made an
     * HTTP call and still does not.
     */
    private static function testCompanyNameWithoutOrgNumberStillPromptsForSearch(): void
    {
        $controller = self::makeController(['company' => 'Tienda Ejemplo SL', 'dni' => '']);

        self::runHandler($controller);

        TinyAssert::count(1, $controller->emitted);
        TinyAssert::same('incomplete_company', $controller->emitted[0]['status']);
        TinyAssert::same(self::SEARCH_PROMPT, $controller->emitted[0]['error']);
        TinyAssert::same(0, $controller->module->outboundCalls, 'this branch must stay HTTP-free');
    }

    /** Unchanged prompt #2: no company identity of any kind on the address. */
    private static function testNoCompanyDataAtAllStillPromptsForCompanyName(): void
    {
        $controller = self::makeController(['company' => '', 'dni' => '']);

        self::runHandler($controller);

        TinyAssert::count(1, $controller->emitted);
        TinyAssert::same('no_company', $controller->emitted[0]['status']);
        TinyAssert::same(self::NAME_PROMPT, $controller->emitted[0]['error']);
    }

    /**
     * The company-search path is untouched: a buyer who picked a company posts
     * company + companyid and short-circuits before the address is consulted.
     */
    private static function testCompanySearchSelectionStillShortCircuits(): void
    {
        $controller = self::makeController(['company' => '', 'dni' => '']);
        Tools::setTestValue('company', 'Tienda Ejemplo SL');
        Tools::setTestValue('companyid', 'B87654321');

        self::runHandler($controller);

        self::assertPayloadWasBuilt($controller, 'a company selected from the search must not be re-prompted');
        TinyAssert::same('B87654321', $controller->module->seenCompanyId);
        TinyAssert::same('Tienda Ejemplo SL', $controller->module->seenCompanyName);
    }

    /* ---- fixtures ---- */

    /**
     * The handler answered with an order-intent payload rather than one of the
     * company prompts.
     */
    private static function assertPayloadWasBuilt($controller, string $message): void
    {
        TinyAssert::count(1, $controller->emitted, $message);
        TinyAssert::true($controller->emitted[0]['success'], $message);
        TinyAssert::false(
            isset($controller->emitted[0]['status']),
            $message . ' (a company prompt carries a status, a payload does not)'
        );
        TinyAssert::true(
            isset($controller->emitted[0]['payload']['buyer']['company']['organization_number']),
            $message
        );
    }

    private static function runHandler($controller): void
    {
        try {
            $controller->ajaxProcessCheckOrderIntent();
        } catch (StubOrderIntentResponded $responded) {
            // Stands in for the production exit.
        }

    }

    /**
     * @param array{company:string,dni:string} $addressFields
     */
    private static function makeController(array $addressFields)
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
        Context::getContext()->cookie = new Cookie();
        Tools::setTestValue('token', Tools::getToken(false));
        Tools::setTestValue('id_address_invoice', self::ADDRESS_ID);
        // The handler only serves POSTs.
        $_SERVER['REQUEST_METHOD'] = 'POST';

        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        StubStore::$countries[self::ES_COUNTRY_ID] = 'ES';
        StubStore::$addresses[self::ADDRESS_ID] = [
            'id_country' => self::ES_COUNTRY_ID,
            'company' => $addressFields['company'],
            'dni' => $addressFields['dni'],
            'address1' => 'Calle Mayor 1',
            'city' => 'Madrid',
            'postcode' => '28001',
            'phone' => '+34600000000',
            'loaded' => true,
        ];
        StubStore::$customers[self::CUSTOMER_ID] = [
            'email' => 'buyer@example.com',
            'firstname' => 'Ana',
            'lastname' => 'Ruiz',
            'secure_key' => 'secure-key-9702',
            'loaded' => true,
        ];
        StubStore::$carts[self::CART_ID] = [
            'id_customer' => self::CUSTOMER_ID,
            'id_currency' => 978,
            'id_address_invoice' => self::ADDRESS_ID,
            'id_address_delivery' => self::ADDRESS_ID,
            'id_carrier' => 0,
            'id_lang' => 1,
        ];

        Context::getContext()->cart = new Cart(self::CART_ID);

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
        $controller->module = self::makeModule();

        return $controller;
    }

    /**
     * Module double that counts company lookups instead of making them and
     * records the company identity the payload build was handed.
     */
    private static function makeModule()
    {
        return new class extends TwopaymentTestHarness {
            public int $outboundCalls = 0;
            public string $seenCompanyId = '';
            public string $seenCompanyName = '';

            public function getTwoIntentOrderData($cart, $customer, $currency, $address)
            {
                $this->seenCompanyId = (string) $address->companyid;
                $this->seenCompanyName = (string) $address->company;

                return [
                    'gross_amount' => '121.00',
                    'net_amount' => '100.00',
                    'tax_amount' => '21.00',
                    'discount_amount' => '0.00',
                    'currency' => 'EUR',
                    'invoice_type' => 'FUNDED_INVOICE',
                    'buyer' => [
                        'company' => [
                            'company_name' => $this->seenCompanyName,
                            'country_prefix' => 'ES',
                            'organization_number' => $this->seenCompanyId,
                            'website' => '',
                        ],
                    ],
                    'billing_address' => ['country' => 'ES', 'city' => 'Madrid'],
                    'shipping_address' => ['country' => 'ES', 'city' => 'Madrid'],
                    'line_items' => [],
                ];
            }

            /**
             * Tripwire. Every outbound call the module makes builds its URL from
             * this, including the removed /companies/v2/company pre-verification.
             * Nothing on the order-intent controller path is supposed to reach
             * the network, so asking for the host at all is the regression.
             */
            public function getTwoCheckoutHostUrl()
            {
                ++$this->outboundCalls;

                throw new RuntimeException(
                    'The order-intent handler must make no outbound call (TWO-25206)'
                );
            }
        };
    }
}

if (!class_exists('StubOrderIntentResponded')) {
    /**
     * Deliberately an Error, not an Exception: the handler wraps its payload
     * build in catch (Exception), so an Exception here would be swallowed and
     * re-emitted as a build failure instead of standing in for the exit.
     */
    class StubOrderIntentResponded extends Error
    {
    }
}
