<?php
/**
 * UPGRADE SCRIPT: Version 2.6.3
 *
 * Sole trader is gated on country only (TWO-25166): the
 * PS_TWO_ENABLE_SOLE_TRADER merchant toggle is removed from the module
 * entirely. Whether a buyer in a country can check out as a sole trader
 * is Two's registry answer (GET /registry/v1/supported-company-types,
 * TWO-24753 - GB/US currently), not a merchant preference, and Magento's
 * toggle-less behaviour is the cross-plugin target state (TWO-25163).
 *
 * Existing installs carry a stored row for the key - upgrade-2.6.1
 * explicitly wrote a 0 default, and install() did too, which is why the
 * feature was invisible on both PrestaShop staging shops. Nothing reads
 * the key any more, so this deletes it rather than leaving a dead row
 * that a future reader could mistake for live configuration.
 *
 * Created: 2026-07-27
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_6_3($module)
{
    if (Configuration::hasKey('PS_TWO_ENABLE_SOLE_TRADER')) {
        Configuration::deleteByName('PS_TWO_ENABLE_SOLE_TRADER');

        PrestaShopLogger::addLog(
            'Two Payment v2.6.3 upgrade: removed retired PS_TWO_ENABLE_SOLE_TRADER toggle - sole trader is gated on the registry country answer only (TWO-25166)',
            1,
            null,
            'Module',
            $module->id
        );
    }

    return true;
}
