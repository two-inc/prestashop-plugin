<?php
/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

class TwopaymentCancelModuleFrontController extends ModuleFrontController
{

    public function __construct()
    {
        parent::__construct();
        $this->context = Context::getContext();
    }

    public function postProcess()
    {
        parent::postProcess();

        $attempt_token = trim((string)Tools::getValue('attempt_token'));
        if (!Tools::isEmpty($attempt_token)) {
            $this->handleAttemptCancel($attempt_token);
            return;
        }

        $id_order = (int)Tools::getValue('id_order');
        if ($id_order > 0) {
            $this->handleLegacyOrderCancel($id_order);
            return;
        }

        $message = $this->module->l('Unable to find the requested order please contact store owner.');
        $this->errors[] = $message;
        $this->redirectWithNotifications('index.php?controller=order');
    }

    private function handleAttemptCancel($attempt_token)
    {
        $attempt = $this->module->getTwoCheckoutAttempt($attempt_token);
        if (!$attempt) {
            $message = $this->module->l('Unable to find the requested payment attempt.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        if (!$this->isAuthorizedAttemptCallback($attempt)) {
            PrestaShopLogger::addLog(
                'TwoPayment: Unauthorized cancel callback for attempt ' . $attempt_token,
                3
            );
            $message = $this->module->l('Unable to validate this cancellation callback. Please retry checkout.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        $attempt_order_id = (int)$attempt['id_order'];
        if ($attempt_order_id > 0) {
            // Safety: if the attempt is already linked to a local order, reuse legacy cancellation behavior.
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'CANCELLED');
            $this->handleLegacyOrderCancel($attempt_order_id);
            return;
        }

        $extra_data = array();
        if (!empty($attempt['two_order_id'])) {
            $two_order_id = $attempt['two_order_id'];
            $cancel_response = $this->module->setTwoPaymentRequest('/v1/order/' . $two_order_id . '/cancel', [], 'POST');
            $cancel_http_status = isset($cancel_response['http_status']) ? (int)$cancel_response['http_status'] : 0;
            if ($cancel_http_status >= Twopayment::HTTP_STATUS_BAD_REQUEST) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Attempt cancel failed for token ' . $attempt_token . ', Two order ' . $two_order_id .
                    ', HTTP ' . $cancel_http_status,
                    2
                );
            }

            $response = $this->module->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
            $response_http_status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
            if ($response_http_status > 0 && $response_http_status < Twopayment::HTTP_STATUS_BAD_REQUEST && is_array($response)) {
                $extra_data['two_order_state'] = isset($response['state']) ? $response['state'] : '';
                $extra_data['two_order_status'] = isset($response['status']) ? $response['status'] : '';
                if (isset($response['invoice_url'])) {
                    $extra_data['two_invoice_url'] = $response['invoice_url'];
                }
                if (isset($response['invoice_details']['id'])) {
                    $extra_data['two_invoice_id'] = $response['invoice_details']['id'];
                }
            }
        }

        $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'CANCELLED', $extra_data);

        // Keep the cart active for retry after cancellation.
        $cart = new Cart((int)$attempt['id_cart']);
        if (Validate::isLoadedObject($cart)) {
            $this->context->cart = $cart;
            $this->context->cookie->id_cart = (int)$cart->id;
            $this->context->cookie->write();
        }

        $message = $this->module->l('Your order is cancelled.');
        $this->errors[] = $message;
        $this->redirectWithNotifications('index.php?controller=order');
    }

    private function handleLegacyOrderCancel($id_order)
    {
        $order = new Order((int)$id_order);
        if (!Validate::isLoadedObject($order)) {
            $message = $this->module->l('Unable to find the requested order please contact store owner.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        $this->module->restoreDuplicateCart($order->id, $order->id_customer);
        $this->module->changeOrderStatus($order->id, $this->getCancelledStatus());

        $orderpaymentdata = $this->module->getTwoOrderPaymentData($id_order);
        if ($orderpaymentdata && isset($orderpaymentdata['two_order_id'])) {
            $two_order_id = $orderpaymentdata['two_order_id'];
            
            $response = $this->module->setTwoPaymentRequest('/v1/order/' . $two_order_id . '/cancel', [], 'POST');
            $cancel_http_status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
            if ($cancel_http_status === 0 || $cancel_http_status >= Twopayment::HTTP_STATUS_BAD_REQUEST) {
                $message = sprintf($this->module->l('Could not update status to cancelled, please check with Two admin for id %s'), $two_order_id);
                $this->errors[] = $message;
                $this->redirectWithNotifications('index.php?controller=order');
            }

            $response = $this->module->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
            $response_http_status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
            if ($response_http_status > 0 && $response_http_status < Twopayment::HTTP_STATUS_BAD_REQUEST && isset($response['state']) && $response['state'] == 'CANCELLED') {
                $payment_data = array(
                    'two_order_id' => $response['id'],
                    'two_order_reference' => $response['merchant_reference'],
                    'two_order_state' => $response['state'],
                    'two_order_status' => $response['status'],
                    'two_day_on_invoice' => (string)$this->module->getSelectedPaymentTerm(), // Selected payment term
                    'two_invoice_url' => $response['invoice_url'],
                    'two_payment_term_type' => isset($orderpaymentdata['two_payment_term_type']) ? $orderpaymentdata['two_payment_term_type'] : Configuration::get('PS_TWO_PAYMENT_TERM_TYPE'),
                );
                $this->module->setTwoOrderPaymentData($order->id, $payment_data);
            }
        }

        $message = $this->module->l('Your order is cancelled.');
        $this->errors[] = $message;
        $this->redirectWithNotifications('index.php?controller=order');
    }

    private function getCancelledStatus()
    {
        $cancelled_status = Configuration::get('PS_TWO_OS_CANCELLED');
        if (!$cancelled_status) {
            $cancelled_status = Configuration::get('PS_TWO_OS_CANCELLED_MAP');
            if (!$cancelled_status) {
                $cancelled_status = Configuration::get('PS_OS_CANCELED');
            }
        }
        return (int)$cancelled_status;
    }

    /**
     * Validate callback authorization against stored attempt secure key.
     */
    private function isAuthorizedAttemptCallback($attempt)
    {
        $provided_key = trim((string)Tools::getValue('key'));
        $context_customer_id = (isset($this->context->customer->id)) ? (int)$this->context->customer->id : 0;
        $context_customer_secure_key = (isset($this->context->customer->secure_key)) ? (string)$this->context->customer->secure_key : '';

        return $this->module->isTwoAttemptCallbackAuthorized(
            $attempt,
            $provided_key,
            $context_customer_id,
            $context_customer_secure_key
        );
    }

}
