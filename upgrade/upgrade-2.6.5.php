<?php
/**
 * UPGRADE SCRIPT: Version 2.6.5
 *
 * PS_TWO_ENABLE_COMPANY_ID is removed from the module entirely (TWO-25190
 * plugin-parity cleanup). It was a rendered admin switch ("Activate company
 * org.id auto-complete") whose only consumer was the `company_id_search`
 * entry in the Media::addJsDef() `twopayment` payload - and no JavaScript
 * ever read that entry. Company lookup on the checkout is driven solely by
 * `company_name_search` (views/js/twopayment.js), which is unchanged. The
 * switch therefore advertised a setting that did nothing.
 *
 * Existing installs carry a stored row for the key (install() seeded it to 1
 * and every advanced-settings save rewrote it). Nothing reads it, so this
 * deletes it rather than leaving a dead row that a future reader could
 * mistake for live configuration.
 *
 * Created: 2026-07-27
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_6_5($module)
{
    if (Configuration::hasKey('PS_TWO_ENABLE_COMPANY_ID')) {
        Configuration::deleteByName('PS_TWO_ENABLE_COMPANY_ID');

        PrestaShopLogger::addLog(
            'Two Payment v2.6.5 upgrade: removed dead PS_TWO_ENABLE_COMPANY_ID configuration row - the org.id auto-complete switch was never read by any consumer (TWO-25190)',
            1,
            null,
            'Module',
            $module->id
        );
    }

    return true;
}
