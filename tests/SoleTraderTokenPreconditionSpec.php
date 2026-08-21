<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/orderintent.php';

/**
 * TWO-40: what the sole-trader token mint is allowed to run on.
 *
 * The precondition is a country resolver that prefers what the request POSTS -
 * the buyer's live in-page selection - and falls back to the cart's delivery
 * address only when no usable country was posted. The cart's INVOICE address is
 * not consulted at any tier. Minting still requires TwoSoleTrader::isAvailable()
 * to answer yes, server-side, for a country that actually resolved.
 *
 * Driven through the controller's own action switch rather than by reading the
 * source: the thing under test is a removed guard, and a spec asserting the
 * absence of a string passes the moment the string is reworded.
 */
final class SoleTraderTokenPreconditionSpec
{
    private const INVOICE_ADDRESS_ID = 4101;
    private const DELIVERY_ADDRESS_ID = 4102;
    private const CART_ID = 991;

    private const ISO_GB = 'GB';
    private const ISO_NO = 'NO';
    /** In the country table, absent from the registry's supported list. */
    private const ISO_DE = 'DE';

    public static function runAll(): void
    {
        $tests = [
            'testPostedCountryWinsOverTheCartsInvoiceAddress',
            'testACommittedInvoiceAddressIsNeverConsulted',
            'testNoInvoiceAddressMintsFromAValidPostedCountry',
            'testLowercasePostedCountryIsAccepted',
            'testGarbagePostedCountryFallsBackToTheDeliveryAddress',
            'testAbsentPostedCountryFallsBackToTheDeliveryAddress',
            'testAbsentPostedCountryUsesTheDeliveryAddressNotTheInvoiceOne',
            'testUnresolvableDeliveryAddressIsRefused',
            'testDeliveryAddressThatNoLongerLoadsIsRefused',
            'testNothingResolvableIsRefused',
            'testResolvedButIneligibleCountryIsRefused',
            'testPostedCountryCannotConjureAvailability',
            'testInvalidAjaxTokenIsRefused',
            'testGetIsRefused',
        ];
        foreach ($tests as $test) {
            self::reset();
            self::$test();
            print("PASS SoleTraderTokenPreconditionSpec::$test\n");
        }
    }

    private static function reset(): void
    {
        StubStore::reset();
        TwoSoleTrader::resetCache();
        PrestaShopLogger::reset();
        // TwoSoleTrader caches the registry's answer in the cookie (single slot,
        // per country), and Context is a singleton here.
        Context::getContext()->cookie = new Cookie();
        StubStore::$countries = [
            10 => self::ISO_GB,
            11 => self::ISO_NO,
            12 => self::ISO_DE,
        ];
    }

    /* ---- tier 1: the posted country, i.e. the buyer's live selection ---- */

    /**
     * Both countries are eligible, so the pass cannot come from either one being
     * rejected - only from the posted one being the country resolved.
     */
    private static function testPostedCountryWinsOverTheCartsInvoiceAddress(): void
    {
        self::seedCart(self::ISO_NO, null);

        $emitted = self::mint(['country' => self::ISO_GB]);

        TinyAssert::true($emitted['success'], 'an eligible posted country must mint');
        TinyAssert::same(
            self::ISO_GB,
            $emitted['country'],
            'the cart\'s committed invoice address overrode the posted country'
        );
    }

    /**
     * A cart with an eligible invoice address, nothing posted and no delivery
     * address must be REFUSED - the case where a merely demoted invoice tier
     * would silently win.
     */
    private static function testACommittedInvoiceAddressIsNeverConsulted(): void
    {
        self::seedCart(self::ISO_GB, null);

        $emitted = self::mint([]);

        TinyAssert::false(
            $emitted['success'],
            'the cart\'s invoice address is still being consulted as a fallback tier'
        );
        TinyAssert::false(isset($emitted['delegation_token']));
    }

    /**
     * The bug this ticket exists for: the address-editor page, no invoice
     * address on the cart, the buyer's currently selected country posted.
     */
    private static function testNoInvoiceAddressMintsFromAValidPostedCountry(): void
    {
        self::seedCart(null, null);

        $emitted = self::mint(['country' => self::ISO_GB]);

        TinyAssert::true(
            $emitted['success'],
            'a cart with no invoice address must still be able to start the sole-trader flow'
        );
        TinyAssert::same(self::ISO_GB, $emitted['country']);
        TinyAssert::same('del-token', $emitted['delegation_token']);
        TinyAssert::same('autofill-token', $emitted['autofill_token']);
    }

    /**
     * The alpha-2 shape check only accepts upper case, so a lower-case selection
     * - which some themes' country markup carries - would fall through and
     * refuse on a cart with no delivery address.
     */
    private static function testLowercasePostedCountryIsAccepted(): void
    {
        self::seedCart(null, null);

        $emitted = self::mint(['country' => 'gb']);

        TinyAssert::true($emitted['success'], 'a lower-case posted country must still resolve');
        TinyAssert::same(self::ISO_GB, $emitted['country'], 'the resolved country must be normalised');
    }

    /* ---- tier 2: the cart's delivery address, last resort ---- */

    /**
     * Junk falls back to the delivery address rather than refusing, and never
     * reaches the gate. Every shape the alpha-2 check has to reject.
     */
    private static function testGarbagePostedCountryFallsBackToTheDeliveryAddress(): void
    {
        foreach (['GBR', 'g', '1', 'G1', '  ', '<b>', 'gb-'] as $garbage) {
            self::reset();
            self::seedCart(null, self::ISO_NO);

            $emitted = self::mint(['country' => $garbage]);

            TinyAssert::true(
                $emitted['success'],
                'a posted country of "' . $garbage . '" must fall through to the delivery address, not refuse'
            );
            TinyAssert::same(
                self::ISO_NO,
                $emitted['country'],
                'a posted country of "' . $garbage . '" reached the gate'
            );
        }
    }

    private static function testAbsentPostedCountryFallsBackToTheDeliveryAddress(): void
    {
        self::seedCart(null, self::ISO_GB);

        $emitted = self::mint([]);

        TinyAssert::true($emitted['success']);
        TinyAssert::same(self::ISO_GB, $emitted['country']);
    }

    /**
     * The invoice address is an eligible country too, so a wrong resolution
     * would still mint and only the reported country gives it away.
     */
    private static function testAbsentPostedCountryUsesTheDeliveryAddressNotTheInvoiceOne(): void
    {
        self::seedCart(self::ISO_NO, self::ISO_GB);

        $emitted = self::mint([]);

        TinyAssert::true($emitted['success'], 'the delivery address must answer when nothing was posted');
        TinyAssert::same(
            self::ISO_GB,
            $emitted['country'],
            'the cart\'s invoice address answered ahead of its delivery address'
        );
    }

    /**
     * An address that loads but whose id_country is not in the country table. No
     * country resolves, and with no tier below it that is a refusal, not a guess.
     */
    private static function testUnresolvableDeliveryAddressIsRefused(): void
    {
        self::seedCart(null, null);
        $cart = Context::getContext()->cart;
        // Loads as an object, but its country is not in the country table.
        StubStore::$addresses[self::DELIVERY_ADDRESS_ID] = ['id_country' => 4199];
        $cart->id_address_delivery = self::DELIVERY_ADDRESS_ID;

        $emitted = self::mint([]);

        TinyAssert::false($emitted['success'], 'an address resolving to no country must not mint');
        TinyAssert::false(isset($emitted['delegation_token']));
    }

    /**
     * An `id_address_delivery` pointing at a row that no longer loads. Pins the
     * `Validate::isLoadedObject()` guard in addressCountryIso(), whose deletion
     * leaves every other case here green.
     */
    private static function testDeliveryAddressThatNoLongerLoadsIsRefused(): void
    {
        self::seedCart(null, null);
        $cart = Context::getContext()->cart;
        // Deliberately never seeded into StubStore::$addresses, so the address
        // object comes back unloaded.
        $cart->id_address_delivery = 4198;

        $emitted = self::mint([]);

        TinyAssert::false($emitted['success'], 'an address that does not load must not mint');
        TinyAssert::false(isset($emitted['delegation_token']));
    }

    /* ---- fail-closed ---- */

    /**
     * The registry gate cannot be evaluated against nothing, so defaulting a
     * country here would be exactly the token oracle the gate exists to stop.
     */
    private static function testNothingResolvableIsRefused(): void
    {
        self::seedCart(null, null);

        $emitted = self::mint(['country' => 'nonsense']);

        TinyAssert::false($emitted['success'], 'an unresolvable country must not mint');
        TinyAssert::false(
            isset($emitted['delegation_token']),
            'a refusal must not hand the browser a token'
        );
        TinyAssert::false(
            isset($emitted['autofill_token']),
            'a refusal must not hand the browser a token'
        );
    }

    private static function testResolvedButIneligibleCountryIsRefused(): void
    {
        self::seedCart(null, self::ISO_DE);

        $emitted = self::mint([]);

        TinyAssert::false($emitted['success'], 'an ineligible country must not mint');
        TinyAssert::false(isset($emitted['delegation_token']));
    }

    /**
     * The preferred tier is still gated: the posted country cannot be used to
     * mint where the registry says the flow is unavailable.
     */
    private static function testPostedCountryCannotConjureAvailability(): void
    {
        self::seedCart(null, null);

        $emitted = self::mint(['country' => self::ISO_DE]);

        TinyAssert::false(
            $emitted['success'],
            'a posted country must still be checked against the registry, server-side'
        );
        TinyAssert::false(isset($emitted['autofill_token']));
    }

    private static function testInvalidAjaxTokenIsRefused(): void
    {
        self::seedCart(null, null);

        $emitted = self::mint(['country' => self::ISO_GB], 'not-the-token');

        TinyAssert::false($emitted['success'], 'an unauthenticated caller must not mint');
        TinyAssert::false(isset($emitted['delegation_token']));
    }

    private static function testGetIsRefused(): void
    {
        self::seedCart(null, null);

        $emitted = self::mint(['country' => self::ISO_GB], 'token', 'GET');

        TinyAssert::false($emitted['success'], 'the mint must be POST-only');
        TinyAssert::false(isset($emitted['delegation_token']));
    }

    /**
     * `null` means the cart carries no such address at all - the address-editor
     * state for the invoice one.
     *
     * @param string|null $invoiceIso
     * @param string|null $deliveryIso
     */
    private static function seedCart($invoiceIso, $deliveryIso): void
    {
        $cart = new Cart();
        $cart->id = self::CART_ID;

        if ($invoiceIso !== null) {
            StubStore::$addresses[self::INVOICE_ADDRESS_ID] = [
                'id_country' => self::countryId($invoiceIso),
            ];
            $cart->id_address_invoice = self::INVOICE_ADDRESS_ID;
        }
        if ($deliveryIso !== null) {
            StubStore::$addresses[self::DELIVERY_ADDRESS_ID] = [
                'id_country' => self::countryId($deliveryIso),
            ];
            $cart->id_address_delivery = self::DELIVERY_ADDRESS_ID;
        }

        Context::getContext()->cart = $cart;
    }

    private static function countryId(string $iso): int
    {
        $id = array_search($iso, StubStore::$countries, true);
        if ($id === false) {
            throw new RuntimeException('fixture: unknown country ' . $iso);
        }

        return (int) $id;
    }

    /**
     * Drive the soleTraderTokens action through postProcess() and the
     * controller's own action switch, with the registry answering GB and NO
     * (but not DE) and both token mints succeeding.
     *
     * @param array<string,string> $post
     *
     * @return array<string,mixed> the JSON the endpoint answered with
     */
    private static function mint(array $post, string $token = 'token', string $method = 'POST'): array
    {
        Tools::resetTestValues();
        Tools::setTestValue('ajax', 1);
        Tools::setTestValue('action', 'soleTraderTokens');
        if ($token !== '') {
            Tools::setTestValue('token', $token);
        }
        foreach ($post as $key => $value) {
            Tools::setTestValue($key, $value);
        }
        $_SERVER['REQUEST_METHOD'] = $method;

        TwoSoleTrader::$transport = function ($endpoint, $payload) {
            return [
                'status' => 200,
                'headers' => [
                    'two-delegated-authority-token' => $endpoint === '/registry/v1/delegation'
                        ? 'del-token'
                        : 'autofill-token',
                ],
            ];
        };

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
        $controller->module = self::registryHarness();

        try {
            $controller->postProcess();
        } catch (StubOrderIntentResponded $responded) {
            // Stands in for the production exit.
        }

        TinyAssert::count(1, $controller->emitted, 'the mint action must answer the caller exactly once');

        return $controller->emitted[0];
    }

    private static function registryHarness(): TwoSoleTraderTestHarness
    {
        $module = new TwoSoleTraderTestHarness();
        $module->cannedResponses = [
            '/registry/v1/supported-company-types/' . self::ISO_GB => [
                'http_status' => 200,
                'supported_company_types' => ['SOLE_TRADER'],
            ],
            '/registry/v1/supported-company-types/' . self::ISO_NO => [
                'http_status' => 200,
                'supported_company_types' => ['SOLE_TRADER'],
            ],
            '/registry/v1/supported-company-types/' . self::ISO_DE => [
                'http_status' => 200,
                'supported_company_types' => [],
            ],
        ];

        return $module;
    }
}
