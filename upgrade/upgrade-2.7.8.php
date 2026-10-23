<?php
/**
 * UPGRADE SCRIPT: Version 2.7.8
 *
 * Rewrites `PS_TWO_ENVIRONMENT` to the canonical set `production`, `sandbox`,
 * `staging` (TWO-25455, TWO-26604), preserving the hosts each shop already
 * talked to:
 *
 * - `production` in any case, or `prod`: `production`
 * - `sandbox` in any case: `sandbox`
 * - `staging` in any case: `staging`, kept
 * - `development`, empty, missing or anything else: `sandbox`. None of these
 *   had an entry in any host map, so they always resolved to the sandbox
 *   hosts. `development` was also the released install-time default, so
 *   every shop that never touched the dropdown is on it today and must stay
 *   on sandbox, not move to staging.
 *
 * Every stored row is rewritten at its own tier (global, shop group, shop),
 * and a missing global row is written as `sandbox`. Idempotent: a value
 * already canonical is not written again.
 *
 * Created: 2026-08-14
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_8($module)
{
    // Every stored row, at every tier: global, shop group and shop. A
    // Configuration::get() here would see only the tier the upgrade's context
    // resolves to, leaving a shop's own override on its legacy value.
    $rows = Db::getInstance()->executeS(
        'SELECT `id_shop_group`, `id_shop`, `value` FROM `' . _DB_PREFIX_ . 'configuration`'
        . " WHERE `name` = 'PS_TWO_ENVIRONMENT'"
    );
    $rows = is_array($rows) ? $rows : array();
    $hasGlobal = false;
    foreach ($rows as $row) {
        if (!(int) $row['id_shop_group'] && !(int) $row['id_shop']) {
            $hasGlobal = true;
        }
    }
    if (!$hasGlobal) {
        // A missing key always resolved to the sandbox hosts.
        $rows[] = array('id_shop_group' => 0, 'id_shop' => 0, 'value' => null);
    }

    $ok = true;
    foreach ($rows as $row) {
        $current = $row['value'];
        $target = Twopayment::normaliseTwoEnvironment($current);
        if ($current === $target) {
            continue;
        }
        $group = (int) $row['id_shop_group'];
        $shop = (int) $row['id_shop'];
        $scope = 'group ' . $group . ', shop ' . $shop;
        if (!Configuration::updateValue('PS_TWO_ENVIRONMENT', $target, false, $group, $shop)) {
            PrestaShopLogger::addLog(
                'TwoPayment Upgrade 2.7.8: Failed to rewrite PS_TWO_ENVIRONMENT to ' . $target . ' (' . $scope . ')',
                3,
                null,
                'Module',
                $module->id
            );
            $ok = false;
            continue;
        }
        PrestaShopLogger::addLog(
            'TwoPayment: Successfully upgraded to version 2.7.8 - PS_TWO_ENVIRONMENT rewritten from '
            . var_export($current, true) . ' to ' . $target . ' (' . $scope . ')',
            1,
            null,
            'Module',
            $module->id
        );
    }

    return $ok;
}
