<?php
/**
 * UPGRADE SCRIPT: Version 2.7.5
 *
 * Refreshes the SHOP-LEVEL override tree so this version's change to
 * `override/classes/form/CustomerAddressFormatter.php` actually reaches an
 * already-installed shop (TWO-40; override mechanics per TWO-25265).
 *
 * WHAT CHANGED IN THE OVERRIDE
 *
 * Three unreachable private helpers - addConstraints(), addMaxLength() and
 * getFieldLabel() - and the `$definition` property that existed only to feed
 * them, all removed. No behaviour in this file changed: PHP private members are
 * not polymorphic, so `CustomerAddressFormatterCore` could never resolve to any
 * of them, and nothing in this repository reflects over the class.
 *
 * WHY A MIGRATION IS REQUIRED FOR THAT
 *
 * A module's `override/` directory is a TEMPLATE. PrestaShop copies it into the
 * shop's own `override/` tree once, at install or reset, and from then on the
 * shop's copy is the file that executes. Nothing rewrites that copy - not an
 * upgrade, not a deploy that replaces the module directory, not a git-sync.
 * `.github/scripts/check-override-migration.sh` enforces this for every PR that
 * touches a file under `override/`, whatever the change - a dead-code removal
 * leaves this file behaviourally identical, but the check has no way to
 * distinguish that from a substantive one without re-deriving the diff by hand,
 * so it gates on "was this file touched", not "did behaviour change".
 *
 * WHY A NEW VERSION RATHER THAN AN EDIT TO upgrade-2.7.4.php
 *
 * PrestaShop discovers upgrade scripts BY FILENAME and runs
 * `upgrade/upgrade-<version>.php` only for versions strictly ABOVE the installed
 * one. Shops that already reached 2.7.4 would never re-run its script.
 *
 * WHAT IT DOES
 *
 * Hands off to `TwoOverrideMigrator::refresh()`, same as 2.7.3's and 2.7.4's
 * scripts - see those files for the full mechanics.
 *
 * ONE LIMIT, so this header does not overstate it: `refresh()` deletes
 * shop-level copies it classifies STALE, i.e. ones carrying a `twopayment`
 * version stamp below the current version. A copy carrying NO stamp classifies
 * UNSTAMPED and is deliberately left alone. So "the edit reaches an
 * already-installed shop" holds for every shop whose override was written by a
 * stamping version of this module - every version since TWO-25265 - but not for
 * a copy predating that.
 *
 * NOTHING ELSE. In particular this script does NOT rename any configuration key.
 * The company-search location key's rename was developed here, withdrawn from
 * this version, and then landed in 2.7.6 as
 * PS_TWO_ENABLE_COMPANY_NAME -> PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS - in a
 * deliberately SIMPLIFIED, GLOBAL-TIER-ONLY form, on Doug's explicit ruling
 * that with no live merchants the tier-exact migration is not worth its risk.
 *
 * The reason a SAFE rename is hard still stands and is still the record: three
 * adversarial review rounds each found a different silent merchant-data-loss
 * variant in it, because `deleteByName()` is name-wide while every writer is
 * tier-scoped, PrestaShop has THREE configuration tiers (global / shop-group /
 * shop), and neither the resolving API nor the offline test double can tell
 * those tiers apart. A rename that must not lose a multistore merchant's
 * per-shop or per-group override needs a tier-exact SQL migration and
 * multistore CI coverage. Read `.ai/decisions.md` before assuming 2.7.6's
 * script covers that case - it deliberately does not, and says so in its own
 * header.
 *
 * IDEMPOTENCY
 *
 * A second run finds every override already stamped at the installed version,
 * classifies it CURRENT, and deletes nothing. A fresh 2.7.5 install never
 * reaches this script, and would have nothing to do if it did.
 *
 * It cannot fail the upgrade: every filesystem operation inside the migrator is
 * guarded and this function returns true unconditionally, because a shop that
 * cannot be tidied must still finish upgrading.
 *
 * Created: 2026-08-10
 *
 * @param Twopayment $module
 * @return bool
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_5($module)
{
    try {
        require_once rtrim($module->getLocalPath(), '/') . '/classes/TwoOverrideMigrator.php';

        $notes = TwoOverrideMigrator::refresh($module);
    } catch (Throwable $e) {
        // Deliberately broad, same reasoning as the 2.7.1-2.7.4 scripts: this is
        // housekeeping on top of an upgrade that has already succeeded, and
        // anything thrown here leaves the module version un-bumped and the shop in
        // a state no later script can reason about.
        PrestaShopLogger::addLog(
            'Two Payment v2.7.5 upgrade: shop-level override refresh raised "' . $e->getMessage()
            . '" and was skipped, so CustomerAddressFormatter may still be stale (TWO-40)',
            2,
            null,
            'Module',
            $module->id
        );

        return true;
    }

    PrestaShopLogger::addLog(
        'Two Payment v2.7.5 upgrade: shop-level override refresh (CustomerAddressFormatter) - '
        . (empty($notes) ? 'no override files present, nothing to do' : implode('; ', $notes))
        . ' (TWO-40)',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
