<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

/**
 * Whether the Default shipping tax code fallback is switched on for a shop
 * (TWO-26082). Off unless Two has enabled it for the merchant with
 * `bin/console twopayment:shipping-tax-fallback enable`, globally or for one
 * shop with `--id_shop=<n>`. A shop's own setting wins over the global one.
 * While off, the admin field is hidden and a stored group is kept but never
 * consulted.
 */
class TwoShippingTaxFallbackGate
{
    const CONFIG_ENABLED = 'PS_TWO_SHIPPING_TAX_FALLBACK_ENABLED';
    const CONFIG_GROUP = 'PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP';
    const COMMAND_NAME = 'twopayment:shipping-tax-fallback';
    const ACTIONS = array('enable', 'disable', 'status');

    /**
     * @param int|null $idShop The shop to read for, or null for the context's
     * @param int|null $idShopGroup That shop's group, or null for the context's
     * @return bool
     */
    public static function isEnabled($idShop = null, $idShopGroup = null)
    {
        return trim((string) Configuration::get(self::CONFIG_ENABLED, null, $idShopGroup, $idShop)) === '1';
    }

    /**
     * Applies one console action.
     *
     * @param string $action enable, disable or status
     * @param string|null $idShop The shop to write, or null for the global row
     * @return array{0:int,1:string} Exit code and the effective state of every shop
     */
    public static function run($action, $idShop = null)
    {
        $action = strtolower(trim((string) $action));
        if (!in_array($action, self::ACTIONS, true)) {
            return array(1, 'Unknown action "' . $action . '". Use: ' . self::COMMAND_NAME . ' enable|disable|status [--id_shop=<n>]');
        }
        $shops = array_map('intval', array_values(Shop::getShops(false, null, true)));
        if ($idShop !== null) {
            $idShop = trim((string) $idShop);
            if (!ctype_digit($idShop) || !in_array((int) $idShop, $shops, true)) {
                return array(1, 'Unknown shop "' . $idShop . '". Shops: ' . implode(', ', $shops) . '.');
            }
            $idShop = (int) $idShop;
        }
        if ($action !== 'status') {
            $value = $action === 'enable' ? '1' : '0';
            if ($idShop === null) {
                // updateValue() would write the context shop's row under multistore; this must be the global one.
                Configuration::updateGlobalValue(self::CONFIG_ENABLED, $value);
            } else {
                Configuration::updateValue(self::CONFIG_ENABLED, $value, false, (int) Shop::getGroupFromShop($idShop), $idShop);
            }
        }

        return array(0, self::describe($shops));
    }

    /**
     * @param int[] $shops
     * @return string
     */
    private static function describe(array $shops)
    {
        $lines = array('Default shipping tax code fallback:');
        if (Shop::isFeatureActive()) {
            $lines[] = '  global: ' . (trim((string) Configuration::get(self::CONFIG_ENABLED, null, 0, 0)) === '1' ? 'enabled' : 'disabled');
        }
        foreach ($shops as $idShop) {
            $idShopGroup = (int) Shop::getGroupFromShop($idShop);
            $enabled = self::isEnabled($idShop, $idShopGroup);
            $group = trim((string) Configuration::get(self::CONFIG_GROUP, null, $idShopGroup, $idShop));
            $lines[] = '  shop ' . $idShop . ': ' . ($enabled ? 'enabled' : 'disabled') .
                ' (' . self::source($idShop, $idShopGroup) . '), stored tax rules group: ' .
                ($group === '' ? 'none' : $group . ($enabled ? '' : ', kept but not used while disabled'));
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Which row the shop's effective setting comes from.
     *
     * @param int $idShop
     * @param int $idShopGroup
     * @return string
     */
    private static function source($idShop, $idShopGroup)
    {
        if (Shop::isFeatureActive() && Configuration::hasKey(self::CONFIG_ENABLED, null, null, $idShop)) {
            return 'shop setting';
        }
        if (Shop::isFeatureActive() && Configuration::hasKey(self::CONFIG_ENABLED, null, $idShopGroup)) {
            return 'shop group setting';
        }

        return Configuration::hasKey(self::CONFIG_ENABLED) ? 'global setting' : 'not set';
    }
}
