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
 * Upgrade to version 2.1.2
 * 
 * This upgrade includes three major improvements:
 * 
 * 1. CRITICAL FIX: jQuery dependency loading for PrestaShop 1.7.6.5 compatibility
 *    - Implemented triple-layer jQuery loading strategy
 *    - Added CDN fallback for guaranteed jQuery availability
 *    - Enhanced compatibility with PrestaShop 1.7.6.5 and custom themes
 *    - Fixed "jQuery is not defined" error on merchant environments
 *    - Fixed jQuery Deferred API compatibility (.done/.fail vs .then/.catch)
 * 
 * 2. FEATURE: Multi-status fulfillment triggers
 *    - Migrates PS_TWO_OS_FULFILLED_MAP from single status ID to JSON array format
 *    - Supports multiple order statuses triggering fulfillment
 *    - Merchants can now configure multiple shipping statuses (e.g., Manual, Auto, FedEx)
 *    - Backward compatible with existing single-status configuration
 * 
 * 3. FEATURE: Fraud verification skip
 *    - Allows merchants with custom verification logic to skip Two's fraud redirect
 *    - Double validation (merchant flag + Two API state=VERIFIED)
 *    - Fail-safe mechanism prevents bypassing Two's security
 *    - No database or configuration changes required
 * 
 * @param Twopayment $module
 * @return bool
 */
function upgrade_module_2_1_2($module)
{
    // Log upgrade start
    PrestaShopLogger::addLog(
        'Two Payment: Starting upgrade to version 2.1.2 (jQuery compatibility + Multi-status fulfillment + Fraud verification skip)',
        1,
        null,
        'Module',
        $module->id
    );
    
    // ================================================================
    // PART 1: Clear cache for jQuery compatibility fixes
    // ================================================================
    try {
        if (method_exists('Tools', 'clearAllCache')) {
            Tools::clearAllCache();
        }
        if (method_exists('Tools', 'clearCache')) {
            Tools::clearCache();
        }
        
        PrestaShopLogger::addLog(
            'Two Payment v2.1.2 upgrade: Cache cleared successfully',
            1,
            null,
            'Module',
            $module->id
        );
    } catch (Exception $e) {
        PrestaShopLogger::addLog(
            'Two Payment v2.1.2 upgrade: Cache clear failed - ' . $e->getMessage(),
            2,
            null,
            'Module',
            $module->id
        );
    }
    
    // ================================================================
    // PART 2: Migrate fulfillment status to multi-status format
    // ================================================================
    $current_fulfilled_map = Configuration::get('PS_TWO_OS_FULFILLED_MAP');
    
    if ($current_fulfilled_map) {
        // Check if it's already in JSON array format
        $decoded = json_decode($current_fulfilled_map, true);
        
        if (!is_array($decoded)) {
            // It's a single ID - convert to JSON array
            $status_id = (int)$current_fulfilled_map;
            Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode(array($status_id)));
            
            PrestaShopLogger::addLog(
                'Two Payment v2.1.2 upgrade: Migrated fulfillment status from single ID (' . $status_id . ') to multi-status array format',
                1,
                null,
                'Module',
                $module->id
            );
        } else {
            // Already in array format (shouldn't happen on fresh upgrade from 2.1.1)
            PrestaShopLogger::addLog(
                'Two Payment v2.1.2 upgrade: Fulfillment status already in multi-status format',
                1,
                null,
                'Module',
                $module->id
            );
        }
    } else {
        // No existing config - set default to Shipped
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode(array((int)Configuration::get('PS_OS_SHIPPING'))));
        
        PrestaShopLogger::addLog(
            'Two Payment v2.1.2 upgrade: Initialized fulfillment status with default Shipped status',
            1,
            null,
            'Module',
            $module->id
        );
    }
    
    // ================================================================
    // PART 3: Fraud verification skip feature (no migration needed)
    // ================================================================
    // This feature requires no database or configuration changes
    // It's implemented purely in the payment controller logic
    PrestaShopLogger::addLog(
        'Two Payment v2.1.2 upgrade: Fraud verification skip feature enabled (no configuration required)',
        1,
        null,
        'Module',
        $module->id
    );
    
    // Log successful upgrade
    PrestaShopLogger::addLog(
        'Two Payment: Successfully upgraded to version 2.1.2 - jQuery compatibility + Multi-status fulfillment + Fraud verification skip',
        1,
        null,
        'Module',
        $module->id
    );
    
    return true;
}

