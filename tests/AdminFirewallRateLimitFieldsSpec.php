<?php

declare(strict_types=1);

/**
 * ABN-490 - the Diagnostics custom request-header table that replaced the
 * single firewall-token field, plus the rate-limit controls it sits beside.
 *
 * The header list is one Configuration key holding JSON, rendered as an
 * HTML table because HelperForm has no repeatable-row field type. Two
 * properties are load-bearing and easy to lose:
 *
 *   - every configured row is sent from the server on EVERY call, including
 *     the unauthenticated order-intent path, because the firewall the rows
 *     exist to clear does not care whether Two would have authenticated the
 *     request;
 *   - only rows ticked "also send from browser" reach the browser at all,
 *     since anything published there is readable by the buyer.
 */
final class AdminFirewallRateLimitFieldsSpec
{
    public static function runAll(): void
    {
        self::testRemovedFirewallTokenFieldsAreGone();
        self::testCustomHeaderTableAndRateLimitAreInDiagnostics();
        self::testNewFieldLabelsAreSentenceCase();
        self::testHelpTextIsExact();

        self::testEveryConfiguredHeaderIsAttached();
        self::testNoHeadersConfiguredAttachesNothing();
        self::testHeadersAttachedOnUnauthenticatedRequest();
        self::testUnsendableRowsAreDropped();
        self::testOnlyBrowserFlaggedRowsReachTheBrowser();

        self::testTableRendersStoredRows();
        self::testTableEscapesStoredValues();

        self::testSaveStoresSubmittedRows();
        self::testSaveOfAnEmptiedTableStoresAnEmptyList();
        self::testSaveIgnoresAnUnrelatedPost();

        self::testValidationRejectsMalformedHeaderName();
        self::testValidationRejectsValuelessRow();
        self::testValidationRejectsLineBreakInValue();
        self::testValidationAcceptsWellFormedRows();

        self::testTrustedProxiesValidationRejectsMalformedEntry();
        self::testTrustedProxiesValidationRejectsZeroWidthCidr();
        self::testTrustedProxiesValidationAcceptsWellFormedEntries();

        self::testBrowserHeaderMapWiredIntoMediaHook();
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

    private static function diagnosticsInputs(TwopaymentTestHarness $module): array
    {
        return self::formInputsByName(
            (new ReflectionMethod(Twopayment::class, 'getTwoDiagnosticsForm'))->invoke($module)
        );
    }

    private static function generalInputs(TwopaymentTestHarness $module): array
    {
        return self::formInputsByName(
            (new ReflectionMethod(Twopayment::class, 'getTwoGeneralForm'))->invoke($module)
        );
    }

    private static function storeHeaders(array $rows): void
    {
        Configuration::updateValue(Twopayment::CONFIG_CUSTOM_HEADERS, json_encode($rows));
    }

    // ---- admin field presence ------------------------------------------------

    private static function testRemovedFirewallTokenFieldsAreGone(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::false(
            isset(self::generalInputs($module)['PS_TWO_FIREWALL_TOKEN']),
            'the single firewall-token field is replaced by the Diagnostics header table'
        );
        TinyAssert::false(
            isset(self::diagnosticsInputs($module)['PS_TWO_FIREWALL_TOKEN_BROWSER']),
            'the browser-token switch is replaced by the per-row "also send from browser" tick'
        );
    }

    private static function testCustomHeaderTableAndRateLimitAreInDiagnostics(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();
        $inputs = self::diagnosticsInputs($module);

        foreach (array(Twopayment::CONFIG_CUSTOM_HEADERS, 'PS_TWO_TRUSTED_PROXIES', 'PS_TWO_DISABLE_RATE_LIMIT') as $name) {
            TinyAssert::true(isset($inputs[$name]), $name . ' renders on the Diagnostics form');
        }
        TinyAssert::same('html', $inputs[Twopayment::CONFIG_CUSTOM_HEADERS]['type']);
        TinyAssert::same('textarea', $inputs['PS_TWO_TRUSTED_PROXIES']['type']);
        TinyAssert::same('switch', $inputs['PS_TWO_DISABLE_RATE_LIMIT']['type']);

        $general = self::generalInputs($module);
        foreach (array(Twopayment::CONFIG_CUSTOM_HEADERS, 'PS_TWO_TRUSTED_PROXIES', 'PS_TWO_DISABLE_RATE_LIMIT') as $name) {
            TinyAssert::false(isset($general[$name]), $name . ' must not also render on the General form');
        }
    }

    private static function testNewFieldLabelsAreSentenceCase(): void
    {
        self::reset();
        $inputs = self::diagnosticsInputs(new TwopaymentTestHarness());

        TinyAssert::same('Custom request headers', $inputs[Twopayment::CONFIG_CUSTOM_HEADERS]['label']);
        TinyAssert::same('Trusted proxies', $inputs['PS_TWO_TRUSTED_PROXIES']['label']);
        TinyAssert::same('Disable checkout rate limiting', $inputs['PS_TWO_DISABLE_RATE_LIMIT']['label']);
    }

    private static function testHelpTextIsExact(): void
    {
        self::reset();
        $inputs = self::diagnosticsInputs(new TwopaymentTestHarness());

        TinyAssert::same(
            'Addresses of your own reverse proxies, load balancers or CDN egress, as IPs or CIDR ranges, separated by commas or new lines. These IP addresses will be exempt from rate limiting.',
            $inputs['PS_TWO_TRUSTED_PROXIES']['desc']
        );

        // The publish-to-the-buyer warning the removed switch carried has to
        // survive on the table, since ticking a row does the same thing.
        TinyAssert::true(
            strpos(
                $inputs[Twopayment::CONFIG_CUSTOM_HEADERS]['html_content'],
                'will be published to the buyer&#039;s brower and may be read by anyone'
            ) !== false,
            'the table must still warn that a browser-ticked header is readable by anyone'
        );
    }

    // ---- request headers -----------------------------------------------------

    private static function headersFor(TwopaymentTestHarness $module, string $endpoint): array
    {
        return (new ReflectionMethod(Twopayment::class, 'getTwoRequestHeaders'))->invoke($module, $endpoint);
    }

    private static function testEveryConfiguredHeaderIsAttached(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');
        self::storeHeaders(array(
            array('name' => 'X-WAF-TOKEN', 'value' => 'waf-token-1', 'send_from_browser' => false),
            array('name' => 'X-Corp-Gate', 'value' => 'gate-2', 'send_from_browser' => true),
        ));
        $headers = self::headersFor(new TwopaymentTestHarness(), '/v1/order');

        TinyAssert::true(in_array('X-WAF-TOKEN:waf-token-1', $headers, true), 'the first configured header is attached');
        TinyAssert::true(in_array('X-Corp-Gate:gate-2', $headers, true), 'the second configured header is attached');
    }

    private static function testNoHeadersConfiguredAttachesNothing(): void
    {
        self::reset();
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'test-api-key');
        self::storeHeaders(array());

        TinyAssert::same(
            array('Content-Type: application/json; charset=utf-8', 'X-API-Key:test-api-key'),
            self::headersFor(new TwopaymentTestHarness(), '/v1/order'),
            'an empty header list must add nothing at all'
        );
    }

    private static function testHeadersAttachedOnUnauthenticatedRequest(): void
    {
        // Unlike X-Vendor-Name and X-API-Key, these are not gated on the API
        // key: the unauthenticated order-intent path still has to clear the
        // merchant's firewall.
        self::reset();
        self::storeHeaders(array(
            array('name' => 'X-WAF-TOKEN', 'value' => 'waf-token-1', 'send_from_browser' => false),
        ));

        TinyAssert::true(
            in_array('X-WAF-TOKEN:waf-token-1', self::headersFor(new TwopaymentTestHarness(), '/v1/order_intent'), true),
            'a configured header must travel on the unauthenticated order-intent path too'
        );
    }

    private static function testUnsendableRowsAreDropped(): void
    {
        self::reset();
        self::storeHeaders(array(
            array('name' => 'X-Has Space', 'value' => 'v', 'send_from_browser' => false),
            array('name' => 'X-Injects', 'value' => "v\r\nX-Evil: 1", 'send_from_browser' => false),
            array('name' => 'X-Valueless', 'value' => '', 'send_from_browser' => false),
            array('name' => 'X-Good', 'value' => 'good', 'send_from_browser' => false),
        ));

        TinyAssert::same(
            array('X-Good:good'),
            Twopayment::getTwoCustomHeaderLines(),
            'a name with a space, a value carrying CRLF and a valueless row are all unsendable and must be dropped'
        );
    }

    private static function testOnlyBrowserFlaggedRowsReachTheBrowser(): void
    {
        self::reset();
        self::storeHeaders(array(
            array('name' => 'X-Server-Only', 'value' => 'secret', 'send_from_browser' => false),
            array('name' => 'X-Corp-Gate', 'value' => 'gate-2', 'send_from_browser' => true),
        ));

        TinyAssert::same(
            array('X-Corp-Gate' => 'gate-2'),
            Twopayment::getTwoBrowserCustomHeaders(),
            'an unticked row must never be published to the buyer'
        );
    }

    // ---- the rendered table --------------------------------------------------

    private static function tableHtml(TwopaymentTestHarness $module): string
    {
        return (string) self::diagnosticsInputs($module)[Twopayment::CONFIG_CUSTOM_HEADERS]['html_content'];
    }

    private static function testTableRendersStoredRows(): void
    {
        self::reset();
        self::storeHeaders(array(
            array('name' => 'X-Server-Only', 'value' => 'secret', 'send_from_browser' => false),
            array('name' => 'X-Corp-Gate', 'value' => 'gate-2', 'send_from_browser' => true),
        ));
        $html = self::tableHtml(new TwopaymentTestHarness());

        TinyAssert::true(
            strpos($html, 'name="two_custom_header_name[0]" value="X-Server-Only"') !== false
                && strpos($html, 'name="two_custom_header_value[0]" value="secret"') !== false
                && strpos($html, 'name="two_custom_header_browser[0]">') !== false,
            'the unticked row renders with its own index and an unchecked box'
        );
        TinyAssert::true(
            strpos($html, 'name="two_custom_header_name[1]" value="X-Corp-Gate"') !== false
                && strpos($html, 'name="two_custom_header_browser[1]" checked>') !== false,
            'the ticked row renders on the next index with a checked box'
        );
        TinyAssert::true(
            strpos($html, 'name="two_custom_headers_submitted" value="1"') !== false,
            'the marker input must render, else removing the last row saves as "field absent"'
        );
    }

    private static function testTableEscapesStoredValues(): void
    {
        self::reset();
        self::storeHeaders(array(
            array('name' => 'X-Quote', 'value' => '"><script>alert(1)</script>', 'send_from_browser' => false),
        ));
        $html = self::tableHtml(new TwopaymentTestHarness());

        TinyAssert::true(strpos($html, '<script>') === false, 'a stored value must not break out of the value attribute');
        TinyAssert::true(
            strpos($html, 'value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"') !== false,
            'the stored value renders escaped'
        );
    }

    // ---- save ---------------------------------------------------------------

    private static function harnessExposingSaveAndValidate(): Twopayment
    {
        return new class() extends TwopaymentTestHarness {
            /** @return array<int,string> */
            public function validateDiagnosticsFormForTest(): array
            {
                $this->errors = array();
                $this->validTwoDiagnosticsFormValues();

                return $this->errors;
            }

            public function saveDiagnosticsFormForTest(): void
            {
                $this->saveTwoDiagnosticsFormValues();
            }
        };
    }

    private static function testSaveStoresSubmittedRows(): void
    {
        self::reset();
        Tools::setTestValue('two_custom_headers_submitted', '1');
        Tools::setTestValue('two_custom_header_name', array(0 => ' X-WAF-TOKEN ', 3 => 'X-Corp-Gate', 7 => '  '));
        Tools::setTestValue('two_custom_header_value', array(0 => ' waf-token-1 ', 3 => 'gate-2', 7 => ''));
        Tools::setTestValue('two_custom_header_browser', array(3 => '1'));

        self::harnessExposingSaveAndValidate()->saveDiagnosticsFormForTest();

        TinyAssert::same(
            array(
                array('name' => 'X-WAF-TOKEN', 'value' => 'waf-token-1', 'send_from_browser' => false),
                array('name' => 'X-Corp-Gate', 'value' => 'gate-2', 'send_from_browser' => true),
            ),
            json_decode((string) Configuration::get(Twopayment::CONFIG_CUSTOM_HEADERS), true),
            'submitted rows are trimmed, reindexed, and the wholly blank row dropped'
        );
    }

    private static function testSaveOfAnEmptiedTableStoresAnEmptyList(): void
    {
        self::reset();
        self::storeHeaders(array(array('name' => 'X-WAF-TOKEN', 'value' => 'waf-token-1', 'send_from_browser' => false)));
        // Removing every row leaves no header inputs in the POST at all.
        Tools::setTestValue('two_custom_headers_submitted', '1');

        self::harnessExposingSaveAndValidate()->saveDiagnosticsFormForTest();

        TinyAssert::same(
            array(),
            json_decode((string) Configuration::get(Twopayment::CONFIG_CUSTOM_HEADERS), true),
            'an emptied table must save as an empty list, not leave the old rows in place'
        );
    }

    private static function testSaveIgnoresAnUnrelatedPost(): void
    {
        self::reset();
        self::storeHeaders(array(array('name' => 'X-WAF-TOKEN', 'value' => 'waf-token-1', 'send_from_browser' => false)));

        self::harnessExposingSaveAndValidate()->saveDiagnosticsFormForTest();

        TinyAssert::same(
            array(array('name' => 'X-WAF-TOKEN', 'value' => 'waf-token-1', 'send_from_browser' => false)),
            json_decode((string) Configuration::get(Twopayment::CONFIG_CUSTOM_HEADERS), true),
            'a POST carrying no header table must leave the stored rows alone'
        );
    }

    // ---- save validation -----------------------------------------------------

    private static function validate(): array
    {
        return self::harnessExposingSaveAndValidate()->validateDiagnosticsFormForTest();
    }

    private static function testValidationRejectsMalformedHeaderName(): void
    {
        self::reset();
        Tools::setTestValue('two_custom_headers_submitted', '1');
        Tools::setTestValue('two_custom_header_name', array('X-Has Space'));
        Tools::setTestValue('two_custom_header_value', array('v'));

        TinyAssert::true(count(self::validate()) > 0, 'a header name outside the RFC 7230 token set must be refused');
    }

    private static function testValidationRejectsValuelessRow(): void
    {
        self::reset();
        Tools::setTestValue('two_custom_headers_submitted', '1');
        Tools::setTestValue('two_custom_header_name', array('X-WAF-TOKEN'));
        Tools::setTestValue('two_custom_header_value', array(''));

        TinyAssert::true(
            count(self::validate()) > 0,
            'a named row with no value would be silently dropped from the header list, so it must be refused instead'
        );
    }

    private static function testValidationRejectsLineBreakInValue(): void
    {
        self::reset();
        Tools::setTestValue('two_custom_headers_submitted', '1');
        Tools::setTestValue('two_custom_header_name', array('X-WAF-TOKEN'));
        Tools::setTestValue('two_custom_header_value', array("token\r\nX-Evil: 1"));

        TinyAssert::true(count(self::validate()) > 0, 'a value that could split the request must be refused');
    }

    private static function testValidationAcceptsWellFormedRows(): void
    {
        self::reset();
        Tools::setTestValue('two_custom_headers_submitted', '1');
        Tools::setTestValue('two_custom_header_name', array('X-WAF-TOKEN', 'X-Corp-Gate'));
        Tools::setTestValue('two_custom_header_value', array('waf-token-1', 'gate-2'));
        Tools::setTestValue('two_custom_header_browser', array(1 => '1'));

        TinyAssert::count(0, self::validate(), 'well-formed header rows must not be refused');
    }

    // ---- trusted-proxies save validation -------------------------------------

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

    // ---- browser-facing config (source-inspection, same style as the
    // existing Media-hook coverage in CompanySearchCountrySourcingSpec) -----

    private static function testBrowserHeaderMapWiredIntoMediaHook(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/twopayment.php');
        TinyAssert::true(is_string($source) && $source !== '', 'could not read twopayment.php');

        $start = strpos($source, 'public function hookActionFrontControllerSetMedia()');
        TinyAssert::true($start !== false, 'hookActionFrontControllerSetMedia() no longer exists');
        $body = substr($source, $start, 20000);

        TinyAssert::true(
            strpos($body, "'custom_headers' => self::getTwoBrowserCustomHeaders()") !== false,
            'the browser-facing config must publish only the browser-flagged rows'
        );
    }
}
