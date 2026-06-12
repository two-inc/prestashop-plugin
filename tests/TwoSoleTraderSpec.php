<?php

declare(strict_types=1);

/**
 * Unit spec for the sole-trader business logic (TWO-24755): both gates
 * (registry endpoint + merchant toggle), fail-soft registry handling,
 * fail-closed token minting, and the account_type form option.
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

    public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
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
            'testRegistryErrorFallsBackToRegisteredBusiness',
            'testRegistryRejectsMalformedCountry',
            'testRegistryResponseCachedPerRequest',
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
            ['/registry/v1/supported-company-types/' => self::registryOk(['REGISTERED_BUSINESS', 'SOLE_TRADER'])]
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
            ['/registry/v1/supported-company-types/' => self::registryOk(['REGISTERED_BUSINESS', 'SOLE_TRADER'])]
        );
        TinyAssert::false(TwoSoleTrader::isAvailable($module, 'GB'));
    }

    private static function testHiddenWhenAccountTypeModeOff(): void
    {
        // The option rides the account_type selector; without that mode the
        // feature cannot surface no matter what the toggle says.
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 0],
            ['/registry/v1/supported-company-types/' => self::registryOk(['REGISTERED_BUSINESS', 'SOLE_TRADER'])]
        );
        TinyAssert::false(TwoSoleTrader::isAvailable($module, 'GB'));
    }

    private static function testHiddenWhenRegistryOmitsIt(): void
    {
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1],
            ['/registry/v1/supported-company-types/' => self::registryOk(['REGISTERED_BUSINESS'])]
        );
        TinyAssert::false(TwoSoleTrader::isAvailable($module, 'NO'));
    }

    private static function testRegistryErrorFallsBackToRegisteredBusiness(): void
    {
        // Network error (transport returns false)
        $module = self::harness(['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1], []);
        TinyAssert::same(['REGISTERED_BUSINESS'], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));

        // Non-200
        TwoSoleTrader::resetCache();
        $module->cannedResponses = [
            '/registry/v1/supported-company-types/' => ['http_status' => 404],
        ];
        TinyAssert::same(['REGISTERED_BUSINESS'], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));

        // 200 with malformed body
        TwoSoleTrader::resetCache();
        $module->cannedResponses = [
            '/registry/v1/supported-company-types/' => ['http_status' => 200, 'data' => 'junk'],
        ];
        TinyAssert::same(['REGISTERED_BUSINESS'], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));
    }

    private static function testRegistryRejectsMalformedCountry(): void
    {
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1],
            ['/registry/v1/supported-company-types/' => self::registryOk(['REGISTERED_BUSINESS', 'SOLE_TRADER'])]
        );
        // Never hits the API for junk country input
        TinyAssert::same(['REGISTERED_BUSINESS'], TwoSoleTrader::getSupportedCompanyTypes($module, ''));
        TinyAssert::same(['REGISTERED_BUSINESS'], TwoSoleTrader::getSupportedCompanyTypes($module, 'G'));
        TinyAssert::same(['REGISTERED_BUSINESS'], TwoSoleTrader::getSupportedCompanyTypes($module, 'GBR'));
        TinyAssert::count(0, $module->requests);
    }

    private static function testRegistryResponseCachedPerRequest(): void
    {
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1],
            ['/registry/v1/supported-company-types/' => self::registryOk(['REGISTERED_BUSINESS', 'SOLE_TRADER'])]
        );
        TwoSoleTrader::getSupportedCompanyTypes($module, 'GB');
        TwoSoleTrader::getSupportedCompanyTypes($module, 'GB');
        TwoSoleTrader::getSupportedCompanyTypes($module, 'gb');
        TinyAssert::count(1, $module->requests);
        // A different country is its own cache entry
        TwoSoleTrader::getSupportedCompanyTypes($module, 'US');
        TinyAssert::count(2, $module->requests);
    }

    private static function testAccountTypeAllowedMatrix(): void
    {
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1, 'PS_TWO_USE_ACCOUNT_TYPE' => 1],
            ['/registry/v1/supported-company-types/' => self::registryOk(['REGISTERED_BUSINESS', 'SOLE_TRADER'])]
        );
        TinyAssert::true(TwoSoleTrader::isAccountTypeAllowed($module, 'business', 'NO'));
        TinyAssert::true(TwoSoleTrader::isAccountTypeAllowed($module, 'sole_trader', 'GB'));
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
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'PRODUCTION');
        TinyAssert::same('https://checkout.two.inc/soletrader/signup', TwoSoleTrader::getSignupPageUrl());

        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'SANDBOX');
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
            ['/registry/v1/supported-company-types/' => self::registryOk(['REGISTERED_BUSINESS', 'SOLE_TRADER'])]
        );

        $country = new Country();
        $country->iso_code = 'GB';
        $formatter = new CustomerAddressFormatter($country, $translator, []);
        $format = $formatter->getFormat();
        TinyAssert::true(isset($format['account_type']), 'Expected account_type field');
        TinyAssert::same('Sole trader', $format['account_type']->getAvailableValue('sole_trader'), 'Expected sole_trader option for GB with toggle on');

        // Unsupported country: no third option
        TwoSoleTrader::resetCache();
        $module->cannedResponses = ['/registry/v1/supported-company-types/' => self::registryOk(['REGISTERED_BUSINESS'])];
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
