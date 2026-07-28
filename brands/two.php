<?php
/**
 * Two brand defaults (PrestaShop).
 *
 * A minimal brand-config seam mirroring the WooCommerce plugin's
 * brands/two.php and the Magento plugin's brand descriptors. Values here are
 * the Two defaults; a white-label overlay can replace this file to narrow or
 * relabel them. Only the surcharge-relevant keys are defined for now
 * (TWO-24893); the full brand-config foundation is TWO-24746.
 *
 * @return array<string, mixed>
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

return array(
    // Increments the buyer surcharge line may be rounded to, offered in the
    // admin Rounding Step select. Mirrors the WooCommerce brand
    // available_rounding_steps and Magento's RoundingStep source model; an
    // overlay narrows the set.
    'rounding_steps' => array(0.10, 0.50, 1.00, 5.00, 10.00),
    // Buyer-facing label for the offset-pricing fee line; null falls back to
    // the translated default in Twopayment::getTwoSurchargeLineLabel().
    'fee_line_label' => null,
    // Per-brand switch for the order-intent APPROVED notice shown inline in
    // the Two payment option at checkout (TWO-25213). Three states, resolved
    // by Twopayment::getIntentApprovedNotice():
    //
    //   null (or key absent) - platform default translated copy, notice ON.
    //                          This is the Two default.
    //   ''                   - notice suppressed entirely: no element is
    //                          rendered, not even an empty wrapper.
    //   non-empty string     - used verbatim as the company-variant template,
    //                          where %s is the buyer's company name.
    //
    // Only the APPROVED notice is switched; declined and error messages are
    // functional and always render.
    'intent_approved_notice' => null,
);
