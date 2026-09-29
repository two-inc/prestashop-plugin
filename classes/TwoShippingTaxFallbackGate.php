<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

/**
 * Whether the Default shipping tax code fallback is switched on for this shop
 * (TWO-26082). Off unless Two has enabled it for the merchant with
 * `bin/console twopayment:shipping-tax-fallback enable`. While off, the admin
 * field is hidden and a stored group is kept but never consulted.
 */
class TwoShippingTaxFallbackGate
{
    const CONFIG_ENABLED = 'PS_TWO_SHIPPING_TAX_FALLBACK_ENABLED';
    const CONFIG_GROUP = 'PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP';
    const COMMAND_NAME = 'twopayment:shipping-tax-fallback';
    const ACTIONS = array('enable', 'disable', 'status');

    /**
     * @return bool
     */
    public static function isEnabled()
    {
        return trim((string) Configuration::get(self::CONFIG_ENABLED)) === '1';
    }

    /**
     * Applies one console action.
     *
     * @param string $action enable, disable or status
     * @return array{0:int,1:string} Exit code and the line to print
     */
    public static function run($action)
    {
        $action = strtolower(trim((string) $action));
        if (!in_array($action, self::ACTIONS, true)) {
            return array(1, 'Unknown action "' . $action . '". Use: ' . self::COMMAND_NAME . ' enable|disable|status');
        }
        if ($action === 'enable') {
            Configuration::updateValue(self::CONFIG_ENABLED, '1');
        } elseif ($action === 'disable') {
            Configuration::updateValue(self::CONFIG_ENABLED, '0');
        }

        return array(0, self::describe());
    }

    /**
     * @return string
     */
    private static function describe()
    {
        $group = trim((string) Configuration::get(self::CONFIG_GROUP));

        return 'Default shipping tax code fallback: ' . (self::isEnabled() ? 'enabled' : 'disabled') .
            '. Stored tax rules group: ' . ($group === '' ? 'none' : $group) .
            (self::isEnabled() || $group === '' ? '.' : ' (kept, not used while disabled).');
    }
}
