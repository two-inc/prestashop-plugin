<?php

/**
 * INTEGRATION PROBE - a stale stored merchant id, recovered by the Diagnostics "Refresh merchant profile" button.
 *
 * PS_TWO_MERCHANT_ID is written only by a verified General save, so a shop seeded, cloned or migrated without one
 * can hold an id that is not its API key's merchant, and Two answers 404 for it. The refresh resolves the id from
 * the key and fetches that record. This probe sets a wrong id in the real Configuration table, runs the Diagnostics
 * refresh path, and checks:
 *   - the button reports success
 *   - the record comes back: the offered terms and the merchant country
 *   - the stored id and short name are now the key's merchant's
 *
 * Hermetic: the API-key verification and the merchant record fetch are stubbed, so no network and no Two credentials.
 * Every Configuration value it writes is restored on exit.
 *
 * Usage: php tests/integration/stale-merchant-id.php
 */

if (!defined('_PS_VERSION_')) {
    require '/var/www/html/config/config.inc.php';
}
require_once __DIR__ . '/lib/probe-helpers.php';

probeBootKernel();
Module::getInstanceByName('twopayment');

/** The installed module answering as Two would for a key whose merchant is "probe-merchant". */
class StaleMerchantIdProbeTwopayment extends Twopayment
{
    const KEY_MERCHANT = 'probe-merchant';

    /** @var string[] record endpoints fetched */
    public $fetched = array();
    /** @var array|null what the Diagnostics button answered */
    public $answer = null;

    protected function requestTwoApiKeyVerification($apiKey, $environment, $timeout = null)
    {
        return array('response' => json_encode(array('id' => self::KEY_MERCHANT, 'short_name' => 'probe')), 'code' => 200, 'error' => '');
    }

    public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
    {
        $this->fetched[] = $endpoint;
        if ($endpoint !== '/v1/merchant/' . self::KEY_MERCHANT) {
            return array('http_status' => 404, 'error' => 'merchant not found');
        }

        return array('http_status' => 200, 'id' => self::KEY_MERCHANT, 'available_terms' => array(30, 60), 'due_in_days' => 30, 'country_code' => 'NO');
    }

    protected function respondTwoRefreshMerchantRecord(array $payload)
    {
        $this->answer = $payload;
    }
}

$keys = array(
    'PS_TWO_MERCHANT_ID',
    'PS_TWO_MERCHANT_API_KEY',
    'PS_TWO_MERCHANT_SHORT_NAME',
    Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS,
    Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS_TS,
    Twopayment::CONFIG_MERCHANT_DUE_IN_DAYS,
    Twopayment::CONFIG_MERCHANT_INVOICE_DISTRIBUTED,
    Twopayment::CONFIG_PLATFORM_MIN_ORDER,
    Twopayment::CONFIG_MERCHANT_BUYER_COUNTRIES,
    Twopayment::CONFIG_MERCHANT_COUNTRY,
    Twopayment::CONFIG_MERCHANT_RECORD_KEY,
    Twopayment::CONFIG_MERCHANT_RECORD_FOREIGN_TS,
);
$saved = array();
foreach ($keys as $key) {
    $saved[$key] = Configuration::get($key);
}
register_shutdown_function(static function () use ($saved) {
    foreach ($saved as $key => $value) {
        if ($value === false) {
            Configuration::deleteByName($key);
        } else {
            Configuration::updateValue($key, $value);
        }
    }
});

// A shop whose key was installed without a verified save: a wrong id, no short name and no record held.
Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', 'probe-api-key');
Configuration::updateValue('PS_TWO_MERCHANT_ID', 'not-this-keys-merchant');
foreach (array_slice($keys, 2) as $key) {
    Configuration::updateValue($key, '');
}

$failures = array();
$check = static function ($ok, $description) use (&$failures) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $description . "\n";
    if (!$ok) {
        $failures[] = $description;
    }
};

$module = new StaleMerchantIdProbeTwopayment();
$module->ajaxProcessRefreshMerchantRecord();
$reader = new StaleMerchantIdProbeTwopayment();

echo 'fetched: ' . implode(', ', $module->fetched) . "\n";
$check(is_array($module->answer) && $module->answer['success'] === true, 'the Diagnostics refresh reports success');
$check($reader->getAvailablePaymentTerms() !== array(), 'the offered terms come back (got: ' . json_encode($reader->getAvailablePaymentTerms()) . ')');
$check($reader->getTwoMerchantCountry() === 'NO', 'the merchant country comes back (got: ' . $reader->getTwoMerchantCountry() . ')');
$check(Configuration::get('PS_TWO_MERCHANT_ID') === StaleMerchantIdProbeTwopayment::KEY_MERCHANT, 'the stored merchant id is now the key\'s merchant');
$check(Configuration::get('PS_TWO_MERCHANT_SHORT_NAME') === 'probe', 'the stored short name is now the key\'s merchant\'s (got: ' . Configuration::get('PS_TWO_MERCHANT_SHORT_NAME') . ')');

if ($failures !== array()) {
    echo count($failures) . " check(s) failed\n";
    exit(1);
}
echo "all checks passed\n";
