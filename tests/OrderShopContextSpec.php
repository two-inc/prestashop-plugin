<?php

declare(strict_types=1);

/**
 * TWO-27200: TwoOrderShopContext::run() runs work on an order in the order's own shop and hands the back office's
 * shop context back as it found it, also on an exception.
 */
final class OrderShopContextSpec
{
    public static function runAll(): void
    {
        try {
            self::testRunsInTheOrdersShopAndRestores();
            self::testRestoresOnAnException();
        } finally {
            StubStore::reset();
            Context::getContext()->shop = new Shop();
        }
    }

    private static function fleet(bool $multistore): void
    {
        StubStore::reset();
        StubStore::$multistore = $multistore;
        StubStore::$shops = [1 => 1, 2 => 1, 10 => 2];
        StubStore::$configuration['TWO_SPEC_KEY'] = 'all shops';
        StubStore::$configurationShop[10] = ['TWO_SPEC_KEY' => 'shop 10'];
        StubStore::$orders[555] = ['module' => 'twopayment', 'id_shop' => 10];
    }

    private static function testRunsInTheOrdersShopAndRestores(): void
    {
        $inShop10 = [Shop::CONTEXT_SHOP, 10, 10, 'shop 10'];
        // [multistore, back office [context type, context id, context shop], order (id or id_shop), seen inside, description]
        $cases = [
            [false, [Shop::CONTEXT_SHOP, 1, 1], (object) ['id_shop' => 10], [Shop::CONTEXT_SHOP, 1, 1, 'all shops'], 'single shop: nothing switches'],
            [true, [Shop::CONTEXT_ALL, null, 1], (object) ['id_shop' => 10], $inShop10, 'all shops'],
            [true, [Shop::CONTEXT_GROUP, 1, 1], (object) ['id_shop' => 10], $inShop10, 'another group'],
            [true, [Shop::CONTEXT_SHOP, 2, 2], (object) ['id_shop' => 10], $inShop10, 'another shop'],
            [true, [Shop::CONTEXT_ALL, null, 1], 555, $inShop10, 'an order id'],
            [true, [Shop::CONTEXT_SHOP, 10, 10], (object) ['id_shop' => 10], $inShop10, 'the order\'s shop already'],
            [true, [Shop::CONTEXT_ALL, null, 1], (object) ['id_shop' => 99], [Shop::CONTEXT_ALL, null, 1, 'all shops'], 'a shop that does not load: nothing switches'],
            [true, [Shop::CONTEXT_ALL, null, 1], 0, [Shop::CONTEXT_ALL, null, 1, 'all shops'], 'no order: nothing switches'],
        ];
        foreach ($cases as [$multistore, [$type, $id, $contextShop], $order, $inside, $description]) {
            self::fleet($multistore);
            Shop::setContext($type, $id);
            $backOfficeShop = new Shop($contextShop);
            Context::getContext()->shop = $backOfficeShop;

            $seen = TwoOrderShopContext::run($order, static function () {
                return [Shop::getContext(), Shop::getContextShopID(), Context::getContext()->shop->id, Configuration::get('TWO_SPEC_KEY')];
            });

            TinyAssert::same($inside, $seen, $description . ': context, shop, context shop and setting inside');
            $restoredId = $type === Shop::CONTEXT_GROUP ? Shop::getContextShopGroupID() : Shop::getContextShopID();
            TinyAssert::same([$type, $id], [Shop::getContext(), $restoredId], $description . ': shop context handed back');
            TinyAssert::true($backOfficeShop === Context::getContext()->shop, $description . ': context shop handed back');
        }
    }

    private static function testRestoresOnAnException(): void
    {
        self::fleet(true);
        Shop::setContext(Shop::CONTEXT_SHOP, 2);
        $backOfficeShop = new Shop(2);
        Context::getContext()->shop = $backOfficeShop;

        TinyAssert::throws(static function () {
            TwoOrderShopContext::run((object) ['id_shop' => 10], static function () {
                throw new RuntimeException('boom');
            });
        }, 'boom');

        TinyAssert::same([Shop::CONTEXT_SHOP, 2], [Shop::getContext(), Shop::getContextShopID()], 'an exception: shop context handed back');
        TinyAssert::true($backOfficeShop === Context::getContext()->shop, 'an exception: context shop handed back');
    }
}
