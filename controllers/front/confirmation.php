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

        $id_order = Tools::getValue('id_order');

        if (isset($id_order) && !Tools::isEmpty($id_order)) {
            $order = new Order((int) $id_order);
            $customer = new Customer($order->id_customer);
            
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

                if (isset($response['state']) && $response['state'] == 'VERIFIED') {
                    // Order is verified, now confirm it to move to CONFIRMED state
                    $confirm_result = $this->module->confirmTwoOrder($two_order_id);
                    
                    // Use the confirmation result or fallback to original state
                    $final_state = $confirm_result['success'] ? $confirm_result['state'] : $response['state'];
                    $final_status = ($confirm_result['success'] && $confirm_result['status']) ? $confirm_result['status'] : $response['status'];
                    
                    $payment_data = array(
                        'two_order_id' => $response['id'],
                        'two_order_reference' => $response['merchant_reference'],
                        'two_order_state' => $final_state,
                        'two_order_status' => $final_status,
                        'two_day_on_invoice' => (string)$this->module->getSelectedPaymentTerm(), // Selected payment term
                        'two_invoice_url' => $response['invoice_url'],
                    );
                    $this->module->setTwoOrderPaymentData($order->id, $payment_data);
                }
            }
            // Use custom Two state if available, fallback to mapped state
            $verified_status = Configuration::get('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT');
            if (!$verified_status) {
                $verified_status = Configuration::get('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP');
                if (!$verified_status) {
                    $verified_status = Configuration::get('PS_OS_PREPARATION');
                }
            }
            $this->module->changeOrderStatus($order->id, $verified_status);
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $order->id_cart . '&id_module=' . $this->module->id . '&id_order=' . $order->id . '&key=' . $customer->secure_key);
        } else {
            $message = $this->module->l('Unable to find the requested order please contact store owner.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order&step=1');
        }
    }

}
