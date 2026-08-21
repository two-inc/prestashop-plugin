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
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart)) {
            $this->failCheckout(
                '',
                'TwoPayment: Invalid cart object in payment controller',
                2
            );
            return;
        }

        $currency = new Currency($cart->id_currency);

        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0) {
            $this->failCheckout(
                '',
                'TwoPayment: Incomplete cart data - Customer: ' . $cart->id_customer . ', Delivery: ' . $cart->id_address_delivery . ', Invoice: ' . $cart->id_address_invoice,
                2
            );
            return;
        }

        if (!$this->module->active) {
            $this->failCheckout(
                '',
                'TwoPayment: Payment attempt on inactive module',
                2
            );
            return;
        }

        if (!Validate::isLoadedObject($currency)) {
            $this->failCheckout(
                '',
                'TwoPayment: Invalid currency for cart ' . $cart->id,
                2
            );
            return;
        }

        // API-key verification gate (TWO-25326). hookPaymentOptions() withholds
        // Two whenever the stored key does not verify, but a buyer holding a
        // page rendered while it still did can post here afterwards, and
        // without this that submission fails deeper in, at order creation, as
        // an opaque error.
        //
        // DEFINITIVELY unusable only: a rejected key or no key at all is worth
        // refusing a submission over, a 5xx or a network blip at this exact
        // instant is not - that would turn a good order into "not available"
        // and cache the refusal for a minute, where proceeding would have
        // reached order creation with its own longer timeout and decline
        // handling.
        if ($this->module->isTwoApiKeyDefinitelyUnusable()) {
            $this->failCheckout(
                $this->module->l('This payment method is not available.'),
                'TwoPayment: Payment attempt while the API key does not verify - cart ' . (int) $cart->id,
                2
            );
            return;
        }

        if (!$this->module->isCartCurrencySupportedByTwo($cart)) {
            $this->failCheckout(
                $this->module->l('This payment method is not available for your selected currency.'),
                'TwoPayment: Unsupported currency ' . (int)$cart->id_currency . ' for cart ' . $cart->id,
                2
            );
            return;
        }

        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'twopayment') {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            $this->failCheckout(
                $this->module->l('This payment method is not available.')
            );
            return;
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->failCheckout(
                $this->module->l('Customer is not valid.')
            );
            return;
        }

        $submittedToken = trim((string) Tools::getValue('token'));
        if (Tools::isEmpty($submittedToken) || !hash_equals((string) Tools::getToken(false), $submittedToken)) {
            $this->failCheckout(
                $this->module->l('Your payment approval has expired. Please refresh the page and try again.'),
                'TwoPayment: Payment submit blocked - invalid or missing checkout token for cart ' . $cart->id,
                3
            );
            return;
        }

        $address = new Address((int) $cart->id_address_invoice);
        if (!Validate::isLoadedObject($address)) {
            $this->failCheckout(
                '',
                'TwoPayment: Invalid invoice address for cart ' . $cart->id . ' while preparing payment',
                3
            );
            return;
        }

        // Shared module resolver keeps checkout and order payload logic consistent.
        $companyData = $this->module->getTwoCheckoutCompanyData($address);
        $companyName = isset($companyData['company_name']) ? trim((string) $companyData['company_name']) : '';
        $companyId = isset($companyData['organization_number']) ? trim((string) $companyData['organization_number']) : '';
        if (Tools::isEmpty($companyName) || Tools::isEmpty($companyId)) {
            $this->failCheckout(
                sprintf($this->module->l('To pay with %s, please select your company so we can verify your business and offer invoice terms.'), $this->module->getTwoBrandConfig('product_name'))
            );
            return;
        }

        // Keep attempt table bounded without adding cron requirements.
        $this->module->maybeCleanupStaleTwoCheckoutAttempts();

        $orderIntentResult = $this->module->checkTwoOrderIntentApprovalAtPayment($cart, $customer, $currency, $address);
        $frontendIntentTelemetry = isset($this->context->cookie->two_order_intent_approved)
            ? $this->context->cookie->two_order_intent_approved === '1'
            : null;
        if ($frontendIntentTelemetry !== null && $frontendIntentTelemetry !== (bool)$orderIntentResult['approved']) {
            PrestaShopLogger::addLog(
                'TwoPayment: Frontend order intent telemetry differs from backend authoritative result for cart ' .
                $cart->id . '. Frontend=' . ($frontendIntentTelemetry ? 'approved' : 'declined') .
                ', Backend=' . ((bool)$orderIntentResult['approved'] ? 'approved' : 'declined'),
                2
            );
        }

        if (!(bool)$orderIntentResult['approved']) {
            $failureMessage = (isset($orderIntentResult['message']) && !Tools::isEmpty($orderIntentResult['message']))
                ? (string)$orderIntentResult['message']
                : sprintf($this->module->l('Your order could not be approved by %s payment. Please choose another payment method or contact support.'), $this->module->getTwoBrandConfig('product_name'));
            $this->failCheckout(
                $failureMessage,
                'TwoPayment: Order blocked by authoritative backend order intent check for cart ' .
                $cart->id . '. Status=' . (isset($orderIntentResult['status']) ? $orderIntentResult['status'] : 'unknown') .
                ', HTTP=' . (isset($orderIntentResult['http_status']) ? (int)$orderIntentResult['http_status'] : 0),
                3
            );
            return;
        }

        // Provider-first flow: create Two order first, then create PrestaShop order only after verified callback
        $attempt_token = $this->module->generateTwoCheckoutAttemptToken($cart->id, $customer->id);
        $merchant_order_id = $this->module->buildTwoMerchantOrderId($attempt_token, $cart->id);

        $merchant_urls = [
            'merchant_confirmation_url' => $this->context->link->getModuleLink($this->module->name, 'confirmation', ['attempt_token' => $attempt_token, 'key' => $customer->secure_key], true),
            'merchant_cancel_order_url' => $this->context->link->getModuleLink($this->module->name, 'cancel', ['attempt_token' => $attempt_token, 'key' => $customer->secure_key], true),
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => ''
        ];

        try {
            $paymentdata = $this->module->getTwoNewOrderData($merchant_order_id, $cart, $merchant_urls);
            $cart_snapshot_hash = $this->module->calculateTwoCheckoutSnapshotHash($cart, $paymentdata);
            $idempotency_key = $this->module->buildTwoOrderCreateIdempotencyKey($cart, $cart_snapshot_hash);
        } catch (Exception $e) {
            // Surface WHY only when the plugin raised the failure as a
            // buyer-actionable amount diagnostic (TwoCheckoutAmountException,
            // TWO-25161); withholding it left the buyer staring at a generic
            // spinner message. Payload building walks PrestaShop core, so an
            // arbitrary exception can reach here - e.g. a
            // PrestaShopDatabaseException would put SQL/table/column text on a
            // public storefront, so anything else gets the generic message,
            // with the real class and message logged at severity 4.
            $isBuyerActionable = $e instanceof TwoCheckoutAmountException;
            $message = sprintf($this->module->l('%s could not build this order from your cart.'), $this->module->getTwoBrandConfig('product_name'));
            $detail = $isBuyerActionable ? trim((string)$e->getMessage()) : '';
            if ($detail !== '') {
                $message .= ' ' . sprintf($this->module->l('Details: %s.'), $detail);
            }
            $message .= ' ' . $this->module->l('Please review your cart and try again, or contact the store.');
            $this->failCheckout(
                $message,
                'TwoPayment: Failed building order payload for cart ' . $cart->id . ' - [' .
                get_class($e) . '] ' . $e->getMessage(),
                $isBuyerActionable ? 3 : 4
            );
            return;
        }

        $response = $this->module->setTwoPaymentRequest(
            '/v1/order',
            $paymentdata,
            'POST',
            ['X-Idempotency-Key: ' . $idempotency_key]
        );

        $http_status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
        
        $response_summary = $this->module->buildTwoApiResponseLogSummary($response);
        PrestaShopLogger::addLog(
            'TwoPayment: Two API response summary - ' . json_encode($response_summary),
            ($http_status === Twopayment::HTTP_STATUS_CREATED ? 1 : 3)
        );

        // Any status other than 201 means no local order should exist.
        if ($http_status !== Twopayment::HTTP_STATUS_CREATED) {
            PrestaShopLogger::addLog(
                'TwoPayment: Two API did not return 201 (got ' . $http_status . ') - no local order created for cart ' . $cart->id . ', attempt ' . $attempt_token,
                3
            );
            PrestaShopLogger::addLog(
                'TwoPayment: Provider order lifecycle - create_failed for attempt ' . $attempt_token .
                ', HTTP ' . $http_status,
                2
            );

            $message = sprintf($this->module->l('Unable to process your order with %s payment.'), $this->module->getTwoBrandConfig('product_name'));

            if (!isset($response) || $http_status === 0) {
                $message = $this->module->l('Connection error with payment provider. Please try again.');
            } elseif ($http_status === 401 || $http_status === 403) {
                $message = $this->module->l('Payment method configuration error. Please contact the store.');
            } elseif ($http_status === 400) {
                $two_err = $this->module->getTwoErrorMessage($response);
                if ($two_err) {
                    $message = $two_err;
                } else {
                    $message = $this->module->l('Invalid order data. Please check your details and try again.');
                }
            } elseif ($http_status >= Twopayment::HTTP_STATUS_SERVER_ERROR) {
                $message = $this->module->l('Payment provider temporarily unavailable. Please try again later.');
            }

            // Surface the platform minimum when attributable (TWO-24775): a
            // connection error, provider outage, or auth/config failure
            // (401/403) says nothing about order value, so restrict to 4xx
            // order rejections. Fail-soft: no hint when it can't be attributed.
            if ($http_status >= Twopayment::HTTP_STATUS_BAD_REQUEST
                && $http_status < Twopayment::HTTP_STATUS_SERVER_ERROR
                && $http_status !== 401
                && $http_status !== 403
            ) {
                $minimum_hint = $this->module->getTwoMinimumOrderDeclineHint(
                    is_array($response) ? $response : array(),
                    $cart
                );
                if (!Tools::isEmpty($minimum_hint)) {
                    $message .= ' ' . $minimum_hint;
                }
            }

            $this->failCheckout($message);
            return;
        }

        if (isset($response['id']) && $response['id']) {
            $invoice_id = isset($response['invoice_details']['id']) ? $response['invoice_details']['id'] : null;
            $resolved_terms = $this->module->resolveTwoPaymentTermsFromOrderResponse(
                $response,
                (string)$this->module->getSelectedPaymentTerm(),
                Configuration::get('PS_TWO_PAYMENT_TERM_TYPE')
            );

            if ($invoice_id) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Invoice ID extracted from order creation - attempt ' . $attempt_token . ', Invoice ID: ' . $invoice_id,
                    1,
                    null,
                    'Cart',
                    $cart->id
                );
            } else {
                PrestaShopLogger::addLog(
                    'TwoPayment: No invoice ID in order creation response - attempt ' . $attempt_token,
                    2,
                    null,
                    'Cart',
                    $cart->id
                );
            }
            
            $payment_data = array(
                'two_order_id' => $response['id'],
                'two_order_reference' => $response['merchant_reference'],
                'two_order_state' => $response['state'],
                'two_order_status' => $response['status'],
                'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
                'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
                'two_invoice_url' => $response['invoice_url'],
                'two_invoice_id' => $invoice_id,
            );

            $attempt_data = array_merge($payment_data, array(
                'id_cart' => (int)$cart->id,
                'id_customer' => (int)$customer->id,
                'id_order' => null,
                'customer_secure_key' => $customer->secure_key,
                'merchant_order_id' => $merchant_order_id,
                'cart_snapshot_hash' => $cart_snapshot_hash,
                'order_create_idempotency_key' => $idempotency_key,
                'status' => 'CREATED',
            ));

            if (!$this->module->setTwoCheckoutAttempt($attempt_token, $attempt_data)) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Failed to persist checkout attempt ' . $attempt_token . ' for cart ' . $cart->id,
                    3
                );
                if (isset($response['id']) && $response['id']) {
                    $this->module->cancelTwoOrderBestEffort((string)$response['id'], 'attempt_persist_failed');
                }
                $this->failCheckout($this->module->l('Temporary checkout issue. Please try again.'));
                return;
            }

            // Fraud verification skip must be enabled by Two on request.
            $fraudVerificationSkip = isset($paymentdata['fraud_verification_skip']) && $paymentdata['fraud_verification_skip'] === true;

            if ($fraudVerificationSkip) {
                $orderState = isset($response['state']) ? strtoupper($response['state']) : '';
                $validSkipStates = array('VERIFIED', 'CONFIRMED', 'FULFILLED');

                if (in_array($orderState, $validSkipStates, true)) {
                    PrestaShopLogger::addLog(
                        'TwoPayment: Fraud verification skipped for attempt ' . $attempt_token . ' - Order state is ' . $orderState . ', proceeding to confirmation',
                        1,
                        null,
                        'Cart',
                        $cart->id
                    );

                    $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'REDIRECTED', array(
                        'two_order_state' => $response['state'],
                        'two_order_status' => $response['status'],
                        'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
                        'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
                        'two_invoice_url' => isset($response['invoice_url']) ? $response['invoice_url'] : '',
                        'two_invoice_id' => $invoice_id,
                    ));

                    Tools::redirect($this->context->link->getModuleLink($this->module->name, 'confirmation', ['attempt_token' => $attempt_token, 'key' => $customer->secure_key], true));
                } else {
                    $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED', array(
                        'two_order_state' => isset($response['state']) ? $response['state'] : '',
                        'two_order_status' => isset($response['status']) ? $response['status'] : '',
                    ));

                    if (isset($response['id']) && $response['id']) {
                        $this->module->cancelTwoOrderBestEffort((string)$response['id'], 'fraud_skip_state_invalid');
                    }

                    PrestaShopLogger::addLog(
                        'TwoPayment: Fraud verification skip requested for attempt ' . $attempt_token . ' but order state is "' . $orderState . '" (expected one of ' . implode(', ', $validSkipStates) . '). Blocking checkout.',
                        3,
                        null,
                        'Cart',
                        $cart->id
                    );

                    // Generic message: don't expose fraud verification skip details to the customer.
                    $this->failCheckout(
                        $this->module->l('Unable to process your payment at this time. Please contact the store owner for assistance.')
                    );
                    return;
                }
            } else {
                $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'REDIRECTED', array(
                    'two_order_state' => isset($response['state']) ? $response['state'] : '',
                    'two_order_status' => isset($response['status']) ? $response['status'] : '',
                    'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
                    'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
                    'two_invoice_url' => isset($response['invoice_url']) ? $response['invoice_url'] : '',
                    'two_invoice_id' => $invoice_id,
                ));

                if (!isset($response['payment_url']) || Tools::isEmpty($response['payment_url'])) {
                    $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
                    if (isset($response['id']) && !Tools::isEmpty($response['id'])) {
                        $cancelled = $this->module->cancelTwoOrderBestEffort((string)$response['id'], 'missing_payment_url');
                        PrestaShopLogger::addLog(
                            'TwoPayment: Provider order lifecycle - created_without_redirect for attempt ' . $attempt_token .
                            ', Two order ' . $response['id'] .
                            ', cleanup=' . ($cancelled ? 'cancelled' : 'cancel_failed'),
                            $cancelled ? 2 : 3
                        );
                    }
                    $this->failCheckout($this->module->l('Unable to redirect to payment provider. Please try again.'));
                    return;
                }

                Tools::redirect($response['payment_url']);
            }
        } else {
            PrestaShopLogger::addLog(
                'TwoPayment: Two API created response without id for cart ' . $cart->id . ', attempt ' . $attempt_token,
                3
            );
            $this->failCheckout($this->module->l('Something went wrong please contact store owner.'));
            return;
        }
    }

    /**
     * Report a checkout failure to whoever submitted the payment form.
     *
     * A browser navigation gets what it has always got: a 302 back to the
     * order page with the message flashed into the session notifications.
     *
     * An AJAX caller cannot use that. It follows the 302 transparently,
     * receives the order page's HTML with HTTP 200, has no way to tell that
     * from a success, and leaves the buyer staring at a checkout that never
     * moves - the message is rendered into a page nobody ever displays.
     * Checkout front-ends that post the payment form over XHR instead of
     * navigating (PrestaShop's own checkout module among them) hit exactly
     * this. For those callers, answer with a machine-readable JSON error and
     * a non-2xx status so the failure is impossible to mistake for success.
     *
     * @param string $message
     * @param string $logMessage
     * @param int $severity
     * @return void
     */
    private function failCheckout($message = '', $logMessage = '', $severity = 2)
    {
        if (!Tools::isEmpty($logMessage)) {
            PrestaShopLogger::addLog($logMessage, (int)$severity);
        }

        $redirectUrl = 'index.php?controller=order';

        if (self::isTwoAjaxCheckoutRequest($_SERVER)) {
            $this->emitTwoCheckoutJsonFailure(self::buildTwoCheckoutFailurePayload(
                $message,
                // Several internal failures deliberately carry no buyer-facing
                // text (the detail belongs in the log, not on the storefront).
                // An AJAX caller still needs something to render, or the fix
                // reproduces the silent hang one layer up.
                sprintf($this->module->l('Unable to process your order with %s payment. Please choose another payment method or contact the store.'), $this->module->getTwoBrandConfig('product_name')),
                $redirectUrl
            ));
            return;
        }

        if (!Tools::isEmpty($message)) {
            $this->errors[] = $message;
            $this->redirectWithNotifications($redirectUrl);
            return;
        }
        Tools::redirect($redirectUrl);
    }

    /**
     * Whether the payment form was submitted by an AJAX caller rather than by
     * a top-level browser navigation.
     *
     * XMLHttpRequest-based checkouts (jQuery, and PrestaShop's own checkout
     * JS) announce themselves with X-Requested-With. fetch() callers do not,
     * so also treat a request that asks for JSON and does not accept HTML as
     * AJAX - a page navigation always accepts HTML.
     *
     * @param array $server Request server variables ($_SERVER shape).
     * @return bool
     */
    public static function isTwoAjaxCheckoutRequest(array $server)
    {
        $requestedWith = isset($server['HTTP_X_REQUESTED_WITH'])
            ? strtolower(trim((string)$server['HTTP_X_REQUESTED_WITH']))
            : '';
        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }

        $accept = isset($server['HTTP_ACCEPT']) ? strtolower((string)$server['HTTP_ACCEPT']) : '';
        return strpos($accept, 'application/json') !== false
            && strpos($accept, 'text/html') === false;
    }

    /**
     * Build the JSON body handed to an AJAX caller when checkout fails.
     *
     * @param string $message Buyer-facing message, may be empty.
     * @param string $fallbackMessage Used when $message carries no text.
     * @param string $redirectUrl Where a caller that prefers to navigate should go.
     * @return array
     */
    public static function buildTwoCheckoutFailurePayload($message, $fallbackMessage, $redirectUrl)
    {
        $text = trim((string)$message);
        if ($text === '') {
            $text = trim((string)$fallbackMessage);
        }

        return array(
            'error' => true,
            'message' => $text,
            'redirect_url' => (string)$redirectUrl,
        );
    }

    /**
     * Write the JSON failure body and stop. Isolated so tests can observe the
     * payload without the process-terminating side effects.
     *
     * @param array $payload
     * @return void
     */
    protected function emitTwoCheckoutJsonFailure(array $payload)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(Twopayment::HTTP_STATUS_BAD_REQUEST);
        }
        echo json_encode($payload);
        exit;
    }

}
