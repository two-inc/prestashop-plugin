<?php
/**
 * UPGRADE SCRIPT: Version 2.7.17
 *
 * Adds two columns to `twopayment` (TWO-26085):
 *
 * - `two_update_hash` VARCHAR(32) NULL: an order update (tracking save, admin
 *   edit, the confirmation sync) records a hash of what Two accepted, so a
 *   later save that changes nothing sends nothing. A NULL hash only means the
 *   next update is sent.
 * - `two_declared_rates` TEXT NULL: the shipping and gift wrapping rates the
 *   order's create payload declared. An update falls back to them where the
 *   order itself records no usable rate, as a carrier-less order does. A NULL
 *   only removes that fallback.
 *
 * Nothing is backfilled. Guarded on information_schema rather than assumed
 * absent: a shop installed fresh on 2.7.17+ already has the columns from
 * createTwoTables(), and ensureTwoPaymentColumns() may have added them at
 * runtime on a shop whose files were swapped in place without core running
 * an upgrade.
 *
 * Created: 2026-09-30
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_17($module)
{
    $table_name = _DB_PREFIX_ . 'twopayment';
    $columns = array(
        'two_update_hash' => array('VARCHAR(32) NULL', 'every order update will be sent to Two, changed or not'),
        'two_declared_rates' => array('TEXT NULL', 'an update of an order that records no usable shipping or wrapping rate will fail rather than use the rate declared at placement'),
    );

    foreach ($columns as $column => $spec) {
        $column_exists = (int)Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '" . _DB_NAME_ . "'
             AND TABLE_NAME = '" . pSQL($table_name) . "'
             AND COLUMN_NAME = '" . pSQL($column) . "'"
        );

        if (!$column_exists
            && !Db::getInstance()->execute('ALTER TABLE `' . $table_name . '` ADD `' . $column . '` ' . $spec[0])
        ) {
            // Not a failed upgrade: the shop keeps working without the column, as before 2.7.17.
            PrestaShopLogger::addLog(
                'TwoPayment Upgrade 2.7.17: Failed to add column ' . $column . ' to ' . $table_name . ' - ' . $spec[1],
                3,
                null,
                'Module',
                $module->id
            );
        }
    }

    PrestaShopLogger::addLog(
        'TwoPayment: Upgraded to version 2.7.17 - order updates that change nothing are no longer sent,'
        . ' and updates fall back to the rates declared at placement',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
