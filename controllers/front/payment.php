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

        // Validate currency
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
        // page rendered while it still did can post here afterwards - and
        // without this that submission proceeds and fails deeper in, at order
        // creation, as an opaque error. Refuse it here, in the same shape as
        // every other unavailability on this path.
        //
        // DEFINITIVELY unusable only, and cache-only (review round 2): a
        // rejected key or no key at all is worth refusing a submission over, a
        // 5xx or a network blip at this exact instant is not - that would turn a
        // good order into "not available" and cache the refusal for a minute,
        // where proceeding would have reached order creation with its own longer
        // timeout and its own decline handling.
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

        // Validate payment form token before any provider request.
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

        // Guard: Require company details when placing order with Two.
        // Use shared module resolver so checkout and order payload logic stay consistent.
        $companyData = $this->module->getTwoCheckoutCompanyData($address);
        $companyName = isset($companyData['company_name']) ? trim((string) $companyData['company_name']) : '';
        $companyId = isset($companyData['organization_number']) ? trim((string) $companyData['organization_number']) : '';
        if (Tools::isEmpty($companyName) || Tools::isEmpty($companyId)) {
            $this->failCheckout(
                $this->module->l('To pay with Two, please select your company so we can verify your business and offer invoice terms.')
            );
            return;
        }

        // Keep attempt table bounded without adding cron requirements.
        $this->module->maybeCleanupStaleTwoCheckoutAttempts();

        // Authoritative server-side order intent check at payment submit.
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
                : $this->module->l('Your order could not be approved by Two payment. Please choose another payment method or contact support.');
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
            // Surface WHY the payload could not be built - but only when the
            // plugin itself raised the failure as a buyer-actionable amount
            // diagnostic (TwoCheckoutAmountException). Withholding that detail
            // is what left the buyer staring at a spinner with a generic
            // message in TWO-25161, and since TWO-24768 the same string also
            // goes into the AJAX JSON body.
            //
            // Payload building walks PrestaShop core (TaxManagerFactory,
            // Address, Carrier, DB reads), so an arbitrary exception can reach
            // here: a PrestaShopDatabaseException would put SQL text and
            // table/column names on a public storefront. Those get the generic
            // message, with the real exception class and message logged at
            // severity 4 so nothing is lost for diagnosis.
            $isBuyerActionable = $e instanceof TwoCheckoutAmountException;
            $message = $this->module->l('Two could not build this order from your cart.');
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

        // Call Two API to create order
        $response = $this->module->setTwoPaymentRequest(
            '/v1/order',
            $paymentdata,
            'POST',
            ['X-Idempotency-Key: ' . $idempotency_key]
        );
        
        // Extract HTTP status code from enhanced response structure
        $http_status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
        
        $response_summary = $this->module->buildTwoApiResponseLogSummary($response);
        PrestaShopLogger::addLog(
            'TwoPayment: Two API response summary - ' . json_encode($response_summary),
            ($http_status === Twopayment::HTTP_STATUS_CREATED ? 1 : 3)
        );

        // CRITICAL CHECK: Only proceed if Two returned 201 Created
        // Any other status = order creation failed, and no local order should exist
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

            // Surface the platform minimum when the rejection is attributable
            // to it (TWO-24775): the API's machine-readable decline_reason
            // first, with a strictly-below-minimum fallback while older
            // backends carry only a generic reason. Only on a 4xx ORDER
            // rejection - a connection error, provider outage, or an
            // auth/config failure (401/403) says nothing about the order
            // value. Fail-soft: no hint when it cannot be attributed.
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
            // Extract invoice ID from response if available
            $invoice_id = isset($response['invoice_details']['id']) ? $response['invoice_details']['id'] : null;
            $resolved_terms = $this->module->resolveTwoPaymentTermsFromOrderResponse(
                $response,
                (string)$this->module->getSelectedPaymentTerm(),
                Configuration::get('PS_TWO_PAYMENT_TERM_TYPE')
            );
            
            // Log invoice ID extraction for debugging
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
                    // Best effort cleanup when local attempt persistence fails.
                    $this->module->cancelTwoOrderBestEffort((string)$response['id'], 'attempt_persist_failed');
                }
                $this->failCheckout($this->module->l('Temporary checkout issue. Please try again.'));
                return;
            }

            // Fraud Verification Skip (Must be enabled by Two on request)
            // If merchant has set fraud_verification_skip=true in paymentdata, handle accordingly
            $fraudVerificationSkip = isset($paymentdata['fraud_verification_skip']) && $paymentdata['fraud_verification_skip'] === true;
            
            if ($fraudVerificationSkip) {
                // Merchant wants to skip fraud verification - validate that Two verified the order
                $orderState = isset($response['state']) ? strtoupper($response['state']) : '';
                $validSkipStates = array('VERIFIED', 'CONFIRMED', 'FULFILLED');
                
                if (in_array($orderState, $validSkipStates, true)) {
                    // Order is verified - skip payment_url redirect and go directly to local confirmation callback
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
                    // Order is NOT verified but merchant requested to skip verification - this is an error
                    $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED', array(
                        'two_order_state' => isset($response['state']) ? $response['state'] : '',
                        'two_order_status' => isset($response['status']) ? $response['status'] : '',
                    ));

                    // Best effort provider cleanup
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
                    
                    // Generic error message - don't expose fraud verification skip details to customer
                    $this->failCheckout(
                        $this->module->l('Unable to process your payment at this time. Please contact the store owner for assistance.')
                    );
                    return;
                }
            } else {
                // Standard flow - redirect to Two's payment_url for verification
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
                $this->module->l('Unable to process your order with Two payment. Please choose another payment method or contact the store.'),
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
