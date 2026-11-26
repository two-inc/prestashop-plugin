<?php
/**
 * UPGRADE SCRIPT: Version 2.7.2
 *
 * Refreshes the SHOP-LEVEL override tree so this version's company-field
 * placeholder actually reaches an already-installed shop (TWO-25288, override
 * mechanics per TWO-25265).
 *
 * WHY THIS EXISTS
 *
 * 2.7.2 changes `override/classes/form/CustomerAddressFormatter.php`: the
 * company field's placeholder - which is the empty-field hint - now reads
 * "Enter company name to search" instead of the previous wording.
 *
 * A module's `override/` directory is a TEMPLATE. PrestaShop copies it into the
 * shop's own `override/` tree once, at install (or reset), and from then on the
 * shop's copy is the file that executes. Nothing rewrites that copy - not an
 * upgrade, not a deploy that replaces the module directory, not a git-sync - and
 * `Module::addOverride()` cannot do it either: for any method the shop copy
 * already declares it throws rather than replacing.
 *
 * So without this script the change ships correctly, the module reports 2.7.2,
 * the files on disk are 2.7.2, the deploy is healthy - and every existing shop
 * goes on rendering the OLD placeholder indefinitely. The browser JS applies the
 * same wording only when the field carries NO placeholder, so a shop running the
 * stale override keeps beating it: the old wording wins, silently, and the
 * element looks shipped while being invisible in production. This is the failure
 * TWO-25265 found the hard way on a live shop, in this exact file.
 *
 * WHY A NEW VERSION RATHER THAN AN EDIT TO upgrade-2.7.1.php
 *
 * PrestaShop discovers upgrade scripts BY FILENAME and runs
 * `upgrade/upgrade-<version>.php` only for versions strictly ABOVE the installed
 * one. Shops are already on 2.7.1, so anything appended to `upgrade-2.7.1.php`
 * would never run there - `number_upgraded=0`, no error, no log line. A
 * migration that has to reach an already-installed version needs a version of
 * its own. `.github/scripts/check-upgrade-script-version.sh` enforces this.
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
 * classifies it CURRENT, and deletes nothing. A fresh 2.7.2 install never
 * reaches this script at all, and would have nothing to do if it did: the
 * override was written by `install()` at the current version.
 *
 * It cannot fail the upgrade. Every filesystem operation inside the migrator is
 * guarded, and this function returns true unconditionally - a shop that cannot
 * be tidied must still finish upgrading, because the alternative is a
 * half-upgraded shop.
 *
 * Created: 2026-07-30
 *
 * @param Twopayment $module
 * @return bool
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_2($module)
{
    require_once rtrim($module->getLocalPath(), '/') . '/classes/TwoOverrideMigrator.php';

    try {
        $notes = TwoOverrideMigrator::refresh($module);
    } catch (Exception $e) {
        // Deliberately broad, same reasoning as the 2.7.1 script: this is
        // housekeeping on top of an upgrade that has already succeeded, and a
        // thrown exception in an upgrade script leaves the module version
        // un-bumped and the shop in a state no later script can reason about.
        PrestaShopLogger::addLog(
            'Two Payment v2.7.2 upgrade: shop-level override refresh raised "' . $e->getMessage()
            . '" and was skipped. The shop may still be running a stale override, in which case the '
            . 'company field keeps the old placeholder wording; check override/classes/form/ against '
            . 'the module version (TWO-25288)',
            2,
            null,
            'Module',
            $module->id
        );

        return true;
    }

    PrestaShopLogger::addLog(
        'Two Payment v2.7.2 upgrade: shop-level override refresh - '
        . (empty($notes) ? 'no override files present, nothing to do' : implode('; ', $notes))
        . ' (TWO-25288)',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
