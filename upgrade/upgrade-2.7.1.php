<?php
/**
 * UPGRADE SCRIPT: Version 2.7.1
 *
 * Refreshes the SHOP-LEVEL override tree (TWO-25265).
 *
 * WHAT WENT WRONG, because it explains why this script has to exist at all.
 *
 * 2.7.0 changed `override/classes/form/CustomerAddressFormatter.php`: it stopped
 * injecting the department and project fields into the billing address block,
 * because those fields moved into the Two payment tile. The module's own copy of
 * that override changed correctly and shipped correctly. Every already-installed
 * shop kept running the OLD one anyway.
 *
 * A module's `override/` directory is a TEMPLATE. PrestaShop copies it into the
 * shop's own `override/` tree once, at install (or reset), and from then on the
 * shop's copy is the file that executes. Nothing rewrites that copy - not an
 * upgrade, not a deploy that replaces the module directory, not a git-sync.
 * `Module::addOverride()` cannot even do it when it does run: for any method the
 * shop copy already declares it throws rather than replacing, and it has no path
 * that removes one. A module RESET does not help either, for the same reason.
 *
 * So the shop kept a 2.4.0-stamped `CustomerAddressFormatter` that went on
 * injecting department and project into the address form, while reporting module
 * version 2.7.0, with 2.7.0 files on disk and a healthy deploy. Observed on a
 * live staging shop 2026-07-29; the same shop lineage backs the shop that tracks
 * `main`, so the identical symptom is queued up there for whenever 2.7.0 is
 * released. This script is what stops that.
 *
 * WHAT THIS SCRIPT DOES
 *
 * Hands off to `TwoOverrideMigrator::refresh()`, which deletes the shop-level
 * copy of each of the module's overrides when - and only when - it is stale AND
 * carries no other module's ownership stamp, then rebuilds the class index and
 * re-runs `installOverrides()` so the current version's copy is written fresh.
 * See that class for the ownership rules and for why a co-owned or unstamped
 * file is deliberately left alone.
 *
 * WHY A NEW VERSION RATHER THAN AN EDIT TO upgrade-2.7.0.php
 *
 * Not a style choice. PrestaShop discovers upgrade scripts BY FILENAME and runs
 * `upgrade/upgrade-<version>.php` only for versions strictly ABOVE the installed
 * one. The shop that has the bug is already ON 2.7.0, so anything appended to
 * `upgrade-2.7.0.php` would never run there - `number_upgraded=0`, silently.
 * A migration that has to reach an already-installed version needs a version of
 * its own. `.github/scripts/check-upgrade-script-version.sh` enforces this.
 *
 * IDEMPOTENCY
 *
 * A second run finds every override already stamped at the installed version,
 * classifies it CURRENT, and deletes nothing. A fresh 2.7.1 install never
 * reaches this script at all, and would have nothing to do if it did: the
 * override was written by `install()` at the current version.
 *
 * It cannot fail the upgrade. Every filesystem operation is guarded, an
 * unreadable or unwritable file is logged and skipped, and the function returns
 * true unconditionally - a shop that cannot be tidied must still finish
 * upgrading, because the alternative is a half-upgraded shop.
 *
 * Created: 2026-07-29
 *
 * @param Twopayment $module
 * @return bool
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_1($module)
{
    require_once rtrim($module->getLocalPath(), '/') . '/classes/TwoOverrideMigrator.php';

    try {
        $notes = TwoOverrideMigrator::refresh($module);
    } catch (Exception $e) {
        // Deliberately broad. This is housekeeping on top of an upgrade that has
        // already succeeded; nothing here is worth failing the upgrade over, and
        // a thrown exception in an upgrade script leaves the module version
        // un-bumped and the shop in a state no later script can reason about.
        PrestaShopLogger::addLog(
            'Two Payment v2.7.1 upgrade: shop-level override refresh raised "' . $e->getMessage()
            . '" and was skipped. The shop may still be running a stale override; check '
            . 'override/classes/form/ against the module version (TWO-25265)',
            2,
            null,
            'Module',
            $module->id
        );

        return true;
    }

    PrestaShopLogger::addLog(
        'Two Payment v2.7.1 upgrade: shop-level override refresh - '
        . (empty($notes) ? 'no override files present, nothing to do' : implode('; ', $notes))
        . ' (TWO-25265)',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
