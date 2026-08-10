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

    // THE HARD PART OF THIS SCRIPT, and the part three review rounds have now been
    // spent on. Read all of this before changing any of it.
    //
    // The asymmetry that makes a rename dangerous where an ordinary setting read
    // is not: Configuration::get()/updateValue() are SHOP-SCOPED, but
    // deleteByName() is GLOBAL and unconditional, with no per-shop variant. So the
    // obvious three-line rename - read, write, delete - reads one shop's value and
    // then destroys every other shop's. That is why this is not three lines, and
    // it is why "the rest of the module is context-scoped too" is not a defence:
    // the other 198 Configuration::get() calls in twopayment.php only ever READ.
    //
    // Three further traps, each of which shipped as a defect in an earlier round of
    // this same script:
    //
    //  1. hasKey($key, null, null, $idShop) is a bare isset() on the PER-SHOP cache
    //     with NO fallback to the global (id_shop NULL) row - identical in 1.7.8,
    //     8.1 and 9.0. get() DOES fall back shop -> group -> global. install() runs
    //     Shop::setContext(Shop::CONTEXT_ALL), so install-seeded rows are GLOBAL.
    //     A per-shop hasKey() loop therefore answers "no row" for every shop on a
    //     stock multistore install, carries nothing, and then deletes the global
    //     row anyway.
    //
    //  2. But get()-per-shop is not the answer on its own either, because it
    //     resolves THROUGH the global row - so writing back what it returns
    //     materialises a per-shop row for every shop even when the only row was
    //     global. That permanently changes precedence: a later back-office save
    //     made in "all shops" context writes the global row, which the per-shop
    //     rows we invented now shadow, and the merchant's save silently does
    //     nothing. So the two tiers must be migrated AS tiers - global to global,
    //     per-shop to per-shop - which is what getGlobalValue()/updateGlobalValue()
    //     are for, and what hasKey()-per-shop is genuinely good at (detecting a row
    //     that really is per-shop).
    //
    //  3. "Set" means what the RESOLVER means, not what hasKey() means. A row
    //     holding '' is present to hasKey() but resolves to the address-area
    //     DEFAULT in Twopayment::isCompanySearchInAddressArea(). Gating on hasKey()
    //     let an empty new-key row - a shape saveTwoCompanyLookupFormValues() can
    //     write from a POST that omits the field - look like a deliberate choice,
    //     suppress the copy, and lose the merchant's tile setting. Every decision
    //     below goes through two_config_is_set().
    //
    // SNAPSHOT EVERY READ BEFORE THE FIRST WRITE. Writing the global tier first
    // would make the per-shop newer-wins checks below see a value this migration
    // itself had just written.
    //
    // The single-shop branch is deliberately kept simple and separate rather than
    // folded into the multistore one: on a single-shop install the global-row APIs
    // are not the right question, and the ambient context read is exactly the
    // behaviour this module has always had.
    $carried = array();

    if (!Shop::isFeatureActive()) {
        $stored_old = Configuration::get($old_key);
        $stored_new = Configuration::get($new_key);

        // Carried as a STRING, unchanged. Casting to int would turn a row holding
        // '' - which the resolver treats as absent, i.e. address area - into 0,
        // which means the payment tile. That is the one transformation this
        // migration must not perform.
        if (two_config_is_set($stored_old) && !two_config_is_set($stored_new)) {
            Configuration::updateValue($new_key, $stored_old);
            $carried[] = 'shop="' . (string) $stored_old . '"';
        }
    } else {
        // --- snapshot -------------------------------------------------------
        $global_old = Configuration::getGlobalValue($old_key);
        $global_new = Configuration::getGlobalValue($new_key);

        $shop_ids = (array) Shop::getCompleteListOfShopsID();
        $shop_old = array();
        $shop_resolves_new = array();
        foreach ($shop_ids as $id_shop) {
            $id_shop = (int) $id_shop;

            // hasKey-per-shop is the RIGHT question here and only here: "is there a
            // row that genuinely belongs to this shop", as opposed to one it merely
            // inherits from the global tier. A shop that only inherits must keep
            // inheriting, or trap 2 above bites.
            if (Configuration::hasKey($old_key, null, null, $id_shop)) {
                $shop_old[$id_shop] = Configuration::get($old_key, null, null, $id_shop);
            }

            // Whereas THIS is a resolution question - "does this shop already end
            // up with a value under the new name, from any tier" - so it is get().
            $shop_resolves_new[$id_shop] = two_config_is_set(
                Configuration::get($new_key, null, null, $id_shop)
            );
        }

        // --- write, tier for tier -------------------------------------------
        if (two_config_is_set($global_old) && !two_config_is_set($global_new)) {
            Configuration::updateGlobalValue($new_key, $global_old);
            $carried[] = 'all-shops="' . (string) $global_old . '"';
        }

        foreach ($shop_old as $id_shop => $stored_old) {
            // NEWER WINS, per shop: the 2.7.5 admin form reads and writes the new
            // key from the moment the files land, but this script runs only when
            // the module upgrade is actually triggered. A merchant who saved the
            // position inside that window must not have it reverted here.
            if ($shop_resolves_new[$id_shop]) {
                continue;
            }
            if (!two_config_is_set($stored_old)) {
                continue;
            }
            Configuration::updateValue($new_key, $stored_old, false, null, $id_shop);
            $carried[] = 'shop ' . $id_shop . '="' . (string) $stored_old . '"';
        }
    }

    // UNCONDITIONAL, and deliberately not guarded by a "does a row exist" check.
    // deleteByName() on an absent key is a DELETE that matches nothing - harmless
    // and idempotent - whereas every attempt to detect "is there an old row
    // anywhere" has to reason about the global-vs-per-shop cache split all over
    // again, and got it wrong twice. Removing the detection removes the bug class.
    // It runs after every read above, which is why the snapshot had to come first.
    Configuration::deleteByName($old_key);

    if (!empty($carried)) {
        PrestaShopLogger::addLog(
            'Two Payment v2.7.5 upgrade: retired configuration key ' . $old_key . ' in favour of '
            . $new_key . '; carried across unchanged: ' . implode(', ', $carried) . ' (TWO-40)',
            1,
            null,
            'Module',
            $module->id
        );
    } else {
        PrestaShopLogger::addLog(
            'Two Payment v2.7.5 upgrade: nothing to carry from ' . $old_key . ' on any shop (no usable'
            . ' value, or every shop already resolves one under the new name); ' . $new_key
            . ' resolves to the address-area default where it is unset (TWO-40)',
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
