<?php
/**
 * UPGRADE SCRIPT: Version 2.7.0
 *
 * 2.7.0 moves the optional buyer reference fields out of the billing address
 * block and into the Two payment tile, adds two more of them, and makes all
 * four visible out of the box on a FRESH install.
 *
 * WHAT THIS SCRIPT DOES - seed only, never overwrite:
 *
 *   PS_TWO_ENABLE_DEPARTMENT      -> 1  ONLY if the key is absent
 *   PS_TWO_ENABLE_PROJECT         -> 1  ONLY if the key is absent
 *   PS_TWO_ENABLE_PO_NUMBER       -> 1  new key, so absent everywhere
 *   PS_TWO_ENABLE_INVOICE_EMAIL   -> 1  new key, so absent everywhere
 *
 * A stored value is treated as a merchant's choice regardless of how it got
 * there, which is the same call the WooCommerce plugin makes. `hasKey()` is
 * therefore the gate on every one of the four, not just the new ones, and the
 * script is safe to run again.
 *
 * EXPECTED CONSEQUENCE, and it is accepted rather than a bug: on department and
 * project this is close to a NO-OP for existing shops. The admin form has
 * always saved both keys on every submit, so practically every live shop
 * already carries a stored 0 - a 0 that came from install() never writing a
 * default (the switches therefore rendered off) rather than from a decision.
 * Those shops keep department and project OFF until a merchant switches them
 * on; only the two new fields, purchase order number and invoice email, appear
 * at checkout after upgrading. A near-empty log line here is the correct
 * outcome, not a broken upgrade.
 *
 * Fresh installs are unaffected by any of this: install() writes all four keys
 * to 1 directly.
 *
 * There is no data migration. Nothing was ever stored against these fields:
 * department and project were injected into the address form but PrestaShop's
 * address table has no columns for them, so the submitted values were
 * discarded and the order payload sent them empty. From 2.7.0 the values are
 * submitted with the payment form and reach Two on order creation.
 *
 * Created: 2026-07-28
 *
 * @param Twopayment $module
 * @return bool
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_0($module)
{
    $enabled_by_default = array(
        'PS_TWO_ENABLE_DEPARTMENT',
        'PS_TWO_ENABLE_PROJECT',
        'PS_TWO_ENABLE_PO_NUMBER',
        'PS_TWO_ENABLE_INVOICE_EMAIL',
    );

    $seeded = array();
    foreach ($enabled_by_default as $key) {
        // Absent only. A stored 0 is a choice and survives this upgrade.
        if (Configuration::hasKey($key)) {
            continue;
        }
        Configuration::updateValue($key, 1);
        $seeded[] = $key;
    }

    if (!empty($seeded)) {
        PrestaShopLogger::addLog(
            'Two Payment v2.7.0 upgrade: seeded the absent optional checkout field switches (' .
            implode(', ', $seeded) . ') to 1 - they render in the Two payment tile instead of the billing address block. ' .
            'Keys already stored were left exactly as the merchant had them',
            1,
            null,
            'Module',
            $module->id
        );
    }

    return true;
}
