<?php
/**
 * UPGRADE SCRIPT: Version 2.7.15
 *
 * Replaces the single firewall-token pair (PS_TWO_FIREWALL_TOKEN plus the
 * PS_TWO_FIREWALL_TOKEN_BROWSER switch) with PS_TWO_CUSTOM_HEADERS, a JSON
 * list of arbitrarily-named {name, value, send_from_browser} header rows.
 *
 * A shop with a token configured has it because its firewall refuses traffic
 * without it, so dropping the key would take that shop's checkout offline.
 * Seeded as one row named X-WAF-TOKEN - the header the old field was always
 * sent as - carrying the old browser flag, which reproduces the previous
 * behaviour exactly.
 *
 * Created: 2026-09-03
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_15($module)
{
    $token = trim((string) Configuration::get('PS_TWO_FIREWALL_TOKEN'));

    // Never overwrite a list that already holds rows: a re-run (reinstall,
    // rollback and re-bump) would otherwise discard headers the merchant has
    // added since, and re-add a token they deliberately removed.
    $existing = json_decode((string) Configuration::get('PS_TWO_CUSTOM_HEADERS'), true);
    $has_rows = is_array($existing) && $existing !== array();

    if ($token !== '' && !$has_rows) {
        Configuration::updateValue('PS_TWO_CUSTOM_HEADERS', json_encode(array(
            array(
                'name' => 'X-WAF-TOKEN',
                'value' => $token,
                'send_from_browser' => (bool) Configuration::get('PS_TWO_FIREWALL_TOKEN_BROWSER'),
            ),
        )));

        PrestaShopLogger::addLog(
            'Two Payment v2.7.15 upgrade: migrated the configured firewall token to a custom '
            . 'request-header row named X-WAF-TOKEN under Diagnostics',
            1,
            null,
            'Module',
            $module->id
        );
    } elseif (!$has_rows) {
        Configuration::updateValue('PS_TWO_CUSTOM_HEADERS', json_encode(array()));
    }

    Configuration::deleteByName('PS_TWO_FIREWALL_TOKEN');
    Configuration::deleteByName('PS_TWO_FIREWALL_TOKEN_BROWSER');

    PrestaShopLogger::addLog(
        'Two Payment: Successfully upgraded to version 2.7.15 - the firewall token field is replaced '
        . 'by the Diagnostics custom request-header table',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
