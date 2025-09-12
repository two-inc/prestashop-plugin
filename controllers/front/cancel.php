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

        $id_order = Tools::getValue('id_order');

        if (isset($id_order) && !Tools::isEmpty($id_order)) {
            $order = new Order((int) $id_order);

            $this->module->restoreDuplicateCart($order->id, $order->id_customer);
            // Use custom Two state if available, fallback to mapped state  
            $cancelled_status = Configuration::get('PS_TWO_OS_CANCELLED');
            if (!$cancelled_status) {
                $cancelled_status = Configuration::get('PS_TWO_OS_CANCELLED_MAP');
                if (!$cancelled_status) {
                    $cancelled_status = Configuration::get('PS_OS_CANCELED');
                }
            }
            $this->module->changeOrderStatus($order->id, $cancelled_status);

            $orderpaymentdata = $this->module->getTwoOrderPaymentData($id_order);
            if ($orderpaymentdata && isset($orderpaymentdata['two_order_id'])) {
                $two_order_id = $orderpaymentdata['two_order_id'];
                
                $response = $this->module->setTwoPaymentRequest('/v1/order/' . $two_order_id . '/cancel', [], 'POST');
                if (!isset($response)) {
                    $message = sprintf($this->module->l('Could not update status to cancelled, please check with Two admin for id %s'), $two_order_id);
                    $this->errors[] = $message;
                    $this->redirectWithNotifications('index.php?controller=order');
                }

                $response = $this->module->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
                if (isset($response['state']) && $response['state'] == 'CANCELLED') {
                    $payment_data = array(
                        'two_order_id' => $response['id'],
                        'two_order_reference' => $response['merchant_reference'],
                        'two_order_state' => $response['state'],
                        'two_order_status' => $response['status'],
                        'two_day_on_invoice' => (string)$this->module->getSelectedPaymentTerm(), // Selected payment term
                        'two_invoice_url' => $response['invoice_url'],
                    );
                    $this->module->setTwoOrderPaymentData($order->id, $payment_data);
                }
            }
            $message = $this->module->l('Your order is cancelled.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        } else {
            $message = $this->module->l('Unable to find the requested order please contact store owner.');
            $this->errors[] = $message;
            $this->redirectWithNotifications('index.php?controller=order');
        }
    }

}
