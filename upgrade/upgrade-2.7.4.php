<?php
/**
 * UPGRADE SCRIPT: Version 2.7.4
 *
 * Refreshes the SHOP-LEVEL override tree so this version's change to
 * `override/classes/form/CustomerAddressFormatter.php` actually reaches an
 * already-installed shop (TWO-40; override mechanics per TWO-25265).
 *
 * WHAT CHANGED IN THE OVERRIDE
 *
 * Comment-only: the note explaining why there is no account-type selector on
 * the address form now points at the company-search "I'm a sole trader" entry
 * point (TWO-40) instead of the retired payment-step Business / Sole trader
 * toggle it used to describe (TWO-24755). No behaviour in this file changed.
 *
 * WHY A MIGRATION IS REQUIRED FOR THAT
 *
 * A module's `override/` directory is a TEMPLATE. PrestaShop copies it into the
 * shop's own `override/` tree once, at install or reset, and from then on the
 * shop's copy is the file that executes. Nothing rewrites that copy - not an
 * upgrade, not a deploy that replaces the module directory, not a git-sync.
 * `.github/scripts/check-override-migration.sh` enforces this for every PR that
 * touches a file under `override/`, whatever the change - a comment-only edit
 * left this file byte-for-byte identical in behaviour, but the check has no way
 * to distinguish that from a substantive one without re-deriving the diff by
 * hand, so it gates on "was this file touched", not "did behaviour change".
 *
 * WHY A NEW VERSION RATHER THAN AN EDIT TO upgrade-2.7.3.php
 *
 * PrestaShop discovers upgrade scripts BY FILENAME and runs
 * `upgrade/upgrade-<version>.php` only for versions strictly ABOVE the installed
 * one. Shops that already reached 2.7.3 would never re-run its script.
 *
 * WHAT IT DOES
 *
 * Hands off to `TwoOverrideMigrator::refresh()`, same as 2.7.3's script - see
 * that file for the full mechanics. Harmless to run again on top of it: the
 * migrator classifies by version stamp, so this only re-stamps overrides that
 * are actually stale for 2.7.4.
 *
 * IDEMPOTENCY
 *
 * A second run finds every override already stamped at the installed version,
 * classifies it CURRENT, and deletes nothing. A fresh 2.7.4 install never
 * reaches this script, and would have nothing to do if it did.
 *
 * It cannot fail the upgrade: every filesystem operation inside the migrator is
 * guarded and this function returns true unconditionally, because a shop that
 * cannot be tidied must still finish upgrading.
 *
 * Created: 2026-08-07
 *
 * @param Twopayment $module
 * @return bool
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_4($module)
{
    try {
        require_once rtrim($module->getLocalPath(), '/') . '/classes/TwoOverrideMigrator.php';

        $notes = TwoOverrideMigrator::refresh($module);
    } catch (Throwable $e) {
        // Deliberately broad, same reasoning as the 2.7.1-2.7.3 scripts: this is
        // housekeeping on top of an upgrade that has already succeeded, and
        // anything thrown here leaves the module version un-bumped and the shop in
        // a state no later script can reason about.
        PrestaShopLogger::addLog(
            'Two Payment v2.7.4 upgrade: shop-level override refresh raised "' . $e->getMessage()
            . '" and was skipped (TWO-40)',
            2,
            null,
            'Module',
            $module->id
        );

        return true;
    }

    PrestaShopLogger::addLog(
        'Two Payment v2.7.4 upgrade: shop-level override refresh - '
        . (empty($notes) ? 'no override files present, nothing to do' : implode('; ', $notes))
        . ' (TWO-40)',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
