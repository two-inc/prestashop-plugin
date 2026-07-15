<?php

declare(strict_types=1);

/**
 * Unit spec for the sole-trader business logic (TWO-24755): the two gates
 * (registry endpoint + merchant toggle, both riding account-type mode),
 * registry-only response semantics (empty list = business-only checkout),
 * fail-soft registry handling, fail-closed token minting, the account_type
 * gate matrix, and the third option on the address-form selector.
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

    public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
    {
        $this->requests[] = $endpoint;
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
            'testAvailableWhenRegistryAndToggleAgree',
            'testHiddenWhenToggleOff',
            'testHiddenWhenAccountTypeModeOff',
            'testHiddenWhenRegistryOmitsIt',
            'testRegistryErrorFallsBackToNoSoleTrader',
            'testRegistryRejectsMalformedCountry',
            'testRegistryResponseCachedPerRequest',
            'testCookieCacheIsSingleSlotAndOverwritesOnCountryChange',
            'testCookieCacheExpiresAfterTtl',
            'testFetchErrorIsNotCached',
            'testAccountTypeAllowedMatrix',
            'testTokenMintReadsHeaderAndFailsClosed',
            'testSignupUrlFollowsEnvironment',
            'testFormatterAddsThirdOptionOnlyWhenAvailable',
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
        StubStore::$moduleInstance = $module;
        return $module;
    }

    private static function registryOk(array $types): array
    {
        return [
            'http_status' => 200,
            'supported_company_types' => $types,
        ];
    }

    private static function testAvailableWhenRegistryAndToggleAgree(): void
    {
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );
        TinyAssert::true(TwoSoleTrader::isAvailable($module, 'GB'));
        // Lowercase input normalises to the same country
        TwoSoleTrader::resetCache();
        TinyAssert::true(TwoSoleTrader::isAvailable($module, 'gb'));
    }

    private static function testHiddenWhenToggleOff(): void
    {
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 0, 'PS_TWO_USE_ACCOUNT_TYPE' => 1],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );
        TinyAssert::false(TwoSoleTrader::isAvailable($module, 'GB'));
    }

    private static function testHiddenWhenAccountTypeModeOff(): void
    {
        // The option rides the account_type selector; without that mode the
        // feature cannot surface no matter what the toggle says.
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 0],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );
        TinyAssert::false(TwoSoleTrader::isAvailable($module, 'GB'));
    }

    private static function testHiddenWhenRegistryOmitsIt(): void
    {
        // Registered businesses need no registry enrollment, so the endpoint
        // deliberately omits them: an empty list means business-only checkout.
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1],
            ['/registry/v1/supported-company-types/' => self::registryOk([])]
        );
        TinyAssert::false(TwoSoleTrader::isAvailable($module, 'NO'));
    }

    private static function testRegistryErrorFallsBackToNoSoleTrader(): void
    {
        // Network error (transport returns false)
        $module = self::harness(['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1], []);
        TinyAssert::same([], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));

        // Non-200
        TwoSoleTrader::resetCache();
        StubStore::reset();
        Configuration::updateValue('PS_TWO_ENABLE_SOLE_TRADER', 1);
        $module->cannedResponses = [
            '/registry/v1/supported-company-types/' => ['http_status' => 404],
        ];
        TinyAssert::same([], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));

        // 200 with malformed body
        TwoSoleTrader::resetCache();
        StubStore::reset();
        Configuration::updateValue('PS_TWO_ENABLE_SOLE_TRADER', 1);
        $module->cannedResponses = [
            '/registry/v1/supported-company-types/' => ['http_status' => 200, 'data' => 'junk'],
        ];
        TinyAssert::same([], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));
    }

    private static function testRegistryRejectsMalformedCountry(): void
    {
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1],
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
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );
        TwoSoleTrader::getSupportedCompanyTypes($module, 'GB');
        TwoSoleTrader::getSupportedCompanyTypes($module, 'GB');
        TwoSoleTrader::getSupportedCompanyTypes($module, 'gb');
        TinyAssert::count(1, $module->requests);
        // A different country is its own cache entry
        TwoSoleTrader::getSupportedCompanyTypes($module, 'US');
        TinyAssert::count(2, $module->requests);
    }

    /**
     * The cookie cache is a single slot (TWO-24755 F3): switching country
     * overwrites it rather than growing a new key per country, capping the
     * PrestaShop session cookie's growth regardless of how many countries
     * a caller requests.
     */
    private static function testCookieCacheIsSingleSlotAndOverwritesOnCountryChange(): void
    {
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1],
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
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1],
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
     * A registry fetch ERROR must not be cached (TWO-24755 Y3) - otherwise
     * a single transient blip suppresses the sole-trader option for the
     * rest of the TTL window, indistinguishable from a real business-only
     * country.
     */
    private static function testFetchErrorIsNotCached(): void
    {
        $module = self::harness(['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1], []);
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

    private static function testAccountTypeAllowedMatrix(): void
    {
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1],
            ['/registry/v1/supported-company-types/GB' => self::registryOk(['SOLE_TRADER']),
             '/registry/v1/supported-company-types/NO' => self::registryOk([])]
        );
        TinyAssert::true(TwoSoleTrader::isAccountTypeAllowed($module, 'business', 'NO'));
        TinyAssert::true(TwoSoleTrader::isAccountTypeAllowed($module, 'sole_trader', 'GB'));
        TinyAssert::false(TwoSoleTrader::isAccountTypeAllowed($module, 'sole_trader', 'NO'));
        TinyAssert::false(TwoSoleTrader::isAccountTypeAllowed($module, 'personal', 'GB'));
        TinyAssert::false(TwoSoleTrader::isAccountTypeAllowed($module, '', 'GB'));

        // Toggle off: sole_trader rejected even for GB, business still fine
        Configuration::updateValue('PS_TWO_ENABLE_SOLE_TRADER', 0);
        TwoSoleTrader::resetCache();
        TinyAssert::false(TwoSoleTrader::isAccountTypeAllowed($module, 'sole_trader', 'GB'));
        TinyAssert::true(TwoSoleTrader::isAccountTypeAllowed($module, 'business', 'GB'));
    }

    private static function testTokenMintReadsHeaderAndFailsClosed(): void
    {
        $module = self::harness(['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1], []);

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

    private static function testFormatterAddsThirdOptionOnlyWhenAvailable(): void
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

        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );

        $country = new Country();
        $country->iso_code = 'GB';
        $format = (new CustomerAddressFormatter($country, $translator, []))->getFormat();
        TinyAssert::true(isset($format['account_type']), 'Expected account_type field');
        TinyAssert::same('Sole trader', $format['account_type']->getAvailableValue('sole_trader'), 'Expected sole_trader option for GB with toggle on');

        // Unsupported country: no third option
        TwoSoleTrader::resetCache();
        $module->cannedResponses = ['/registry/v1/supported-company-types/' => self::registryOk([])];
        $country = new Country();
        $country->iso_code = 'NO';
        $format = (new CustomerAddressFormatter($country, $translator, []))->getFormat();
        TinyAssert::same(null, $format['account_type']->getAvailableValue('sole_trader'), 'No sole_trader option for NO');

        // Toggle off: no third option even for GB
        Configuration::updateValue('PS_TWO_ENABLE_SOLE_TRADER', 0);
        TwoSoleTrader::resetCache();
        $country = new Country();
        $country->iso_code = 'GB';
        $format = (new CustomerAddressFormatter($country, $translator, []))->getFormat();
        TinyAssert::same(null, $format['account_type']->getAvailableValue('sole_trader'), 'No sole_trader option when toggle off');
    }
}
