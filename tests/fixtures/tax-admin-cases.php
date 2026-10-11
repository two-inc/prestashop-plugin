<?php

declare(strict_types=1);

/**
 * The "Tax codes for 0% lines" admin cases (TWO-26153), shared by tests/TaxCodeAdminSpec.php and the render baseline
 * (dev/tax-admin-baseline.php), which also runs them against the base commit. So everything here uses only what the
 * base commit has too: StubStore, Configuration and the two wire seams, setTwoPaymentRequest() and
 * requestTwoApiKeyVerification().
 *
 * Each case is [name, setup, responses]. The setup seeds a shop; the responses script the wire:
 *   'verify'   => [http, body] for GET /v1/merchant/verify_api_key (body null for a transport failure)
 *   'merchant' => [http, body] for GET /v1/merchant/<id>
 *   'codes'    => [country => [http, body]] for GET /v1/tax_codes/<country>
 */

/** The installed module with its two wire seams scripted, recording what it asked. */
class TaxAdminFixtureModule extends TwopaymentTestHarness
{
    /** @var string[] */
    public $requested = array();
    /** @var array */
    public $responses = array();

    public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
    {
        $this->requested[] = $method . ' ' . $endpoint;
        if (preg_match('#^/v1/tax_codes/([A-Z]{2})$#', $endpoint, $m) === 1) {
            $scripted = isset($this->responses['codes'][$m[1]]) ? $this->responses['codes'][$m[1]] : array(503, array());
        } elseif (strpos($endpoint, '/v1/merchant/') === 0) {
            $scripted = isset($this->responses['merchant']) ? $this->responses['merchant'] : array(503, array());
        } else {
            $scripted = array(404, array());
        }
        list($status, $body) = $scripted;

        return array_merge(array('http_status' => $status, 'data' => $body), is_array($body) ? $body : array());
    }

    /**
     * The Order management save as the configuration page's postProcess() runs it: validate, then save only without
     * errors.
     *
     * @return string[] the validation errors
     */
    public function saveOrderManagement()
    {
        $this->errors = array();
        $this->validTwoOrderManagementFormValues();
        if ($this->errors === array()) {
            $this->saveTwoOrderManagementFormValues();
        }

        return $this->errors;
    }

    protected function requestTwoApiKeyVerification($apiKey, $environment, $timeout = null)
    {
        $this->requested[] = 'GET /v1/merchant/verify_api_key';
        list($status, $body) = isset($this->responses['verify']) ? $this->responses['verify'] : array(200, array('id' => 'merchant-1', 'short_name' => 'shop'));
        if ($body === null) {
            return array('response' => false, 'code' => 0, 'error' => 'Could not resolve host');
        }

        return array('response' => json_encode($body), 'code' => $status, 'error' => '');
    }
}

final class TaxAdminCases
{
    const GROUP_ZERO = 8101;
    const GROUP_STANDARD = 8102;
    const RULE_CANARIES = 8201;
    const RULE_STANDARD = 8202;
    const RULE_EXPORT = 8203;
    const TAX_ZERO = 9811;
    const TAX_21 = 9812;
    const COUNTRIES = array(34 => 'ES', 117 => 'GB', 21 => 'US', 10 => 'IT', 17 => 'NO', 8 => 'FR');

    /** @return array<string,array> GET /v1/tax_codes/<country> answers */
    public static function codeLists()
    {
        $entry = static function ($code, $name, $rate) {
            return array('code' => $code, 'display_name' => $name, 'rate' => $rate, 'requires_exemption_reason' => false, 'exemption_reason_code' => null);
        };

        return array(
            'ES' => array(200, array(
                'data' => array(
                    $entry('ES_IVA_EXPORT', 'Exportación', '0'),
                    $entry('ES_IVA_INTRA_COMMUNITY', 'Entrega intracomunitaria', '0'),
                    $entry('ES_IVA_EXEMPT_ART20', 'Exento art. 20', '0'),
                    $entry('ES_IVA_STANDARD', 'IVA General (21%)', '0.21'),
                ),
                'country_code' => 'ES',
                'metadata' => array('mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => array('0'), 'credit_notes_require_line_items' => false),
            )),
            'GB' => array(200, array(
                'data' => array(
                    $entry('GB_VAT_ZERO', 'Zero rated', '0'),
                    $entry('GB_VAT_REVERSE_CHARGE', 'Reverse charge', '0'),
                    $entry('GB_VAT_EXEMPT', 'Exempt', '0'),
                    $entry('GB_VAT_STANDARD', 'Standard', '0.2'),
                    $entry('GB_VAT_REDUCED', 'Reduced', '0.05'),
                ),
                'country_code' => 'GB',
                'metadata' => array('mandatory_tax_codes_enabled' => false, 'mandatory_tax_rates' => array(), 'credit_notes_require_line_items' => false),
            )),
            'NO' => array(200, array(
                'data' => array($entry('NO_MVA_EXPORT', 'Utførsel', '0'), $entry('NO_MVA_STANDARD', 'Standard', '0.25')),
                'country_code' => 'NO',
                'metadata' => array('mandatory_tax_codes_enabled' => true, 'mandatory_tax_rates' => array('0.00'), 'credit_notes_require_line_items' => false),
            )),
            'IT' => array(400, array('error_code' => 'COUNTRY_NOT_SUPPORTED', 'error_message' => 'Tax codes are not supported for country: IT')),
        );
    }

    /**
     * A single-shop install with an API key, the merchant record checkout holds for it, two tax rules groups (one with
     * two 0% rules, one with a 21% rule only) and the given stored mapping.
     *
     * @param string $country the merchant country checkout holds
     * @param string|null $map the stored PS_TWO_TAX_CODE_MAP, null for none
     */
    public static function seed($country, $map = null)
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
        foreach (self::COUNTRIES as $id => $iso) {
            StubStore::$countries[$id] = $iso;
        }
        StubStore::$taxes[self::TAX_ZERO] = array('name' => 'IVA 0%', 'rate' => 0, 'active' => 1);
        StubStore::$taxes[self::TAX_21] = array('name' => 'IVA 21%', 'rate' => 21, 'active' => 1);
        StubStore::$taxRulesGroups = array(
            self::GROUP_ZERO => array('name' => 'Books', 'active' => 1),
            self::GROUP_STANDARD => array('name' => 'Standard', 'active' => 1),
        );
        StubStore::$taxRules = array(
            self::RULE_CANARIES => array('id_tax_rules_group' => self::GROUP_ZERO, 'id_country' => 34, 'zipcode_from' => '35000', 'zipcode_to' => '35999', 'id_tax' => 0),
            self::RULE_EXPORT => array('id_tax_rules_group' => self::GROUP_ZERO, 'id_country' => 21, 'id_tax' => self::TAX_ZERO, 'description' => 'Exports'),
            self::RULE_STANDARD => array('id_tax_rules_group' => self::GROUP_STANDARD, 'id_country' => 34, 'id_tax' => self::TAX_21),
        );
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-1');
        Configuration::updateValue('PS_TWO_MERCHANT_ID', 'merchant-1');
        Configuration::updateValue('PS_TWO_MERCHANT_SHORT_NAME', 'shop');
        Configuration::updateValue('PS_TWO_MERCHANT_COUNTRY', $country);
        Configuration::updateValue('PS_TWO_MERCHANT_INVOICE_DISTRIBUTED', '0');
        Configuration::updateValue('PS_TWO_MERCHANT_RECORD_KEY', TwopaymentTestHarness::recordKeyStampForTest('key-1'));
        if ($map !== null) {
            Configuration::updateValue('PS_TWO_TAX_CODE_MAP', $map);
        }
    }

    /**
     * Switch the stub shop to multistore with shops 1 and 2 in group 1, in shop 1's context.
     */
    public static function multistore()
    {
        StubStore::$multistore = true;
        StubStore::$shops = array(1 => 1, 2 => 1);
        Shop::setContext(Shop::CONTEXT_SHOP, 1);
    }

    /**
     * @param string $country
     * @param string $merchantId
     * @return array the scripted responses for a healthy merchant in $country
     */
    public static function healthy($country, $merchantId = 'merchant-1')
    {
        return array(
            'verify' => array(200, array('id' => $merchantId, 'short_name' => 'shop')),
            'merchant' => array(200, array('id' => $merchantId, 'country_code' => $country, 'invoice_distributed_by_merchant' => false)),
            'codes' => self::codeLists(),
        );
    }

    /**
     * @return array<int,array{0:string,1:callable,2:array}>
     */
    public static function all()
    {
        $es = '{"8101|exempt":"ES_IVA_INTRA_COMMUNITY","8101|none":"ES_IVA_EXPORT","rule:8201":"ES_IVA_EXPORT"}';
        $gbZero = '{"8101|none":"GB_VAT_ZERO","rule:8203":"GB_VAT_REVERSE_CHARGE"}';
        $gbStandard = '{"8101|none":"GB_VAT_STANDARD"}';
        $unreadable = '{"abc":"ES_IVA_EXPORT"}';
        $esZeroPointZero = self::healthy('ES');
        $esZeroPointZero['codes']['ES'][1]['metadata']['mandatory_tax_rates'] = array('0.00');
        $outage = self::healthy('ES');
        $outage['codes'] = array('ES' => array(503, array()));

        return array(
            array('es-mandatory', static function () use ($es) {
                self::seed('ES', $es);
            }, self::healthy('ES')),
            array('es-mandatory-0.00', static function () {
                self::seed('ES');
            }, $esZeroPointZero),
            array('gb-nothing-saved', static function () {
                self::seed('GB');
            }, self::healthy('GB')),
            array('gb-es-map', static function () use ($es) {
                self::seed('GB', $es);
            }, self::healthy('GB')),
            array('gb-zero-codes', static function () use ($gbZero) {
                self::seed('GB', $gbZero);
            }, self::healthy('GB')),
            array('gb-standard-code', static function () use ($gbStandard) {
                self::seed('GB', $gbStandard);
            }, self::healthy('GB')),
            array('gb-empty-object', static function () {
                self::seed('GB', '{}');
            }, self::healthy('GB')),
            array('gb-unreadable', static function () use ($unreadable) {
                self::seed('GB', $unreadable);
            }, self::healthy('GB')),
            array('es-unreadable', static function () use ($unreadable) {
                self::seed('ES', $unreadable);
            }, self::healthy('ES')),
            array('no-key-unreadable', static function () use ($unreadable) {
                self::seed('ES', $unreadable);
                Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', '');
            }, self::healthy('ES')),
            array('it-not-supported', static function () {
                self::seed('IT');
            }, self::healthy('IT')),
            array('list-outage-cold', static function () {
                self::seed('ES');
            }, $outage),
            array('list-outage-warm', static function () {
                self::seed('ES');
                Configuration::updateValue('PS_TWO_TAX_CODES', json_encode(array(
                    'country' => 'ES',
                    'fetched_at' => time() - 2 * 86400,
                    'codes' => array(array('code' => 'ES_IVA_EXPORT', 'display_name' => 'Exportación', 'rate' => 0.0)),
                    'metadata' => array('mandatory' => true, 'supported' => true),
                )));
            }, $outage),
            array('verify-503', static function () {
                self::seed('ES');
            }, array('verify' => array(503, array())) + self::healthy('ES')),
            array('verify-401', static function () {
                self::seed('ES');
            }, array('verify' => array(401, array())) + self::healthy('ES')),
            array('verify-transport', static function () {
                self::seed('ES');
            }, array('verify' => array(0, null)) + self::healthy('ES')),
            array('merchant-without-country', static function () {
                self::seed('ES');
            }, array('merchant' => array(200, array('id' => 'merchant-1'))) + self::healthy('ES')),
            array('key-swapped', static function () {
                self::seed('ES');
                Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'key-2');
            }, self::healthy('ES', 'merchant-2')),
            array('environment-swapped', static function () {
                self::seed('ES');
                Configuration::updateValue('PS_TWO_ENVIRONMENT', 'production');
            }, self::healthy('ES')),
            array('stale-merchant-id', static function () {
                self::seed('ES');
                Configuration::updateValue('PS_TWO_MERCHANT_ID', '');
            }, self::healthy('ES')),
            array('no-key', static function () {
                self::seed('ES');
                Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', '');
            }, self::healthy('ES')),
            array('no-mandatory', static function () {
                self::seed('NO');
            }, self::healthy('NO')),
            array('checkout-country-unknown', static function () {
                self::seed('');
            }, self::healthy('ES')),
            array('multistore-all-shops', static function () use ($es) {
                self::seed('ES', $es);
                self::multistore();
                Shop::setContext(Shop::CONTEXT_ALL);
            }, self::healthy('ES')),
            array('multistore-shop', static function () use ($es) {
                self::seed('ES', $es);
                self::multistore();
            }, self::healthy('ES')),
        );
    }
}
