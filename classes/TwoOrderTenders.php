<?php
/**
 * An order's tenders (TWO-26326): anything that has already paid part of the
 * order without being an order line, such as a gift card or store credit.
 *
 * Core PrestaShop has neither. Its vouchers are cart rules, which are order
 * lines (discount lines) already and are never tenders. A module that takes a
 * payment outside the cart, so that part of the cart total PrestaShop works
 * out is already paid when the buyer reaches Two, declares it on the hook
 * actionTwoOrderTenders, which is normally empty. See README "Tenders".
 *
 * Every name here is part of a permanent public contract.
 *
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoOrderTenders
{
    const HOOK = 'actionTwoOrderTenders';

    const TYPE_GIFT_CARD = 'gift_card';
    const TYPE_STORE_CREDIT = 'store_credit';
    const TYPE_OTHER = 'other';

    const MAX_STRING = 120;

    /**
     * The tenders the modules on the hook declare, checked.
     *
     * With $slip, each module declares what that credit slip returned to the
     * tenders rather than what the order was paid with.
     *
     * A module's exception reaches the caller, so a request whose tenders
     * cannot be read is never sent as if it had none.
     *
     * @param Cart|null $cart
     * @param Order|null $order
     * @param OrderSlip|object|null $slip
     * @return array<int,array{type:string,label:string,amount:float,source:string}>
     */
    public static function collect($cart, $order, $slip = null)
    {
        if ($cart === null && $order === null) {
            return array();
        }
        $declared = array();
        $args = array('cart' => $cart, 'order' => $order, 'order_slip' => $slip, 'tenders' => &$declared);
        $method = 'hook' . ucfirst(self::HOOK);
        foreach (TwoOrderPostprocessing::runnableSubscribers(self::HOOK) as $module) {
            $module->{$method}($args);
        }

        return self::normalise($declared);
    }

    /**
     * Each entry kept as {type, label, amount, source}. An amount of zero is
     * skipped. An entry that is not an array, or whose amount is missing,
     * not a number or negative, is refused and logged with its type, source
     * and amount, never its label, which can name a card. An unknown type is
     * reported as other.
     *
     * @param mixed $declared
     * @return array<int,array{type:string,label:string,amount:float,source:string}>
     */
    public static function normalise($declared)
    {
        $out = array();
        foreach (is_array($declared) ? $declared : array($declared) as $entry) {
            $type = is_array($entry) && isset($entry['type']) && is_string($entry['type']) ? $entry['type'] : '';
            $source = is_array($entry) && isset($entry['source']) && is_scalar($entry['source']) ? self::cut((string) $entry['source']) : '';
            $amount = is_array($entry) && array_key_exists('amount', $entry) ? $entry['amount'] : null;
            if (!is_array($entry) || !is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0) {
                PrestaShopLogger::addLog(
                    'TwoPayment: A declared tender was refused and is not sent to Two (type ' . self::cut($type) . ', source ' . $source
                    . ', amount ' . (is_scalar($amount) ? self::cut(var_export($amount, true)) : gettype($amount)) . '): its amount must be a number of zero or more.',
                    2
                );
                continue;
            }
            if (round((float) $amount, 2) == 0.0) {
                continue;
            }
            $out[] = array(
                'type' => in_array($type, array(self::TYPE_GIFT_CARD, self::TYPE_STORE_CREDIT), true) ? $type : self::TYPE_OTHER,
                'label' => isset($entry['label']) && is_scalar($entry['label']) ? self::cut((string) $entry['label']) : '',
                'amount' => round((float) $amount, 2),
                'source' => $source,
            );
        }

        return $out;
    }

    /**
     * @param array $tenders normalise()'s list
     * @return float what they paid, to the cent
     */
    public static function total(array $tenders)
    {
        $cents = 0;
        foreach ($tenders as $tender) {
            $cents += (int) round((float) $tender['amount'] * 100);
        }

        return $cents / 100;
    }

    private static function cut($value)
    {
        return strlen($value) <= self::MAX_STRING ? $value : substr($value, 0, self::MAX_STRING) . '...';
    }
}
