<?php
/**
 * UPGRADE SCRIPT: Version 2.7.19
 *
 * Creates `twopayment_cart_record` (TWO-26094), which holds the cart-scoped
 * company selection and mirror-write record server-side, keyed by cart and
 * shop. They used to live in the PrestaShop cookie, where a `|` or `¤` in a
 * company name, or a long address, made core's cookie write throw.
 *
 * Idempotent: the table is created with IF NOT EXISTS, and the module also
 * creates it lazily for a shop whose files were swapped without an upgrade.
 *
 * Created: 2026-09-30
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_19($module)
{
    if (!$module->ensureTwoCartRecordTable()) {
        return false;
    }

    PrestaShopLogger::addLog(
        'Two Payment: Successfully upgraded to version 2.7.19 - created the cart record table',
        1,
        null,
        'Module',
        $module->id
    );

    return true;
}
