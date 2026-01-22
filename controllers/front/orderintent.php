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
            case 'getCompany':
                $this->ajaxProcessGetCompany();
                break;
            case 'savePaymentTerm':
                $this->ajaxProcessSavePaymentTerm();
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
            default:
                $this->sendJsonResponse(json_encode([
                    'success' => false,
                    'error' => 'Unknown action: ' . $action
                ]));
        }
    }

    /**
     * Persist selected payment term (days) into PrestaShop cookie
     */
    public function ajaxProcessSavePaymentTerm()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => 'Invalid token']));
            return;
        }
        if (!$this->isPost()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => 'Only POST requests allowed']));
            return;
        }
        $days = (int)Tools::getValue('days');
        if ($days <= 0) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => 'Invalid days']));
            return;
        }
        $this->context->cookie->two_payment_term = $days;
        $this->context->cookie->setExpire(time() + Twopayment::COOKIE_EXPIRY_ONE_HOUR);
        PrestaShopLogger::addLog('TwoPayment: Saved selected payment term ' . $days . ' days in cookie', 1);
        $this->sendJsonResponse(json_encode(['success' => true]));
    }

    /**
     * Persist company data into PrestaShop cookie (no secrets)
     */
    public function ajaxProcessSaveCompany()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => 'Invalid token']));
            return;
        }

        $company = trim(Tools::getValue('company', ''));
        $companyId = trim(Tools::getValue('companyid', ''));
        $country = trim(Tools::getValue('country', ''));

        if (empty($company) || empty($companyId)) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => 'Missing company data']));
            return;
        }

        $this->context->cookie->two_company_name = $company;
        $this->context->cookie->two_company_id = $companyId;
        if (!empty($country)) {
            $this->context->cookie->two_company_country = $country;
        }
        $this->context->cookie->setExpire(time() + Twopayment::COOKIE_EXPIRY_ONE_HOUR);
        PrestaShopLogger::addLog('TwoPayment: Saved company in cookie for session', 1);
        $this->sendJsonResponse(json_encode(['success' => true]));
    }

    /**
     * Retrieve company data from PrestaShop cookie
     */
    public function ajaxProcessGetCompany()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode(['success' => false, 'error' => 'Invalid token']));
            return;
        }
        $company = isset($this->context->cookie->two_company_name) ? $this->context->cookie->two_company_name : '';
        $companyId = isset($this->context->cookie->two_company_id) ? $this->context->cookie->two_company_id : '';
        $companyCountry = isset($this->context->cookie->two_company_country) ? $this->context->cookie->two_company_country : '';
        $this->sendJsonResponse(json_encode([
            'success' => true,
            'company' => $company,
            'companyid' => $companyId,
            'country' => $companyCountry
        ]));
    }

    /**
     * Handle non-AJAX requests (for testing)
     */
    public function initContent()
    {
        
        // If this is a direct access (not AJAX), return simple response
        if (!Tools::getValue('ajax')) {
            die;
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
        // Rate limiting protection - max 3 requests per minute per session
        if (!$this->checkRateLimit()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => 'Too many requests. Please wait and try again.'
            ]));
            return;
        }

        // Validate AJAX token for security
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => 'Invalid token'
            ]));
            return;
        }

        // Only allow POST requests
        if (!$this->isPost()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => 'Only POST requests allowed'
            ]));
            return;
        }



        $cart = $this->context->cart;
        $customer = new Customer($cart->id_customer);
        $currency = new Currency($cart->id_currency);
        
        // CRITICAL FIX: Use the address ID that JavaScript sends, not hardcoded invoice address
        $addressId = (int)Tools::getValue('id_address_delivery');
        if (empty($addressId)) {
            // Fallback to delivery address from cart, then invoice address
            $addressId = $cart->id_address_delivery ?: $cart->id_address_invoice;
        }
        
        $address = new Address($addressId);
        

        // Validate cart and customer with proper PrestaShop validation
        if ($cart->id_customer == 0 || !Validate::isLoadedObject($customer) || !Validate::isLoadedObject($address)) {
            PrestaShopLogger::addLog('TwoPayment: Invalid cart, customer, or address data in order intent (address ID: ' . $addressId . ')', 3);
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => 'Invalid cart or customer data'
            ]));
            return;
        }

        // PRESTASHOP NATIVE APPROACH - Multiple data sources with fallback chain
        $companyData = $this->getCompanyDataWithFallbacks();
        $companyName = $companyData['company'];
        $companyId = $companyData['companyid'];
        // Determine account type. When admin disabled account type, treat as business at payment step but relax earlier steps on FE.
        $useAccountType = (int)Configuration::get('PS_TWO_USE_ACCOUNT_TYPE');
        $accountType = $useAccountType ? 'business' : 'business';
        
        // Store company data in PrestaShop session for future use
        $this->storeCompanyDataInSession($companyData);
        
        // ENHANCED VALIDATION: Provide clear status codes for different company data scenarios
        // This allows frontend to show specific guidance to users
        
        // Case 1: No company name at all - user hasn't entered company details
        if (empty($companyName)) {
            PrestaShopLogger::addLog('TwoPayment: No company name provided - prompting user', 2);
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'status' => 'no_company',
                'error' => $this->module->l('To pay with Two, go back to your billing address and enter your company name in the Company field.')
            ]));
            return;
        }
        
        // Case 2: Has company name but no org number - common with existing addresses
        if (empty($companyId)) {
            PrestaShopLogger::addLog('TwoPayment: Company name exists but no org number - prompting user to search', 2);
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'status' => 'incomplete_company',
                'error' => $this->module->l('To pay with Two, go back to your billing address and search for your company name. Select your company from the results to verify your business.')
            ]));
            return;
        }
        

        // SECURITY LAYER: Verify account type (kept for defense-in-depth)
        if (empty($accountType) || $accountType !== 'business') {
            PrestaShopLogger::addLog('TwoPayment: Order intent blocked - non-business account type: ' . $accountType, 2);
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => 'Two payment is only available for business accounts'
            ]));
            return;
        }

        try {
            // Set address with validated form data for API call (form-first approach)
            $address->company = $companyName;
            $address->companyid = $companyId;
            
            // Get order intent data
            $paymentdata = $this->module->getTwoIntentOrderData($cart, $customer, $currency, $address);

            // Return payload only (frontend will call Two API directly)
            $this->sendJsonResponse(json_encode([
                'success' => true,
                'payload' => $paymentdata
            ]));
            return;
        } catch (Exception $e) {
            // Log exception for debugging
            PrestaShopLogger::addLog('TwoPayment: Build order intent payload exception - ' . $e->getMessage(), 3);
            
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => 'Failed to build order intent payload'
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
     * Save order intent result to session for server-side validation
     * Called when client receives order intent result from Two API
     */
    public function ajaxProcessSaveOrderIntentResult()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => 'Invalid token'
            ]));
            return;
        }

        $approved = (bool)Tools::getValue('approved');
        $timestamp = time();

        // Store in PrestaShop cookie (session-based) for server-side validation
        $this->context->cookie->two_order_intent_approved = $approved ? '1' : '0';
        $this->context->cookie->two_order_intent_timestamp = (string)$timestamp;
        
        // Write cookie to ensure it's saved
        $this->context->cookie->write();

        PrestaShopLogger::addLog(
            'TwoPayment: Order intent result saved to session - Approved: ' . ($approved ? 'yes' : 'no') . ', Timestamp: ' . $timestamp,
            1
        );

        $this->sendJsonResponse(json_encode([
            'success' => true,
            'approved' => $approved,
            'timestamp' => $timestamp
        ]));
    }

    /**
     * Clear order intent result from session
     * Called when user switches away from Two payment method
     */
    public function ajaxProcessClearOrderIntentResult()
    {
        if (!$this->validateAjaxToken()) {
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => 'Invalid token'
            ]));
            return;
        }

        // Clear order intent result from cookie
        unset($this->context->cookie->two_order_intent_approved);
        unset($this->context->cookie->two_order_intent_timestamp);
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
     * Max 3 requests per minute per session
     */
    private function checkRateLimit()
    {
        $session_id = session_id();
        if (empty($session_id)) {
            // If no session, use IP as fallback (less reliable but better than nothing)
            $session_id = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
        
        $rate_limit_key = 'two_order_intent_' . md5($session_id);
        $current_time = time();
        $rate_limit_window = Twopayment::API_TIMEOUT_SHORT; // 1 minute (using API_TIMEOUT_SHORT constant)
        $max_requests = 5; // Production rate limit
        
        // Get current request data from session
        $request_data = isset($_SESSION[$rate_limit_key]) ? $_SESSION[$rate_limit_key] : [];
        
        // Clean old requests outside the window
        $request_data = array_filter($request_data, function($timestamp) use ($current_time, $rate_limit_window) {
            return ($current_time - $timestamp) < $rate_limit_window;
        });
        
        // Check if we're over the limit
        if (count($request_data) >= $max_requests) {
            return false;
        }
        
        // Add current request
        $request_data[] = $current_time;
        $_SESSION[$rate_limit_key] = $request_data;
        
        return true;
    }

    /**
     * Helper method to validate AJAX token
     */
    public function validateAjaxToken()
    {
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
     * Get company data using PrestaShop-native fallback chain with smart auto-resolution
     * Priority: Form data → Session → Address fields (with org number verification via Two API)
     * 
     * CRITICAL FIX: When a logged-in user uses an existing address, we check for organization
     * numbers stored in address fields (dni, vat_number) and verify them via Two's API.
     * This is MORE RELIABLE than searching by company name because org numbers give exact matches.
     * 
     * Example: https://api.two.inc/companies/v2/company?q=A81304487&country=ES returns exact match
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
        
        // Priority 2: PrestaShop session/cookie (persisted from previous steps or company search)
        $sessionCompany = isset($this->context->cookie->two_company_name) ? trim($this->context->cookie->two_company_name) : '';
        $sessionCompanyId = isset($this->context->cookie->two_company_id) ? trim($this->context->cookie->two_company_id) : '';
        
        if (!empty($sessionCompany) && !empty($sessionCompanyId)) {
            PrestaShopLogger::addLog('TwoPayment: Company data retrieved from PrestaShop session (complete)', 1);
            return [
                'company' => $sessionCompany,
                'companyid' => $sessionCompanyId
            ];
        }
        
        // Priority 3: Customer's address with ORG NUMBER VERIFICATION via Two API
        // This is the KEY FIX - we look for org numbers in address fields and verify them
        if ($this->context->customer->isLogged()) {
            $address = new Address($this->context->cart->id_address_invoice);
            if (Validate::isLoadedObject($address)) {
                $countryIso = Country::getIsoById($address->id_country);
                
                if ($countryIso && is_string($countryIso)) {
                    // STEP 1: Try to extract organization number from address fields
                    // This checks dni, vat_number, companyid fields
                    $existingOrgNumber = $this->module->extractOrgNumberFromAddress($address, $countryIso);
                    
                    if (!empty($existingOrgNumber)) {
                        // STEP 2: Verify the org number via Two's API to get company name
                        // This gives us an EXACT match - no vagueness like name-based search
                        PrestaShopLogger::addLog(
                            'TwoPayment: Found org number in address (' . $existingOrgNumber . '), verifying via Two API',
                            1
                        );
                        
                        $verifiedCompany = $this->module->verifyCompanyByOrgNumber($existingOrgNumber, $countryIso);
                        
                        if ($verifiedCompany && !empty($verifiedCompany['organization_number'])) {
                            // SUCCESS! We have verified company data from existing address
                            $resolvedCompany = $verifiedCompany['name'];
                            $resolvedOrgNumber = $verifiedCompany['organization_number'];
                            
                            // Cache in session for future requests
                            $this->context->cookie->two_company_name = $resolvedCompany;
                            $this->context->cookie->two_company_id = $resolvedOrgNumber;
                            $this->context->cookie->two_company_country = $countryIso;
                            $this->context->cookie->setExpire(time() + Twopayment::COOKIE_EXPIRY_ONE_HOUR);
                            
                            PrestaShopLogger::addLog(
                                'TwoPayment: ✓ Company VERIFIED from address org number - ' . 
                                $existingOrgNumber . ' => ' . $resolvedCompany . ' (cached in session)',
                                1
                            );
                            
                            return [
                                'company' => $resolvedCompany,
                                'companyid' => $resolvedOrgNumber
                            ];
                        } else {
                            // Org number couldn't be verified - might be invalid or Two API issue
                            PrestaShopLogger::addLog(
                                'TwoPayment: Org number from address could not be verified: ' . $existingOrgNumber . 
                                ' in ' . $countryIso . ' - user will need to search manually',
                                2
                            );
                        }
                    }
                    
                    // FALLBACK: Address has company name but no verifiable org number
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
            $this->context->cookie->two_company_name = $companyData['company'];
            $this->context->cookie->two_company_id = $companyData['companyid'] ?? '';
            
            // Set cookie expiration (1 hour)
            $this->context->cookie->setExpire(time() + Twopayment::COOKIE_EXPIRY_ONE_HOUR);
            
            PrestaShopLogger::addLog('TwoPayment: Company data stored in PrestaShop session', 1);
        }
    }
    
    /**
     * Try to retrieve stored company ID for a given company name
     * This could be enhanced with a database lookup in the future
     */
    private function getStoredCompanyId($companyName)
    {
        // For now, check session storage first
        if (isset($this->context->cookie->two_company_id)) {
            return $this->context->cookie->two_company_id;
        }
        
        // Future enhancement: Database lookup
        // $sql = 'SELECT companyid FROM ' . _DB_PREFIX_ . 'two_company_cache WHERE company_name = "' . pSQL($companyName) . '"';
        // return Db::getInstance()->getValue($sql);
        
        return null;
    }
}
