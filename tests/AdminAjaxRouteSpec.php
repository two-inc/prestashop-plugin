<?php

declare(strict_types=1);

final class TwoAdminAjaxDispatched extends RuntimeException
{
}

/**
 * Records which admin AJAX handler getContent() handed the request to, and
 * stops there, as the real handlers do by ending the request.
 */
class TwoAdminAjaxRouteHarness extends TwopaymentTestHarness
{
    public function ajaxUrl(string $action): string
    {
        return $this->getTwoAdminAjaxUrl($action);
    }

    public function ajaxProcessFetchMerchantFeeRates()
    {
        throw new TwoAdminAjaxDispatched('FetchMerchantFeeRates');
    }

    public function ajaxProcessVerifyApiKeyLive()
    {
        throw new TwoAdminAjaxDispatched('VerifyApiKeyLive');
    }

    public function ajaxProcessRefreshMerchantRecord()
    {
        throw new TwoAdminAjaxDispatched('RefreshMerchantRecord');
    }
}

/**
 * TWO-27370: PrestaShop 9 has no legacy AdminModules controller, so the admin
 * AJAX calls (fees, API-key check, merchant refresh) never reached the module
 * and the fee line always read "could not be reached". They now go to the
 * configure page's own link, whose getContent() dispatches them there.
 */
final class AdminAjaxRouteSpec
{
    public static function runAll(): void
    {
        self::testUrlIsTheConfigureLinkWithTheActionAppended();
        self::testGetContentDispatchesOnlyListedAjaxActions();
    }

    private static function testUrlIsTheConfigureLinkWithTheActionAppended(): void
    {
        $module = new TwoAdminAjaxRouteHarness();
        foreach (Twopayment::ADMIN_AJAX_ACTIONS as $action) {
            TinyAssert::same(
                'https://shop.local/admin/AdminModules?configure=twopayment&ajax=1&action=' . $action,
                $module->ajaxUrl($action),
                $action . ': the action is appended after getAdminLink(), never passed to it'
            );
        }
    }

    private static function testGetContentDispatchesOnlyListedAjaxActions(): void
    {
        $cases = [
            // [ajax, action, dispatched handler or null, description]
            ['1', 'FetchMerchantFeeRates', 'FetchMerchantFeeRates', 'the fee lookup is dispatched'],
            ['1', 'VerifyApiKeyLive', 'VerifyApiKeyLive', 'the API-key check is dispatched'],
            ['1', 'RefreshMerchantRecord', 'RefreshMerchantRecord', 'the merchant refresh is dispatched'],
            ['1', 'SomethingElse', null, 'an unlisted action is not dispatched'],
            ['1', 'fetchMerchantFeeRates', null, 'the action name is matched exactly'],
            [null, 'FetchMerchantFeeRates', null, 'a non-AJAX request renders the page'],
        ];

        foreach ($cases as [$ajax, $action, $expected, $description]) {
            Tools::resetTestValues();
            if ($ajax !== null) {
                Tools::setTestValue('ajax', $ajax);
            }
            Tools::setTestValue('action', $action);

            $dispatched = null;
            try {
                (new TwoAdminAjaxRouteHarness())->getContent();
            } catch (TwoAdminAjaxDispatched $e) {
                $dispatched = $e->getMessage();
            } catch (Throwable $e) {
                // Past the dispatch, into the page render the harness does
                // not fully stub: not dispatched.
            }

            TinyAssert::same($expected, $dispatched, $description);
        }
        Tools::resetTestValues();
    }
}
