<?php
/**
 * Two Invoice Retrieval Service
 *
 * Resolves an invoice PDF download with a graceful order-state check:
 * when the invoice fetch fails with 400 ORDER_NOT_FULFILLED the service
 * checks the order state and turns the raw API error into an actionable
 * user-facing notice (or a single retry when the order is FULFILLED).
 *
 * The flow is pure: it returns a discriminated result array and never
 * echoes, redirects, or exits. Controllers map the result to a PDF stream
 * or a redirect-with-notice.
 *
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoInvoiceRetrievalService
{
    const NOTICE_NOT_READY = 'not_ready';
    const NOTICE_UNAVAILABLE_STATE = 'unavailable_state';
    const NOTICE_NO_REFERENCE = 'no_reference';
    const NOTICE_ERROR = 'error';

    const ERROR_CODE_ORDER_NOT_FULFILLED = 'ORDER_NOT_FULFILLED';

    /** @var Twopayment */
    private $module;

    /**
     * @param Twopayment $module Module instance
     */
    public function __construct($module)
    {
        $this->module = $module;
    }

    /**
     * Resolve the invoice download for a local order.
     *
     * Result shapes:
     * - ['action' => 'stream', 'body' => string, 'content_type' => 'application/pdf']
     * - ['action' => 'notice', 'level' => 'info'|'error', 'code' => NOTICE_*, 'state' => string, 'message' => string]
     *
     * @param int $id_order Local order ID
     * @param array $twopaymentdata Persisted twopayment row for the order
     * @param string|null $lang Optional invoice language code
     * @return array
     */
    public function resolveInvoiceDownload($id_order, $twopaymentdata, $lang = null)
    {
        $two_order_id = $this->module->resolveTwoOrderIdForInvoice((int)$id_order, $twopaymentdata);
        if (Tools::isEmpty($two_order_id)) {
            return $this->notice('error', self::NOTICE_NO_REFERENCE);
        }

        $params = array();
        if ($lang) {
            $params['lang'] = $lang;
        }

        $fetch = $this->module->getTwoInvoicePdf($two_order_id, $params);
        if ($this->isPdfSuccess($fetch)) {
            return $this->stream($fetch);
        }

        if (!$this->isOrderNotFulfilled($fetch)) {
            // Any other failure (network error, 5xx, unrelated 400, non-PDF 200
            // body) keeps today's error behavior - no new flow is invented here.
            return $this->errorNotice($fetch);
        }

        $order_state = $this->module->fetchTwoOrderStateFromApi(
            $two_order_id,
            Twopayment::API_TIMEOUT_STATE_CHECK
        );
        if ((int)$order_state['http_status'] !== Twopayment::HTTP_STATUS_OK || $order_state['state'] === '') {
            return $this->errorNotice($fetch);
        }

        $state = $order_state['state'];

        if ($state === 'FULFILLING') {
            // Informational, not an error: the invoice simply is not ready yet.
            return $this->notice('info', self::NOTICE_NOT_READY);
        }

        if ($state === 'FULFILLED') {
            // The order just became FULFILLED; the PDF may exist now. Retry once.
            $retry = $this->module->getTwoInvoicePdf($two_order_id, $params);
            if ($this->isPdfSuccess($retry)) {
                return $this->stream($retry);
            }

            return $this->errorNotice($retry);
        }

        return $this->notice('info', self::NOTICE_UNAVAILABLE_STATE, $state);
    }

    /**
     * @param array $fetch Response from Twopayment::getTwoInvoicePdf
     * @return bool True when the response is a streamable PDF
     */
    private function isPdfSuccess($fetch)
    {
        if (!is_array($fetch) || (int)($fetch['http_status'] ?? 0) !== Twopayment::HTTP_STATUS_OK) {
            return false;
        }

        $body = isset($fetch['body']) ? (string)$fetch['body'] : '';
        $content_type = isset($fetch['content_type']) ? (string)$fetch['content_type'] : '';

        // Never stream a JSON/HTML error body as a .pdf attachment.
        return strncmp($body, '%PDF-', 5) === 0 || stripos($content_type, 'application/pdf') !== false;
    }

    /**
     * @param array $fetch Response from Twopayment::getTwoInvoicePdf
     * @return bool
     */
    private function isOrderNotFulfilled($fetch)
    {
        return is_array($fetch) &&
            (int)($fetch['http_status'] ?? 0) === Twopayment::HTTP_STATUS_BAD_REQUEST &&
            (string)($fetch['error_code'] ?? '') === self::ERROR_CODE_ORDER_NOT_FULFILLED;
    }

    /**
     * @param string $level 'info' or 'error'
     * @param string $code NOTICE_* code
     * @param string $state Two order state (unavailable-state notices only)
     * @return array
     */
    private function notice($level, $code, $state = '')
    {
        return array(
            'action' => 'notice',
            'level' => $level,
            'code' => $code,
            'state' => $state,
            'message' => $this->module->getTwoInvoiceNoticeMessage($code, $state),
        );
    }

    /**
     * Error notice preserving today's error rendering (getTwoErrorMessage).
     *
     * @param array $fetch Response from Twopayment::getTwoInvoicePdf
     * @return array
     */
    private function errorNotice($fetch)
    {
        $body = (is_array($fetch) && isset($fetch['data']) && is_array($fetch['data'])) ? $fetch['data'] : null;
        $message = $body !== null ? $this->module->getTwoErrorMessage($body) : null;
        if (Tools::isEmpty($message)) {
            $message = $this->module->getTwoInvoiceNoticeMessage(self::NOTICE_ERROR);
        }

        return array(
            'action' => 'notice',
            'level' => 'error',
            'code' => self::NOTICE_ERROR,
            'state' => '',
            'message' => (string)$message,
        );
    }

    /**
     * @param array $fetch Successful PDF response
     * @return array
     */
    private function stream($fetch)
    {
        return array(
            'action' => 'stream',
            'body' => (string)$fetch['body'],
            'content_type' => 'application/pdf',
        );
    }
}
