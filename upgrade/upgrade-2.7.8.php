<?php
/**
 * UPGRADE SCRIPT: Version 2.7.8
 *
 * Removes the decorative "Development" `PS_TWO_ENVIRONMENT` option (TWO-25455).
 *
 * `development` had no entry in any of Twopayment::ENVIRONMENT_HOSTS,
 * Twopayment::PORTAL_HOSTS, Twopayment::BUYER_PORTAL_HOSTS or
 * TwoSoleTrader::$signup_hosts, so selecting it silently fell back to the
 * sandbox host everywhere - identical to leaving the setting unset. It was
 * also, until this release, the install-time default (installTwoSettings()),
 * so any shop that never touched the dropdown is on this value today.
 *
 * The admin dropdown no longer offers "Development" as of this release. Any
 * shop with `PS_TWO_ENVIRONMENT` still stored as 'development' is rewritten
 * to 'staging' here - the closest non-inert replacement, and a real entry in
 * every host map above - rather than being left on a value the admin UI can
 * no longer render or validate.
 *
 * Idempotent: a shop already on 'staging', 'production', or anything else is
 * untouched.
 *
 * Created: 2026-08-14
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_8($module)
{
    $current = Configuration::get('PS_TWO_ENVIRONMENT');

    if (strtolower((string) $current) !== 'development') {
        return true;
    }

    if (!Configuration::updateValue('PS_TWO_ENVIRONMENT', 'staging')) {
        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.7.8: Failed to rewrite PS_TWO_ENVIRONMENT from the removed '
            . "'development' value to 'staging'",
            3,
            null,
            'Module',
            $module->id
        );

        return false;
    }

    PrestaShopLogger::addLog(
        "TwoPayment: Successfully upgraded to version 2.7.8 - PS_TWO_ENVIRONMENT was 'development' "
        . "(a removed, decorative option that always fell back to sandbox hosts) and has been "
        . "rewritten to 'staging'",
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
