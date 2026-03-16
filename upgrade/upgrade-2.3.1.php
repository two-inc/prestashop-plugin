<?php
/**
 * UPGRADE SCRIPT: Version 2.3.1
 * 
 * Bug fixes and improvements:
 * - Fixed tax amount calculation to satisfy Two API formula (tax_amount = net_amount * tax_rate)
 * - Fixed shipping cost detection when free shipping cart rules are active
 * - Added Plugin Information tab in admin with capabilities and limitations
 * 
 * No database schema changes required.
 * 
 * Created: 2026-01-22
 * 
 * PHP COMPATIBILITY: PHP 7.1+ (PrestaShop 1.7.x compatible)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_1($module)
{
    PrestaShopLogger::addLog(
        'TwoPayment: Successfully upgraded to version 2.3.1 - Tax calculation fixes and Plugin Information tab',
        1,
        null,
        'Module',
        $module->id
    );
    
    return true;
}
