<?php
/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

class TwopaymentPaymentModuleFrontController extends ModuleFrontController
{

    public function __construct()
    {
        parent::__construct();
        $this->context = Context::getContext();
    }

    public function postProcess()
    {
        parent::postProcess();
        // Guard: Require company details when placing order with Two

        //We check if the cart exists; if it doesn’t, we get it from the context
        if (isset($cart) && !empty($cart)) {
            $address = new Address($cart->id_address_invoice);    
        } else {
            $address = new Address(Context::getContext()->cart->id_address_invoice);    
        }

        $companyName = isset($address->company) ? trim($address->company) : '';
        $companyId = '';
        // Prefer companyid if present; fallback for ES to dni
        if (!empty($address->companyid)) {
            $companyId = trim($address->companyid);
        } else {
            $iso = Country::getIsoById($address->id_country);
            if ($iso === 'ES' && !empty($address->dni)) {
                $companyId = trim($address->dni);
            }
        }
        // Fallback to cookie values saved during company selection (handles GB and others)
        if (Tools::isEmpty($companyName) && isset($this->context->cookie->two_company_name)) {
            $companyName = trim($this->context->cookie->two_company_name);
        }
        if (Tools::isEmpty($companyId) && isset($this->context->cookie->two_company_id)) {
            $companyId = trim($this->context->cookie->two_company_id);
        }

        if (Tools::isEmpty($companyName) || Tools::isEmpty($companyId)) {
            $msg = $this->module->l('To pay with Two, please select your company so we can verify your business and offer invoice terms.');
            $this->errors[] = $msg;
            $this->redirectWithNotifications('index.php?controller=order');
        }


        $cart = $this->context->cart;
        $currency = new Currency($cart->id_currency);

        // Enhanced cart validation with detailed logging
        if (!Validate::isLoadedObject($cart)) {
            PrestaShopLogger::addLog('TwoPayment: Invalid cart object in payment controller', 2);
            Tools::redirect('index.php?controller=order');
        }

        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0) {
            PrestaShopLogger::addLog('TwoPayment: Incomplete cart data - Customer: ' . $cart->id_customer . ', Delivery: ' . $cart->id_address_delivery . ', Invoice: ' . $cart->id_address_invoice, 2);
            Tools::redirect('index.php?controller=order');
        }

        if (!$this->module->active) {
            PrestaShopLogger::addLog('TwoPayment: Payment attempt on inactive module', 2);
            Tools::redirect('index.php?controller=order');
        }

        // Validate currency
        if (!Validate::isLoadedObject($currency)) {
            PrestaShopLogger::addLog('TwoPayment: Invalid currency for cart ' . $cart->id, 2);
            Tools::redirect('index.php?controller=order');
        }

        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'twopayment') {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            $message = $this->module->l('This payment method is not available.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $message = $this->module->l('Customer is not valid.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        //  Validate order intent approval if enabled (server-side security layer)
        if (Configuration::get('PS_TWO_ENABLE_ORDER_INTENT')) {
            $orderIntentApproved = isset($this->context->cookie->two_order_intent_approved) 
                ? $this->context->cookie->two_order_intent_approved === '1' 
                : null;
            
            $orderIntentTimestamp = isset($this->context->cookie->two_order_intent_timestamp) 
                ? (int)$this->context->cookie->two_order_intent_timestamp 
                : 0;
            
            // Check if order intent was checked
            if ($orderIntentApproved === null) {
                // Order intent was never checked - log but allow (may be disabled or skipped)
                PrestaShopLogger::addLog(
                    'TwoPayment: Order placed without order intent check (may be disabled or skipped) - Cart ID: ' . $cart->id,
                    2
                );
            } elseif ($orderIntentApproved === false) {
                // Order intent was checked and DECLINED - BLOCK ORDER
                PrestaShopLogger::addLog(
                    'TwoPayment: Order BLOCKED - order intent was declined. Cart ID: ' . $cart->id . ', Customer ID: ' . $customer->id,
                    3
                );
                
                $message = $this->module->l('Your order could not be approved by Two payment. Please choose another payment method or contact support.');
                $this->errors[] = $message;
                $this->redirectWithNotifications('index.php?controller=order');
                return; // Stop execution - prevent order creation
            } elseif ($orderIntentTimestamp > 0) {
                // Check if result is recent (within configured expiry time)
                $age = time() - $orderIntentTimestamp;
                if ($age > $this->module::ORDER_INTENT_EXPIRY_SECONDS) {
                    PrestaShopLogger::addLog(
                        'TwoPayment: Order intent result expired (age: ' . $age . ' seconds). Requiring re-check. Cart ID: ' . $cart->id,
                        2
                    );
                    
                    // Block order - require fresh order intent check
                    $message = $this->module->l('Your payment approval has expired. Please refresh the page and try again.');
                    $this->errors[] = $message;
                    $this->redirectWithNotifications('index.php?controller=order');
                    return; // Stop execution - prevent order creation
                }
            }
        }

        // CRITICAL: Create PrestaShop order FIRST to generate order ID for Two's callback URLs
        // If Two rejects (non-201), we'll DELETE the order entirely (no phantom orders)
        $initial_status = Configuration::get('PS_TWO_OS_AWAITING_VERIFICATION');
        if (!$initial_status) {
            // Fallback to mapped state if custom state doesn't exist (for existing installations)
            $initial_status = Configuration::get('PS_TWO_OS_AWAITING_VERIFICATION_MAP');
            if (!$initial_status) {
                // Final fallback to preparation state
                $initial_status = Configuration::get('PS_OS_PREPARATION');
            }
        }
        
        $this->module->validateOrder($cart->id, $initial_status, $cart->getOrderTotal(true, Cart::BOTH), $this->module->displayName, null, array(), (int) $currency->id, false, $customer->secure_key);
        $created_order_id = $this->module->currentOrder;
        
        PrestaShopLogger::addLog('TwoPayment: PrestaShop order created (ID: ' . $created_order_id . '), now calling Two API to create Two order', 1);

        // Build Two order payload with PrestaShop order ID for callbacks
        $paymentdata = $this->module->getTwoNewOrderData($created_order_id, $cart);

        // Call Two API to create order
        $response = $this->module->setTwoPaymentRequest('/v1/order', $paymentdata, 'POST');
        
        // Extract HTTP status code from enhanced response structure
        $http_status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
        
        PrestaShopLogger::addLog('TwoPayment: Two API response - HTTP ' . $http_status . ' - Body: ' . json_encode($response), ($http_status === Twopayment::HTTP_STATUS_CREATED ? 1 : 3));

        // CRITICAL CHECK: Only proceed if Two returned 201 Created
        // Any other status = order creation failed, delete PrestaShop order
        if ($http_status !== Twopayment::HTTP_STATUS_CREATED) {
            // Two rejected the order - DELETE PrestaShop order completely (no phantom orders)
            PrestaShopLogger::addLog('TwoPayment: Two API did not return 201 (got ' . $http_status . ') - DELETING PrestaShop order ' . $created_order_id, 3);
            
            // Delete order from database
            $this->module->deleteOrder($created_order_id);
            
            // Restore cart so customer can try again
            $this->module->restoreDuplicateCart($created_order_id, $customer->id);
            
            // Determine user-friendly error message based on response
            $message = $this->module->l('Unable to process your order with Two payment.');
            
            if (!isset($response) || $http_status === 0) {
                $message = $this->module->l('Connection error with payment provider. Please try again.');
            } elseif ($http_status === 401 || $http_status === 403) {
                $message = $this->module->l('Payment method configuration error. Please contact the store.');
            } elseif ($http_status === 400) {
                // Try to extract specific error from Two's response
                $two_err = $this->module->getTwoErrorMessage($response);
                if ($two_err) {
                    $message = $two_err;
                } else {
                    $message = $this->module->l('Invalid order data. Please check your details and try again.');
                }
            } elseif ($http_status >= Twopayment::HTTP_STATUS_SERVER_ERROR) {
                $message = $this->module->l('Payment provider temporarily unavailable. Please try again later.');
            }
            
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        if (isset($response['id']) && $response['id']) {
            // Extract invoice ID from response if available
            $invoice_id = isset($response['invoice_details']['id']) ? $response['invoice_details']['id'] : null;
            
            // Log invoice ID extraction for debugging
            if ($invoice_id) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Invoice ID extracted from order creation - Order ' . $this->module->currentOrder . ', Invoice ID: ' . $invoice_id,
                    1,
                    null,
                    'Order',
                    $this->module->currentOrder
                );
            } else {
                PrestaShopLogger::addLog(
                    'TwoPayment: No invoice ID in order creation response - Order ' . $this->module->currentOrder,
                    2,
                    null,
                    'Order',
                    $this->module->currentOrder
                );
            }
            
            $payment_data = array(
                'two_order_id' => $response['id'],
                'two_order_reference' => $response['merchant_reference'],
                'two_order_state' => $response['state'],
                'two_order_status' => $response['status'],
                'two_day_on_invoice' => (string)$this->module->getSelectedPaymentTerm(), // Selected payment term
                'two_invoice_url' => $response['invoice_url'],
                'two_invoice_id' => $invoice_id,
            );

            $this->module->setTwoOrderPaymentData($this->module->currentOrder, $payment_data);

            // Fraud Verification Skip (Must be enabled by Two on request)
            // If merchant has set fraud_verification_skip=true in paymentdata, handle accordingly
            $fraudVerificationSkip = isset($paymentdata['fraud_verification_skip']) && $paymentdata['fraud_verification_skip'] === true;
            
            if ($fraudVerificationSkip) {
                // Merchant wants to skip fraud verification - validate that Two verified the order
                $orderState = isset($response['state']) ? strtoupper($response['state']) : '';
                
                if ($orderState === 'VERIFIED') {
                    // Order is verified - skip payment_url redirect and go directly to confirmation
                    PrestaShopLogger::addLog(
                        'TwoPayment: Fraud verification skipped for order ' . $this->module->currentOrder . ' - Order state is VERIFIED, proceeding to confirmation',
                        1,
                        null,
                        'Order',
                        $this->module->currentOrder
                    );
                    
                    // Update order status to "Two: Verified - Ready for Fulfillment"
                    // This is the correct status for orders that are verified and awaiting fulfillment
                    $verified_status = Configuration::get('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT');
                    if (!$verified_status) {
                        // Fallback to mapped state if custom state doesn't exist
                        $verified_status = Configuration::get('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP');
                        if (!$verified_status) {
                            // Final fallback to payment accepted
                            $verified_status = Configuration::get('PS_OS_PAYMENT');
                        }
                    }
                    $this->module->changeOrderStatus($this->module->currentOrder, $verified_status);
                    
                    // Redirect to order confirmation page
                    Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
                } else {
                    // Order is NOT verified but merchant requested to skip verification - this is an error
                    $this->module->restoreDuplicateCart($this->module->currentOrder, $customer->id);
                    $this->module->changeOrderStatus($this->module->currentOrder, Configuration::get('PS_TWO_OS_PAYMENT_ERROR_MAP'));
                    
                    PrestaShopLogger::addLog(
                        'TwoPayment: Fraud verification skip requested for order ' . $this->module->currentOrder . ' but order state is "' . $orderState . '" (expected VERIFIED). Blocking checkout.',
                        3,
                        null,
                        'Order',
                        $this->module->currentOrder
                    );
                    
                    // Generic error message - don't expose fraud verification skip details to customer
                    $message = $this->module->l('Unable to process your payment at this time. Please contact the store owner for assistance.');
                    $this->errors[] = $message;
                    $this->redirectWithNotifications('index.php?controller=order');
                }
            } else {
                // Standard flow - redirect to Two's payment_url for verification
                Tools::redirect($response['payment_url']);
            }
        } else {
            $this->module->restoreDuplicateCart($this->module->currentOrder, $customer->id);
            $this->module->changeOrderStatus($this->module->currentOrder, Configuration::get('PS_TWO_OS_PAYMENT_ERROR_MAP'));
            $message = $this->module->l('Something went wrong please contact store owner.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }
    }

}
