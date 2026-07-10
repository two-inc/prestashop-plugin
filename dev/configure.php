<?php
/**
 * Dev-environment configurator for the Two PrestaShop module.
 *
 * Invoked by `make configure TWO_API_KEY=… TWO_ENVIRONMENT=…`. Writes the
 * Two module configuration AND calls the verify_api_key endpoint so the
 * checkout-step gating logic in twopayment.php:1587 actually clears
 * (otherwise the plugin silently hides itself from the payment step).
 *
 * Why this exists rather than `Configuration::updateValue` in a one-liner:
 * - The verify call is normally only triggered by the admin form save —
 *   `make configure` would otherwise leave the dev env in a broken state
 *   (key set, but merchant_short_name NULL, plugin hidden).
 * - ps_configuration has no UNIQUE(name, id_shop_group, id_shop) constraint,
 *   so cross-context CLI writes can produce duplicate rows. We defensively
 *   prune duplicates for keys we write here.
 */

define('_PS_ADMIN_DIR_', '/var/www/html/admin-dev');
require '/var/www/html/config/config.inc.php';

$apiKey      = getenv('TWO_API_KEY') ?: 'dummy-dev-key';
$environment = getenv('TWO_ENVIRONMENT') ?: 'sandbox';
// Plugin uses 'development' internally; 'sandbox' is an alias from the Makefile UX.
$pluginEnv = ($environment === 'production') ? 'production' : 'development';

// --- Defensive dedup: keep highest id_configuration row per (name, NULL scope). ---
$keys = [
    'PS_TWO_MERCHANT_API_KEY',
    'PS_TWO_ENVIRONMENT',
    'PS_TWO_MERCHANT_SHORT_NAME',
    'PS_TWO_MERCHANT_ID',
    'PS_TWO_API_KEY_VERIFIED',
];
$db = Db::getInstance();
foreach ($keys as $key) {
    $db->execute(
        "DELETE FROM `" . _DB_PREFIX_ . "configuration` "
        . "WHERE name = '" . pSQL($key) . "' "
        . "AND (id_shop IS NULL OR id_shop = 0) "
        . "AND (id_shop_group IS NULL OR id_shop_group = 0) "
        . "AND id_configuration NOT IN ("
        . "  SELECT id_configuration FROM ("
        . "    SELECT MAX(id_configuration) AS id_configuration "
        . "    FROM `" . _DB_PREFIX_ . "configuration` "
        . "    WHERE name = '" . pSQL($key) . "' "
        . "    AND (id_shop IS NULL OR id_shop = 0) "
        . "    AND (id_shop_group IS NULL OR id_shop_group = 0)"
        . "  ) AS keepers"
        . ")"
    );
}
Configuration::resetStaticCache();

Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', $apiKey);
Configuration::updateValue('PS_TWO_ENVIRONMENT', $pluginEnv);

// --- Verify the API key against Two so merchant_short_name gets populated. ---
// TWO_API_BASE_URL is set by the Makefile from gcloud identity:
// @two.inc users hit api.staging.two.inc, external devs hit api.sandbox.two.inc.
// Production builds always use api.two.inc regardless.
if ($pluginEnv === 'production') {
    $verifyHost = 'https://api.two.inc';
} else {
    $verifyHost = getenv('TWO_API_BASE_URL') ?: 'https://api.sandbox.two.inc';
}
$url = $verifyHost . '/v1/merchant/verify_api_key?client=PS&client_v=make-configure';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json; charset=utf-8',
    'X-API-Key:' . $apiKey,
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$verified = false;
if ($response !== false && $httpCode === 200) {
    $decoded = json_decode($response, true);
    if (is_array($decoded) && isset($decoded['id'], $decoded['short_name'])) {
        Configuration::updateValue('PS_TWO_MERCHANT_ID', $decoded['id']);
        Configuration::updateValue('PS_TWO_MERCHANT_SHORT_NAME', $decoded['short_name']);
        Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 1);
        $verified = true;
        echo "Two config updated. Merchant: {$decoded['short_name']} ({$decoded['id']}), env={$pluginEnv}" . PHP_EOL;
    }
}

if (!$verified) {
    Configuration::updateValue('PS_TWO_MERCHANT_SHORT_NAME', '');
    Configuration::updateValue('PS_TWO_MERCHANT_ID', '');
    Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 0);
    $reason = $curlError ?: ('HTTP ' . $httpCode);
    echo "Two config updated, but API key verification skipped/failed ({$reason}). "
        . "Plugin will NOT appear at checkout until verified with a real key." . PHP_EOL;
}
