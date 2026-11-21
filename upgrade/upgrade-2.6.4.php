<?php
/**
 * UPGRADE SCRIPT: Version 2.6.4
 *
 * PS_TWO_ENABLE_B2B_B2C is removed from the module entirely (TWO-24739
 * plugin-parity cleanup). It was never a rendered admin field and was
 * never read for a behavioural decision - the only two references were
 * the advanced-settings form's value hydration and a matching
 * updateValue() in the save handler, so saving advanced settings wrote a
 * blank value into the row on every submit and nothing ever consulted it.
 * Two is B2B-only; there is no B2B/B2C mode to switch.
 *
 * Existing installs therefore carry a stored row for the key (any shop
 * whose merchant has ever saved advanced settings). Nothing reads it, so
 * this deletes it rather than leaving a dead row that a future reader
 * could mistake for live configuration.
 *
 * Created: 2026-07-27
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_6_4($module)
{
    if (Configuration::hasKey('PS_TWO_ENABLE_B2B_B2C')) {
        Configuration::deleteByName('PS_TWO_ENABLE_B2B_B2C');

        PrestaShopLogger::addLog(
            'Two Payment v2.6.4 upgrade: removed dead PS_TWO_ENABLE_B2B_B2C configuration row - never rendered, never read (TWO-24739)',
            1,
            null,
            'Module',
            $module->id
        );
    }

    return true;
}
