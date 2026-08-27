<?php
/**
 * UPGRADE SCRIPT: Version 2.7.10
 *
 * Reinstalls the SHOP-LEVEL override tree on shops that have no copy of
 * `override/classes/form/CustomerAddressFormatter.php` at all (TWO-25265).
 *
 * WHAT WENT WRONG
 *
 * `TwoOverrideMigrator::refresh()` skipped any path with no file at the shop
 * level, and returned early when it had deleted nothing. A shop whose copy was
 * deleted but never rebuilt - the state a failed `installOverrides()` after a
 * successful delete leaves behind - therefore stayed with no override, and
 * every later version's refresh call skipped it again for the same reason.
 * Observed on a staging shop reporting 2.7.9 whose `override/classes/form/`
 * held nothing but PrestaShop's `index.php`: the address form rendered in
 * core's field order, with the country selector near the end instead of above
 * the company field.
 *
 * `refresh()` now counts a missing file the module still SHIPS as work to do
 * and re-runs `installOverrides()`, which writes it fresh. A path the caller
 * named as RETIRED is still skipped when absent - that is its finished state.
 *
 * WHY A NEW VERSION RATHER THAN AN EDIT TO AN EXISTING SCRIPT
 *
 * PrestaShop discovers upgrade scripts BY FILENAME and runs
 * `upgrade/upgrade-<version>.php` only for versions strictly above the
 * installed one. The shops that need this are already on 2.7.9 or below, so the
 * fix has to arrive under a filename none of them has run.
 *
 * IDEMPOTENCY
 *
 * The reinstalled file is stamped at the installed version, so a second run
 * classifies it CURRENT and does nothing. A fresh install never reaches this
 * script and would have nothing to do if it did.
 *
 * It cannot fail the upgrade: every filesystem operation inside the migrator is
 * guarded and this function returns true unconditionally, because a shop that
 * cannot be tidied must still finish upgrading.
 *
 * Created: 2026-08-27
 *
 * @param Twopayment $module
 * @return bool
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_10($module)
{
    try {
        require_once rtrim($module->getLocalPath(), '/') . '/classes/TwoOverrideMigrator.php';

        $notes = TwoOverrideMigrator::refresh($module);
    } catch (Throwable $e) {
        // Deliberately broad, same reasoning as the 2.7.1-2.7.9 scripts: this is
        // housekeeping on top of an upgrade that has already succeeded, and
        // anything thrown here leaves the module version un-bumped and the shop in
        // a state no later script can reason about.
        PrestaShopLogger::addLog(
            'Two Payment v2.7.10 upgrade: shop-level override refresh raised "' . $e->getMessage()
            . '" and was skipped, so CustomerAddressFormatter may still be missing (TWO-25265)',
            2,
            null,
            'Module',
            $module->id
        );

        return true;
    }

    PrestaShopLogger::addLog(
        'Two Payment v2.7.10 upgrade: shop-level override refresh (CustomerAddressFormatter) - '
        . (empty($notes) ? 'no override files present, nothing to do' : implode('; ', $notes))
        . ' (TWO-25265)',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
