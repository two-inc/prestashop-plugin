<?php
/**
 * UPGRADE SCRIPT: Version 2.7.13
 *
 * Creates `twopayment_rate_limit` (TWO-25386): the per-(route, caller)
 * fixed-window counter TwoRateLimiter uses to meter the buyer-facing
 * order-intent AJAX endpoints, on by default. A fresh install already gets
 * this table from Twopayment::installTwoDatabaseTables() - this script only
 * carries an existing shop across, hence CREATE TABLE IF NOT EXISTS rather
 * than an unconditional create.
 *
 * Nothing else needs migrating: the new Configuration keys
 * (PS_TWO_FIREWALL_TOKEN, PS_TWO_FIREWALL_TOKEN_BROWSER,
 * PS_TWO_TRUSTED_PROXIES, PS_TWO_DISABLE_RATE_LIMIT) all read as their
 * secure/off default when the row is absent, so an upgraded shop with no
 * row behaves exactly as one that just ran install().
 *
 * Created: 2026-08-31
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_13($module)
{
    $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'twopayment_rate_limit` (
        `rate_key` VARCHAR(64) NOT NULL,
        `window_start` INT(11) UNSIGNED NOT NULL,
        `hit_count` INT(11) UNSIGNED NOT NULL,
        PRIMARY KEY (`rate_key`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

    if (!Db::getInstance()->execute($sql)) {
        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.7.13: Failed to create twopayment_rate_limit - '
            . 'checkout rate limiting will not enforce until this table exists',
            3,
            null,
            'Module',
            $module->id
        );

        return false;
    }

    PrestaShopLogger::addLog(
        'TwoPayment: Successfully upgraded to version 2.7.13 - checkout rate limiting (on by default) '
        . 'and the firewall token/trusted-proxy settings are now available under General/Diagnostics',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
