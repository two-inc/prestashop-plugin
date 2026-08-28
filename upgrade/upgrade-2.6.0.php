<?php
/**
 * UPGRADE SCRIPT: Version 2.6.0
 *
 * Retire the PS_TWO_USE_OWN_INVOICES admin toggle (TWO-25111 / TWO-25106
 * Option A): the plugin-side invoice upload is now gated solely on the
 * merchant's server-side `invoice_distributed_by_merchant` flag, read from
 * the cached GET /v1/merchant record. The configuration row is deleted so no
 * remnant can be mistaken for a live setting; the code never reads it again
 * either way.
 *
 * Migration safety: merchants who had the toggle enabled were whitelisted
 * server-side, and TWO-24761 (whitelist retirement) set
 * invoice_distributed_by_merchant=true for all previously-whitelisted
 * merchants - so deleting the toggle does not remove the feature from any
 * merchant that legitimately had it.
 *
 * Created: 2026-07-15
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_6_0($module)
{
    if (Configuration::hasKey('PS_TWO_USE_OWN_INVOICES')) {
        $was_enabled = (bool) Configuration::get('PS_TWO_USE_OWN_INVOICES');
        Configuration::deleteByName('PS_TWO_USE_OWN_INVOICES');
        PrestaShopLogger::addLog(
            'TwoPayment Upgrade 2.6.0: Removed retired PS_TWO_USE_OWN_INVOICES toggle (was '
            . ($was_enabled ? 'ENABLED' : 'disabled')
            . '). Invoice upload is now gated on the invoice_distributed_by_merchant flag from the Two merchant record (TWO-25111).',
            1,
            null,
            'Module',
            $module->id
        );
    }

    return true;
}
