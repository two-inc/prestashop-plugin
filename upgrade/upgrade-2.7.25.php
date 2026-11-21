<?php
/**
 * UPGRADE SCRIPT: Version 2.7.25
 *
 * New orders now take the Awaiting and Verified statuses mapped in the
 * settings (TWO-27329). Earlier versions stored core's Processing in progress
 * as both mappings, yet created and verified orders in the module's own
 * statuses. A shop still on that old default for both would otherwise start
 * creating orders in Processing in progress before Two verifies the buyer, so
 * each of the two mappings is moved to the module's own status, and the shop
 * sees the statuses it saw before.
 *
 * Only a tier whose Awaiting and Verified mappings both name Processing in
 * progress, and whose own statuses exist, is moved; every other mapping stays.
 * The global tier and, on a multistore shop, every shop group and shop tier
 * holding a mapping of its own are covered. It returns true on every path: a
 * tier it could not write is logged and keeps its mapping, which the merchant
 * can change in the order status settings.
 *
 * Created: 2026-11-21
 *
 * @param Twopayment $module
 * @return bool
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_25($module)
{
    try {
        $moved = $module->migrateTwoPreparationStatusMaps();
        PrestaShopLogger::addLog(
            'Two Payment: Upgraded to version 2.7.25 - moved the Awaiting and Verified status mapping of ' . (int) $moved . ' tier(s) from Processing in progress to the module statuses',
            1,
            null,
            'Module',
            (int) $module->id
        );
    } catch (Throwable $e) {
        PrestaShopLogger::addLog(
            'Two Payment: Upgrade to version 2.7.25 could not move every Awaiting and Verified status mapping - ' . $e->getMessage() . '; check the order status settings',
            3,
            null,
            'Module',
            (int) $module->id
        );
    }

    return true;
}
