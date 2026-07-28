<?php
/**
 * UPGRADE SCRIPT: Version 2.7.0
 *
 * 2.7.0 moves the optional buyer reference fields out of the billing address
 * block and into the Two payment tile, adds two more of them, and makes all
 * four visible out of the box (ABN-472).
 *
 * WHAT THIS CHANGES ON AN EXISTING SHOP - deliberately, and it IS a visible
 * checkout change:
 *
 *   PS_TWO_ENABLE_DEPARTMENT      -> 1
 *   PS_TWO_ENABLE_PROJECT         -> 1
 *   PS_TWO_ENABLE_PO_NUMBER       -> 1  (new key)
 *   PS_TWO_ENABLE_INVOICE_EMAIL   -> 1  (new key)
 *
 * These are WRITTEN, not seeded-if-absent, and that is the whole point of the
 * script. Seeding only absent keys would have been very nearly a no-op: the
 * admin form has always saved department and project on every submit, so
 * practically every live shop already carries a stored 0 for both - a 0 that
 * came from install() never writing a default and the switches therefore
 * rendering off, not from a merchant deciding these fields were unwanted. The
 * agreed cross-platform out-of-box state is all four fields visible, and an
 * upgraded shop is expected to land on it. A merchant who wants one off turns
 * it off in the module configuration afterwards; the switch still works and
 * nothing here runs again.
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

    foreach ($enabled_by_default as $key) {
        Configuration::updateValue($key, 1);
    }

    PrestaShopLogger::addLog(
        'Two Payment v2.7.0 upgrade: enabled the optional checkout fields (' .
        implode(', ', $enabled_by_default) . ') - they now render in the Two payment tile instead of the billing address block (ABN-472)',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
