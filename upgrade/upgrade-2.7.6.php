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
 * the new key if there was one, and deletes the old key. Nothing else. A shop
 * that never had the old key keeps the new key's own default ('1', address
 * area), which is the behaviour the old default produced too, so a fresh
 * install and a migrated shop end up in the same place.
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
 * the file swap and someone opening the back office, the new key is absent,
 * the resolver falls back to its default, and a shop configured for tile mode
 * silently gets the search back in the address area AND address autofill
 * re-enabled (`getAddressLookupEnabled()` keys off the same resolver) on a
 * live storefront.
 *
 * There is deliberately NO read shim for the old key. Doug's ruling: "not a
 * permanent alias". Opening the module's configuration page once after a
 * file-swap deploy is therefore a real release step, not a formality.
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
 * nothing is copied, and `deleteByName()` on an absent name is a no-op. The
 * new key keeps whatever it holds - the copy is guarded on the OLD key having
 * a usable value, so a re-run can never overwrite a position the merchant set
 * after the first run. A fresh 2.7.6 install never reaches this script.
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
    try {
        // Resolving read in the ambient context - see the header: this is the
        // one value that gets carried, and the delete below is name-wide.
        $old = Configuration::get('PS_TWO_ENABLE_COMPANY_NAME');

        $carried = false;
        if ($old !== false && $old !== null && $old !== '') {
            // '' is treated as ABSENT here because the resolver
            // (isCompanySearchInAddressArea()) treats it that way too: an
            // empty row means "no position chosen", and copying it across
            // would carry nothing while looking like it carried something.
            Configuration::updateValue('PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS', $old);
            $carried = true;
        }

        Configuration::deleteByName('PS_TWO_ENABLE_COMPANY_NAME');
    } catch (Throwable $e) {
        // Deliberately broad, same reasoning as the 2.7.1-2.7.5 scripts:
        // anything thrown here leaves the module version un-bumped and the
        // shop in a state no later script can reason about.
        PrestaShopLogger::addLog(
            'Two Payment v2.7.6 upgrade: company-search location key rename raised "' . $e->getMessage()
            . '" and was skipped, so PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS may be absent and the shop'
            . ' resolving to the address-area default (TWO-40)',
            2,
            null,
            'Module',
            $module->id
        );

        return true;
    }

    PrestaShopLogger::addLog(
        'Two Payment v2.7.6 upgrade: PS_TWO_ENABLE_COMPANY_NAME -> PS_ENABLE_COMPANY_SEARCH_IN_ADDRESS - '
        . ($carried
            ? 'carried the stored value "' . $old . '" across (global tier only; any shop-group or'
                . ' per-shop override of the old key was deleted uncarried, see this script\'s header)'
            : 'no usable value on the old key, the new key keeps its own default')
        . ' (TWO-40)',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
