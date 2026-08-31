<?php
/**
 * UPGRADE SCRIPT: Version 2.7.14
 *
 * Removes PS_TWO_FINALIZE_PURCHASE ("Automatically fulfil orders with
 * {brand}"), the master on/off switch, aligning PrestaShop with
 * woocommerce-plugin/magento-plugin's single-selection fulfilment-trigger
 * model: PS_TWO_OS_FULFILLED_MAP alone decides, and an empty selection means
 * fulfilment never fires.
 *
 * A shop that had the switch OFF today relies on it, not its Fulfilled-status
 * mapping, to keep fulfilment manual-only - that mapping still holds
 * whatever status was configured before the switch was ever touched. Losing
 * the switch without clearing that mapping would silently turn auto-fulfil
 * back on. So: switch OFF -> clear PS_TWO_OS_FULFILLED_MAP to an empty JSON
 * array, reproducing "never fulfils" as an empty selection. Switch ON needs
 * no change - its Fulfilled-status mapping already reflects intended
 * behaviour.
 *
 * Created: 2026-09-01
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_14($module)
{
    // hasKey() first: the switch's own row is deleted at the end of this
    // function, so a second run (reinstall, rollback+re-bump) must not read
    // the now-missing key as "was off" and re-clear a mapping the merchant
    // has since configured.
    $switch_was_off = Configuration::hasKey('PS_TWO_FINALIZE_PURCHASE')
        && ((int) Configuration::get('PS_TWO_FINALIZE_PURCHASE')) === 0;

    if ($switch_was_off) {
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode(array()));

        PrestaShopLogger::addLog(
            'Two Payment v2.7.14 upgrade: PS_TWO_FINALIZE_PURCHASE was off - cleared '
            . 'PS_TWO_OS_FULFILLED_MAP to an empty selection so fulfilment stays manual-only',
            1,
            null,
            'Module',
            $module->id
        );
    }

    Configuration::deleteByName('PS_TWO_FINALIZE_PURCHASE');

    PrestaShopLogger::addLog(
        'Two Payment: Successfully upgraded to version 2.7.14 - removed the '
        . 'PS_TWO_FINALIZE_PURCHASE switch, PS_TWO_OS_FULFILLED_MAP is now the sole fulfilment trigger',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
