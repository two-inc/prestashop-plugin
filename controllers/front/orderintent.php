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

    /**
     * Basic initialization - called for any request to this controller
     */
    public function init()
    {
        parent::init();
    }

    /**
     * Post processing - another fallback for AJAX handling
     */
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
            default:
                $this->sendJsonResponse(json_encode([
                    'success' => false,
                    'error' => $this->module->l('Unknown action requested.')
                ]));
        }
    }

    /**
     * Whether the sole trader toggle applies for a billing country
     * (TWO-24755). Combines the registry endpoint's country answer with
     * the merchant toggle, both server-side; JS only renders the result.
     * Runs live as the buyer edits the address form (before any invoice
     * address is necessarily saved on the cart), so the country is
     * whatever the buyer currently has selected - this endpoint only
     * decides whether to SHOW the toggle, not anything security-bearing.
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
        // response ends the request (TWO-25326 round 4 review). The payment tile
        // renders the toggle from that cookie and never resolves it itself, so
        // this write is what makes the server-rendered toggle exist at all - it is
        // not a tidy-up. PrestaShop's Cookie writes itself from its destructor,
        // which does run on the exit() below, but only while headers are still
        // unsent - i.e. contingent on output buffering, which is an ini setting
        // and not something this endpoint should depend on.
        //
        // Several other cookie-mutating actions here already write explicitly, so
        // this is the established pattern rather than a new one - though not all of
        // them do, and some only on part of their paths. Those are the same latent
        // dependence, noted rather than changed because none of them is what this
        // ticket is about and none has ever been reported failing. Deliberately not
        // enumerated: a list of which actions do and do not is exactly the comment
        // that goes stale, and has already been wrong twice on this branch.
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
     * (TWO-24755) and hand the browser what it needs to open the hosted
     * signup popup and autofill the buyer. The merchant API key stays
     * server-side; tokens are scoped and short-lived by the Two API.
     *
     * The one authorisation gate on minting is
     * TwoSoleTrader::isAvailable($this->module, $iso) - the registry's answer
     * for a billing country, re-evaluated SERVER-SIDE on every call. It is
     * never taken from the browser, and a country that does not resolve at
     * all is refused rather than defaulted. That is what stops this endpoint
     * being a token oracle where the flow is off or the country ineligible.
     *
     * What the country is resolved FROM is a trust ordering, not a security
     * boundary (TWO-40): the cart's invoice address first, then a posted
     * `country`, then the cart's delivery address. Accepting a posted country
     * in the middle tier grants no privilege at all:
     *
     *  - the mint itself takes no country - the tokens are country-independent,
     *    so there is no per-country capability to escalate into;
     *  - the registry check still runs here, on the server, so a spoofed
     *    country only ever permits minting in a country the registry ALREADY
     *    supports sole traders in;
     *  - and the browser can already learn exactly that set for any country it
     *    likes from the `soleTraderAvailability` action, which answers a
     *    client-supplied country by design.
     *
     * So the posted value can move the answer from "unresolved" to "the
     * registry's own answer for some real country", and nothing else. The
     * other Two plugins' equivalent handlers resolve it the same way.
     *
     * Why the middle tier has to exist: on the checkout address-editor page
     * the cart usually has NO invoice address yet, which is precisely when the
     * buyer clicks "I'm a sole trader". Requiring one refused every single
     * one of those attempts, and the browser has no place to show that error
     * on that page, so the entry point simply dead-ended in silence.
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
            // The country this mint was authorised against, as resolved
            // server-side by resolveSoleTraderCountryIso(): the JS must use
            // THIS, not a DOM guess, when it later saves the enrolled
            // company - getTwoValidatedSessionCompanyData() wipes the
            // session company on any country mismatch.
            'country' => $countryIso,
        ]));
    }

    /**
     * The billing country the sole-trader gate is evaluated against, from the
     * most trustworthy source available (TWO-40). See
     * ajaxProcessSoleTraderTokens() for why the middle tier is not a privilege
     * escalation.
     *
     * Ordering:
     *   1. the cart's invoice address - a value the buyer already committed to
     *      the cart, and never overridden by anything the request carries;
     *   2. a posted `country`, accepted only in exactly the ISO-3166-1 alpha-2
     *      shape (two upper-case letters). This is the address-editor case,
     *      where no invoice address exists yet and the buyer's currently
     *      selected country is the only answer there is;
     *   3. the cart's delivery address, for a POST that carried no usable
     *      country at all (an older cached script, a stripped body).
     *
     * A tier that HAS an address but cannot resolve a country from it (an
     * address row that no longer loads, an id_country with no ISO) falls
     * through to the next tier rather than terminating the search: an
     * unresolvable address is not an answer, and the tiers below it are no
     * less trustworthy than the nothing it produced.
     *
     * @return string ISO-3166-1 alpha-2 code, or '' when nothing resolves -
     *                which the caller refuses on, rather than defaulting
     */
    private function resolveSoleTraderCountryIso()
    {
        $cart = $this->context->cart;

        if ($cart && (int) $cart->id_address_invoice > 0) {
            $iso = $this->addressCountryIso((int) $cart->id_address_invoice);
            if ($iso !== '') {
                return $iso;
            }
        }

        $posted = Tools::strtoupper(trim((string) Tools::getValue('country')));
        if (preg_match('/^[A-Z]{2}$/', $posted)) {
            return $posted;
        }

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

    /**
     * Persist selected payment term (days) into PrestaShop cookie
     */
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
     * Reads nothing from POST beyond the token - the current cart is ambient
     * via the context. Fail-soft: any failure inside the module method yields
     * {success:false} and the frontend keeps its static rate preview (always
     * a 200 JSON response, never breaks checkout).
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
     * selection (selected=1 -> exactly one line at the current quoted fee,
     * selected=0 -> no line). Idempotent by contract of
     * Twopayment::syncTwoSurchargeCartLine: repeat calls with the same
     * selection are no-ops ({changed:false}), so re-clicks, reloads and
     * re-fired change events can never stack duplicate lines. Fail-soft:
     * always answers 200 JSON; a {success:false} tells the JS nothing was
     * reconciled (the create-time parity gate remains the hard guarantee).
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
        // Ordering guard: the checkout JS sends a monotonically increasing
        // sequence number so a slower, older request (rapid method switches)
        // cannot overwrite a newer one server-side. Absent/invalid seq
        // (legacy cached JS) falls back to unguarded behaviour.
        $seqRaw = Tools::getValue('seq');
        $syncSeq = (is_numeric($seqRaw) && (float) $seqRaw > 0) ? (int) $seqRaw : null;
        $result = $this->module->syncTwoSurchargeCartLine($this->context->cart, $selected, $syncSeq);
        $this->sendJsonResponse(json_encode($result));
    }

    /**
     * Persist company data into PrestaShop cookie (no secrets)
     */
    public function ajaxProcessSaveCompany()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => $this->module->l('Invalid token')]));
            return;
        }

        $company = trim(Tools::getValue('company', ''));
        $companyId = trim(Tools::getValue('companyid', ''));
        $country = trim(Tools::getValue('country', ''));
        $addressId = (int) Tools::getValue('id_address', 0);
        if ($addressId <= 0 && Validate::isLoadedObject($this->context->cart)) {
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
        if ($addressId > 0) {
            $fields['address_id'] = (string) $addressId;
        }
        $this->module->storeTwoCartScopedCompany($fields);
        $this->context->cookie->setExpire(time() + Twopayment::COOKIE_EXPIRY_ONE_HOUR);
        PrestaShopLogger::addLog('TwoPayment: Saved company in cookie for session', 1);
        $this->sendJsonResponse(json_encode(['success' => true]));
    }

    /**
     * Forget the session company (TWO-25288).
     *
     * Its own action rather than a `saveCompany` carrying empty values, because
     * that action rejects an empty company or company id up front and answers
     * "missing company data" - so using it to clear would be a silent no-op.
     *
     * Needed because the session company is the FIRST thing the order payload and
     * the order-intent handler consult, ahead of the address, and it is otherwise
     * discarded only on a country mismatch or an address switch - never on the
     * buyer changing the company name. A buyer who declares their company is not
     * in the register and types a different name would otherwise have the
     * previously selected company credit-checked at placement.
     *
     * Every key `saveCompany` writes is unset here, including the country and
     * address markers: leaving a marker behind with no company is the half-record
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
     * Retrieve company data from PrestaShop cookie
     */
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

    /**
     * Handle non-AJAX requests (for testing)
     */
    public function initContent()
    {
        
        // If this is a direct access (not AJAX), return simple response
        if (!Tools::getValue('ajax')) {
            exit;
        }
        
        parent::initContent();
    }


    /**
     * AJAX method for order intent validation
     * Called via: ?ajax=1&action=checkOrderIntent
     */
    public function ajaxProcessCheckOrderIntent()
    {
        // Order intent pre-approval preview toggle (TWO-25386 #8). Server-side
        // hard gate, defense-in-depth alongside the client-side
        // shouldRunOrderIntent() check in TwoOrderIntent.js which normally
        // prevents this call from firing at all when disabled. Never touches
        // Twopayment::checkTwoOrderIntentApprovalAtPayment() - the
        // authoritative approval check at actual payment submission always
        // runs regardless of this setting.
        if (!$this->module->isTwoOrderIntentPreviewEnabled()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'status' => 'order_intent_disabled',
                'error' => $this->module->l('Order intent preview is disabled by the merchant configuration.')
            ]));
            return;
        }

        // Rate limiting protection
        if (!$this->checkRateLimit()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => $this->module->l('Too many requests. Please wait and try again.')
            ]));
            return;
        }

        // Validate AJAX token for security
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => $this->module->l('Invalid token')
            ]));
            return;
        }

        // Only allow POST requests
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
        
        // Use invoice/billing address as authoritative company identity source.
        $addressId = (int)Tools::getValue('id_address_invoice');
        if (empty($addressId)) {
            // Backward compatibility: older clients may still send delivery id only.
            $addressId = (int)Tools::getValue('id_address_delivery');
        }
        if (empty($addressId)) {
            // Fallback to invoice address from cart, then delivery address.
            $addressId = $cart->id_address_invoice ?: $cart->id_address_delivery;
        }
        
        $address = new Address($addressId);
        

        // Validate cart and customer with proper PrestaShop validation
        if ($cart->id_customer == 0 || !Validate::isLoadedObject($customer) || !Validate::isLoadedObject($address)) {
            PrestaShopLogger::addLog('TwoPayment: Invalid cart, customer, or address data in order intent (address ID: ' . $addressId . ')', 3);
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => $this->module->l('Invalid cart or customer data')
            ]));
            return;
        }

        // PRESTASHOP NATIVE APPROACH - Multiple data sources with fallback chain
        $companyData = $this->getCompanyDataWithFallbacks();
        $companyName = $companyData['company'];
        $companyId = $companyData['companyid'];

        // Store company data in PrestaShop session for future use
        $this->storeCompanyDataInSession($companyData);
        
        // ENHANCED VALIDATION: Provide clear status codes for different company data scenarios
        // This allows frontend to show specific guidance to users
        
        // An org number is the business identity Two resolves against the
        // company registry, and it resolves the company NAME from that registry
        // too - overwriting whatever the plugin sent. So an org number without a
        // local company name is a complete, usable identity and must not be
        // blocked here (TWO-25206); the payload path has always sent it that way.
        if (empty($companyId)) {
            // Case 1: No company name and no org number - user hasn't entered company details
            if (empty($companyName)) {
                PrestaShopLogger::addLog('TwoPayment: No company name provided - prompting user', 2);
                $this->sendJsonResponse(json_encode([
                    'success' => false,
                    'status' => 'no_company',
                    'error' => sprintf($this->module->l('To pay with %s, go back to your billing address and enter your company name in the Company field.'), $this->module->getTwoBrandConfig('product_name'))
                ]));
                return;
            }

            // Case 2: Has company name but no org number - common with existing addresses
            PrestaShopLogger::addLog('TwoPayment: Company name exists but no org number - prompting user to search', 2);
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'status' => 'incomplete_company',
                'error' => sprintf($this->module->l('To pay with %s, go back to your billing address and search for your company name. Select your company from the results to verify your business.'), $this->module->getTwoBrandConfig('product_name'))
            ]));
            return;
        }

        // An org number is the business guard (TWO-24755): registered
        // businesses search/select their company, and enrolled sole traders
        // carry the synthetic org number their Two registration minted, so both
        // arrive here as a valid business - there is no account-type selector to
        // also check. Whether the org number is real is Two's call, made on the
        // order-intent request itself (TWO-25206).

        try {
            // Set address with validated form data for API call (form-first approach)
            $address->company = $companyName;
            $address->companyid = $companyId;
            
            // Get order intent data
            $paymentdata = $this->module->getTwoIntentOrderData($cart, $customer, $currency, $address);

            // TWO-24799: snapshot-dedupe the UX-only intent check. Every
            // checkout update (payment-option toggle, surcharge cart-line
            // refresh, payment form re-render) re-runs this handler and the
            // browser then pays a 2.5-3s /v1/order_intent round trip, even when
            // none of the decision inputs moved. Hand the browser back the
            // decision it already got for this exact snapshot so it can skip
            // that call. Any cart, address, country or company change yields a
            // different hash and the call happens for real.
            //
            // This is a UX cache only: the authoritative gate remains
            // Twopayment::checkTwoOrderIntentApprovalAtPayment() at payment
            // submit, which always calls the provider and is never served from
            // here.
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

            // Return payload only (frontend will call Two API directly)
            $this->sendJsonResponse(json_encode($response));
            return;
        } catch (Exception $e) {
            // Log exception for debugging
            PrestaShopLogger::addLog('TwoPayment: Build order intent payload exception - ' . $e->getMessage(), 3);
            
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => $this->module->l('Failed to build order intent payload')
            ]));
            return;
        }
    }

    /**
     * New action that mirrors ajaxProcessCheckOrderIntent behavior: build payload only
     */
    public function ajaxProcessBuildPayload()
    {
        // Reuse the same logic path
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

        // Store in PrestaShop cookie (session-based) for server-side validation
        $this->context->cookie->two_order_intent_approved = $approved ? '1' : '0';
        $this->context->cookie->two_order_intent_timestamp = (string)$timestamp;

        // TWO-24799: bind the reported decision to the snapshot hash the server
        // computed when it handed this browser the payload, so the next
        // checkout update with identical decision inputs can skip the provider
        // round trip. The hash is never taken from the request.
        $this->module->storeTwoOrderIntentDecisionForPendingSnapshot($approved);

        // Write cookie to ensure it's saved
        $this->context->cookie->write();

        PrestaShopLogger::addLog('TwoPayment: Order intent telemetry saved in session', 1);

        $this->sendJsonResponse(json_encode([
            'success' => true,
            'approved' => $approved,
            'timestamp' => $timestamp
        ]));
    }

    /**
     * Clear order intent telemetry from session
     * Called when user switches away from Two payment method
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
     * `skip_confirm_auth`): PS_TWO_SKIP_CONFIRM_NONCE_CHECK, when enabled,
     * skips this token check entirely on every action on this controller.
     * Default OFF - matches the pre-existing always-checked behaviour.
     */
    public function validateAjaxToken()
    {
        if ($this->module->isTwoSkipConfirmNonceCheckEnabled()) {
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
        $validatedSession = $this->module->getTwoValidatedSessionCompanyData($currentCountryIso);
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
