<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

class TwopaymentOrderintentModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function __construct()
    {
        parent::__construct();
        $this->context = Context::getContext();
    }

    public function init()
    {
        parent::init();
    }

    public function postProcess()
    {
        
        if (Tools::getValue('ajax')) {
            $this->displayAjax();
            return;
        }
        
        parent::postProcess();
    }

    /**
     * Fallback AJAX handler for older PrestaShop versions
     */
    public function displayAjax()
    {
        $action = Tools::getValue('action');

        if (!TwoRateLimiter::check($action)) {
            header('Retry-After: 60');
            http_response_code(429);
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => 'Too many requests']));
            return;
        }

        switch ($action) {
            case 'buildPayload':
                $this->ajaxProcessBuildPayload();
                break;
            case 'saveCompany':
                $this->ajaxProcessSaveCompany();
                break;
            case 'clearCompany':
                $this->ajaxProcessClearCompany();
                break;
            case 'saveMirrorWrites':
                $this->ajaxProcessSaveMirrorWrites();
                break;
            case 'getCompany':
                $this->ajaxProcessGetCompany();
                break;
            case 'savePaymentTerm':
                $this->ajaxProcessSavePaymentTerm();
                break;
            case 'fetchTermSurcharges':
                $this->ajaxProcessFetchTermSurcharges();
                break;
            case 'syncSurchargeLine':
                $this->ajaxProcessSyncSurchargeLine();
                break;
            case 'checkOrderIntent':
                $this->ajaxProcessCheckOrderIntent();
                break;
            case 'saveOrderIntentResult':
                $this->ajaxProcessSaveOrderIntentResult();
                break;
            case 'clearOrderIntentResult':
                $this->ajaxProcessClearOrderIntentResult();
                break;
            case 'soleTraderAvailability':
                $this->ajaxProcessSoleTraderAvailability();
                break;
            case 'soleTraderTokens':
                $this->ajaxProcessSoleTraderTokens();
                break;
            case 'companySearch':
                $this->ajaxProcessCompanySearch();
                break;
            case 'companyDetails':
                $this->ajaxProcessCompanyDetails();
                break;
            case 'orderIntent':
                $this->ajaxProcessOrderIntent();
                break;
            default:
                $this->sendJsonResponse(json_encode([
                    'success' => false,
                    'error' => $this->module->l('Unknown action requested.')
                ]));
        }
    }

    /**
     * Whether the sole trader toggle applies for a billing country
     * (TWO-24755). Runs live as the buyer edits the address form (before any
     * invoice address is necessarily saved on the cart), so the country is
     * whatever the buyer currently has selected - this endpoint only decides
     * whether to SHOW the toggle, not anything security-bearing.
     */
    public function ajaxProcessSoleTraderAvailability()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Invalid token')]));
            return;
        }
        $country = (string) Tools::getValue('country');
        $available = TwoSoleTrader::isAvailable($this->module, $country);
        // Persist the registry answer this lookup may have just cached BEFORE the
        // response ends the request (TWO-25326). The payment tile renders the
        // toggle from that cookie and never resolves it itself, so this write is
        // what makes the server-rendered toggle exist at all. PrestaShop's Cookie
        // writes itself from its destructor, which does run on the exit() below,
        // but only while headers are still unsent - i.e. contingent on output
        // buffering, an ini setting this endpoint should not depend on.
        if ($this->context->cookie) {
            $this->context->cookie->write();
        }
        $this->sendJsonResponse(json_encode([
            'success' => true,
            'available' => $available,
        ]));
    }

    /**
     * Mint the delegation + autofill tokens for the sole-trader flow
     * (TWO-24755). The merchant API key stays server-side; tokens are scoped
     * and short-lived by the Two API.
     *
     * The one authorisation gate on minting is TwoSoleTrader::isAvailable() -
     * re-evaluated SERVER-SIDE on every call, never taken from the browser, and
     * a country that does not resolve at all is refused rather than defaulted.
     * That is what stops this endpoint being a token oracle where the flow is
     * off or the country ineligible.
     *
     * A posted country is preferred over the cart's (TWO-40) and grants no
     * privilege: mintTokens() takes no country, its delegation scopes are
     * fixed, so a spoofed country only ever permits minting in a country the
     * registry already supports sole traders in - which the browser can learn
     * anyway from the `soleTraderAvailability` action.
     *
     * A posted country has to be usable at all because on the checkout
     * address-editor page the cart usually has NO invoice address yet, which is
     * precisely when the buyer clicks "I'm a sole trader".
     */
    public function ajaxProcessSoleTraderTokens()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Invalid token')]));
            return;
        }
        if (!$this->isPost()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Only POST requests allowed')]));
            return;
        }
        $countryIso = $this->resolveSoleTraderCountryIso();
        if ($countryIso === '') {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Could not determine the billing country for this order')]));
            return;
        }
        if (!TwoSoleTrader::isAvailable($this->module, $countryIso)) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Sole trader checkout is not available')]));
            return;
        }
        $tokens = TwoSoleTrader::mintTokens($this->module);
        if ($tokens === null) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Could not initialise the sole trader flow')]));
            return;
        }
        $this->sendJsonResponse(json_encode([
            'success' => true,
            'delegation_token' => $tokens['delegation_token'],
            'autofill_token' => $tokens['autofill_token'],
            'signup_url' => TwoSoleTrader::getSignupPageUrl(),
            // The JS must use THIS, not a DOM guess, when it later saves the
            // enrolled company - getTwoValidatedSessionCompanyData() wipes the
            // session company on any country mismatch.
            'country' => $countryIso,
        ]));
    }

    /**
     * The billing country the sole-trader gate is evaluated against (TWO-40).
     * See ajaxProcessSoleTraderTokens() for why preferring what the request
     * carries is not a privilege escalation.
     *
     * A posted country wins outright: it is the buyer's live selection, the
     * only source describing the address this order will be billed to as it
     * stands right now. The cart's delivery address is a last resort for a
     * request that carried no usable country at all (an older cached script, a
     * stripped body). The cart's INVOICE address is deliberately not consulted
     * at any tier - a committed invoice address is precisely the stale value
     * this ordering rules out.
     *
     * @return string ISO-3166-1 alpha-2 code, or '' when nothing resolves -
     *                which the caller refuses on, rather than defaulting
     */
    private function resolveSoleTraderCountryIso()
    {
        $posted = Tools::strtoupper(trim((string) Tools::getValue('country')));
        if (preg_match('/^[A-Z]{2}$/', $posted)) {
            return $posted;
        }

        $cart = $this->context->cart;
        if ($cart && (int) $cart->id_address_delivery > 0) {
            return $this->addressCountryIso((int) $cart->id_address_delivery);
        }

        return '';
    }

    /**
     * @param int $idAddress
     *
     * @return string the address's country ISO code, or '' if the address does
     *                not load or its country has no ISO code
     */
    private function addressCountryIso($idAddress)
    {
        $address = new Address((int) $idAddress);
        if (!Validate::isLoadedObject($address)) {
            return '';
        }

        return (string) Country::getIsoById((int) $address->id_country);
    }

    public function ajaxProcessSavePaymentTerm()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Invalid token')]));
            return;
        }
        if (!$this->isPost()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Only POST requests allowed')]));
            return;
        }
        $days = (int)Tools::getValue('days');
        if ($days <= 0) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Invalid days')]));
            return;
        }
        $this->context->cookie->two_payment_term = $days;
        $this->context->cookie->setExpire(time() + Twopayment::COOKIE_EXPIRY_ONE_HOUR);
        PrestaShopLogger::addLog('TwoPayment: Saved selected payment term ' . $days . ' days in cookie', 1);
        $this->sendJsonResponse(json_encode(['success' => true]));
    }

    /**
     * Live per-term buyer surcharge amounts for the checkout term chips.
     * Fail-soft: any failure yields {success:false} and the frontend keeps its
     * static rate preview (always a 200 JSON response, never breaks checkout).
     */
    public function ajaxProcessFetchTermSurcharges()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Invalid token')]));
            return;
        }
        $this->sendJsonResponse(json_encode($this->module->getTwoOfferedTermSurchargeAmounts()));
    }

    /**
     * Reconcile the cart's hidden surcharge line with the buyer's payment
     * selection. Idempotent by contract of
     * Twopayment::syncTwoSurchargeCartLine, so re-clicks, reloads and re-fired
     * change events can never stack duplicate lines. Fail-soft: always answers
     * 200 JSON (the create-time parity gate remains the hard guarantee).
     */
    public function ajaxProcessSyncSurchargeLine()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Invalid token')]));
            return;
        }
        if (!$this->isPost()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Only POST requests allowed')]));
            return;
        }
        $selected = (int) Tools::getValue('selected') === 1;
        // The checkout JS sends a monotonically increasing sequence number so a
        // slower, older request (rapid method switches) cannot overwrite a newer
        // one server-side. Absent/invalid seq (legacy cached JS) falls back to
        // unguarded behaviour.
        $seqRaw = Tools::getValue('seq');
        $syncSeq = (is_numeric($seqRaw) && (float) $seqRaw > 0) ? (int) $seqRaw : null;
        $result = $this->module->syncTwoSurchargeCartLine($this->context->cart, $selected, $syncSeq);
        $this->sendJsonResponse(json_encode($result));
    }

    public function ajaxProcessSaveCompany()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Invalid token')]));
            return;
        }

        $company = trim(Tools::getValue('company', ''));
        $companyId = trim(Tools::getValue('companyid', ''));
        $country = trim(Tools::getValue('country', ''));
        // TWO-25503: an explicit `0` is the browser stating that the address this
        // selection belongs to does not exist yet - a billing address still being
        // typed. Substituting the cart's address there stamps the selection with
        // the address it is NOT for (the shipping one), and every address-switch
        // guard then throws the selection away at the payment step. Only a
        // MISSING parameter (older cached JS) takes the cart fallback.
        $addressIdRaw = Tools::getValue('id_address', false);
        $addressId = (int) $addressIdRaw;
        if ($addressIdRaw === false && Validate::isLoadedObject($this->context->cart)) {
            $addressId = (int) $this->context->cart->id_address_invoice;
            if ($addressId <= 0) {
                $addressId = (int) $this->context->cart->id_address_delivery;
            }
        }

        if (empty($company) || empty($companyId)) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Missing company data')]));
            return;
        }

        $fields = ['name' => $company, 'id' => $companyId];
        if (!empty($country)) {
            $fields['country'] = $country;
        }
        // Written even as '0' (TWO-25503): this record is a NEW selection, so
        // leaving the key out would let the PREVIOUS selection's address stamp
        // survive beside it and get compared against the buyer's current one.
        $fields['address_id'] = (string) $addressId;
        $this->module->storeTwoCartScopedCompany($fields);
        $this->context->cookie->setExpire(time() + Twopayment::COOKIE_EXPIRY_ONE_HOUR);
        PrestaShopLogger::addLog('TwoPayment: Saved company in cookie for session', 1);
        $this->sendJsonResponse(json_encode(['success' => true]));
    }

    /**
     * Forget the session company (TWO-25288).
     *
     * Its own action rather than a `saveCompany` carrying empty values, because
     * that action rejects an empty company or company id up front.
     *
     * Needed because the session company is the FIRST thing the order payload and
     * the order-intent handler consult, ahead of the address, and it is otherwise
     * discarded only on a country mismatch or an address switch - never on the
     * buyer changing the company name. A buyer who declares their company is not
     * in the register and types a different name would otherwise have the
     * previously selected company credit-checked at placement.
     *
     * Every key `saveCompany` writes is unset here, including the country and
     * address markers: leaving a marker behind with no company is a half-record
     * state whose interpretation differs between the two readers of this cookie.
     */
    public function ajaxProcessClearCompany()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => $this->module->l('Invalid token')
            ]));
            return;
        }

        $this->module->clearTwoCartScopedCompany();

        PrestaShopLogger::addLog('TwoPayment: Session company cleared for manual company entry', 1);

        $this->sendJsonResponse(json_encode([
            'success' => true
        ]));
    }

    /**
     * Record what the mirror has just written into the secondary address, so the
     * next page load can still tell those values apart from ones the buyer typed
     * (TWO-40).
     *
     * A field the body does not carry is left exactly as it was, so the browser
     * can report one field's write without republishing the rest. An EMPTY string
     * is a real value here, not an omission: it is how the browser disowns a
     * value it has just cleared out of the form.
     */
    public function ajaxProcessSaveMirrorWrites()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => $this->module->l('Invalid token')
            ]));
            return;
        }
        if (!$this->isPost()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => $this->module->l('Only POST requests allowed')
            ]));
            return;
        }

        $fields = [];
        foreach (array_keys(Twopayment::MIRROR_WRITE_SESSION_KEYS) as $field) {
            $posted = Tools::getValue($field, null);
            if ($posted === null || $posted === false) {
                continue;
            }
            $value = trim((string) $posted);
            $fields[$field] = ($field === 'country') ? Tools::strtoupper($value) : $value;
        }

        if (empty($fields)) {
            // A machine code rather than a translated sentence: this endpoint's
            // body is never rendered to a buyer.
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => 'no_mirrored_values'
            ]));
            return;
        }

        $this->module->storeTwoCartScopedMirrorWrites($fields);
        // Explicit write, for the same reason ajaxProcessSoleTraderAvailability()
        // does it: the destructor-driven write is contingent on headers still being
        // unsent, which depends on output buffering rather than on this endpoint.
        if ($this->context->cookie) {
            $this->context->cookie->write();
        }

        $this->sendJsonResponse(json_encode(['success' => true]));
    }

    public function ajaxProcessGetCompany()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Invalid token')]));
            return;
        }
        $stored = $this->module->readTwoCartScopedCompany();
        $company = $stored !== null ? $stored['name'] : '';
        $companyId = $stored !== null ? $stored['id'] : '';
        $companyCountry = $stored !== null ? $stored['country'] : '';
        $companyAddressId = $stored !== null ? (int) $stored['address_id'] : 0;
        $this->sendJsonResponse(json_encode([
            'success' => true,
            'company' => $company,
            'companyid' => $companyId,
            'country' => $companyCountry,
            'address_id' => $companyAddressId
        ]));
    }

    public function initContent()
    {

        if (!Tools::getValue('ajax')) {
            exit;
        }
        
        parent::initContent();
    }


    public function ajaxProcessCheckOrderIntent()
    {
        // Order intent pre-approval preview toggle (TWO-25386 #8). Server-side
        // hard gate, defense-in-depth alongside the client-side
        // shouldRunOrderIntent() check in TwoOrderIntent.js. Never touches
        // Twopayment::checkTwoOrderIntentApprovalAtPayment() - the authoritative
        // approval check at payment submission always runs regardless.
        if (!$this->module->isTwoOrderIntentPreviewEnabled()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'status' => 'order_intent_disabled',
                'error' => $this->module->l('Order intent preview is disabled by the merchant configuration.')
            ]));
            return;
        }

        if (!$this->checkRateLimit()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => $this->module->l('Too many requests. Please wait and try again.')
            ]));
            return;
        }

        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => $this->module->l('Invalid token')
            ]));
            return;
        }

        if (!$this->isPost()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => $this->module->l('Only POST requests allowed')
            ]));
            return;
        }



        $cart = $this->context->cart;
        $customer = new Customer($cart->id_customer);
        $currency = new Currency($cart->id_currency);
        
        // Invoice/billing address is the authoritative company identity source.
        $addressId = (int)Tools::getValue('id_address_invoice');
        if (empty($addressId)) {
            // Older clients may still send delivery id only.
            $addressId = (int)Tools::getValue('id_address_delivery');
        }
        if (empty($addressId)) {
            $addressId = $cart->id_address_invoice ?: $cart->id_address_delivery;
        }

        $address = new Address($addressId);


        if ($cart->id_customer == 0 || !Validate::isLoadedObject($customer) || !Validate::isLoadedObject($address)) {
            PrestaShopLogger::addLog('TwoPayment: Invalid cart, customer, or address data in order intent (address ID: ' . $addressId . ')', 3);
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => $this->module->l('Invalid cart or customer data')
            ]));
            return;
        }

        // Buyer-country gate (TWO-40): no point building an intent payload for
        // an order the payment submit will refuse on the same cart.
        if (!$this->module->isTwoBuyerCountrySupported($cart)) {
            PrestaShopLogger::addLog(
                'TwoPayment: Order intent refused - unsupported buyer country ('
                . $this->module->describeTwoBuyerCountryRefusal($cart) . ')',
                2
            );
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'status' => 'buyer_country_not_supported',
                'error' => $this->module->l('This payment method is not available.')
            ]));
            return;
        }

        $companyData = $this->getCompanyDataWithFallbacks();
        $companyName = $companyData['company'];
        $companyId = $companyData['companyid'];

        $this->storeCompanyDataInSession($companyData);

        // An org number is the business identity Two resolves against the
        // company registry, and it resolves the company NAME from that registry
        // too - overwriting whatever the plugin sent. So an org number without a
        // local company name is a complete, usable identity and must not be
        // blocked here (TWO-25206); the payload path has always sent it that way.
        if (empty($companyId)) {
            if (empty($companyName)) {
                PrestaShopLogger::addLog('TwoPayment: No company name provided - prompting user', 2);
                $this->sendJsonResponse(json_encode([
                    'success' => false,
                    'status' => 'no_company',
                    'error' => sprintf($this->module->l('To pay with %s, go back to your billing address and enter your company name in the Company field.'), $this->module->getTwoBrandConfig('product_name'))
                ]));
                return;
            }

            PrestaShopLogger::addLog('TwoPayment: Company name exists but no org number - prompting user to search', 2);
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'status' => 'incomplete_company',
                'error' => sprintf($this->module->l('To pay with %s, go back to your billing address and search for your company name. Select your company from the results to verify your business.'), $this->module->getTwoBrandConfig('product_name'))
            ]));
            return;
        }

        // An org number is the business guard (TWO-24755): enrolled sole traders
        // carry the synthetic org number their Two registration minted, so they
        // arrive here as a valid business - there is no account-type selector to
        // also check. Whether the org number is real is Two's call, made on the
        // order-intent request itself (TWO-25206).

        try {
            $address->company = $companyName;
            $address->companyid = $companyId;

            $paymentdata = $this->module->getTwoIntentOrderData($cart, $customer, $currency, $address);

            // TWO-24799: snapshot-dedupe the UX-only intent check. Every checkout
            // update re-runs this handler and the browser then pays a 2.5-3s
            // /v1/order_intent round trip even when no decision input moved. Any
            // cart, address, country or company change yields a different hash
            // and the call happens for real.
            //
            // A UX cache only: the authoritative gate remains
            // Twopayment::checkTwoOrderIntentApprovalAtPayment() at payment
            // submit, which is never served from here.
            $snapshotHash = $this->module->calculateTwoOrderIntentSnapshotHash($cart, $paymentdata);
            $cachedDecision = $this->module->getTwoCachedOrderIntentDecision($snapshotHash);
            $this->module->markTwoPendingOrderIntentSnapshot($snapshotHash);

            $response = [
                'success' => true,
                'payload' => $paymentdata,
            ];

            if ($cachedDecision !== null) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Reused cached order intent decision for unchanged snapshot (approved=' .
                    ($cachedDecision['approved'] ? '1' : '0') . ')',
                    1
                );
                $response['intent_decision'] = [
                    'approved' => (bool) $cachedDecision['approved'],
                    'timestamp' => (int) $cachedDecision['timestamp'],
                ];
            }

            $this->context->cookie->write();

            // Payload only - the frontend calls the Two API directly.
            $this->sendJsonResponse(json_encode($response));
            return;
        } catch (Exception $e) {
            PrestaShopLogger::addLog('TwoPayment: Build order intent payload exception - ' . $e->getMessage(), 3);
            
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => $this->module->l('Failed to build order intent payload')
            ]));
            return;
        }
    }

    public function ajaxProcessBuildPayload()
    {
        $this->ajaxProcessCheckOrderIntent();
    }

    /**
     * Save frontend order intent result in session as telemetry only.
     * Authoritative approval is revalidated server-side on payment submit.
     */
    public function ajaxProcessSaveOrderIntentResult()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => $this->module->l('Invalid token')
            ]));
            return;
        }

        $approved = (bool)Tools::getValue('approved');
        $timestamp = time();

        $this->context->cookie->two_order_intent_approved = $approved ? '1' : '0';
        $this->context->cookie->two_order_intent_timestamp = (string)$timestamp;

        // TWO-24799: binds to the snapshot hash the server computed when it handed
        // this browser the payload. The hash is never taken from the request.
        $this->module->storeTwoOrderIntentDecisionForPendingSnapshot($approved);

        $this->context->cookie->write();

        PrestaShopLogger::addLog('TwoPayment: Order intent telemetry saved in session', 1);

        $this->sendJsonResponse(json_encode([
            'success' => true,
            'approved' => $approved,
            'timestamp' => $timestamp
        ]));
    }

    /**
     * Called when the buyer switches away from the Two payment method.
     */
    public function ajaxProcessClearOrderIntentResult()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => $this->module->l('Invalid token')
            ]));
            return;
        }

        // Clear order intent result from cookie
        unset($this->context->cookie->two_order_intent_approved);
        unset($this->context->cookie->two_order_intent_timestamp);
        // TWO-24799: the buyer switching away from Two is an explicit reset, so
        // the deduped decision goes with it - a later switch back re-checks for
        // real rather than reviving a decision the buyer never sees confirmed.
        $this->module->clearTwoCachedOrderIntentDecision();
        $this->context->cookie->write();

        PrestaShopLogger::addLog('TwoPayment: Order intent result cleared from session', 1);

        $this->sendJsonResponse(json_encode([
            'success' => true
        ]));
    }

    /**
     * Helper method to check if request is POST
     */
    private function isPost()
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    /**
     * Rate limiting check - prevent abuse of order intent API
     * Max 5 requests per minute per checkout cookie session
     */
    private function checkRateLimit()
    {
        $current_time = time();
        $rate_limit_window = 60;
        $max_requests = 5; // Production rate limit

        $request_data = array();
        $encoded = isset($this->context->cookie->two_order_intent_rate_limit) ? (string)$this->context->cookie->two_order_intent_rate_limit : '';
        if (!Tools::isEmpty($encoded)) {
            $decoded = json_decode($encoded, true);
            if (is_array($decoded)) {
                foreach ($decoded as $timestamp) {
                    $timestamp = (int)$timestamp;
                    if ($timestamp > 0 && ($current_time - $timestamp) < $rate_limit_window) {
                        $request_data[] = $timestamp;
                    }
                }
            }
        }

        // Check if we're over the limit
        if (count($request_data) >= $max_requests) {
            return false;
        }

        // Add current request
        $request_data[] = $current_time;
        $this->context->cookie->two_order_intent_rate_limit = json_encode(array_values($request_data));
        $this->context->cookie->write();

        return true;
    }

    /**
     * Helper method to validate AJAX token.
     *
     * DEBUG ESCAPE HATCH (TWO-25386 #4, ported from woocommerce-plugin's
     * `skip_confirm_auth`): PS_TWO_SKIP_CONFIRM_TOKEN_CHECK, when enabled,
     * skips this token check entirely on every action on this controller.
     * Default OFF - matches the pre-existing always-checked behaviour.
     */
    /**
     * Company-name search, relayed server-side so the firewall token stays out
     * of the browser. Two's status and body are passed through untouched:
     * callers read `error_code`/`error_message` off the failing response.
     */
    public function ajaxProcessCompanySearch()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Invalid token')]));
            return;
        }

        $query = array(
            'q' => (string) Tools::getValue('q'),
            'limit' => (int) Tools::getValue('limit'),
            'offset' => (int) Tools::getValue('offset'),
            'country' => (string) Tools::getValue('country'),
        );
        if ($query['q'] === '' || $query['country'] === '') {
            $this->relayTwoApiResponse(array('http_status' => 400, 'data' => array(
                'error_code' => 'INVALID_REQUEST',
            )));
            return;
        }
        if ($query['limit'] < 1) {
            unset($query['limit']);
        }

        $this->relayTwoApiResponse($this->module->setTwoPaymentRequest(
            '/companies/v2/company?' . http_build_query($query),
            array(),
            'GET'
        ));
    }

    /**
     * Company detail lookup, relayed server-side. See ajaxProcessCompanySearch().
     */
    public function ajaxProcessCompanyDetails()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Invalid token')]));
            return;
        }

        $lookupId = trim((string) Tools::getValue('lookup_id'));
        // Path segment, so anything outside the registry id charset is refused
        // rather than escaped into the URL.
        if ($lookupId === '' || !preg_match('/^[A-Za-z0-9._:-]+$/', $lookupId)) {
            $this->relayTwoApiResponse(array('http_status' => 400, 'data' => array(
                'error_code' => 'INVALID_REQUEST',
            )));
            return;
        }

        $this->relayTwoApiResponse($this->module->setTwoPaymentRequest(
            '/companies/v2/company/' . rawurlencode($lookupId),
            array(),
            'GET'
        ));
    }

    /**
     * Order-intent decision, relayed server-side. See ajaxProcessCompanySearch().
     */
    public function ajaxProcessOrderIntent()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Invalid token')]));
            return;
        }

        $payload = json_decode((string) Tools::getValue('payload'), true);
        if (!is_array($payload)) {
            $this->relayTwoApiResponse(array('http_status' => 400, 'data' => array(
                'error_code' => 'INVALID_REQUEST',
            )));
            return;
        }

        $this->relayTwoApiResponse($this->module->setTwoPaymentRequest('/v1/order_intent', $payload, 'POST'));
    }

    /**
     * Pass a setTwoPaymentRequest() result back to the browser with Two's own
     * status code, so existing client-side success/error branches still apply.
     */
    private function relayTwoApiResponse($result)
    {
        $status = is_array($result) && isset($result['http_status']) ? (int) $result['http_status'] : 0;
        // A transport failure has no HTTP status of its own; 502 keeps it on
        // the client's error branch instead of looking like an empty success.
        if ($status < 100) {
            $status = 502;
        }
        $body = is_array($result) && isset($result['data']) && is_array($result['data']) ? $result['data'] : array();

        http_response_code($status);
        $this->sendJsonResponse(json_encode($body));
    }

    public function validateAjaxToken()
    {
        if ($this->module->isTwoSkipConfirmTokenCheckEnabled()) {
            return true;
        }

        $token = Tools::getValue('token');
        if (empty($token)) {
            return false;
        }

        // Validate token format (should be alphanumeric)
        if (!preg_match('/^[a-zA-Z0-9]+$/', $token)) {
            return false;
        }

        return $token === Tools::getToken(false);
    }

    /**
     * Send AJAX JSON response and exit
     */
    public function sendJsonResponse($content)
    {
        // Ensure valid JSON; if invalid, wrap in error envelope
        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $content = json_encode(['success' => false, 'error' => 'Invalid response format']);
        }
        // Send as JSON consistently (avoid empty responses on some PS versions)
        header('Content-Type: application/json; charset=utf-8');
        if (method_exists($this, 'ajaxDie')) {
            $this->ajaxDie($content);
        }
        echo $content;
        exit;
    }

    /**
     * Get company data using PrestaShop-native fallback chain
     * Priority: Form data → Session → Address fields → Partial session → Partial form
     *
     * When a logged-in user checks out against an existing address, an organization
     * number stored in that address (dni, companyid - vat_number is deliberately not a source) is taken as-is and
     * handed to Two, which validates its format and resolves it against the company
     * registry on the order-intent request (TWO-25206). This module makes no company
     * lookup of its own on this path.
     */
    private function getCompanyDataWithFallbacks()
    {
        // Priority 1: Form data (highest priority - direct user input from company search)
        $company = trim(Tools::getValue('company', ''));
        $companyId = trim(Tools::getValue('companyid', ''));
        
        if (!empty($company) && !empty($companyId)) {
            PrestaShopLogger::addLog('TwoPayment: Company data retrieved from form data', 1);
            return ['company' => $company, 'companyid' => $companyId];
        }
        
        // Resolve selected checkout address first (prefer request-provided delivery address).
        $selectedAddressId = (int) Tools::getValue('id_address_invoice');
        if ($selectedAddressId <= 0) {
            $selectedAddressId = (int) Tools::getValue('id_address_delivery');
        }
        if ($selectedAddressId <= 0) {
            $selectedAddressId = (int) $this->context->cart->id_address_invoice;
        }
        if ($selectedAddressId <= 0) {
            $selectedAddressId = (int) $this->context->cart->id_address_delivery;
        }

        // Priority 2: PrestaShop session/cookie (persisted from previous steps or company search)
        // Validate session company country against the current selected address country.
        $currentCountryIso = '';
        if ($selectedAddressId > 0) {
            $selectedAddress = new Address($selectedAddressId);
            if (Validate::isLoadedObject($selectedAddress)) {
                $countryIsoCandidate = Country::getIsoById($selectedAddress->id_country);
                if ($countryIsoCandidate && is_string($countryIsoCandidate)) {
                    $currentCountryIso = $countryIsoCandidate;
                }
            }
        }
        $validatedSession = $this->module->getTwoValidatedSessionCompanyData($currentCountryIso, $selectedAddressId);
        $sessionCompany = isset($validatedSession['company_name']) ? trim($validatedSession['company_name']) : '';
        $sessionCompanyId = isset($validatedSession['organization_number']) ? trim($validatedSession['organization_number']) : '';
        $storedCompany = $this->module->readTwoCartScopedCompany();
        $sessionAddressId = ($storedCompany !== null && $storedCompany['address_id'] !== '')
            ? (int) $storedCompany['address_id']
            : 0;

        if ($sessionAddressId > 0 && $selectedAddressId > 0 && $sessionAddressId !== $selectedAddressId) {
            PrestaShopLogger::addLog(
                'TwoPayment: Ignoring session company due to address switch in order intent. Session address=' .
                $sessionAddressId . ', selected address=' . $selectedAddressId,
                2
            );
            $sessionCompany = '';
            $sessionCompanyId = '';
        }
        
        if (!empty($sessionCompany) && !empty($sessionCompanyId)) {
            PrestaShopLogger::addLog('TwoPayment: Company data retrieved from PrestaShop session (complete)', 1);
            return [
                'company' => $sessionCompany,
                'companyid' => $sessionCompanyId
            ];
        }
        
        // Priority 3: Org number stored on the customer's saved address, used AS-IS.
        //
        // TWO-25206: the plugin used to pre-verify this org number against
        // /companies/v2/company before letting it through. That call was
        // redundant and could block buyers Two accepts:
        //  - Two verifies the org number synchronously on the very same
        //    /v1/order_intent request this handler builds the payload for -
        //    per-country format and checksum validation, then an exact
        //    by-org-number registry lookup - and rejects the intent with
        //    COMPANY_NOT_FOUND when it does not resolve.
        //  - Two also overwrites the company name from the registry, so any
        //    name resolved here was discarded and re-derived anyway.
        //  - The pre-check used the FUZZY search endpoint while order intent
        //    uses the exact one, so a resolvable company could fail the
        //    pre-check and hard-block the buyer; a slow provider was
        //    indistinguishable from "company does not exist"; and a single
        //    fuzzy hit was accepted even when its org number differed from the
        //    one searched, caching another company as the buyer's identity.
        //
        // The payload path (Twopayment::getCompanyDataWithFallbacks()) has
        // always sent this org number unverified, so the pre-check never
        // guarded the payload - only its own prompt.
        $address = new Address($selectedAddressId);
        if (Validate::isLoadedObject($address)) {
            $countryIso = Country::getIsoById($address->id_country);

            if ($countryIso && is_string($countryIso)) {
                // Look for an organization number in dni or companyid (vat_number is deliberately not a source).
                $existingOrgNumber = $this->module->extractOrgNumberFromAddress($address, $countryIso);

                if (!empty($existingOrgNumber)) {
                    PrestaShopLogger::addLog(
                        'TwoPayment: Using org number from address for ' . $countryIso .
                        ' unverified - Two verifies it on order intent',
                        1
                    );

                    // Deliberately NOT cached in the two_company_* cookie: that
                    // cookie holds company data verified by the company search,
                    // and this org number has not been verified by anything yet.
                    return [
                        'company' => trim((string) $address->company),
                        'companyid' => $existingOrgNumber
                    ];
                }

                // FALLBACK: Address has company name but no org number in any field
                // User will need to use company search to select their company
                if (!empty($address->company)) {
                    PrestaShopLogger::addLog(
                        'TwoPayment: Address has company name but no org number found in fields - ' .
                        'company: "' . $address->company . '" in ' . $countryIso,
                        1
                    );
                    
                    return [
                        'company' => trim($address->company),
                        'companyid' => '' // User needs to search and select
                    ];
                }
            }
        }
        
        // Priority 4: Partial session data (company name without org number)
        if (!empty($sessionCompany) && empty($sessionCompanyId)) {
            PrestaShopLogger::addLog(
                'TwoPayment: Session has company name but no org number - user needs to search',
                1
            );
            return [
                'company' => $sessionCompany,
                'companyid' => ''
            ];
        }
        
        // Priority 5: Any partial form data
        if (!empty($company) || !empty($companyId)) {
            PrestaShopLogger::addLog('TwoPayment: Partial company data found - company: "' . $company . '", companyid: "' . $companyId . '"', 2);
            return ['company' => $company, 'companyid' => $companyId];
        }
        
        // No data found
        PrestaShopLogger::addLog('TwoPayment: No company data found in any source', 2);
        return ['company' => '', 'companyid' => ''];
    }
    
    /**
     * Store company data in PrestaShop session for persistence across checkout steps
     */
    private function storeCompanyDataInSession($companyData)
    {
        if (!empty($companyData['company'])) {
            // TWO-25503: a name-only result is a FAILED resolution, never a
            // buyer decision - the resolver reaches it when a guard declined the
            // stored record, which is exactly when that record is still the only
            // copy of the organisation number. Persisting it here destroyed that
            // number for the rest of the checkout, so no later re-check could
            // recover it. Disowning a company has its own action (clearCompany).
            $stored = $this->module->readTwoCartScopedCompany();
            if (trim((string) ($companyData['companyid'] ?? '')) === ''
                && $stored !== null
                && trim((string) $stored['id']) !== ''
            ) {
                return;
            }

            $fields = [
                'name' => $companyData['company'],
                'id' => $companyData['companyid'] ?? '',
            ];

            $addressId = (int) Tools::getValue('id_address_invoice');
            if ($addressId <= 0) {
                $addressId = (int) Tools::getValue('id_address_delivery');
            }
            if ($addressId <= 0) {
                $addressId = (int) $this->context->cart->id_address_invoice;
            }
            if ($addressId <= 0) {
                $addressId = (int) $this->context->cart->id_address_delivery;
            }

            if ($addressId > 0) {
                $selectedAddress = new Address($addressId);
                if (Validate::isLoadedObject($selectedAddress)) {
                    $countryIso = Country::getIsoById($selectedAddress->id_country);
                    if ($countryIso && is_string($countryIso)) {
                        $fields['country'] = strtoupper($countryIso);
                    }
                    $fields['address_id'] = (string) $addressId;
                }
            }

            $this->module->storeTwoCartScopedCompany($fields);

            // Set cookie expiration (1 hour)
            $this->context->cookie->setExpire(time() + Twopayment::COOKIE_EXPIRY_ONE_HOUR);
            
            PrestaShopLogger::addLog('TwoPayment: Company data stored in PrestaShop session', 1);
        }
    }
}
