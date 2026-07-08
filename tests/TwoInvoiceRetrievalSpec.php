<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/classes/TwoInvoiceRetrievalService.php';

/**
 * Harness with scriptable API responses for the invoice retrieval flow.
 * Invoice fetches are consumed from a queue (so retry behavior is observable);
 * the order GET returns a fixed canned response.
 */
class TwoInvoiceRetrievalHarness extends TwopaymentTestHarness
{
    public $pdfResponses = [];
    public $pdfCalls = 0;
    public $orderResponse = ['http_status' => 500];
    public $orderCalls = 0;
    public $lastOrderTimeout = null;
    public $lastPdfTimeout = null;
    public $attemptRow = false;

    public function getTwoInvoicePdf($two_order_id, $params = [], $timeout = null)
    {
        $this->pdfCalls++;
        $this->lastPdfTimeout = $timeout;
        if (count($this->pdfResponses) > 1) {
            return array_shift($this->pdfResponses);
        }

        return $this->pdfResponses[0];
    }

    public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
    {
        $this->orderCalls++;
        $this->lastOrderTimeout = $timeout;

        return $this->orderResponse;
    }

    protected function getLatestTwoCheckoutAttemptByOrder($id_order)
    {
        return $this->attemptRow;
    }
}

/**
 * Harness exposing the protected admin-tab self-heal path.
 */
class TwoInvoiceTabHarness extends TwopaymentTestHarness
{
    public function ensureTab(): void
    {
        $this->ensureTwoInvoiceAdminTabRegistered();
    }
}

final class TwoInvoiceRetrievalSpec
{
    public static function runAll(): void
    {
        self::testStreamsWhenInvoiceReturnsPdf();
        self::testFulfillingReturnsInfoNotice();
        self::testFulfilledRetriesOnceThenStreams();
        self::testFulfilledRetryFailsIsError();
        self::testOtherStateNamesStateInMessage();
        self::testMissingOrderIdIsError();
        self::test200ButNotPdfIsError();
        self::testNonOrderNotFulfilled400IsErrorAsToday();
        self::testNetworkErrorIsError();
        self::testOrderStateFetchFailureIsError();
        self::testGuestWithValidKeyAllowed();
        self::testGuestWithWrongKeyDenied();
        self::testLoggedInCustomerMismatchDenied();
        self::testLoggedInOwnerWithMatchingSecureKeyAllowed();
        self::testErrorNoticeNeverEchoesUpstreamText();
        self::testAdminTabInstalledWhenMissing();
        self::testAdminTabNotReinstalledWhenAlreadyRegistered();
    }

    private static function pdfOk(): array
    {
        return [
            'http_status' => 200,
            'body' => "%PDF-1.4 test-invoice",
            'content_type' => 'application/pdf',
            'error_code' => '',
            'data' => null,
        ];
    }

    private static function notFulfilled(): array
    {
        return [
            'http_status' => 400,
            'body' => '{"error_code":"ORDER_NOT_FULFILLED"}',
            'content_type' => 'application/json',
            'error_code' => 'ORDER_NOT_FULFILLED',
            'data' => ['error_code' => 'ORDER_NOT_FULFILLED'],
        ];
    }

    private static function orderState(string $state): array
    {
        return [
            'http_status' => 200,
            'data' => ['id' => 'two-1', 'state' => $state],
        ];
    }

    private static function service(TwoInvoiceRetrievalHarness $module): TwoInvoiceRetrievalService
    {
        return new TwoInvoiceRetrievalService($module);
    }

    private static function baseRow(): array
    {
        return ['two_order_id' => 'two-1'];
    }

    private static function testStreamsWhenInvoiceReturnsPdf(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $module->pdfResponses = [self::pdfOk()];

        $result = self::service($module)->resolveInvoiceDownload(7, self::baseRow());

        TinyAssert::same('stream', $result['action']);
        TinyAssert::same("%PDF-1.4 test-invoice", $result['body']);
        TinyAssert::same(1, $module->pdfCalls, 'Invoice fetch should be called exactly once');
        TinyAssert::same(0, $module->orderCalls, 'Order GET must not run on a successful fetch');
        TinyAssert::same(
            Twopayment::API_TIMEOUT_PDF_FETCH,
            $module->lastPdfTimeout,
            'Synchronous PDF fetch must use the tight timeout, not the 30s default'
        );
    }

    private static function testFulfillingReturnsInfoNotice(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $module->pdfResponses = [self::notFulfilled()];
        $module->orderResponse = self::orderState('FULFILLING');

        $result = self::service($module)->resolveInvoiceDownload(7, self::baseRow());

        TinyAssert::same('notice', $result['action']);
        TinyAssert::same('info', $result['level'], 'FULFILLING must be informational, not an error');
        TinyAssert::same(TwoInvoiceRetrievalService::NOTICE_NOT_READY, $result['code']);
        TinyAssert::same(1, $module->pdfCalls, 'No retry while still FULFILLING');
        TinyAssert::same(1, $module->orderCalls);
        TinyAssert::same(Twopayment::API_TIMEOUT_STATE_CHECK, $module->lastOrderTimeout, 'State check must use the tight timeout');
    }

    private static function testFulfilledRetriesOnceThenStreams(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $module->pdfResponses = [self::notFulfilled(), self::pdfOk()];
        $module->orderResponse = self::orderState('FULFILLED');

        $result = self::service($module)->resolveInvoiceDownload(7, self::baseRow());

        TinyAssert::same('stream', $result['action']);
        TinyAssert::same(2, $module->pdfCalls, 'FULFILLED must retry the invoice fetch exactly once');
        TinyAssert::same(
            Twopayment::API_TIMEOUT_PDF_FETCH,
            $module->lastPdfTimeout,
            'The FULFILLED retry fetch must also use the tight timeout'
        );
    }

    private static function testFulfilledRetryFailsIsError(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $module->pdfResponses = [self::notFulfilled(), self::notFulfilled()];
        $module->orderResponse = self::orderState('FULFILLED');

        $result = self::service($module)->resolveInvoiceDownload(7, self::baseRow());

        TinyAssert::same('notice', $result['action']);
        TinyAssert::same('error', $result['level']);
        TinyAssert::same(2, $module->pdfCalls, 'Only one retry is allowed');
    }

    private static function testOtherStateNamesStateInMessage(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $module->pdfResponses = [self::notFulfilled()];
        $module->orderResponse = self::orderState('CANCELLED');

        $result = self::service($module)->resolveInvoiceDownload(7, self::baseRow());

        TinyAssert::same('notice', $result['action']);
        TinyAssert::same('info', $result['level']);
        TinyAssert::same(TwoInvoiceRetrievalService::NOTICE_UNAVAILABLE_STATE, $result['code']);
        TinyAssert::same('CANCELLED', $result['state']);
        TinyAssert::true(strpos($result['message'], 'CANCELLED') !== false, 'Message must name the order state');
        TinyAssert::same(1, $module->pdfCalls, 'No retry for non-FULFILLED states');
    }

    private static function testMissingOrderIdIsError(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $module->pdfResponses = [self::pdfOk()];

        $result = self::service($module)->resolveInvoiceDownload(7, ['two_order_id' => '']);

        TinyAssert::same('notice', $result['action']);
        TinyAssert::same('error', $result['level']);
        TinyAssert::same(TwoInvoiceRetrievalService::NOTICE_NO_REFERENCE, $result['code']);
        TinyAssert::same(0, $module->pdfCalls, 'No API call without a Two order reference');
        TinyAssert::same(0, $module->orderCalls);
    }

    private static function test200ButNotPdfIsError(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $module->pdfResponses = [[
            'http_status' => 200,
            'body' => '{"unexpected":"json"}',
            'content_type' => 'application/json',
            'error_code' => '',
            'data' => ['unexpected' => 'json'],
        ]];

        $result = self::service($module)->resolveInvoiceDownload(7, self::baseRow());

        TinyAssert::same('notice', $result['action']);
        TinyAssert::same('error', $result['level'], 'A non-PDF 200 body must never be streamed');
        TinyAssert::same(0, $module->orderCalls);
    }

    private static function testNonOrderNotFulfilled400IsErrorAsToday(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $module->pdfResponses = [[
            'http_status' => 400,
            'body' => '{"error_code":"SOMETHING_ELSE","error_message":"other failure"}',
            'content_type' => 'application/json',
            'error_code' => 'SOMETHING_ELSE',
            'data' => ['error_code' => 'SOMETHING_ELSE', 'error_message' => 'other failure'],
        ]];

        $result = self::service($module)->resolveInvoiceDownload(7, self::baseRow());

        TinyAssert::same('notice', $result['action']);
        TinyAssert::same('error', $result['level']);
        TinyAssert::same(0, $module->orderCalls, 'Unrelated 400s must not trigger the state-check flow');
        TinyAssert::same(1, $module->pdfCalls, 'Unrelated 400s must not be retried');
    }

    private static function testNetworkErrorIsError(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $module->pdfResponses = [[
            'http_status' => 0,
            'body' => '',
            'content_type' => '',
            'error_code' => '',
            'data' => ['error' => 'Connection error', 'error_message' => 'Unable to connect to Two API. Please check your server configuration.'],
        ]];

        $result = self::service($module)->resolveInvoiceDownload(7, self::baseRow());

        TinyAssert::same('notice', $result['action']);
        TinyAssert::same('error', $result['level']);
        TinyAssert::same(0, $module->orderCalls);
    }

    private static function testOrderStateFetchFailureIsError(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $module->pdfResponses = [self::notFulfilled()];
        $module->orderResponse = ['http_status' => 500];

        $result = self::service($module)->resolveInvoiceDownload(7, self::baseRow());

        TinyAssert::same('notice', $result['action']);
        TinyAssert::same('error', $result['level']);
        TinyAssert::same(1, $module->pdfCalls, 'No retry when the state cannot be determined');
    }

    private static function testGuestWithValidKeyAllowed(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $order = (object) ['id_customer' => 5];
        $customer = (object) ['secure_key' => 'sk-guest-order'];

        // Guest checkout: no logged-in context, only the `key` query parameter.
        TinyAssert::true($module->isTwoOrderCustomerAccessAuthorized($order, $customer, 'sk-guest-order', 0, ''));
    }

    private static function testGuestWithWrongKeyDenied(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $order = (object) ['id_customer' => 5];
        $customer = (object) ['secure_key' => 'sk-guest-order'];

        TinyAssert::false($module->isTwoOrderCustomerAccessAuthorized($order, $customer, 'sk-attacker', 0, ''));
    }

    private static function testLoggedInCustomerMismatchDenied(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $order = (object) ['id_customer' => 5];
        $customer = (object) ['secure_key' => 'sk-owner'];

        // Logged-in customer 9 (different secure key) must not access order of customer 5.
        TinyAssert::false($module->isTwoOrderCustomerAccessAuthorized($order, $customer, '', 9, 'sk-other'));
    }

    private static function testLoggedInOwnerWithMatchingSecureKeyAllowed(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $order = (object) ['id_customer' => 5];
        $customer = (object) ['secure_key' => 'sk-owner'];

        TinyAssert::true($module->isTwoOrderCustomerAccessAuthorized($order, $customer, '', 5, 'sk-owner'));
    }

    private static function testErrorNoticeNeverEchoesUpstreamText(): void
    {
        $module = new TwoInvoiceRetrievalHarness();
        $module->pdfResponses = [[
            'http_status' => 400,
            'body' => '{"error_code":"SOMETHING_ELSE","error_message":"internal upstream detail"}',
            'content_type' => 'application/json',
            'error_code' => 'SOMETHING_ELSE',
            'data' => ['error_code' => 'SOMETHING_ELSE', 'error_message' => 'internal upstream detail'],
        ]];

        $result = self::service($module)->resolveInvoiceDownload(7, self::baseRow());

        TinyAssert::same('error', $result['level']);
        TinyAssert::same(TwoInvoiceRetrievalService::NOTICE_ERROR, $result['code']);
        TinyAssert::same(
            $module->getTwoInvoiceNoticeMessage(TwoInvoiceRetrievalService::NOTICE_ERROR),
            $result['message'],
            'Error notices must carry only the whitelisted generic message'
        );
        TinyAssert::false(
            strpos($result['message'], 'internal upstream detail') !== false,
            'Raw upstream API error text must never reach the user-facing message'
        );
    }

    private static function testAdminTabInstalledWhenMissing(): void
    {
        StubStore::$tabIds = [];
        StubStore::$tabAddCalls = [];

        $module = new TwoInvoiceTabHarness();
        $module->ensureTab();

        TinyAssert::same(
            ['AdminTwopaymentInvoice'],
            StubStore::$tabAddCalls,
            'A missing invoice admin tab must be installed by the self-heal path'
        );
        TinyAssert::true(
            Tab::getIdFromClassName('AdminTwopaymentInvoice') > 0,
            'The tab must be registered after self-heal'
        );

        StubStore::$tabIds = ['AdminTwopaymentInvoice' => 1];
        StubStore::$tabAddCalls = [];
    }

    private static function testAdminTabNotReinstalledWhenAlreadyRegistered(): void
    {
        StubStore::$tabIds = ['AdminTwopaymentInvoice' => 42];
        StubStore::$tabAddCalls = [];

        $module = new TwoInvoiceTabHarness();
        $module->ensureTab();

        TinyAssert::same([], StubStore::$tabAddCalls, 'An already-registered tab must not be reinstalled');
        TinyAssert::same(42, Tab::getIdFromClassName('AdminTwopaymentInvoice'), 'Existing tab id must be untouched');

        StubStore::$tabIds = ['AdminTwopaymentInvoice' => 1];
        StubStore::$tabAddCalls = [];
    }
}
