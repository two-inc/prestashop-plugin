<?php
/**
 * UPGRADE SCRIPT: Version 2.7.19
 *
 * Creates `twopayment_cart_record` (TWO-26094), which holds the cart-scoped
 * company selection and mirror-write record server-side, keyed by cart and
 * shop. They used to live in the PrestaShop cookie, where a `|` or `¤` in a
 * company name, or a long address, made core's cookie write throw.
 *
 * Each row carries the cart's `id_customer`, so psgdpr erasure and export find
 * a customer's rows even after psgdpr 2.x has reassigned their carts to its
 * anonymous customer. A table created by an earlier build of this version gets
 * the column added, and every row is backfilled from `ps_cart`.
 *
 * Registers actionDeleteGDPRCustomer and actionExportGDPRData, so psgdpr
 * erases and exports the table's rows for a customer, and
 * actionObjectCartUpdateAfter, which claims a guest cart's rows once it gets a
 * customer.
 *
 * Idempotent: the table is created with IF NOT EXISTS, the column is added
 * only when missing, and the backfill only touches rows still at 0.
 *
 * Created: 2026-09-30
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_19($module)
{
    $db = Db::getInstance();
    $table = '`' . _DB_PREFIX_ . Twopayment::CART_RECORD_TABLE . '`';
    if (!$module->ensureTwoCartRecordTable()) {
        return false;
    }
    if (!$db->executeS('SHOW COLUMNS FROM ' . $table . ' LIKE "id_customer"')
        && !$db->execute(
            'ALTER TABLE ' . $table . ' ADD `id_customer` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `id_shop`,'
            . ' ADD KEY `idx_cart_record_customer` (`id_customer`)'
        )
    ) {
        return false;
    }
    if (!$db->execute(
        'UPDATE ' . $table . ' `cr` INNER JOIN `' . _DB_PREFIX_ . 'cart` `c` ON `c`.`id_cart` = `cr`.`id_cart`'
        . ' SET `cr`.`id_customer` = `c`.`id_customer` WHERE `cr`.`id_customer` = 0'
    )
        || !$module->registerHook('actionDeleteGDPRCustomer')
        || !$module->registerHook('actionExportGDPRData')
        || !$module->registerHook('actionObjectCartUpdateAfter')
    ) {
        return false;
    }

    PrestaShopLogger::addLog(
        'Two Payment: Successfully upgraded to version 2.7.19 - created the cart record table and registered its GDPR and cart hooks',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
