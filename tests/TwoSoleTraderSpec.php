<?php

declare(strict_types=1);

/**
 * Unit spec for the sole-trader business logic (TWO-24755): the two gates
 * (registry endpoint + merchant toggle), fail-soft registry handling,
 * fail-closed token minting, and that the address form carries no
 * account-type selector (sole traders enrol via the payment-step toggle).
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
            'testEnabledFollowsToggleOnly',
            'testHiddenWhenToggleOff',
            'testHiddenWhenRegistryOmitsIt',
            'testRegistryErrorFallsBackToNoSoleTrader',
            'testRegistryRejectsMalformedCountry',
            'testRegistryResponseCachedPerRequest',
            'testTokenMintReadsHeaderAndFailsClosed',
            'testSignupUrlFollowsEnvironment',
            'testFormatterHasNoAccountTypeField',
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
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1],
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
            ['PS_TWO_ENABLE_SOLE_TRADER' => 0],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );
        TinyAssert::false(TwoSoleTrader::isAvailable($module, 'GB'));
    }

    private static function testEnabledFollowsToggleOnly(): void
    {
        // The feature is gated solely by the merchant toggle now — there is
        // no account-type mode to also satisfy (TWO-24755 alignment).
        self::harness(['PS_TWO_ENABLE_SOLE_TRADER' => 1], []);
        TinyAssert::true(TwoSoleTrader::isEnabled());

        Configuration::updateValue('PS_TWO_ENABLE_SOLE_TRADER', 0);
        TinyAssert::false(TwoSoleTrader::isEnabled());
    }

    private static function testHiddenWhenRegistryOmitsIt(): void
    {
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1],
            ['/registry/v1/supported-company-types/' => self::registryOk([])]
        );
        TinyAssert::false(TwoSoleTrader::isAvailable($module, 'NO'));
    }

    private static function testRegistryErrorFallsBackToNoSoleTrader(): void
    {
        // Network error (transport returns false)
        $module = self::harness(['PS_TWO_ENABLE_SOLE_TRADER' => 1], []);
        TinyAssert::same([], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));

        // Non-200
        TwoSoleTrader::resetCache();
        $module->cannedResponses = [
            '/registry/v1/supported-company-types/' => ['http_status' => 404],
        ];
        TinyAssert::same([], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));

        // 200 with malformed body
        TwoSoleTrader::resetCache();
        $module->cannedResponses = [
            '/registry/v1/supported-company-types/' => ['http_status' => 200, 'data' => 'junk'],
        ];
        TinyAssert::same([], TwoSoleTrader::getSupportedCompanyTypes($module, 'GB'));
    }

    private static function testRegistryRejectsMalformedCountry(): void
    {
        $module = self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1],
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
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1],
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

    private static function testTokenMintReadsHeaderAndFailsClosed(): void
    {
        $module = self::harness(['PS_TWO_ENABLE_SOLE_TRADER' => 1], []);

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

        // Even with sole trader enabled and the registry returning it, the
        // address form carries no account-type selector — buyers always
        // enter company details (B2B) and sole traders enrol via the
        // payment-step toggle (TWO-24755).
        self::harness(
            ['PS_TWO_ENABLE_SOLE_TRADER' => 1],
            ['/registry/v1/supported-company-types/' => self::registryOk(['SOLE_TRADER'])]
        );

        $country = new Country();
        $country->iso_code = 'GB';
        $format = (new CustomerAddressFormatter($country, $translator, []))->getFormat();
        TinyAssert::false(isset($format['account_type']), 'Address form must not add an account_type field');
        TinyAssert::true(isset($format['company']), 'Company field is still present for B2B checkout');
    }
}
