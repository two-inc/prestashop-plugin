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
    // Customer-facing product name (admin captions, checkout copy, order
    // notes). Mirrors the WooCommerce plugin's and Magento plugin's
    // product_name brand key (TWO-25386). An overlay replaces this with its
    // own brand name; getTwoBrandConfig() resolves it wherever the plugin
    // used to hardcode the literal word "Two".
    'product_name' => 'Two',
    // Increments the buyer surcharge line may be rounded to, offered in the
    // admin Rounding Step select. Mirrors the WooCommerce brand
    // available_rounding_steps and Magento's RoundingStep source model; an
    // overlay narrows the set.
    'rounding_steps' => array(0.10, 0.50, 1.00, 5.00, 10.00),
    // Buyer-facing label for the offset-pricing fee line; null falls back to
    // the translated default in Twopayment::getTwoSurchargeLineLabel().
    'fee_line_label' => null,
    // ON/OFF switch for the order-intent APPROVED notice shown inline in the
    // Two payment option at checkout (TWO-25218). Explicit boolean ONLY,
    // resolved by Twopayment::isIntentApprovedNoticeEnabled():
    //
    //   true          - notice ON. Declared explicitly here, not left implied.
    //   false         - notice suppressed entirely: no element is rendered,
    //                   not even an empty wrapper.
    //   key absent    - documented default TRUE (notice ON). This is what
    //                   keeps a third-party overlay that declares nothing on ON.
    //   anything else - a clear logged error, then the default TRUE. Never a
    //                   silent third behaviour. Deliberately not a throw: this
    //                   resolves on a buyer-facing checkout render, and a white
    //                   screen is a worse failure than a notice that stays on.
    //
    // What is switched: the buyer-facing reassurance messaging around the
    // order-intent pre-check - the APPROVED notice, and the loading overlay
    // shown while the check runs (TWO-25224; it carries our own "Checking Two
    // payment eligibility..." copy, so the two switch together). Declined and
    // error messages are functional and always render.
    'intent_approved_notice_enabled' => true,
    // COPY OVERRIDE ONLY for that notice (TWO-25218), resolved by
    // Twopayment::getIntentApprovedNotice():
    //
    //   null (or key absent) - platform default translated copy. The Two default.
    //   non-empty string     - used verbatim as the company-variant template,
    //                          where %s is the buyer's company name.
    //
    // NOTE: an empty or whitespace-only string is now INERT - it resolves to
    // the platform default copy. It is NOT an off switch. Under TWO-25213 it
    // was, so that is what a reader will remember; the switch is
    // 'intent_approved_notice_enabled' above and nothing else. An override
    // replaces the company variant only; the no-company copy stays default.
    'intent_approved_notice' => null,
);
