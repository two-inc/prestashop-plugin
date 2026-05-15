<?php
/**
 * UPGRADE SCRIPT: Version 2.4.0
 *
 * Provider-first checkout refactor + hardening:
 * - Adds checkout attempt persistence table `twopayment_attempt`
 * - Enables creating PrestaShop orders only after Two verification callback
 * - Adds cart snapshot and idempotency metadata columns
 *
 * Created: 2026-02-25
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_4_0($module)
{
    $table_name = _DB_PREFIX_ . 'twopayment_attempt';

    $exists = (int)Db::getInstance()->getValue(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '" . _DB_NAME_ . "' AND TABLE_NAME = '" . pSQL($table_name) . "'"
    );

    if (!$exists) {
        $query = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'twopayment_attempt` (
            `id_attempt` int(11) NOT NULL AUTO_INCREMENT,
            `attempt_token` VARCHAR(80) NOT NULL,
            `id_cart` INT(11) UNSIGNED NOT NULL,
            `id_customer` INT(11) UNSIGNED NOT NULL,
            `id_order` INT(11) UNSIGNED NULL,
            `customer_secure_key` VARCHAR(64) NOT NULL,
            `merchant_order_id` VARCHAR(80) NOT NULL,
            `two_order_id` VARCHAR(255) NULL,
            `two_order_reference` VARCHAR(255) NULL,
            `two_order_state` VARCHAR(64) NULL,
            `two_order_status` VARCHAR(64) NULL,
            `two_day_on_invoice` VARCHAR(32) NULL,
            `two_payment_term_type` VARCHAR(20) DEFAULT "STANDARD",
            `two_invoice_url` TEXT NULL,
            `two_invoice_id` VARCHAR(255) NULL,
            `cart_snapshot_hash` VARCHAR(64) NULL,
            `order_create_idempotency_key` VARCHAR(128) NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT "CREATED",
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_attempt`),
            UNIQUE KEY `uniq_attempt_token` (`attempt_token`),
            KEY `idx_attempt_cart` (`id_cart`),
            KEY `idx_attempt_order` (`id_order`),
            KEY `idx_attempt_two_order_id` (`two_order_id`),
            KEY `idx_attempt_updated_at` (`updated_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        if (!Db::getInstance()->execute($query)) {
            PrestaShopLogger::addLog(
                'TwoPayment Upgrade 2.4.0: Failed to create table ' . $table_name,
                3,
                null,
                'Module',
                $module->id
            );
            return false;
        }

        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.4.0: Created table ' . $table_name,
            1,
            null,
            'Module',
            $module->id
        );
    }

    $columns_to_add = array(
        'cart_snapshot_hash' => "ALTER TABLE `" . _DB_PREFIX_ . "twopayment_attempt` ADD `cart_snapshot_hash` VARCHAR(64) NULL AFTER `two_invoice_id`",
        'order_create_idempotency_key' => "ALTER TABLE `" . _DB_PREFIX_ . "twopayment_attempt` ADD `order_create_idempotency_key` VARCHAR(128) NULL AFTER `cart_snapshot_hash`",
    );

    foreach ($columns_to_add as $column_name => $query) {
        $column_exists = (int)Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = '" . _DB_NAME_ . "'
             AND TABLE_NAME = '" . pSQL($table_name) . "'
             AND COLUMN_NAME = '" . pSQL($column_name) . "'"
        );

        if (!$column_exists) {
            if (!Db::getInstance()->execute($query)) {
                PrestaShopLogger::addLog(
                    'TwoPayment Upgrade 2.4.0: Failed to add column ' . $column_name,
                    3,
                    null,
                    'Module',
                    $module->id
                );
                return false;
            }
        }
    }

    $indexes_to_add = array(
        'idx_attempt_updated_at' => "ALTER TABLE `" . _DB_PREFIX_ . "twopayment_attempt` ADD INDEX `idx_attempt_updated_at` (`updated_at`)",
    );

    foreach ($indexes_to_add as $index_name => $query) {
        $index_exists = (int)Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = '" . _DB_NAME_ . "'
             AND TABLE_NAME = '" . pSQL($table_name) . "'
             AND INDEX_NAME = '" . pSQL($index_name) . "'"
        );

        if (!$index_exists) {
            if (!Db::getInstance()->execute($query)) {
                PrestaShopLogger::addLog(
                    'TwoPayment Upgrade 2.4.0: Failed to add index ' . $index_name,
                    3,
                    null,
                    'Module',
                    $module->id
                );
                return false;
            }
        }
    }

    PrestaShopLogger::addLog(
        'TwoPayment: Successfully upgraded to version 2.4.0 - Provider-first checkout flow with snapshot/idempotency hardening enabled',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
