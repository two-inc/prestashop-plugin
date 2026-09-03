<?php

declare(strict_types=1);

/**
 * Unit spec for the sole-trader business logic (TWO-24755; toggle removal
 * TWO-25166): the registry's country answer is the ONLY gate.
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
            'testTokenMintRequestCarriesClientParamsAndVendorHeader',
            'testConfigureSslVerificationIsCallableFromOutsideTwopayment',
            'testSignupUrlFollowsEnvironment',
            'testSignupUrlHonoursCheckoutOverrideInDevMode',
            'testSignupUrlIgnoresCheckoutOverrideOutsideDevMode',
            'testServiceUrlOverridesResolveIndependently',
            'testFormatterHasNoAccountTypeField',
            'testPaymentTileCarriesTheServerResolvedToggleAnswer',
            'testPaymentTileWithAnUnknownAnswerAsksNothingAndClaimsNothing',
            'testPaymentTileWithNoBillingCountryAsksTheRegistryNothing',
            'testRegistryLookupUsesTheTightCheckoutTimeout',
            'testFailedRegistryLookupIsAttemptedOncePerRequest',
            'testPaymentOptionStubRefusesASetterCoreDoesNotHave',
            'testAvailabilityEndpointPersistsTheRegistryAnswer',
            'testAvailabilityEndpointWritesNothingWhenTheTokenIsRejected',
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
     * TWO-25166: no merchant configuration is consulted at all.
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
     * A stored 0 - what install() and upgrade-2.6.1 wrote, and why the feature
     * was invisible on both PrestaShop staging shops - must no longer suppress
     * the flow.
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

        TwoSoleTrader::resetCache();
        StubStore::reset();
        $module->cannedResponses = [
            '/registry/v1/supported-company-types/' => ['http_status' => 404],
        ];
        TinyAssert::same([], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));

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
        TwoSoleTrader::getSupportedCompanyTypes($module, 'US');
        TinyAssert::count(2, $module->requests);
    }

    /**
     * A single slot caps the PrestaShop session cookie's growth: the
     * availability check runs on unvalidated client input live at the
     * address-form step.
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

        TwoSoleTrader::resetCache();
        TwoSoleTrader::getSupportedCompanyTypes($module, 'US');
        $afterUs = json_decode($cookie->{TwoSoleTrader::COOKIE_KEY}, true);
        TinyAssert::same('US', $afterUs['country']);
        TinyAssert::same([], $afterUs['types']);
    }

    private static function testCookieCacheExpiresAfterTtl(): void
    {
        $module = self::harness(
            [],
            ['/registry/v1/supported-company-types/GB' => self::registryOk(['SOLE_TRADER'])]
        );
        $cookie = Context::getContext()->cookie;
        // Seeded directly to bypass the request-scoped static cache.
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
     * A cached fetch error would suppress the toggle for the rest of the TTL
     * window, indistinguishable from a real business-only country.
     */
    private static function testFetchErrorIsNotCached(): void
    {
        $module = self::harness([], []);
        // No canned response => setTwoPaymentRequest returns false => fetch error
        $types = TwoSoleTrader::getSupportedCompanyTypes($module, 'GB');
        TinyAssert::same([], $types);
        $cookie = Context::getContext()->cookie;
        TinyAssert::true(!isset($cookie->{TwoSoleTrader::COOKIE_KEY}) || empty($cookie->{TwoSoleTrader::COOKIE_KEY}), 'A fetch error must not populate the cookie cache');

        TwoSoleTrader::resetCache();
        $module->cannedResponses = ['/registry/v1/supported-company-types/GB' => self::registryOk(['SOLE_TRADER'])];
        TinyAssert::same(['SOLE_TRADER'], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));
    }

    private static function testTokenMintReadsHeaderAndFailsClosed(): void
    {
        $module = self::harness([], []);

        // Header names arrive lower-cased: the transport does that.
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

        // Never hand the browser half a flow: one failure voids the pair.
        TwoSoleTrader::$transport = function ($endpoint, $payload) {
            if ($endpoint === '/autofill/v1/delegation') {
                return ['status' => 500, 'headers' => []];
            }
            return ['status' => 200, 'headers' => ['two-delegated-authority-token' => 'reg-token']];
        };
        TinyAssert::same(null, TwoSoleTrader::mintTokens($module));

        TwoSoleTrader::$transport = function ($endpoint, $payload) {
            return ['status' => 200, 'headers' => []];
        };
        TinyAssert::same(null, TwoSoleTrader::mintTokens($module));
    }

    /**
     * The $transport seam above bypasses postCapturingHeaders()'s own URL and
     * header assembly entirely, so it can't catch a regression there (the
     * delegation-mint call used to build its URL with no query string and
     * hand-roll its own headers, missing client/client_v and X-Vendor-Name).
     * Exercise buildTokenMintUrl()/buildTokenMintHeaders() directly instead.
     */
    private static function testTokenMintRequestCarriesClientParamsAndVendorHeader(): void
    {
        $module = self::harness(['PS_TWO_VENDOR_NAME' => 'Shop A'], []);

        $method = new ReflectionMethod('TwoSoleTrader', 'buildTokenMintUrl');
        $method->setAccessible(true);
        $url = $method->invoke(null, $module, '/registry/v1/delegation');

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        TinyAssert::same('PS', $query['client'] ?? null, 'delegation-mint URL must carry the shared client param');
        // Literal, not a call to getTwoClientVersion() again - that would just
        // compare the method against itself and pin nothing.
        TinyAssert::same('2.4.0', $query['client_v'] ?? null, 'delegation-mint URL must carry the shared client_v param');

        $headersMethod = new ReflectionMethod('TwoSoleTrader', 'buildTokenMintHeaders');
        $headersMethod->setAccessible(true);
        $headers = $headersMethod->invoke(null, $module, '/registry/v1/delegation');
        TinyAssert::true(in_array('X-API-Key:test-api-key', $headers, true), 'delegation-mint headers must carry X-API-Key');
        TinyAssert::true(in_array('X-Vendor-Name:Shop A', $headers, true), 'delegation-mint headers must carry X-Vendor-Name');
    }

    /**
     * TwoSoleTrader::postCapturingHeaders() calls
     * $module->configureSslVerification($ch) from OUTSIDE the Twopayment class,
     * a call every $transport-seamed test above bypasses: declaring it private
     * would fatal every real token mint with this suite still green.
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
        // mirroring Twopayment::ENVIRONMENT_HOSTS
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'sandbox');
        TinyAssert::same('https://checkout.sandbox.two.inc/soletrader/signup', TwoSoleTrader::getSignupPageUrl());
        Configuration::updateValue('PS_TWO_ENVIRONMENT', '');
        TinyAssert::same('https://checkout.sandbox.two.inc/soletrader/signup', TwoSoleTrader::getSignupPageUrl());
    }

    /**
     * A child process is required: _PS_MODE_DEV_ is a constant, so the gate on
     * these overrides cannot be exercised on both sides inside one PHP process.
     *
     * @param string $psModeDev '1', '0' or 'unset'
     * @param array<string, string> $env override vars to export
     *
     * @return array{signup: string, api: string, portal: string}
     */
    private static function resolveUrls(string $psModeDev, array $env, string $environment = 'staging'): array
    {
        $probe = __DIR__ . '/fixtures/dev-mode-url-probe.php';
        $childEnv = array_merge(['PROBE_PS_MODE_DEV' => $psModeDev], $env);
        // stderr goes to a temp FILE, not a second pipe: draining pipes one
        // after the other deadlocks if the child fills the one not being read.
        $errorLog = tmpfile();
        if ($errorLog === false) {
            throw new RuntimeException('Could not open a temp file for the probe stderr');
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => $errorLog];
        $process = proc_open(
            [PHP_BINARY, $probe, $environment],
            $descriptors,
            $pipes,
            dirname(__DIR__),
            $childEnv
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the dev-mode URL probe');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        rewind($errorLog);
        $stderr = (string) stream_get_contents($errorLog);
        fclose($errorLog);
        $status = proc_close($process);
        if ($status !== 0) {
            // stdout as well as stderr: PHP CLI prints fatals to STDOUT, so a
            // stderr-only message for a crashed probe is an empty message.
            throw new RuntimeException('Dev-mode URL probe failed (' . $status . '): ' . $stdout . $stderr);
        }
        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Dev-mode URL probe printed no JSON: ' . $stdout . $stderr);
        }
        return $decoded;
    }

    /**
     * TWO_CHECKOUT_BASE_URL repoints the hosted signup page in dev mode while
     * leaving the API and portal alone.
     */
    private static function testSignupUrlHonoursCheckoutOverrideInDevMode(): void
    {
        $urls = self::resolveUrls('1', ['TWO_CHECKOUT_BASE_URL' => 'http://localhost:3000']);
        TinyAssert::same('http://localhost:3000/soletrader/signup', $urls['signup']);

        // A hand-typed value may carry a trailing slash; the path must not double up.
        $urls = self::resolveUrls('1', ['TWO_CHECKOUT_BASE_URL' => 'http://localhost:3000/']);
        TinyAssert::same('http://localhost:3000/soletrader/signup', $urls['signup']);

        // Empty is not an override - it falls back to the environment map.
        // Delivered via PROBE_EMPTY_VARS, not as an empty entry in the env
        // array: proc_open() drops those, which would make the child see the
        // variable as ABSENT and quietly test the wrong branch of the gate.
        // Empty-but-present is the shape docker-compose.yml actually ships.
        $urls = self::resolveUrls('1', ['PROBE_EMPTY_VARS' => 'TWO_CHECKOUT_BASE_URL']);
        TinyAssert::same('https://checkout.staging.two.inc/soletrader/signup', $urls['signup']);

        $urls = self::resolveUrls('1', ['PROBE_EMPTY_VARS' => 'TWO_API_BASE_URL,TWO_PORTAL_BASE_URL']);
        TinyAssert::same('https://api.staging.two.inc', $urls['api']);
        TinyAssert::same('https://portal.staging.two.inc', $urls['portal']);
    }

    /**
     * The security-relevant half of the gate. Covers both shapes - the constant
     * defined false (what a production PrestaShop does) and absent altogether.
     */
    private static function testSignupUrlIgnoresCheckoutOverrideOutsideDevMode(): void
    {
        $env = ['TWO_CHECKOUT_BASE_URL' => 'http://attacker.example/evil'];

        $urls = self::resolveUrls('0', $env, 'production');
        TinyAssert::same('https://checkout.two.inc/soletrader/signup', $urls['signup']);

        $urls = self::resolveUrls('unset', $env, 'production');
        TinyAssert::same('https://checkout.two.inc/soletrader/signup', $urls['signup']);

        $urls = self::resolveUrls(
            '0',
            [
                'TWO_API_BASE_URL' => 'http://attacker.example/api',
                'TWO_PORTAL_BASE_URL' => 'http://attacker.example/portal',
            ],
            'production'
        );
        TinyAssert::same('https://api.two.inc', $urls['api']);
        TinyAssert::same('https://portal.two.inc', $urls['portal']);
    }

    /**
     * Staging API plus a locally-served checkout page is the whole point of
     * splitting the three overrides.
     */
    private static function testServiceUrlOverridesResolveIndependently(): void
    {
        $urls = self::resolveUrls('1', ['TWO_CHECKOUT_BASE_URL' => 'https://checkout.local.test']);
        TinyAssert::same('https://checkout.local.test/soletrader/signup', $urls['signup']);
        TinyAssert::same('https://api.staging.two.inc', $urls['api']);
        TinyAssert::same('https://portal.staging.two.inc', $urls['portal']);

        $urls = self::resolveUrls('1', ['TWO_API_BASE_URL' => 'http://host.docker.internal:8080']);
        TinyAssert::same('http://host.docker.internal:8080', $urls['api']);
        TinyAssert::same('https://checkout.staging.two.inc/soletrader/signup', $urls['signup']);
        TinyAssert::same('https://portal.staging.two.inc', $urls['portal']);

        $urls = self::resolveUrls('1', ['TWO_PORTAL_BASE_URL' => 'http://host.docker.internal:8081']);
        TinyAssert::same('http://host.docker.internal:8081', $urls['portal']);
        TinyAssert::same('https://api.staging.two.inc', $urls['api']);
        TinyAssert::same('https://checkout.staging.two.inc/soletrader/signup', $urls['signup']);

        $urls = self::resolveUrls('1', [
            'TWO_API_BASE_URL' => 'http://host.docker.internal:8080',
            'TWO_PORTAL_BASE_URL' => 'http://host.docker.internal:8081',
            'TWO_CHECKOUT_BASE_URL' => 'http://localhost:3000',
        ]);
        TinyAssert::same('http://host.docker.internal:8080', $urls['api']);
        TinyAssert::same('http://host.docker.internal:8081', $urls['portal']);
        TinyAssert::same('http://localhost:3000/soletrader/signup', $urls['signup']);
    }

    /**
     * The account_type selector was removed entirely (TWO-24755 rework): sole
     * traders enrol via the payment-step toggle, not the address form.
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
     * The stub Smarty otherwise discards what getTwoPaymentOption() assigns.
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
     * TWO-25326 bug 9: the toggle is rendered SERVER-side, so the payment tile
     * carries the registry answer and the country it answers about.
     *
     * The seam no Jest test can see. TwoSoleTrader.adoptServerRenderedToggle
     * treats anything it cannot parse as "no answer" and silently falls back to
     * the round trip that caused the flicker, so a rename or a dropped assign
     * here quietly restores the bug with every JS test still green.
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
        // The tile reads CACHE-ONLY, so the answer has to already be known; on a
        // real shop the browser's own availability request arranges that.
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
        // '0' is a real answer, distinct from '': it lets the browser adopt
        // "business-only country" and stop asking.
        TinyAssert::same('0', $businessOnly['vars']['sole_trader_answer']);
        TinyAssert::same('GB', $businessOnly['vars']['sole_trader_country']);
    }

    /**
     * A failed lookup costs the request ONE timeout, not one per caller.
     *
     * Counterpart to testFetchErrorIsNotCached: the error must not become an
     * ANSWER nor outlive the request, but must still be remembered FOR it.
     * Without the request-scoped memo a failing registry becomes several serial
     * timeouts on the checkout render path, taking the payment option past the
     * e2e suite's wait and off the page entirely.
     */
    private static function testFailedRegistryLookupIsAttemptedOncePerRequest(): void
    {
        // No canned response: the harness returns false, a transport failure.
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
     *  - it must not be "no": isAvailable() flattens the two, right for a
     *    capability gate and wrong for markup the browser adopts as settled and
     *    never re-asks - one timeout would become a cached "business-only
     *    country" for the rest of the page's life;
     *  - it must not be resolved HERE: this runs in a shopper's checkout render,
     *    and a payment-option change reloads that page, so a live call makes
     *    every payment-step render on a shop that cannot reach the registry pay
     *    the timeout again.
     */
    private static function testPaymentTileWithAnUnknownAnswerAsksNothingAndClaimsNothing(): void
    {
        StubStore::$addresses[8811] = ['id_country' => 44];
        StubStore::$countries[44] = 'gb';
        Context::getContext()->cart->id_address_invoice = 8811;

        // A registry that WOULD answer, and a cold cache: the canned success is
        // what makes this about the render rather than about the transport.
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
     * The response unwinds instead of exiting. Same pattern as
     * SessionCompanyClearSpec/OrgNumberPreVerificationSpec.
     */
    private static function makeAvailabilityController(string $token, string $country)
    {
        Tools::resetTestValues();
        Tools::setTestValue('ajax', 1);
        Tools::setTestValue('action', 'soleTraderAvailability');
        Tools::setTestValue('country', $country);
        if ($token !== '') {
            Tools::setTestValue('token', $token);
        }

        $controller = new class extends TwopaymentOrderintentModuleFrontController {
            /** @var array<int,array> */
            public array $emitted = array();
            /** @var int cookie writes seen at the moment the response was sent */
            public int $writesAtResponse = -1;

            public function sendJsonResponse($content)
            {
                $decoded = json_decode((string) $content, true);
                $this->emitted[] = is_array($decoded) ? $decoded : array('raw' => $content);
                $this->writesAtResponse = Context::getContext()->cookie->writes;

                throw new StubOrderIntentResponded('order intent response sent');
            }
        };
        $controller->context = Context::getContext();
        $controller->module = self::harness([], array(
            '/registry/v1/supported-company-types/' => self::registryOk(array('SOLE_TRADER')),
        ));

        return $controller;
    }

    /**
     * TWO-25326: the payment tile renders the sole-trader toggle from that cookie
     * and never resolves the registry itself, so without an explicit write the
     * server-rendered toggle can never appear and the chip flicker comes back.
     * PrestaShop's Cookie destructor is not enough - it only writes while headers
     * are unsent, contingent on output buffering, an ini setting.
     *
     * Asserted at the moment the response is sent, not afterwards, because a write
     * that happens after the headers are out is exactly the failure mode.
     */
    private static function testAvailabilityEndpointPersistsTheRegistryAnswer(): void
    {
        $controller = self::makeAvailabilityController(Tools::getToken(false), 'GB');
        $before = Context::getContext()->cookie->writes;

        try {
            $controller->postProcess();
        } catch (StubOrderIntentResponded $e) {
            // expected: the response unwinds instead of exiting
        }

        TinyAssert::same(
            array(array('success' => true, 'available' => true)),
            $controller->emitted,
            'the endpoint must still answer the availability question'
        );
        TinyAssert::true(
            $controller->writesAtResponse > $before,
            'the registry answer must be written to the cookie BEFORE the response ends the request - '
            . 'the payment tile renders the toggle from that cookie and never resolves the registry itself'
        );
    }

    /**
     * The token-rejected path returns before any lookup, so there is no answer
     * to persist and no session to touch.
     */
    private static function testAvailabilityEndpointWritesNothingWhenTheTokenIsRejected(): void
    {
        $controller = self::makeAvailabilityController('wrongtoken', 'GB');
        $before = Context::getContext()->cookie->writes;

        try {
            $controller->postProcess();
        } catch (StubOrderIntentResponded $e) {
            // expected
        }

        TinyAssert::same($before, Context::getContext()->cookie->writes);
        // Not merely `success === false`: the controller's unknown-action branch
        // emits exactly that and also writes nothing, so this test would pass with
        // the availability case removed entirely.
        TinyAssert::same(
            array(array('success' => false, 'error' => 'Invalid token')),
            $controller->emitted,
            'a rejected token must be refused as a token, not silently as an unknown action'
        );
        // Without this, moving the token check below the registry call - an
        // unauthenticated request triggering a live outbound call - passes.
        TinyAssert::same(
            array(),
            $controller->module->requests,
            'an unauthenticated request must not reach the registry at all'
        );
    }

    /**
     * A stub accepting ANY `set*` name lets a module change calling a setter core
     * lacks pass every spec here and fatal in production - the one mismatch a stub
     * of a core value object exists to catch. This pins the allowlist as
     * load-bearing rather than decorative.
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
