<?php
/**
 * UPGRADE SCRIPT: Version 2.7.9
 *
 * Refreshes the SHOP-LEVEL override tree so its copy of
 * `override/classes/form/CustomerAddressFormatter.php` matches what this
 * version ships (TWO-25498; override mechanics per TWO-25265).
 *
 * WHAT CHANGED IN THE OVERRIDE
 *
 * Comment-only: one internal ticket reference was dropped from a code comment
 * ahead of a public-repo push. No behaviour in this file changed.
 *
 * WHY A MIGRATION IS REQUIRED FOR THAT
 *
 * `.github/scripts/check-override-migration.sh` gates on "was a file under
 * `override/` touched", not "did behaviour change" — it has no way to
 * distinguish a comment edit from a substantive one without re-deriving the
 * diff by hand. See classes/TwoOverrideMigrator.php for the full mechanics.
 *
 * WHAT IT DOES
 *
 * Hands off to `TwoOverrideMigrator::refresh()`, same as the 2.7.1-2.7.5
 * scripts — see those files for the full mechanics. Because nothing about the
 * override's behaviour changed, every shop's existing copy already matches on
 * every field `refresh()` checks except the version stamp, so this call is
 * expected to be a same-content restamp, not a behavioural change.
 *
 * IDEMPOTENCY
 *
 * A second run finds every override already stamped at the installed version,
 * classifies it CURRENT, and deletes nothing. A fresh 2.7.9 install never
 * reaches this script, and would have nothing to do if it did.
 *
 * It cannot fail the upgrade: every filesystem operation inside the migrator is
 * guarded and this function returns true unconditionally, because a shop that
 * cannot be tidied must still finish upgrading.
 *
 * Created: 2026-08-21
 *
 * @param Twopayment $module
 * @return bool
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_9($module)
{
    try {
        require_once rtrim($module->getLocalPath(), '/') . '/classes/TwoOverrideMigrator.php';

        $notes = TwoOverrideMigrator::refresh($module);
    } catch (Throwable $e) {
        // Deliberately broad, same reasoning as the 2.7.1-2.7.5 scripts: this is
        // housekeeping on top of an upgrade that has already succeeded, and
        // anything thrown here leaves the module version un-bumped and the shop in
        // a state no later script can reason about.
        PrestaShopLogger::addLog(
            'Two Payment v2.7.9 upgrade: shop-level override refresh raised "' . $e->getMessage()
            . '" and was skipped, so CustomerAddressFormatter may still be stale (TWO-25498)',
            2,
            null,
            'Module',
            $module->id
        );

        return true;
    }

    PrestaShopLogger::addLog(
        'Two Payment v2.7.9 upgrade: shop-level override refresh (CustomerAddressFormatter) - '
        . (empty($notes) ? 'no override files present, nothing to do' : implode('; ', $notes))
        . ' (TWO-25498)',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
