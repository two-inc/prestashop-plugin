<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/payment.php';

/**
 * TWO-40 - the merchant record's buyer-country allowlist is enforced by the
 * plugin, at both the display and the submission point.
 *
 * GET /v1/merchant carries `supported_buyer_countries`: ISO-3166-1 alpha-2
 * codes the merchant may transact with. An ABSENT, null or EMPTY list means no
 * restriction - the day-one state of every existing merchant - and anything
 * else allows only those codes.
 *
 * That empty-means-unrestricted rule is the opposite of the module's other two
 * allowlists. `module_country` withholds on empty (core would refuse the
 * submission too) and the currency ISO list withholds on a non-match. Here the
 * server defines the semantics of its own field, so this gate diverges
 * deliberately, and the divergence is pinned below rather than left to be
 * "corrected" into line with its neighbours later.
 *
 * Contract pinned here:
 *
 *  - Absent / null / empty / malformed field => unrestricted. Never withheld.
 *  - Codes are compared case-insensitively; the cache holds them uppercased.
 *  - The BILLING country decides, falling back to the SHIPPING country when the
 *    billing address carries none. That resolution order is the country the
 *    order ultimately submits as the buyer's registration country, which is
 *    what the backend enforces this list against.
 *  - A fetch that never succeeded leaves the gate unrestricted (fail OPEN): an
 *    API fault is not a country verdict, and hiding Two over one would take the
 *    payment method off every shop at once.
 *  - Once the list IS restrictive, a cart with no resolvable country is
 *    withheld - it cannot be shown to be in the list.
 *  - BOTH enforcement points agree: the payment-options hook withholds the
 *    tile, and controllers/front/payment.php refuses a POST that bypassed it.
 *    A gate on only one of them is either a dead end at the last click or an
 *    open door for a stale page.
 */
final class BuyerCountryGateSpec
{
    /** ps_country id fixtures, registered into StubStore::$countries. */
    private const COUNTRY_IDS = [826 => 'GB', 47 => 'NO', 34 => 'ES', 49 => 'DE'];

    private const ID_BILLING = 904;
    private const ID_SHIPPING = 905;

    /** Address fixture meaning "this address exists but carries no country". */
    private const NO_COUNTRY = '';

    public static function runAll(): void
    {
        self::testTheFetchCachesTheAllowlist();
        self::testTheGateVerdict();
        self::testBothEnforcementPointsAgreeWithTheGate();
        self::testThePaymentOptionIsWithheldWhenNoIsoCountryResolves();
        self::testAFetchThatNeverSucceededLeavesTheGateUnrestricted();
        self::testAFailedRefetchDoesNotBlankAnEstablishedAllowlist();
        self::testAMerchantIdentityChangeDropsTheAllowlist();
        self::testTheWithholdReasonIsLoggedOncePerRequest();
    }

    /* ===================================================================
     * Harness
     * =================================================================== */

    private static function reset(): void
    {
        StubStore::reset();
        Tools::resetTestValues();
        PrestaShopLogger::reset();
        StubStore::$countries = self::COUNTRY_IDS;
    }

    private static function idForIso(string $iso): int
    {
        $id = array_search($iso, self::COUNTRY_IDS, true);
        if ($id === false) {
            throw new RuntimeException('No country id fixture for ' . $iso);
        }

        return (int) $id;
    }

    /** Write the cache the gate reads, bypassing the wire. */
    private static function cacheAllowlist(?array $isos): void
    {
        Configuration::updateValue(
            Twopayment::CONFIG_MERCHANT_BUYER_COUNTRIES,
            $isos ? json_encode($isos) : ''
        );
    }

    /**
     * A cart that clears every OTHER hookPaymentOptions gate and every
     * postProcess guard ahead of the buyer-country one, so the only thing left
     * that can refuse it is the gate under test.
     *
     * @param string|null $billing  ISO code, self::NO_COUNTRY for an address
     *   carrying no country, or null for no such address on the cart.
     * @param string|null $shipping same encoding.
     */
    private static function offerableModule(?string $billing, ?string $shipping): object
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        // Without this the payment-tile render emits an undefined-property
        // warning on every call, which is noise the next real one hides in.
        $module->_path = '/modules/twopayment/';

        StubStore::$currencies[826] = ['iso_code' => 'GBP', 'loaded' => true];
        StubStore::$moduleCurrencies['twopayment'] = [['id_currency' => 826]];
        StubStore::$customers[9001] = ['email' => 'buyer@example.com', 'loaded' => true];

        $cart = new Cart(7340);
        $cart->id_customer = 9001;
        $cart->id_currency = 826;
        $cart->id_address_invoice = self::address(self::ID_BILLING, $billing);
        $cart->id_address_delivery = self::address(self::ID_SHIPPING, $shipping);
        $module->context->cart = $cart;
        Context::getContext()->cart = $cart;

        return $module;
    }

    /** Seeds one address fixture; returns the cart's id for it (0 = none). */
    private static function address(int $idAddress, ?string $iso): int
    {
        if ($iso === null) {
            return 0;
        }

        StubStore::$addresses[$idAddress] = [
            'id_country' => $iso === self::NO_COUNTRY ? 0 : self::idForIso($iso),
            'company' => 'Example Trading Ltd',
            'vat_number' => 'GB123456789',
            'loaded' => true,
        ];

        return $idAddress;
    }

    /** Harness whose GET /v1/merchant fetch is stubbed. */
    private static function moduleWithMerchantResponse($response): object
    {
        self::reset();
        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'm-123');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');

        return new class ($response) extends TwopaymentTestHarness {
            public int $fetchCount = 0;
            private $response;

            public function __construct($response)
            {
                parent::__construct();
                $this->response = $response;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                ++$this->fetchCount;

                return $this->response;
            }
        };
    }

    private static function withholdLogLines(): int
    {
        $lines = 0;
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'supported buyer country') !== false
                || strpos($entry['message'], 'buyer country could not be resolved') !== false
            ) {
                ++$lines;
            }
        }

        return $lines;
    }

    /**
     * Whether the SUBMISSION point refused, keyed on this gate's own log line.
     * Deliberately not "did anything redirect": every guard on that path ends
     * in a redirect, and the guards after this one fire on this fixture, so a
     * redirect proves nothing about which check refused.
     */
    private static function submissionWasRefused(object $module): bool
    {
        PrestaShopLogger::reset();

        $controller = new TwopaymentPaymentModuleFrontController();
        $controller->module = $module;

        try {
            $controller->postProcess();
        } catch (Exception $e) {
            // Any guard on this path ends in a redirect the stub core raises,
            // and anything past them all reaches provider plumbing this
            // harness does not stub. The log below is what decides.
        }

        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'unsupported buyer country') !== false) {
                return true;
            }
        }

        return false;
    }

    /* ===================================================================
     * Cache population
     * =================================================================== */

    /**
     * What the shared merchant fetch caches for each shape of the field. The
     * malformed rows matter as much as the good ones: every one of them must
     * land on "unrestricted", because the alternative - reading garbage as a
     * restriction - silently removes Two for real buyers.
     */
    private static function testTheFetchCachesTheAllowlist(): void
    {
        $cases = [
            [['http_status' => 200, 'available_terms' => [30]], [], 'an absent field is unrestricted'],
            [['http_status' => 200, 'supported_buyer_countries' => null], [], 'a null field is unrestricted'],
            [['http_status' => 200, 'supported_buyer_countries' => []], [], 'an empty list is unrestricted'],
            [['http_status' => 200, 'supported_buyer_countries' => 'GB'], [], 'a bare string is not a one-country allowlist'],
            [['http_status' => 200, 'supported_buyer_countries' => ['GB']], ['GB'], 'a single code is cached'],
            [['http_status' => 200, 'supported_buyer_countries' => ['no', 'gb']], ['GB', 'NO'], 'codes are uppercased and sorted'],
            [['http_status' => 200, 'supported_buyer_countries' => [' gb ', 'GB']], ['GB'], 'codes are trimmed and de-duplicated'],
            [['http_status' => 200, 'supported_buyer_countries' => ['GB', 'GBR', '', 'X', 42, null, ['NO']]], ['GB'], 'only alpha-2 string codes survive'],
        ];

        foreach ($cases as [$response, $expected, $description]) {
            $module = self::moduleWithMerchantResponse($response);
            $module->getMerchantAvailableTerms(true);

            TinyAssert::same(
                $expected,
                $module->getMerchantBuyerCountries(),
                'fetch caching: ' . $description
            );
        }
    }

    /* ===================================================================
     * Gate
     * =================================================================== */

    /**
     * The gate's verdict for each (allowlist, billing, shipping) combination.
     * Read as: with this list cached, and these two addresses on the cart, is
     * Two allowed?
     */
    private static function verdictCases(): array
    {
        return [
            // Unrestricted: no country is ever consulted, including one that no
            // address could resolve at all.
            [null, 'GB', 'GB', true, 'no cached list allows any country'],
            [[], 'GB', 'GB', true, 'an empty cached list allows any country'],
            [null, self::NO_COUNTRY, null, true, 'an unrestricted merchant is not gated on a resolvable country'],

            // Restricted.
            [['GB', 'NO'], 'GB', 'GB', true, 'a billing country in the list is allowed'],
            [['GB', 'NO'], 'DE', 'DE', false, 'a billing country outside the list is refused'],
            [['GB'], 'ES', 'GB', false, 'an allowlisted SHIPPING country does not rescue a refused billing country'],

            // Case handling, both directions.
            [['gb'], 'GB', 'GB', true, 'a lowercase cached code still matches'],
            [['GB'], 'GB', 'GB', true, 'an uppercase cached code matches'],

            // Billing -> shipping fallback.
            [['NO'], self::NO_COUNTRY, 'NO', true, 'a billing address with no country falls back to shipping'],
            [['NO'], null, 'NO', true, 'no billing address at all falls back to shipping'],
            [['NO'], self::NO_COUNTRY, 'DE', false, 'the fallback is still matched against the list'],

            // Nothing resolvable, and the list IS restrictive.
            [['NO'], self::NO_COUNTRY, self::NO_COUNTRY, false, 'neither address carrying a country is refused'],
            [['NO'], null, null, false, 'no addresses at all is refused'],
        ];
    }

    private static function testTheGateVerdict(): void
    {
        foreach (self::verdictCases() as [$allowlist, $billing, $shipping, $expected, $description]) {
            $module = self::offerableModule($billing, $shipping);
            self::cacheAllowlist($allowlist);

            TinyAssert::same(
                $expected,
                $module->isTwoBuyerCountrySupported($module->context->cart),
                'gate verdict: ' . $description
            );
        }
    }

    /**
     * The same table driven through BOTH enforcement points. The hook alone
     * leaves a buyer holding a page rendered before the merchant record changed
     * able to POST straight past it; the controller alone offers a tile that
     * dies at the last click.
     *
     * The two points are NOT identical in what reaches them, and the difference
     * is asserted rather than skipped:
     *
     *  - At the DISPLAY point the module's pre-existing no-country guards (the
     *    invoice-address check, then TWO-25387's module_country gate) run first
     *    and already withhold the tile whenever the BILLING address carries no
     *    country. They are stricter than this gate, so any row relying on the
     *    shipping fallback is withheld there whatever this gate decides. That
     *    is existing behaviour this change does not touch.
     *  - At the SUBMISSION point there is no such guard - postProcess() defers
     *    the module_country question to core - so the shipping fallback is
     *    load-bearing there, which is why it is asserted there.
     *
     * Rows with no invoice or delivery address at all cannot reach the
     * submission gate (postProcess() refuses an incomplete cart several guards
     * earlier), so asserting them there would pass for the wrong reason.
     */
    private static function testBothEnforcementPointsAgreeWithTheGate(): void
    {
        foreach (self::verdictCases() as [$allowlist, $billing, $shipping, $expected, $description]) {
            $billingResolves = $billing !== null && $billing !== self::NO_COUNTRY;

            $module = self::offerableModule($billing, $shipping);
            self::cacheAllowlist($allowlist);

            TinyAssert::same(
                ($expected && $billingResolves) ? 1 : 0,
                count($module->hookPaymentOptions([])),
                'display point: ' . $description
            );

            if ($billing === null || $shipping === null) {
                continue;
            }

            $module = self::offerableModule($billing, $shipping);
            self::cacheAllowlist($allowlist);

            TinyAssert::same(
                !$expected,
                self::submissionWasRefused($module),
                'submission point: ' . $description
            );
        }
    }

    /**
     * A separate question from the allowlist above: not "is this country
     * permitted" but "is there a country at all".
     *
     * TILE MOUNT ONLY (PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS off), which is the
     * one configuration where the control has no country select to read: the
     * only register it can search is the one resolved by this same
     * billing-then-shipping chain and injected as `twopayment.billing_country`.
     * An unresolved country would render a tile whose search declines on every
     * keystroke, so the option is withheld instead. With the search in the
     * address area the control reads the form's own select and the payment
     * option is not the merchant's to lose.
     *
     * The fixture is an address whose country id the shop's country table does
     * not answer for - a deleted country row. That is what reaches this gate:
     * an address carrying NO country id is already refused by TWO-25387's
     * module_country check several lines earlier, and an allowlisted merchant
     * is already refused by the gate above. The merchant here is unrestricted
     * and the country ids pass module_country, so nothing else can refuse.
     */
    private static function testThePaymentOptionIsWithheldWhenNoIsoCountryResolves(): void
    {
        $module = self::offerableModule('GB', 'GB');
        self::cacheAllowlist(null);
        Configuration::updateValue('PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS', 0);
        StubStore::$addresses[self::ID_BILLING]['id_country'] = 4242;
        StubStore::$addresses[self::ID_SHIPPING]['id_country'] = 4243;

        TinyAssert::count(
            0,
            $module->hookPaymentOptions([]),
            'the tile was offered with no company register for its search to query'
        );

        // Same unresolvable cart, search back in the address area: the gate is
        // scoped to the tile mount and must not reach this configuration.
        $module = self::offerableModule('GB', 'GB');
        self::cacheAllowlist(null);
        Configuration::updateValue('PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS', 1);
        StubStore::$addresses[self::ID_BILLING]['id_country'] = 4242;
        StubStore::$addresses[self::ID_SHIPPING]['id_country'] = 4243;

        TinyAssert::count(
            1,
            $module->hookPaymentOptions([]),
            'the address-area mount lost the payment option to a tile-only gate'
        );

        // The shipping address alone is enough to keep the tile offered.
        $module = self::offerableModule('GB', 'NO');
        self::cacheAllowlist(null);
        Configuration::updateValue('PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS', 0);
        StubStore::$addresses[self::ID_BILLING]['id_country'] = 4242;

        TinyAssert::count(
            1,
            $module->hookPaymentOptions([]),
            'a resolvable shipping country did not keep the payment option offered'
        );
    }

    /* ===================================================================
     * Fail-open
     * =================================================================== */

    /**
     * The case that decides whether this feature is shippable. Every merchant
     * starts with no cached list, and a shop that cannot reach Two at all must
     * keep offering Two rather than lose the payment method to a network fault.
     * Driven through the real fetch for each failure shape, so this asserts the
     * module's own behaviour and not a pre-seeded cache.
     */
    private static function testAFetchThatNeverSucceededLeavesTheGateUnrestricted(): void
    {
        $cases = [
            [['http_status' => 500], 'a 5xx response'],
            [['http_status' => 401], 'a rejected API key'],
            [['http_status' => 0], 'a transport failure'],
            [false, 'no response body at all'],
        ];

        foreach ($cases as [$response, $description]) {
            $module = self::moduleWithMerchantResponse($response);
            $module->getMerchantAvailableTerms(true);
            TinyAssert::same(1, $module->fetchCount, 'the fetch must actually have been attempted: ' . $description);

            TinyAssert::same(
                [],
                $module->getMerchantBuyerCountries(),
                'fail open: ' . $description . ' must leave the allowlist unresolved'
            );

            // And the gate that reads it must offer Two, on a country no
            // plausible allowlist would contain.
            $module = self::offerableModule('DE', 'DE');
            TinyAssert::same(
                true,
                $module->isTwoBuyerCountrySupported($module->context->cart),
                'fail open: ' . $description . ' must not withhold the payment option'
            );
        }
    }

    /**
     * The mirror of the case above, and the reason the fail-open posture is not
     * simply "clear the cache on any doubt": a merchant WITH a restriction must
     * keep it across an API blip. Serve-stale, exactly like the sibling caches
     * fed by this fetch.
     */
    private static function testAFailedRefetchDoesNotBlankAnEstablishedAllowlist(): void
    {
        $module = self::moduleWithMerchantResponse(['http_status' => 200, 'supported_buyer_countries' => ['NO']]);
        $module->getMerchantAvailableTerms(true);
        TinyAssert::same(['NO'], $module->getMerchantBuyerCountries());

        $stale = self::moduleWithMerchantResponseKeepingCache(['http_status' => 503]);
        $stale->getMerchantAvailableTerms(true);

        TinyAssert::same(
            ['NO'],
            $stale->getMerchantBuyerCountries(),
            'a failed refetch must serve the last-known allowlist, not blank it'
        );
    }

    /**
     * As moduleWithMerchantResponse(), but without wiping the Configuration
     * store - so an already-cached allowlist survives into the new instance.
     */
    private static function moduleWithMerchantResponseKeepingCache($response): object
    {
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS, 0);

        return new class ($response) extends TwopaymentTestHarness {
            public int $fetchCount = 0;
            private $response;

            public function __construct($response)
            {
                parent::__construct();
                $this->response = $response;
            }

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                ++$this->fetchCount;

                return $this->response;
            }
        };
    }

    /**
     * Serve-stale must never outlive the identity it belongs to: a new API key
     * or merchant id means the old merchant's country restriction has to go,
     * and it must drop to unrestricted rather than to "nothing allowed".
     */
    private static function testAMerchantIdentityChangeDropsTheAllowlist(): void
    {
        $module = self::moduleWithMerchantResponse(['http_status' => 200, 'supported_buyer_countries' => ['NO']]);
        $module->getMerchantAvailableTerms(true);
        TinyAssert::same(['NO'], $module->getMerchantBuyerCountries());

        $module->invalidateMerchantAvailableTerms();

        TinyAssert::same([], $module->getMerchantBuyerCountries(), 'an identity change must drop the allowlist');
        TinyAssert::same(
            true,
            $module->isTwoBuyerCountrySupported(self::offerableModule('DE', 'DE')->context->cart),
            'the dropped allowlist must read as unrestricted, not as nothing allowed'
        );
    }

    /**
     * Core asks for payment options several times per payment-step render, and
     * a country restriction is a standing merchant setting - so every excluded
     * buyer would otherwise write several ps_log rows per render, burying the
     * next real line in the module's own repetition.
     */
    private static function testTheWithholdReasonIsLoggedOncePerRequest(): void
    {
        $module = self::offerableModule('DE', 'DE');
        self::cacheAllowlist(['GB']);
        PrestaShopLogger::reset();

        $module->hookPaymentOptions([]);
        $module->hookPaymentOptions([]);
        $module->hookPaymentOptions([]);

        TinyAssert::same(1, self::withholdLogLines(), 'the withhold reason must be logged once per request');

        $logged = '';
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'supported buyer country') !== false) {
                $logged = $entry['message'];
            }
        }
        TinyAssert::true(
            strpos($logged, 'DE') !== false,
            'the log must name the country that was refused, or it cannot be diagnosed: ' . $logged
        );
    }
}
