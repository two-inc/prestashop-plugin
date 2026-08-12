<?php
/**
 * UPGRADE SCRIPT: Version 2.7.7
 *
 * Adds the order-scoped buyer-company columns to `twopayment` (TWO-40):
 *
 *   - `two_organization_number` VARCHAR(64) NULL
 *   - `two_company_name`        VARCHAR(255) NULL
 *
 * WHY THESE COLUMNS EXIST. The buyer company can only be resolved in the
 * buyer's own request: it comes from the cart-scoped company selection, and the
 * cart has rotated by the time an admin order edit, a tracking-number save or a
 * provider webhook reaches getTwoUpdateOrderData(). With nowhere to read it
 * from, that path fell through to the address's own identifier fields, which
 * hold nothing for a `TWO:`-prefixed identifier (core's isDniLite rejects the
 * colon, so the value never reaches `dni`) and nothing at all in a country
 * without need_identification_number - so the order UPDATE PUT an empty
 * organization_number over a good one. The created order now persists what it
 * sent and the update path reads it back, exactly as `two_day_on_invoice`
 * already does for the payment term (TWO-24752).
 *
 * NOTHING IS BACKFILLED, deliberately. The value is only knowable from the
 * buyer's request, and that request is long gone for every existing order.
 * Guessing one from the address would write the same empty-or-wrong value the
 * columns exist to avoid. Orders placed before this upgrade keep the previous
 * behaviour - getTwoUpdateOrderData() falls back to re-resolving - and that
 * fallback is why an empty column must read as "no snapshot" rather than as a
 * snapshot of nothing.
 *
 * Guarded on information_schema per column rather than assumed absent: a shop
 * installed fresh on 2.7.7+ already has them from createTwoTables(), and
 * ensureTwoOrderCompanyColumns() may have added them at runtime on a shop whose
 * files were swapped in place without core ever running an upgrade. All three
 * routes must be idempotent.
 *
 * Created: 2026-08-11
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_7($module)
{
    $table_name = _DB_PREFIX_ . 'twopayment';

    $columns_to_add = array(
        'two_organization_number' => 'ALTER TABLE `' . $table_name
            . '` ADD `two_organization_number` VARCHAR(64) NULL',
        'two_company_name' => 'ALTER TABLE `' . $table_name
            . '` ADD `two_company_name` VARCHAR(255) NULL',
    );

    foreach ($columns_to_add as $column_name => $query) {
        $column_exists = (int)Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '" . _DB_NAME_ . "'
             AND TABLE_NAME = '" . pSQL($table_name) . "'
             AND COLUMN_NAME = '" . pSQL($column_name) . "'"
        );

        if ($column_exists) {
            continue;
        }

        if (!Db::getInstance()->execute($query)) {
            PrestaShopLogger::addLog(
                'TwoPayment Upgrade 2.7.7: Failed to add column ' . $column_name . ' to ' . $table_name,
                3,
                null,
                'Module',
                $module->id
            );

            // Returning false here is the right call, unlike in upgrade-2.7.6:
            // there the failure left a merchant setting intact and disabling the
            // module would have been the greater harm, whereas a missing column
            // means every order-creation write to this table fails. Better a
            // loud failed upgrade than a shop that silently loses orders.
            return false;
        }

        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.7.7: Added column ' . $column_name . ' to ' . $table_name,
            1,
            null,
            'Module',
            $module->id
        );
    }

    PrestaShopLogger::addLog(
        'TwoPayment: Successfully upgraded to version 2.7.7 - the buyer company the order was created '
        . 'with is now persisted, so post-order updates stop sending an empty organisation number',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
