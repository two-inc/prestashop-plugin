<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

/**
 * Runs work on an existing order in the order's own shop (TWO-27200).
 *
 * In the back office the shop context is the one the employee picked in the
 * shop selector, which on a multistore install need not be the order's shop.
 * Everything the module reads per shop then reads the wrong one: module
 * settings (Configuration::get() with no shop reads the context shop), and
 * the actionTwoOrderPostprocessing subscribers, which core looks up with
 * `hm.id_shop = Context::getContext()->shop->id`. So both the static shop
 * context and the context's Shop object are switched to the order's shop,
 * as core's Hook::exec() does for its $id_shop argument, and both are handed
 * back as they were found, also on an exception.
 *
 * Core caches the hook module list per context shop id, so the switch reads
 * the order's shop's list, not a cached copy of the selected shop's.
 */
class TwoOrderShopContext
{
    /**
     * @param Order|int $order the order, or its id
     * @param callable $callback run in the order's shop
     * @return mixed what $callback returned
     */
    public static function run($order, callable $callback)
    {
        if (!Shop::isFeatureActive()) {
            return $callback();
        }
        if (!is_object($order)) {
            $order = new Order((int) $order);
        }
        $idShop = isset($order->id_shop) ? (int) $order->id_shop : 0;
        $context = Context::getContext();
        $current = isset($context->shop->id) ? (int) $context->shop->id : 0;
        if ($idShop <= 0
            || ((int) Shop::getContext() === Shop::CONTEXT_SHOP && (int) Shop::getContextShopID() === $idShop && $current === $idShop)
        ) {
            return $callback();
        }
        $shop = new Shop($idShop);
        if (!Validate::isLoadedObject($shop)) {
            return $callback();
        }

        $previousType = Shop::getContext();
        $previousId = (int) $previousType === Shop::CONTEXT_GROUP ? Shop::getContextShopGroupID() : Shop::getContextShopID();
        $previousShop = $context->shop;
        $context->shop = $shop;
        Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
        try {
            return $callback();
        } finally {
            $context->shop = $previousShop;
            Shop::setContext($previousType, $previousId);
        }
    }
}
