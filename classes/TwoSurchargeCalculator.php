<?php
/**
 * Buyer surcharge (offset pricing fee) payload builder — pure logic.
 *
 * All fee arithmetic is done server-side by POST /v1/pricing/order/fee; this
 * class only maps merchant config onto the request's `buyer_fee_share` block.
 * Kept dependency-free (no PrestaShop classes) so it stays unit-testable
 * independently of the checkout rendering path.
 *
 * TWO-24752 (offset pricing fee) + TWO-24893 (brand-driven rounding relay).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoSurchargeCalculator
{
    /** "none" (and any unmapped value) omits the rounding block. */
    const ROUNDING_BASIS_TO_API = array(
        'up' => 'UP',
        'down' => 'DOWN',
        'standard' => 'STANDARD',
    );

    /** Anything else is treated as "none". */
    const VALID_TYPES = array('percentage', 'fixed', 'fixed_and_percentage');

    /**
     * The pricing API refuses a value finer than two places rather than
     * rounding it itself (TWO-25289).
     */
    const MONEY_DECIMALS = 2;

    /**
     * @param mixed $type
     * @return string
     */
    public static function normalizeType($type)
    {
        $type = (string) $type;

        return in_array($type, self::VALID_TYPES, true) ? $type : 'none';
    }

    /**
     * `percentage` is sent as 0.0 for fixed-only fees so the API default of
     * 100% is never silently applied. Differential mode sends
     * `reference_terms` so the API computes the delta itself.
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

        // Fixed amounts and caps are emitted in the store currency they are
        // configured in; the module re-denominates them into the quote currency
        // before the wire call (Twopayment::convertTwoBuyerFeeShareCurrency,
        // TWO-25105).
        if ($hasFixed && isset($row['fixed']) && (float) $row['fixed'] > 0) {
            $buyer_fee_share['surcharge'] = round((float) $row['fixed'], self::MONEY_DECIMALS);
        }

        // `cap` only applies where the fee has a percentage component, so a
        // stored limit left over from a previous surcharge type must not leak
        // into a fixed-only request.
        //
        // Only a NULL limit means "no cap"; a limit of exactly 0 is relayed as
        // `cap => 0`, bounding the fee at zero (TWO-25289). Not filtered on
        // `> 0` even though the admin form refuses a zero cap: a 0 arriving by
        // a route the form does not police (direct Configuration write, import)
        // would otherwise go out UNCAPPED.
        if ($hasPercentage && isset($row['limit'])) {
            $buyer_fee_share['cap'] = round((float) $row['limit'], self::MONEY_DECIMALS);
        }

        // Percentage modes only, for the same reason as `cap`.
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
     * The API requires both keys and rejects step <= 0, so an unmapped basis
     * or a non-positive step omits the block entirely.
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
