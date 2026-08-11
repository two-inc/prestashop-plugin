<?php
/**
 * UPGRADE SCRIPT: Version 2.7.6
 *
 * Carries the company-search location setting across the rename
 * `PS_TWO_ENABLE_COMPANY_NAME` -> `PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS`
 * (TWO-40, item #1). The old name said "enable company name", which has not
 * been what the switch does since TWO-25326 §7.1 - it decides WHERE the one
 * company-search control renders (address entry vs payment tile), never
 * whether it exists.
 *
 * WHAT IT DOES
 *
 * Reads the old key through the ordinary resolving API, writes that value to
 * the new key if there was one AND the new key does not already hold one, and
 * deletes the old key. Nothing else. A shop that never had the old key keeps
 * the new key's own default ('1', address area), which is the behaviour the old
 * default produced too, so a fresh install and a migrated shop end up in the
 * same place.
 *
 * The delete is the LAST thing that happens and it is conditional on the copy:
 * `Configuration::updateValue()` returns an accumulated Db result and can be
 * falsy WITHOUT throwing, so when a copy was attempted and did not land, the
 * old row is the only surviving copy of the merchant's position and is kept
 * deliberately - logged at severity 3, telling the operator to re-run. Deleting
 * it there would destroy the value the script exists to preserve. On every
 * other path (nothing usable to carry, or the new key already holding a usable
 * value) the old row carries nothing and is removed.
 *
 * DELIBERATELY GLOBAL-TIER-ONLY - READ THIS BEFORE CALLING IT A BUG
 *
 * PrestaShop has THREE configuration tiers, not two: global
 * (id_shop_group NULL, id_shop NULL), shop group (group set, shop NULL) and
 * shop (group NULL, shop set). `Configuration::get()` resolves ACROSS those
 * tiers against the ambient context and `Configuration::updateValue()` writes
 * into whichever tier that context selects - but
 * `Configuration::deleteByName()` is NAME-WIDE and unconditional, with no
 * per-tier variant at all.
 *
 * So this script carries exactly ONE value across - whatever the ambient
 * context resolved to, which on an upgrade is the global row - and then
 * destroys every row of the old key at every tier. A shop-group or per-shop
 * OVERRIDE of the old key is deleted without being carried, and that shop
 * silently reverts to the new key's default instead of the position its
 * merchant chose.
 *
 * That loss is ACCEPTED, on Doug's explicit ruling: this module has no live
 * merchants, so no such override exists in the wild, and the tier-exact
 * migration this would otherwise need was attempted three times and produced
 * three distinct variants of silent merchant data loss. `.ai/decisions.md`
 * records what a SAFE rename requires - direct tier-by-tier `ps_configuration`
 * SQL rather than any tier-inferring API, plus multistore CI coverage and a
 * shop dimension in the offline `Configuration` test double. If this plugin
 * ever has multistore merchants, that is the work; do not assume this script
 * covers it.
 *
 * THE FILE-SWAP WINDOW, which this script cannot close
 *
 * Upgrade scripts only run via the back-office Module Manager or
 * `dev/ci/upgrade-module.sh`. A deploy that merely REPLACES the module's files
 * - which is how the git-synced shops update - does NOT run them. So between
 * the file swap and the upgrade actually being run, the new key is absent,
 * the resolver falls back to its default, and a shop configured for tile mode
 * silently gets the search back in the address area on a live storefront.
 *
 * Address autofill comes back with it only where `PS_TWO_ADDRESS_LOOKUP` is
 * absent or '1'. `getAddressLookupEnabled()` force-returns '0' while the
 * search is not in the address area, so once the resolver flips back to the
 * address-area default it reads that row again - but a shop that picked tile
 * mode THROUGH THE ADMIN FORM had that row written to 0 by the very same save
 * (`saveTwoCompanyLookupFormValues()` gates the write on
 * `isAddressLookupSettingAvailable()`), so autofill stays off there. The
 * autofill half of this window therefore bites shops whose tile mode was
 * seeded programmatically - as the e2e suite's tile-location spec does - not
 * ones that clicked it in the back office.
 *
 * There is deliberately NO read shim for the old key. Doug's ruling: "not a
 * permanent alias". Running the upgrade once after a file-swap deploy is
 * therefore a real release step, not a formality - and the ONLY things that
 * run it are the back-office Module Manager -> Upgrade action and
 * `dev/ci/upgrade-module.sh`. Opening the module's own CONFIGURATION page does
 * NOT run any upgrade script; no PrestaShop code path executes
 * `upgrade/*.php` from there.
 *
 * WHY A NEW VERSION RATHER THAN AN EDIT TO AN EXISTING SCRIPT
 *
 * PrestaShop discovers upgrade scripts BY FILENAME and runs
 * `upgrade/upgrade-<version>.php` only for versions strictly ABOVE the
 * installed one, deriving the function name from the filename. Appending this
 * migration to 2.7.5's script would mean it never runs on a shop that already
 * reached 2.7.5 - silently, with `number_upgraded=0`.
 *
 * IDEMPOTENCY
 *
 * A second run finds no old key at all: `Configuration::get()` returns false,
 * nothing is copied, and `deleteByName()` on an absent name is a no-op. A
 * fresh 2.7.6 install never reaches this script.
 *
 * The copy is guarded on BOTH keys - the old one having a usable value AND the
 * new one having none yet. The second half is not just re-run protection: the
 * file-swap window above lets a merchant open the config page and SAVE a
 * position (new key written, old row untouched) before any upgrade has run, so
 * a later Module Manager upgrade would otherwise copy the stale old row over
 * the choice the merchant just made and move the search back to the address
 * area. So the rule is "the new key wins whenever it has a value", of which
 * "a re-run changes nothing" is one case, not the other way round.
 *
 * It cannot fail the upgrade: everything is wrapped and this function returns
 * true unconditionally, same reasoning as the 2.7.1-2.7.5 scripts. A shop
 * whose setting could not be carried must still finish upgrading - it lands on
 * the default, which is a wrong position, not a broken shop.
 *
 * Created: 2026-08-11
 *
 * @param Twopayment $module
 * @return bool
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_6($module)
{
    // Every fact the log message is built from lives out here, so the throw
    // path reports exactly the same state the success path does - it cannot
    // claim a copy that did not land, nor omit an old row that is still there.
    $old = false;
    $new = false;
    $oldRead = false;
    $newRead = false;
    $oldUsable = false;
    $newAlreadySet = false;
    $copyAttempted = false;
    $carried = false;
    $keptOldRow = false;
    $deleteAttempted = false;
    $deleted = false;
    $threw = null;

    try {
        // Resolving read in the ambient context - see the header: this is the
        // one value that gets carried, and the delete below is name-wide.
        $old = Configuration::get('PS_TWO_ENABLE_COMPANY_NAME');
        $oldRead = true;

        // '' is treated as ABSENT for BOTH keys here because the resolver
        // (isCompanySearchInAddressArea()) treats it that way too: an empty
        // row means "no position chosen". Note this is deliberately NOT
        // Configuration::hasKey(), which counts an empty row as SET - a
        // hasKey() guard on the new key would suppress the copy on a row that
        // the module itself reads as absent, and lose the value.
        $oldUsable = ($old !== false && $old !== null && $old !== '');

        // The new key wins whenever it already holds a usable value: the
        // file-swap window lets a merchant save a position before this script
        // ever runs, and copying the stale old row over it would silently move
        // the search back to the address area. See the header's IDEMPOTENCY
        // section.
        $new = Configuration::get('PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS');
        $newRead = true;
        $newAlreadySet = ($new !== false && $new !== null && $new !== '');

        if ($oldUsable && !$newAlreadySet) {
            $copyAttempted = true;
            // updateValue() returns an accumulated Db result and CAN be falsy
            // without throwing (a Validate failure, a failed Db::execute). Its
            // answer decides whether the old row is still the only copy of the
            // value, so it must not be discarded.
            $carried = (bool) Configuration::updateValue('PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS', $old);
        }

        // THE ONE PLACE that decides whether the old row is destroyed. It is
        // kept only when a copy was attempted and failed, because then it holds
        // the sole surviving copy of the merchant's chosen position; deleting it
        // there would be the data loss this whole script exists to avoid. On
        // every other path - nothing usable to carry, or the new key already
        // holding a usable value - the old row is redundant and goes.
        $keptOldRow = ($copyAttempted && !$carried);
        if (!$keptOldRow) {
            $deleteAttempted = true;
            // Returns false without throwing on a Validate failure or a failed
            // Db::execute, in which case the old row is still there and the log
            // below must not claim the rename completed.
            $deleted = (bool) Configuration::deleteByName('PS_TWO_ENABLE_COMPANY_NAME');
        }
    } catch (Throwable $e) {
        // Deliberately broad, same reasoning as the 2.7.1-2.7.5 scripts:
        // anything thrown here leaves the module version un-bumped and the
        // shop in a state no later script can reason about. The message is
        // built from the recorded state below, not from an assumption about
        // WHERE the throw came from.
        $threw = $e->getMessage();
    }

    $severity = 1;

    if (!$oldRead) {
        $outcome = 'nothing was migrated - the old key could not be read';
    } elseif (!$newRead) {
        $outcome = 'nothing was copied - the new key could not be read';
    } elseif ($carried) {
        $outcome = 'carried the stored value "' . $old . '" across (global tier only; any shop-group or'
            . ' per-shop override of the old key was deleted uncarried, see this script\'s header)';
    } elseif ($copyAttempted) {
        $outcome = 'copying the stored value "' . $old . '" to the new key FAILED';
    } elseif ($newAlreadySet) {
        // Distinguished from the no-old-value case on purpose: an operator
        // reading the log must be able to tell "already migrated, or the
        // merchant already chose a position" from "there was nothing to carry".
        $outcome = 'the new key already held "' . $new . '", so the copy was skipped and that value kept'
            . ($oldUsable
                ? ' in preference to the old key\'s "' . $old . '"'
                : ' (the old key held no usable value either)');
    } else {
        $outcome = 'no usable value on the old key, the new key keeps its own default';
    }

    if ($deleted) {
        $outcome .= '; the old key PS_TWO_ENABLE_COMPANY_NAME was removed';
    } elseif ($keptOldRow) {
        $outcome .= '; PS_TWO_ENABLE_COMPANY_NAME was deliberately KEPT because it now holds the only'
            . ' copy of the value - re-run this upgrade once the write failure is resolved';
        $severity = 3;
    } elseif ($deleteAttempted) {
        $outcome .= '; deleting the old key FAILED, so PS_TWO_ENABLE_COMPANY_NAME may still be present in'
            . ' ps_configuration and a later run of this script will see it again';
        $severity = max($severity, 2);
    } else {
        $outcome .= '; the delete never ran, so PS_TWO_ENABLE_COMPANY_NAME may still be present in'
            . ' ps_configuration and a later run of this script will see it again';
        $severity = max($severity, 2);
    }

    if ($threw !== null) {
        PrestaShopLogger::addLog(
            'Two Payment v2.7.6 upgrade: PS_TWO_ENABLE_COMPANY_NAME -> PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS'
            . ' raised "' . $threw . '" - ' . $outcome . ' (TWO-40)',
            max($severity, 2),
            null,
            'Module',
            $module->id
        );

        return true;
    }

    PrestaShopLogger::addLog(
        'Two Payment v2.7.6 upgrade: PS_TWO_ENABLE_COMPANY_NAME -> PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS - '
        . $outcome . ' (TWO-40)',
        $severity,
        null,
        'Module',
        $module->id
    );

    return true;
}
