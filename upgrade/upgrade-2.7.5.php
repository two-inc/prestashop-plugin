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

function upgrade_module_2_7_5($module)
{
    $old_key = 'PS_TWO_ENABLE_COMPANY_NAME';
    $new_key = 'PS_TWO_COMPANY_SEARCH_LOCATION';

    // PER SHOP, not once for the context. This is the whole reason this block is
    // a loop rather than three lines (adversarial review, MAJOR):
    //
    //   - Configuration::get() resolves ONE value out of the current shop
    //     context;
    //   - Configuration::updateValue() writes across the context's shop list;
    //   - Configuration::deleteByName() removes the row for EVERY shop,
    //     unconditionally.
    //
    // So a context-scoped read followed by a global delete collapses a
    // multistore install onto whichever value the upgrade happened to run in:
    // shop A on the payment tile and shop B in the address area come out both
    // set to one of the two, silently. install() seeds its defaults at
    // Shop::CONTEXT_ALL, but the admin save path is a plain updateValue(), so
    // per-shop rows genuinely exist on any multistore shop whose merchant has
    // saved these settings in a single-shop context.
    //
    // Read every shop's value FIRST, then write, then delete once at the end -
    // the delete cannot be inside the loop, because the first iteration would
    // wipe the rows the later iterations still need to read.
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

    // A value already stored under the NEW name always wins, and the old row is
    // then deleted without being copied (adversarial review, MAJOR: ordering
    // hazard). The 2.7.5 admin form reads and writes the new key from the moment
    // the files land, but this script runs only when the module upgrade is
    // actually triggered - so a merchant who saves the position inside that
    // window would otherwise have their save overwritten by the stale old-key
    // value when the script finally runs. Newer wins; nothing is lost either way.
    $new_key_already_set = Configuration::hasKey($new_key);

    $carried = array();
    foreach ($shop_ids as $id_shop) {
        if ($id_shop === null) {
            if (!Configuration::hasKey($old_key)) {
                continue;
            }
            if ($new_key_already_set) {
                continue;
            }
            // Read as a string and write it back unchanged. Casting to int here
            // would turn a row holding '' - which the resolver treats as
            // "absent", i.e. address area - into a 0, which means the payment
            // tile. That is the one transformation this migration must not
            // perform.
            $carried[] = array(null, Configuration::get($old_key));
            continue;
        }

        if (!Configuration::hasKey($old_key, null, null, (int) $id_shop)) {
            continue;
        }
        if (Configuration::hasKey($new_key, null, null, (int) $id_shop)) {
            continue;
        }
        $carried[] = array((int) $id_shop, Configuration::get($old_key, null, null, (int) $id_shop));
    }

    // The delete below is global, so it must happen whenever ANY old row exists -
    // not only when something was carried. A shop whose value was skipped because
    // the new key already held one still needs its stale old row gone.
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
            . $new_key . '; ' . count($carried) . ' shop row(s) carried across unchanged'
            . (empty($summary) ? ' (all shops already held a value under the new name)' : ': ' . implode(', ', $summary))
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
