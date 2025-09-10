<?php
/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_0($module)
{
    // Ensure new minimum compatibility is respected and any new defaults exist
    PrestaShopLogger::addLog('TwoPayment: Running upgrade 2.0.0', 1);

    // Default new toggle to OFF for upgrades if not set yet
    if (Configuration::get('PS_TWO_USE_ACCOUNT_TYPE') === false) {
        Configuration::updateValue('PS_TWO_USE_ACCOUNT_TYPE', 0);
    }

    // No schema changes for 2.0.0; keep as a safe no-op
    return true;
}


