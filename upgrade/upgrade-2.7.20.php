<?php
/**
 * UPGRADE SCRIPT: Version 2.7.20
 *
 * Moves the tax code mapping from one code per tax rules group to the rows the
 * Order management form now shows per group (TWO-26153): a buyer in another EU
 * country with a VAT number, each 0% tax rule, and no rule for the address.
 * Each group's old code is copied to its exempt row, its no-rule row and every
 * 0% tax rule the group has now, so every line the old mapping covered keeps
 * its code. Tax rules added later start on (none).
 *
 * It moves the global tier and, on a multistore shop, every shop group and
 * shop tier that holds a mapping of its own. A tier with no mapping, or one
 * already stored as rows, is left as it is, so the script is safe to run
 * again. It returns true on every path: a tier it could not write is logged,
 * keeps its old value, and the module still reads that value as the rows it
 * would have become until the merchant saves the form.
 *
 * Created: 2026-10-10
 *
 * @param Twopayment $module
 * @return bool
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_20($module)
{
    try {
        $moved = $module->migrateTwoTaxCodeMapToRows();
        PrestaShopLogger::addLog(
            'Two Payment: Upgraded to version 2.7.20 - moved ' . (int) $moved . ' tax rules group mapping(s) to the per-rule rows',
            1,
            null,
            'Module',
            (int) $module->id
        );
    } catch (Throwable $e) {
        PrestaShopLogger::addLog(
            'Two Payment: Upgrade to version 2.7.20 could not move every tax code mapping - ' . $e->getMessage() . '; it is still read as before, and saving the Order management form stores it as rows',
            3,
            null,
            'Module',
            (int) $module->id
        );
    }

    return true;
}
