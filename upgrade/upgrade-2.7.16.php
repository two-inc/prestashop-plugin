<?php
/**
 * UPGRADE SCRIPT: Version 2.7.16
 *
 * Buyer surcharge replay on order updates (TWO-26076):
 *
 *   - adds `two_not_sent_at` DATETIME NULL to `twopayment`: when set, an order
 *     edit or tracking number saved in PrestaShop has not reached Two, and the
 *     order page says so until an update is accepted.
 *   - records as retired every product id the fee reference was sold under.
 *     An order update recognises the fee row by a current or retired fee id
 *     AND the fee reference, but retirements were only recorded from this
 *     release. A fee product recreated earlier left its old id unrecorded, so
 *     without this seed its orders would replay no fee at all.
 *
 * Both steps are idempotent and have runtime fallbacks
 * (ensureTwoPaymentColumns() and getTwoSurchargeProductIds()), for a shop whose
 * files were swapped in place without core running this script.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_16($module)
{
    $table_name = _DB_PREFIX_ . 'twopayment';
    $column_exists = (int) Db::getInstance()->getValue(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = '" . _DB_NAME_ . "'
         AND TABLE_NAME = '" . pSQL($table_name) . "'
         AND COLUMN_NAME = 'two_not_sent_at'"
    );

    if (!$column_exists && !Db::getInstance()->execute('ALTER TABLE `' . $table_name . '` ADD `two_not_sent_at` DATETIME NULL')) {
        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.7.16: Failed to add column two_not_sent_at to ' . $table_name,
            3,
            null,
            'Module',
            $module->id
        );

        return false;
    }

    $module->seedTwoRetiredSurchargeProductIds();

    PrestaShopLogger::addLog(
        'TwoPayment: Successfully upgraded to version 2.7.16 - order updates replay the surcharge as recorded, '
        . 'and an update that does not reach Two is shown on the order page',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
