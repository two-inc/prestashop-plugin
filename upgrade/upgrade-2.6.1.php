<?php
/**
 * UPGRADE SCRIPT: Version 2.5.1
 *
 * Sole trader checkout rework (TWO-24755): the Personal/Business/Sole-
 * trader account-type selector on the address form is removed entirely
 * in favour of a payment-step Business / Sole trader toggle (matches the
 * Magento/WooCommerce plugins). This deletes the now-unused
 * PS_TWO_USE_ACCOUNT_TYPE configuration and sets an explicit default (0)
 * for the new PS_TWO_ENABLE_SOLE_TRADER toggle, so existing installs
 * read an explicit stored value rather than an unset/false read -
 * matching this module's convention for new config keys (see
 * upgrade-2.3.0.php, upgrade-2.5.0.php). No live merchants are on this
 * plugin yet (staging only), so there is no merchant-facing migration
 * risk either way.
 *
 * Created: 2026-07-15
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_5_1($module)
{
    Configuration::deleteByName('PS_TWO_USE_ACCOUNT_TYPE');

    if (!Configuration::hasKey('PS_TWO_ENABLE_SOLE_TRADER')) {
        Configuration::updateValue('PS_TWO_ENABLE_SOLE_TRADER', 0);
    }

    return true;
}
