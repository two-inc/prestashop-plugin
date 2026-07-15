<?php
/**
 * UPGRADE SCRIPT: Version 2.5.0
 *
 * Surcharge tax migration visibility (TWO-25071):
 * The flat "Surcharge Tax Rate (%)" (PS_TWO_SURCHARGE_TAX_RATE, pre-release
 * builds only) is replaced by a merchant-selected TaxRulesGroup
 * (PS_TWO_SURCHARGE_TAX_RULES_GROUP). No automatic conversion is attempted:
 * inventing a TaxRulesGroup to match a bare percentage is destination-blind
 * and risks taxing orders wrongly. Instead, when a shop upgrades with the
 * flat rate configured and no group selected yet, this script:
 * - logs a warning via PrestaShopLogger, and
 * - sets a persistent flag (PS_TWO_SURCHARGE_TAX_MIGRATION_NOTICE) that the
 *   module config page renders as a visible "surcharge tax needs
 *   re-selection" warning until the merchant saves a selection.
 *
 * Created: 2026-07-11
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_5_0($module)
{
    $flatRate = Configuration::get('PS_TWO_SURCHARGE_TAX_RATE');
    $group = Configuration::get('PS_TWO_SURCHARGE_TAX_RULES_GROUP');

    $flatRateWasConfigured = ($flatRate !== false && $flatRate !== null && trim((string) $flatRate) !== '');
    $groupIsConfigured = ($group !== false && $group !== null && trim((string) $group) !== '');

    if ($flatRateWasConfigured && !$groupIsConfigured) {
        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.5.0: A flat surcharge tax rate (PS_TWO_SURCHARGE_TAX_RATE='
            . trim((string) $flatRate)
            . ') was configured but is no longer used. The surcharge is UNTAXED until a'
            . ' Surcharge Tax Rules Group is selected and saved in the module Payment settings.',
            2,
            null,
            'Module',
            $module->id
        );
        Configuration::updateValue('PS_TWO_SURCHARGE_TAX_MIGRATION_NOTICE', '1');
    }

    return true;
}
