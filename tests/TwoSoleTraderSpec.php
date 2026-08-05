<?php

declare(strict_types=1);

/**
 * Unit spec for the sole-trader business logic (TWO-24755; toggle removal
 * TWO-25166): the registry's country answer is the ONLY gate - no merchant
 * configuration and no account-type mode are consulted (both features have
 * been removed), registry-only
 * response semantics (empty list = business-only checkout), fail-soft vs
 * fail-closed registry/token handling, the cookie cache's single-slot/TTL
 * behaviour, and that the address form carries no account_type field.
 */

/**
 * Harness whose Two API surface returns canned responses keyed by
 * endpoint prefix, recording every request.
 */
final class TwoSoleTraderTestHarness extends TwopaymentTestHarness
{
    /** @var array<string, mixed> */
    public $cannedResponses = [];
    /** @var string[] */
    public $requests = [];
    /** @var array<string, int|null> endpoint prefix -> timeout it was called with */
    public $timeouts = [];

    public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
    {
        $this->requests[] = $endpoint;
        $this->timeouts[$endpoint] = $timeout;
        foreach ($this->cannedResponses as $prefix => $response) {
            if (strpos($endpoint, $prefix) === 0) {
                return $response;
            }
        }
        return false;
    }
}

final class TwoSoleTraderSpec
{
    public static function runAll(): void
    {
        $tests = [
            'testAvailableInRegistryCapableCountryWithNoConfig',
            'testRetiredToggleValueHasNoEffect',
            'testUpgradeDeletesRetiredToggle',
            'testAvailableWhenRegistryAllowsCountry',
            'testHiddenWhenRegistryOmitsIt',
            'testRegistryErrorFallsBackToNoSoleTrader',
            'testRegistryRejectsMalformedCountry',
            'testRegistryResponseCachedPerRequest',
            'testCookieCacheIsSingleSlotAndOverwritesOnCountryChange',
            'testCookieCacheExpiresAfterTtl',
            'testFetchErrorIsNotCached',
            'testTokenMintReadsHeaderAndFailsClosed',
            'testConfigureSslVerificationIsCallableFromOutsideTwopayment',
            'testSignupUrlFollowsEnvironment',
            'testFormatterHasNoAccountTypeField',
            'testPaymentTileCarriesTheServerResolvedToggleAnswer',
            'testPaymentTileWithAnUnknownAnswerAsksNothingAndClaimsNothing',
            'testPaymentTileWithNoBillingCountryAsksTheRegistryNothing',
            'testRegistryLookupUsesTheTightCheckoutTimeout',
            'testFailedRegistryLookupIsAttemptedOncePerRequest',
            'testPaymentOptionStubRefusesASetterCoreDoesNotHave',
        ];
        foreach ($tests as $test) {
            self::reset();
            self::$test();
            print("PASS TwoSoleTraderSpec::$test\n");
        }
    }

    private static function reset(): void
    {
        StubStore::reset();
        TwoSoleTrader::resetCache();
        PrestaShopLogger::reset();
    }

    private static function harness(array $config, array $responses): TwoSoleTraderTestHarness
    {
        foreach ($config as $key => $value) {
            Configuration::updateValue($key, $value);
        }
        $module = new TwoSoleTraderTestHarness();
        $module->cannedResponses = $responses;
        return $module;
    }

    private static function registryOk(array $types): array
    {
        return [
            'http_status' => 200,
            'supported_company_types' => $types,
        ];
    }

    /**
     * The registry's country answer is the ONLY gate (TWO-25166): no
     * merchant configuration is consulted at all, so a store with no
     * sole-trader config row whatsoever offers the flow in a
     * registry-capable country.
     */
    private static function testAvailableInRegistryCapableCountryWithNoConfig(): void
    {
        $module = self::harness(
            [],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );
        TinyAssert::false(
            Configuration::hasKey('PS_TWO_ENABLE_SOLE_TRADER'),
            'This test must exercise a store with no sole-trader configuration row at all'
        );
        TinyAssert::true(TwoSoleTrader::isAvailable($module, 'GB'));
    }

    /**
     * The retired merchant toggle is dead weight: a stored 0 - which is
     * exactly what install() and upgrade-2.6.1 wrote, and why the feature
     * was invisible on both PrestaShop staging shops - must no longer
     * suppress the flow.
     */
    private static function testRetiredToggleValueHasNoEffect(): void
    {
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 0],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );
        TinyAssert::true(
            TwoSoleTrader::isAvailable($module, 'GB'),
            'A leftover PS_TWO_ENABLE_SOLE_TRADER=0 row must not gate sole trader checkout'
        );
        TinyAssert::false(
            method_exists('TwoSoleTrader', 'isEnabled'),
            'TwoSoleTrader::isEnabled() is retired - the country answer is the only gate'
        );
    }

    /**
     * The 2.6.3 upgrade deletes the retired configuration row rather than
     * leaving a dead value a future reader could mistake for live config.
     */
    private static function testUpgradeDeletesRetiredToggle(): void
    {
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.6.3.php';

        Configuration::updateValue('PS_TWO_ENABLE_SOLE_TRADER', 0);
        TinyAssert::true(upgrade_module_2_6_3(new TwopaymentTestHarness()));
        TinyAssert::false(
            Configuration::hasKey('PS_TWO_ENABLE_SOLE_TRADER'),
            'upgrade-2.6.3 must delete the retired PS_TWO_ENABLE_SOLE_TRADER row'
        );

        // Idempotent on a store that never had the row
        TinyAssert::true(upgrade_module_2_6_3(new TwopaymentTestHarness()));
    }

    private static function testAvailableWhenRegistryAllowsCountry(): void
    {
        $module = self::harness(
            [],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );
        TinyAssert::true(TwoSoleTrader::isAvailable($module, 'GB'));
        // Lowercase input normalises to the same country
        TwoSoleTrader::resetCache();
        TinyAssert::true(TwoSoleTrader::isAvailable($module, 'gb'));
    }

    private static function testHiddenWhenRegistryOmitsIt(): void
    {
        // Registered businesses need no registry enrollment, so the endpoint
        // deliberately omits them: an empty list means business-only checkout.
        $module = self::harness(
            [],
            ['/registry/v1/supported-company-types/' => self::registryOk([])]
        );
        TinyAssert::false(TwoSoleTrader::isAvailable($module, 'NO'));
    }

    private static function testRegistryErrorFallsBackToNoSoleTrader(): void
    {
        // Network error (transport returns false)
        $module = self::harness([], []);
        TinyAssert::same([], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));

        // Non-200
        TwoSoleTrader::resetCache();
        StubStore::reset();
        $module->cannedResponses = [
            '/registry/v1/supported-company-types/' => ['http_status' => 404],
        ];
        TinyAssert::same([], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));

        // 200 with malformed body
        TwoSoleTrader::resetCache();
        StubStore::reset();
        $module->cannedResponses = [
            '/registry/v1/supported-company-types/' => ['http_status' => 200, 'data' => 'junk'],
        ];
        TinyAssert::same([], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));
    }

    private static function testRegistryRejectsMalformedCountry(): void
    {
        $module = self::harness(
            [],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );
        // Never hits the API for junk country input
        TinyAssert::same([], TwoSoleTrader::getSupportedCompanyTypes($module, ''));
        TinyAssert::same([], TwoSoleTrader::getSupportedCompanyTypes($module, 'G'));
        TinyAssert::same([], TwoSoleTrader::getSupportedCompanyTypes($module, 'GBR'));
        TinyAssert::count(0, $module->requests);
    }

    private static function testRegistryResponseCachedPerRequest(): void
    {
        $module = self::harness(
            [],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );
        TwoSoleTrader::getSupportedCompanyTypes($module, 'GB');
        TwoSoleTrader::getSupportedCompanyTypes($module, 'GB');
        TwoSoleTrader::getSupportedCompanyTypes($module, 'gb');
        TinyAssert::count(1, $module->requests);
        // A different country is its own cache entry within the request
        TwoSoleTrader::getSupportedCompanyTypes($module, 'US');
        TinyAssert::count(2, $module->requests);
    }

    /**
     * The cookie cache is a single slot: switching country overwrites it
     * rather than growing a new key per country, capping the PrestaShop
     * session cookie's growth regardless of how many countries a caller
     * requests (the availability check runs on unvalidated client input
     * live at the address-form step).
     */
    private static function testCookieCacheIsSingleSlotAndOverwritesOnCountryChange(): void
    {
        $module = self::harness(
            [],
            [
                '/registry/v1/supported-company-types/GB' => self::registryOk(['SOLE_TRADER']),
                '/registry/v1/supported-company-types/US' => self::registryOk([]),
            ]
        );
        TwoSoleTrader::getSupportedCompanyTypes($module, 'GB');
        $cookie = Context::getContext()->cookie;
        TinyAssert::true(isset($cookie->{TwoSoleTrader::COOKIE_KEY}), 'Expected the single cache cookie to be set');
        $afterGb = json_decode($cookie->{TwoSoleTrader::COOKIE_KEY}, true);
        TinyAssert::same('GB', $afterGb['country']);

        // Switching country overwrites the same key rather than adding one
        TwoSoleTrader::resetCache();
        TwoSoleTrader::getSupportedCompanyTypes($module, 'US');
        $afterUs = json_decode($cookie->{TwoSoleTrader::COOKIE_KEY}, true);
        TinyAssert::same('US', $afterUs['country']);
        TinyAssert::same([], $afterUs['types']);
    }

    /**
     * A cached answer for the same country within the TTL is reused
     * without hitting the registry again; once stale it re-fetches.
     */
    private static function testCookieCacheExpiresAfterTtl(): void
    {
        $module = self::harness(
            [],
            ['/registry/v1/supported-company-types/GB' => self::registryOk(['SOLE_TRADER'])]
        );
        $cookie = Context::getContext()->cookie;
        // Seed a stale cookie entry (older than CACHE_TTL_SECONDS) directly,
        // bypassing the request-scoped static cache this test doesn't want.
        $cookie->{TwoSoleTrader::COOKIE_KEY} = json_encode([
            'country' => 'GB',
            'types' => ['SOME_STALE_VALUE'],
            'fetched_at' => time() - TwoSoleTrader::CACHE_TTL_SECONDS - 1,
        ]);
        $types = TwoSoleTrader::getSupportedCompanyTypes($module, 'GB');
        TinyAssert::same(['SOLE_TRADER'], $types, 'Stale cookie entry must trigger a re-fetch, not be reused');
        TinyAssert::count(1, $module->requests);
    }

    /**
     * A registry fetch ERROR must not be cached - otherwise a single
     * transient blip suppresses the toggle for the rest of the TTL
     * window, indistinguishable from a real business-only country.
     */
    private static function testFetchErrorIsNotCached(): void
    {
        $module = self::harness([], []);
        // No canned response for GB => setTwoPaymentRequest returns false => fetch error
        $types = TwoSoleTrader::getSupportedCompanyTypes($module, 'GB');
        TinyAssert::same([], $types);
        $cookie = Context::getContext()->cookie;
        TinyAssert::true(!isset($cookie->{TwoSoleTrader::COOKIE_KEY}) || empty($cookie->{TwoSoleTrader::COOKIE_KEY}), 'A fetch error must not populate the cookie cache');

        // Now the registry recovers - resetCache clears the request-scoped
        // cache so the recovered answer is actually fetched and used
        TwoSoleTrader::resetCache();
        $module->cannedResponses = ['/registry/v1/supported-company-types/GB' => self::registryOk(['SOLE_TRADER'])];
        TinyAssert::same(['SOLE_TRADER'], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));
    }

    private static function testTokenMintReadsHeaderAndFailsClosed(): void
    {
        $module = self::harness([], []);

        // Happy path: both mints return the token header (case handled by
        // the transport, which lower-cases header names)
        TwoSoleTrader::$transport = function ($endpoint, $payload) {
            return [
                'status' => 200,
                'headers' => ['two-delegated-authority-token' => $endpoint === '/registry/v1/delegation' ? 'reg-token' : 'autofill-token'],
            ];
        };
        TinyAssert::same(
            ['delegation_token' => 'reg-token', 'autofill_token' => 'autofill-token'],
            TwoSoleTrader::mintTokens($module)
        );

        // Second mint failing voids the pair — never hand the browser half a flow
        TwoSoleTrader::$transport = function ($endpoint, $payload) {
            if ($endpoint === '/autofill/v1/delegation') {
                return ['status' => 500, 'headers' => []];
            }
            return ['status' => 200, 'headers' => ['two-delegated-authority-token' => 'reg-token']];
        };
        TinyAssert::same(null, TwoSoleTrader::mintTokens($module));

        // Missing header on a 200 also fails closed
        TwoSoleTrader::$transport = function ($endpoint, $payload) {
            return ['status' => 200, 'headers' => []];
        };
        TinyAssert::same(null, TwoSoleTrader::mintTokens($module));
    }

    /**
     * Regression guard: TwoSoleTrader::postCapturingHeaders() calls
     * $module->configureSslVerification($ch) from OUTSIDE the Twopayment
     * class (TwoSoleTrader does not extend it). Every $transport-seamed
     * test above bypasses that real call entirely, so a caught-too-late
     * bug here (Twopayment::configureSslVerification declared private)
     * would fatal every real, non-test token mint while every other test
     * in this suite kept passing. Cheap, network-free tripwire: assert
     * the method is actually callable from another class without
     * needing to exercise curl.
     */
    private static function testConfigureSslVerificationIsCallableFromOutsideTwopayment(): void
    {
        $method = new ReflectionMethod('Twopayment', 'configureSslVerification');
        TinyAssert::true($method->isPublic(), 'configureSslVerification must be public - TwoSoleTrader calls it from outside the Twopayment class');
    }

    private static function testSignupUrlFollowsEnvironment(): void
    {
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'production');
        TinyAssert::same('https://checkout.two.inc/soletrader/signup', TwoSoleTrader::getSignupPageUrl());

        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'staging');
        TinyAssert::same('https://checkout.staging.two.inc/soletrader/signup', TwoSoleTrader::getSignupPageUrl());

        // Anything else (legacy 'development', sandbox, empty) => sandbox,
        // mirroring Twopayment::ENVIRONMENT_HOSTS semantics
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'sandbox');
        TinyAssert::same('https://checkout.sandbox.two.inc/soletrader/signup', TwoSoleTrader::getSignupPageUrl());
        Configuration::updateValue('PS_TWO_ENVIRONMENT', '');
        TinyAssert::same('https://checkout.sandbox.two.inc/soletrader/signup', TwoSoleTrader::getSignupPageUrl());
    }

    /**
     * The address form carries no account_type field regardless of
     * sole-trader registry state - that selector feature has been
     * removed entirely (TWO-24755 rework). Sole traders enrol via the
     * payment-step toggle, not the address form.
     */
    private static function testFormatterHasNoAccountTypeField(): void
    {
        $overridePath = dirname(__DIR__) . '/override/classes/form/CustomerAddressFormatter.php';
        if (!class_exists('CustomerAddressFormatter', false)) {
            require_once $overridePath;
        }

        $translator = new class {
            public function trans($message, array $params = [], $domain = null): string
            {
                return (string) $message;
            }
        };

        $country = new Country();
        $format = (new CustomerAddressFormatter($country, $translator, []))->getFormat();
        TinyAssert::false(isset($format['account_type']), 'Address form must not add an account_type field');
        TinyAssert::true(isset($format['company']), 'Company field is still present for B2B checkout');
    }

    /**
     * Capture what getTwoPaymentOption() hands the template, which the stub
     * Smarty otherwise discards.
     *
     * @return array{vars: array<string, mixed>}
     */
    private static function captureTileVars(TwoSoleTraderTestHarness $module): array
    {
        $captured = new class {
            /** @var array<string, mixed> */
            public $vars = [];

            public function assign($vars): void
            {
                if (is_array($vars)) {
                    $this->vars = array_merge($this->vars, $vars);
                }
            }

            public function fetch($template): string
            {
                return '';
            }
        };
        $context = Context::getContext();
        $previous = $context->smarty;
        $context->smarty = $captured;
        try {
            $method = new ReflectionMethod(Twopayment::class, 'getTwoPaymentOption');
            $method->setAccessible(true);
            $method->invoke($module);
        } finally {
            $context->smarty = $previous;
        }

        return ['vars' => $captured->vars];
    }

    /**
     * TWO-25326 bug 9, round 3: the toggle is rendered SERVER-side now, so the
     * payment tile has to carry the registry answer and the country it is an
     * answer about.
     *
     * This is the seam no Jest test can see. The browser half
     * (TwoSoleTrader.adoptServerRenderedToggle) reads exactly two attributes and
     * treats anything it cannot parse as "no answer" - i.e. it silently falls
     * back to the round trip that caused the flicker in the first place. So a
     * rename or a dropped assign here does not break the checkout, it just
     * quietly restores the bug, with every JS test still green.
     */
    private static function testPaymentTileCarriesTheServerResolvedToggleAnswer(): void
    {
        StubStore::$addresses[8811] = ['id_country' => 44];
        StubStore::$countries[44] = 'gb';
        Context::getContext()->cart->id_address_invoice = 8811;

        $module = self::harness(
            [],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );
        // The tile reads CACHE-ONLY (round 3 review, finding 2), so the answer has
        // to already be known - which on a real shop is what the browser's own
        // availability request arranges, through the endpoint that writes the
        // cookie. This stands in for that having happened.
        TwoSoleTrader::isAvailable($module, 'GB');
        $requestsBefore = count($module->requests);
        $available = self::captureTileVars($module);
        TinyAssert::same(
            $requestsBefore,
            count($module->requests),
            'rendering the tile must not make a registry request of its own'
        );
        TinyAssert::same(true, $available['vars']['sole_trader_available']);
        TinyAssert::same('1', $available['vars']['sole_trader_answer']);
        TinyAssert::same('GB', $available['vars']['sole_trader_country']);

        // The other answer, from the same source: a country the registry does
        // not list sole traders for. The template renders the toggle hidden and
        // chipless, and the browser adopts THAT rather than asking again.
        self::reset();
        StubStore::$addresses[8811] = ['id_country' => 44];
        StubStore::$countries[44] = 'gb';
        Context::getContext()->cart->id_address_invoice = 8811;
        $businessOnlyModule = self::harness(
            [],
            ['/registry/v1/supported-company-types/' => self::registryOk([])]
        );
        TwoSoleTrader::isAvailable($businessOnlyModule, 'GB');
        $businessOnly = self::captureTileVars($businessOnlyModule);
        TinyAssert::same(false, $businessOnly['vars']['sole_trader_available']);
        // '0' is a real answer and must be distinguishable from '' below - it is
        // what lets the browser adopt "business-only country" and stop asking.
        TinyAssert::same('0', $businessOnly['vars']['sole_trader_answer']);
        TinyAssert::same('GB', $businessOnly['vars']['sole_trader_country']);
    }

    /**
     * A failed lookup costs the request ONE timeout, not one per caller.
     *
     * This is the counterpart to testFetchErrorIsNotCached: the error must not
     * become an ANSWER and must not outlive the request, but it must still be
     * remembered FOR the request. Round 3 dropped the request-scoped memo along
     * with the bad one and turned a failing registry into several serial timeouts
     * on the checkout render path - which took the payment option past the e2e
     * suite's wait and off the page entirely.
     */
    private static function testFailedRegistryLookupIsAttemptedOncePerRequest(): void
    {
        // No canned response: the harness returns false, the shape of a transport
        // failure.
        $module = self::harness([], []);

        TinyAssert::same(null, TwoSoleTrader::getSupportedCompanyTypesOrNull($module, 'GB'));
        TinyAssert::same(null, TwoSoleTrader::getSupportedCompanyTypesOrNull($module, 'GB'));
        TinyAssert::false(TwoSoleTrader::isAvailable($module, 'GB'));

        TinyAssert::same(
            1,
            count(array_filter($module->requests, function ($endpoint) {
                return strpos($endpoint, '/registry/v1/supported-company-types/GB') === 0;
            })),
            'a failing registry lookup must be attempted at most once per request per country'
        );

        // Still per-COUNTRY, not a blanket "give up".
        TinyAssert::same(null, TwoSoleTrader::getSupportedCompanyTypesOrNull($module, 'NO'));
        TinyAssert::same(
            1,
            count(array_filter($module->requests, function ($endpoint) {
                return strpos($endpoint, '/registry/v1/supported-company-types/NO') === 0;
            })),
            'a different country must still get its own attempt'
        );
    }

    /**
     * An answer that is not already known is reported as NO answer, and costs the
     * render no request at all.
     *
     * Two properties in one, and both matter:
     *  - it must not be "no" (isAvailable() flattens the two, which is right for a
     *    capability gate and wrong for markup the browser adopts as settled and
     *    never re-asks - one timeout would become a cached "business-only country"
     *    for the rest of the page's life);
     *  - it must not be resolved HERE. This runs in a shopper's checkout render,
     *    and a payment-option change reloads that page, so a live call meant every
     *    payment-step render on a shop that cannot reach the registry paid the
     *    timeout again (round 3 review, finding 2).
     */
    private static function testPaymentTileWithAnUnknownAnswerAsksNothingAndClaimsNothing(): void
    {
        StubStore::$addresses[8811] = ['id_country' => 44];
        StubStore::$countries[44] = 'gb';
        Context::getContext()->cart->id_address_invoice = 8811;

        // A registry that WOULD answer, and a cold cache. The tile must still not
        // call it - that is the whole point, and a canned success here is what
        // makes the assertion about the render rather than about the transport.
        $module = self::harness(
            [],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );
        $captured = self::captureTileVars($module);

        TinyAssert::same('', $captured['vars']['sole_trader_answer']);
        // Still fail-soft in what it DRAWS - the toggle does not render.
        TinyAssert::same(false, $captured['vars']['sole_trader_available']);
        TinyAssert::same('GB', $captured['vars']['sole_trader_country']);
        TinyAssert::same(
            array(),
            array_values(array_filter($module->requests, function ($endpoint) {
                return strpos($endpoint, '/registry/v1/supported-company-types/') === 0;
            })),
            'the payment-step render must never resolve availability over the network'
        );
    }

    /**
     * The PaymentOption stub must refuse a setter PrestaShop core does not have.
     *
     * Round 4 review: the stub used to accept ANY `set*` name, record it and
     * return $this - so a module change calling a setter core lacks passed every
     * spec here and fatalled in production, the one mismatch a stub of a core
     * value object exists to catch. This asserts the allowlist is load-bearing
     * rather than decorative.
     */
    private static function testPaymentOptionStubRefusesASetterCoreDoesNotHave(): void
    {
        $option = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        // A real one still works, and still chains.
        TinyAssert::true($option->setModuleName('twopayment') === $option);

        $refused = false;
        try {
            $option->setSomethingCoreDoesNotHave('x');
        } catch (BadMethodCallException $e) {
            $refused = true;
        }
        TinyAssert::true($refused, 'the stub must refuse a setter that PrestaShop core does not define');
    }

    /**
     * The registry lookup must carry the tight checkout timeout rather than
     * setTwoPaymentRequest()'s 60-second default (API_TIMEOUT_LONG), which is
     * sized for file uploads. It is reached from the module's own AJAX controller
     * while a buyer waits on the checkout for the toggle to appear - the payment
     * tile deliberately does NOT reach it, reading the answer cache-only instead -
     * so a minute is the wrong bound for it either way.
     */
    private static function testRegistryLookupUsesTheTightCheckoutTimeout(): void
    {
        $module = self::harness(
            [],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );
        TwoSoleTrader::isAvailable($module, 'GB');

        TinyAssert::same(
            Twopayment::API_TIMEOUT_STATE_CHECK,
            $module->timeouts['/registry/v1/supported-company-types/GB'] ?? null,
            'the registry lookup happens while a buyer waits on the checkout and must not inherit the 60s default'
        );
    }

    /**
     * No billing address yet means no country, and a country is the registry
     * call's only argument - so there is nothing to ask and the tile must say
     * "not available", definitively, without spending a request on it.
     *
     * The caller-side `!== ''` guard is defence in depth rather than the only
     * thing standing between here and an HTTP call: getSupportedCompanyTypes()
     * rejects a non-ISO country before any request of its own. What this test
     * genuinely pins is the ANSWER - '0', a definite no, not '' - because an
     * empty country is not an unresolved registry, and telling the browser it was
     * would make it keep asking about a country it does not have.
     */
    private static function testPaymentTileWithNoBillingCountryAsksTheRegistryNothing(): void
    {
        Context::getContext()->cart->id_address_invoice = 0;

        $module = self::harness(
            [],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );
        $captured = self::captureTileVars($module);

        TinyAssert::same(false, $captured['vars']['sole_trader_available']);
        TinyAssert::same('0', $captured['vars']['sole_trader_answer']);
        TinyAssert::same('', $captured['vars']['sole_trader_country']);
        TinyAssert::same(
            array(),
            array_values(array_filter($module->requests, function ($endpoint) {
                return strpos($endpoint, '/registry/v1/supported-company-types/') === 0;
            })),
            'a tile with no billing country must not call the registry at all'
        );
    }
}
