<?php
/**
 * UPGRADE SCRIPT: Version 2.7.17
 *
 * Adds `two_update_hash` VARCHAR(32) NULL to `twopayment` (TWO-26085).
 *
 * An order update (tracking save, admin edit, the confirmation sync) records
 * a hash of what Two accepted, so a later save that changes nothing sends
 * nothing. A NULL hash only means the next update is sent, so nothing is
 * backfilled.
 *
 * Guarded on information_schema rather than assumed absent: a shop installed
 * fresh on 2.7.17+ already has the column from createTwoTables(), and
 * ensureTwoPaymentColumns() may have added it at runtime on a shop whose
 * files were swapped in place without core running an upgrade.
 *
 * Created: 2026-09-30
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_17($module)
{
    $table_name = _DB_PREFIX_ . 'twopayment';

    $column_exists = (int)Db::getInstance()->getValue(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = '" . _DB_NAME_ . "'
         AND TABLE_NAME = '" . pSQL($table_name) . "'
         AND COLUMN_NAME = 'two_update_hash'"
    );

    if (!$column_exists
        && !Db::getInstance()->execute('ALTER TABLE `' . $table_name . '` ADD `two_update_hash` VARCHAR(32) NULL')
    ) {
        // Not a failed upgrade: without the column every update is simply sent, as before 2.7.17.
        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.7.17: Failed to add column two_update_hash to ' . $table_name
            . ' - every order update will be sent to Two, changed or not',
            3,
            null,
            'Module',
            $module->id
        );

        return true;
    }

    PrestaShopLogger::addLog(
        'TwoPayment: Successfully upgraded to version 2.7.17 - order updates that change nothing are no longer sent',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
