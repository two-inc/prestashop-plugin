<?php
/**
 * UPGRADE SCRIPT: Version 2.3.2
 * 
 * Invoice Upload Feature:
 * - Re-enables invoice upload functionality (disabled by default)
 * - Uploads PrestaShop-generated PDF invoices to Two when orders are fulfilled
 * - Merchants are responsible for customizing their invoice templates to include Two's payment details
 * 
 * No database changes required.
 * No new hooks required.
 * 
 * Created: 2026-01-22
 * 
 * PHP COMPATIBILITY: PHP 7.1+ (PrestaShop 1.7.x compatible)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_2($module)
{
    PrestaShopLogger::addLog(
        'TwoPayment: Successfully upgraded to version 2.3.2 - Invoice upload feature re-enabled (disabled by default)',
        1,
        null,
        'Module',
        $module->id
    );
    
    return true;
}
