<?php
/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

class TwopaymentCancelModuleFrontController extends ModuleFrontController
{
    /** @var string|null The cart lock this request holds, if any (TWO-27356). */
    private $held_lock = null;

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
        $this->redirectToReturnPage();
    }

    private function handleAttemptCancel($attempt_token)
    {
        $attempt = $this->module->getTwoCheckoutAttempt($attempt_token);
        if (!$attempt) {
            $message = $this->module->l('Unable to find the requested payment attempt.');
            $this->errors[] = $message;
            $this->redirectToReturnPage();
        }

        if (!$this->isAuthorizedAttemptCallback($attempt)) {
            PrestaShopLogger::addLog(
                'TwoPayment: Unauthorized cancel callback for attempt ' . $attempt_token,
                3
            );
            $message = $this->module->l('Unable to validate this cancellation callback. Please retry checkout.');
            $this->errors[] = $message;
            $this->redirectToReturnPage();
        }

        // The cart lock placement holds from before validateOrder() until the attempt is linked
        // (TWO-27356), so a cancel never runs while this attempt's order is being placed.
        $lock = TwoPendingRecovery::lockName($attempt, $attempt_token);
        if (!$this->module->acquireTwoAttemptLock($lock, TwoPendingRecovery::LOCK_WAIT_SECONDS)) {
            $this->redirectAfterBusyCancel($attempt_token);
        }
        $this->held_lock = $lock;
        try {
            $this->cancelAttemptLocked($attempt_token);
        } finally {
            $this->releaseHeldLock();
        }
    }

    /**
     * Another request holds the cart lock and did not finish within the wait. Nothing is sent to
     * Two and nothing is written: the buyer gets the decline message if the attempt is already
     * closed, and otherwise is told it was not cancelled and to check their order history shortly.
     *
     * @param string $attempt_token
     * @return void
     */
    private function redirectAfterBusyCancel($attempt_token)
    {
        $attempt = $this->module->getTwoCheckoutAttempt($attempt_token);
        $status = is_array($attempt) && isset($attempt['status']) ? strtoupper((string)$attempt['status']) : '';
        $this->errors[] = $status === 'CANCELLED'
            ? $this->getDeclineMessage()
            : $this->module->l('This payment is still being processed, so it was not cancelled. Check your order history in a moment.');
        $this->redirectToReturnPage();
    }

    /**
     * Runs under the cart lock, on the attempt as it stands once the lock is held: a placement
     * that finished while this request waited has linked its order by then.
     *
     * Two's checkout sends a declined buyer here, and the buyer wants to pay for the same basket
     * another way (TWO-27390). The resolver reads Two's state and closes the attempt the way it
     * asks; the buyer then sees the decline message once: on the payment step with the cart
     * restored when this session is theirs (a copy of an ordered cart reopens checkout at the
     * address step on PrestaShop 8 and 9), or with this session's own basket, and with no basket,
     * on the cart page. An approved order goes to the confirmation route instead.
     *
     * @param string $attempt_token
     * @return void
     */
    private function cancelAttemptLocked($attempt_token)
    {
        $result = $this->module->getTwoPendingRecovery()->resolveCancelReturnLocked($attempt_token);
        $outcome = $result['outcome'];
        if ($outcome === TwoPendingRecovery::OUTCOME_FAILED) {
            $this->errors[] = $this->module->l('Unable to find the requested payment attempt.');
            $this->redirectToReturnPage();
        }

        $attempt = $this->module->getTwoCheckoutAttempt($attempt_token);
        if ($outcome === TwoPendingRecovery::ACTION_CONFIRM) {
            // Tools::redirect() exits without passing through redirectWithNotifications().
            $this->releaseHeldLock();
            Tools::redirect($this->context->link->getModuleLink($this->module->name, 'confirmation', array(
                'attempt_token' => $attempt_token,
                'key' => is_array($attempt) && isset($attempt['customer_secure_key']) ? (string) $attempt['customer_secure_key'] : '',
            ), true));
        }
        if ($outcome === TwoPendingRecovery::OUTCOME_STALE) {
            $this->errors[] = $this->module->l('This payment attempt has already been finalized.');
            $this->redirectToReturnPage();
        }
        if ($outcome === TwoPendingRecovery::OUTCOME_LOOKUP_FAILED || $outcome === TwoPendingRecovery::OUTCOME_UNCHANGED) {
            // Two's state is unknown or not one this module acts on: no decline is claimed, and the
            // attempt stays waiting for the scheduled check.
            $this->errors[] = $result['message'];
            $this->redirectToReturnPage();
        }

        if ($outcome === TwoPendingRecovery::OUTCOME_CANCELLED || $outcome === TwoPendingRecovery::OUTCOME_DECLINED) {
            // Only the request that closes the attempt restores its cart. A repeat click finds it
            // closed and restores nothing, so a cart ordered since is never handed back again.
            $this->restoreCartIntoOwnSession($attempt);
        }
        $this->errors[] = $this->getDeclineMessage();
        $this->redirectToReturnPage();
    }

    /**
     * Puts the attempt's cart back into this session only when the session is the buyer's own:
     * the customer in it, guest or registered, is the attempt's customer. A link opened in another
     * browser restores nothing. A cart that already has an order is restored as a copy. Core's
     * Cart::duplicate() leaves the copy's checkout session empty, so on PrestaShop 8 and 9 checkout
     * reopens at the address step rather than the payment step, and the copy keeps no voucher
     * codes: only automatically applied cart rules come back.
     *
     * @param mixed $attempt
     * @return void
     */
    private function restoreCartIntoOwnSession($attempt)
    {
        $session_customer_id = isset($this->context->customer->id) ? (int) $this->context->customer->id : 0;
        if (!is_array($attempt) || $session_customer_id <= 0 || $session_customer_id !== (int) $attempt['id_customer']) {
            return;
        }
        $cart = new Cart((int) $attempt['id_cart']);
        if (!Validate::isLoadedObject($cart) || (int) $cart->id_customer !== $session_customer_id) {
            return;
        }
        if ($cart->orderExists()) {
            // Core drops an ordered cart from the session on the next request, so the buyer gets a copy.
            $this->module->restoreDuplicateCart((int) $attempt['id_order'], $session_customer_id);

            return;
        }
        $this->context->cart = $cart;
        $this->context->cookie->id_cart = (int) $cart->id;
        $this->context->cookie->write();
    }

    /**
     * @return string
     */
    private function getDeclineMessage()
    {
        return sprintf(
            $this->module->l('%s could not approve this purchase. Please choose another payment method.'),
            $this->module->getTwoBrandConfig('product_name')
        );
    }

    private function handleLegacyOrderCancel($id_order)
    {
        $order = new Order((int)$id_order);
        if (!Validate::isLoadedObject($order)) {
            $message = $this->module->l('Unable to find the requested order please contact store owner.');
            $this->errors[] = $message;
            $this->redirectToReturnPage();
        }

        $customer = new Customer((int)$order->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $message = $this->module->l('Unable to load order customer.');
            $this->errors[] = $message;
            $this->redirectToReturnPage();
        }

        if (!$this->isAuthorizedLegacyOrderAccess($order, $customer)) {
            PrestaShopLogger::addLog(
                'TwoPayment: Unauthorized legacy cancel callback for order ' . (int)$order->id,
                3
            );
            $message = $this->module->l('Unable to validate this cancellation callback. Please retry checkout.');
            $this->errors[] = $message;
            $this->redirectToReturnPage();
        }

        $orderpaymentdata = $this->module->getTwoOrderPaymentData($id_order);
        if (!$this->module->hasTwoProviderOrderMapping($orderpaymentdata)) {
            PrestaShopLogger::addLog(
                'TwoPayment: Legacy cancel callback missing Two order mapping for order ' . (int)$order->id .
                '. Local cancellation aborted to preserve provider-first consistency.',
                3
            );
            $message = sprintf($this->module->l('Could not update status to cancelled, please check with %1$s admin for id %2$s'), $this->module->getTwoBrandConfig('product_name'), (string)$order->id);
            $this->errors[] = $message;
            $this->redirectToReturnPage();
        }

        $two_order_id = (string)$orderpaymentdata['two_order_id'];

        $cancel_response = $this->module->sendTwoOrderRequest(TwoOrderPostprocessing::REQUEST_CANCEL, 'buyer_cancel', '/v1/order/' . $two_order_id . '/cancel', [], 'POST', null, $order);

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
                ', cancel: ' . $this->module->getTwoApiErrorDetail($cancel_response) .
                ', fetch_http=' . $response_http_status .
                ', provider_state=' . (isset($response['state']) ? (string)$response['state'] : 'unknown'),
                2
            );
            $message = sprintf($this->module->l('Could not update status to cancelled, please check with %1$s admin for id %2$s'), $this->module->getTwoBrandConfig('product_name'), $two_order_id);
            $this->errors[] = $message;
            $this->redirectToReturnPage();
        }

        $this->module->restoreDuplicateCart($order->id, $order->id_customer);
        $this->module->changeOrderStatus($order->id, $this->module->getTwoCancelledOrderStatusId());

        $message = $this->module->l('Your order is cancelled.');
        $this->errors[] = $message;
        $this->redirectToReturnPage();
    }

    /**
     * Every outcome leaves through here, with its message queued. A basket goes to the payment
     * step. A session with no basket, such as the link opened in another browser, goes to the
     * cart page (TWO-27410): core's checkout sends an empty cart on to the cart page itself, and
     * from PrestaShop 8 that second redirect replaces the queued message with its own empty set,
     * so the buyer would see nothing.
     *
     * @return void
     */
    private function redirectToReturnPage()
    {
        $cart = $this->context->cart;
        $has_basket = Validate::isLoadedObject($cart) && (int) $cart->nbProducts() > 0;
        $this->redirectWithNotifications($has_basket ? 'index.php?controller=order' : 'index.php?controller=cart&action=show');
    }

    /**
     * A redirect ends the request with exit, which skips finally blocks, so the cart lock is
     * released here before any redirect leaves the controller.
     *
     * @param mixed ...$args
     * @return mixed
     */
    public function redirectWithNotifications(...$args)
    {
        $this->releaseHeldLock();

        return parent::redirectWithNotifications(...$args);
    }

    /**
     * @return void
     */
    private function releaseHeldLock()
    {
        if ($this->held_lock !== null) {
            $this->module->releaseTwoAttemptLock($this->held_lock);
            $this->held_lock = null;
        }
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
        return $this->module->isTwoOrderCustomerAccessAuthorized(
            $order,
            $customer,
            trim((string)Tools::getValue('key')),
            isset($this->context->customer->id) ? (int)$this->context->customer->id : 0,
            isset($this->context->customer->secure_key) ? trim((string)$this->context->customer->secure_key) : ''
        );
    }

}
