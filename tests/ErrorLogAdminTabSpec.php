<?php

declare(strict_types=1);

/**
 * Harness exposing the protected error log admin-tab self-heal path.
 */
class TwoErrorLogTabHarness extends TwopaymentTestHarness
{
    public function ensureTab(): void
    {
        $this->ensureTwoErrorLogAdminTabRegistered();
    }
}

/**
 * TWO-27355: only install() created the AdminTwoErrorLog tab, so a shop
 * upgraded from an earlier version never had it and "View error log" landed on
 * controller-not-found. The constructor's self-heal registers it.
 */
final class ErrorLogAdminTabSpec
{
    public static function runAll(): void
    {
        $cases = [
            // [registered tabs before, expected add calls, expected error log tab id (null: any > 0), description]
            [['AdminTwopaymentInvoice' => 1], ['AdminTwoErrorLog'], null, 'an upgraded shop with no error log tab gets one'],
            [['AdminTwopaymentInvoice' => 1, 'AdminTwoErrorLog' => 42], [], 42, 'an already registered error log tab is left alone'],
        ];

        $savedIds = StubStore::$tabIds;
        $savedCalls = StubStore::$tabAddCalls;
        try {
            foreach ($cases as [$before, $expectedAdds, $expectedId, $description]) {
                StubStore::$tabIds = $before;
                StubStore::$tabAddCalls = [];

                (new TwoErrorLogTabHarness())->ensureTab();

                TinyAssert::same($expectedAdds, StubStore::$tabAddCalls, $description . ': tab add calls');
                $id = Tab::getIdFromClassName('AdminTwoErrorLog');
                if ($expectedId === null) {
                    TinyAssert::true($id > 0, $description . ': tab registered');
                } else {
                    TinyAssert::same($expectedId, $id, $description . ': tab id untouched');
                }
            }
        } finally {
            StubStore::$tabIds = $savedIds;
            StubStore::$tabAddCalls = $savedCalls;
        }
    }
}
