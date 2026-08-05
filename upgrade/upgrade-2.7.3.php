<?php
/**
 * UPGRADE SCRIPT: Version 2.7.3
 *
 * Refreshes the SHOP-LEVEL override tree so this version's change to
 * `override/classes/form/CustomerAddressFormatter.php` actually reaches an
 * already-installed shop (TWO-25326; override mechanics per TWO-25265).
 *
 * WHAT CHANGED IN THE OVERRIDE
 *
 * The company field's search-mode placeholder is no longer applied
 * unconditionally: it is withheld while the shop's Two API key does not verify,
 * because in that state no company-search control is mounted and the field is a
 * plain text input, so a hint telling the buyer to search it would be
 * instructing them to do something that cannot happen.
 *
 * WHY A MIGRATION IS REQUIRED FOR THAT
 *
 * A module's `override/` directory is a TEMPLATE. PrestaShop copies it into the
 * shop's own `override/` tree once, at install or reset, and from then on the
 * shop's copy is the file that executes. Nothing rewrites that copy - not an
 * upgrade, not a deploy that replaces the module directory, not a git-sync - and
 * `Module::addOverride()` cannot either: for any method the shop copy already
 * declares it throws rather than replacing.
 *
 * So without this script the module reports 2.7.3, the files on disk are 2.7.3,
 * the deploy is green, and every existing shop goes on running the 2.7.2 copy of
 * `CustomerAddressFormatter` - which keeps telling buyers to search a field that
 * will not search. Exactly the silent staleness TWO-25265 found on a live shop,
 * in this same file.
 *
 * WHY A NEW VERSION RATHER THAN AN EDIT TO upgrade-2.7.2.php
 *
 * PrestaShop discovers upgrade scripts BY FILENAME and runs
 * `upgrade/upgrade-<version>.php` only for versions strictly ABOVE the installed
 * one. Shops that already reached 2.7.2 would never re-run its script:
 * `number_upgraded=0`, no error, no log line. A migration that has to reach an
 * already-installed version needs a version of its own;
 * `.github/scripts/check-upgrade-script-version.sh` enforces that.
 *
 * WHAT IT DOES
 *
 * Hands off to `TwoOverrideMigrator::refresh()`, which deletes the shop-level
 * copy of each of the module's overrides when - and only when - it is stale AND
 * carries no other module's ownership stamp, then rebuilds the class index and
 * re-runs `installOverrides()` so this version's copy is written fresh. No
 * retired paths are passed: the override is still shipped and still used, only
 * its contents changed.
 *
 * IDEMPOTENCY
 *
 * A second run finds every override already stamped at the installed version,
 * classifies it CURRENT, and deletes nothing. A fresh 2.7.3 install never
 * reaches this script, and would have nothing to do if it did.
 *
 * It cannot fail the upgrade: every filesystem operation inside the migrator is
 * guarded and this function returns true unconditionally, because a shop that
 * cannot be tidied must still finish upgrading.
 *
 * NOTE FOR SHOPS RUNNING AN INTERMEDIATE BUILD OF THIS CHANGE (dev/staging
 * shops that git-sync the branch): TwoOverrideMigrator classifies staleness by
 * version STAMP, so a shop that already installed 2.7.3 from an earlier commit
 * has a shop-level copy stamped 2.7.3, is classified CURRENT, and is NOT
 * refreshed by this script - it keeps running that earlier copy of the
 * override. Force it with a module reset (or by deleting the shop-level copy)
 * before live-testing this behaviour. No merchant is in that position: 2.7.3 is
 * unreleased.
 *
 * Created: 2026-08-05
 *
 * @param Twopayment $module
 * @return bool
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_3($module)
{
    try {
        // Inside the try, not before it: resolving the path and loading the class
        // are as capable of raising as the refresh itself (a missing file, an odd
        // install), and this function's whole contract is that nothing it does can
        // fail the upgrade.
        require_once rtrim($module->getLocalPath(), '/') . '/classes/TwoOverrideMigrator.php';

        $notes = TwoOverrideMigrator::refresh($module);
    } catch (Throwable $e) {
        // Deliberately broad, same reasoning as the 2.7.1/2.7.2 scripts: this is
        // housekeeping on top of an upgrade that has already succeeded, and
        // anything thrown here leaves the module version un-bumped and the shop in
        // a state no later script can reason about. Throwable rather than
        // Exception: an Error (a TypeError inside the migrator, a missing class on
        // an odd install) has exactly that consequence and would otherwise not be
        // caught at all.
        PrestaShopLogger::addLog(
            'Two Payment v2.7.3 upgrade: shop-level override refresh raised "' . $e->getMessage()
            . '" and was skipped. The shop may still be running a stale override, in which case the '
            . 'company field keeps offering a search that cannot run while the API key does not verify; '
            . 'check override/classes/form/ against the module version (TWO-25326)',
            2,
            null,
            'Module',
            $module->id
        );

        return true;
    }

    PrestaShopLogger::addLog(
        'Two Payment v2.7.3 upgrade: shop-level override refresh - '
        . (empty($notes) ? 'no override files present, nothing to do' : implode('; ', $notes))
        . ' (TWO-25326)',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
