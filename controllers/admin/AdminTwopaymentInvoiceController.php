<?php
/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/../../classes/TwoInvoiceRetrievalService.php';

/**
 * Admin invoice PDF download, registered through an invisible tab so
 * PrestaShop enforces employee authentication, CSRF token, and profile
 * permissions (same access as the admin order view exposes today).
 *
 * Streams the PDF on success; otherwise redirects back to the order view
 * with a whitelisted notice code rendered by the admin order hooks.
 */
class AdminTwopaymentInvoiceController extends ModuleAdminController
{
    public function postProcess()
    {
        $id_order = (int)Tools::getValue('id_order');
        $twopaymentdata = $id_order > 0 ? $this->module->getTwoOrderPaymentData($id_order) : false;

        if (!$twopaymentdata) {
            $this->redirectToOrderView($id_order, TwoInvoiceRetrievalService::NOTICE_NO_REFERENCE, '');
        }

        $service = new TwoInvoiceRetrievalService($this->module);
        $result = $service->resolveInvoiceDownload($id_order, $twopaymentdata);

        if (isset($result['action']) && $result['action'] === 'stream') {
            $order = new Order($id_order);
            $reference = Validate::isLoadedObject($order) ? (string)$order->reference : (string)$id_order;
            $this->module->streamTwoInvoicePdf($result, $reference);
        }

        $this->redirectToOrderView(
            $id_order,
            isset($result['code']) ? (string)$result['code'] : TwoInvoiceRetrievalService::NOTICE_ERROR,
            isset($result['state']) ? (string)$result['state'] : ''
        );
    }

    /**
     * Redirect back to the admin order view carrying only a whitelisted notice
     * code (never free text) - the admin order hooks map it back to a message.
     *
     * @param int $id_order
     * @param string $code TwoInvoiceRetrievalService::NOTICE_* code
     * @param string $state Two order state for the unavailable-state notice
     * @return void
     */
    private function redirectToOrderView($id_order, $code, $state)
    {
        $url = $this->context->link->getAdminLink(
            'AdminOrders',
            true,
            array('route' => 'admin_orders_view', 'orderId' => (int)$id_order),
            array('id_order' => (int)$id_order, 'vieworder' => 1)
        );

        $notice_params = array('two_invoice_notice' => (string)$code);
        if ($state !== '') {
            $notice_params['two_invoice_state'] = $state;
        }

        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($notice_params);

        Tools::redirectAdmin($url);
    }
}
