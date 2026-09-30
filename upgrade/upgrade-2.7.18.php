<?php
/**
 * UPGRADE SCRIPT: Version 2.7.18
 *
 * Creates the actionTwoOrderPostprocessing hook row (TWO-26092), so the hook
 * is listed under Design > Positions before any subscriber registers on it.
 * The module itself never registers on the hook.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_18($module)
{
    return $module->installTwoOrderPostprocessingHook();
}
