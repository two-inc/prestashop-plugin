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
 * Since TWO-26082 the fallback is also off, and the admin field hidden, until
 * Two enables it for a merchant with
 * `php bin/console twopayment:shipping-tax-fallback enable [--shop=<n>]` (PS 1.7.6+). That
 * switch has no default either, so nothing here seeds it. See the
 * "Default shipping tax code" section of README.md.
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
