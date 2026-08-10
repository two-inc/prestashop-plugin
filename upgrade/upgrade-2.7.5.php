<?php
/**
 * UPGRADE SCRIPT: Version 2.7.5
 *
 * Does two things, both of which an already-installed shop needs and neither of
 * which happens on its own (TWO-40):
 *
 *   1. Renames the stored configuration key `PS_TWO_ENABLE_COMPANY_NAME` to
 *      `PS_TWO_COMPANY_SEARCH_LOCATION`, carrying the merchant's current value
 *      across.
 *   2. Refreshes the SHOP-LEVEL override tree, so this version's change to
 *      `override/classes/form/CustomerAddressFormatter.php` actually reaches a
 *      shop that already holds a copy (override mechanics per TWO-25265).
 *
 * (1) WHY THE KEY IS RENAMED
 *
 * The key has not meant "enable company name" since TWO-25326 §7.1. It selects
 * WHERE the one company-search control renders - the checkout address area
 * ('1') or the payment tile ('0') - and the control exists either way. A key
 * named ENABLE_ reads as an on/off switch to everyone who meets it, including
 * the next person deciding whether it is safe to turn off. The name now says
 * what the value does.
 *
 * This is a REAL rename, not an alias. The old key is read once, here, and then
 * deleted. Nothing in the module reads it afterwards. A permanent
 * read-the-old-key-too fallback would leave two spellings of one setting in the
 * tree forever, and the next edit would update one of them.
 *
 * VALUE SEMANTICS ARE UNCHANGED: 1 = address area, 0 = payment tile. Only the
 * key's name moves, so a merchant who had chosen the payment tile still has the
 * payment tile afterwards. An absent row stays absent and keeps resolving to
 * '1' (address area) through Twopayment::isCompanySearchInAddressArea(), which
 * is the install default and the only behaviour that existed before the switch
 * could mean anything else - writing a row here for a shop that never had one
 * would invent a decision the merchant never made.
 *
 * (2) WHY AN OVERRIDE REFRESH IS REQUIRED
 *
 * A module's `override/` directory is a TEMPLATE. PrestaShop copies it into the
 * shop's own `override/` tree once, at install or reset, and from then on the
 * shop's copy is the file that executes. Nothing rewrites that copy - not an
 * upgrade, not a deploy that replaces the module directory, not a git-sync. So
 * this version's edit to `CustomerAddressFormatter` (three unreachable private
 * helpers - addConstraints(), addMaxLength(), getFieldLabel() - and the
 * `$definition` property that existed only to feed them, all removed) changes
 * nothing on an existing shop until the migrator re-stamps it.
 * `.github/scripts/check-override-migration.sh` gates on "was a file under
 * `override/` touched", not on whether behaviour changed, which is the right
 * call: it has no way to tell the two apart without re-deriving the diff.
 *
 * WHY A NEW VERSION RATHER THAN AN EDIT TO upgrade-2.7.4.php
 *
 * PrestaShop discovers upgrade scripts BY FILENAME and runs
 * `upgrade/upgrade-<version>.php` only for versions strictly ABOVE the installed
 * one. Shops that already reached 2.7.4 would never re-run its script.
 *
 * IDEMPOTENCY
 *
 * Both halves are safe to run twice. The rename is guarded on
 * `Configuration::hasKey()` on the OLD key, which the first run deletes, so a
 * second run finds nothing to do and cannot overwrite a value the merchant has
 * since changed under the new key. The migrator classifies overrides by version
 * stamp, so a second run finds every file already stamped at the installed
 * version and deletes nothing. A fresh 2.7.5 install never reaches this script.
 *
 * It cannot fail the upgrade: the override refresh is wrapped, and this function
 * returns true unconditionally, because a shop that cannot be tidied must still
 * finish upgrading. The rename is deliberately NOT inside that try/catch -
 * `Configuration` is core and always available at this point, and silently
 * swallowing a failure here would leave the shop with neither key.
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
    $old_key = 'PS_TWO_ENABLE_COMPANY_NAME';
    $new_key = 'PS_TWO_COMPANY_SEARCH_LOCATION';

    if (Configuration::hasKey($old_key)) {
        // Read as a string and write it back unchanged. Casting to int here
        // would turn a row holding '' - which the resolver treats as "absent",
        // i.e. address area - into a 0, which means the payment tile. That is
        // the one transformation this migration must not perform.
        $stored = Configuration::get($old_key);

        Configuration::updateValue($new_key, $stored);
        Configuration::deleteByName($old_key);

        PrestaShopLogger::addLog(
            'Two Payment v2.7.5 upgrade: renamed configuration key ' . $old_key . ' to ' . $new_key
            . ' (value "' . (string) $stored . '" carried across unchanged) (TWO-40)',
            1,
            null,
            'Module',
            $module->id
        );
    } else {
        PrestaShopLogger::addLog(
            'Two Payment v2.7.5 upgrade: no ' . $old_key . ' row to rename; ' . $new_key
            . ' will resolve to the address-area default (TWO-40)',
            1,
            null,
            'Module',
            $module->id
        );
    }

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
