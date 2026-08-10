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
 * This is a REAL rename, not a permanent alias. Nothing writes the old name any
 * more, this script deletes its row, and the module has exactly ONE live read of
 * it left: a compatibility branch in Twopayment::isCompanySearchInAddressArea(),
 * taken only when the new key is absent, and scheduled for deletion in 2.8.0 by a
 * test that turns red once the declared version reaches it.
 *
 * That shim is not a hedge against this script - it covers the window this script
 * cannot reach. PrestaShop runs an upgrade script only when the module upgrade is
 * actually triggered (the web Module Manager, or dev/ci/upgrade-module.sh), NOT
 * when a deploy merely replaces the module directory - which is how the
 * git-synced shops update. Between the file swap and someone opening the back
 * office, the new key is absent and the old row is still in the DB. Without the
 * shim that window silently flips a tile-mode merchant back to the address area
 * and re-enables address autofill on a live storefront.
 *
 * NEWER WINS: a value already stored under the new name is never overwritten by
 * the old row - it is kept and the old row is deleted uncopied. Otherwise a
 * merchant who saved the position during that window would have their save
 * reverted the moment this script finally ran.
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
 * second run finds nothing to do - and even if the row were somehow back, the
 * newer-wins rule above stops it overwriting a value the merchant has since
 * changed. The migrator classifies overrides by version stamp, so a second run
 * finds every file already stamped at the installed version and deletes nothing.
 * A fresh 2.7.5 install never reaches this script.
 *
 * ONE LIMIT ON THE OVERRIDE HALF, so this header does not overstate it:
 * `TwoOverrideMigrator::refresh()` deletes shop-level copies it classifies STALE,
 * i.e. ones carrying a `twopayment` version stamp below the current version. A
 * copy carrying NO stamp classifies UNSTAMPED and is deliberately left alone. So
 * "the edit reaches an already-installed shop" holds for every shop whose
 * override was written by a stamping version of this module, which is every
 * version since TWO-25265 - not for a copy predating that.
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

/**
 * Is a Configuration value "set" in the sense Twopayment::isCompanySearchInAddressArea()
 * means it?
 *
 * Deliberately NOT Configuration::hasKey(): a row holding the empty string is
 * present to hasKey() but resolves to the address-area DEFAULT in the module, so
 * treating it as set makes an empty row look like a deliberate choice. Every
 * decision in this migration uses this predicate; hasKey() is used only to ask
 * "is there a row to delete", which is the one question it answers correctly.
 *
 * Guarded on function_exists because PrestaShop includes every in-range upgrade
 * script into the same request, and a later version's script may want the same
 * helper.
 *
 * @param mixed $value
 * @return bool
 */
if (!function_exists('two_config_is_set')) {
    function two_config_is_set($value)
    {
        return !($value === false || $value === null || $value === '');
    }
}

function upgrade_module_2_7_5($module)
{
    $old_key = 'PS_TWO_ENABLE_COMPANY_NAME';
    $new_key = 'PS_TWO_COMPANY_SEARCH_LOCATION';

    // PER SHOP, and every read goes through Configuration::get(), never
    // hasKey(). Both halves of that are corrections to real defects found in
    // review, and neither is obvious from the call sites, so:
    //
    //  1. deleteByName() is GLOBAL and unconditional - there is no per-shop
    //     variant. get()/updateValue() are shop-scoped. So a context-scoped read
    //     followed by a global delete collapses a multistore install onto
    //     whichever value the upgrade happened to run in: shop A on the payment
    //     tile and shop B in the address area both come out as one of the two,
    //     silently. Hence the loop.
    //
    //  2. hasKey($key, null, null, $idShop) is a bare isset() on the per-shop
    //     cache with NO fallback to the global (id_shop NULL) row - verified
    //     identical in 1.7.8, 8.1 and 9.0. get() DOES fall back
    //     shop -> group -> global. install() seeds its defaults under
    //     Shop::CONTEXT_ALL, i.e. GLOBAL rows, so on a multistore install
    //     hasKey-per-shop answers false for every shop even though every shop
    //     reads a value - and the round of this fix that used hasKey() therefore
    //     found nothing to carry, then deleted the global row anyway. get() per
    //     shop is the only read that sees what that shop actually resolves.
    //
    //  3. "Set" means what the RESOLVER means by it, not what hasKey() means. A
    //     row holding '' is present to hasKey() but is treated as ABSENT by
    //     Twopayment::isCompanySearchInAddressArea(), which resolves it to the
    //     address-area default. Gating newer-wins on hasKey() therefore let an
    //     EMPTY new-key row - a shape saveTwoCompanyLookupFormValues() can write
    //     from a POST that omits the field - suppress the copy and lose the
    //     merchant's tile choice. two_config_is_set() below is the resolver's
    //     own notion, and it is the only one used for a decision.
    //
    // Read every shop FIRST, then write, then delete once at the end: the delete
    // cannot be inside the loop, because the first iteration would wipe the rows
    // the later iterations still need to read.
    $shop_ids = array();
    if (Shop::isFeatureActive()) {
        $shop_ids = (array) Shop::getCompleteListOfShopsID();
    }
    if (empty($shop_ids)) {
        // Single-shop install, or a core too old to answer: one pass with the
        // ambient context, which is what the non-multistore behaviour has
        // always been.
        $shop_ids = array(null);
    }

    $carried = array();
    foreach ($shop_ids as $id_shop) {
        // NEWER WINS, evaluated per shop. The 2.7.5 admin form reads and writes
        // the new key from the moment the files land, but this script runs only
        // when the module upgrade is actually triggered - so a merchant who saves
        // the position inside that window must not have it overwritten by the
        // stale old-key value when the script finally runs.
        $existing_new = ($id_shop === null)
            ? Configuration::get($new_key)
            : Configuration::get($new_key, null, null, (int) $id_shop);
        if (two_config_is_set($existing_new)) {
            continue;
        }

        // Read as a string and write it back unchanged. Casting to int here
        // would turn a row holding '' - which the resolver treats as "absent",
        // i.e. address area - into a 0, which means the payment tile. That is the
        // one transformation this migration must not perform.
        $stored_old = ($id_shop === null)
            ? Configuration::get($old_key)
            : Configuration::get($old_key, null, null, (int) $id_shop);
        if (!two_config_is_set($stored_old)) {
            continue;
        }

        $carried[] = array($id_shop === null ? null : (int) $id_shop, $stored_old);
    }

    // The delete is global, so it must fire whenever ANY old row exists at all -
    // not only when something was carried. A shop skipped by newer-wins, and a
    // row holding '' that was skipped as unset, both still need the stale old row
    // gone. hasKey() is the right question HERE and only here: this asks "is
    // there a row to delete", which is exactly what it answers.
    $any_old_row = Configuration::hasKey($old_key);
    foreach ($shop_ids as $id_shop) {
        if ($id_shop !== null && Configuration::hasKey($old_key, null, null, (int) $id_shop)) {
            $any_old_row = true;
        }
    }

    foreach ($carried as $pair) {
        list($id_shop, $stored) = $pair;
        if ($id_shop === null) {
            Configuration::updateValue($new_key, $stored);
        } else {
            Configuration::updateValue($new_key, $stored, false, null, (int) $id_shop);
        }
    }

    if ($any_old_row) {
        // One global delete, after every shop's value has been written under the
        // new name. deleteByName() is all-shops by design and there is no
        // per-shop variant, which is exactly why the reads had to finish first.
        Configuration::deleteByName($old_key);

        $summary = array();
        foreach ($carried as $pair) {
            $summary[] = ($pair[0] === null ? 'context' : ('shop ' . $pair[0])) . '="' . (string) $pair[1] . '"';
        }

        PrestaShopLogger::addLog(
            'Two Payment v2.7.5 upgrade: retired configuration key ' . $old_key . ' in favour of '
            . $new_key . '; ' . count($carried) . ' shop value(s) carried across unchanged'
            . (empty($summary)
                ? ' (every shop already resolved a value under the new name, or held no usable old value)'
                : ': ' . implode(', ', $summary))
            . ' (TWO-40)',
            1,
            null,
            'Module',
            $module->id
        );
    } else {
        PrestaShopLogger::addLog(
            'Two Payment v2.7.5 upgrade: no ' . $old_key . ' row to rename on any shop; ' . $new_key
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
