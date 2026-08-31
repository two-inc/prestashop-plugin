<?php

declare(strict_types=1);

/**
 * TWO-25386 - admin field presence/grouping/casing for the firewall token
 * and rate-limit controls, plus the request-header and validation wiring.
 * Mirrors woocommerce-plugin/magento-plugin: firewall token stays in
 * General, trusted proxies + the browser-token toggle + the rate-limit
 * escape hatch live in Diagnostics.
 */
final class AdminFirewallRateLimitFieldsSpec
{
    public static function runAll(): void
    {
        self::testFirewallTokenIsInGeneralForm();
        self::testTrustedProxiesAndBrowserToggleAndRateLimitAreInDiagnostics();
        self::testNewFieldLabelsAreSentenceCase();
        self::testHelpTextIsExact();

        self::testFirewallTokenHeaderAttachedWhenConfigured();
        self::testFirewallTokenHeaderOmittedWhenBlank();
        self::testFirewallTokenHeaderAttachedOnUnauthenticatedRequest();

        self::testTrustedProxiesValidationRejectsMalformedEntry();
        self::testTrustedProxiesValidationRejectsZeroWidthCidr();
        self::testTrustedProxiesValidationAcceptsWellFormedEntries();

        self::testBrowserTokenGatingWiredIntoMediaHook();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
    }

    private static function formInputsByName(array $form): array
    {
        $byName = array();
        foreach ($form['form']['input'] as $input) {
            if (isset($input['name'])) {
                $byName[$input['name']] = $input;
            }
        }

        return $byName;
    }

    private static function testFirewallTokenIsInGeneralForm(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $method = new ReflectionMethod(Twopayment::class, 'getTwoGeneralForm');
        $inputs = self::formInputsByName($method->invoke($module));

        TinyAssert::true(isset($inputs['PS_TWO_FIREWALL_TOKEN']), 'Firewall token stays in the General form');
        TinyAssert::same('text', $inputs['PS_TWO_FIREWALL_TOKEN']['type']);
    }

    private static function testTrustedProxiesAndBrowserToggleAndRateLimitAreInDiagnostics(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $method = new ReflectionMethod(Twopayment::class, 'getTwoDiagnosticsForm');
        $inputs = self::formInputsByName($method->invoke($module));

        foreach (['PS_TWO_TRUSTED_PROXIES', 'PS_TWO_FIREWALL_TOKEN_BROWSER', 'PS_TWO_DISABLE_RATE_LIMIT'] as $name) {
            TinyAssert::true(isset($inputs[$name]), $name . ' renders on the Diagnostics form');
        }
        TinyAssert::same('textarea', $inputs['PS_TWO_TRUSTED_PROXIES']['type']);
        TinyAssert::same('switch', $inputs['PS_TWO_FIREWALL_TOKEN_BROWSER']['type']);
        TinyAssert::same('switch', $inputs['PS_TWO_DISABLE_RATE_LIMIT']['type']);

        $generalMethod = new ReflectionMethod(Twopayment::class, 'getTwoGeneralForm');
        $generalInputs = self::formInputsByName($generalMethod->invoke($module));
        foreach (['PS_TWO_TRUSTED_PROXIES', 'PS_TWO_FIREWALL_TOKEN_BROWSER', 'PS_TWO_DISABLE_RATE_LIMIT'] as $name) {
            TinyAssert::false(isset($generalInputs[$name]), $name . ' must not also render on the General form');
        }
    }

    private static function testNewFieldLabelsAreSentenceCase(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $diagInputs = self::formInputsByName(
            (new ReflectionMethod(Twopayment::class, 'getTwoDiagnosticsForm'))->invoke($module)
        );
        $generalInputs = self::formInputsByName(
            (new ReflectionMethod(Twopayment::class, 'getTwoGeneralForm'))->invoke($module)
        );

        TinyAssert::same('Firewall token (optional)', $generalInputs['PS_TWO_FIREWALL_TOKEN']['label']);
        TinyAssert::same('Trusted proxies', $diagInputs['PS_TWO_TRUSTED_PROXIES']['label']);
        TinyAssert::same('Add firewall token to browser-originated traffic', $diagInputs['PS_TWO_FIREWALL_TOKEN_BROWSER']['label']);
        TinyAssert::same('Disable checkout rate limiting', $diagInputs['PS_TWO_DISABLE_RATE_LIMIT']['label']);
    }

    private static function testHelpTextIsExact(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $diagInputs = self::formInputsByName(
            (new ReflectionMethod(Twopayment::class, 'getTwoDiagnosticsForm'))->invoke($module)
        );

        TinyAssert::same(
            'Addresses of your own reverse proxies, load balancers or CDN egress, as IPs or CIDR ranges, separated by commas or new lines. These IP addresses will be exempt from rate limiting.',
            $diagInputs['PS_TWO_TRUSTED_PROXIES']['desc']
        );
        TinyAssert::same(
            "Only switch this on if your IT administrator requires the firewall token for calls from the user's browser as well as those from your server. Your firewall token will be published to the buyer's brower and may be read by anyone.",
            $diagInputs['PS_TWO_FIREWALL_TOKEN_BROWSER']['desc']
        );
    }

    // ---- request headers ----------------------------------------------------

    private static function headersFor(TwopaymentTestHarness $module, string $endpoint): array
    {
        $method = new ReflectionMethod(Twopayment::class, 'getTwoRequestHeaders');

        return $method->invoke($module, $endpoint);
    }

    private static function firewallHeader(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (strpos($header, 'X-WAF-TOKEN:') === 0) {
                return $header;
            }
        }

        return null;
    }

    private static function testFirewallTokenHeaderAttachedWhenConfigured(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');
        Configuration::updateValue('PS_TWO_FIREWALL_TOKEN', 'waf-token-1');
        $module = new TwopaymentTestHarness();

        TinyAssert::same('X-WAF-TOKEN:waf-token-1', self::firewallHeader(self::headersFor($module, '/v1/order')));
    }

    private static function testFirewallTokenHeaderOmittedWhenBlank(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');
        Configuration::updateValue('PS_TWO_FIREWALL_TOKEN', '');
        $module = new TwopaymentTestHarness();

        TinyAssert::true(self::firewallHeader(self::headersFor($module, '/v1/order')) === null);
    }

    private static function testFirewallTokenHeaderAttachedOnUnauthenticatedRequest(): void
    {
        // Unlike X-Vendor-Name and X-API-Key, the firewall token is not
        // gated on $includeApiKey - the unauthenticated order-intent preview
        // path still has to clear the firewall.
        self::reset();
        Configuration::updateValue('PS_TWO_FIREWALL_TOKEN', 'waf-token-1');
        $module = new TwopaymentTestHarness();

        TinyAssert::same(
            'X-WAF-TOKEN:waf-token-1',
            self::firewallHeader(self::headersFor($module, '/v1/order_intent'))
        );
    }

    // ---- trusted-proxies save validation -------------------------------------

    private static function validate(): array
    {
        $module = new class() extends TwopaymentTestHarness {
            /** @return array<int,string> */
            public function validateDiagnosticsFormForTest(): array
            {
                $this->errors = array();
                $this->validTwoDiagnosticsFormValues();

                return $this->errors;
            }
        };

        return $module->validateDiagnosticsFormForTest();
    }

    private static function testTrustedProxiesValidationRejectsMalformedEntry(): void
    {
        self::reset();
        Tools::setTestValue('PS_TWO_TRUSTED_PROXIES', 'not-an-address');

        TinyAssert::true(count(self::validate()) > 0, 'a malformed proxy entry must be refused at save time');
    }

    private static function testTrustedProxiesValidationRejectsZeroWidthCidr(): void
    {
        self::reset();
        Tools::setTestValue('PS_TWO_TRUSTED_PROXIES', '0.0.0.0/0');

        TinyAssert::true(count(self::validate()) > 0, 'a /0 entry must be refused, not silently stored as exempting every caller');
    }

    private static function testTrustedProxiesValidationAcceptsWellFormedEntries(): void
    {
        self::reset();
        Tools::setTestValue('PS_TWO_TRUSTED_PROXIES', "10.0.0.0/8\n2001:db8::/32");

        TinyAssert::count(0, self::validate(), 'well-formed CIDR entries must not be refused');
    }

    // ---- browser-token gating (source-inspection, same style as the
    // existing Media-hook coverage in CompanySearchCountrySourcingSpec) -----

    private static function testBrowserTokenGatingWiredIntoMediaHook(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/twopayment.php');
        TinyAssert::true(is_string($source) && $source !== '', 'could not read twopayment.php');

        $start = strpos($source, 'public function hookActionFrontControllerSetMedia()');
        TinyAssert::true($start !== false, 'hookActionFrontControllerSetMedia() no longer exists');
        $body = substr($source, $start, 20000);

        TinyAssert::true(
            strpos($body, "'firewall_token' =>") !== false
                && strpos($body, 'PS_TWO_FIREWALL_TOKEN_BROWSER') !== false
                && strpos($body, 'getTwoFirewallToken()') !== false,
            'the browser-facing config no longer gates the firewall token on the browser-token toggle'
        );
    }
}
