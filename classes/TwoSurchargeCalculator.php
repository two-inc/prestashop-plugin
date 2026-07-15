<?php
/**
 * Buyer surcharge (offset pricing fee) payload builder — pure logic.
 *
 * PrestaShop equivalent of Magento's Service/Order/SurchargeCalculator and the
 * WooCommerce plugin's WC_Twoinc_Payment_Terms::build_buyer_fee_share. All fee
 * arithmetic is done server-side by POST /v1/pricing/order/fee; this class only
 * maps merchant config onto the request's `buyer_fee_share` block. Keeping it
 * dependency-free (no PrestaShop classes) makes it directly unit-testable and
 * satisfies TWO-24752's requirement that surcharge business logic live in PHP,
 * callable independently of the checkout rendering path.
 *
 * TWO-24752 (offset pricing fee) + TWO-24893 (brand-driven rounding relay).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoSurchargeCalculator
{
    /**
     * Stored surcharge-rounding basis -> pricing-API basis. "none" (and any
     * unmapped value) omits the rounding block. Mirrors Magento's
     * SurchargeCalculator::ROUNDING_BASIS_TO_API and the WooCommerce plugin's
     * ROUNDING_BASIS_TO_API.
     */
    const ROUNDING_BASIS_TO_API = array(
        'up' => 'UP',
        'down' => 'DOWN',
        'standard' => 'STANDARD',
    );

    /** Surcharge methods that carry a fee (anything else is treated as "none"). */
    const VALID_TYPES = array('percentage', 'fixed', 'fixed_and_percentage');

    /**
     * Coerce a stored surcharge type to a known value, defaulting to "none".
     *
     * @param mixed $type
     * @return string
     */
    public static function normalizeType($type)
    {
        $type = (string) $type;

        return in_array($type, self::VALID_TYPES, true) ? $type : 'none';
    }

    /**
     * Build the buyer_fee_share block for one term, or null when no surcharge
     * is configured. Mirrors Magento/WooCommerce:
     *  - percentage types supply `percentage` (0.0 for fixed-only so the API
     *    default of 100% is never silently applied)
     *  - `surcharge_basis` is sent explicitly
     *  - fixed types supply `surcharge`
     *  - a positive limit on a percentage type supplies `cap`
     *  - rounding (percentage modes only) supplies `rounding`
     *  - differential mode supplies `reference_terms` (default term) so the
     *    API computes the delta itself — no delta math in the plugin
     *
     * @param array    $settings   {type, differential(bool), grid, rounding_basis, rounding_step}
     * @param int      $days       selected term in days
     * @param int|null $defaultTerm default term for differential reference_terms
     * @param bool     $isEndOfMonth whether the merchant uses end-of-month terms
     * @return array|null
     */
    public static function buildBuyerFeeShare(array $settings, $days, $defaultTerm, $isEndOfMonth)
    {
        $type = self::normalizeType(isset($settings['type']) ? $settings['type'] : 'none');
        if ($type === 'none') {
            return null;
        }

        $hasPercentage = in_array($type, array('percentage', 'fixed_and_percentage'), true);
        $hasFixed = in_array($type, array('fixed', 'fixed_and_percentage'), true);

        $grid = isset($settings['grid']) && is_array($settings['grid']) ? $settings['grid'] : array();
        $days = (int) $days;
        $row = isset($grid[$days]) && is_array($grid[$days]) ? $grid[$days] : array();

        $buyer_fee_share = array(
            'percentage' => $hasPercentage && isset($row['percentage']) ? (float) $row['percentage'] : 0.0,
            'surcharge_basis' => 'buyer_pays',
        );

        // Fixed amounts and caps are emitted here in the store currency they
        // are configured in; the module re-denominates them into the quote
        // currency via Two's FX rates before the wire call
        // (Twopayment::convertTwoBuyerFeeShareCurrency, TWO-25105).
        // Percentage surcharge is currency-agnostic.
        if ($hasFixed && isset($row['fixed']) && (float) $row['fixed'] > 0) {
            $buyer_fee_share['surcharge'] = (float) $row['fixed'];
        }

        // `cap` only applies where the fee has a percentage component — a
        // fixed-only fee is constant, nothing to clamp — so a stored limit left
        // over from a previous surcharge type must not leak into a fixed-only
        // request.
        if ($hasPercentage && isset($row['limit']) && (float) $row['limit'] > 0) {
            $buyer_fee_share['cap'] = (float) $row['limit'];
        }

        // `rounding` snaps the final buyer line to a clean increment,
        // server-side. Percentage modes only, for the same reason as `cap`.
        if ($hasPercentage) {
            $rounding = self::buildRounding(
                isset($settings['rounding_basis']) ? $settings['rounding_basis'] : 'none',
                isset($settings['rounding_step']) ? $settings['rounding_step'] : null
            );
            if ($rounding !== null) {
                $buyer_fee_share['rounding'] = $rounding;
            }
        }

        if (!empty($settings['differential']) && $defaultTerm !== null) {
            $buyer_fee_share['reference_terms'] = self::buildTermsBlock((int) $defaultTerm, (bool) $isEndOfMonth);
        }

        return $buyer_fee_share;
    }

    /**
     * The rounding block for buyer_fee_share, or null when rounding is off.
     * The backend does the arithmetic; the plugin only relays {step, basis}.
     * A None/unmapped basis or a non-positive step omits the block (the API
     * requires both keys and rejects step <= 0). Mirrors Magento's
     * SurchargeCalculator::buildRounding and WooCommerce's build_rounding.
     *
     * @param mixed      $basis
     * @param float|null $step
     * @return array|null
     */
    public static function buildRounding($basis, $step)
    {
        $basis = (string) $basis;
        if (!isset(self::ROUNDING_BASIS_TO_API[$basis])) {
            return null;
        }
        if ($step === null) {
            return null;
        }
        $step = (float) $step;
        if ($step <= 0) {
            return null;
        }

        return array(
            'step' => $step,
            'basis' => self::ROUNDING_BASIS_TO_API[$basis],
        );
    }

    /**
     * A NET_TERMS block for a duration, adding
     * duration_days_calculated_from = END_OF_MONTH when the merchant uses
     * end-of-month terms (Magento/WooCommerce parity).
     *
     * @param int  $days
     * @param bool $isEndOfMonth
     * @return array
     */
    public static function buildTermsBlock($days, $isEndOfMonth)
    {
        $block = array(
            'type' => 'NET_TERMS',
            'duration_days' => (int) $days,
        );
        if ($isEndOfMonth) {
            $block['duration_days_calculated_from'] = 'END_OF_MONTH';
        }

        return $block;
    }
}
