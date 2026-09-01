<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/payment.php';

/**
 * TWO-40 - the merchant record's buyer-country allowlist is enforced by the
 * plugin, at both the display and the submission point.
 *
 * GET /v1/merchant carries `supported_buyer_countries`: ISO-3166-1 alpha-2
 * codes the merchant may transact with. The gate is TRI-STATE, and the state
 * that matters is whether the merchant record carried the FIELD, not whether it
 * carried any codes:
 *
 *  - Field ABSENT from the response body => unrestricted. This is the only
 *    fail-open state, and it means one thing: the backend serving this shop
 *    does not publish the field yet, so there is no restriction to enforce.
 *  - Field PRESENT and null, or an empty list => nothing is allowed. The
 *    backend has answered, and its answer permits no buyer country.
 *  - Field PRESENT with codes => only those codes are allowed.
 *  - Field PRESENT but not a list at all => nothing is allowed, and the state
 *    is logged as malformed so the two cases are told apart in the shop log.
 *
 * Absent and present-but-empty are therefore opposite verdicts, which is why
 * the cache encoding distinguishes them (see CACHE_* below) instead of
 * collapsing both to "no codes".
 *
 * Contract pinned here:
 *
 *  - Codes are compared case-insensitively and trimmed; the cache holds them
 *    uppercased.
 *  - The BILLING country decides, falling back to the SHIPPING country when the
 *    billing address carries none. That resolution order is the country the
 *    order ultimately submits as the buyer's registration country, which is
 *    what the backend enforces this list against.
 *  - A fetch that never succeeded leaves the gate unrestricted (fail OPEN): an
 *    API fault is not a country verdict, and hiding Two over one would take the
 *    payment method off every shop at once.
 *  - Once the field IS present, a cart with no resolvable country is withheld -
 *    it cannot be shown to be in the list.
 *  - EVERY enforcement point agrees: the payment-options hook withholds the
 *    tile, controllers/front/payment.php refuses a POST that bypassed it, and
 *    both order-intent paths refuse too. A gate on only one of them is either a
 *    dead end at the last click or an open door for a stale page.
 *  - Every refusal of an order or an intent is logged at warning severity,
 *    naming the merchant, the buyer country and which state refused.
 */
final class BuyerCountryGateSpec
{
    /** ps_country id fixtures, registered into StubStore::$countries. */
    private const COUNTRY_IDS = [826 => 'GB', 47 => 'NO', 34 => 'ES', 49 => 'DE'];

    private const ID_BILLING = 904;
    private const ID_SHIPPING = 905;

    /** Address fixture meaning "this address exists but carries no country". */
    private const NO_COUNTRY = '';

    /** Cache values for the three non-allowlist states. */
    private const CACHE_UNFETCHED = '';
    private const CACHE_ABSENT = 'null';
    private const CACHE_EMPTY = '[]';
    private const CACHE_MALFORMED = 'false';

    public static function runAll(): void
    {
        self::testTheFetchCachesTheAllowlist();
        self::testTheCacheRoundTripPreservesTheState();
        self::testPresenceIsDetectedWithoutTheRawBody();
        self::testTheGateVerdict();
        self::testARefusedSubmissionLogsAWarning();
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
    private static function cacheRaw(string $json): void
    {
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_BUYER_COUNTRIES, $json);
    }

    /** The shape setTwoPaymentRequest() returns: raw body, flattened onto the root. */
    private static function merchantResponse(array $body, int $status = 200): array
    {
        return array_merge(['http_status' => $status, 'data' => $body], $body);
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
            if (strpos($entry['message'], 'not a supported buyer country') !== false) {
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
     * What the shared merchant fetch caches for each shape of the field, as the
     * accessor's tri-state value, the state token that drives the log line, and
     * the raw cache string. The raw string is asserted because it is what has to
     * survive a Configuration round trip for absent and present-empty to stay
     * distinguishable on the next request.
     */
    private static function fetchCases(): array
    {
        return [
            [['available_terms' => [30]], null, 'absent', self::CACHE_ABSENT, 'an absent field is unrestricted'],
            [['supported_buyer_countries' => null], [], 'empty', self::CACHE_EMPTY, 'a present null field permits no country'],
            [['supported_buyer_countries' => []], [], 'empty', self::CACHE_EMPTY, 'a present empty list permits no country'],
            [['supported_buyer_countries' => 'GB'], [], 'malformed', self::CACHE_MALFORMED, 'a bare string permits no country rather than becoming a one-country allowlist'],
            [['supported_buyer_countries' => ['GBR', 'X']], [], 'empty', self::CACHE_EMPTY, 'a list holding no usable code permits no country'],
            [['supported_buyer_countries' => ['GB']], ['GB'], 'allowlist', '["GB"]', 'a single code is cached'],
            [['supported_buyer_countries' => ['no', 'gb']], ['GB', 'NO'], 'allowlist', '["GB","NO"]', 'codes are uppercased and sorted'],
            [['supported_buyer_countries' => [' gb ', 'GB']], ['GB'], 'allowlist', '["GB"]', 'codes are trimmed and de-duplicated'],
            [['supported_buyer_countries' => ['GB', 'GBR', '', 'X', 42, null, ['NO']]], ['GB'], 'allowlist', '["GB"]', 'only alpha-2 string codes survive'],
        ];
    }

    private static function testTheFetchCachesTheAllowlist(): void
    {
        foreach (self::fetchCases() as [$body, $expected, $state, $raw, $description]) {
            $module = self::moduleWithMerchantResponse(self::merchantResponse($body));
            $module->getMerchantAvailableTerms(true);

            TinyAssert::same(
                $expected,
                $module->getMerchantBuyerCountries(),
                'fetch caching: ' . $description
            );
            TinyAssert::same(
                $state,
                $module->getTwoBuyerCountryRestrictionState(),
                'fetch state: ' . $description
            );
        }
    }

    /**
     * The same table read back through Configuration by a SECOND instance: the
     * gate runs on a later request than the fetch, so a state that does not
     * survive the store is a state the gate never sees.
     */
    private static function testTheCacheRoundTripPreservesTheState(): void
    {
        foreach (self::fetchCases() as [$body, $expected, $state, $raw, $description]) {
            $module = self::moduleWithMerchantResponse(self::merchantResponse($body));
            $module->getMerchantAvailableTerms(true);

            TinyAssert::same(
                $raw,
                Configuration::get(Twopayment::CONFIG_MERCHANT_BUYER_COUNTRIES),
                'cache encoding: ' . $description
            );

            $later = new TwopaymentTestHarness();
            TinyAssert::same(
                $expected,
                $later->getMerchantBuyerCountries(),
                'cache round trip: ' . $description
            );
            TinyAssert::same(
                $state,
                $later->getTwoBuyerCountryRestrictionState(),
                'cache round trip state: ' . $description
            );
        }
    }

    /**
     * Presence is read from the raw body under `data`. A response carrying only
     * the flattened root - the shape older callers and stubs pass around - must
     * still be read for the field rather than treated as absent wholesale.
     */
    private static function testPresenceIsDetectedWithoutTheRawBody(): void
    {
        $module = self::moduleWithMerchantResponse(['http_status' => 200, 'supported_buyer_countries' => []]);
        $module->getMerchantAvailableTerms(true);

        TinyAssert::same('empty', $module->getTwoBuyerCountryRestrictionState());
        TinyAssert::same([], $module->getMerchantBuyerCountries());
    }

    /* ===================================================================
     * Gate
     * =================================================================== */

    /**
     * The gate's verdict for each (cache value, billing, shipping) combination.
     * Read as: with this cached, and these two addresses on the cart, is Two
     * allowed?
     */
    private static function verdictCases(): array
    {
        return [
            // Unrestricted: no country is ever consulted, including one that no
            // address could resolve at all.
            [self::CACHE_UNFETCHED, 'GB', 'GB', true, 'a cache no fetch has populated allows any country'],
            [self::CACHE_ABSENT, 'GB', 'GB', true, 'an absent field allows any country'],
            [self::CACHE_ABSENT, self::NO_COUNTRY, null, true, 'an unrestricted merchant is not gated on a resolvable country'],

            // Present, and permitting nothing.
            [self::CACHE_EMPTY, 'GB', 'GB', false, 'a present empty list refuses a country no list could contain'],
            [self::CACHE_EMPTY, self::NO_COUNTRY, self::NO_COUNTRY, false, 'a present empty list refuses an unresolvable country too'],
            [self::CACHE_MALFORMED, 'GB', 'GB', false, 'a present non-list field refuses every country'],

            // Allowlist.
            ['["GB","NO"]', 'GB', 'GB', true, 'a billing country in the list is allowed'],
            ['["GB","NO"]', 'DE', 'DE', false, 'a billing country outside the list is refused'],
            ['["GB"]', 'ES', 'GB', false, 'an allowlisted SHIPPING country does not rescue a refused billing country'],

            // Case and padding, on the cached side.
            ['["gb"]', 'GB', 'GB', true, 'a lowercase cached code still matches'],
            ['[" gb "]', 'GB', 'GB', true, 'a padded cached code still matches'],
            ['["GB"]', 'GB', 'GB', true, 'an uppercase cached code matches'],

            // Billing -> shipping fallback.
            ['["NO"]', self::NO_COUNTRY, 'NO', true, 'a billing address with no country falls back to shipping'],
            ['["NO"]', null, 'NO', true, 'no billing address at all falls back to shipping'],
            ['["NO"]', self::NO_COUNTRY, 'DE', false, 'the fallback is still matched against the list'],

            // Nothing resolvable, and the field IS present.
            ['["NO"]', self::NO_COUNTRY, self::NO_COUNTRY, false, 'neither address carrying a country is refused'],
            ['["NO"]', null, null, false, 'no addresses at all is refused'],
        ];
    }

    private static function testTheGateVerdict(): void
    {
        foreach (self::verdictCases() as [$cached, $billing, $shipping, $expected, $description]) {
            $module = self::offerableModule($billing, $shipping);
            self::cacheRaw($cached);

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
        foreach (self::verdictCases() as [$cached, $billing, $shipping, $expected, $description]) {
            $billingResolves = $billing !== null && $billing !== self::NO_COUNTRY;

            $module = self::offerableModule($billing, $shipping);
            self::cacheRaw($cached);

            TinyAssert::same(
                ($expected && $billingResolves) ? 1 : 0,
                count($module->hookPaymentOptions([])),
                'display point: ' . $description
            );

            if ($billing === null || $shipping === null) {
                continue;
            }

            $module = self::offerableModule($billing, $shipping);
            self::cacheRaw($cached);

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
     * billing-then-shipping chain and injected as `twopayment.company_search_country`.
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
        self::cacheRaw(self::CACHE_ABSENT);
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
        self::cacheRaw(self::CACHE_ABSENT);
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
        self::cacheRaw(self::CACHE_ABSENT);
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
                null,
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

        TinyAssert::same(null, $module->getMerchantBuyerCountries(), 'an identity change must drop the allowlist');
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
        self::cacheRaw('["GB"]');
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

    /**
     * Every refusal of a real submission is diagnosable: severity 2, with the
     * buyer country and the state that refused both named. Unlike the display
     * gate there is no once-per-request latch - a refused submission is one
     * event per attempt, not one per render.
     */
    private static function testARefusedSubmissionLogsAWarning(): void
    {
        $cases = [
            ['["GB"]', 'allowlist', 'a country outside the allowlist'],
            [self::CACHE_EMPTY, 'empty', 'a present but empty list'],
            [self::CACHE_MALFORMED, 'malformed', 'a present but non-list field'],
        ];

        foreach ($cases as [$cached, $state, $description]) {
            $module = self::offerableModule('DE', 'DE');
            self::cacheRaw($cached);
            TinyAssert::true(self::submissionWasRefused($module), 'order submit refused: ' . $description);
            self::assertWarningNames('Order submission refused', $state, 'order submit: ' . $description);

            $module = self::offerableModule('DE', 'DE');
            self::cacheRaw($cached);
            PrestaShopLogger::reset();
            $result = $module->checkTwoOrderIntentApprovalAtPayment(
                $module->context->cart,
                new Customer(),
                new Currency(),
                new Address()
            );

            TinyAssert::same(false, $result['approved'], 'order intent refused: ' . $description);
            TinyAssert::same('buyer_country_not_supported', $result['status'], 'order intent status: ' . $description);
            self::assertWarningNames('Order intent refused', $state, 'order intent: ' . $description);
        }
    }

    /** Asserts one severity-2 line carrying $needle, the buyer country and $state. */
    private static function assertWarningNames(string $needle, string $state, string $context): void
    {
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], $needle) === false) {
                continue;
            }

            TinyAssert::same(2, $entry['severity'], $context . ': must be logged at warning severity');
            TinyAssert::true(
                strpos($entry['message'], 'buyer country DE') !== false
                && strpos($entry['message'], 'list ' . $state) !== false,
                $context . ': the log must name the buyer country and the refusing state: ' . $entry['message']
            );

            return;
        }

        throw new RuntimeException($context . ': no "' . $needle . '" line was logged');
    }
}
