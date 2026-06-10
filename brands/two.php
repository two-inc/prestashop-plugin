<?php
/**
 * Two brand configuration — the default brand of the base module.
 *
 * A partner edition supplies its own file with the same shape and the
 * module loads it instead (see Twopayment::getTwoBrand()). Every key
 * here has a runtime consumer — new keys land with the code that reads
 * them.
 *
 * `vendor_name` and `brand_tag` are sent in the create/intent/update
 * order payloads ONLY when non-empty, so the Two brand (empty values)
 * produces a byte-identical payload to the pre-brand-config module.
 */

return [
    'code' => 'two',
    'provider' => 'Two',
    'product_name' => 'Two',
    'display_name' => 'Two - BNPL for businesses',
    'description' => 'This module allows any merchant to accept payments with Two payment gateway.',
    'payment_title' => 'Pay with Two',
    'payment_subtitle' => 'Buy now, pay later - instant credit',
    'logo_path' => 'views/img/TwoLogo.svg',
    'support_email' => 'support@two.inc',
    'documentation_url' => 'https://docs.two.inc',
    'vendor_name' => '',
    'brand_tag' => '',
];
