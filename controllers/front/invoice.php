<?php
/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

require_once dirname(__FILE__) . '/../../classes/TwoInvoiceRetrievalService.php';

/**
 * Buyer-facing invoice PDF download.
 *
 * Fetches the invoice server-side (instead of linking the browser straight to
 * the API) so a 400 ORDER_NOT_FULFILLED can be turned into a proper notice via
 * the order-state check in TwoInvoiceRetrievalService.
 */
class TwopaymentInvoiceModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();
        $this->context = Context::getContext();
    }

    public function postProcess()
    {
        parent::postProcess();

        $id_order = (int)Tools::getValue('id_order');
        $order = new Order($id_order);
        $twopaymentdata = $id_order > 0 ? $this->module->getTwoOrderPaymentData($id_order) : false;

        if (!Validate::isLoadedObject($order) || !$twopaymentdata) {
            $this->errors[] = $this->module->l('Unable to find the requested order please contact store owner.');
            $this->redirectWithNotifications('index.php');
        }

        // Ownership guard shared with the cancel/confirmation callbacks:
        // secure-key `key` fallback (covers guest checkout) or logged-in
        // context customer matching the order owner. Timing-safe compares.
        $customer = new Customer((int)$order->id_customer);
        $authorized = $this->module->isTwoOrderCustomerAccessAuthorized(
            $order,
            $customer,
            trim((string)Tools::getValue('key')),
            isset($this->context->customer->id) ? (int)$this->context->customer->id : 0,
            isset($this->context->customer->secure_key) ? trim((string)$this->context->customer->secure_key) : ''
        );

        if (!$authorized) {
            PrestaShopLogger::addLog(
                'TwoPayment: Unauthorized invoice download attempt for order ' . $id_order,
                3
            );
            $this->errors[] = $this->module->l('You are not allowed to access this invoice.');
            $this->redirectWithNotifications('index.php');
        }

        $service = new TwoInvoiceRetrievalService($this->module);
        $result = $service->resolveInvoiceDownload($id_order, $twopaymentdata);

        if (isset($result['action']) && $result['action'] === 'stream') {
            $this->module->streamTwoInvoicePdf($result, (string)$order->reference);
        }

        if (isset($result['level']) && $result['level'] === 'error') {
            $this->errors[] = $result['message'];
        } else {
            $this->info[] = $result['message'];
        }

        $is_logged = isset($this->context->customer) &&
            is_object($this->context->customer) &&
            method_exists($this->context->customer, 'isLogged') &&
            $this->context->customer->isLogged();

        $this->redirectWithNotifications(
            $is_logged
                ? 'index.php?controller=order-detail&id_order=' . (int)$id_order
                : 'index.php?controller=guest-tracking'
        );
    }
}
