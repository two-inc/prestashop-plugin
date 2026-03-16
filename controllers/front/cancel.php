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

        $attempt_order_id = (int)$this->module->resolveTwoAttemptOrderIdForCancellation($attempt);
        if ($attempt_order_id > 0) {
            // Safety: if the attempt is already linked to a local order, reuse legacy cancellation behavior.
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'CANCELLED', array(
                'id_order' => $attempt_order_id,
            ));
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
                $resolved_terms = $this->module->resolveTwoPaymentTermsFromOrderResponse(
                    $response,
                    isset($attempt['two_day_on_invoice']) ? (string)$attempt['two_day_on_invoice'] : (string)$this->module->getSelectedPaymentTerm(),
                    isset($attempt['two_payment_term_type']) ? $attempt['two_payment_term_type'] : Configuration::get('PS_TWO_PAYMENT_TERM_TYPE')
                );
                $extra_data['two_order_state'] = isset($response['state']) ? $response['state'] : '';
                $extra_data['two_order_status'] = isset($response['status']) ? $response['status'] : '';
                $extra_data['two_day_on_invoice'] = $resolved_terms['two_day_on_invoice'];
                $extra_data['two_payment_term_type'] = $resolved_terms['two_payment_term_type'];
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

        $customer = new Customer((int)$order->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $message = $this->module->l('Unable to load order customer.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        if (!$this->isAuthorizedLegacyOrderAccess($order, $customer)) {
            PrestaShopLogger::addLog(
                'TwoPayment: Unauthorized legacy cancel callback for order ' . (int)$order->id,
                3
            );
            $message = $this->module->l('Unable to validate this cancellation callback. Please retry checkout.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        $orderpaymentdata = $this->module->getTwoOrderPaymentData($id_order);
        if (!$this->module->hasTwoProviderOrderMapping($orderpaymentdata)) {
            PrestaShopLogger::addLog(
                'TwoPayment: Legacy cancel callback missing Two order mapping for order ' . (int)$order->id .
                '. Local cancellation aborted to preserve provider-first consistency.',
                3
            );
            $message = sprintf($this->module->l('Could not update status to cancelled, please check with Two admin for id %s'), (string)$order->id);
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        $two_order_id = (string)$orderpaymentdata['two_order_id'];

        $cancel_response = $this->module->setTwoPaymentRequest('/v1/order/' . $two_order_id . '/cancel', [], 'POST');
        $cancel_http_status = isset($cancel_response['http_status']) ? (int)$cancel_response['http_status'] : 0;

        $response = $this->module->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
        $response_http_status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
        $provider_cancelled = $this->module->isTwoOrderCancelledResponse($response, $response_http_status);

        if ($provider_cancelled) {
            $resolved_terms = $this->module->resolveTwoPaymentTermsFromOrderResponse(
                $response,
                isset($orderpaymentdata['two_day_on_invoice']) ? (string)$orderpaymentdata['two_day_on_invoice'] : (string)$this->module->getSelectedPaymentTerm(),
                isset($orderpaymentdata['two_payment_term_type']) ? $orderpaymentdata['two_payment_term_type'] : Configuration::get('PS_TWO_PAYMENT_TERM_TYPE')
            );
            $payment_data = array(
                'two_order_id' => $response['id'],
                'two_order_reference' => $response['merchant_reference'],
                'two_order_state' => $response['state'],
                'two_order_status' => $response['status'],
                'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
                'two_invoice_url' => $response['invoice_url'],
                'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
            );
            $this->module->setTwoOrderPaymentData($order->id, $payment_data);
        } else {
            PrestaShopLogger::addLog(
                'TwoPayment: Legacy cancel callback could not confirm CANCELLED provider state for order ' . (int)$order->id .
                ', Two order ' . $two_order_id .
                ', cancel_http=' . $cancel_http_status .
                ', fetch_http=' . $response_http_status .
                ', provider_state=' . (isset($response['state']) ? (string)$response['state'] : 'unknown'),
                2
            );
            $message = sprintf($this->module->l('Could not update status to cancelled, please check with Two admin for id %s'), $two_order_id);
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        $this->module->restoreDuplicateCart($order->id, $order->id_customer);
        $this->module->changeOrderStatus($order->id, $this->getCancelledStatus());

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

    /**
     * Validate legacy callback authorization for order-based cancellation paths.
     *
     * @param Order $order
     * @param Customer $customer
     * @return bool
     */
    private function isAuthorizedLegacyOrderAccess($order, $customer)
    {
        if (!Validate::isLoadedObject($order) || !Validate::isLoadedObject($customer)) {
            return false;
        }

        $expected_secure_key = trim((string)$customer->secure_key);
        if (Tools::isEmpty($expected_secure_key)) {
            return false;
        }

        $provided_key = trim((string)Tools::getValue('key'));
        if (!Tools::isEmpty($provided_key)) {
            return hash_equals($expected_secure_key, $provided_key);
        }

        $context_customer_id = isset($this->context->customer->id) ? (int)$this->context->customer->id : 0;
        $context_customer_secure_key = isset($this->context->customer->secure_key) ? trim((string)$this->context->customer->secure_key) : '';

        return $context_customer_id === (int)$order->id_customer &&
            !Tools::isEmpty($context_customer_secure_key) &&
            hash_equals($expected_secure_key, $context_customer_secure_key);
    }

}
