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
            case 'checkOrderIntent':
                $this->ajaxProcessCheckOrderIntent();
                break;
            default:
                $this->sendJsonResponse(json_encode([
                    'success' => false,
                    'error' => 'Unknown action: ' . $action
                ]));
        }
    }

    /**
     * Handle non-AJAX requests (for testing)
     */
    public function initContent()
    {
        
        // If this is a direct access (not AJAX), return simple response
        if (!Tools::getValue('ajax')) {
            echo 'TwoPayment OrderIntent Controller - Direct Access Test';
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
        $accountType = 'business'; // Always business for Two payments
        
        // Store company data in PrestaShop session for future use
        $this->storeCompanyDataInSession($companyData);
        
        
        // Simple validation - require both company name and organization number
        if (empty($companyName)) {
            PrestaShopLogger::addLog('TwoPayment: ERROR - No company name provided in form data', 3);
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => 'Company name is required for business accounts'
            ]));
            return;
        }
        
        if (empty($companyId)) {
            PrestaShopLogger::addLog('TwoPayment: ERROR - No organization number provided in form data', 3);
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => 'Organization number is required. Please select your company from the search results.'
            ]));
            return;
        }
        

        // SECURITY LAYER: Verify account type
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

            // Make order intent API call
            $response = $this->module->setTwoPaymentRequest("/v1/order_intent", $paymentdata, 'POST');

            // Check for API errors first
            $two_err = $this->module->getTwoErrorMessage($response);

            if ($two_err) {
                // API error occurred - log and return error
                PrestaShopLogger::addLog('TwoPayment Order Intent API Error: ' . $two_err, 2);
                
                $this->sendJsonResponse(json_encode([
                    'success' => false,
                    'error' => 'API Error: ' . $two_err
                ]));
                return;
            }

            // API call succeeded, now check the actual approval status
            $isApproved = isset($response['approved']) && $response['approved'] === true;
            
            if ($isApproved) {
                // Provide approval message as per Two's best practices
                $approvalMessage = sprintf(
                    $this->module->l('Your invoice with Two is likely to be accepted subject to additional checks. By completing the purchase, you verify that you have the legal right to purchase on behalf of %s'),
                    '<strong>' . Tools::safeOutput($address->company) . '</strong>'
                );
                
                $this->sendJsonResponse(json_encode([
                    'success' => true,
                    'approval' => true,
                    'message' => $approvalMessage
                ]));
                return;
            } else {
                // Provide user-friendly decline message
                $declineMessage = $this->module->l('Your invoice with Two cannot be approved at this time. Please select an alternative payment method.');
                
                $this->sendJsonResponse(json_encode([
                    'success' => true,
                    'approval' => false,
                    'message' => $declineMessage
                ]));
                return;
            }
        } catch (Exception $e) {
            // Log exception for debugging
            PrestaShopLogger::addLog('TwoPayment  v Exception: ' . $e->getMessage(), 3);
            
            $this->sendJsonResponse(json_encode([
                'success' => false,
                'error' => 'An error occurred while processing the order intent'
            ]));
            return;
        }
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
        $rate_limit_window = 60; // 1 minute
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
        // Security headers
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        
        // Validate that content is valid JSON
        $decoded = json_decode($content);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success' => false, 'error' => 'Invalid response format']);
        } else {
            echo $content;
        }
        exit;
    }

    /**
     * Get company data using PrestaShop-native fallback chain
     * Priority: Form data → Session → Address → Database
     */
    private function getCompanyDataWithFallbacks()
    {
        // Priority 1: Form data (highest priority - direct user input)
        $company = trim(Tools::getValue('company', ''));
        $companyId = trim(Tools::getValue('companyid', ''));
        
        if (!empty($company) && !empty($companyId)) {
            PrestaShopLogger::addLog('TwoPayment: Company data retrieved from form data', 1);
            return ['company' => $company, 'companyid' => $companyId];
        }
        
        // Priority 2: PrestaShop session/cookie (persisted from previous steps)
        if (isset($this->context->cookie->two_company_name) && !empty($this->context->cookie->two_company_name)) {
            PrestaShopLogger::addLog('TwoPayment: Company data retrieved from PrestaShop session', 1);
            return [
                'company' => $this->context->cookie->two_company_name,
                'companyid' => $this->context->cookie->two_company_id ?? ''
            ];
        }
        
        // Priority 3: Customer's current address data
        if ($this->context->customer->isLogged()) {
            $address = new Address($this->context->cart->id_address_invoice);
            if (Validate::isLoadedObject($address) && !empty($address->company)) {
                PrestaShopLogger::addLog('TwoPayment: Company data retrieved from customer address', 1);
                return [
                    'company' => $address->company,
                    'companyid' => $this->getStoredCompanyId($address->company) ?? ''
                ];
            }
        }
        
        // Priority 4: Check if we have any partial data
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
            $this->context->cookie->setExpire(time() + 3600);
            
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
