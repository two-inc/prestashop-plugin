<?php
/**
 * UPGRADE SCRIPT: Version 2.2.0
 * 
 * Adds support for "Using Own Invoices" feature
 * - Adds two_invoice_id column (stores Two's invoice ID from order creation)
 * - Adds invoice upload tracking columns
 * 
 * Created: 2025-11-06
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_2_0($module)
{
    // Add new columns to twopayment table for invoice upload tracking
    $sql = array();
    
    // Check if columns already exist (in case upgrade is re-run)
    $columns_to_add = array(
        'two_invoice_id' => "ALTER TABLE `" . _DB_PREFIX_ . "twopayment` ADD `two_invoice_id` VARCHAR(255) NULL AFTER `two_invoice_url`",
        'two_invoice_upload_status' => "ALTER TABLE `" . _DB_PREFIX_ . "twopayment` ADD `two_invoice_upload_status` ENUM('PENDING', 'UPLOADING', 'UPLOADED', 'FAILED', 'NOT_APPLICABLE') DEFAULT 'NOT_APPLICABLE' AFTER `two_invoice_id`",
        'two_invoice_upload_reference' => "ALTER TABLE `" . _DB_PREFIX_ . "twopayment` ADD `two_invoice_upload_reference` VARCHAR(255) NULL AFTER `two_invoice_upload_status`",
        'two_invoice_upload_error' => "ALTER TABLE `" . _DB_PREFIX_ . "twopayment` ADD `two_invoice_upload_error` TEXT NULL AFTER `two_invoice_upload_reference`",
        'two_invoice_uploaded_at' => "ALTER TABLE `" . _DB_PREFIX_ . "twopayment` ADD `two_invoice_uploaded_at` DATETIME NULL AFTER `two_invoice_upload_error`"
    );
    
    foreach ($columns_to_add as $column_name => $query) {
        // Check if column exists
        $column_exists = Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = '" . _DB_NAME_ . "' 
            AND TABLE_NAME = '" . _DB_PREFIX_ . "twopayment' 
            AND COLUMN_NAME = '" . $column_name . "'"
        );
        
        if (!$column_exists) {
            if (!Db::getInstance()->execute($query)) {
                PrestaShopLogger::addLog(
                    'TwoPayment Upgrade 2.2.0: Failed to add column ' . $column_name,
                    3,
                    null,
                    'Module',
                    $module->id
                );
                return false;
            }
            PrestaShopLogger::addLog(
                'TwoPayment Upgrade 2.2.0: Successfully added column ' . $column_name,
                1,
                null,
                'Module',
                $module->id
            );
        } else {
            PrestaShopLogger::addLog(
                'TwoPayment Upgrade 2.2.0: Column ' . $column_name . ' already exists, skipping',
                1,
                null,
                'Module',
                $module->id
            );
        }
    }
    
    // Add default configuration for "Using Own Invoices" feature (disabled by default)
    if (!Configuration::hasKey('PS_TWO_USE_OWN_INVOICES')) {
        Configuration::updateValue('PS_TWO_USE_OWN_INVOICES', 0);
        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.2.0: Added PS_TWO_USE_OWN_INVOICES configuration',
            1,
            null,
            'Module',
            $module->id
        );
    }
    
    // Migrate API key configuration from typo "MERACHANT" to correct "MERCHANT"
    $old_key = 'PS_TWO_MERACHANT_API_KEY';
    $new_key = 'PS_TWO_MERCHANT_API_KEY';
    
    if (Configuration::hasKey($old_key)) {
        $api_key_value = Configuration::get($old_key);
        if (!empty($api_key_value)) {
            // Migrate value to new key
            Configuration::updateValue($new_key, $api_key_value);
            PrestaShopLogger::addLog(
                'TwoPayment Upgrade 2.2.0: Migrated API key from PS_TWO_MERACHANT_API_KEY to PS_TWO_MERCHANT_API_KEY',
                1,
                null,
                'Module',
                $module->id
            );
        }
        // Delete old key (after migration)
        Configuration::deleteByName($old_key);
        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.2.0: Removed old API key configuration PS_TWO_MERACHANT_API_KEY',
            1,
            null,
            'Module',
            $module->id
        );
    }
    
    PrestaShopLogger::addLog(
        'TwoPayment: Successfully upgraded to version 2.2.0',
        1,
        null,
        'Module',
        $module->id
    );
    
    return true;
}

