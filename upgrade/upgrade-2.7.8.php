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
 * Idempotent: a value already canonical is not written again.
 *
 * Created: 2026-08-14
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_8($module)
{
    $current = Configuration::get('PS_TWO_ENVIRONMENT');
    $target = Twopayment::normaliseTwoEnvironment($current);

    if ($current === $target) {
        return true;
    }

    if (!Configuration::updateValue('PS_TWO_ENVIRONMENT', $target)) {
        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.7.8: Failed to rewrite PS_TWO_ENVIRONMENT to ' . $target,
            3,
            null,
            'Module',
            $module->id
        );

        return false;
    }

    PrestaShopLogger::addLog(
        'TwoPayment: Successfully upgraded to version 2.7.8 - PS_TWO_ENVIRONMENT rewritten from '
        . var_export($current, true) . ' to ' . $target,
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
