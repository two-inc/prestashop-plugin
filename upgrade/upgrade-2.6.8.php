<?php
/**
 * UPGRADE SCRIPT: Version 2.6.8
 *
 * 2.6.8 adds the optional "Default shipping tax code" setting
 * (PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP, TWO-25200): the tax rules group
 * assumed for shipping when, and only when, the carrier's declared group
 * cannot be resolved for the order.
 *
 * THERE IS DELIBERATELY NOTHING TO MIGRATE. The setting has NO default value:
 * an install that carries no row keeps exactly the pre-2.6.8 behaviour, which
 * is to refuse such an order loudly rather than assume a rate. Seeding any
 * value here - including "No tax" - would silently start relaying a rate the
 * merchant never declared, which is the one thing the shipping-VAT design
 * forbids. install() likewise does not seed the key.
 *
 * The admin field is additionally hidden unless the install opts in with
 * `define('_TWO_ENABLE_DEFAULT_SHIPPING_TAX_CODE_', true);` in
 * config/defines_custom.inc.php - see README.md. That constant is a
 * per-install file edit and is not something an upgrade script can or should
 * write.
 *
 * Created: 2026-07-27
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_6_8($module)
{
    return true;
}
