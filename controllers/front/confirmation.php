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

        $customer = new Customer((int)$attempt['id_customer']);
        if (!Validate::isLoadedObject($customer)) {
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
            $message = $this->module->l('Unable to load customer for this payment attempt.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        if ($this->abortConfirmationIfAttemptCancelled($attempt_token, $attempt)) {
            return;
        }

        $latest_attempt = $this->module->getTwoCheckoutAttempt($attempt_token);
        if (is_array($latest_attempt)) {
            $attempt = $latest_attempt;
            if ($this->abortConfirmationIfAttemptCancelled($attempt_token, $attempt)) {
                return;
            }
        }

        $existing_attempt_order_id = (int)$attempt['id_order'];
        if ($existing_attempt_order_id > 0) {
            $existing_order = new Order($existing_attempt_order_id);
            if (Validate::isLoadedObject($existing_order)) {
                $existing_payment_data = $this->module->getTwoOrderPaymentData($existing_order->id);
                if ($existing_payment_data && isset($existing_payment_data['two_order_id'])) {
                    $sync_ok = $this->syncTwoMerchantOrderId($existing_order, $existing_payment_data);
                    if ($sync_ok) {
                        $this->module->setTwoCheckoutAttemptMerchantOrderId($attempt_token, (string)$existing_order->id);
                    }
                }
                $latest_attempt = $this->module->getTwoCheckoutAttempt($attempt_token);
                if (is_array($latest_attempt)) {
                    $attempt = $latest_attempt;
                    if ($this->abortConfirmationIfAttemptCancelled($attempt_token, $attempt)) {
                        return;
                    }
                }
                $this->redirectToOrderConfirmation($existing_order, $customer);
            }
        }

        $cart = new Cart((int)$attempt['id_cart']);
        if (!Validate::isLoadedObject($cart)) {
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
            $message = $this->module->l('Unable to load cart for this payment attempt.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        // Ensure checkout context matches the attempt before creating a local order.
        $this->context->cart = $cart;
        $this->context->customer = $customer;
        $this->context->currency = new Currency((int)$cart->id_currency);
        $this->context->cookie->id_cart = (int)$cart->id;
        $this->context->cookie->id_customer = (int)$customer->id;
        $this->context->cookie->write();

        if (empty($attempt['two_order_id'])) {
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
            $message = $this->module->l('Missing provider order reference for this attempt.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        $stored_snapshot_hash = isset($attempt['cart_snapshot_hash']) ? trim((string)$attempt['cart_snapshot_hash']) : '';
        if (!Tools::isEmpty($stored_snapshot_hash)) {
            try {
                $current_snapshot_hash = $this->buildAttemptSnapshotHash($attempt_token, $attempt, $cart, true);
            } catch (Exception $e) {
                $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
                PrestaShopLogger::addLog(
                    'TwoPayment: Failed to build cart snapshot for attempt ' . $attempt_token . ' - ' . $e->getMessage(),
                    3
                );
                $message = $this->module->l('Unable to validate cart consistency for this payment. Please try again.');
                $this->errors[] = $message;
                $this->redirectWithNotifications('index.php?controller=order');
            }

            if (!hash_equals($stored_snapshot_hash, $current_snapshot_hash)) {
                // Compatibility fallback: allow old attempts created before callback key was added.
                try {
                    $legacy_snapshot_hash = $this->buildAttemptSnapshotHash($attempt_token, $attempt, $cart, false);
                } catch (Exception $e) {
                    $legacy_snapshot_hash = '';
                }

                if (!Tools::isEmpty($legacy_snapshot_hash) && hash_equals($stored_snapshot_hash, $legacy_snapshot_hash)) {
                    $current_snapshot_hash = $legacy_snapshot_hash;
                } else {
                    // Cart changed between provider order creation and callback finalization.
                    $this->module->setTwoPaymentRequest('/v1/order/' . $attempt['two_order_id'] . '/cancel', [], 'POST');
                    $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
                    PrestaShopLogger::addLog(
                        'TwoPayment: Cart snapshot mismatch for attempt ' . $attempt_token . '. Stored=' . $stored_snapshot_hash . ', Current=' . $current_snapshot_hash,
                        3
                    );
                    $message = $this->module->l('Your cart changed during payment verification. Please review your cart and try again.');
                    $this->errors[] = $message;
                    $this->redirectWithNotifications('index.php?controller=order');
                }
            }
        }

        $two_order_id = $attempt['two_order_id'];
        $response = $this->module->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
        $two_err = $this->module->getTwoErrorMessage($response);
        if ($two_err) {
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
            $message = ($two_err != '') ? $two_err : $this->module->l('Unable to retrieve the order payment information please contact store owner.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        $two_state = isset($response['state']) ? strtoupper((string)$response['state']) : '';
        $valid_states = array('VERIFIED', 'CONFIRMED', 'FULFILLED');
        if (!in_array($two_state, $valid_states, true)) {
            $extra_data = array(
                'two_order_state' => isset($response['state']) ? $response['state'] : '',
                'two_order_status' => isset($response['status']) ? $response['status'] : '',
            );

            $resolved_order_id = (int)$this->module->resolveTwoAttemptOrderIdForCancellation($attempt);
            if ($two_state === 'CANCELLED') {
                if ($resolved_order_id > 0) {
                    $extra_data['id_order'] = $resolved_order_id;
                }
                $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'CANCELLED', $extra_data);
                $this->module->syncLocalOrderStatusFromTwoState($resolved_order_id, $two_state);
                $message = $this->module->l('Your order is cancelled.');
            } else {
                $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED', $extra_data);
                $message = $this->module->l('Payment has not been verified yet. Please try again or contact support.');
            }

            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        $final_state = isset($response['state']) ? $response['state'] : 'VERIFIED';
        $final_status = isset($response['status']) ? $response['status'] : null;
        if ($two_state === 'VERIFIED') {
            $confirm_result = $this->module->confirmTwoOrder($two_order_id);
            if ($confirm_result['success']) {
                $final_state = $confirm_result['state'];
                $final_status = $confirm_result['status'] ?: $final_status;
            }
        }

        $provider_gross_amount = $this->module->extractTwoProviderGrossAmountForValidation($response);
        if ($provider_gross_amount === null) {
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
            PrestaShopLogger::addLog(
                'TwoPayment: Missing or invalid provider gross_amount in callback response for attempt ' . $attempt_token,
                3
            );
            $message = $this->module->l('Unable to retrieve the order payment information please contact store owner.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
            return;
        }

        // Re-read latest attempt status to close cancel/confirm race windows.
        $latest_attempt = $this->module->getTwoCheckoutAttempt($attempt_token);
        if (is_array($latest_attempt)) {
            $attempt = $latest_attempt;
            if ($this->abortConfirmationIfAttemptCancelled($attempt_token, $attempt)) {
                return;
            }
        }

        $id_order = (int)$attempt['id_order'];
        if ($id_order <= 0) {
            $id_order = (int)$this->module->getTwoOrderIdByCart((int)$cart->id);
        }

        if ($id_order > 0) {
            $existing_payment_data = $this->module->getTwoOrderPaymentData((int)$id_order);
            if ($this->module->hasTwoOrderRebindingConflict($existing_payment_data, (string)$attempt['two_order_id'])) {
                $existing_two_order_id = isset($existing_payment_data['two_order_id']) ? (string)$existing_payment_data['two_order_id'] : '';
                PrestaShopLogger::addLog(
                    'TwoPayment: Rebinding guard blocked callback for attempt ' . $attempt_token .
                    '. Existing order ' . (int)$id_order . ' already linked to Two order ' . $existing_two_order_id .
                    ', incoming Two order ' . (string)$attempt['two_order_id'],
                    3
                );

                // Best effort: cancel incoming duplicate provider order to avoid orphaned external state.
                if (!Tools::isEmpty($attempt['two_order_id'])) {
                    $this->module->setTwoPaymentRequest('/v1/order/' . $attempt['two_order_id'] . '/cancel', [], 'POST');
                }

                $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
                $existing_order = new Order((int)$id_order);
                if (Validate::isLoadedObject($existing_order)) {
                    $this->redirectToOrderConfirmation($existing_order, $customer);
                }

                $message = $this->module->l('This payment attempt has already been finalized.');
                $this->errors[] = $message;
                $this->redirectWithNotifications('index.php?controller=order');
            }
        }

        if ($id_order <= 0) {
            $latest_attempt = $this->module->getTwoCheckoutAttempt($attempt_token);
            if (is_array($latest_attempt)) {
                $attempt = $latest_attempt;
                if ($this->abortConfirmationIfAttemptCancelled($attempt_token, $attempt)) {
                    return;
                }
            }

            $initial_status = $this->getInitialAwaitingStatus();
            $create_result = $this->module->createTwoLocalOrderAfterProviderVerification(
                $cart,
                $customer,
                (int)$initial_status,
                (float)$provider_gross_amount
            );

            if (!(bool)$create_result['success']) {
                $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
                $error_code = isset($create_result['error']) ? (string)$create_result['error'] : '';
                if ($error_code === 'currency_invalid') {
                    $message = $this->module->l('Unable to load currency for this payment attempt.');
                } else {
                    $message = $this->module->l('Unable to create local order for this payment attempt.');
                }
                $this->errors[] = $message;
                $this->redirectWithNotifications('index.php?controller=order');
            }
            $id_order = isset($create_result['id_order']) ? (int)$create_result['id_order'] : 0;
            if ((bool)(isset($create_result['recovered_existing']) ? $create_result['recovered_existing'] : false)) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Recovered existing local order ' . $id_order .
                    ' for callback attempt ' . $attempt_token . ' after validateOrder race/duplicate.',
                    2
                );
            }
        }

        if ($id_order <= 0) {
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
            $message = $this->module->l('Unable to create local order for this payment attempt.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        $order = new Order((int)$id_order);
        if (!Validate::isLoadedObject($order)) {
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
            $message = $this->module->l('Unable to load the created order. Please contact support.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        $latest_attempt = $this->module->getTwoCheckoutAttempt($attempt_token);
        if (is_array($latest_attempt)) {
            $attempt = $latest_attempt;
            if ($this->abortConfirmationIfAttemptCancelled($attempt_token, $attempt)) {
                return;
            }
        }

        $invoice_id = isset($response['invoice_details']['id']) ? $response['invoice_details']['id'] : $attempt['two_invoice_id'];
        $invoice_url = isset($response['invoice_url']) ? $response['invoice_url'] : $attempt['two_invoice_url'];
        $resolved_terms = $this->module->resolveTwoPaymentTermsFromOrderResponse(
            $response,
            isset($attempt['two_day_on_invoice']) ? (string)$attempt['two_day_on_invoice'] : (string)$this->module->getSelectedPaymentTerm(),
            isset($attempt['two_payment_term_type']) ? $attempt['two_payment_term_type'] : Configuration::get('PS_TWO_PAYMENT_TERM_TYPE')
        );
        $payment_data = array(
            'two_order_id' => isset($response['id']) ? $response['id'] : $attempt['two_order_id'],
            'two_order_reference' => isset($response['merchant_reference']) ? $response['merchant_reference'] : $attempt['two_order_reference'],
            'two_order_state' => $final_state,
            'two_order_status' => $final_status,
            'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
            'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
            'two_invoice_url' => $invoice_url,
            'two_invoice_id' => $invoice_id,
        );
        $this->module->setTwoOrderPaymentData($order->id, $payment_data);

        // Best effort: replace provisional merchant_order_id with real PrestaShop id_order in Two.
        $sync_ok = $this->syncTwoMerchantOrderId($order, $payment_data);
        if ($sync_ok) {
            $this->module->setTwoCheckoutAttemptMerchantOrderId($attempt_token, (string)$order->id);
        }

        $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'CONFIRMED', array(
            'id_order' => (int)$order->id,
            'two_order_state' => $payment_data['two_order_state'],
            'two_order_status' => $payment_data['two_order_status'],
            'two_day_on_invoice' => $payment_data['two_day_on_invoice'],
            'two_payment_term_type' => $payment_data['two_payment_term_type'],
            'two_invoice_url' => $payment_data['two_invoice_url'],
            'two_invoice_id' => $payment_data['two_invoice_id'],
        ));

        $latest_attempt = $this->module->getTwoCheckoutAttempt($attempt_token);
        if (is_array($latest_attempt)) {
            $attempt = $latest_attempt;
            if ($this->abortConfirmationIfAttemptCancelled($attempt_token, $attempt)) {
                return;
            }
        }

        $this->module->changeOrderStatus($order->id, $this->getVerifiedStatus());
        $this->redirectToOrderConfirmation($order, $customer);
    }

    /**
     * Best effort sync of merchant_order_id in Two to the real PrestaShop order ID.
     * Never blocks customer confirmation if provider update fails.
     */
    private function syncTwoMerchantOrderId($order, $payment_data)
    {
        if (!Validate::isLoadedObject($order) || !is_array($payment_data) || empty($payment_data['two_order_id'])) {
            return false;
        }

        try {
            $update_payload = $this->module->getTwoUpdateOrderData($order, $payment_data);
            $update_payload['merchant_order_id'] = (string)$order->id;

            $update_response = $this->module->setTwoPaymentRequest(
                '/v1/order/' . $payment_data['two_order_id'],
                $update_payload,
                'PUT'
            );

            $http_status = isset($update_response['http_status']) ? (int)$update_response['http_status'] : 0;
            if ($http_status === Twopayment::HTTP_STATUS_OK) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Synced merchant_order_id=' . $order->id . ' to Two order ' . $payment_data['two_order_id'],
                    1,
                    null,
                    'Order',
                    $order->id
                );
                return true;
            }

            $sync_error = $this->module->getTwoErrorMessage($update_response);
            PrestaShopLogger::addLog(
                'TwoPayment: Failed to sync merchant_order_id for order ' . $order->id .
                ', Two order ' . $payment_data['two_order_id'] .
                ', HTTP ' . $http_status .
                ($sync_error ? ', Error: ' . $sync_error : ''),
                2,
                null,
                'Order',
                $order->id
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'TwoPayment: Exception syncing merchant_order_id for order ' . $order->id .
                ' - ' . $e->getMessage(),
                2,
                null,
                'Order',
                $order->id
            );
        }

        return false;
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
     * Rebuild snapshot hash using current cart and expected callback URL format.
     */
    private function buildAttemptSnapshotHash($attempt_token, $attempt, $cart, $include_secure_key)
    {
        $confirm_params = array('attempt_token' => $attempt_token);
        $cancel_params = array('attempt_token' => $attempt_token);
        if ($include_secure_key && !Tools::isEmpty($attempt['customer_secure_key'])) {
            $confirm_params['key'] = $attempt['customer_secure_key'];
            $cancel_params['key'] = $attempt['customer_secure_key'];
        }

        $comparison_urls = array(
            'merchant_confirmation_url' => $this->context->link->getModuleLink($this->module->name, 'confirmation', $confirm_params, true),
            'merchant_cancel_order_url' => $this->context->link->getModuleLink($this->module->name, 'cancel', $cancel_params, true),
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        );
        $comparison_payload = $this->module->getTwoNewOrderData($attempt['merchant_order_id'], $cart, $comparison_urls);

        return $this->module->calculateTwoCheckoutSnapshotHash($cart, $comparison_payload);
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
                $message = ($two_err != '') ? $two_err : $this->module->l('Unable to retrieve the order payment information please contact store owner.');
                $this->errors[] = $message;
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
                // Order is verified, now confirm it to move to CONFIRMED state
                $confirm_result = $this->module->confirmTwoOrder($two_order_id);
                
                // Use the confirmation result or fallback to original state
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

    private function getInitialAwaitingStatus()
    {
        $initial_status = Configuration::get('PS_TWO_OS_AWAITING_VERIFICATION');
        if (!$initial_status) {
            $initial_status = Configuration::get('PS_TWO_OS_AWAITING_VERIFICATION_MAP');
            if (!$initial_status) {
                $initial_status = Configuration::get('PS_OS_PREPARATION');
            }
        }
        return (int)$initial_status;
    }

    private function getVerifiedStatus()
    {
        $verified_status = Configuration::get('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT');
        if (!$verified_status) {
            $verified_status = Configuration::get('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP');
            if (!$verified_status) {
                $verified_status = Configuration::get('PS_OS_PREPARATION');
            }
        }
        return (int)$verified_status;
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
        $attempt_status = isset($attempt['status']) ? (string)$attempt['status'] : '';
        if (!$this->module->shouldBlockTwoAttemptConfirmationByStatus($attempt_status)) {
            return false;
        }

        $resolved_order_id = (int)$this->module->resolveTwoAttemptOrderIdForCancellation($attempt);
        if ($resolved_order_id > 0) {
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'CANCELLED', array(
                'id_order' => $resolved_order_id,
            ));
            $this->module->syncLocalOrderStatusFromTwoState($resolved_order_id, 'CANCELLED');
        }

        if (!empty($attempt['two_order_id'])) {
            $this->module->cancelTwoOrderBestEffort((string)$attempt['two_order_id'], 'confirmation_after_cancelled_attempt');
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
