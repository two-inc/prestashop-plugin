<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/orderintent.php';

/**
 * TWO-40: what the sole-trader token mint is allowed to run on.
 *
 * The endpoint used to require an invoice address on the cart. On the checkout
 * address-editor page - the one page the "I'm a sole trader" entry is actually
 * clicked from - the cart usually has none, so every attempt was refused, and
 * the browser had nowhere on that page to render the refusal: the entry point
 * dead-ended in silence.
 *
 * The precondition is now a trust-ordered country resolver, and the property
 * these specs pin is that the ONE authorisation gate survived it: minting still
 * requires TwoSoleTrader::isAvailable() to answer yes, server-side, for a
 * country that actually resolved. A posted country can only move the answer from
 * "unresolved" to "the registry's own answer for a real country" - it cannot
 * outrank the cart's invoice address, and it cannot conjure availability where
 * the registry says no.
 *
 * Behavioural, and driven through the controller's own action switch rather than
 * by reading the source - for the reason recorded on SessionCompanyClearSpec:
 * an early `return` above the work leaves every source literal in place and a
 * grep-shaped spec green. Here the equivalent would be worse, since the thing
 * under test is a REMOVED guard: a spec asserting the absence of a string
 * passes the moment the string is reworded, whether or not anything mints.
 */
final class SoleTraderTokenPreconditionSpec
{
    private const INVOICE_ADDRESS_ID = 4101;
    private const DELIVERY_ADDRESS_ID = 4102;
    private const CART_ID = 991;

    /** Countries the stub country table knows, and what the registry says. */
    private const ISO_GB = 'GB';
    private const ISO_NO = 'NO';
    /** In the country table, absent from the registry's supported list. */
    private const ISO_DE = 'DE';

    public static function runAll(): void
    {
        $tests = [
            'testInvoiceAddressWinsOverAPostedCountry',
            'testNoInvoiceAddressMintsFromAValidPostedCountry',
            'testGarbagePostedCountryFallsBackToTheDeliveryAddress',
            'testAbsentPostedCountryFallsBackToTheDeliveryAddress',
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
        // A fresh session: TwoSoleTrader caches the registry's answer in the
        // cookie (single slot, per country), and Context is a singleton here.
        Context::getContext()->cookie = new Cookie();
        StubStore::$countries = [
            10 => self::ISO_GB,
            11 => self::ISO_NO,
            12 => self::ISO_DE,
        ];
    }

    /* ---- tier 1: the cart's invoice address ---- */

    /**
     * The committed value on the cart outranks anything the request carries.
     * Asserted with a posted country that is ALSO eligible, so the pass cannot
     * come from the posted one being rejected - only from the resolved country
     * being the invoice address's.
     */
    private static function testInvoiceAddressWinsOverAPostedCountry(): void
    {
        self::seedCart(self::ISO_NO, null);

        $emitted = self::mint(['country' => self::ISO_GB]);

        TinyAssert::true($emitted['success'], 'an eligible invoice-address country must mint');
        TinyAssert::same(
            self::ISO_NO,
            $emitted['country'],
            'the posted country overrode the cart\'s own invoice address'
        );
    }

    /* ---- tier 2: the posted country ---- */

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

    /* ---- tier 3: the cart's delivery address ---- */

    /**
     * A POST carrying junk where the country should be resolves from the cart's
     * delivery address rather than refusing - and the junk itself never reaches
     * the gate. Every shape the alpha-2 check has to reject.
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

    /* ---- fail-closed ---- */

    /**
     * No invoice address, no usable posted country, no delivery address. The
     * refusal is country-shaped now, and it must still be a refusal: the
     * registry gate cannot be evaluated against nothing, so defaulting a
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

    /**
     * The gate itself, on the tier-1 path: a country that resolves fine but
     * that the registry does not support sole traders in.
     */
    private static function testResolvedButIneligibleCountryIsRefused(): void
    {
        self::seedCart(self::ISO_DE, null);

        $emitted = self::mint([]);

        TinyAssert::false($emitted['success'], 'an ineligible country must not mint');
        TinyAssert::false(isset($emitted['delegation_token']));
    }

    /**
     * The load-bearing security case for the new tier: a posted country is
     * still gated. If the registry says no for it, no tokens - so the widened
     * precondition cannot be used to mint where the flow is unavailable.
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

    /* ---- the unchanged request guards ---- */

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

    /* ---- fixtures ---- */

    /**
     * A cart in the context, with or without each address. `null` means the
     * cart carries no such address at all - which is the address-editor state
     * for the invoice one.
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

    /**
     * A module whose Two API surface answers the registry's supported-types
     * lookup per country: sole traders in GB and NO, business-only in DE.
     */
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
