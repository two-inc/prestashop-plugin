<?php
/**
 * UPGRADE SCRIPT: Version 2.3.0
 * 
 * Adds support for End-of-Month (EOM) payment terms
 * - Adds PS_TWO_PAYMENT_TERM_TYPE configuration ('STANDARD' or 'EOM')
 * - Existing merchants default to 'STANDARD' for backward compatibility
 * - Adds PS_TWO_PAYMENT_TERM_TYPE_ORDER to store term type per order
 * 
 * Created: 2025-11-21
 * 
 * PHP COMPATIBILITY: PHP 7.1+ (PrestaShop 1.7.x compatible)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_0($module)
{
    // Add default configuration for payment term type (STANDARD by default for backward compatibility)
    if (!Configuration::hasKey('PS_TWO_PAYMENT_TERM_TYPE')) {
        Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', 'STANDARD');
        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.3.0: Added PS_TWO_PAYMENT_TERM_TYPE configuration (default: STANDARD)',
            1,
            null,
            'Module',
            $module->id
        );
    }
    
    // Add column to twopayment table to store term type per order
    $column_name = 'two_payment_term_type';
    $column_exists = Db::getInstance()->getValue(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = '" . _DB_NAME_ . "' 
        AND TABLE_NAME = '" . _DB_PREFIX_ . "twopayment' 
        AND COLUMN_NAME = '" . $column_name . "'"
    );
    
    if (!$column_exists) {
        $query = "ALTER TABLE `" . _DB_PREFIX_ . "twopayment` 
                  ADD `two_payment_term_type` VARCHAR(20) DEFAULT 'STANDARD' 
                  AFTER `two_day_on_invoice`";
        
        if (!Db::getInstance()->execute($query)) {
            PrestaShopLogger::addLog(
                'TwoPayment Upgrade 2.3.0: Failed to add column ' . $column_name,
                3,
                null,
                'Module',
                $module->id
            );
            return false;
        }
        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.3.0: Successfully added column ' . $column_name,
            1,
            null,
            'Module',
            $module->id
        );
    } else {
        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.3.0: Column ' . $column_name . ' already exists, skipping',
            1,
            null,
            'Module',
            $module->id
        );
    }
    
    // Update existing orders to have STANDARD type (for historical data consistency)
    $update_query = "UPDATE `" . _DB_PREFIX_ . "twopayment` 
                     SET `two_payment_term_type` = 'STANDARD' 
                     WHERE `two_payment_term_type` IS NULL OR `two_payment_term_type` = ''";
    
    if (Db::getInstance()->execute($update_query)) {
        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.3.0: Updated existing orders to STANDARD term type',
            1,
            null,
            'Module',
            $module->id
        );
    }
    
    PrestaShopLogger::addLog(
        'TwoPayment: Successfully upgraded to version 2.3.0 - EOM payment terms feature added',
        1,
        null,
        'Module',
        $module->id
    );
    
    return true;
}

