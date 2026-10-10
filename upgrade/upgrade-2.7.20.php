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
 * A shop with no mapping, or one already stored as rows, is left as it is, so
 * the script is safe to run again. It carries the global tier only, like
 * upgrade-2.7.12.php, and returns true on every path: a mapping it could not
 * move is reported by the reader as unreadable and re-saved on the form.
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
            'Two Payment: Upgraded to version 2.7.20 - moved the tax code mapping of ' . (int) $moved . ' tax rules group(s) to the per-rule rows',
            1,
            null,
            'Module',
            (int) $module->id
        );
    } catch (Throwable $e) {
        PrestaShopLogger::addLog(
            'Two Payment: Upgrade to version 2.7.20 could not move the tax code mapping - ' . $e->getMessage() . '; re-save it on the Order management form',
            3,
            null,
            'Module',
            (int) $module->id
        );
    }

    return true;
}
