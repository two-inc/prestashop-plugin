<?php
/**
 * Two brand configuration — the default brand of the base module.
 *
 * A partner edition sets the PS_TWO_BRAND_CODE configuration value;
 * Twopayment::getTwoBrand() resolves it to brands/{code}.php and merges
 * that file over these defaults, so a partner file declares only what
 * differs. PS_TWO_BRAND_CODE is the PRODUCTION selection mechanism —
 * unlike the WooCommerce plugin's TWO_BRAND_CODE, which is a dev-only
 * env override (its production selection is the overlay plugin's
 * twoinc_brand_file filter).
 *
 * Consumer map (every key has a runtime reader — new keys land with the
 * code that reads them):
 * - provider, display_name, description → module constructor identity
 * - payment_title, payment_subtitle → payment-option defaults when the
 *   merchant has not configured PS_TWO_TITLE / PS_TWO_SUB_TITLE
 * - product_name, code → Smarty var {$two_brand.*} (assigned at
 *   setMedia + payment-option render) and tests
 * - support_email, documentation_url → admin help panel
 */

return [
    'code' => 'two',
    'provider' => 'Two',
    'product_name' => 'Two',
    'display_name' => 'Two - BNPL for businesses',
    'description' => 'This module allows any merchant to accept payments with Two payment gateway.',
    'payment_title' => 'Pay with Two',
    'payment_subtitle' => 'Buy now, pay later - instant credit',
    'support_email' => 'support@two.inc',
    'documentation_url' => 'https://docs.two.inc',
    // Payment terms the brand allows in the checkout selector; the
    // merchant's admin checkboxes narrow within this set. null = no
    // brand constraint (the Two default - PS merchants already hold
    // term selections in PS_TWO_PAYMENT_TERMS_* that must keep
    // working; this differs from WooCommerce, where the brand list IS
    // the offerable set because WC had no terms before). A partner
    // edition sets e.g. [30, 60, 90]. Read only by
    // getAvailablePaymentTerms() - the term-availability seam.
    'available_terms' => null,
];
