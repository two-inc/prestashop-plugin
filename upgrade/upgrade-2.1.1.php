<?php
/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade script for Two Payment Module version 2.1.1
 * Fixes critical issues with asset loading and initialization
 */
function upgrade_module_2_1_1($module)
{
    // Clear any existing cache to ensure new JavaScript files are loaded
    if (method_exists('Tools', 'clearCache')) {
        Tools::clearCache();
    }
    
    // Log successful upgrade
    PrestaShopLogger::addLog('Two Payment Module upgraded to 2.1.1: Fixed asset loading and initialization issues', 1);
    
    return true;
}
