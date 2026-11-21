<?php
/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

class TwopaymentConfirmationModuleFrontController extends ModuleFrontController
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
            $this->handleAttemptTokenConfirmation($attempt_token);
            return;
        }

        $id_order = (int)Tools::getValue('id_order');
        if ($id_order > 0) {
            $this->handleLegacyOrderConfirmation($id_order);
            return;
        }

        $message = $this->module->l('Unable to find the requested order please contact store owner.');
        $this->errors[] = $message;
        $this->redirectWithNotifications('index.php?controller=order&step=1');
    }

    private function handleAttemptTokenConfirmation($attempt_token)
    {
        $attempt = $this->module->getTwoCheckoutAttempt($attempt_token);
        if (!$attempt) {
            $message = $this->module->l('Unable to find the requested payment attempt. Please try checkout again.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        if (!$this->isAuthorizedAttemptCallback($attempt)) {
            PrestaShopLogger::addLog(
                'TwoPayment: Unauthorized confirmation callback for attempt ' . $attempt_token,
                3
            );
            $message = $this->module->l('Unable to validate this payment callback. Please retry checkout.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        // The scheduled check and the admin button settle attempts through the same resolver, under
        // the same per-attempt lock (TWO-26300).
        $result = $this->module->getTwoPendingRecovery()->resolveAttempt($attempt_token, TwoPendingRecovery::TRIGGER_BUYER_RETURN);
        if ($result['outcome'] === TwoPendingRecovery::OUTCOME_BUSY) {
            $this->redirectAfterBusyCheck($attempt_token);
        }
        if ($result['outcome'] === TwoPendingRecovery::OUTCOME_PLACED || $result['outcome'] === TwoPendingRecovery::OUTCOME_ALREADY_PLACED) {
            $order = new Order((int) $result['id_order']);
            if (Validate::isLoadedObject($order)) {
                $this->redirectToOrderConfirmation($order, new Customer((int) $order->id_customer));
            }
        }

        $this->errors[] = $result['message'] !== ''
            ? $result['message']
            : $this->module->l('Unable to create local order for this payment attempt.');
        $this->redirectWithNotifications('index.php?controller=order');
    }

    /**
     * Another check holds this attempt and did not finish within the wait. A placed order goes to
     * its confirmation page, a cancelled one says so, and anything else asks the buyer to reload:
     * the buyer is never shown a success page for an order that is not placed.
     *
     * @param string $attempt_token
     * @return void
     */
    private function redirectAfterBusyCheck($attempt_token)
    {
        $attempt = $this->module->getTwoCheckoutAttempt($attempt_token);
        $status = is_array($attempt) && isset($attempt['status']) ? strtoupper((string) $attempt['status']) : '';
        $id_order = is_array($attempt) && isset($attempt['id_order']) ? (int) $attempt['id_order'] : 0;
        if ($status === 'CONFIRMED' && $id_order > 0) {
            $order = new Order($id_order);
            if (Validate::isLoadedObject($order)) {
                $this->redirectToOrderConfirmation($order, new Customer((int) $order->id_customer));
            }
        }
        $this->errors[] = $status === 'CANCELLED'
            ? $this->module->l('Your order is cancelled.')
            : $this->module->l('Your payment is still being processed. Please reload this page in a moment.');
        $this->redirectWithNotifications('index.php?controller=order');
    }

    /**
     * Two's reason for a failed order lookup, for the merchant (TWO-26611).
     *
     * @param string $reference
     * @param mixed $response
     * @param int $id_cart
     * @return void
     */
    private function logTwoOrderLookupFailure($reference, $response, $id_cart)
    {
        $this->module->getTwoPendingRecovery()->logTwoOrderLookupFailure($reference, $response, $id_cart);
    }

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

    private function handleLegacyOrderConfirmation($id_order)
    {
        $order = new Order((int)$id_order);
        if (!Validate::isLoadedObject($order)) {
            $message = $this->module->l('Unable to find the requested order please contact store owner.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order&step=1');
        }

        $customer = new Customer($order->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $message = $this->module->l('Unable to load order customer.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        if (!$this->isAuthorizedLegacyOrderAccess($order, $customer)) {
            PrestaShopLogger::addLog(
                'TwoPayment: Unauthorized legacy confirmation callback for order ' . (int)$order->id,
                3
            );
            $message = $this->module->l('Unable to validate this payment callback. Please retry checkout.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        $orderpaymentdata = $this->module->getTwoOrderPaymentData($id_order);
        if ($orderpaymentdata && isset($orderpaymentdata['two_order_id'])) {
            $two_order_id = $orderpaymentdata['two_order_id'];
            
            $response = $this->module->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
            $two_err = $this->module->getTwoErrorMessage($response);
            if ($two_err) {
                $this->module->restoreDuplicateCart($order->id, $customer->id);
                $this->module->changeOrderStatus($order->id, Configuration::get('PS_TWO_OS_PAYMENT_ERROR_MAP'));
                // Two's reason is for the merchant, never the buyer (TWO-26611).
                $this->logTwoOrderLookupFailure(
                    'cart ' . (int) $order->id_cart . ', order ' . (int) $order->id . ', Two order ' . $two_order_id,
                    $response,
                    (int) $order->id_cart
                );
                $this->errors[] = $this->module->l('Unable to retrieve the order payment information please contact store owner.');
                $this->redirectWithNotifications('index.php?controller=order');
            }

            $two_state = isset($response['state']) ? strtoupper(trim((string)$response['state'])) : '';
            if ($two_state === 'CANCELLED') {
                $this->module->syncLocalOrderStatusFromTwoState((int)$order->id, 'CANCELLED');
                $message = $this->module->l('Your order is cancelled.');
                $this->errors[] = $message;
                $this->redirectWithNotifications('index.php?controller=order');
            }

            if ($two_state === 'VERIFIED') {
                $confirm_result = $this->module->confirmTwoOrder($two_order_id, 'legacy_confirmation', null, $order);
                $final_state = $confirm_result['success'] ? $confirm_result['state'] : $response['state'];
                $final_status = ($confirm_result['success'] && $confirm_result['status']) ? $confirm_result['status'] : $response['status'];
                $resolved_terms = $this->module->resolveTwoPaymentTermsFromOrderResponse(
                    $response,
                    (string)$this->module->getSelectedPaymentTerm(),
                    Configuration::get('PS_TWO_PAYMENT_TERM_TYPE')
                );
                
                $payment_data = array(
                    'two_order_id' => $response['id'],
                    'two_order_reference' => $response['merchant_reference'],
                    'two_order_state' => $final_state,
                    'two_order_status' => $final_status,
                    'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
                    'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
                    'two_invoice_url' => $response['invoice_url'],
                );
                // No company snapshot on this path, deliberately (TWO-40). The
                // legacy callback confirms an order that ALREADY EXISTS, so the
                // cart it came from has rotated and the cart-scoped record is
                // gone; resolving here would yield an empty organisation number,
                // and because the keys are presence-conditional, sending them
                // empty would be strictly worse than omitting them - it would
                // pin a blank over whatever is stored.
                $this->module->setTwoOrderPaymentData($order->id, $payment_data);
            }
        }

        $this->module->changeOrderStatus($order->id, $this->getVerifiedStatus());
        $this->redirectToOrderConfirmation($order, $customer);
    }

    /**
     * Validate legacy callback authorization for order-based confirmation paths.
     *
     * @param Order $order
     * @param Customer $customer
     * @return bool
     */
    private function isAuthorizedLegacyOrderAccess($order, $customer)
    {
        return $this->module->isTwoOrderCustomerAccessAuthorized(
            $order,
            $customer,
            trim((string)Tools::getValue('key')),
            isset($this->context->customer->id) ? (int)$this->context->customer->id : 0,
            isset($this->context->customer->secure_key) ? trim((string)$this->context->customer->secure_key) : ''
        );
    }

    private function getVerifiedStatus()
    {
        return (int) $this->module->getTwoVerifiedStatusIdForConfirmation();
    }

    /**
     * Stop confirmation flow when attempt is already cancelled.
     *
     * @param string $attempt_token
     * @param array $attempt
     * @return bool True when flow was aborted
     */
    private function abortConfirmationIfAttemptCancelled($attempt_token, $attempt)
    {
        if (!$this->module->getTwoPendingRecovery()->abortIfCancelled($attempt_token, $attempt)) {
            return false;
        }

        $message = $this->module->l('Your order is cancelled.');
        $this->errors[] = $message;
        $this->redirectWithNotifications('index.php?controller=order');

        return true;
    }

    private function redirectToOrderConfirmation($order, $customer)
    {
        Tools::redirect(
            'index.php?controller=order-confirmation&id_cart=' . (int)$order->id_cart .
            '&id_module=' . (int)$this->module->id .
            '&id_order=' . (int)$order->id .
            '&key=' . $customer->secure_key
        );
    }

}
