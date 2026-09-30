<?php

declare(strict_types=1);

/**
 * TWO-26094: twopayment_cart_record holds buyer-typed company and address data,
 * so it is purged daily from any storefront request, and deleted on a psgdpr
 * erasure request.
 */
final class CartRecordRetentionSpec
{
    public static function runAll(): void
    {
        $customer5 = [10 => [5, 1], 11 => [6, 1]];
        // [records as [id_cart, id_shop, days old], orders as [id_cart, id_shop], carts as id => [id_customer, id_shop],
        //  action, expected remaining "cart:shop" rows, description]
        $cases = [
            [[[1, 1, 89], [2, 1, 91]], [], [], ['front'], ['1:1'], 'purge cutoff: 89 days kept, 91 purged'],
            [[[3, 1, 1]], [[3, 1]], [], ['front'], [], 'a cart with an order paid by another method is purged'],
            [[[4, 1, 1], [4, 2, 1]], [[4, 1]], [], ['front'], ['4:2'], 'the same cart id on a different shop is isolated from the purge'],
            [[[2, 1, 91]], [], [], ['front', 'attempt purge fails'], [], 'the record purge runs when the attempt purge fails'],
            [[[2, 1, 91]], [], [], ['front', 'ran an hour ago'], ['2:1'], 'the purge runs at most once a day'],
            [[[10, 1, 1], [11, 1, 1]], [], $customer5, ['gdpr', ['id' => 5]], ['11:1'], 'GDPR delete removes only that customer\'s rows (psgdpr 1.x shape)'],
            [[[10, 1, 1], [11, 1, 1]], [], $customer5, ['gdpr', ['5']], ['11:1'], 'GDPR delete by customer id (psgdpr 2.x shape)'],
            [[[10, 1, 1], [11, 1, 1]], [], $customer5, ['gdpr', [['email' => 'five@example.com']]], ['11:1'], 'GDPR delete by email'],
            [[[10, 1, 1], [10, 2, 1]], [], $customer5, ['gdpr', ['id' => 5]], ['10:2'], 'GDPR delete: the same cart id on a different shop is isolated'],
        ];

        $failures = [];
        foreach ($cases as [$records, $orders, $carts, $action, $expected, $description]) {
            try {
                self::runCase($records, $orders, $carts, $action, $expected);
            } catch (Throwable $e) {
                $failures[] = $description . ': ' . $e->getMessage();
            }
        }

        try {
            self::assertExportAndRegistration();
        } catch (Throwable $e) {
            $failures[] = 'GDPR export and hook registration: ' . $e->getMessage();
        }
        TinyAssert::true($failures === [], "failing rows:\n" . implode("\n", $failures));
    }

    private static function runCase(array $records, array $orders, array $carts, array $action, array $expected): void
    {
        self::seed($records, $orders, $carts);
        $module = new TwopaymentTestHarness();

        if ($action[0] === 'front') {
            if (($action[1] ?? '') === 'attempt purge fails') {
                StubStore::$dbFailOn = ['/twopayment_attempt/'];
            }
            if (($action[1] ?? '') === 'ran an hour ago') {
                Configuration::updateValue('PS_TWO_ATTEMPT_CLEANUP_LAST_RUN', (string) (time() - 3600));
            }
            $module->hookActionFrontControllerInitAfter([]);
        } else {
            TinyAssert::same('true', $module->hookActionDeleteGDPRCustomer($action[1]), 'psgdpr success return');
        }

        TinyAssert::same($expected, self::remaining(), 'remaining rows');
    }

    private static function assertExportAndRegistration(): void
    {
        self::seed([[10, 1, 1], [11, 1, 1]], [], [10 => [5, 1], 11 => [6, 1]]);
        $export = json_decode((string) (new TwopaymentTestHarness())->hookActionExportGDPRData(['id' => 5]), true);
        TinyAssert::same([10], array_map('intval', array_column((array) $export, 'id_cart')), 'export carries only the customer\'s rows');

        require_once __DIR__ . '/../upgrade/upgrade-2.7.19.php';
        StubStore::$registerHookCalls = [];
        TinyAssert::true(upgrade_module_2_7_19(new TwopaymentTestHarness()), 'upgrade succeeds');
        TinyAssert::same(['actionDeleteGDPRCustomer', 'actionExportGDPRData'], StubStore::$registerHookCalls, 'upgrade registers the GDPR hooks');
    }

    private static function seed(array $records, array $orders, array $carts): void
    {
        StubStore::$cartRecords = [];
        StubStore::$cartRecordUpdatedAt = [];
        StubStore::$dbFailOn = [];
        StubStore::$orders = [];
        StubStore::$carts = [];
        StubStore::$customers = [5 => ['email' => 'five@example.com'], 6 => ['email' => 'six@example.com']];
        Configuration::updateValue('PS_TWO_ATTEMPT_CLEANUP_LAST_RUN', '0');
        Context::getContext()->cart = null;
        foreach ($records as [$cartId, $shopId, $daysOld]) {
            StubStore::$cartRecords[$cartId][$shopId]['company'] = '{"name":"Acme Ltd"}';
            StubStore::$cartRecordUpdatedAt[$cartId][$shopId]['company'] = date('Y-m-d H:i:s', time() - $daysOld * 86400);
        }
        foreach ($orders as $i => [$cartId, $shopId]) {
            StubStore::$orders[900 + $i] = ['id_cart' => $cartId, 'id_shop' => $shopId, 'module' => 'ps_wirepayment'];
        }
        foreach ($carts as $cartId => [$customerId, $shopId]) {
            StubStore::$carts[$cartId] = ['id_customer' => $customerId, 'id_shop' => $shopId];
        }
    }

    /** @return string[] */
    private static function remaining(): array
    {
        $rows = [];
        foreach (StubStore::$cartRecords as $cartId => $shops) {
            foreach ($shops as $shopId => $recordsByName) {
                if ($recordsByName !== []) {
                    $rows[] = $cartId . ':' . $shopId;
                }
            }
        }
        sort($rows);

        return $rows;
    }
}
