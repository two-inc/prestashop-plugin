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
        $address = new Address($cart->id_address_invoice);
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

        // NOTE: Order intent approval is handled client-side prior to order placement.
        // We avoid re-calling /v1/order_intent here to prevent mismatches and duplicate checks.

        //Two Create order
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

        $paymentdata = $this->module->getTwoNewOrderData($this->module->currentOrder, $cart);

        $response = $this->module->setTwoPaymentRequest('/v1/order', $paymentdata, 'POST');

        

        if (!isset($response)) {
            $this->module->restoreDuplicateCart($this->module->currentOrder, $customer->id);
            $this->module->changeOrderStatus($this->module->currentOrder, Configuration::get('PS_TWO_OS_PAYMENT_ERROR_MAP'));
            $message = $this->module->l('Something went wrong please contact store owner.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        if (isset($response['result']) && $response['result'] === 'failure') {
            $this->module->restoreDuplicateCart($this->module->currentOrder, $customer->id);
            $this->module->changeOrderStatus($this->module->currentOrder, Configuration::get('PS_TWO_OS_PAYMENT_ERROR_MAP'));
            PrestaShopLogger::addLog('TwoPayment: Two /v1/order failure response: ' . json_encode($response), 3);
            $message = $this->module->l('Your order cannot be processed with Two at this time. Please select an alternative payment method.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        if (isset($response['response']['code']) && ($response['response']['code'] === 401 || $response['response']['code'] === 403)) {
            $this->module->restoreDuplicateCart($this->module->currentOrder, $customer->id);
            $this->module->changeOrderStatus($this->module->currentOrder, Configuration::get('PS_TWO_OS_PAYMENT_ERROR_MAP'));
            $message = $this->module->l('Website is not properly configured with Two payment.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        if (isset($response['response']['code']) && $response['response']['code'] === 400) {
            $this->module->restoreDuplicateCart($this->module->currentOrder, $customer->id);
            $this->module->changeOrderStatus($this->module->currentOrder, Configuration::get('PS_TWO_OS_PAYMENT_ERROR_MAP'));
            $message = $this->module->l('Something went wrong please contact store owner.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        $two_err = $this->module->getTwoErrorMessage($response);
        if ($two_err) {
            $this->module->restoreDuplicateCart($this->module->currentOrder, $customer->id);
            $this->module->changeOrderStatus($this->module->currentOrder, Configuration::get('PS_TWO_OS_PAYMENT_ERROR_MAP'));
            $message = ($two_err != '') ? $two_err : $this->module->l('Something went wrong please contact store owner.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        if (isset($response['response']['code']) && $response['response']['code'] >= 400) {
            $this->module->restoreDuplicateCart($this->module->currentOrder, $customer->id);
            $this->module->changeOrderStatus($this->module->currentOrder, Configuration::get('PS_TWO_OS_PAYMENT_ERROR_MAP'));
            $message = $this->module->l('EHF Invoice is not available for this order.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }

        if (isset($response['id']) && $response['id']) {
            $payment_data = array(
                'two_order_id' => $response['id'],
                'two_order_reference' => $response['merchant_reference'],
                'two_order_state' => $response['state'],
                'two_order_status' => $response['status'],
                'two_day_on_invoice' => (string)$this->module->getSelectedPaymentTerm(), // Selected payment term
                'two_invoice_url' => $response['invoice_url'],
            );

            $this->module->setTwoOrderPaymentData($this->module->currentOrder, $payment_data);

            Tools::redirect($response['payment_url']);
        } else {
            $this->module->restoreDuplicateCart($this->module->currentOrder, $customer->id);
            $this->module->changeOrderStatus($this->module->currentOrder, Configuration::get('PS_TWO_OS_PAYMENT_ERROR_MAP'));
            $message = $this->module->l('Something went wrong please contact store owner.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }
    }

}
