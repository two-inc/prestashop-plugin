<?php
/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/TwoSurchargeCalculator.php';

class Twopayment extends PaymentModule
{
    // Constants for order building logic
    const GROSS_AMOUNT_TOLERANCE = 0.02; // 2 cents tolerance for rounding differences
    const ORDER_INTENT_EXPIRY_SECONDS = 1800; // 30 minutes
    
    // Constants for payment terms
    const DEFAULT_PAYMENT_TERM_DAYS = 30; // Default payment term in days
    const PAYMENT_TERMS_OPTIONS = [7, 15, 20, 30, 45, 60, 90]; // Available payment term options (all > 0: getMerchantDueInDays() treats a cached 0 as "unset")
    // EOM (End-of-Month) terms are only offerable for these durations.
    const EOM_PAYMENT_TERMS_OPTIONS = [30, 45, 60];
    // TTL (seconds) for the cached GET /v1/merchant record - shared by the
    // available_terms list (TWO-24813) and the due_in_days default (TWO-24859),
    // which are both sourced from a SINGLE fetch of the same endpoint.
    const MERCHANT_AVAILABLE_TERMS_TTL = 900; // 15 minutes
    // On a FAILED merchant-record fetch, retry after this short backoff instead
    // of waiting the full TTL, so a transient blip does not lock in a stale
    // term list / wrong default for 15 minutes (TWO-24859 review).
    const MERCHANT_RECORD_RETRY_BACKOFF = 300; // 5 minutes
    // Dedicated Configuration keys for the cached merchant record (kept OUT
    // of the general-settings save path so a checkout-render refresh can never
    // race a concurrent admin save into reverting other settings - TWO-24813).
    // The value keys share one timestamp (fetched together, expire together).
    const CONFIG_MERCHANT_AVAILABLE_TERMS = 'PS_TWO_MERCHANT_AVAILABLE_TERMS';
    const CONFIG_MERCHANT_AVAILABLE_TERMS_TS = 'PS_TWO_MERCHANT_AVAILABLE_TERMS_TS';
    // Cached GET /v1/merchant `due_in_days` (the merchant's default invoice
    // term). Populated by the SAME fetch as CONFIG_MERCHANT_AVAILABLE_TERMS and
    // gated by the shared CONFIG_MERCHANT_AVAILABLE_TERMS_TS (TWO-24859).
    const CONFIG_MERCHANT_DUE_IN_DAYS = 'PS_TWO_MERCHANT_DUE_IN_DAYS';
    
    // Constants for API timeouts (seconds)
    const API_TIMEOUT_SHORT = 30; // Standard API timeout
    const API_TIMEOUT_LONG = 60; // Extended timeout for file uploads
    const API_TIMEOUT_STATE_CHECK = 10; // Tight timeout for the invoice-download order state check
    const API_TIMEOUT_PDF_FETCH = 10; // Tight timeout for synchronous invoice PDF fetches (buyer + admin download clicks)
    const API_CONNECT_TIMEOUT = 5; // Connection-establishment timeout for all Two API calls
    
    // Constants for validation tolerances
    const TAX_FORMULA_TOLERANCE = 0.02; // Tolerance for tax formula validation
    const NET_FORMULA_TOLERANCE = 0.05; // Tolerance for net formula validation
    const ORDER_RECONCILIATION_TOLERANCE = 0.02; // Warn-level parity tolerance against cart totals (PrestaShop rounding can drift by up to 2 cents)
    const TAX_RATE_PRECISION = 3; // Decimal precision for line-item tax rates sent to Two
    const TAX_SUBTOTAL_RATE_PRECISION = 2; // Keep tax subtotal grouping stable for compatibility
    const SNAPSHOT_TAX_RATE_PRECISION = 2; // Keep snapshot hash behavior stable across minor rate precision drift
    const TAX_RATE_PERCENT_PRECISION = 2; // Provider expects VAT rates rounded to 2 decimals in percent
    const TAX_RATE_VARIANCE_TOLERANCE = 0.005; // Acceptable decimal variance between configured and applied rate
    const TAX_RATE_CONTEXT_SNAP_TOLERANCE = 0.0025; // Snap near-context discount rates (e.g. 0.212 -> 0.21) for provider compatibility
    const SPANISH_FALLBACK_TAX_RATE = 0.21; // ES strict fallback when unresolved line rates drift from canonical contexts
    // Two module currency coverage baseline: keep these provider currencies explicitly allowed.
    // Required coverage: NOK, GBP, SEK, USD, DKK, EUR
    const TWO_SUPPORTED_CURRENCY_ISOS = ['NOK', 'GBP', 'SEK', 'USD', 'DKK', 'EUR'];
    
    // Constants for delivery dates
    const DEFAULT_DELIVERY_DAYS_OFFSET = 7; // Default expected delivery date offset
    
    // Constants for HTTP status codes
    const HTTP_STATUS_OK = 200;
    const HTTP_STATUS_CREATED = 201;
    const HTTP_STATUS_BAD_REQUEST = 400;
    const HTTP_STATUS_SERVER_ERROR = 500;
    
    // Constants for cookie/session expiry (seconds)
    const COOKIE_EXPIRY_ONE_HOUR = 3600; // 1 hour
    const ATTEMPT_RETENTION_DAYS = 90; // Keep attempt telemetry for 90 days
    const ATTEMPT_CLEANUP_INTERVAL_SECONDS = 86400; // Run cleanup at most once per day

    protected $output = '';
    protected $errors = array();
    protected $verifiedMerchantId = null;
    protected $verifiedMerchantShortName = null;

    public function __construct()
    {
        $this->name = 'twopayment';
        $this->tab = 'payments_gateways';
        $this->version = '2.4.0';
        $this->ps_versions_compliancy = array('min' => '1.7.6.0', 'max' => _PS_VERSION_);
        $this->author = 'Two';
        $this->bootstrap = true;
        $this->module_key = '0dff0a98ae080e510d4e23d22abcfe9c';
        $this->author_address = '';
        parent::__construct();
        $this->languages = Language::getLanguages(false);
        $this->displayName = $this->l('Two - BNPL for businesses');
        $this->description = $this->l('This module allows any merchant to accept payments with Two payment gateway.');
        $this->merchant_short_name = Configuration::get('PS_TWO_MERCHANT_SHORT_NAME');
        $this->api_key = Configuration::get('PS_TWO_MERCHANT_API_KEY');
        $this->enable_company_name = Configuration::get('PS_TWO_ENABLE_COMPANY_NAME');
        $this->enable_company_id = Configuration::get('PS_TWO_ENABLE_COMPANY_ID');
        $this->enable_department = Configuration::get('PS_TWO_ENABLE_DEPARTMENT');
        $this->enable_project = Configuration::get('PS_TWO_ENABLE_PROJECT');
        // Order intent pre-check is mandatory for all checkouts.
        $this->enable_order_intent = 1;
        $this->use_account_type = Configuration::get('PS_TWO_USE_ACCOUNT_TYPE');
        $this->finalize_purchase_shipping = Configuration::get('PS_TWO_FINALIZE_PURCHASE');
        
        // Ensure custom Two states exist (for existing installations)
        $this->ensureCustomStatesExist();
        $this->ensureRequiredHooksRegistered();
        $this->ensureTwoInvoiceAdminTabRegistered();
    }
    
    /**
     * Ensure custom Two order states exist, create them if they don't
     * This handles existing installations that didn't have custom states
     */
    private function ensureCustomStatesExist()
    {
        // Check if the main custom state exists
        if (!Configuration::get('PS_TWO_OS_AWAITING_VERIFICATION')) {
            // Create custom states and set up default mappings
            $this->createTwoOrderState();
            
            // Set up default mappings if they don't exist
            if (!Configuration::get('PS_TWO_OS_AWAITING_VERIFICATION_MAP')) {
                Configuration::updateValue('PS_TWO_OS_AWAITING_VERIFICATION_MAP', Configuration::get('PS_OS_PREPARATION'));
                Configuration::updateValue('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP', Configuration::get('PS_OS_PREPARATION'));
                Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', Configuration::get('PS_OS_SHIPPING'));
                Configuration::updateValue('PS_TWO_OS_PAYMENT_ERROR_MAP', Configuration::get('PS_OS_ERROR'));
                Configuration::updateValue('PS_TWO_OS_CANCELLED_MAP', Configuration::get('PS_OS_CANCELED'));
                Configuration::updateValue('PS_TWO_OS_REFUNDED_MAP', Configuration::get('PS_OS_REFUND'));
            }
        }
    }

    /**
     * Register newly introduced hooks on existing installations.
     */
    private function ensureRequiredHooksRegistered()
    {
        if ((int)$this->id <= 0 || !Module::isInstalled($this->name)) {
            return;
        }

        $required_hooks = array(
            'actionObjectOrderHistoryAddBefore',
        );

        foreach ($required_hooks as $hook_name) {
            if (!$this->isRegisteredInHook($hook_name)) {
                $this->registerHook($hook_name);
            }
        }
    }

    /**
     * Register the invisible admin tab backing the invoice download controller
     * on existing installations (mirrors ensureRequiredHooksRegistered).
     * Protected (not private) so the test suite can exercise the self-heal path.
     */
    protected function ensureTwoInvoiceAdminTabRegistered()
    {
        if ((int)$this->id <= 0 || !Module::isInstalled($this->name)) {
            return;
        }

        if ((int)Tab::getIdFromClassName('AdminTwopaymentInvoice') > 0) {
            return;
        }

        $this->installTwoInvoiceAdminTab();
    }

    /**
     * Install the invisible admin tab that exposes AdminTwopaymentInvoiceController.
     * PrestaShop enforces employee authentication + token + profile permissions
     * for controllers registered through a tab.
     *
     * @return bool
     */
    protected function installTwoInvoiceAdminTab()
    {
        if ((int)Tab::getIdFromClassName('AdminTwopaymentInvoice') > 0) {
            return true;
        }

        $tab = new Tab();
        $tab->class_name = 'AdminTwopaymentInvoice';
        $tab->module = $this->name;
        $tab->id_parent = -1; // Invisible: no menu entry, still permission-gated
        $tab->active = 1;
        $tab->name = array();
        foreach (Language::getLanguages(true) as $language) {
            $tab->name[(int)$language['id_lang']] = 'Invoice Download';
        }

        return (bool)$tab->add();
    }

    /**
     * Remove the invoice download admin tab.
     *
     * @return bool
     */
    protected function uninstallTwoInvoiceAdminTab()
    {
        $id_tab = (int)Tab::getIdFromClassName('AdminTwopaymentInvoice');
        if ($id_tab <= 0) {
            return true;
        }

        $tab = new Tab($id_tab);
        return (bool)$tab->delete();
    }


    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        return parent::install() &&
            $this->registerHook('actionAdminControllerSetMedia') &&
            $this->registerHook('actionFrontControllerSetMedia') &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('actionOrderSlipAdd') &&
            $this->registerHook('actionObjectOrderHistoryAddBefore') &&
            $this->registerHook('paymentOptions') &&
            $this->registerHook('displayPaymentReturn') &&
            $this->registerHook('displayAdminOrderLeft') &&
            $this->registerHook('displayAdminOrderTabLink') &&
            $this->registerHook('displayAdminOrderTabContent') &&
            $this->registerHook('displayOrderDetail') &&
            $this->registerHook('actionOrderEdited') &&
            $this->registerHook('actionAdminOrdersTrackingNumberUpdate') &&
            $this->registerHook('actionCustomerAddressSave') &&
            $this->installTwoInvoiceAdminTab() &&
            $this->installTwoSettings() &&
            $this->createTwoOrderState() &&
            $this->createTwoTables();
    }

    protected function installTwoSettings()
    {
        $installData = array();
        foreach ($this->languages as $language) {
            $installData['PS_TWO_TITLE'][(int) $language['id_lang']] = 'Business invoice 30 days';
            $installData['PS_TWO_SUB_TITLE'][(int) $language['id_lang']] = 'Buy now, pay later - instant credit';
        }
        Configuration::updateValue('PS_TWO_TAB_VALUE', 1);
        Configuration::updateValue('PS_TWO_TITLE', $installData['PS_TWO_TITLE']);
        Configuration::updateValue('PS_TWO_SUB_TITLE', $installData['PS_TWO_SUB_TITLE']);
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'development'); // Default to development for safety
        Configuration::updateValue('PS_TWO_MERCHANT_SHORT_NAME', '');
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', '');
        Configuration::updateValue('PS_TWO_MERCHANT_ID', '');
        Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 0);
        Configuration::updateValue('PS_TWO_DISABLE_SSL_VERIFY', 0); // Default: SSL verification enabled (secure)
        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', 1);
        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_ID', 1);
        Configuration::updateValue('PS_TWO_FINALIZE_PURCHASE', 1);
        Configuration::updateValue('PS_TWO_USE_ACCOUNT_TYPE', 0);
        Configuration::updateValue('PS_TWO_USE_OWN_INVOICES', 0); // Disabled by default - must be enabled after coordinating with Two
        Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', 'STANDARD'); // Default: Standard payment terms (not EOM)
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1); // Default: 30 days enabled
        Configuration::updateValue('PS_TWO_ENABLE_TAX_SUBTOTALS', 1); // Enabled by default; can be disabled for compatibility
        // Custom Two order states will be created by createTwoOrderState()
        // Set sensible default mappings to standard PrestaShop states
        // Processing states default to their Two-branded states out-of-the-box
        Configuration::updateValue('PS_TWO_OS_AWAITING_VERIFICATION_MAP', Configuration::get('PS_TWO_OS_AWAITING_VERIFICATION'));
        Configuration::updateValue('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP', Configuration::get('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT'));
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode(array((int)Configuration::get('PS_OS_SHIPPING')))); // "Shipped" - stored as JSON array
        Configuration::updateValue('PS_TWO_OS_PAYMENT_ERROR_MAP', Configuration::get('PS_OS_ERROR')); // "Payment error"
        Configuration::updateValue('PS_TWO_OS_CANCELLED_MAP', Configuration::get('PS_OS_CANCELED')); // "Canceled"
        Configuration::updateValue('PS_TWO_OS_REFUNDED_MAP', Configuration::get('PS_OS_REFUND')); // "Refunded"
        return true;
    }
    
    /**
     * Clean approach: No modifications to core PrestaShop tables
     * Company data is handled through form fields and session state
     */
    
    protected function createTwoOrderState()
    {
        $orderStates = [
            [
                'config_key' => 'PS_TWO_OS_AWAITING_VERIFICATION',
                'name' => 'Two: Awaiting Buyer Verification',
                'color' => '#FF9500',
                'paid' => 0,
                'invoice' => 0,
                'shipped' => 0,
                'delivery' => 0,
                'logable' => 1,
            ],
            [
                'config_key' => 'PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT',
                'name' => 'Two: Verified - Ready for Fulfillment',
                'color' => '#007CFF',
                'paid' => 1,
                'invoice' => 1,
                'shipped' => 0,
                'delivery' => 0,
                'logable' => 1,
            ],
            [
                'config_key' => 'PS_TWO_OS_FULFILLED',
                'name' => 'Two: Order Fulfilled - Payment Terms Active',
                'color' => '#34C759',
                'paid' => 1,
                'invoice' => 1,
                'shipped' => 1,
                'delivery' => 0,
                'logable' => 1,
            ],
            [
                'config_key' => 'PS_TWO_OS_PAYMENT_ERROR',
                'name' => 'Two: Payment Processing Error',
                'color' => '#FF3B30',
                'paid' => 0,
                'invoice' => 0,
                'shipped' => 0,
                'delivery' => 0,
                'logable' => 1,
            ],
            [
                'config_key' => 'PS_TWO_OS_CANCELLED',
                'name' => 'Two: Order Cancelled',
                'color' => '#8E8E93',
                'paid' => 0,
                'invoice' => 0,
                'shipped' => 0,
                'delivery' => 0,
                'logable' => 1,
            ],
            [
                'config_key' => 'PS_TWO_OS_REFUNDED',
                'name' => 'Two: Order Refunded',
                'color' => '#AF52DE',
                'paid' => 0,
                'invoice' => 1,
                'shipped' => 0,
                'delivery' => 0,
                'logable' => 1,
            ],
        ];

        foreach ($orderStates as $stateConfig) {
            if (!Configuration::get($stateConfig['config_key'])) {
                $orderStateObj = new OrderState();
                $orderStateObj->send_email = 0;
                $orderStateObj->module_name = $this->name;
                $orderStateObj->invoice = $stateConfig['invoice'];
                $orderStateObj->color = $stateConfig['color'];
                $orderStateObj->logable = $stateConfig['logable'];
                $orderStateObj->shipped = $stateConfig['shipped'];
                $orderStateObj->unremovable = 1;
                $orderStateObj->delivery = $stateConfig['delivery'];
                $orderStateObj->hidden = 0;
                $orderStateObj->paid = $stateConfig['paid'];
                $orderStateObj->pdf_delivery = 0;
                $orderStateObj->pdf_invoice = $stateConfig['invoice'];
                $orderStateObj->deleted = 0;
                
                foreach ($this->languages as $language) {
                    $orderStateObj->name[$language['id_lang']] = $stateConfig['name'];
                }
                
                if ($orderStateObj->add()) {
                    Configuration::updateValue($stateConfig['config_key'], (int) $orderStateObj->id);
                } else {
                    return false;
                }
            }
        }
        return true;
    }

    protected function createTwoTables()
    {
        // Only create our own module tables - no modifications to core PrestaShop tables
        $sql = array();
        
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'twopayment` (
            `id_two` int(11) NOT NULL AUTO_INCREMENT,
            `id_order` INT( 11 ) UNSIGNED,
            `two_order_id` TEXT NULL,
            `two_order_reference` TEXT NULL,
            `two_order_state` TEXT NULL,
            `two_order_status` TEXT NULL,
            `two_day_on_invoice` TEXT NULL,
            `two_payment_term_type` VARCHAR(20) DEFAULT "STANDARD",
            `two_invoice_url` TEXT NULL,
            `two_invoice_id` VARCHAR(255) NULL,
            `two_invoice_upload_status` ENUM("PENDING", "UPLOADING", "UPLOADED", "FAILED", "NOT_APPLICABLE") DEFAULT "NOT_APPLICABLE",
            `two_invoice_upload_reference` VARCHAR(255) NULL,
            `two_invoice_upload_error` TEXT NULL,
            `two_invoice_uploaded_at` DATETIME NULL,
            PRIMARY KEY  (`id_two`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'twopayment_attempt` (
            `id_attempt` int(11) NOT NULL AUTO_INCREMENT,
            `attempt_token` VARCHAR(80) NOT NULL,
            `id_cart` INT(11) UNSIGNED NOT NULL,
            `id_customer` INT(11) UNSIGNED NOT NULL,
            `id_order` INT(11) UNSIGNED NULL,
            `customer_secure_key` VARCHAR(64) NOT NULL,
            `merchant_order_id` VARCHAR(80) NOT NULL,
            `two_order_id` VARCHAR(255) NULL,
            `two_order_reference` VARCHAR(255) NULL,
            `two_order_state` VARCHAR(64) NULL,
            `two_order_status` VARCHAR(64) NULL,
            `two_day_on_invoice` VARCHAR(32) NULL,
            `two_payment_term_type` VARCHAR(20) DEFAULT "STANDARD",
            `two_invoice_url` TEXT NULL,
            `two_invoice_id` VARCHAR(255) NULL,
            `cart_snapshot_hash` VARCHAR(64) NULL,
            `order_create_idempotency_key` VARCHAR(128) NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT "CREATED",
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_attempt`),
            UNIQUE KEY `uniq_attempt_token` (`attempt_token`),
            KEY `idx_attempt_cart` (`id_cart`),
            KEY `idx_attempt_order` (`id_order`),
            KEY `idx_attempt_two_order_id` (`two_order_id`),
            KEY `idx_attempt_updated_at` (`updated_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';
        // Note: invoice_details (payment info) is NOT stored in DB - fetched from Two API when needed
        // This ensures payment details are always current and avoids stale data issues

        foreach ($sql as $query) {
            if (Db::getInstance()->execute($query) == false) {
                return false;
            }
        }
        return true;
    }

    public function uninstall()
    {
        return parent::uninstall() &&
            $this->unregisterHook('actionAdminControllerSetMedia') &&
            $this->unregisterHook('actionFrontControllerSetMedia') &&
            $this->unregisterHook('actionOrderStatusUpdate') &&
            $this->unregisterHook('actionOrderSlipAdd') &&
            $this->unregisterHook('actionObjectOrderHistoryAddBefore') &&
            $this->unregisterHook('paymentOptions') &&
            $this->unregisterHook('displayPaymentReturn') &&
            $this->unregisterHook('displayAdminOrderLeft') &&
            $this->unregisterHook('displayAdminOrderTabLink') &&
            $this->unregisterHook('displayAdminOrderTabContent') &&
            $this->unregisterHook('displayOrderDetail') &&
            $this->unregisterHook('actionOrderEdited') &&
            $this->unregisterHook('actionAdminOrdersTrackingNumberUpdate') &&
            $this->unregisterHook('actionCustomerAddressSave') &&
            $this->uninstallTwoInvoiceAdminTab() &&
            $this->uninstallTwoSettings() &&
            $this->deleteTwoTables();
    }

    protected function uninstallTwoSettings()
    {
        Configuration::deleteByName('PS_TWO_TAB_VALUE');
        Configuration::deleteByName('PS_TWO_TITLE');
        Configuration::deleteByName('PS_TWO_SUB_TITLE');
        Configuration::deleteByName('PS_TWO_MERCHANT_SHORT_NAME');
        Configuration::deleteByName('PS_TWO_MERCHANT_API_KEY');
        Configuration::deleteByName('PS_TWO_MERCHANT_ID');
        Configuration::deleteByName('PS_TWO_API_KEY_VERIFIED');
        Configuration::deleteByName('PS_TWO_DISABLE_SSL_VERIFY');
        Configuration::deleteByName('PS_TWO_ENABLE_COMPANY_NAME');
        Configuration::deleteByName('PS_TWO_ENABLE_COMPANY_ID');
        Configuration::deleteByName('PS_TWO_ENABLE_DEPARTMENT');
        Configuration::deleteByName('PS_TWO_ENABLE_PROJECT');
        Configuration::deleteByName('PS_TWO_ENABLE_TAX_SUBTOTALS');
        Configuration::deleteByName('PS_TWO_FINALIZE_PURCHASE');
        Configuration::deleteByName('PS_TWO_USE_ACCOUNT_TYPE');
        Configuration::deleteByName('PS_TWO_DEBUG_MODE');
        return true;
    }

    protected function deleteTwoTables()
    {
        $sql = array();
        foreach ($sql as $query) {
            if (Db::getInstance()->execute($query) == false) {
                return false;
            }
        }
        return true;
    }

    public function getContent()
    {
        if (((bool) Tools::isSubmit('submitTwoGeneralForm')) == true) {
            Configuration::updateValue('PS_TWO_TAB_VALUE', 1);
            $this->validTwoGeneralFormValues();
            if (!count($this->errors)) {
                $this->saveTwoGeneralFormValues();
            } else {
                foreach ($this->errors as $err) {
                    $this->output .= $this->displayError($err);
                }
            }
        }

        if (((bool) Tools::isSubmit('submitTwoPaymentSettingsForm')) == true) {
            Configuration::updateValue('PS_TWO_TAB_VALUE', 5);
            $this->validTwoPaymentSettingsFormValues();
            if (!count($this->errors)) {
                $this->saveTwoPaymentSettingsFormValues();
            } else {
                foreach ($this->errors as $err) {
                    $this->output .= $this->displayError($err);
                }
            }
        }

        if (((bool) Tools::isSubmit('submitTwoOtherForm')) == true) {
            Configuration::updateValue('PS_TWO_TAB_VALUE', 2);
            $this->validTwoOtherFormValues();
            if (!count($this->errors)) {
                $this->saveTwoOtherFormValues();
            } else {
                foreach ($this->errors as $err) {
                    $this->output .= $this->displayError($err);
                }
            }
        }

        if (((bool) Tools::isSubmit('submitTwoOrderStatusForm')) == true) {
            Configuration::updateValue('PS_TWO_TAB_VALUE', 3);
            $this->saveTwoOrderStatusFormValues();
        }

        $this->context->smarty->assign(
            array(
                'renderTwoGeneralForm' => $this->renderTwoGeneralForm(),
                'renderTwoPaymentSettingsForm' => $this->renderTwoPaymentSettingsForm(),
                'renderTwoOtherForm' => $this->renderTwoOtherForm(),
                'renderTwoOrderStatusForm' => $this->renderTwoOrderStatusForm(),
                'renderTwoPluginInfo' => $this->renderTwoPluginInfo(),
                'twotabvalue' => Configuration::get('PS_TWO_TAB_VALUE'),
                'two_api_verified' => (int) Configuration::get('PS_TWO_API_KEY_VERIFIED'),
                'two_merchant_id' => Configuration::get('PS_TWO_MERCHANT_ID'),
                'two_merchant_short_name' => Configuration::get('PS_TWO_MERCHANT_SHORT_NAME'),
                'two_env' => Configuration::get('PS_TWO_ENVIRONMENT'),
            )
        );

        $this->output .= $this->display(__FILE__, 'views/templates/admin/configuration.tpl');
        return $this->output;
    }

    protected function renderTwoGeneralForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->module = $this;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitTwoGeneralForm';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'uri' => $this->getPathUri(),
            'fields_value' => $this->getTwoGeneralFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );
        return $helper->generateForm(array($this->getTwoGeneralForm()));
    }

    protected function getTwoGeneralForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('General Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Title'),
                        'desc' => $this->l('Enter a title which is appear on checkout page as payment method title.'),
                        'name' => 'PS_TWO_TITLE',
                        'required' => true,
                        'lang' => true,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Sub title'),
                        'desc' => $this->l('Enter a sub title which is appear on checkout page as payment method sub title.'),
                        'name' => 'PS_TWO_SUB_TITLE',
                        'required' => true,
                        'lang' => true,
                    ),
                    
                    array(
                        'type' => 'password',
                        'label' => $this->l('Api key'),
                        'name' => 'PS_TWO_MERCHANT_API_KEY',
                        'required' => true,
                        'desc' => $this->l('Enter your api key which is provided by Two.'),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Environment'),
                        'name' => 'PS_TWO_ENVIRONMENT',
                        'desc' => $this->l('Select the Two API environment to use. Production for live transactions, Staging/Development for testing.'),
                        'required' => true,
                        'options' => array(
                            'query' => array(
                                array('id_option' => 'development', 'name' => $this->l('Development')),
                                array('id_option' => 'staging', 'name' => $this->l('Staging')),
                                array('id_option' => 'production', 'name' => $this->l('Production')),
                            ),
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
        return $fields_form;
    }

    /**
     * Build the "Available Payment Terms" checkbox rows, restricted to the
     * merchant's backend available_terms (TWO-24813) and falling back to the
     * hardcoded option list on a cold cache. The class drives the client-side
     * STANDARD/EOM show-hide toggle: 30/45/60 are valid under both, the rest are
     * STANDARD-only. Refreshes the cache (admin config render is a sanctioned
     * refresh point).
     *
     * @return array<int, array{id:string,name:string,val:string,class:string}>
     */
    protected function buildPaymentTermCheckboxQuery()
    {
        $source = $this->getOfferableTermSource(true);
        sort($source);
        $query = array();
        foreach ($source as $term) {
            $term = (int) $term;
            $type_class = in_array($term, self::EOM_PAYMENT_TERMS_OPTIONS, true)
                ? 'two-term-both'
                : 'two-term-standard';
            $query[] = array(
                'id' => (string) $term,
                'name' => sprintf($this->l('%d days'), $term),
                'val' => '1',
                'class' => 'two-term-option two-term-' . $term . ' ' . $type_class,
            );
        }
        return $query;
    }

    protected function getTwoGeneralFormValues()
    {
        $fields_values = array();
        foreach ($this->languages as $language) {
            $fields_values['PS_TWO_TITLE'][$language['id_lang']] = Tools::getValue('PS_TWO_TITLE_' . (int) $language['id_lang'], Configuration::get('PS_TWO_TITLE', (int) $language['id_lang']));
            $fields_values['PS_TWO_SUB_TITLE'][$language['id_lang']] = Tools::getValue('PS_TWO_SUB_TITLE_' . (int) $language['id_lang'], Configuration::get('PS_TWO_SUB_TITLE', (int) $language['id_lang']));
        }
        $fields_values['PS_TWO_MERCHANT_SHORT_NAME'] = Tools::getValue('PS_TWO_MERCHANT_SHORT_NAME', Configuration::get('PS_TWO_MERCHANT_SHORT_NAME'));
        $fields_values['PS_TWO_MERCHANT_API_KEY'] = Tools::getValue('PS_TWO_MERCHANT_API_KEY', Configuration::get('PS_TWO_MERCHANT_API_KEY'));
        $fields_values['PS_TWO_ENVIRONMENT'] = Tools::getValue('PS_TWO_ENVIRONMENT', Configuration::get('PS_TWO_ENVIRONMENT'));
        return $fields_values;
    }

    protected function validTwoGeneralFormValues()
    {
        foreach ($this->languages as $language) {
            if (Tools::isEmpty(Tools::getValue('PS_TWO_TITLE_' . (int) $language['id_lang']))) {
                $this->errors[] = $this->l('Enter a title.');
            }
            if (Tools::isEmpty(Tools::getValue('PS_TWO_SUB_TITLE_' . (int) $language['id_lang']))) {
                $this->errors[] = $this->l('Enter a sub title.');
            }
        }
        if (Tools::isEmpty(Tools::getValue('PS_TWO_MERCHANT_API_KEY'))) {
            $this->errors[] = $this->l('Enter an API key.');
        }
        
        // Validate environment
        $environment = Tools::getValue('PS_TWO_ENVIRONMENT');
        if (Tools::isEmpty($environment) || !in_array($environment, array('production', 'development', 'staging'))) {
            $this->errors[] = $this->l('Please select a valid environment (Production, Development or Staging).');
        }
        
        // Verify API key with Two against selected environment and capture merchant id and short name
        $apiKey = trim(Tools::getValue('PS_TWO_MERCHANT_API_KEY'));
        $env = Tools::getValue('PS_TWO_ENVIRONMENT');
        if (!empty($apiKey) && in_array($env, array('production','development','staging'))) {
            $verify = $this->verifyTwoApiKey($apiKey, $env);
            if ($verify === false) {
                $this->errors[] = $this->l('API key verification failed. Please check your API key.');
            } else {
                if (!isset($verify['id']) || !isset($verify['short_name'])) {
                    $this->errors[] = $this->l('Invalid verification response from Two.');
                } else {
                    $this->verifiedMerchantId = $verify['id'];
                    $this->verifiedMerchantShortName = $verify['short_name'];
                }
            }
        }
    }

    protected function saveTwoGeneralFormValues()
    {

        $values = array();
        foreach ($this->languages as $language) {
            $values['PS_TWO_TITLE'][(int) $language['id_lang']] = Tools::getValue('PS_TWO_TITLE_' . (int) $language['id_lang']);
            $values['PS_TWO_SUB_TITLE'][(int) $language['id_lang']] = Tools::getValue('PS_TWO_SUB_TITLE_' . (int) $language['id_lang']);
        }
        Configuration::updateValue('PS_TWO_TITLE', $values['PS_TWO_TITLE']);
        Configuration::updateValue('PS_TWO_SUB_TITLE', $values['PS_TWO_SUB_TITLE']);
        // If verification succeeded, use verified short name; else fallback to form (kept for safety)
        $shortNameToSave = $this->verifiedMerchantShortName ? $this->verifiedMerchantShortName : trim(Tools::getValue('PS_TWO_MERCHANT_SHORT_NAME'));
        Configuration::updateValue('PS_TWO_MERCHANT_SHORT_NAME', $shortNameToSave);
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', trim(Tools::getValue('PS_TWO_MERCHANT_API_KEY')));
        Configuration::updateValue('PS_TWO_ENVIRONMENT', Tools::getValue('PS_TWO_ENVIRONMENT'));
        if ($this->verifiedMerchantId) {
            if ((string) Configuration::get('PS_TWO_MERCHANT_ID') !== (string) $this->verifiedMerchantId) {
                // Merchant identity changed: drop the cached term list so
                // serve-stale never bridges the old merchant's terms (TWO-24813).
                $this->invalidateMerchantAvailableTerms();
            }
            Configuration::updateValue('PS_TWO_MERCHANT_ID', $this->verifiedMerchantId);
            Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 1);
        } else {
            // Ensure flag not stale when verification fails/non-run
            Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 0);
        }

        $this->output .= $this->displayConfirmation($this->l('General settings are updated.'));
    }

    protected function renderTwoPaymentSettingsForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->module = $this;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitTwoPaymentSettingsForm';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'uri' => $this->getPathUri(),
            'fields_value' => $this->getTwoPaymentSettingsFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );
        return $helper->generateForm(array($this->getTwoPaymentSettingsForm()));
    }

    protected function getTwoPaymentSettingsForm()
    {
        $inputs = array(
            array(
                'type' => 'radio',
                'label' => $this->l('Payment Term Type'),
                'name' => 'PS_TWO_PAYMENT_TERM_TYPE',
                'desc' => $this->l('Choose how payment terms are calculated:') . '<br><br><strong>' . $this->l('Standard Terms:') . '</strong> ' . $this->l('Payment due X days from fulfillment date. Example: If you fulfill an order on January 15th with 30-day terms, payment is due February 14th.') . '<br><br><strong>' . $this->l('End-of-Month (EOM) Terms:') . '</strong> ' . $this->l('Payment due at the end of the current month plus X days from fulfillment date. Example: If you fulfill an order on January 15th with EOM+30 terms, payment is due February 28th (end of January + 30 days). This is common for B2B invoicing.'),
                'is_bool' => false,
                'values' => array(
                    array(
                        'id' => 'term_type_standard',
                        'value' => 'STANDARD',
                        'label' => $this->l('Standard Terms (e.g., 30 days from fulfillment)')
                    ),
                    array(
                        'id' => 'term_type_eom',
                        'value' => 'EOM',
                        'label' => $this->l('End-of-Month Terms (e.g., EOM + 30 days)')
                    ),
                ),
            ),
            array(
                'type' => 'checkbox',
                'label' => $this->l('Available Payment Terms'),
                'name' => 'PS_TWO_PAYMENT_TERMS',
                'desc' => '<span id="two-payment-terms-desc-standard" style="display: none;">' . $this->l('Select which payment terms you want to offer. Standard terms are calculated from the fulfillment date.') . '</span><span id="two-payment-terms-desc-eom" style="display: none;">' . $this->l('Select which payment terms you want to offer. EOM (End-of-Month) terms are calculated from the end of the month at fulfillment, plus the selected days. Only 30, 45, and 60 day terms are available for EOM.') . '</span>',
                'values' => array(
                    // Restrict the tickable set to the merchant's backend
                    // available_terms (TWO-24813); admin render is one of
                    // the two sanctioned refresh points.
                    'query' => $this->buildPaymentTermCheckboxQuery(),
                    'id' => 'id',
                    'name' => 'name'
                )
            ),
        );

        // Offset pricing fee (buyer surcharge) fields — appended so the
        // per-term grid reflects the merchant's currently-offered terms.
        // TWO-24752 / TWO-24893.
        $inputs = array_merge($inputs, $this->getTwoSurchargeFormInputs());

        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Payment Settings'),
                    'icon' => 'icon-money',
                ),
                'input' => $inputs,
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    protected function getTwoPaymentSettingsFormValues()
    {
        $fields_values = array();

        // Payment term type (STANDARD or EOM)
        $fields_values['PS_TWO_PAYMENT_TERM_TYPE'] = Tools::getValue('PS_TWO_PAYMENT_TERM_TYPE', Configuration::get('PS_TWO_PAYMENT_TERM_TYPE'));

        // Payment terms checkboxes
        $payment_terms = array_map('strval', self::PAYMENT_TERMS_OPTIONS);
        foreach ($payment_terms as $term) {
            $fields_values['PS_TWO_PAYMENT_TERMS_' . $term] = Tools::getValue('PS_TWO_PAYMENT_TERMS_' . $term, Configuration::get('PS_TWO_PAYMENT_TERMS_' . $term));
        }

        $fields_values = array_merge($fields_values, $this->getTwoSurchargeFormValues());

        return $fields_values;
    }

    protected function validTwoPaymentSettingsFormValues()
    {
        // Validate payment terms
        $payment_terms = array_map('strval', self::PAYMENT_TERMS_OPTIONS);
        $selected_terms = array();
        foreach ($payment_terms as $term) {
            if (Tools::getValue('PS_TWO_PAYMENT_TERMS_' . $term)) {
                $selected_terms[] = $term;
            }
        }

        if (empty($selected_terms)) {
            $this->errors[] = $this->l('You must select at least one payment term.');
        }

        $this->validTwoSurchargeFormValues();
    }

    protected function saveTwoPaymentSettingsFormValues()
    {
        // Save payment term type (STANDARD or EOM)
        $term_type = Tools::getValue('PS_TWO_PAYMENT_TERM_TYPE');
        if ($term_type === 'STANDARD' || $term_type === 'EOM') {
            Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', $term_type);
        }

        // Save payment terms checkboxes. Iterate ONLY the terms the admin form
        // actually rendered (the backend-restricted offerable source), NOT the
        // full hardcoded list. buildPaymentTermCheckboxQuery() renders a checkbox
        // per offerable term, so a term the backend has withdrawn is hidden and
        // never POSTed; iterating the hardcoded list here would read its absent
        // POST value as unchecked and silently zero the merchant's stored
        // preference on any unrelated save. Leaving hidden keys untouched
        // preserves that preference for when the backend re-offers the term
        // (TWO-24813).
        $payment_terms = array_map('strval', $this->getOfferableTermSource(false));
        foreach ($payment_terms as $term) {
            Configuration::updateValue('PS_TWO_PAYMENT_TERMS_' . $term, Tools::getValue('PS_TWO_PAYMENT_TERMS_' . $term) ? 1 : 0);
        }

        $this->saveTwoSurchargeFormValues();

        $this->output .= $this->displayConfirmation($this->l('Payment settings are updated.'));
    }

    protected function renderTwoOtherForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->module = $this;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitTwoOtherForm';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'uri' => $this->getPathUri(),
            'fields_value' => $this->getTwoOtherFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );
        return $helper->generateForm(array($this->getTwoOtherForm()));
    }

    protected function getTwoOtherForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Other Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Use Account Type selection'),
                        'name' => 'PS_TWO_USE_ACCOUNT_TYPE',
                        'is_bool' => true,
                        'desc' => $this->l('If Yes, the address form will show Account Type and company fields become required for business. If No, the address form will not show Account Type and Two will prompt for company only at payment time.'),
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'PS_TWO_USE_ACCOUNT_TYPE_ON',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'PS_TWO_USE_ACCOUNT_TYPE_OFF',
                                'value' => 0,
                                'label' => $this->l('No')
                            ),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Activate company name auto-complete'),
                        'name' => 'PS_TWO_ENABLE_COMPANY_NAME',
                        'is_bool' => true,
                        'desc' => $this->l('If you choose YES then customers to use search api to find their company names.'),
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'PS_TWO_ENABLE_COMPANY_NAME_ON',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'PS_TWO_ENABLE_COMPANY_NAME_OFF',
                                'value' => 0,
                                'label' => $this->l('No')
                            ),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Activate company org.id auto-complete'),
                        'name' => 'PS_TWO_ENABLE_COMPANY_ID',
                        'is_bool' => true,
                        'desc' => $this->l('If you choose YES then customers to use search api to fins their company id (number) automatically.'),
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'PS_TWO_ENABLE_COMPANY_ID_ON',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'PS_TWO_ENABLE_COMPANY_ID_OFF',
                                'value' => 0,
                                'label' => $this->l('No')
                            ),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Show Department field'),
                        'name' => 'PS_TWO_ENABLE_DEPARTMENT',
                        'is_bool' => true,
                        'desc' => $this->l('If you choose YES then customers will see department field in checkout.'),
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'PS_TWO_ENABLE_DEPARTMENT_ON',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'PS_TWO_ENABLE_DEPARTMENT_OFF',
                                'value' => 0,
                                'label' => $this->l('No')
                            ),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Show Project field'),
                        'name' => 'PS_TWO_ENABLE_PROJECT',
                        'is_bool' => true,
                        'desc' => $this->l('If you choose YES then customers will see project field in checkout.'),
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'PS_TWO_ENABLE_PROJECT_ON',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'PS_TWO_ENABLE_PROJECT_OFF',
                                'value' => 0,
                                'label' => $this->l('No')
                            ),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Automatically fulfill orders with Two'),
                        'name' => 'PS_TWO_FINALIZE_PURCHASE',
                        'is_bool' => true,
                        'desc' => $this->l('When enabled, orders are automatically marked as fulfilled in Two when their status changes to one of your configured fulfillment trigger statuses (see Order Status Mapping). This activates buyer payment terms and begins the payout cycle. If disabled, you must fulfill orders manually in Two\'s Merchant Portal.'),
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'PS_TWO_FINALIZE_PURCHASE_ON',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'PS_TWO_FINALIZE_PURCHASE_OFF',
                                'value' => 0,
                                'label' => $this->l('No')
                            ),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Upload Own Invoices to Two'),
                        'name' => 'PS_TWO_USE_OWN_INVOICES',
                        'is_bool' => true,
                        'desc' => $this->l('Enable this ONLY if you are using your own invoices instead of Two\'s generated invoices. This must be coordinated with Two before enabling.') . '<br><br>' .
                                  '<strong>' . $this->l('When enabled:') . '</strong><br>' .
                                  '• ' . $this->l('Your PrestaShop invoices will be uploaded to Two when orders are fulfilled') . '<br>' .
                                  '• ' . $this->l('Two will NOT generate invoices - your invoice is used instead') . '<br><br>' .
                                  '<strong style="color: #d63031;">' . $this->l('REQUIRED: You must customize your invoice template') . '</strong><br>' .
                                  $this->l('Edit your invoice template to include Two\'s payment details FOR TWO ORDERS ONLY.') . '<br>' .
                                  $this->l('Template location:') . ' <code>/themes/YOUR_THEME/pdf/invoice.tpl</code> ' . $this->l('or') . ' <code>/pdf/invoice.tpl</code><br><br>' .
                                  '<strong>' . $this->l('Add this code to your invoice template:') . '</strong>' .
                                  '<pre style="background:#f5f5f5; padding:10px; margin:10px 0; border-radius:4px; font-size:11px; overflow-x:auto;">' .
                                  '{if $order->module == \'twopayment\'}<br>' .
                                  '&lt;div style="margin-top:20px; padding:15px; border:1px solid #333;"&gt;<br>' .
                                  '  &lt;strong&gt;Payment Instructions&lt;/strong&gt;&lt;br&gt;<br>' .
                                  '  The debt represented by this invoice has been assigned to Two.<br>' .
                                  '  Please pay to Two\'s bank account (details provided by Two).<br>' .
                                  '  Include your payment reference when paying.<br>' .
                                  '&lt;/div&gt;<br>' .
                                  '{/if}</pre>' .
                                  $this->l('Two will provide you with the specific bank details and payment reference format to include.') . '<br><br>' .
                                  '<strong style="color: #d63031;">' . $this->l('Important: Contact Two support before enabling this feature.') . '</strong>',
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'PS_TWO_USE_OWN_INVOICES_ON',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'PS_TWO_USE_OWN_INVOICES_OFF',
                                'value' => 0,
                                'label' => $this->l('No')
                            ),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Send tax subtotals in request payloads'),
                        'name' => 'PS_TWO_ENABLE_TAX_SUBTOTALS',
                        'is_bool' => true,
                        'desc' => $this->l('If you choose YES, tax_subtotals will be sent in /v1/order and /v1/order_intent payloads. If you choose NO, tax_subtotals will be omitted from those payloads.'),
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'PS_TWO_ENABLE_TAX_SUBTOTALS_ON',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'PS_TWO_ENABLE_TAX_SUBTOTALS_OFF',
                                'value' => 0,
                                'label' => $this->l('No')
                            ),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Disable SSL Verification (Corporate Networks Only)'),
                        'name' => 'PS_TWO_DISABLE_SSL_VERIFY',
                        'is_bool' => true,
                        'desc' => $this->l('WARNING: Only enable this if you are behind a corporate proxy with custom SSL certificates. This disables SSL certificate verification and is a SECURITY RISK. NOT RECOMMENDED for production.'),
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'PS_TWO_DISABLE_SSL_VERIFY_ON',
                                'value' => 1,
                                'label' => $this->l('Yes (Not Recommended)')
                            ),
                            array(
                                'id' => 'PS_TWO_DISABLE_SSL_VERIFY_OFF',
                                'value' => 0,
                                'label' => $this->l('No (Secure)')
                            ),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enable Debug Mode'),
                        'name' => 'PS_TWO_DEBUG_MODE',
                        'is_bool' => true,
                        'desc' => $this->l('Enable detailed logging for troubleshooting. Logs tax calculations and other diagnostic data. Only enable when requested by Two support.'),
                        'required' => false,
                        'values' => array(
                            array(
                                'id' => 'PS_TWO_DEBUG_MODE_ON',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'PS_TWO_DEBUG_MODE_OFF',
                                'value' => 0,
                                'label' => $this->l('No')
                            ),
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );

        return $fields_form;
    }

    protected function getTwoOtherFormValues()
    {
        $fields_values = array();
        $fields_values['PS_TWO_USE_ACCOUNT_TYPE'] = Tools::getValue('PS_TWO_USE_ACCOUNT_TYPE', Configuration::get('PS_TWO_USE_ACCOUNT_TYPE'));
        $fields_values['PS_TWO_ENABLE_COMPANY_NAME'] = Tools::getValue('PS_TWO_ENABLE_COMPANY_NAME', Configuration::get('PS_TWO_ENABLE_COMPANY_NAME'));
        $fields_values['PS_TWO_ENABLE_COMPANY_ID'] = Tools::getValue('PS_TWO_ENABLE_COMPANY_ID', Configuration::get('PS_TWO_ENABLE_COMPANY_ID'));
        $fields_values['PS_TWO_ENABLE_DEPARTMENT'] = Tools::getValue('PS_TWO_ENABLE_DEPARTMENT', Configuration::get('PS_TWO_ENABLE_DEPARTMENT'));
        $fields_values['PS_TWO_ENABLE_PROJECT'] = Tools::getValue('PS_TWO_ENABLE_PROJECT', Configuration::get('PS_TWO_ENABLE_PROJECT'));
        $fields_values['PS_TWO_FINALIZE_PURCHASE'] = Tools::getValue('PS_TWO_FINALIZE_PURCHASE', Configuration::get('PS_TWO_FINALIZE_PURCHASE'));
        $fields_values['PS_TWO_USE_OWN_INVOICES'] = Tools::getValue('PS_TWO_USE_OWN_INVOICES', Configuration::get('PS_TWO_USE_OWN_INVOICES'));
        $fields_values['PS_TWO_ENABLE_B2B_B2C'] = Tools::getValue('PS_TWO_ENABLE_B2B_B2C', Configuration::get('PS_TWO_ENABLE_B2B_B2C'));
        $fields_values['PS_TWO_ENABLE_TAX_SUBTOTALS'] = Tools::getValue('PS_TWO_ENABLE_TAX_SUBTOTALS', Configuration::get('PS_TWO_ENABLE_TAX_SUBTOTALS', 1));
        $fields_values['PS_TWO_DISABLE_SSL_VERIFY'] = Tools::getValue('PS_TWO_DISABLE_SSL_VERIFY', Configuration::get('PS_TWO_DISABLE_SSL_VERIFY'));
        $fields_values['PS_TWO_DEBUG_MODE'] = Tools::getValue('PS_TWO_DEBUG_MODE', Configuration::get('PS_TWO_DEBUG_MODE'));
        return $fields_values;
    }

    protected function validTwoOtherFormValues()
    {
    }

    protected function saveTwoOtherFormValues()
    {
        Configuration::updateValue('PS_TWO_USE_ACCOUNT_TYPE', Tools::getValue('PS_TWO_USE_ACCOUNT_TYPE'));
        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', Tools::getValue('PS_TWO_ENABLE_COMPANY_NAME'));
        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_ID', Tools::getValue('PS_TWO_ENABLE_COMPANY_ID'));
        Configuration::updateValue('PS_TWO_ENABLE_DEPARTMENT', Tools::getValue('PS_TWO_ENABLE_DEPARTMENT'));
        Configuration::updateValue('PS_TWO_ENABLE_PROJECT', Tools::getValue('PS_TWO_ENABLE_PROJECT'));
        Configuration::updateValue('PS_TWO_FINALIZE_PURCHASE', Tools::getValue('PS_TWO_FINALIZE_PURCHASE'));
        Configuration::updateValue('PS_TWO_USE_OWN_INVOICES', Tools::getValue('PS_TWO_USE_OWN_INVOICES'));
        Configuration::updateValue('PS_TWO_ENABLE_B2B_B2C', Tools::getValue('PS_TWO_ENABLE_B2B_B2C'));
        Configuration::updateValue('PS_TWO_ENABLE_TAX_SUBTOTALS', (int) Tools::getValue('PS_TWO_ENABLE_TAX_SUBTOTALS', 1));
        Configuration::updateValue('PS_TWO_DISABLE_SSL_VERIFY', (int) Tools::getValue('PS_TWO_DISABLE_SSL_VERIFY', 0));
        Configuration::updateValue('PS_TWO_DEBUG_MODE', Tools::getValue('PS_TWO_DEBUG_MODE'));

        $this->output .= $this->displayConfirmation($this->l('Other settings are updated.'));
    }

    protected function renderTwoOrderStatusForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->module = $this;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitTwoOrderStatusForm';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'uri' => $this->getPathUri(),
            'fields_value' => $this->getTwoOrderStatusFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );
        return $helper->generateForm(array($this->getTwoOrderStatusForm()));
    }

    /**
     * Render the Plugin Information tab content
     * Displays capabilities and limitations of the Two Payment plugin
     * 
     * @return string HTML content for the plugin information tab
     */
    protected function renderTwoPluginInfo()
    {
        $html = '
        <div class="panel">
            <div class="panel-heading">
                <i class="icon-info-circle"></i> ' . $this->l('What This Plugin Does') . '
            </div>
            <div class="panel-body">
                <div class="alert alert-info">
                    <strong>' . $this->l('Two is a B2B Buy Now, Pay Later solution') . '</strong><br>
                    ' . $this->l('This plugin enables business customers to pay on invoice with instant credit decisions.') . '
                </div>
                ' . $this->renderTwoPluginHealthChecklist() . '
                
                <h4 style="color:#4CAF50;margin-top:20px;"><i class="icon-check"></i> ' . $this->l('What the plugin CAN do') . '</h4>
                <ul class="list-unstyled" style="margin-left:20px;">
                    <li style="margin-bottom:8px;"><i class="icon-check text-success"></i> ' . $this->l('Accept B2B invoice payments with instant credit approval') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-check text-success"></i> ' . $this->l('Company search and validation at checkout (auto-complete)') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-check text-success"></i> ' . $this->l('Real-time buyer eligibility check (Order Intent) before purchase') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-check text-success"></i> ' . $this->l('Automatic order fulfillment when order status changes (configurable)') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-check text-success"></i> ' . $this->l('Support for Standard and End-of-Month (EOM) payment terms') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-check text-success"></i> ' . $this->l('Configurable payment terms (7, 15, 20, 30, 45, 60, 90 days)') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-check text-success"></i> ' . $this->l('Handle full refunds through PrestaShop admin') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-check text-success"></i> ' . $this->l('Display Two order information in admin order view') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-check text-success"></i> ' . $this->l('Support for multiple tax rates and tax-exempt customers') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-check text-success"></i> ' . $this->l('Handle free shipping cart rules and discounts correctly') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-check text-success"></i> ' . $this->l('Works with PrestaShop 1.7.6 through 9.x') . '</li>
                </ul>
                
                <h4 style="color:#f0ad4e;margin-top:25px;"><i class="icon-warning"></i> ' . $this->l('Important Requirements') . '</h4>
                <ul class="list-unstyled" style="margin-left:20px;">
                    <li style="margin-bottom:8px;"><i class="icon-exclamation-triangle text-warning"></i> ' . $this->l('Customers must have a valid company/organization number') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-exclamation-triangle text-warning"></i> ' . $this->l('Customers must enter their company name in the billing address') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-exclamation-triangle text-warning"></i> ' . $this->l('A valid phone number is required for credit checks') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-exclamation-triangle text-warning"></i> ' . $this->l('Two must approve the buyer before the order can be placed') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-exclamation-triangle text-warning"></i> ' . $this->l('Products must have correct tax rules configured in PrestaShop') . '</li>
                </ul>
                
                <h4 style="color:#d9534f;margin-top:25px;"><i class="icon-times"></i> ' . $this->l('What the plugin CANNOT do') . '</h4>
                <ul class="list-unstyled" style="margin-left:20px;">
                    <li style="margin-bottom:8px;"><i class="icon-times text-danger"></i> ' . $this->l('Process B2C (consumer) payments - Two is B2B only') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-times text-danger"></i> ' . $this->l('Guarantee approval - Two performs real-time credit checks') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-times text-danger"></i> ' . $this->l('Override Two\'s credit decision or buyer limits') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-times text-danger"></i> ' . $this->l('Process partial refunds - use the Two Merchant Portal for partial refunds') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-times text-danger"></i> ' . $this->l('Partial fulfillment - orders must be fulfilled in full') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-times text-danger"></i> ' . $this->l('Fix incorrect tax configuration in your store - taxes must be set up correctly in PrestaShop') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-times text-danger"></i> ' . $this->l('Process orders without a valid company registration number') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-times text-danger"></i> ' . $this->l('Change payment terms after an order is placed') . '</li>
                </ul>
                
                <h4 style="color:#5bc0de;margin-top:25px;"><i class="icon-lightbulb-o"></i> ' . $this->l('Troubleshooting Tips') . '</h4>
                <ul class="list-unstyled" style="margin-left:20px;">
                    <li style="margin-bottom:8px;"><i class="icon-info-circle text-info"></i> <strong>' . $this->l('Tax shows 0%?') . '</strong> ' . $this->l('Check that tax rules are configured for your country in International > Taxes > Tax Rules') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-info-circle text-info"></i> <strong>' . $this->l('Buyer rejected?') . '</strong> ' . $this->l('The company may have reached their credit limit or failed Two\'s credit check') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-info-circle text-info"></i> <strong>' . $this->l('Company not found?') . '</strong> ' . $this->l('Customer must enter their official registered company name') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-info-circle text-info"></i> <strong>' . $this->l('Phone invalid?') . '</strong> ' . $this->l('Ensure the phone number includes country code and is in a valid format') . '</li>
                    <li style="margin-bottom:8px;"><i class="icon-info-circle text-info"></i> <strong>' . $this->l('Amount mismatch errors?') . '</strong> ' . $this->l('Enable Debug Mode in Other Settings and contact Two support with the logs') . '</li>
                </ul>
            </div>
        </div>
        
        <div class="panel">
            <div class="panel-heading">
                <i class="icon-life-ring"></i> ' . $this->l('Need Help?') . '
            </div>
            <div class="panel-body">
                <p>' . $this->l('For technical support or questions about this plugin:') . '</p>
                <ul>
                    <li><strong>' . $this->l('Email:') . '</strong> <a href="mailto:support@two.inc">support@two.inc</a></li>
                    <li><strong>' . $this->l('Documentation:') . '</strong> <a href="https://docs.two.inc" target="_blank">docs.two.inc</a></li>
                    <li><strong>' . $this->l('Merchant Portal:') . '</strong> <a href="' . $this->getTwoPortalUrl() . '" target="_blank">' . $this->l('Open Two Portal') . '</a></li>
                </ul>
                <p style="margin-top:15px;"><small class="text-muted">' . $this->l('Plugin Version:') . ' ' . $this->version . ' | ' . $this->l('PrestaShop:') . ' ' . _PS_VERSION_ . '</small></p>
            </div>
        </div>';
        
        return $html;
    }

    /**
     * Render a compact operational health summary for plugin configuration.
     *
     * @return string HTML
     */
    protected function renderTwoPluginHealthChecklist()
    {
        $environment = (string) Configuration::get('PS_TWO_ENVIRONMENT', 'development');
        $api_verified = (bool) Configuration::get('PS_TWO_API_KEY_VERIFIED');
        $ssl_disabled = (bool) Configuration::get('PS_TWO_DISABLE_SSL_VERIFY');
        $order_intent_enabled = true;
        $use_account_type = (bool) Configuration::get('PS_TWO_USE_ACCOUNT_TYPE');
        $term_type = (string) Configuration::get('PS_TWO_PAYMENT_TERM_TYPE', 'STANDARD');
        $available_terms = $this->getAvailablePaymentTerms();

        $term_labels = array();
        foreach ($available_terms as $term) {
            $term_labels[] = (int) $term;
        }

        $status_rows = array(
            array(
                'label' => $this->l('API key'),
                'value' => $api_verified ? $this->l('Verified') : $this->l('Not verified'),
                'ok' => $api_verified,
            ),
            array(
                'label' => $this->l('Environment'),
                'value' => strtoupper($environment),
                'ok' => true,
            ),
            array(
                'label' => $this->l('SSL verification'),
                'value' => $ssl_disabled ? $this->l('Disabled') : $this->l('Enabled'),
                'ok' => !$ssl_disabled,
            ),
            array(
                'label' => $this->l('Order intent pre-check'),
                'value' => $order_intent_enabled ? $this->l('Enabled') : $this->l('Disabled'),
                'ok' => true,
            ),
            array(
                'label' => $this->l('Account type mode'),
                'value' => $use_account_type ? $this->l('Enabled') : $this->l('Disabled'),
                'ok' => true,
            ),
            array(
                'label' => $this->l('Payment terms'),
                'value' => $term_type . ' (' . implode(', ', $term_labels) . ')',
                'ok' => !empty($term_labels),
            ),
        );

        $html = '<div class="panel" style="margin-top:15px;">';
        $html .= '<div class="panel-heading"><i class="icon-dashboard"></i> ' . $this->l('Current Configuration Health') . '</div>';
        $html .= '<div class="panel-body">';
        $html .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px 16px;">';

        foreach ($status_rows as $row) {
            $status_class = $row['ok'] ? 'text-success' : 'text-warning';
            $status_icon = $row['ok'] ? 'icon-check-circle' : 'icon-warning';
            $html .= '<div><strong>' . $row['label'] . ':</strong> <span class="' . $status_class . '"><i class="' . $status_icon . '"></i> ' . $row['value'] . '</span></div>';
        }

        $html .= '</div>';

        if ($environment === 'production' && $ssl_disabled) {
            $html .= '<div class="alert alert-danger" style="margin-top:12px;margin-bottom:0;">';
            $html .= '<strong>' . $this->l('Security warning:') . '</strong> ';
            $html .= $this->l('SSL verification is disabled in production. Re-enable it unless your network requires a trusted corporate proxy setup.');
            $html .= '</div>';
        }

        if (!$api_verified) {
            $html .= '<div class="alert alert-warning" style="margin-top:12px;margin-bottom:0;">';
            $html .= '<strong>' . $this->l('Action required:') . '</strong> ';
            $html .= $this->l('API key is not verified. Checkout requests may fail until the General Settings are saved with a valid key.');
            $html .= '</div>';
        }

        $html .= '</div></div>';

        return $html;
    }

    protected function getTwoOrderStatusForm()
    {
        // Get all available PrestaShop order states for mapping
        $orderStates = OrderState::getOrderStates($this->context->language->id);
        
        // Build a filtered list excluding Two custom states (for Group A mapping selects)
        $twoCustomStateIds = array_values(array_filter(array(
            (int) Configuration::get('PS_TWO_OS_AWAITING_VERIFICATION'),
            (int) Configuration::get('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT'),
            (int) Configuration::get('PS_TWO_OS_FULFILLED'),
            (int) Configuration::get('PS_TWO_OS_PAYMENT_ERROR'),
            (int) Configuration::get('PS_TWO_OS_CANCELLED'),
            (int) Configuration::get('PS_TWO_OS_REFUNDED'),
        ), function ($v) { return $v > 0; }));
        
        $orderStatesNoTwo = array_values(array_filter($orderStates, function ($state) use ($twoCustomStateIds) {
            return !in_array((int) $state['id_order_state'], $twoCustomStateIds);
        }));

        // Build restricted lists for processing states: allow only the matching Two state + non-Two states
        $awaitingId = (int) Configuration::get('PS_TWO_OS_AWAITING_VERIFICATION');
        $verifiedId = (int) Configuration::get('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT');

        $awaitingState = null;
        $verifiedState = null;
        foreach ($orderStates as $st) {
            if ((int) $st['id_order_state'] === $awaitingId) {
                $awaitingState = $st;
            } elseif ((int) $st['id_order_state'] === $verifiedId) {
                $verifiedState = $st;
            }
        }

        $orderStatesAwaitingOnly = $orderStatesNoTwo;
        if ($awaitingState) {
            $orderStatesAwaitingOnly[] = $awaitingState;
        }

        $orderStatesVerifiedOnly = $orderStatesNoTwo;
        if ($verifiedState) {
            $orderStatesVerifiedOnly[] = $verifiedState;
        }
        
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Two Order Status Mapping'),
                    'icon' => 'icon-cogs',
                ),
                'description' => $this->l('Map Two payment states to PrestaShop order states for workflow integration. Two creates its own branded order states automatically, but you can map them to existing PrestaShop states if needed.') . '<br><br><strong>' . $this->l('Default Mappings:') . '</strong><br>' . 
                    '• ' . $this->l('Awaiting Buyer Verification → Two: Awaiting Buyer Verification') . '<br>' .
                    '• ' . $this->l('Verified - Ready for Fulfillment → Two: Verified - Ready for Fulfillment') . '<br>' .
                    '• ' . $this->l('Order Fulfilled → Shipped') . '<br>' .
                    '• ' . $this->l('Payment Error → Payment error') . '<br>' .
                    '• ' . $this->l('Order Cancelled → Canceled') . '<br>' .
                    '• ' . $this->l('Order Refunded → Refunded'),
                'input' => array(
                    array(
                        'type' => 'select',
                        'name' => 'PS_TWO_OS_AWAITING_VERIFICATION_MAP',
                        'label' => $this->l('Two: Awaiting Buyer Verification'),
                        'desc' => $this->l('When the buyer needs to complete order verification with Two before payment processing can begin. Default: Preparation in progress'),
                        'required' => true,
                        'options' => array(
                            'query' => $orderStatesAwaitingOnly,
                            'id' => 'id_order_state',
                            'name' => 'name'
                        )
                    ),
                    array(
                        'type' => 'select',
                        'name' => 'PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP',
                        'label' => $this->l('Two: Verified - Ready for Fulfillment'),
                        'desc' => $this->l('Payment is verified and order is ready for merchant fulfillment. Merchant can now process and ship the order. Default: Preparation in progress'),
                        'required' => true,
                        'options' => array(
                            'query' => $orderStatesVerifiedOnly,
                            'id' => 'id_order_state',
                            'name' => 'name'
                        )
                    ),
                    array(
                        'type' => 'select',
                        'name' => 'PS_TWO_OS_FULFILLED_MAP',
                        'label' => $this->l('Two: Order Fulfilled - Trigger Statuses'),
                        'desc' => $this->buildFulfillmentStatusDescription(),
                        'required' => true,
                        'multiple' => true,
                        'size' => 8,
                        'options' => array(
                            'query' => $orderStatesNoTwo,
                            'id' => 'id_order_state',
                            'name' => 'name'
                        )
                    ),
                    array(
                        'type' => 'select',
                        'name' => 'PS_TWO_OS_PAYMENT_ERROR_MAP',
                        'label' => $this->l('Two: Payment Processing Error'),
                        'desc' => $this->l('Payment processing failed. Merchant should investigate and contact Two support if needed. Default: Payment error'),
                        'required' => true,
                        'options' => array(
                            'query' => $orderStatesNoTwo,
                            'id' => 'id_order_state',
                            'name' => 'name'
                        )
                    ),
                    array(
                        'type' => 'select',
                        'name' => 'PS_TWO_OS_CANCELLED_MAP',
                        'label' => $this->l('Two: Order Cancelled'),
                        'desc' => $this->l('Order has been cancelled with Two. This prevents fulfillment and stops the payment process. Default: Canceled'),
                        'required' => true,
                        'options' => array(
                            'query' => $orderStatesNoTwo,
                            'id' => 'id_order_state',
                            'name' => 'name'
                        )
                    ),
                    array(
                        'type' => 'select',
                        'name' => 'PS_TWO_OS_REFUNDED_MAP',
                        'label' => $this->l('Two: Order Refunded'),
                        'desc' => $this->l('Order has been refunded through Two. A credit note is issued to the buyer immediately. Default: Refunded'),
                        'required' => true,
                        'options' => array(
                            'query' => $orderStatesNoTwo,
                            'id' => 'id_order_state',
                            'name' => 'name'
                        )
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
        return $fields_form;
    }

    protected function getTwoOrderStatusFormValues()
    {
        $fields_values = array();
        $fields_values['PS_TWO_OS_AWAITING_VERIFICATION_MAP'] = Tools::getValue('PS_TWO_OS_AWAITING_VERIFICATION_MAP', Configuration::get('PS_TWO_OS_AWAITING_VERIFICATION_MAP'));
        $fields_values['PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP'] = Tools::getValue('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP', Configuration::get('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP'));
        
        // Handle multi-select for fulfillment statuses
        $fulfilled_map = Configuration::get('PS_TWO_OS_FULFILLED_MAP');
        if ($fulfilled_map) {
            // Decode JSON array or split comma-separated values
            $fulfilled_ids = json_decode($fulfilled_map, true);
            if (!is_array($fulfilled_ids)) {
                // Backward compatibility: if it's a single ID, convert to array
                $fulfilled_ids = array($fulfilled_map);
            }
            $fields_values['PS_TWO_OS_FULFILLED_MAP'] = $fulfilled_ids;
        } else {
            // Default to Shipped status
            $fields_values['PS_TWO_OS_FULFILLED_MAP'] = array(Configuration::get('PS_OS_SHIPPING'));
        }
        
        $fields_values['PS_TWO_OS_PAYMENT_ERROR_MAP'] = Tools::getValue('PS_TWO_OS_PAYMENT_ERROR_MAP', Configuration::get('PS_TWO_OS_PAYMENT_ERROR_MAP'));
        $fields_values['PS_TWO_OS_CANCELLED_MAP'] = Tools::getValue('PS_TWO_OS_CANCELLED_MAP', Configuration::get('PS_TWO_OS_CANCELLED_MAP'));
        $fields_values['PS_TWO_OS_REFUNDED_MAP'] = Tools::getValue('PS_TWO_OS_REFUNDED_MAP', Configuration::get('PS_TWO_OS_REFUNDED_MAP'));
        return $fields_values;
    }

    protected function saveTwoOrderStatusFormValues()
    {
        Configuration::updateValue('PS_TWO_OS_AWAITING_VERIFICATION_MAP', Tools::getValue('PS_TWO_OS_AWAITING_VERIFICATION_MAP'));
        Configuration::updateValue('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP', Tools::getValue('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP'));
        
        // Handle multi-select for fulfillment statuses - store as JSON array
        $fulfilled_statuses = Tools::getValue('PS_TWO_OS_FULFILLED_MAP');
        if (is_array($fulfilled_statuses) && !empty($fulfilled_statuses)) {
            // Store as JSON array for multiple selections
            Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode(array_map('intval', $fulfilled_statuses)));
        } else {
            // Fallback to default Shipped status if nothing selected
            Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode(array((int)Configuration::get('PS_OS_SHIPPING'))));
        }
        
        Configuration::updateValue('PS_TWO_OS_PAYMENT_ERROR_MAP', Tools::getValue('PS_TWO_OS_PAYMENT_ERROR_MAP'));
        Configuration::updateValue('PS_TWO_OS_CANCELLED_MAP', Tools::getValue('PS_TWO_OS_CANCELLED_MAP'));
        Configuration::updateValue('PS_TWO_OS_REFUNDED_MAP', Tools::getValue('PS_TWO_OS_REFUNDED_MAP'));

        // Build confirmation message with currently selected fulfillment trigger statuses
        $fulfilled_map = Configuration::get('PS_TWO_OS_FULFILLED_MAP');
        $fulfilled_ids = json_decode($fulfilled_map, true);
        if (!is_array($fulfilled_ids)) {
            $fulfilled_ids = array($fulfilled_map);
        }
        
        $status_names = $this->getOrderStatusNames($fulfilled_ids);
        $status_list = !empty($status_names) ? implode(', ', $status_names) : $this->l('None selected');
        
        $confirmation_message = $this->l('Two order status mapping updated successfully.');
        if (!empty($status_names)) {
            $confirmation_message .= '<br><br><strong>' . $this->l('Currently active fulfillment trigger statuses:') . '</strong><br>';
            $confirmation_message .= '<ul style="margin: 5px 0; padding-left: 20px;">';
            foreach ($status_names as $status_name) {
                $confirmation_message .= '<li>' . htmlspecialchars($status_name, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $confirmation_message .= '</ul>';
        }

        $this->output .= $this->displayConfirmation($confirmation_message);
    }
    
    /**
     * Build description for fulfillment status field showing currently active statuses
     * 
     * @return string Description HTML
     */
    protected function buildFulfillmentStatusDescription()
    {
        $base_desc = $this->l('Select one or more order statuses that should trigger Two fulfillment. When any of these statuses are set, the order will be marked as fulfilled with Two. Buyer payment terms become active and payout cycle begins. You can select multiple statuses (Hold Ctrl/Cmd to select multiple. Default: Shipped');
        
        // Get currently selected statuses
        $fulfilled_map = Configuration::get('PS_TWO_OS_FULFILLED_MAP');
        $fulfilled_ids = json_decode($fulfilled_map, true);
        if (!is_array($fulfilled_ids)) {
            if (!empty($fulfilled_map)) {
                $fulfilled_ids = array($fulfilled_map);
            } else {
                $fulfilled_ids = array(Configuration::get('PS_OS_SHIPPING'));
            }
        }
        
        $status_names = $this->getOrderStatusNames($fulfilled_ids);
        
        if (!empty($status_names)) {
            $base_desc .= '<br><br><strong style="color: #28a745;">' . $this->l('Currently active:') . '</strong> ';
            $base_desc .= '<span style="color: #28a745; font-weight: bold;">' . implode(', ', array_map(function($name) {
                return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            }, $status_names)) . '</span>';
        }
        
        return $base_desc;
    }
    
    /**
     * Get order status names from status IDs
     * 
     * @param array $status_ids Array of order status IDs
     * @return array Array of status names
     */
    protected function getOrderStatusNames($status_ids)
    {
        if (empty($status_ids) || !is_array($status_ids)) {
            return array();
        }
        
        $status_names = array();
        $all_states = OrderState::getOrderStates($this->context->language->id);
        
        foreach ($status_ids as $status_id) {
            foreach ($all_states as $state) {
                if ((int)$state['id_order_state'] === (int)$status_id) {
                    $status_names[] = $state['name'];
                    break;
                }
            }
        }
        
        return $status_names;
    }

    /**
     * Check if a status ID is in the fulfillment trigger list
     * 
     * @param int $status_id The order status ID to check
     * @return bool True if this status should trigger fulfillment
     */
    protected function isFulfillmentTriggerStatus($status_id)
    {
        $fulfilled_map = Configuration::get('PS_TWO_OS_FULFILLED_MAP');
        
        if (empty($fulfilled_map)) {
            // Default to standard Shipped status
            return ($status_id == Configuration::get('PS_OS_SHIPPING'));
        }
        
        // Try to decode as JSON array (new multi-select format)
        $fulfilled_ids = json_decode($fulfilled_map, true);
        
        if (is_array($fulfilled_ids)) {
            // Multi-select format - check if status is in array
            return in_array((int)$status_id, array_map('intval', $fulfilled_ids));
        }
        
        // Backward compatibility: single status ID (old format)
        return ($status_id == $fulfilled_map);
    }
    
    /**
     * Set order to Two custom state and optionally apply mapping to PrestaShop state
     */
    protected function setTwoOrderState($order_id, $two_state_key, $apply_mapping = true)
    {
        $two_state_id = Configuration::get($two_state_key);
        if ($two_state_id) {
            // First set the Two custom state
            $history = new OrderHistory();
            $history->id_order = $order_id;
            $history->changeIdOrderState($two_state_id, $order_id, true);
            $history->addWithemail();
            
            // Then optionally apply the mapped PrestaShop state
            if ($apply_mapping) {
                $mapped_state_id = Configuration::get($two_state_key . '_MAP');
                if ($mapped_state_id && $mapped_state_id != $two_state_id) {
                    $history2 = new OrderHistory();
                    $history2->id_order = $order_id;
                    $history2->changeIdOrderState($mapped_state_id, $order_id, true);
                    $history2->addWithemail();
                }
            }
        }
    }

    public function hookActionOrderEdited($params)
    {
        $order = $params['order'];
        $cart = new Cart($order->id_cart);
        $payment = $order->getOrderPaymentCollection();
        if (isset($payment[0])) {
            $payment[0]->amount = $cart->getOrderTotal(true, Cart::BOTH);
            $payment[0]->save();
        }

        if ($order->module == $this->name) {
            $orderpaymentdata = $this->getTwoOrderPaymentData($order->id);
            if ($orderpaymentdata && isset($orderpaymentdata['two_order_id'])) {
                $two_order_id = $orderpaymentdata['two_order_id'];
                $paymentdata = $this->getTwoUpdateOrderData($order, $orderpaymentdata);
                $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, $paymentdata, 'PUT');
            }
        }
    }

    /**
     * Push the tracking number to Two when it is set in the admin shipping
     * panel (TWO-24762). The hook has been registered on install all along;
     * this is its first handler. Fired by
     * UpdateOrderShippingDetailsHandler with ['order', 'customer',
     * 'carrier'].
     *
     * Best-effort by design: the Two API only accepts order edits before
     * fulfilment, so a tracking number added after the order was fulfilled
     * is rejected server-side. Nothing here may break the admin action
     * that saved the tracking number — failures are logged and surfaced
     * as a back-office warning instead.
     */
    public function hookActionAdminOrdersTrackingNumberUpdate($params)
    {
        $order = isset($params['order']) ? $params['order'] : null;
        if (!$order || !Validate::isLoadedObject($order) || $order->module != $this->name) {
            return;
        }

        try {
            $orderpaymentdata = $this->getTwoOrderPaymentData($order->id);
            if (!$orderpaymentdata || empty($orderpaymentdata['two_order_id'])) {
                return;
            }

            $paymentdata = $this->getTwoUpdateOrderData($order, $orderpaymentdata);
            $response = $this->setTwoPaymentRequest('/v1/order/' . $orderpaymentdata['two_order_id'], $paymentdata, 'PUT');

            $http_status = is_array($response) && isset($response['http_status']) ? (int)$response['http_status'] : 0;
            if ($http_status < 200 || $http_status >= 300) {
                // Most commonly the order was already fulfilled at Two
                // (edits are rejected after fulfilment): the invoice will
                // not carry the tracking number. Log with the pushed
                // amount so any accepted-elsewhere drift stays
                // reconstructible, and tell the admin who just typed it.
                PrestaShopLogger::addLog(
                    'TwoPayment: tracking number update was not accepted by Two'
                    . ' (Order ID: ' . (int)$order->id
                    . ', Two order ID: ' . $orderpaymentdata['two_order_id']
                    . ', HTTP ' . $http_status
                    . ', gross_amount: ' . (isset($paymentdata['gross_amount']) ? $paymentdata['gross_amount'] : '?')
                    . ', response: ' . (string)$this->getTwoErrorMessage($response) . ')',
                    2
                );
                $this->addTwoBackOfficeWarning(
                    $this->l('The tracking number could not be forwarded to the invoice provider; the invoice will be sent without it.')
                );
            }
        } catch (Throwable $e) {
            // e.g. the order's cart no longer loads (purged carts, deleted
            // catalog products on legacy orders) — tracking was still
            // saved locally; the push is best-effort. Throwable, not
            // Exception: an engine-level Error in payload building must
            // not break the admin save either.
            PrestaShopLogger::addLog(
                'TwoPayment: tracking number update skipped - ' . $e->getMessage()
                . ' (Order ID: ' . (int)$order->id . ')',
                3
            );
        }
    }

    /**
     * Tracking number from the order's carrier record (order_carrier is
     * the canonical store; Order::$shipping_number is its legacy mirror).
     * Empty string when none is set.
     *
     * @param Order $order
     *
     * @return string
     */
    public function getTwoOrderTrackingNumber($order)
    {
        $id_order_carrier = (int)$order->getIdOrderCarrier();
        if ($id_order_carrier) {
            $order_carrier = new OrderCarrier($id_order_carrier);
            if (Validate::isLoadedObject($order_carrier)) {
                // A loaded carrier row is canonical: return its value even
                // when empty (or '0'), never fall through to the stale
                // legacy mirror.
                return trim((string)$order_carrier->tracking_number);
            }
        }

        return trim((string)$order->shipping_number);
    }

    public function hookActionOrderStatusUpdate($params)
    {
        $id_order = $params['id_order'];
        $order = new Order((int) $id_order);
        $new_order_status = $params['newOrderStatus'];
        if ($order->module == $this->name) {
            $orderpaymentdata = $this->getTwoOrderPaymentData($id_order);
            if ($orderpaymentdata && isset($orderpaymentdata['two_order_id'])) {
                $two_order_id = $orderpaymentdata['two_order_id'];

                if ($new_order_status->id == Configuration::get('PS_TWO_OS_CANCELLED_MAP')) {
                    $this->setTwoPaymentRequest('/v1/order/' . $two_order_id . '/cancel', [], 'POST');
                    $response = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
                    if (isset($response['id']) && $response['id']) {
                        $resolved_terms = $this->resolveTwoPaymentTermsFromOrderResponse(
                            $response,
                            isset($orderpaymentdata['two_day_on_invoice']) ? (string)$orderpaymentdata['two_day_on_invoice'] : (string)$this->getSelectedPaymentTerm(),
                            isset($orderpaymentdata['two_payment_term_type']) ? $orderpaymentdata['two_payment_term_type'] : Configuration::get('PS_TWO_PAYMENT_TERM_TYPE')
                        );
                        $payment_data = array(
                            'two_order_id' => $response['id'],
                            'two_order_reference' => $response['merchant_reference'],
                            'two_order_state' => $response['state'],
                            'two_order_status' => $response['status'],
                            'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
                            'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
                            'two_invoice_url' => $response['invoice_url'],
                            'two_invoice_id' => isset($response['invoice_details']['id']) ? $response['invoice_details']['id'] : (isset($orderpaymentdata['two_invoice_id']) ? $orderpaymentdata['two_invoice_id'] : null),
                        );
                        $this->setTwoOrderPaymentData($id_order, $payment_data);
                    }
                } else if ($this->isFulfillmentTriggerStatus($new_order_status->id) && $this->finalize_purchase_shipping) {
                    // Complete fulfillment using the new fulfillments endpoint - wrapped in try-catch for safety
                    try {
                        PrestaShopLogger::addLog('TwoPayment: Initiating complete fulfillment for Two order ID: ' . $two_order_id . ', Order ID: ' . $id_order . ', Triggered by status: ' . $new_order_status->name . ' (ID: ' . $new_order_status->id . ')', 1);

                        $stored_two_state = isset($orderpaymentdata['two_order_state']) ? strtoupper(trim((string)$orderpaymentdata['two_order_state'])) : '';
                        if ($this->shouldBlockTwoFulfillmentByTwoState($stored_two_state)) {
                            $this->applyTwoCancelledOrderStateProfileToStatusObject($new_order_status, (int)$order->id_lang);
                            $this->addTwoBackOfficeWarning($this->l('Fulfillment blocked: this Two order is cancelled at provider. The order status has been reverted to cancelled.'));
                            PrestaShopLogger::addLog(
                                'TwoPayment: Fulfillment blocked for cancelled Two order ' . $two_order_id .
                                ' (stored state=' . $stored_two_state . '). Fulfillment status change will be forced to cancelled for order ' . $id_order,
                                2
                            );
                            return;
                        }
                        
                        // Validate order state before attempting fulfillment
                        $current_two_order = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
                        if (!$current_two_order || !isset($current_two_order['state'])) {
                            PrestaShopLogger::addLog('TwoPayment: Cannot retrieve Two order state for fulfillment. Two order ID: ' . $two_order_id, 3);
                            return;
                        }

                        $provider_two_state = strtoupper(trim((string)$current_two_order['state']));
                        if ($this->shouldBlockTwoFulfillmentByTwoState($provider_two_state)) {
                            $resolved_terms = $this->resolveTwoPaymentTermsFromOrderResponse(
                                $current_two_order,
                                isset($orderpaymentdata['two_day_on_invoice']) ? (string)$orderpaymentdata['two_day_on_invoice'] : (string)$this->getSelectedPaymentTerm(),
                                isset($orderpaymentdata['two_payment_term_type']) ? $orderpaymentdata['two_payment_term_type'] : Configuration::get('PS_TWO_PAYMENT_TERM_TYPE')
                            );
                            $payment_data = array(
                                'two_order_id' => $two_order_id,
                                'two_order_reference' => isset($current_two_order['merchant_reference']) ? $current_two_order['merchant_reference'] : (isset($orderpaymentdata['two_order_reference']) ? $orderpaymentdata['two_order_reference'] : ''),
                                'two_order_state' => $provider_two_state,
                                'two_order_status' => isset($current_two_order['status']) ? $current_two_order['status'] : (isset($orderpaymentdata['two_order_status']) ? $orderpaymentdata['two_order_status'] : ''),
                                'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
                                'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
                                'two_invoice_url' => isset($current_two_order['invoice_url']) ? $current_two_order['invoice_url'] : (isset($orderpaymentdata['two_invoice_url']) ? $orderpaymentdata['two_invoice_url'] : ''),
                                'two_invoice_id' => isset($current_two_order['invoice_details']['id']) ? $current_two_order['invoice_details']['id'] : (isset($orderpaymentdata['two_invoice_id']) ? $orderpaymentdata['two_invoice_id'] : null),
                            );
                            $this->setTwoOrderPaymentData((int)$id_order, $payment_data);
                            $this->applyTwoCancelledOrderStateProfileToStatusObject($new_order_status, (int)$order->id_lang);
                            $this->addTwoBackOfficeWarning($this->l('Fulfillment blocked: this Two order is cancelled at provider. The order status has been reverted to cancelled.'));
                            PrestaShopLogger::addLog(
                                'TwoPayment: Fulfillment blocked for cancelled Two order ' . $two_order_id .
                                ' (provider state=' . $provider_two_state . '). Fulfillment status change will be forced to cancelled for order ' . $id_order,
                                2
                            );
                            return;
                        }
                        
                        // Only attempt fulfillment if order is in CONFIRMED state
                        // Only CONFIRMED orders can be fulfilled (VERIFIED orders must be confirmed first to ensure they have been sent to the checkout success page)
                        if (!$this->isTwoOrderFulfillableState($provider_two_state)) {
                            PrestaShopLogger::addLog('TwoPayment: Two order not in fulfillable state. Current state: ' . $provider_two_state . ', Expected: CONFIRMED. Two order ID: ' . $two_order_id, 2);
                            return;
                        }
                        
                        $response = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id . '/fulfillments', [], 'POST');
                        
                        if (isset($response['fulfilled_order']['id']) && $response['fulfilled_order']['id']) {
                            PrestaShopLogger::addLog('TwoPayment: Fulfillment successful for Two order ID: ' . $two_order_id . ', Fulfilled order ID: ' . $response['fulfilled_order']['id'], 1);
                            // Refresh order data from Two to avoid overwriting the stored Two order ID with fulfillment ID
                            $order_after = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
                            if (isset($order_after['id']) && $order_after['id']) {
                                $resolved_terms = $this->resolveTwoPaymentTermsFromOrderResponse(
                                    $order_after,
                                    isset($orderpaymentdata['two_day_on_invoice']) ? (string)$orderpaymentdata['two_day_on_invoice'] : (string)$this->getSelectedPaymentTerm(),
                                    isset($orderpaymentdata['two_payment_term_type']) ? $orderpaymentdata['two_payment_term_type'] : Configuration::get('PS_TWO_PAYMENT_TERM_TYPE')
                                );
                                $payment_data = array(
                                    'two_order_id' => $two_order_id,
                                    'two_order_reference' => isset($order_after['merchant_reference']) ? $order_after['merchant_reference'] : (isset($orderpaymentdata['two_order_reference']) ? $orderpaymentdata['two_order_reference'] : ''),
                                    'two_order_state' => isset($order_after['state']) ? $order_after['state'] : (isset($orderpaymentdata['two_order_state']) ? $orderpaymentdata['two_order_state'] : ''),
                                    'two_order_status' => isset($order_after['status']) ? $order_after['status'] : (isset($orderpaymentdata['two_order_status']) ? $orderpaymentdata['two_order_status'] : ''),
                                    'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
                                    'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
                                    'two_invoice_url' => isset($order_after['invoice_url']) ? $order_after['invoice_url'] : (isset($orderpaymentdata['two_invoice_url']) ? $orderpaymentdata['two_invoice_url'] : ''),
                                    'two_invoice_id' => isset($order_after['invoice_details']['id']) ? $order_after['invoice_details']['id'] : (isset($orderpaymentdata['two_invoice_id']) ? $orderpaymentdata['two_invoice_id'] : null),
                                );
                                // Note: invoice_details (payment info) is NOT stored in DB - it's fetched from Two API when needed
                                // This ensures payment details are always current and avoids DB schema changes
                                
                                $this->setTwoOrderPaymentData($id_order, $payment_data);
                            }
                            
                            // Invoice Upload: Upload PrestaShop invoice to Two when using own invoices
                            $use_own_invoices = Configuration::get('PS_TWO_USE_OWN_INVOICES');
                            PrestaShopLogger::addLog(
                                'TwoPayment: Invoice upload check - PS_TWO_USE_OWN_INVOICES=' . ($use_own_invoices ? 'YES' : 'NO') . ', Order ID=' . $id_order,
                                1,
                                null,
                                'Order',
                                $id_order
                            );
                            
                            if ($use_own_invoices) {
                                // Re-fetch payment data to ensure we have the latest invoice_id
                                $orderpaymentdata_refreshed = $this->getTwoOrderPaymentData($id_order);
                                PrestaShopLogger::addLog(
                                    'TwoPayment: Triggering invoice upload - two_invoice_id=' . 
                                    (isset($orderpaymentdata_refreshed['two_invoice_id']) ? $orderpaymentdata_refreshed['two_invoice_id'] : 'NOT SET'),
                                    1,
                                    null,
                                    'Order',
                                    $id_order
                                );
                                $this->uploadInvoiceAfterFulfillment($id_order, $orderpaymentdata_refreshed);
                            }
                        } else {
                            // Log fulfillment failure with detailed error information
                            $error_message = 'Unknown error';
                            if (isset($response['error'])) {
                                $error_message = is_array($response['error']) ? json_encode($response['error']) : $response['error'];
                            } elseif (isset($response['message'])) {
                                $error_message = $response['message'];
                            }
                            $response_summary = $this->buildTwoApiResponseLogSummary($response);
                            PrestaShopLogger::addLog(
                                'TwoPayment: Fulfillment failed for Two order ID: ' . $two_order_id .
                                ', Error: ' . $error_message .
                                ', Response Summary: ' . json_encode($response_summary),
                                3
                            );
                            
                            // Don't interfere with PrestaShop's status change process
                            // Just log the error - admin can check logs for fulfillment issues
                        }
                    } catch (Exception $e) {
                        // Catch any exceptions to prevent breaking the order status change
                        PrestaShopLogger::addLog('TwoPayment: Exception during fulfillment for Two order ID: ' . $two_order_id . ', Exception: ' . $e->getMessage(), 3);
                    }
                } else if ($new_order_status->id == Configuration::get('PS_TWO_OS_REFUNDED_MAP')) {
                    // Full refund: issue refund call with no request body - wrapped in try-catch for safety
                    try {
                        PrestaShopLogger::addLog('TwoPayment: Initiating full refund for Two order ID: ' . $two_order_id . ', Order ID: ' . $id_order . ', Triggered by status: ' . $new_order_status->name . ' (ID: ' . $new_order_status->id . ')', 1);
                        
                        // Fetch current Two order to check if already fully refunded and validate refundable state
                        $current_two_order = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
                        if (!$current_two_order || !isset($current_two_order['id'])) {
                            PrestaShopLogger::addLog('TwoPayment: Cannot retrieve Two order for refund check. Two order ID: ' . $two_order_id . ', Order ID: ' . $order->id, 3);
                            return;
                        }
                        
                        // Validate order state: Two only allows refunds for FULFILLED orders
                        $order_state = isset($current_two_order['state']) ? $current_two_order['state'] : null;
                        if ($order_state !== 'FULFILLED' && $order_state !== 'REFUNDED') {
                            PrestaShopLogger::addLog('TwoPayment: Order not in refundable state. Current state: ' . $order_state . '. Two only allows refunds for FULFILLED orders. Two order ID: ' . $two_order_id . ', Order ID: ' . $order->id, 2);
                            return;
                        }
                        
                        // Check if order is already fully refunded to prevent duplicate refund calls
                        // This handles cases where admin changes status away from "Refunded" then back to "Refunded"
                        // Note: We don't just rely on order `state` as it shows "REFUNDED" even for partial refunds
                        
                        // Check 1: Order state is already REFUNDED (skip if already refunded)
                        if ($order_state === 'REFUNDED') {
                            PrestaShopLogger::addLog('TwoPayment: Order already refunded (state: REFUNDED). Skipping refund call for Two order ID: ' . $two_order_id . ', Order ID: ' . $order->id, 1);
                            return;
                        }
                        
                        // Check 2: Invoice payment status is already REFUNDED
                        $invoice_payment_status = isset($current_two_order['invoice_details']['payment_status']) ? $current_two_order['invoice_details']['payment_status'] : null;
                        if ($invoice_payment_status === 'REFUNDED') {
                            PrestaShopLogger::addLog('TwoPayment: Invoice already refunded (payment_status: REFUNDED). Skipping refund call for Two order ID: ' . $two_order_id . ', Order ID: ' . $order->id, 1);
                            return;
                        }
                        
                        // Check 3: Verify if full refund already exists by checking refunds array
                        // Sum of refund total_amount (absolute values) should equal gross_amount for full refund
                        // This is the most reliable check as it verifies the actual refunded amount
                        if (isset($current_two_order['refunds']) && is_array($current_two_order['refunds']) && !empty($current_two_order['refunds'])) {
                            $total_refunded = 0;
                            $gross_amount = isset($current_two_order['gross_amount']) ? (float)$current_two_order['gross_amount'] : 0;
                            
                            foreach ($current_two_order['refunds'] as $refund) {
                                if (isset($refund['total_amount'])) {
                                    // total_amount is negative, so we use absolute value
                                    $total_refunded += abs((float)$refund['total_amount']);
                                }
                            }
                            
                            // If total refunded equals gross amount, full refund already exists
                            if ($gross_amount > 0 && abs($total_refunded - $gross_amount) < 0.01) {
                                PrestaShopLogger::addLog('TwoPayment: Full refund already exists (refunded: ' . $total_refunded . ', gross: ' . $gross_amount . '). Skipping refund call for Two order ID: ' . $two_order_id . ', Order ID: ' . $order->id, 1);
                                return;
                            }
                        }
                        
                        // Generate idempotency key to prevent duplicate refund calls (race condition protection)
                        // Format: refund_{order_id}_{unique_hash} ensures uniqueness per refund attempt
                        // Uses microtime + uniqid for maximum uniqueness even in high-concurrency scenarios
                        $idempotency_key = 'refund_' . $two_order_id . '_' . md5($order->id . '_' . microtime(true) . '_' . uniqid('', true));
                        
                        // Issue refund call with no request body (full refund) and idempotency key
                        $response = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id . '/refund', [], 'POST', ['X-Idempotency-Key: ' . $idempotency_key]);
                        
                        // Extract HTTP status code from response
                        $http_status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
                        
                        // Only treat as success if HTTP status is 201 (Created)
                        if ($http_status === self::HTTP_STATUS_CREATED && isset($response['id']) && $response['id']) {
                            // Fetch latest order snapshot to update local state/status
                            $order_after = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
                            if (isset($order_after['id']) && $order_after['id']) {
                                $resolved_terms = $this->resolveTwoPaymentTermsFromOrderResponse(
                                    $order_after,
                                    isset($orderpaymentdata['two_day_on_invoice']) ? (string)$orderpaymentdata['two_day_on_invoice'] : (string)$this->getSelectedPaymentTerm(),
                                    isset($orderpaymentdata['two_payment_term_type']) ? $orderpaymentdata['two_payment_term_type'] : Configuration::get('PS_TWO_PAYMENT_TERM_TYPE')
                                );
                                $payment_data = array(
                                    'two_order_id' => $two_order_id,
                                    'two_order_reference' => isset($order_after['merchant_reference']) ? $order_after['merchant_reference'] : (isset($orderpaymentdata['two_order_reference']) ? $orderpaymentdata['two_order_reference'] : ''),
                                    'two_order_state' => isset($order_after['state']) ? $order_after['state'] : (isset($orderpaymentdata['two_order_state']) ? $orderpaymentdata['two_order_state'] : ''),
                                    'two_order_status' => isset($order_after['status']) ? $order_after['status'] : (isset($orderpaymentdata['two_order_status']) ? $orderpaymentdata['two_order_status'] : ''),
                                    'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
                                    'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
                                    'two_invoice_url' => isset($order_after['invoice_url']) ? $order_after['invoice_url'] : (isset($orderpaymentdata['two_invoice_url']) ? $orderpaymentdata['two_invoice_url'] : ''),
                                    'two_invoice_id' => isset($order_after['invoice_details']['id']) ? $order_after['invoice_details']['id'] : (isset($orderpaymentdata['two_invoice_id']) ? $orderpaymentdata['two_invoice_id'] : null),
                                );
                                $this->setTwoOrderPaymentData($order->id, $payment_data);
                            }
                            PrestaShopLogger::addLog('TwoPayment: Full refund successful (HTTP ' . self::HTTP_STATUS_CREATED . ') for Two order ID: ' . $two_order_id . ', Order ID: ' . $order->id . ', Idempotency Key: ' . $idempotency_key, 1);
                        } else {
                            // Log refund failure with detailed error information including HTTP status
                            $error_message = 'Unknown error';
                            if (isset($response['error'])) {
                                $error_message = is_array($response['error']) ? json_encode($response['error']) : $response['error'];
                            } elseif (isset($response['message'])) {
                                $error_message = $response['message'];
                            }
                            
                            // Logging with HTTP status and context
                            $log_message = 'TwoPayment: Full refund FAILED for Two order ID: ' . $two_order_id . ', Order ID: ' . $order->id;
                            $log_message .= ', HTTP Status: ' . ($http_status > 0 ? $http_status : 'Unknown');
                            $log_message .= ', Error: ' . $error_message;
                            $log_message .= ', Idempotency Key: ' . $idempotency_key;
                            $log_message .= ', Response Summary: ' . json_encode($this->buildTwoApiResponseLogSummary($response));
                            
                            PrestaShopLogger::addLog($log_message, 3);
                            
                            // Log specific error scenarios for easier troubleshooting
                            if ($http_status === 400) {
                                PrestaShopLogger::addLog('TwoPayment: Refund failed - Bad Request (400). Order may not be in refundable state or invalid data. Two order ID: ' . $two_order_id . ', Order ID: ' . $order->id, 3);
                            } elseif ($http_status === 409) {
                                PrestaShopLogger::addLog('TwoPayment: Refund failed - Conflict (409). Possible duplicate refund attempt. Two order ID: ' . $two_order_id . ', Order ID: ' . $order->id, 3);
                            } elseif ($http_status >= self::HTTP_STATUS_SERVER_ERROR) {
                                PrestaShopLogger::addLog('TwoPayment: Refund failed - Server Error (' . $http_status . '). Two API temporarily unavailable. Two order ID: ' . $two_order_id . ', Order ID: ' . $order->id, 3);
                            } elseif ($http_status === 0) {
                                PrestaShopLogger::addLog('TwoPayment: Refund failed - No HTTP response (connection error). Check network connectivity. Two order ID: ' . $two_order_id . ', Order ID: ' . $order->id, 3);
                            }
                            
                            // Don't interfere with PrestaShop's status change process
                        }
                    } catch (Exception $e) {
                        // Catch any exceptions to prevent breaking the order status change
                        PrestaShopLogger::addLog('TwoPayment: Exception during refund for Two order ID: ' . $two_order_id . ', Order ID: ' . $order->id . ', Exception: ' . $e->getMessage() . ', Trace: ' . $e->getTraceAsString(), 3);
                    }
                }
            }
        }
    }

    /**
     * Handle PrestaShop credit slip creation (partial refunds).
     *
     * PrestaShop credit slips are the mechanism for partial refunds. The
     * full-refund path in hookActionOrderStatusUpdate only fires on a status
     * change to "Refunded" and issues a body-less full refund; it does NOT
     * cover credit slips, so partial refunds previously reached Two only when
     * the merchant used the Two merchant portal.
     *
     * This hook builds an {amount, currency} partial-refund payload from the
     * credit slip and calls POST /v1/order/{id}/refund. Two's refund endpoint
     * accepts a simple {amount, currency} body for partial refunds (line_items
     * optional) - confirmed against the checkout-api RefundRequestSchema
     * (PartialRefundRequestSchema) - so we avoid mapping PrestaShop's
     * credit-slip product list to Two line items.
     *
     * Idempotency + duplicate-refund protection:
     *  - The idempotency key is derived from the credit slip ID
     *    (partial_refund_{two_order_id}_slip_{id}), NOT order id + amount. Two
     *    separate partial refunds of the same amount on one order have distinct
     *    slip IDs and therefore distinct keys - they must NOT collide.
     *  - A remaining-refundable-balance guard (gross_amount minus the sum of
     *    existing Two refunds) is enforced BEFORE calling the API. This
     *    generalises the full-refund path's "already fully refunded" guards:
     *    it blocks over-refunding and blocks the double-refund race where a
     *    full-amount slip fires alongside a status change to Refunded (both
     *    hooks can fire for a full-amount slip, and the status hook's own
     *    guards do NOT protect this hook). It deliberately does NOT
     *    blanket-skip on Two state == REFUNDED, because Two reports REFUNDED
     *    even after a partial refund; a blanket skip would break legitimate
     *    sequential partial refunds.
     *
     * Best-effort: any failure is logged and swallowed so the admin's
     * credit-slip action is never broken.
     *
     * @param array $params PrestaShop hook params; expects order_slip.
     * @return void
     */
    public function hookActionOrderSlipAdd($params)
    {
        try {
            if (!is_array($params) || !isset($params['order_slip']) || !is_object($params['order_slip'])) {
                return;
            }

            $slip = $params['order_slip'];
            $slip_id = isset($slip->id) ? (int)$slip->id : 0;
            if ($slip_id <= 0) {
                PrestaShopLogger::addLog('TwoPayment: Partial refund skipped - credit slip has no usable ID.', 2);
                return;
            }

            $order = isset($params['order']) && is_object($params['order']) ? $params['order'] : null;
            if ($order === null) {
                $id_order = isset($slip->id_order) ? (int)$slip->id_order : 0;
                if ($id_order > 0) {
                    $order = new Order($id_order);
                }
            }
            if (!$order || !Validate::isLoadedObject($order) || $order->module != $this->name) {
                return;
            }
            $id_order = (int)$order->id;

            $orderpaymentdata = $this->getTwoOrderPaymentData($id_order);
            if (!$orderpaymentdata || empty($orderpaymentdata['two_order_id'])) {
                // Not a Two order (or no Two mapping): nothing to refund at Two.
                return;
            }
            $two_order_id = $orderpaymentdata['two_order_id'];

            $slip_amount = $this->getTwoCreditSlipGrossAmount($slip);
            if ($slip_amount <= 0) {
                PrestaShopLogger::addLog('TwoPayment: Partial refund skipped - non-positive slip amount for Two order ID: ' . $two_order_id . ', Slip ID: ' . $slip_id, 2);
                return;
            }

            PrestaShopLogger::addLog('TwoPayment: Initiating partial refund for Two order ID: ' . $two_order_id . ', Order ID: ' . $id_order . ', Slip ID: ' . $slip_id . ', Amount: ' . $slip_amount, 1);

            // Fetch the current Two order to validate refundable state and remaining balance.
            $current_two_order = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
            if (!$current_two_order || !isset($current_two_order['id'])) {
                PrestaShopLogger::addLog('TwoPayment: Cannot retrieve Two order for partial refund check. Two order ID: ' . $two_order_id . ', Order ID: ' . $id_order, 3);
                return;
            }

            // Two only allows refunds for FULFILLED orders. REFUNDED is allowed
            // too: the state shows REFUNDED even after a partial refund, and
            // further partial refunds within the remaining balance are valid.
            $order_state = isset($current_two_order['state']) ? $current_two_order['state'] : null;
            if ($order_state !== 'FULFILLED' && $order_state !== 'REFUNDED') {
                PrestaShopLogger::addLog('TwoPayment: Partial refund skipped - order not in refundable state. Current state: ' . $order_state . '. Two order ID: ' . $two_order_id . ', Order ID: ' . $id_order, 2);
                return;
            }

            // Remaining-balance guard: gross minus the sum of existing refunds.
            $gross_amount = isset($current_two_order['gross_amount']) ? (float)$current_two_order['gross_amount'] : 0.0;
            $already_refunded = $this->getTwoOrderRefundedTotal($current_two_order);
            $remaining = $gross_amount - $already_refunded;

            // Fail CLOSED when we can't establish the order gross. Unlike the
            // full-refund path (which posts a body-less refund whose amount Two
            // computes server-side), this path sends a client-specified amount,
            // so a missing/zero gross_amount would otherwise disable the
            // over-refund guard entirely and allow an unbounded, arbitrary-many
            // refund. If we cannot validate the balance, do not refund.
            if ($gross_amount <= 0) {
                PrestaShopLogger::addLog('TwoPayment: Partial refund skipped - cannot determine order gross amount to validate refundable balance. Two order ID: ' . $two_order_id . ', Order ID: ' . $id_order . ', Slip ID: ' . $slip_id, 3);
                return;
            }

            // Reject if this slip would push total refunds past the order gross.
            // The 0.01 tolerance absorbs 2dp rounding. This blocks over-refunding
            // and the full-amount-slip + status-change double-refund race.
            if ($slip_amount > ($remaining + 0.01)) {
                PrestaShopLogger::addLog('TwoPayment: Partial refund rejected - amount ' . $slip_amount . ' exceeds remaining refundable balance ' . $remaining . ' (gross: ' . $gross_amount . ', already refunded: ' . $already_refunded . '). Two order ID: ' . $two_order_id . ', Order ID: ' . $id_order . ', Slip ID: ' . $slip_id, 2);
                return;
            }

            // The refund currency must match the Two order currency.
            $currency = isset($current_two_order['currency']) && $current_two_order['currency']
                ? $current_two_order['currency']
                : $this->getTwoOrderCurrencyIso($order);

            // Fail CLOSED on an unresolved currency rather than POSTing a
            // malformed {amount, currency:''} body: an empty/missing currency
            // could be silently coerced server-side to a different currency,
            // turning a correct amount into a wrong-magnitude refund.
            if ($currency === '' || $currency === null) {
                PrestaShopLogger::addLog('TwoPayment: Partial refund skipped - could not resolve refund currency. Two order ID: ' . $two_order_id . ', Order ID: ' . $id_order . ', Slip ID: ' . $slip_id, 3);
                return;
            }

            $payload = $this->buildTwoPartialRefundPayload($slip_amount, $currency);

            // Idempotency key derived from the credit slip ID (NOT amount) so
            // two same-amount partial refunds on one order don't collide.
            // Scoped by the Two order ID as well so the key is unambiguous even
            // if two PrestaShop installs sharing one Two merchant account
            // happen to mint the same autoincrement slip ID.
            $idempotency_key = 'partial_refund_' . $two_order_id . '_slip_' . $slip_id;

            $response = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id . '/refund', $payload, 'POST', ['X-Idempotency-Key: ' . $idempotency_key]);

            $http_status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
            if ($http_status === self::HTTP_STATUS_CREATED && isset($response['id']) && $response['id']) {
                PrestaShopLogger::addLog('TwoPayment: Partial refund successful (HTTP ' . self::HTTP_STATUS_CREATED . ') for Two order ID: ' . $two_order_id . ', Order ID: ' . $id_order . ', Slip ID: ' . $slip_id . ', Idempotency Key: ' . $idempotency_key, 1);

                // Refresh stored payment data with the latest order snapshot.
                $order_after = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
                if (isset($order_after['id']) && $order_after['id']) {
                    $resolved_terms = $this->resolveTwoPaymentTermsFromOrderResponse(
                        $order_after,
                        isset($orderpaymentdata['two_day_on_invoice']) ? (string)$orderpaymentdata['two_day_on_invoice'] : (string)$this->getSelectedPaymentTerm(),
                        isset($orderpaymentdata['two_payment_term_type']) ? $orderpaymentdata['two_payment_term_type'] : Configuration::get('PS_TWO_PAYMENT_TERM_TYPE')
                    );
                    $payment_data = array(
                        'two_order_id' => $two_order_id,
                        'two_order_reference' => isset($order_after['merchant_reference']) ? $order_after['merchant_reference'] : (isset($orderpaymentdata['two_order_reference']) ? $orderpaymentdata['two_order_reference'] : ''),
                        'two_order_state' => isset($order_after['state']) ? $order_after['state'] : (isset($orderpaymentdata['two_order_state']) ? $orderpaymentdata['two_order_state'] : ''),
                        'two_order_status' => isset($order_after['status']) ? $order_after['status'] : (isset($orderpaymentdata['two_order_status']) ? $orderpaymentdata['two_order_status'] : ''),
                        'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
                        'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
                        'two_invoice_url' => isset($order_after['invoice_url']) ? $order_after['invoice_url'] : (isset($orderpaymentdata['two_invoice_url']) ? $orderpaymentdata['two_invoice_url'] : ''),
                        'two_invoice_id' => isset($order_after['invoice_details']['id']) ? $order_after['invoice_details']['id'] : (isset($orderpaymentdata['two_invoice_id']) ? $orderpaymentdata['two_invoice_id'] : null),
                    );
                    $this->setTwoOrderPaymentData($id_order, $payment_data);
                }
            } else {
                $error_message = 'Unknown error';
                if (isset($response['error'])) {
                    $error_message = is_array($response['error']) ? json_encode($response['error']) : $response['error'];
                } elseif (isset($response['message'])) {
                    $error_message = $response['message'];
                }
                $log_message = 'TwoPayment: Partial refund FAILED for Two order ID: ' . $two_order_id . ', Order ID: ' . $id_order . ', Slip ID: ' . $slip_id;
                $log_message .= ', HTTP Status: ' . ($http_status > 0 ? $http_status : 'Unknown');
                $log_message .= ', Error: ' . $error_message;
                $log_message .= ', Idempotency Key: ' . $idempotency_key;
                $log_message .= ', Response Summary: ' . json_encode($this->buildTwoApiResponseLogSummary($response));
                PrestaShopLogger::addLog($log_message, 3);
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog('TwoPayment: Exception during partial refund. Exception: ' . $e->getMessage() . ', Trace: ' . $e->getTraceAsString(), 3);
        }
    }

    /**
     * Compute the gross (tax-inclusive) refund amount from a PrestaShop credit
     * slip: refunded products (tax incl) plus refunded shipping (tax incl).
     * Falls back to the legacy amount / shipping_cost_amount fields when the
     * tax-incl totals are absent.
     *
     * @param object $slip OrderSlip
     * @return float
     */
    public function getTwoCreditSlipGrossAmount($slip)
    {
        $products = 0.0;
        if (isset($slip->total_products_tax_incl) && $slip->total_products_tax_incl !== null && $slip->total_products_tax_incl !== '') {
            $products = (float)$slip->total_products_tax_incl;
        } elseif (isset($slip->amount)) {
            $products = (float)$slip->amount;
        }

        $shipping = 0.0;
        if (isset($slip->total_shipping_tax_incl) && $slip->total_shipping_tax_incl !== null && $slip->total_shipping_tax_incl !== '') {
            $shipping = (float)$slip->total_shipping_tax_incl;
        } elseif (isset($slip->shipping_cost_amount)) {
            $shipping = (float)$slip->shipping_cost_amount;
        }

        return round($products + $shipping, 2);
    }

    /**
     * Sum the absolute value of every existing Two refund total on an order
     * response. Two records refund total_amount as a negative number.
     *
     * @param array $two_order Two order API response
     * @return float
     */
    public function getTwoOrderRefundedTotal($two_order)
    {
        $total = 0.0;
        if (isset($two_order['refunds']) && is_array($two_order['refunds'])) {
            foreach ($two_order['refunds'] as $refund) {
                if (isset($refund['total_amount'])) {
                    $total += abs((float)$refund['total_amount']);
                }
            }
        }
        return round($total, 2);
    }

    /**
     * Build the {amount, currency} partial-refund payload for Two. Amount is a
     * 2dp decimal string, matching Two's Money format.
     *
     * @param float $amount Gross refund amount
     * @param string $currency ISO currency code
     * @return array
     */
    public function buildTwoPartialRefundPayload($amount, $currency)
    {
        return array(
            'amount' => number_format((float)$amount, 2, '.', ''),
            'currency' => $currency,
        );
    }

    /**
     * Resolve the ISO currency code for a PrestaShop order (fallback when the
     * Two order response carries no currency).
     *
     * @param object $order Order
     * @return string
     */
    public function getTwoOrderCurrencyIso($order)
    {
        if (isset($order->id_currency) && (int)$order->id_currency > 0) {
            $currency = new Currency((int)$order->id_currency);
            if (Validate::isLoadedObject($currency)) {
                return (string)$currency->iso_code;
            }
        }
        return '';
    }

    /**
     * Intercept pending order-history inserts and force cancelled status when
     * a cancelled Two order is incorrectly moved to a blocked forward-processing state
     * (verified-ready or fulfillment-trigger states).
     *
     * @param array $params
     * @return void
     */
    public function hookActionObjectOrderHistoryAddBefore($params)
    {
        if (!is_array($params) || !isset($params['object']) || !is_object($params['object'])) {
            return;
        }

        $history = $params['object'];
        if (!isset($history->id_order) || !isset($history->id_order_state)) {
            return;
        }

        $id_order = (int)$history->id_order;
        $target_status = (int)$history->id_order_state;
        if ($id_order <= 0 || !$this->shouldBlockTwoStatusTransitionByCancelledState($target_status)) {
            return;
        }

        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order) || !isset($order->module) || $order->module !== $this->name) {
            return;
        }

        $latest_attempt = $this->getLatestTwoCheckoutAttemptByOrder($id_order);
        $attempt_status = is_array($latest_attempt) && isset($latest_attempt['status']) ? (string)$latest_attempt['status'] : '';
        if ($this->isTwoAttemptStatusTerminal($attempt_status)) {
            $attempt_two_order_id = is_array($latest_attempt) && isset($latest_attempt['two_order_id']) ? (string)$latest_attempt['two_order_id'] : '';
            $this->forceTwoCancelledOrderHistoryStateBeforeInsert($history, $order, $attempt_two_order_id, 'attempt', 'CANCELLED');
            return;
        }

        $orderpaymentdata = $this->getTwoOrderPaymentData($id_order);
        if (!is_array($orderpaymentdata) || !isset($orderpaymentdata['two_order_id']) || Tools::isEmpty($orderpaymentdata['two_order_id'])) {
            return;
        }

        $two_order_id = $orderpaymentdata['two_order_id'];
        $stored_two_state = isset($orderpaymentdata['two_order_state']) ? strtoupper(trim((string)$orderpaymentdata['two_order_state'])) : '';
        if ($this->shouldBlockTwoFulfillmentByTwoState($stored_two_state)) {
            $this->forceTwoCancelledOrderHistoryStateBeforeInsert($history, $order, $two_order_id, 'stored', $stored_two_state);
            return;
        }

        $current_two_order = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
        if (!is_array($current_two_order) || !isset($current_two_order['state'])) {
            return;
        }

        $provider_two_state = strtoupper(trim((string)$current_two_order['state']));
        if (!$this->shouldBlockTwoFulfillmentByTwoState($provider_two_state)) {
            return;
        }

        $resolved_terms = $this->resolveTwoPaymentTermsFromOrderResponse(
            $current_two_order,
            isset($orderpaymentdata['two_day_on_invoice']) ? (string)$orderpaymentdata['two_day_on_invoice'] : (string)$this->getSelectedPaymentTerm(),
            isset($orderpaymentdata['two_payment_term_type']) ? $orderpaymentdata['two_payment_term_type'] : Configuration::get('PS_TWO_PAYMENT_TERM_TYPE')
        );
        $payment_data = array(
            'two_order_id' => isset($current_two_order['id']) ? $current_two_order['id'] : $two_order_id,
            'two_order_reference' => isset($current_two_order['merchant_reference']) ? $current_two_order['merchant_reference'] : (isset($orderpaymentdata['two_order_reference']) ? $orderpaymentdata['two_order_reference'] : ''),
            'two_order_state' => $provider_two_state,
            'two_order_status' => isset($current_two_order['status']) ? $current_two_order['status'] : (isset($orderpaymentdata['two_order_status']) ? $orderpaymentdata['two_order_status'] : ''),
            'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
            'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
            'two_invoice_url' => isset($current_two_order['invoice_url']) ? $current_two_order['invoice_url'] : (isset($orderpaymentdata['two_invoice_url']) ? $orderpaymentdata['two_invoice_url'] : ''),
            'two_invoice_id' => isset($current_two_order['invoice_details']['id']) ? $current_two_order['invoice_details']['id'] : (isset($orderpaymentdata['two_invoice_id']) ? $orderpaymentdata['two_invoice_id'] : null),
        );
        $this->setTwoOrderPaymentData($id_order, $payment_data);
        $this->forceTwoCancelledOrderHistoryStateBeforeInsert($history, $order, $two_order_id, 'provider', $provider_two_state);
    }

    public function hookActionFrontControllerSetMedia()
    {
        // CRITICAL FIX: Only load Two assets on checkout pages to prevent conflicts and improve performance
        $controller_name = Tools::getValue('controller');
        $is_checkout_page = in_array($controller_name, ['order', 'orderopc']) || 
                           (isset($this->context->controller->php_self) && 
                            in_array($this->context->controller->php_self, ['order', 'order-opc']));
        
        // Additional check for module controllers (payment, confirmation, orderintent)
        $is_two_module_page = (isset($this->context->controller) && 
                              $this->context->controller instanceof ModuleFrontController &&
                              $this->context->controller->module->name === $this->name);
        
        if (!$is_checkout_page && !$is_two_module_page) {
            // Don't load Two assets on non-checkout pages
            return;
        }

        // CRITICAL FIX FOR PRESTASHOP 1.7.6.5: Multi-layer jQuery loading strategy
        // Issue: addJquery() may exist but not output jQuery to HTML on some installations
        // Solution: Triple-layer approach ensures jQuery is ALWAYS available
        
        // Layer 1: Try PrestaShop's native jQuery (works on most installations)
        try {
            if (method_exists($this->context->controller, 'addJquery')) {
                $this->context->controller->addJquery();
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Two Payment: PrestaShop addJquery() failed - ' . $e->getMessage(),
                2,
                null,
                'Module',
                $this->id
            );
        }
        
        // Layer 2: Try jQuery UI (includes jQuery as dependency)
        try {
            if (method_exists($this->context->controller, 'addJqueryUI')) {
                $this->context->controller->addJqueryUI('ui.core');
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Two Payment: PrestaShop addJqueryUI() failed - ' . $e->getMessage(),
                2,
                null,
                'Module',
                $this->id
            );
        }
        
        // Layer 3 moved to frontend runtime: local same-origin jQuery fallback in twopayment.js.
        // This avoids remote CDN dependency while preserving legacy compatibility behavior.

        $countries = Country::getCountries($this->context->language->id, false, false, false);
        $param_countries = array();
        foreach ($countries as $country) {
            $param_countries[$country['id_country']] = Tools::strtolower($country['iso_code']);
        }
        // Build FE i18n (strings are translated by PrestaShop according to current language)
        $i18n = array(
            'checking_eligibility' => $this->l('Checking Two payment eligibility...'),
            'checking_subtext' => $this->l('Please wait a moment while we verify your company details.'),
            'payment_approved_title' => $this->l('Payment Approved'),
            'payment_not_available_title' => $this->l('Payment Not Available'),
            'action_required_title' => $this->l('Action Required'),
            'payment_approved_message' => $this->l('Payment approved! Choose your payment terms below.'),
            'payment_not_available_message' => $this->l('Two payment is not available for this order.'),
            'generic_error' => $this->l('There was an issue processing your Two payment request. Please try again or choose another payment method.'),
            'order_intent_check_failed' => $this->l('Order intent check failed'),
            'invalid_response_from_server' => $this->l('Invalid response from server'),
            'choose_payment_terms' => $this->l('Choose the Buy Now, Pay Later option that works best for you'),
            'payment_period_starts' => $this->l('Your payment period starts when your order is fulfilled'),
            'invoice_likely_accepted_for' => $this->l('Your invoice with Two is likely to be accepted for %s'),
            'invoice_cannot_be_approved_for' => $this->l('Your invoice with Two cannot be approved at this time for %s'),
            'invoice_likely_accepted' => $this->l('Your invoice with Two is likely to be accepted'),
            'invoice_cannot_be_approved' => $this->l('Your invoice with Two cannot be approved at this time'),
            'invalid_phone_number' => $this->l('The phone number in your billing address appears to be invalid. Please go back and ensure you have entered a valid phone number for your country.'),
            'company_name_required' => $this->l('To pay with Two, go back to your billing address and enter your company name in the Company field.'),
            'company_name_required_business' => $this->l('Company name is required for business accounts.'),
            'organization_number_required' => $this->l('Please search and select a valid company to continue with Two payment.'),
            'select_company_to_use_two' => $this->l('To pay with Two, go back to your billing address and search for your company name. Select your company from the results to verify your business.'),
            'invalid_company' => $this->l('The company information provided is not valid. Please search and select a valid company.'),
            'company_not_found' => $this->l('We could not find your company. Please try a different company name or contact support.'),
            'credit_unavailable' => $this->l('Two payment is not available for this order. Please choose another payment method.'),
            'network_issue' => $this->l('There was a temporary issue verifying your payment. Please try again or choose another payment method.'),
            'resolve_payment_issue_before_continuing' => $this->l('Please resolve the payment issue before continuing.'),
            'approval_required' => $this->l('Payment approval required before proceeding'),
            'invoice_declined' => $this->l('Your invoice with Two cannot be approved at this time. Please select an alternative payment method.'),
            'invalid_email' => $this->l('The email address provided is invalid. Please check your email and try again.'),
            'invalid_address' => $this->l('The address provided is invalid. Please go back and verify your billing address details.'),
            'company_incomplete' => $this->l('Company information is incomplete. Go back to your billing address and select your company from the search results.'),
            'validation_error' => $this->l('Some of the information provided is invalid. Please check your billing address details and try again.'),
            'company_verify_failed' => $this->l('Company information could not be verified. Go back to your billing address and select your company from the search results.'),
            'company_verification_needed' => $this->l('Company Verification Needed'),
            'company_auto_resolve_hint' => $this->l('We found your company name but need you to verify it. Please go back to your billing address and select your company from the search results.'),
            'pay_in' => $this->l('Pay in'),
            'days' => $this->l('days'),
            'from_end_of_month' => $this->l('from end of month'),
            'end_of_month_plus_days' => $this->l('End of Month + %s days'),
        );

        // Checkout media render is a sanctioned refresh point for the backend
        // term list (TWO-24813); prime the cache before the cache-only reads
        // in getAvailablePaymentTerms / getDefaultPaymentTerm below.
        $this->getMerchantAvailableTerms(true);

        Media::addJsDef(array('twopayment' => array(
                'search_empty_text' => $this->l('No result found'),
                'checkout_host' => $this->getTwoCheckoutHostUrl(),
                'company_name_search' => $this->enable_company_name,
                'company_id_search' => $this->enable_company_id,
                'enable_department' => $this->enable_department,
                'enable_project' => $this->enable_project,
                'enable_order_intent' => $this->enable_order_intent,
                'use_account_type' => (int) Configuration::get('PS_TWO_USE_ACCOUNT_TYPE'),
                'order_intent_url' => $this->context->link->getModuleLink($this->name, 'orderintent'),
                'ajax_token' => Tools::getToken(false),
                'module_dir' => $this->_path,
                'client' => 'PS',
                'client_version' => $this->version,
                'countries' => $param_countries,
                'available_payment_terms' => $this->getAvailablePaymentTerms(),
                'default_payment_term' => $this->getDefaultPaymentTerm(),
                'payment_term_type' => Configuration::get('PS_TWO_PAYMENT_TERM_TYPE'),
                'i18n' => $i18n,
                'phone_i18n' => array(
                    'invalid_number' => $this->l('Invalid phone number'),
                    'invalid_country_code' => $this->l('Invalid country code'),
                    'too_short' => $this->l('Too short'),
                    'too_long' => $this->l('Too long'),
                    'must_match_country' => $this->l('Phone must match the selected country'),
                ),
        )));
        
        // Register Two payment CSS and JavaScript files
        $this->context->controller->registerStylesheet('two-css', 'modules/twopayment/views/css/two.css', array('priority' => 200, 'media' => 'all'));
        
        // CRITICAL FIX: Remove async loading and ensure proper load order for reliable initialization
        // Ensures they load AFTER jQuery
        $this->context->controller->registerJavascript('two-company-search', 'modules/twopayment/views/js/modules/TwoCompanySearch.js', array('priority' => 201, 'async' => false));
        $this->context->controller->registerJavascript('two-order-intent', 'modules/twopayment/views/js/modules/TwoOrderIntent.js', array('priority' => 202, 'async' => false));
        $this->context->controller->registerJavascript('two-field-validation', 'modules/twopayment/views/js/modules/TwoFieldValidation.js', array('priority' => 203, 'async' => false));
        // Phone validation removed - Two API handles phone number validation
        $this->context->controller->registerJavascript('two-checkout-manager', 'modules/twopayment/views/js/modules/TwoCheckoutManager.js', array('priority' => 205, 'async' => false));
        $this->context->controller->registerJavascript('two-script', 'modules/twopayment/views/js/twopayment.js', array('priority' => 206, 'async' => false));
    }

    /**
     * Back-office media hook.
     * Loads module admin styling for order widgets and module configuration views.
     */
    public function hookActionAdminControllerSetMedia()
    {
        if (!isset($this->context->controller) || !is_object($this->context->controller)) {
            return;
        }

        $controller = $this->context->controller;
        $controller_name = isset($controller->controller_name) ? (string) $controller->controller_name : '';
        $php_self = isset($controller->php_self) ? (string) $controller->php_self : '';
        $request_controller = (string) Tools::getValue('controller');
        $configure_module = (string) Tools::getValue('configure');

        $is_module_config_page = ($configure_module === $this->name);
        $is_order_admin_page = (stripos($controller_name, 'Order') !== false)
            || (stripos($php_self, 'order') !== false)
            || (stripos($request_controller, 'Order') !== false);

        if (!$is_module_config_page && !$is_order_admin_page) {
            return;
        }

        if (method_exists($controller, 'registerStylesheet')) {
            $controller->registerStylesheet(
                'module-twopayment-admin-css',
                'modules/twopayment/views/css/two.css',
                array('media' => 'all', 'priority' => 200)
            );

            return;
        }

        if (method_exists($controller, 'addCSS')) {
            $controller->addCSS($this->_path . 'views/css/two.css');
        }
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        if (Tools::isEmpty($this->merchant_short_name) || Tools::isEmpty($this->api_key)) {
            return;
        }

        // BUSINESS ACCOUNT RESTRICTION: Only show Two for business accounts (when account type is enabled)
        $cart = $this->context->cart;
        if (!Validate::isLoadedObject($cart) || $cart->id_address_invoice == 0) {
            PrestaShopLogger::addLog('TwoPayment: No valid cart or billing address found for payment options', 2);
            return [];
        }

        $billing_address = new Address($cart->id_address_invoice);
        if (!Validate::isLoadedObject($billing_address)) {
            PrestaShopLogger::addLog('TwoPayment: Invalid billing address for payment options', 2);
            return [];
        }

        if (!$this->checkCurrency($cart)) {
            PrestaShopLogger::addLog(
                'TwoPayment: Payment option hidden - unsupported cart currency for cart ' . (int)$cart->id,
                2
            );
            return [];
        }

        // If merchant uses account type selection, gate payment option to business accounts
        if ((int) Configuration::get('PS_TWO_USE_ACCOUNT_TYPE')) {
            $account_type = property_exists($billing_address, 'account_type') ? trim((string) $billing_address->account_type) : '';
            if ($account_type !== 'business') {
                PrestaShopLogger::addLog('TwoPayment: Payment option hidden - account type is not business (current: ' . ($account_type ?: 'not set') . ')', 1);
                return [];
            }
            PrestaShopLogger::addLog('TwoPayment: Payment option shown for business account path', 1);
        } else {
            // When account type selection is disabled, allow showing Two option; FE will prompt for company selection as needed
            PrestaShopLogger::addLog('TwoPayment: Payment option shown (account type disabled)', 1);
        }
        
        $payment_options = [
            $this->getTwoPaymentOption(),
        ];

        return $payment_options;
    }

    protected function getTwoPaymentOption()
    {
        $title = Configuration::get('PS_TWO_TITLE', $this->context->language->id);
        $subtitle = Configuration::get('PS_TWO_SUB_TITLE', $this->context->language->id);

        if (Tools::isEmpty($title)) {
            $title = $this->l('Pay with Two');
        }
        if (Tools::isEmpty($subtitle)) {
            $subtitle = $this->l('Buy now, pay later - instant credit');
        }

        // Order intent is now handled on frontend via AJAX
        $this->context->smarty->assign(array(
            'subtitle' => $subtitle,
            'enable_order_intent' => $this->enable_order_intent,
            'payment_enable' => true, // Always enable, frontend will handle approval
            'message' => '',
            'module_dir' => $this->_path, // Module directory path for assets
            'two_portal_url' => $this->getTwoPortalUrl(), // Dynamic portal URL based on environment
        ));

        $preTwoOption = new PaymentOption();
        $preTwoOption->setModuleName($this->name)
            ->setCallToActionText($title)
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true))
            ->setInputs(['token' => ['name' => 'token', 'type' => 'hidden', 'value' => Tools::getToken(false)]])
            ->setAdditionalInformation($this->context->smarty->fetch('module:twopayment/views/templates/hook/paymentinfo.tpl'));

        return $preTwoOption;
    }

    /**
     * Check if cart currency is allowed for this module according to PrestaShop payment restrictions.
     *
     * @param Cart $cart
     * @return bool
     */
    private function checkCurrency($cart)
    {
        if (!Validate::isLoadedObject($cart) || !isset($cart->id_currency) || (int)$cart->id_currency <= 0) {
            return false;
        }

        $currency_order = new Currency((int)$cart->id_currency);
        if (!Validate::isLoadedObject($currency_order)) {
            return false;
        }

        // Enforce provider-supported currency ISO list first, then apply PrestaShop module assignment check.
        // This keeps behavior explicit and documents covered currencies in-code.
        $currency_iso = strtoupper(trim((string)$currency_order->iso_code));
        if (Tools::isEmpty($currency_iso) || !in_array($currency_iso, self::TWO_SUPPORTED_CURRENCY_ISOS, true)) {
            return false;
        }

        if (!method_exists($this, 'getCurrency')) {
            return true;
        }

        $currencies_module = $this->getCurrency((int)$cart->id_currency);
        if (empty($currencies_module)) {
            return false;
        }

        foreach ($currencies_module as $currency_module) {
            if (isset($currency_module['id_currency']) && (int)$currency_module['id_currency'] === (int)$currency_order->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Public wrapper for currency compatibility checks used by front controllers.
     *
     * @param Cart $cart
     * @return bool
     */
    public function isCartCurrencySupportedByTwo($cart)
    {
        return $this->checkCurrency($cart);
    }

    /**
     * Build shared pricing data for Two payloads from a single line-item source.
     *
     * @param Cart $cart
     * @param string $contextLabel
     * @return array
     * @throws Exception
     */
    private function buildTwoOrderPricingData($cart, $contextLabel = 'order payload', $strictReconciliation = false, $paymentTermDays = null)
    {
        $line_items = $this->getTwoProductItems($cart);
        if (empty($line_items)) {
            PrestaShopLogger::addLog('TwoPayment: Cannot build ' . $contextLabel . ' - no valid line items', 3);
            throw new Exception('No valid line items in cart');
        }

        if (!$this->validateTwoLineItems($line_items)) {
            PrestaShopLogger::addLog('TwoPayment: Cannot build ' . $contextLabel . ' - invalid line item formulas', 3);
            throw new Exception('Invalid line item formulas');
        }

        $lineTotals = $this->calculateTwoLineItemTotals($line_items);
        $max_reconciliation_diff_cents = 0;
        if (!$this->validateTwoOrderReconciliationAgainstCart($cart, $lineTotals, $contextLabel, $max_reconciliation_diff_cents)) {
            if ($this->shouldBlockOnReconciliationDrift($contextLabel, $max_reconciliation_diff_cents, (bool)$strictReconciliation)) {
                PrestaShopLogger::addLog(
                    'TwoPayment: ' . $contextLabel . ' blocked by reconciliation policy. ' .
                    'Max drift=' . $this->getTwoRoundAmount($max_reconciliation_diff_cents / 100) .
                    ', Tolerance=' . $this->getTwoRoundAmount(self::ORDER_RECONCILIATION_TOLERANCE),
                    3
                );
                throw new Exception('Order totals do not reconcile with cart totals');
            }

            PrestaShopLogger::addLog(
                'TwoPayment: ' . $contextLabel . ' reconciliation drift logged as warning-only (intent precheck path).',
                2
            );
        }

        $tax_subtotals = $this->getTwoTaxSubtotals($line_items);
        $subtotalsTotals = $this->calculateOrderTotalsFromTaxSubtotals($tax_subtotals);
        if (
            !$this->isTwoAmountWithinTolerance($lineTotals['net'], $subtotalsTotals['net']) ||
            !$this->isTwoAmountWithinTolerance($lineTotals['tax'], $subtotalsTotals['tax']) ||
            !$this->isTwoAmountWithinTolerance($lineTotals['gross'], $subtotalsTotals['gross'])
        ) {
            PrestaShopLogger::addLog(
                'TwoPayment: Cannot build ' . $contextLabel . ' - tax subtotals mismatch line totals. ' .
                'Line(net/tax/gross)=(' . $this->getTwoRoundAmount($lineTotals['net']) . '/' .
                $this->getTwoRoundAmount($lineTotals['tax']) . '/' .
                $this->getTwoRoundAmount($lineTotals['gross']) . ') vs Subtotals=(' .
                $this->getTwoRoundAmount($subtotalsTotals['net']) . '/' .
                $this->getTwoRoundAmount($subtotalsTotals['tax']) . '/' .
                $this->getTwoRoundAmount($subtotalsTotals['gross']) . ')',
                3
            );
            throw new Exception('Tax subtotals do not reconcile with line items');
        }

        // Offset pricing fee (buyer surcharge) — appended AFTER product-line
        // reconciliation so it never perturbs the cart-vs-lines gate. The fee
        // is quoted from POST /v1/pricing/order/fee (fail-soft: a missing quote
        // simply omits the line and never blocks checkout). Applying it in the
        // shared pricing builder keeps the intent, create and update payloads
        // consistent, so the order-intent approval reconciles against the same
        // gross the create call sends. TWO-24752 / TWO-24893.
        $surchargeLine = $this->buildTwoSurchargeLineItemForCart($cart, $subtotalsTotals['gross'], $paymentTermDays);
        if ($surchargeLine !== null && $this->validateTwoLineItems(array($surchargeLine))) {
            $line_items[] = $surchargeLine;
            $tax_subtotals = $this->getTwoTaxSubtotals($line_items);
            $subtotalsTotals = $this->calculateOrderTotalsFromTaxSubtotals($tax_subtotals);
        }

        return [
            'line_items' => $line_items,
            'tax_subtotals' => $tax_subtotals,
            'net_amount' => $subtotalsTotals['net'],
            'tax_amount' => $subtotalsTotals['tax'],
            'gross_amount' => $subtotalsTotals['gross'],
            'discount_amount' => abs((float)$cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS)),
        ];
    }

    /**
     * Sum line-item monetary fields with stable 2-decimal arithmetic.
     *
     * @param array $line_items
     * @return array
     */
    private function calculateTwoLineItemTotals($line_items)
    {
        $net = 0.0;
        $tax = 0.0;
        $gross = 0.0;
        foreach ($line_items as $item) {
            $net = round($net + (float)(isset($item['net_amount']) ? $item['net_amount'] : 0), 2);
            $tax = round($tax + (float)(isset($item['tax_amount']) ? $item['tax_amount'] : 0), 2);
            $gross = round($gross + (float)(isset($item['gross_amount']) ? $item['gross_amount'] : 0), 2);
        }

        return [
            'net' => $net,
            'tax' => $tax,
            'gross' => $gross,
        ];
    }

    /**
     * Validate line-based totals against cart totals before sending to Two.
     *
     * @param Cart $cart
     * @param array $lineTotals
     * @param string $contextLabel
     * @return bool
     */
    private function validateTwoOrderReconciliationAgainstCart($cart, $lineTotals, $contextLabel, &$maxDiffCents = 0)
    {
        $maxDiffCents = 0;
        $lineNet = round((float)$lineTotals['net'], 2);
        $lineTax = round((float)$lineTotals['tax'], 2);
        $lineGross = round((float)$lineTotals['gross'], 2);

        if (!$this->isTwoAmountWithinTolerance($lineGross, $lineNet + $lineTax)) {
            $maxDiffCents = PHP_INT_MAX;
            PrestaShopLogger::addLog(
                'TwoPayment: ' . $contextLabel . ' reconciliation mismatch - line totals fail gross equation. ' .
                'gross=' . $this->getTwoRoundAmount($lineGross) . ', net+tax=' .
                $this->getTwoRoundAmount($lineNet + $lineTax),
                3
            );
            return false;
        }

        $cartGross = round((float)$cart->getOrderTotal(true, Cart::BOTH), 2);
        $cartNet = round((float)$cart->getOrderTotal(false, Cart::BOTH), 2);
        if ($cart->nbProducts() > 0 && $cartGross == 0.0 && $cartNet == 0.0) {
            PrestaShopLogger::addLog(
                'TwoPayment: Cart totals unavailable for ' . $contextLabel . '; skipping strict reconciliation gate.',
                2
            );
            return true;
        }

        $cartTax = round($cartGross - $cartNet, 2);
        $grossDiff = abs($lineGross - $cartGross);
        $netDiff = abs($lineNet - $cartNet);
        $taxDiff = abs($lineTax - $cartTax);

        // Compare in cents to avoid float boundary artifacts (e.g. visible 0.02 diff treated as 0.0200000001).
        $toleranceCents = $this->convertAmountToCents(self::ORDER_RECONCILIATION_TOLERANCE);
        $grossDiffCents = $this->convertAmountToCents($grossDiff);
        $netDiffCents = $this->convertAmountToCents($netDiff);
        $taxDiffCents = $this->convertAmountToCents($taxDiff);
        $maxDiffCents = max($grossDiffCents, $netDiffCents, $taxDiffCents);

        if (
            $grossDiffCents > $toleranceCents ||
            $netDiffCents > $toleranceCents ||
            $taxDiffCents > $toleranceCents
        ) {
            PrestaShopLogger::addLog(
                'TwoPayment: ' . $contextLabel . ' reconciliation mismatch - order totals mismatch cart totals. ' .
                'Line(net/tax/gross)=(' . $this->getTwoRoundAmount($lineNet) . '/' .
                $this->getTwoRoundAmount($lineTax) . '/' .
                $this->getTwoRoundAmount($lineGross) . '), ' .
                'Cart=(' . $this->getTwoRoundAmount($cartNet) . '/' .
                $this->getTwoRoundAmount($cartTax) . '/' .
                $this->getTwoRoundAmount($cartGross) . '), ' .
                'Diff=(' . $this->getTwoRoundAmount($netDiffCents / 100) . '/' .
                $this->getTwoRoundAmount($taxDiffCents / 100) . '/' .
                $this->getTwoRoundAmount($grossDiffCents / 100) . ')',
                3
            );
            return false;
        }

        return true;
    }

    /**
     * Decide whether reconciliation drift should block local payload generation.
     * Order intent remains permissive; create/update only block on material mismatches.
     *
     * @param string $contextLabel
     * @param int $maxDiffCents
     * @return bool
     */
    private function shouldBlockOnReconciliationDrift($contextLabel, $maxDiffCents, $strictReconciliation = false)
    {
        if ((bool)$strictReconciliation) {
            $strictToleranceCents = $this->convertAmountToCents(self::ORDER_RECONCILIATION_TOLERANCE);
            return (int)$maxDiffCents > $strictToleranceCents;
        }

        if (strpos($contextLabel, 'order intent') !== false) {
            return false;
        }

        // Create/update payloads must fail-closed when drift exceeds default tolerance.
        $createToleranceCents = $this->convertAmountToCents(self::ORDER_RECONCILIATION_TOLERANCE);
        return (int)$maxDiffCents > $createToleranceCents;
    }

    /**
     * Normalize decimal amount to integer cents for stable boundary comparisons.
     *
     * @param float|int|string $amount
     * @return int
     */
    private function convertAmountToCents($amount)
    {
        return (int) round(round((float)$amount, 2) * 100);
    }

    /**
     * Amount comparison helper with configurable tolerance.
     *
     * @param float $left
     * @param float $right
     * @param float|null $tolerance
     * @return bool
     */
    private function isTwoAmountWithinTolerance($left, $right, $tolerance = null)
    {
        if ($tolerance === null) {
            $tolerance = self::ORDER_RECONCILIATION_TOLERANCE;
        }

        return abs(round((float)$left, 2) - round((float)$right, 2)) <= (float)$tolerance;
    }



    public function getTwoIntentOrderData($cart, $customer, $currency, $address)
    {
        return $this->buildTwoIntentOrderData($cart, $customer, $currency, $address, false);
    }

    /**
     * Build order intent payload with selectable reconciliation strictness.
     *
     * @param Cart $cart
     * @param Customer $customer
     * @param Currency $currency
     * @param Address $address
     * @param bool $strictReconciliation
     * @return array
     */
    private function buildTwoIntentOrderData($cart, $customer, $currency, $address, $strictReconciliation)
    {
        // Validate cart has products before building order data
        if (!Validate::isLoadedObject($cart) || $cart->nbProducts() <= 0) {
            PrestaShopLogger::addLog('TwoPayment: Cannot build order intent - cart is empty or invalid', 3);
            throw new Exception('Cart is empty or invalid');
        }
        
        $contextLabel = (bool)$strictReconciliation ? 'order intent strict submit' : 'order intent';
        // Order intent pre-check remains permissive for UX refresh checks.
        // Payment-submit authoritative intent checks must be strict.
        $pricingData = $this->buildTwoOrderPricingData($cart, $contextLabel, (bool)$strictReconciliation);
        $line_items = $pricingData['line_items'];
        $tax_subtotals = $pricingData['tax_subtotals'];
        $final_net = $pricingData['net_amount'];
        $final_tax = $pricingData['tax_amount'];
        $final_gross = $pricingData['gross_amount'];
        $final_discount = $pricingData['discount_amount'];
        
        // Resolve invoice/shipping addresses for parity with create/update payloads.
        $invoice_address = Validate::isLoadedObject($address) ? $address : new Address((int)$cart->id_address_invoice);
        if (!Validate::isLoadedObject($invoice_address)) {
            PrestaShopLogger::addLog('TwoPayment: Cannot build order intent - invalid invoice address', 3);
            throw new Exception('Invalid invoice address');
        }

        $delivery_address = new Address((int)$cart->id_address_delivery);
        if (!Validate::isLoadedObject($delivery_address)) {
            $delivery_address = $invoice_address;
        }

        // Get company data with fallback chain
        $companyData = $this->getCompanyDataWithFallbacks($invoice_address);
        $shippingData = $companyData;
        try {
            $shippingData = $this->getCompanyDataWithFallbacks($delivery_address);
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'TwoPayment: Order intent shipping company fallback used due to address resolution error - ' . $e->getMessage(),
                2
            );
            $delivery_address = $invoice_address;
        }
        $shippingOrgName = !empty($shippingData['company_name']) ? $shippingData['company_name'] : $companyData['company_name'];

        $request_data = [
            'gross_amount' => (string)($this->getTwoRoundAmount($final_gross)),
            'net_amount' => (string)($this->getTwoRoundAmount($final_net)),
            'tax_amount' => (string)($this->getTwoRoundAmount($final_tax)),
            'discount_amount' => (string)($this->getTwoRoundAmount($final_discount)),
            'buyer' => [
                'company' => [
                    'company_name' => $companyData['company_name'],
                    'country_prefix' => $companyData['country_iso'],
                    'organization_number' => $companyData['organization_number'],
                    'website' => '',
                ],
                'representative' => [
                    'email' => $customer->email,
                    'first_name' => $customer->firstname,
                    'last_name' => $customer->lastname,
                    'phone_number' => $this->getPhoneWithFallback($invoice_address),
                ],
            ],
            'currency' => $currency->iso_code,
            'merchant_short_name' => $this->merchant_short_name,
            'invoice_type' => 'FUNDED_INVOICE', // Default product type
            'billing_address' => $this->buildTwoAddress($invoice_address, $companyData['company_name'], $companyData['country_iso']),
            'shipping_address' => $this->buildTwoAddress($delivery_address, $shippingOrgName, $shippingData['country_iso']),
            'line_items' => $line_items,
        ];

        if ($this->shouldIncludeTaxSubtotals()) {
            $request_data['tax_subtotals'] = $tax_subtotals;
        }

        return $request_data;
    }

    /**
     * Server-authoritative order intent check used by payment submission.
     * Frontend intent remains UX-only; this method decides whether checkout can proceed.
     *
     * @param Cart $cart
     * @param Customer $customer
     * @param Currency $currency
     * @param Address $address
     * @return array{
     *   approved:bool,
     *   status:string,
     *   message:string,
     *   timestamp:int,
     *   http_status:int
     * }
     */
    public function checkTwoOrderIntentApprovalAtPayment($cart, $customer, $currency, $address)
    {
        $result = array(
            'approved' => false,
            'status' => 'provider_error',
            'message' => $this->l('Unable to process your order with Two payment.'),
            'timestamp' => time(),
            'http_status' => 0,
        );

        try {
            if ($this->shouldRunStrictOrderIntentParityAtPayment()) {
                // Authoritative payment-submit intent check must fail-closed on reconciliation drift.
                $payload = $this->buildTwoIntentOrderData($cart, $customer, $currency, $address, true);
            } else {
                $payload = $this->getTwoIntentOrderData($cart, $customer, $currency, $address);
            }
        } catch (Exception $e) {
            $exceptionMessage = (string)$e->getMessage();
            if (stripos($exceptionMessage, 'reconcile') !== false) {
                $result['status'] = 'reconciliation_mismatch';
                $result['message'] = $this->l('Unable to process your order with Two payment. Please review your cart and try again.');
            } else {
                $result['status'] = 'payload_error';
            }
            PrestaShopLogger::addLog(
                'TwoPayment: Backend order intent payload build failed at payment submit - ' . $e->getMessage(),
                3
            );
            return $result;
        }

        $response = $this->setTwoPaymentRequest('/v1/order_intent', $payload, 'POST');
        $http_status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
        $result['http_status'] = $http_status;

        $response_summary = $this->buildTwoApiResponseLogSummary($response);
        PrestaShopLogger::addLog(
            'TwoPayment: Backend order intent response summary at payment submit - ' . json_encode($response_summary),
            ($http_status >= self::HTTP_STATUS_BAD_REQUEST || $http_status === 0) ? 2 : 1
        );

        if ($http_status >= self::HTTP_STATUS_OK && $http_status < self::HTTP_STATUS_BAD_REQUEST) {
            if (array_key_exists('approved', $response)) {
                $approved = (bool)$response['approved'];
                $result['approved'] = $approved;
                $result['status'] = $approved ? 'approved' : 'declined';
                $result['message'] = $approved
                    ? ''
                    : $this->l('Your order could not be approved by Two payment. Please choose another payment method or contact support.');

                if (!$approved) {
                    $provider_message = '';
                    if (isset($response['message']) && is_string($response['message'])) {
                        $provider_message = trim($response['message']);
                    } elseif (isset($response['data']) && is_array($response['data']) && isset($response['data']['message']) && is_string($response['data']['message'])) {
                        $provider_message = trim($response['data']['message']);
                    }

                    if (!Tools::isEmpty($provider_message)) {
                        $result['message'] = $provider_message;
                    }
                }

                return $result;
            }

            $result['status'] = 'invalid_response';
            $result['message'] = $this->l('Unable to process your order with Two payment.');
            return $result;
        }

        if ($http_status === 0) {
            $result['status'] = 'provider_unavailable';
            $result['message'] = $this->l('Connection error with payment provider. Please try again.');
            return $result;
        }

        if ($http_status >= self::HTTP_STATUS_SERVER_ERROR) {
            $result['status'] = 'provider_unavailable';
            $result['message'] = $this->l('Payment provider temporarily unavailable. Please try again later.');
            return $result;
        }

        $two_error_message = $this->getTwoErrorMessage($response);
        $result['status'] = 'provider_error';
        if (!Tools::isEmpty($two_error_message)) {
            $result['message'] = $two_error_message;
        }

        return $result;
    }

    /**
     * Extension hook for tests: keep strict parity enabled in production.
     *
     * @return bool
     */
    protected function shouldRunStrictOrderIntentParityAtPayment()
    {
        return true;
    }

    /**
     * Create a local PrestaShop order after provider verification with race-safe recovery.
     *
     * @param Cart $cart
     * @param Customer $customer
     * @param int $initial_status
     * @param float $provider_gross_amount
     * @return array{success:bool,id_order:int,recovered_existing:bool,error:string}
     */
    public function createTwoLocalOrderAfterProviderVerification($cart, $customer, $initial_status, $provider_gross_amount)
    {
        $result = array(
            'success' => false,
            'id_order' => 0,
            'recovered_existing' => false,
            'error' => '',
        );

        if (!Validate::isLoadedObject($cart)) {
            $result['error'] = 'cart_invalid';
            return $result;
        }

        $currency = new Currency((int)$cart->id_currency);
        if (!Validate::isLoadedObject($currency)) {
            $result['error'] = 'currency_invalid';
            return $result;
        }

        try {
            $this->validateOrder(
                (int)$cart->id,
                (int)$initial_status,
                (float)$provider_gross_amount,
                $this->displayName,
                null,
                array(),
                (int)$currency->id,
                false,
                $customer->secure_key
            );
            $createdOrderId = (int)$this->currentOrder;
            if ($createdOrderId > 0) {
                $result['success'] = true;
                $result['id_order'] = $createdOrderId;
                return $result;
            }
        } catch (Exception $e) {
            $result['error'] = (string)$e->getMessage();
            PrestaShopLogger::addLog(
                'TwoPayment: validateOrder exception after provider verification for cart ' . (int)$cart->id .
                ' - ' . $result['error'],
                3
            );
        }

        // Recovery path for idempotent callback retries/races where order was already created.
        $existingOrderId = (int)$this->getTwoOrderIdByCart((int)$cart->id);
        if ($existingOrderId > 0) {
            $result['success'] = true;
            $result['id_order'] = $existingOrderId;
            $result['recovered_existing'] = true;
            return $result;
        }

        return $result;
    }

    /**
     * Best-effort provider order cancellation helper.
     *
     * @param string $two_order_id
     * @param string $context_label
     * @return bool
     */
    public function cancelTwoOrderBestEffort($two_order_id, $context_label = '')
    {
        $two_order_id = trim((string)$two_order_id);
        if (Tools::isEmpty($two_order_id)) {
            return false;
        }

        $response = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id . '/cancel', [], 'POST');
        $http_status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
        $success = ($http_status > 0 && $http_status < self::HTTP_STATUS_BAD_REQUEST);

        PrestaShopLogger::addLog(
            'TwoPayment: Provider order cancel ' . ($success ? 'succeeded' : 'failed') .
            ' for Two order ' . $two_order_id .
            (!Tools::isEmpty($context_label) ? ' (' . $context_label . ')' : '') .
            ', HTTP ' . $http_status,
            $success ? 1 : 2
        );

        return $success;
    }

    /**
     * Extract provider gross amount for callback-time validateOrder amount.
     * Accepts root or nested response payloads and returns null when unavailable/invalid.
     *
     * @param mixed $order_response
     * @return float|null
     */
    public function extractTwoProviderGrossAmountForValidation($order_response)
    {
        if (!is_array($order_response)) {
            return null;
        }

        $payload = $order_response;
        if (
            (!isset($payload['gross_amount']) || Tools::isEmpty($payload['gross_amount'])) &&
            isset($order_response['data']) &&
            is_array($order_response['data'])
        ) {
            $payload = $order_response['data'];
        }

        if (!isset($payload['gross_amount']) || Tools::isEmpty($payload['gross_amount'])) {
            return null;
        }

        if (!is_scalar($payload['gross_amount'])) {
            return null;
        }

        $gross_amount = (float)$payload['gross_amount'];
        if (!is_finite($gross_amount) || $gross_amount < 0) {
            return null;
        }

        return round($gross_amount, 2);
    }

    public function getTwoNewOrderData($merchant_order_id, $cart, $merchant_urls = null)
    {
        // Validate cart has products before building order data
        if (!Validate::isLoadedObject($cart) || $cart->nbProducts() <= 0) {
            PrestaShopLogger::addLog('TwoPayment: Cannot build order data - cart is empty or invalid (Merchant order ID: ' . $merchant_order_id . ')', 3);
            throw new Exception('Cart is empty or invalid');
        }
        
        $order_reference = round(microtime(1) * 1000);
        $customer = new Customer($cart->id_customer);
        $currency = new Currency($cart->id_currency);
        $invoice_address = new Address($cart->id_address_invoice);
        $delivery_address = new Address($cart->id_address_delivery);
        $carrier_name = '';
        $tracking_number = '';
        $expected_delivery_days = self::DEFAULT_DELIVERY_DAYS_OFFSET; // Default fallback
        $carrier = new Carrier($cart->id_carrier, $cart->id_lang);
        if (Validate::isLoadedObject($carrier)) {
            $carrier_name = $carrier->name;
            // Use carrier's max_delivery_days if available, otherwise use default
            if (isset($carrier->max_delivery_days) && $carrier->max_delivery_days > 0) {
                $expected_delivery_days = (int)$carrier->max_delivery_days;
            } elseif (isset($carrier->min_delivery_days) && $carrier->min_delivery_days > 0) {
                // Fallback to min_delivery_days if max not available
                $expected_delivery_days = (int)$carrier->min_delivery_days;
            }
        }

        $pricingData = $this->buildTwoOrderPricingData($cart, 'order data (merchant_order_id=' . $merchant_order_id . ')');
        $line_items = $pricingData['line_items'];
        $tax_subtotals = $pricingData['tax_subtotals'];
        $final_net = $pricingData['net_amount'];
        $final_tax = $pricingData['tax_amount'];
        $final_gross = $pricingData['gross_amount'];
        $final_discount = $pricingData['discount_amount'];

        // Get company data with fallback chain (reused helper method)
        $buyerData = $this->getCompanyDataWithFallbacks($invoice_address);
        $shippingData = $this->getCompanyDataWithFallbacks($delivery_address);
        $buyerCompanyName = $buyerData['company_name'];
        $shippingOrgName = !empty($shippingData['company_name']) ? $shippingData['company_name'] : $buyerCompanyName;

        if (!is_array($merchant_urls)) {
            // Backward compatibility: legacy flow where merchant order id was the local PrestaShop id_order
            $merchant_urls = [
                'merchant_confirmation_url' => $this->context->link->getModuleLink($this->name, 'confirmation', ['id_order' => $merchant_order_id], true),
                'merchant_cancel_order_url' => $this->context->link->getModuleLink($this->name, 'cancel', ['id_order' => $merchant_order_id], true),
                'merchant_edit_order_url' => '',
                'merchant_order_verification_failed_url' => '',
                'merchant_invoice_url' => '',
                'merchant_shipping_document_url' => ''
            ];
        }

        $request_data = [
            'gross_amount' => (string)($this->getTwoRoundAmount($final_gross)),
            'net_amount' => (string)($this->getTwoRoundAmount($final_net)),
            'currency' => $currency->iso_code,
            'discount_amount' => (string)($this->getTwoRoundAmount($final_discount)), // Two API expects positive discount amount at order level (already abs() at line 1532)
            'discount_rate' => '0',
            'invoice_type' => 'FUNDED_INVOICE', // Default product type
            'tax_amount' => (string)($this->getTwoRoundAmount($final_tax)),
            'buyer' => [
                'company' => [
                    'company_name' => $buyerCompanyName,
                    'country_prefix' => $buyerData['country_iso'],
                    'organization_number' => $buyerData['organization_number'],
                    'website' => '',
                ],
                'representative' => [
                    'email' => $customer->email,
                    'first_name' => $customer->firstname,
                    'last_name' => $customer->lastname,
                    'phone_number' => $this->getPhoneWithFallback($invoice_address),
                ],
            ],
            'buyer_department' => property_exists($invoice_address, 'department') ? (string)$invoice_address->department : '',
            'buyer_project' => property_exists($invoice_address, 'project') ? (string)$invoice_address->project : '',
            'merchant_additional_info' => '',
            'merchant_order_id' => (string)$merchant_order_id,
            'merchant_reference' => (string)($order_reference),
            'merchant_urls' => $merchant_urls,
            'billing_address' => $this->buildTwoAddress($invoice_address, $buyerCompanyName, $buyerData['country_iso']),
            'shipping_address' => $this->buildTwoAddress($delivery_address, $shippingOrgName, $shippingData['country_iso']),
            'shipping_details' => [
                'carrier_name' => $carrier_name,
                'tracking_number' => $tracking_number,
                'expected_delivery_date' => date('Y-m-d', strtotime('+ ' . $expected_delivery_days . ' days'))
            ],
            'recurring' => false,
            'order_note' => '',
            'line_items' => $line_items,
            'terms' => $this->buildTermsPayload(),
        ];

        if ($this->shouldIncludeTaxSubtotals()) {
            $request_data['tax_subtotals'] = $tax_subtotals;
        }

        PrestaShopLogger::addLog('TwoPayment: Order creation with terms - ' . json_encode($request_data['terms']), 1);
        
        return $request_data;
    }

    public function getTwoUpdateOrderData($order, $orderpaymentdata)
    {
        $cart = new Cart($order->id_cart);
        
        // Validate cart has products before building order data
        if (!Validate::isLoadedObject($cart) || $cart->nbProducts() <= 0) {
            PrestaShopLogger::addLog('TwoPayment: Cannot build update order data - cart is empty or invalid (Order ID: ' . $order->id . ')', 3);
            throw new Exception('Cart is empty or invalid');
        }
        
        $currency = new Currency($cart->id_currency);
        $customer = new Customer($cart->id_customer);
        $invoice_address = new Address($cart->id_address_invoice);
        $delivery_address = new Address($cart->id_address_delivery);
        $carrier_name = '';
        $expected_delivery_days = self::DEFAULT_DELIVERY_DAYS_OFFSET; // Default fallback
        // Carrier from the ORDER, not the cart: the admin shipping panel
        // updates the order's carrier alongside the tracking number, and
        // sending the stale cart carrier with a fresh tracking number would
        // mismatch. Cart carrier is the fallback for legacy orders.
        $id_carrier = (int)$order->id_carrier ? (int)$order->id_carrier : (int)$cart->id_carrier;
        $carrier = new Carrier($id_carrier, $cart->id_lang);
        if (Validate::isLoadedObject($carrier)) {
            $carrier_name = $carrier->name;
            // Use carrier's max_delivery_days if available, otherwise use default
            if (isset($carrier->max_delivery_days) && $carrier->max_delivery_days > 0) {
                $expected_delivery_days = (int)$carrier->max_delivery_days;
            } elseif (isset($carrier->min_delivery_days) && $carrier->min_delivery_days > 0) {
                // Fallback to min_delivery_days if max not available
                $expected_delivery_days = (int)$carrier->min_delivery_days;
            }
        }
        $tracking_number = $this->getTwoOrderTrackingNumber($order);

        // The update path runs in admin/webhook context with no buyer term
        // cookie, so pass the persisted order term to the surcharge builder;
        // otherwise the fee would be recomputed for the default term and the
        // update gross would diverge from the created-order gross. TWO-24752.
        $storedTerm = (isset($orderpaymentdata['two_day_on_invoice']) && $orderpaymentdata['two_day_on_invoice'] !== '')
            ? (int) $orderpaymentdata['two_day_on_invoice']
            : null;
        $pricingData = $this->buildTwoOrderPricingData($cart, 'update order data (order_id=' . $order->id . ')', false, $storedTerm);
        $line_items = $pricingData['line_items'];
        $tax_subtotals = $pricingData['tax_subtotals'];
        $final_net = $pricingData['net_amount'];
        $final_tax = $pricingData['tax_amount'];
        $final_gross = $pricingData['gross_amount'];
        $final_discount = $pricingData['discount_amount'];

        // Get company data with fallback chain (reused helper method)
        $buyerData = $this->getCompanyDataWithFallbacks($invoice_address);
        $shippingData = $this->getCompanyDataWithFallbacks($delivery_address);
        $buyerCompanyName = $buyerData['company_name'];
        $shippingOrgName = !empty($shippingData['company_name']) ? $shippingData['company_name'] : $buyerCompanyName;

        $request_data = [
            'gross_amount' => (string)($this->getTwoRoundAmount($final_gross)),
            'net_amount' => (string)($this->getTwoRoundAmount($final_net)),
            'currency' => $currency->iso_code,
            'discount_amount' => (string)($this->getTwoRoundAmount($final_discount)), // Two API expects positive discount amount at order level
            'discount_rate' => '0',
            'invoice_type' => 'FUNDED_INVOICE', // Default product type
            'tax_amount' => (string)($this->getTwoRoundAmount($final_tax)),
            'buyer' => [
                'company' => [
                    'company_name' => $buyerCompanyName,
                    'country_prefix' => $buyerData['country_iso'],
                    'organization_number' => $buyerData['organization_number'],
                    'website' => '',
                ],
                'representative' => [
                    'email' => $customer->email,
                    'first_name' => $customer->firstname,
                    'last_name' => $customer->lastname,
                    'phone_number' => $this->getPhoneWithFallback($invoice_address),
                ],
            ],
            'buyer_department' => property_exists($invoice_address, 'department') ? (string)$invoice_address->department : '',
            'buyer_project' => property_exists($invoice_address, 'project') ? (string)$invoice_address->project : '',
            'merchant_additional_info' => '',
            'merchant_order_id' => (string)$order->id,
            'merchant_reference' => (string)($orderpaymentdata['two_order_reference']),
            'billing_address' => $this->buildTwoAddress($invoice_address, $buyerCompanyName, $buyerData['country_iso']),
            'shipping_address' => $this->buildTwoAddress($delivery_address, $shippingOrgName, $shippingData['country_iso']),
            'shipping_details' => [
                'carrier_name' => $carrier_name,
                'tracking_number' => $tracking_number,
                'expected_delivery_date' => date('Y-m-d', strtotime('+ ' . $expected_delivery_days . ' days'))
            ],
            'recurring' => false,
            'order_note' => '',
            'line_items' => $line_items,
        ];

        if ($this->shouldIncludeTaxSubtotals()) {
            $request_data['tax_subtotals'] = $tax_subtotals;
        }

        return $request_data;
    }

    public function getTwoNewRefundData($order, $two_order_snapshot = null)
    {
        $cart = new Cart($order->id_cart);
        $currency = new Currency($cart->id_currency);

        // Determine full refund amount based on Two order snapshot or fallback to PrestaShop totals
        $amount = null;
        $currency_iso = $currency->iso_code;
        if (is_array($two_order_snapshot) && isset($two_order_snapshot['gross_amount'])) {
            $amount = (string)$two_order_snapshot['gross_amount'];
        } else {
            // Fallback: use cart/order gross total in current currency
            $amount = (string)$this->getTwoRoundAmount($cart->getOrderTotal(true, Cart::BOTH));
        }
        if (is_array($two_order_snapshot) && isset($two_order_snapshot['currency'])) {
            $currency_iso = $two_order_snapshot['currency'];
        }

        // For full refunds, explicitly set amount to total gross and flag as full refund via reason
        $request_data = [
            'reason' => $this->l('Full refund issued from PrestaShop'),
            'currency' => $currency_iso,
            'amount' => $amount,
        ];

        return $request_data;
    }

    /**
     * Generate a deterministic UUID v4 from a seed string
     * This ensures consistent UUIDs for the same input across multiple calls
     * 
     * @param string $seed Seed string to generate UUID from
     * @return string UUID v4 format
     */
    protected function generateUuidV4FromSeed($seed)
    {
        // Generate MD5 hash of seed (128 bits = 32 hex chars)
        $hash = md5($seed);
        
        // Format as UUID v4: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        // Where y is one of 8, 9, a, or b (to set variant bits)
        return sprintf(
            '%08s-%04s-4%03s-%04x-%012s',
            substr($hash, 0, 8),  // 8 chars
            substr($hash, 8, 4),  // 4 chars
            substr($hash, 13, 3), // 3 chars (version 4)
            hexdec(substr($hash, 16, 4)) & 0x3fff | 0x8000, // 4 chars (variant bits)
            substr($hash, 20, 12) // 12 chars
        );
    }

    public function getTwoProductItems($cart)
    {
        $items = [];
        $carrier = new Carrier($cart->id_carrier, $cart->id_lang);
        $line_items = $cart->getProducts(true);
        $use_spanish_rate_policy = $this->shouldApplyTwoSpanishTaxRatePolicy($cart);
        
        //  Validate cart has products
        if (empty($line_items)) {
            PrestaShopLogger::addLog('TwoPayment: Cart is empty, cannot build line items', 3);
            return $items; // Return empty array (caller should handle empty cart)
        }
        $known_product_rate_candidates = $this->collectTwoKnownTaxRatesFromConfiguredProductRates($line_items);
        
        foreach ($line_items as $line_item) {
            $categories = Product::getProductCategoriesFull($line_item['id_product'], $cart->id_lang);
            $image = Image::getCover($line_item['id_product']);
            $imagePath = $this->context->link->getImageLink($line_item['link_rewrite'], $image['id_image'], ImageType::getFormattedName('home'));
            
            // Use PrestaShop monetary amounts as canonical values for payload totals.
            $net_amount_prestashop = round((float)$line_item['total'], 2);
            $gross_amount_prestashop = round((float)$line_item['total_wt'], 2);
            $tax_amount_prestashop = round($gross_amount_prestashop - $net_amount_prestashop, 2);
            $quantity = (int)$line_item['cart_quantity'];

            // CRITICAL: Validate quantity to prevent division by zero
            if ($quantity <= 0) {
                PrestaShopLogger::addLog('TwoPayment: Invalid quantity (0 or negative) for product ' . $line_item['id_product'], 3);
                continue; // Skip invalid line items
            }

            $ecotax_service_line = null;
            $ecotax_breakdown = $this->extractTwoEcotaxLineBreakdown(
                $line_item,
                $quantity,
                $net_amount_prestashop,
                $gross_amount_prestashop
            );
            if (!empty($ecotax_breakdown['enabled'])) {
                $net_amount_prestashop = (float)$ecotax_breakdown['product_net'];
                $gross_amount_prestashop = (float)$ecotax_breakdown['product_gross'];
                $tax_amount_prestashop = round($gross_amount_prestashop - $net_amount_prestashop, 2);

                if (isset($line_item['price']) && is_numeric($line_item['price'])) {
                    $line_item['price'] = max(0, (float)$line_item['price'] - (float)$ecotax_breakdown['unit_net']);
                }
            }
            
            // DIAGNOSTIC LOGGING: Log tax data for debugging store-specific issues
            // Only log when debug mode is enabled to avoid excessive log entries in production
            if (Configuration::get('PS_TWO_DEBUG_MODE')) {
                $calculated_rate_for_log = ($net_amount_prestashop > 0) 
                    ? round((($gross_amount_prestashop - $net_amount_prestashop) / $net_amount_prestashop) * 100, 2) 
                    : 0;
                PrestaShopLogger::addLog(
                    'TwoPayment: Product tax debug - ID: ' . $line_item['id_product'] . 
                    ' | rate field: ' . (isset($line_item['rate']) ? $line_item['rate'] : 'NULL') . 
                    ' | total (net): ' . $net_amount_prestashop . 
                    ' | total_wt (gross): ' . $gross_amount_prestashop .
                    ' | calculated rate: ' . $calculated_rate_for_log . '%',
                    1 // Info level
                );
            }
            
            // Derive the effective tax rate from applied PrestaShop amounts first.
            // Keep configured rate only when it is very close (normal variance).
            $rate_from_field_percent = isset($line_item['rate']) ? (float)$line_item['rate'] : 0;
            $rate_from_field_decimal = $rate_from_field_percent / 100;
            $rate_from_amounts_decimal = 0.0;

            if ($net_amount_prestashop > 0) {
                $rate_from_amounts_decimal = $tax_amount_prestashop / $net_amount_prestashop;
                if ($rate_from_amounts_decimal < 0) {
                    $rate_from_amounts_decimal = 0.0;
                }
            }

            $tax_rate = 0.0;
            if ($net_amount_prestashop > 0) {
                $rate_difference = abs($rate_from_field_decimal - $rate_from_amounts_decimal);
                if ($rate_from_field_percent > 0 && $rate_difference <= self::TAX_RATE_VARIANCE_TOLERANCE) {
                    $tax_rate = $rate_from_field_decimal;
                } else {
                    $tax_rate = $rate_from_amounts_decimal;
                    if ($rate_from_field_percent > 0 && Configuration::get('PS_TWO_DEBUG_MODE')) {
                        PrestaShopLogger::addLog(
                            'TwoPayment: Tax rate variance - field: ' . $rate_from_field_percent . '%, amounts: ' .
                            round($rate_from_amounts_decimal * 100, 2) . '% | Product: ' . $line_item['id_product'] .
                            ' | Using applied rate from amounts',
                            1
                        );
                    }
                }
            } elseif ($rate_from_field_percent > 0) {
                $tax_rate = $rate_from_field_decimal;
            }

            $tax_rate = $this->normalizeTwoTaxRateToPercentPrecision($tax_rate);
            $product_known_context_rates = $known_product_rate_candidates;
            if ($rate_from_field_percent > 0) {
                $product_known_context_rates[] = $this->normalizeTwoTaxRateToPercentPrecision($rate_from_field_decimal);
            }
            $snapped_product_rate = $this->normalizeTwoTaxRateToPercentPrecision(
                $this->snapTwoTaxRateToKnownContexts($tax_rate, $product_known_context_rates)
            );
            if (
                abs($tax_amount_prestashop - ($net_amount_prestashop * $snapped_product_rate)) <= self::TAX_FORMULA_TOLERANCE
            ) {
                $tax_rate = $snapped_product_rate;
            }
            
            // Use PrestaShop unit price when available; otherwise derive from net total.
            $unit_price_net_prestashop = isset($line_item['price']) ? (float)$line_item['price'] : null;
            
            if ($unit_price_net_prestashop !== null) {
                $unit_price_net = round($unit_price_net_prestashop, 2);
                
                // Calculate discount from PrestaShop's values
                $expected_total = $quantity * $unit_price_net;
                $discount_amount = round($expected_total - $net_amount_prestashop, 2);
                
                // Ensure discount is not negative (protect against edge cases)
                if ($discount_amount < 0) {
                    PrestaShopLogger::addLog('TwoPayment: Negative discount calculated for product ' . $line_item['id_product'] . ', clamping to 0', 2);
                    $discount_amount = 0;
                }
                
                $net_amount = $net_amount_prestashop;
            } else {
                $discount_amount = isset($line_item['reduction']) ? (float)$line_item['reduction'] : 0;
                
                // Ensure discount is not negative
                if ($discount_amount < 0) {
                    $discount_amount = 0;
                }
                
                $unit_price_net = ($net_amount_prestashop + $discount_amount) / $quantity;
                $unit_price_net = round($unit_price_net, 2);
                
                $net_amount = ($quantity * $unit_price_net) - $discount_amount;
                $net_amount = round($net_amount, 2);
            }

            $tax_amount = $tax_amount_prestashop;
            $gross_amount = $gross_amount_prestashop;
            
            // Calculate actual tax rate percentage for display (tax_class_name)
            $tax_rate_percent_display = round($tax_rate * 100, 2);
            $barcodes = array();
            if (!empty($line_item['ean13'])) {
                $barcodes[] = array(
                    'type' => 'SKU',
                    'value' => $line_item['ean13'],
                );
            }
            if (!empty($line_item['upc'])) {
                $barcodes[] = array(
                    'type' => 'UPC',
                    'value' => $line_item['upc'],
                );
            }
            
            $product = [
                'name' => $line_item['name'],
                'description' => Tools::substr(strip_tags($line_item['description_short']), 0, 255),
                'gross_amount' => (string)$this->getTwoRoundAmount($gross_amount),
                'net_amount' => (string)$this->getTwoRoundAmount($net_amount),
                'discount_amount' => (string)$this->getTwoRoundAmount($discount_amount),
                'tax_amount' => (string)$this->getTwoRoundAmount($tax_amount),
                'tax_class_name' => 'VAT ' . $this->getTwoRoundAmount($tax_rate_percent_display) . '%',
                'tax_rate' => $this->formatTwoTaxRate($tax_rate),
                'unit_price' => (string)$this->getTwoRoundAmount($unit_price_net),
                'quantity' => $quantity,
                'quantity_unit' => 'pcs',
                'image_url' => $imagePath,
                'product_page_url' => $this->context->link->getProductLink($line_item['id_product']),
                'type' => 'PHYSICAL',
                'details' => [
                    'brand' => $line_item['manufacturer_name'],
                    'barcodes' => $barcodes,
                ],
            ];
            $product['details']['categories'] = [];
            if ($categories) {
                foreach ($categories as $category) {
                    $product['details']['categories'][] = $category['name'];
                }
            }

            $items[] = $product;

            if (!empty($ecotax_breakdown['enabled'])) {
                $ecotax_rate = (float)$ecotax_breakdown['rate'];
                $ecotax_rate_percent = round($ecotax_rate * 100, 2);
                $ecotax_service_line = [
                    'name' => $line_item['name'] . ' - ' . $this->l('Ecotax'),
                    'description' => Tools::substr(strip_tags($this->l('Environmental tax (ecotax)')), 0, 255),
                    'gross_amount' => (string)$this->getTwoRoundAmount($ecotax_breakdown['gross']),
                    'net_amount' => (string)$this->getTwoRoundAmount($ecotax_breakdown['net']),
                    'discount_amount' => '0.00',
                    'tax_amount' => (string)$this->getTwoRoundAmount($ecotax_breakdown['tax']),
                    'tax_class_name' => 'VAT ' . $this->getTwoRoundAmount($ecotax_rate_percent) . '%',
                    'tax_rate' => $this->formatTwoTaxRate($ecotax_rate),
                    'unit_price' => (string)$this->getTwoRoundAmount($ecotax_breakdown['net']),
                    'quantity' => 1,
                    'quantity_unit' => 'item',
                    'image_url' => $imagePath,
                    'product_page_url' => $this->context->link->getProductLink($line_item['id_product']),
                    'type' => 'SERVICE',
                ];
                $items[] = $ecotax_service_line;
            }
        }

        // Add shipping as a line item if applicable
        // BEST PRACTICE: Use getPackageShippingCost() to get actual carrier cost BEFORE free shipping rules
        // This fixes the issue where getOrderTotal(ONLY_SHIPPING) returns 0 when free shipping cart rules are active
        $shipping_cost_with_tax = 0;
        $shipping_cost_without_tax = 0;
        
        if (Validate::isLoadedObject($carrier)) {
            // Method 1: Get package shipping cost directly from carrier (ignores free shipping rules)
            // Parameters: id_carrier, use_tax, country, product_list, id_zone
            $shipping_cost_with_tax = $cart->getPackageShippingCost((int)$cart->id_carrier, true, null, null, null);
            $shipping_cost_without_tax = $cart->getPackageShippingCost((int)$cart->id_carrier, false, null, null, null);
            
            // Fallback: If getPackageShippingCost returns 0 or false, try getOrderTotal
            // This handles edge cases where carrier pricing might be complex
            if ($shipping_cost_with_tax <= 0) {
                $shipping_cost_with_tax = (float)$cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
                $shipping_cost_without_tax = (float)$cart->getOrderTotal(false, Cart::ONLY_SHIPPING);
            }
        }
        
        if (Validate::isLoadedObject($carrier) && $shipping_cost_with_tax > 0) {
            // Keep shipping monetary values canonical to PrestaShop totals.
            $shipping_net = round((float)$shipping_cost_without_tax, 2);
            $shipping_gross = round((float)$shipping_cost_with_tax, 2);
            $shipping_tax_amount = round($shipping_gross - $shipping_net, 2);
            $shipping_tax_rate_decimal = $shipping_net > 0
                ? max(0, $shipping_tax_amount / $shipping_net)
                : 0.0;
            $shipping_tax_rate_decimal = $this->normalizeTwoTaxRateToPercentPrecision($shipping_tax_rate_decimal);
            $shipping_known_context_rates = $this->collectTwoKnownTaxRatesFromPositiveItems($items);
            $carrier_configured_rate = $this->getTwoCarrierConfiguredTaxRateDecimal($carrier, $cart);
            if ($carrier_configured_rate > 0) {
                $shipping_known_context_rates[] = $carrier_configured_rate;
            }
            $snapped_shipping_tax_rate = $this->normalizeTwoTaxRateToPercentPrecision(
                $this->snapTwoTaxRateToKnownContexts($shipping_tax_rate_decimal, $shipping_known_context_rates)
            );
            if (
                abs($shipping_tax_amount - ($shipping_net * $snapped_shipping_tax_rate)) <= self::TAX_FORMULA_TOLERANCE
            ) {
                $shipping_tax_rate_decimal = $snapped_shipping_tax_rate;
            }
            $shipping_tax_rate_percent = round($shipping_tax_rate_decimal * 100, 2);
            $shipping_unit_price = $shipping_net;
            
            $shipping_name = $carrier->name ? $carrier->name : $this->l('Shipping');
            $shipping_delay = '';
            if ($carrier->delay && is_array($carrier->delay)) {
                $shipping_delay = isset($carrier->delay[$cart->id_lang]) ? 
                    $carrier->delay[$cart->id_lang] : 
                    reset($carrier->delay);
            } elseif ($carrier->delay) {
                $shipping_delay = $carrier->delay;
            }
            
            $shipping_description = $shipping_delay ? $shipping_delay : $this->l('Shipping cost for order');
            if ($carrier->shipping_method == Carrier::SHIPPING_METHOD_WEIGHT) {
                $shipping_description .= ' ' . sprintf($this->l('(by weight)'));
            } elseif ($carrier->shipping_method == Carrier::SHIPPING_METHOD_PRICE) {
                $shipping_description .= ' ' . sprintf($this->l('(by price)'));
            }
            
            $shipping_line = [
                'name' => $shipping_name,
                'description' => Tools::substr(strip_tags($shipping_description), 0, 255),
                'gross_amount' => (string)$this->getTwoRoundAmount($shipping_gross),
                'net_amount' => (string)$this->getTwoRoundAmount($shipping_net),
                'discount_amount' => '0.00',
                'tax_amount' => (string)$this->getTwoRoundAmount($shipping_tax_amount),
                'tax_class_name' => 'VAT ' . $this->getTwoRoundAmount($shipping_tax_rate_percent) . '%',
                'tax_rate' => $this->formatTwoTaxRate($shipping_tax_rate_decimal),
                'unit_price' => (string)$this->getTwoRoundAmount($shipping_unit_price),
                'quantity' => 1,
                'quantity_unit' => 'pcs',
                'image_url' => '',
                'product_page_url' => '',
                'type' => 'SHIPPING_FEE'
            ];

            $items[] = $shipping_line;
        }

        $wrapping_totals = $this->getTwoGiftWrappingTotals($cart);
        if ($wrapping_totals['gross'] > 0) {
            $wrapping_rate_decimal = $wrapping_totals['net'] > 0
                ? max(0, $wrapping_totals['tax'] / $wrapping_totals['net'])
                : 0.0;
            $wrapping_rate_decimal = $this->normalizeTwoTaxRateToPercentPrecision($wrapping_rate_decimal);
            $wrapping_known_context_rates = $this->collectTwoKnownTaxRatesFromPositiveItems($items);
            $snapped_wrapping_rate = $this->normalizeTwoTaxRateToPercentPrecision(
                $this->snapTwoTaxRateToKnownContexts($wrapping_rate_decimal, $wrapping_known_context_rates)
            );
            if (
                abs($wrapping_totals['tax'] - ($wrapping_totals['net'] * $snapped_wrapping_rate)) <= self::TAX_FORMULA_TOLERANCE
            ) {
                $wrapping_rate_decimal = $snapped_wrapping_rate;
            }
            $wrapping_rate_percent = round($wrapping_rate_decimal * 100, 2);
            $items[] = [
                'name' => $this->l('Gift wrapping'),
                'description' => Tools::substr(strip_tags($this->l('Gift wrapping for this order')), 0, 255),
                'gross_amount' => (string)$this->getTwoRoundAmount($wrapping_totals['gross']),
                'net_amount' => (string)$this->getTwoRoundAmount($wrapping_totals['net']),
                'discount_amount' => '0.00',
                'tax_amount' => (string)$this->getTwoRoundAmount($wrapping_totals['tax']),
                'tax_class_name' => 'VAT ' . $this->getTwoRoundAmount($wrapping_rate_percent) . '%',
                'tax_rate' => $this->formatTwoTaxRate($wrapping_rate_decimal),
                'unit_price' => (string)$this->getTwoRoundAmount($wrapping_totals['net']),
                'quantity' => 1,
                'quantity_unit' => 'item',
                'image_url' => '',
                'product_page_url' => '',
                'type' => 'DIGITAL',
            ];
        }

        // Add cart-level discounts as one or more lines split by tax context when applicable.
        $discount_lines = $this->buildTwoDiscountLinesFromCartTotals($cart, $items);
        if (!empty($discount_lines)) {
            foreach ($discount_lines as $discount_line) {
                $items[] = $discount_line;
            }
        }

        if ($use_spanish_rate_policy) {
            $items = $this->applyTwoSpanishCanonicalTaxRateFallbackToItems($items);
        }

        return $items;
    }

    /**
     * Resolve gift wrapping totals from cart, if wrapping is enabled.
     *
     * @param Cart $cart
     * @return array{net:float,tax:float,gross:float}
     */
    private function getTwoGiftWrappingTotals($cart)
    {
        if (!defined('Cart::ONLY_WRAPPING')) {
            return ['net' => 0.0, 'tax' => 0.0, 'gross' => 0.0];
        }

        $wrapping_gross = round((float)$cart->getOrderTotal(true, Cart::ONLY_WRAPPING), 2);
        $wrapping_net = round((float)$cart->getOrderTotal(false, Cart::ONLY_WRAPPING), 2);
        if ($wrapping_gross <= 0 && $wrapping_net <= 0) {
            return ['net' => 0.0, 'tax' => 0.0, 'gross' => 0.0];
        }

        if ($wrapping_gross < $wrapping_net) {
            $wrapping_gross = $wrapping_net;
        }

        return [
            'net' => $wrapping_net,
            'tax' => round($wrapping_gross - $wrapping_net, 2),
            'gross' => $wrapping_gross,
        ];
    }

    /**
     * Build ecotax split data for a product line when ecotax is available.
     * PrestaShop can include ecotax in product totals, which can distort product VAT context.
     * We model ecotax as a dedicated service line when a safe split is possible.
     *
     * @param array $line_item
     * @param int $quantity
     * @param float $line_net
     * @param float $line_gross
     * @return array{enabled:bool,net:float,tax:float,gross:float,rate:float,product_net:float,product_gross:float,unit_net:float}
     */
    private function extractTwoEcotaxLineBreakdown($line_item, $quantity, $line_net, $line_gross)
    {
        $quantity = (int)$quantity;
        if ($quantity <= 0) {
            return ['enabled' => false];
        }

        $ecotax_unit_net = isset($line_item['ecotax']) && is_numeric($line_item['ecotax'])
            ? abs((float)$line_item['ecotax'])
            : 0.0;
        $ecotax_total_net = round($ecotax_unit_net * $quantity, 2);
        if (isset($line_item['total_ecotax']) && is_numeric($line_item['total_ecotax'])) {
            $ecotax_total_net = round(abs((float)$line_item['total_ecotax']), 2);
        }
        if ($ecotax_total_net <= 0) {
            return ['enabled' => false];
        }

        $ecotax_rate_percent = isset($line_item['ecotax_tax_rate']) && is_numeric($line_item['ecotax_tax_rate'])
            ? max(0, (float)$line_item['ecotax_tax_rate'])
            : (isset($line_item['rate']) ? max(0, (float)$line_item['rate']) : 0.0);
        $ecotax_rate_decimal = round($ecotax_rate_percent / 100, self::TAX_RATE_PRECISION);
        $ecotax_total_tax = round($ecotax_total_net * $ecotax_rate_decimal, 2);
        $ecotax_total_gross = round($ecotax_total_net + $ecotax_total_tax, 2);

        $product_net = round((float)$line_net - $ecotax_total_net, 2);
        $product_gross = round((float)$line_gross - $ecotax_total_gross, 2);
        if ($product_net <= 0 || $product_gross < 0 || $product_gross < $product_net) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'net' => $ecotax_total_net,
            'tax' => $ecotax_total_tax,
            'gross' => $ecotax_total_gross,
            'rate' => $ecotax_rate_decimal,
            'product_net' => $product_net,
            'product_gross' => $product_gross,
            'unit_net' => round($ecotax_total_net / $quantity, 6),
        ];
    }

    /**
     * Build discount lines from PrestaShop cart totals.
     * Splits discount across detected tax contexts to avoid blended synthetic rates.
     *
     * @param Cart $cart
     * @param array $existingItems Positive payload lines already built (products/shipping)
     * @return array
     */
    private function buildTwoDiscountLinesFromCartTotals($cart, $existingItems)
    {
        $discount_gross_total = round((float)$cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS), 2);
        if ($discount_gross_total <= 0) {
            return [];
        }

        $discount_net_total = round((float)$cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS), 2);
        $discount_tax_total = round($discount_gross_total - $discount_net_total, 2);
        $lines = [];
        $remaining_discount_gross = $discount_gross_total;
        $remaining_discount_net = $discount_net_total;

        // Prefer cart-rule monetary values when available to keep discount attribution
        // aligned with PrestaShop invoice semantics. Fallback to context-based split.
        $rule_scope_meta = [];
        $rule_scoped_lines = $this->buildTwoDiscountLinesFromCartRules(
            $cart,
            $discount_net_total,
            $discount_gross_total,
            $existingItems,
            $remaining_discount_net,
            $remaining_discount_gross,
            $rule_scope_meta
        );
        if (!empty($rule_scoped_lines)) {
            foreach ($rule_scoped_lines as $rule_scoped_line) {
                $lines[] = $rule_scoped_line;
            }
            if ($remaining_discount_gross < 0) {
                $remaining_discount_gross = 0.0;
            }
            if ($remaining_discount_net < 0) {
                $remaining_discount_net = 0.0;
            }
            if ($remaining_discount_net > $remaining_discount_gross) {
                $remaining_discount_net = $remaining_discount_gross;
            }
        }

        $fallback_items = $existingItems;

        // Edge-path hardening: if rule-level monetary metadata is incomplete, carve out unresolved
        // free-shipping discount against the shipping context first to avoid blended attribution.
        $should_attempt_free_shipping_fallback = empty($rule_scoped_lines);
        $free_shipping_fallback_cap = null;
        if (
            !$should_attempt_free_shipping_fallback &&
            $remaining_discount_gross > 0 &&
            isset($rule_scope_meta['incomplete_free_shipping_gross']) &&
            (float)$rule_scope_meta['incomplete_free_shipping_gross'] > 0
        ) {
            $should_attempt_free_shipping_fallback = true;
            $free_shipping_fallback_cap = (float)$rule_scope_meta['incomplete_free_shipping_gross'];
        }

        if ($should_attempt_free_shipping_fallback) {
            $fallback_free_shipping = $this->buildTwoFallbackFreeShippingDiscountLine(
                $cart,
                $fallback_items,
                $remaining_discount_gross,
                $remaining_discount_net,
                $free_shipping_fallback_cap
            );
            if ($fallback_free_shipping !== null) {
                $lines[] = $fallback_free_shipping['line'];
                $remaining_discount_gross = round($remaining_discount_gross - $fallback_free_shipping['gross'], 2);
                $remaining_discount_net = round($remaining_discount_net - $fallback_free_shipping['net'], 2);
                if ($remaining_discount_gross < 0) {
                    $remaining_discount_gross = 0.0;
                }
                if ($remaining_discount_net < 0) {
                    $remaining_discount_net = 0.0;
                }
                $fallback_items = $this->filterTwoShippingFeeItems($fallback_items);
            }
        }

        if ($remaining_discount_gross <= 0) {
            return $lines;
        }

        if ($remaining_discount_net > $remaining_discount_gross) {
            $remaining_discount_net = $remaining_discount_gross;
        }
        $remaining_discount_tax = round($remaining_discount_gross - $remaining_discount_net, 2);

        $descriptor = $this->buildTwoDiscountDescriptor($cart);
        $contexts = $this->collectDiscountTaxContextsFromItems($fallback_items);

        if (empty($contexts)) {
            $contexts = [
                '0' => [
                    'tax_rate' => 0.0,
                    'net_weight' => 1.0,
                    'tax_weight' => 1.0,
                ],
            ];
        }

        $net_weights = [];
        $tax_weights = [];
        $known_context_rates = [];
        foreach ($contexts as $context_key => $context_data) {
            $net_weights[$context_key] = (float)$context_data['net_weight'];
            $tax_weights[$context_key] = (float)$context_data['tax_weight'];
            $known_context_rates[] = (float)$context_data['tax_rate'];
        }

        $allocated_nets = $this->allocateTwoAmountByWeights($remaining_discount_net, $net_weights);
        $tax_weight_source = array_sum($tax_weights) > 0 ? $tax_weights : $net_weights;
        $allocated_taxes = $this->allocateTwoAmountByWeights(max(0, $remaining_discount_tax), $tax_weight_source);

        $is_context_split = count($contexts) > 1;
        foreach ($contexts as $context_key => $context_data) {
            $line_net = isset($allocated_nets[$context_key]) ? (float)$allocated_nets[$context_key] : 0.0;
            $line_tax = isset($allocated_taxes[$context_key]) ? (float)$allocated_taxes[$context_key] : 0.0;
            $line_gross = round($line_net + $line_tax, 2);

            if ($line_gross <= 0) {
                continue;
            }

            $segments = $this->buildTwoCanonicalDiscountRateSegments($line_net, $line_tax, $known_context_rates);
            if (empty($segments)) {
                $line_rate_raw = $line_net > 0
                    ? max(0, $line_tax / $line_net)
                    : max(0, (float)$context_data['tax_rate']);
                $line_rate = $this->normalizeTwoTaxRateToPercentPrecision($line_rate_raw);
                $snapped_line_rate = $this->normalizeTwoTaxRateToPercentPrecision(
                    $this->snapTwoTaxRateToKnownContexts($line_rate_raw, $known_context_rates)
                );
                if (abs($line_tax - ($line_net * $snapped_line_rate)) <= self::TAX_FORMULA_TOLERANCE) {
                    $line_rate = $snapped_line_rate;
                }
                $segments = [[
                    'net' => $line_net,
                    'tax' => $line_tax,
                    'gross' => $line_gross,
                    'rate' => $line_rate,
                    'precision' => 4,
                ]];
            }

            $is_segment_split = count($segments) > 1;
            foreach ($segments as $segment) {
                $segment_net = round((float)$segment['net'], 2);
                $segment_tax = round((float)$segment['tax'], 2);
                $segment_gross = round((float)$segment['gross'], 2);
                if ($segment_gross <= 0) {
                    continue;
                }

                $segment_rate = max(0, (float)$segment['rate']);
                $segment_rate_percent = round($segment_rate * 100, 2);
                $line_name = $descriptor['name'];
                if ($is_context_split || $is_segment_split) {
                    $line_name .= ' (' . $this->l('VAT') . ' ' . $this->getTwoRoundAmount($segment_rate_percent) . '%)';
                }

                $line_rate_precision = isset($segment['precision']) ? (int)$segment['precision'] : null;
                $line_rate_formatted = $line_rate_precision === null
                    ? $this->formatTwoTaxRate($segment_rate)
                    : $this->formatTwoTaxRate($segment_rate, $line_rate_precision);

                $lines[] = [
                    'name' => $line_name,
                    'description' => $descriptor['description'],
                    'gross_amount' => (string)$this->getTwoRoundAmount(-$segment_gross),
                    'net_amount' => (string)$this->getTwoRoundAmount(-$segment_net),
                    'discount_amount' => '0.00',
                    'tax_amount' => (string)$this->getTwoRoundAmount(-$segment_tax),
                    'tax_class_name' => 'VAT ' . $this->getTwoRoundAmount($segment_rate_percent) . '%',
                    'tax_rate' => $line_rate_formatted,
                    'unit_price' => (string)$this->getTwoRoundAmount(-$segment_net),
                    'quantity' => 1,
                    'quantity_unit' => 'item',
                    'image_url' => '',
                    'product_page_url' => '',
                    'type' => 'DIGITAL',
                ];
            }
        }

        return $lines;
    }

    /**
     * Build a fallback free-shipping discount line when rule-level monetary metadata is incomplete.
     * This keeps shipping discount attribution on shipping VAT context in fallback mode.
     *
     * @param Cart $cart
     * @param array $existingItems
     * @param float $discountGrossTotal
     * @param float $discountNetTotal
     * @param float|null $freeShippingGrossOverride Positive unresolved free-shipping gross cap
     * @return array|null
     */
    private function buildTwoFallbackFreeShippingDiscountLine(
        $cart,
        $existingItems,
        $discountGrossTotal,
        $discountNetTotal,
        $freeShippingGrossOverride = null
    )
    {
        $shipping_line = null;
        foreach ($existingItems as $item) {
            $line_type = isset($item['type']) ? (string)$item['type'] : '';
            $line_gross = isset($item['gross_amount']) ? round((float)$item['gross_amount'], 2) : 0.0;
            if ($line_type === 'SHIPPING_FEE' && $line_gross > 0) {
                $shipping_line = $item;
                break;
            }
        }
        if ($shipping_line === null) {
            return null;
        }

        $free_shipping_rules = [];
        $free_shipping_gross = 0.0;
        $cart_rules = $cart->getCartRules();
        foreach ($cart_rules as $rule) {
            if (empty($rule['free_shipping'])) {
                continue;
            }

            $rule_gross = $this->extractTwoDiscountRuleGrossAmount($rule);
            if ($rule_gross <= 0 && isset($rule['reduction_amount']) && is_numeric($rule['reduction_amount'])) {
                $rule_gross = abs((float)$rule['reduction_amount']);
            }
            if ($rule_gross <= 0) {
                continue;
            }

            $free_shipping_rules[] = $rule;
            $free_shipping_gross = round($free_shipping_gross + $rule_gross, 2);
        }

        if ($freeShippingGrossOverride !== null) {
            $free_shipping_gross = round(max(0, (float)$freeShippingGrossOverride), 2);
        }

        if ($free_shipping_gross <= 0) {
            return null;
        }

        $shipping_gross = isset($shipping_line['gross_amount']) ? round((float)$shipping_line['gross_amount'], 2) : 0.0;
        $shipping_net = isset($shipping_line['net_amount']) ? round((float)$shipping_line['net_amount'], 2) : 0.0;
        $shipping_tax = round($shipping_gross - $shipping_net, 2);
        if ($shipping_gross <= 0) {
            return null;
        }

        $alloc_gross = min($free_shipping_gross, max(0, (float)$discountGrossTotal), $shipping_gross);
        if ($alloc_gross <= 0) {
            return null;
        }

        $shipping_ratio = $shipping_gross > 0 ? ($shipping_net / $shipping_gross) : 1.0;
        $alloc_net = round($alloc_gross * $shipping_ratio, 2);
        $alloc_net = min($alloc_net, round(max(0, (float)$discountNetTotal), 2), $alloc_gross);
        $alloc_tax = round($alloc_gross - $alloc_net, 2);

        $shipping_rate = $shipping_net > 0 ? ($shipping_tax / $shipping_net) : 0.0;
        if ($shipping_rate < 0) {
            $shipping_rate = 0.0;
        }
        $shipping_rate = $this->normalizeTwoTaxRateToPercentPrecision($shipping_rate);
        $shipping_known_context_rates = $this->collectTwoKnownTaxRatesFromPositiveItems($existingItems);
        $snapped_shipping_rate = $this->normalizeTwoTaxRateToPercentPrecision(
            $this->snapTwoTaxRateToKnownContexts($shipping_rate, $shipping_known_context_rates)
        );
        if (abs($shipping_tax - ($shipping_net * $snapped_shipping_rate)) <= self::TAX_FORMULA_TOLERANCE) {
            $shipping_rate = $snapped_shipping_rate;
        }
        $shipping_rate_percent = round($shipping_rate * 100, 2);
        $descriptor_rule = !empty($free_shipping_rules) ? reset($free_shipping_rules) : null;
        $descriptor = $descriptor_rule !== null
            ? $this->buildTwoSingleDiscountDescriptor($descriptor_rule)
            : $this->buildTwoDiscountDescriptor($cart);

        return [
            'gross' => $alloc_gross,
            'net' => $alloc_net,
            'line' => [
                'name' => $descriptor['name'],
                'description' => $descriptor['description'],
                'gross_amount' => (string)$this->getTwoRoundAmount(-$alloc_gross),
                'net_amount' => (string)$this->getTwoRoundAmount(-$alloc_net),
                'discount_amount' => '0.00',
                'tax_amount' => (string)$this->getTwoRoundAmount(-$alloc_tax),
                'tax_class_name' => 'VAT ' . $this->getTwoRoundAmount($shipping_rate_percent) . '%',
                'tax_rate' => $this->formatTwoTaxRate($shipping_rate),
                'unit_price' => (string)$this->getTwoRoundAmount(-$alloc_net),
                'quantity' => 1,
                'quantity_unit' => 'item',
                'image_url' => '',
                'product_page_url' => '',
                'type' => 'DIGITAL',
            ],
        ];
    }

    /**
     * Remove shipping fee line items from a line-item list.
     *
     * @param array $items
     * @return array
     */
    private function filterTwoShippingFeeItems($items)
    {
        $filtered = [];
        foreach ($items as $item) {
            $line_type = isset($item['type']) ? (string)$item['type'] : '';
            if ($line_type === 'SHIPPING_FEE') {
                continue;
            }
            $filtered[] = $item;
        }

        return $filtered;
    }

    /**
     * Build discount lines using cart-rule monetary values when available.
     * This preserves PrestaShop rule-level semantics better than context weighting.
     *
     * @param Cart $cart
     * @param float $discount_net_total
     * @param float $discount_gross_total
     * @return array
     */
    private function buildTwoDiscountLinesFromCartRules(
        $cart,
        $discount_net_total,
        $discount_gross_total,
        $existingItems,
        &$remaining_discount_net = null,
        &$remaining_discount_gross = null,
        &$rule_scope_meta = null
    )
    {
        $remaining_discount_net = round(max(0, (float)$discount_net_total), 2);
        $remaining_discount_gross = round(max(0, (float)$discount_gross_total), 2);
        $rule_scope_meta = [
            'has_incomplete_rows' => false,
            'has_incomplete_free_shipping' => false,
            'incomplete_free_shipping_gross' => 0.0,
        ];

        $cart_rules = $cart->getCartRules();
        if (empty($cart_rules)) {
            return [];
        }

        $known_context_rates = [];
        $contexts = $this->collectDiscountTaxContextsFromItems($existingItems);
        foreach ($contexts as $context) {
            if (isset($context['tax_rate'])) {
                $known_context_rates[] = (float)$context['tax_rate'];
            }
        }

        $rule_rows = [];
        $complete_rule_rows = [];
        foreach ($cart_rules as $idx => $rule) {
            $gross_raw = $this->extractTwoDiscountRuleGrossAmount($rule);
            if ($gross_raw <= 0) {
                continue;
            }

            $net_raw = $this->extractTwoDiscountRuleNetAmount($rule, $gross_raw);
            $gross_raw = max(0.0, (float)$gross_raw);
            if ($net_raw !== null) {
                $net_raw = max(0.0, (float)$net_raw);
                if ($net_raw > $gross_raw) {
                    $net_raw = $gross_raw;
                }
            } else {
                $rule_scope_meta['has_incomplete_rows'] = true;
                if (!empty($rule['free_shipping'])) {
                    $rule_scope_meta['has_incomplete_free_shipping'] = true;
                    $rule_scope_meta['incomplete_free_shipping_gross'] = round(
                        $rule_scope_meta['incomplete_free_shipping_gross'] + $gross_raw,
                        2
                    );
                }
            }

            $rule_key = (string)$idx;
            $row = [
                'rule' => $rule,
                'gross_raw' => $gross_raw,
                'net_raw' => $net_raw,
            ];
            $rule_rows[$rule_key] = $row;
            if ($net_raw !== null) {
                $complete_rule_rows[$rule_key] = $row;
            }
        }

        if (empty($rule_rows)) {
            return [];
        }

        if (empty($complete_rule_rows)) {
            return [];
        }

        $gross_weights = [];
        $net_weights = [];
        $complete_raw_gross_total = 0.0;
        $complete_raw_net_total = 0.0;
        foreach ($complete_rule_rows as $rule_key => $row) {
            $gross_weights[$rule_key] = (float)$row['gross_raw'];
            $net_weights[$rule_key] = (float)$row['net_raw'];
            $complete_raw_gross_total = round($complete_raw_gross_total + (float)$row['gross_raw'], 2);
            $complete_raw_net_total = round($complete_raw_net_total + (float)$row['net_raw'], 2);
        }

        $complete_gross_target = round(min((float)$discount_gross_total, $complete_raw_gross_total), 2);
        $complete_net_target = round(min((float)$discount_net_total, $complete_raw_net_total), 2);
        if ($complete_net_target > $complete_gross_target) {
            $complete_net_target = $complete_gross_target;
        }

        $allocated_gross = $this->allocateTwoAmountByWeights($complete_gross_target, $gross_weights);
        $net_weight_source = array_sum($net_weights) > 0 ? $net_weights : $gross_weights;
        $allocated_net = $this->allocateTwoAmountByWeights($complete_net_target, $net_weight_source);

        $lines = [];
        $allocated_complete_gross_total = 0.0;
        $allocated_complete_net_total = 0.0;

        foreach ($complete_rule_rows as $rule_key => $row) {
            $line_gross = isset($allocated_gross[$rule_key]) ? (float)$allocated_gross[$rule_key] : 0.0;
            $line_net = isset($allocated_net[$rule_key]) ? (float)$allocated_net[$rule_key] : 0.0;

            if ($line_gross <= 0) {
                continue;
            }

            if ($line_net < 0) {
                $line_net = 0.0;
            }
            if ($line_net > $line_gross) {
                $line_net = $line_gross;
            }

            $descriptor = $this->buildTwoSingleDiscountDescriptor($row['rule']);
            $line_tax = round($line_gross - $line_net, 2);
            $segments = $this->buildTwoCanonicalDiscountRateSegments($line_net, $line_tax, $known_context_rates);
            if (empty($segments)) {
                $fallback_rate = $line_net > 0
                    ? max(0, $line_tax / $line_net)
                    : 0.0;
                $fallback_rate = $this->normalizeTwoTaxRateToPercentPrecision($fallback_rate);
                $snapped_fallback_rate = $this->normalizeTwoTaxRateToPercentPrecision(
                    $this->snapTwoTaxRateToKnownContexts($fallback_rate, $known_context_rates)
                );
                if (abs($line_tax - ($line_net * $snapped_fallback_rate)) <= self::TAX_FORMULA_TOLERANCE) {
                    $fallback_rate = $snapped_fallback_rate;
                }
                $segments = [[
                    'net' => $line_net,
                    'tax' => $line_tax,
                    'gross' => round($line_net + $line_tax, 2),
                    'rate' => $fallback_rate,
                ]];
            }

            $is_split = count($segments) > 1;
            foreach ($segments as $segment) {
                $segment_net = round((float)$segment['net'], 2);
                $segment_tax = round((float)$segment['tax'], 2);
                $segment_gross = round((float)$segment['gross'], 2);
                if ($segment_gross <= 0) {
                    continue;
                }

                $segment_rate = max(0, (float)$segment['rate']);
                $segment_rate_percent = round($segment_rate * 100, 2);
                $segment_name = $descriptor['name'];
                if ($is_split) {
                    $segment_name .= ' (' . $this->l('VAT') . ' ' . $this->getTwoRoundAmount($segment_rate_percent) . '%)';
                }

                $lines[] = [
                    'name' => $segment_name,
                    'description' => $descriptor['description'],
                    'gross_amount' => (string)$this->getTwoRoundAmount(-$segment_gross),
                    'net_amount' => (string)$this->getTwoRoundAmount(-$segment_net),
                    'discount_amount' => '0.00',
                    'tax_amount' => (string)$this->getTwoRoundAmount(-$segment_tax),
                    'tax_class_name' => 'VAT ' . $this->getTwoRoundAmount($segment_rate_percent) . '%',
                    'tax_rate' => $this->formatTwoTaxRate($segment_rate),
                    'unit_price' => (string)$this->getTwoRoundAmount(-$segment_net),
                    'quantity' => 1,
                    'quantity_unit' => 'item',
                    'image_url' => '',
                    'product_page_url' => '',
                    'type' => 'DIGITAL',
                ];
            }

            $allocated_complete_gross_total = round($allocated_complete_gross_total + $line_gross, 2);
            $allocated_complete_net_total = round($allocated_complete_net_total + $line_net, 2);
        }

        if (empty($lines)) {
            return [];
        }

        $remaining_discount_gross = round(max(0, $remaining_discount_gross - $allocated_complete_gross_total), 2);
        $remaining_discount_net = round(max(0, $remaining_discount_net - $allocated_complete_net_total), 2);
        if ($remaining_discount_net > $remaining_discount_gross) {
            $remaining_discount_net = $remaining_discount_gross;
        }

        return $lines;
    }

    /**
     * Split a discount row into canonical tax-rate segments when possible.
     * This avoids blended synthetic rates while preserving row net/tax totals.
     *
     * @param float $line_net Positive net amount
     * @param float $line_tax Positive tax amount
     * @param array $known_rates Decimal tax-rate contexts detected from positive items
     * @return array<int,array{net:float,tax:float,gross:float,rate:float}>
     */
    private function buildTwoCanonicalDiscountRateSegments($line_net, $line_tax, $known_rates)
    {
        $net_cents = $this->convertAmountToCents($line_net);
        $tax_cents = $this->convertAmountToCents($line_tax);
        if ($net_cents <= 0 || $tax_cents < 0) {
            return [];
        }

        $rates = [];
        foreach ((array)$known_rates as $rate) {
            $normalized_rate = $this->normalizeTwoTaxRateToPercentPrecision((float)$rate);
            $key = $this->formatTwoTaxRate($normalized_rate, 4);
            $rates[$key] = $normalized_rate;
        }
        if (empty($rates)) {
            return [];
        }

        $rates = array_values($rates);
        sort($rates, SORT_NUMERIC);

        foreach ($rates as $rate) {
            if ((int)round($net_cents * $rate, 0) === $tax_cents) {
                return [[
                    'net' => round($net_cents / 100, 2),
                    'tax' => round($tax_cents / 100, 2),
                    'gross' => round(($net_cents + $tax_cents) / 100, 2),
                    'rate' => $rate,
                ]];
            }
        }

        if (count($rates) < 2) {
            return [];
        }

        $implied_rate = $net_cents > 0 ? ((float)$tax_cents / (float)$net_cents) : 0.0;
        $pair_candidates = [];
        for ($i = 0; $i < count($rates); $i++) {
            for ($j = $i + 1; $j < count($rates); $j++) {
                $low_rate = $rates[$i];
                $high_rate = $rates[$j];
                if ($high_rate <= $low_rate) {
                    continue;
                }

                $outside_distance = 0.0;
                if ($implied_rate < $low_rate) {
                    $outside_distance = $low_rate - $implied_rate;
                } elseif ($implied_rate > $high_rate) {
                    $outside_distance = $implied_rate - $high_rate;
                }

                $pair_candidates[] = [
                    'low' => $low_rate,
                    'high' => $high_rate,
                    'outside' => $outside_distance,
                    'width' => $high_rate - $low_rate,
                ];
            }
        }

        usort($pair_candidates, function ($left, $right) {
            if ($left['outside'] < $right['outside']) {
                return -1;
            }
            if ($left['outside'] > $right['outside']) {
                return 1;
            }
            if ($left['width'] < $right['width']) {
                return -1;
            }
            if ($left['width'] > $right['width']) {
                return 1;
            }
            return 0;
        });

        foreach ($pair_candidates as $pair) {
            $split = $this->solveTwoRateDiscountSplitInCents(
                $net_cents,
                $tax_cents,
                (float)$pair['low'],
                (float)$pair['high']
            );
            if ($split === null) {
                continue;
            }

            $segments = [];
            if ($split['low_net_cents'] > 0 || $split['low_tax_cents'] > 0) {
                $segments[] = [
                    'net' => round($split['low_net_cents'] / 100, 2),
                    'tax' => round($split['low_tax_cents'] / 100, 2),
                    'gross' => round(($split['low_net_cents'] + $split['low_tax_cents']) / 100, 2),
                    'rate' => (float)$pair['low'],
                ];
            }
            if ($split['high_net_cents'] > 0 || $split['high_tax_cents'] > 0) {
                $segments[] = [
                    'net' => round($split['high_net_cents'] / 100, 2),
                    'tax' => round($split['high_tax_cents'] / 100, 2),
                    'gross' => round(($split['high_net_cents'] + $split['high_tax_cents']) / 100, 2),
                    'rate' => (float)$pair['high'],
                ];
            }

            if (!empty($segments)) {
                return $segments;
            }
        }

        return [];
    }

    /**
     * Solve a two-rate split in cents where tax is computed as round(net * rate).
     *
     * @param int $net_cents
     * @param int $tax_cents
     * @param float $low_rate
     * @param float $high_rate
     * @return array|null
     */
    private function solveTwoRateDiscountSplitInCents($net_cents, $tax_cents, $low_rate, $high_rate)
    {
        if ($net_cents <= 0 || $high_rate <= $low_rate) {
            return null;
        }

        $estimate_high_net = (int)round(
            ((float)$tax_cents - ((float)$net_cents * $low_rate)) / ($high_rate - $low_rate),
            0
        );
        $estimate_high_net = max(0, min($net_cents, $estimate_high_net));

        $max_offset = min($net_cents, 5000);
        for ($offset = 0; $offset <= $max_offset; $offset++) {
            $candidates = [$estimate_high_net + $offset];
            if ($offset > 0) {
                $candidates[] = $estimate_high_net - $offset;
            }

            foreach ($candidates as $candidate_high_net) {
                if ($candidate_high_net < 0 || $candidate_high_net > $net_cents) {
                    continue;
                }
                $candidate_low_net = $net_cents - $candidate_high_net;
                $candidate_low_tax = (int)round($candidate_low_net * $low_rate, 0);
                $candidate_high_tax = (int)round($candidate_high_net * $high_rate, 0);
                if (($candidate_low_tax + $candidate_high_tax) !== $tax_cents) {
                    continue;
                }

                return [
                    'low_net_cents' => $candidate_low_net,
                    'low_tax_cents' => $candidate_low_tax,
                    'high_net_cents' => $candidate_high_net,
                    'high_tax_cents' => $candidate_high_tax,
                ];
            }
        }

        if ($net_cents > 50000) {
            return null;
        }

        for ($candidate_high_net = 0; $candidate_high_net <= $net_cents; $candidate_high_net++) {
            $candidate_low_net = $net_cents - $candidate_high_net;
            $candidate_low_tax = (int)round($candidate_low_net * $low_rate, 0);
            $candidate_high_tax = (int)round($candidate_high_net * $high_rate, 0);
            if (($candidate_low_tax + $candidate_high_tax) !== $tax_cents) {
                continue;
            }

            return [
                'low_net_cents' => $candidate_low_net,
                'low_tax_cents' => $candidate_low_tax,
                'high_net_cents' => $candidate_high_net,
                'high_tax_cents' => $candidate_high_tax,
            ];
        }

        return null;
    }

    /**
     * Extract gross discount amount from a cart rule.
     *
     * @param array $rule
     * @return float
     */
    private function extractTwoDiscountRuleGrossAmount($rule)
    {
        $gross_fields = ['value_real', 'value'];
        foreach ($gross_fields as $field) {
            if (!isset($rule[$field]) || !is_numeric($rule[$field])) {
                continue;
            }

            $amount = abs((float)$rule[$field]);
            if ($amount > 0) {
                return $amount;
            }
        }

        // Only trust reduction_amount as gross when explicitly tax-excluded.
        if (
            isset($rule['reduction_amount']) && is_numeric($rule['reduction_amount']) &&
            isset($rule['reduction_tax']) && (int)$rule['reduction_tax'] === 0
        ) {
            $amount = abs((float)$rule['reduction_amount']);
            if ($amount > 0) {
                return $amount;
            }
        }

        return 0.0;
    }

    /**
     * Extract net discount amount from a cart rule.
     *
     * @param array $rule
     * @param float $gross_amount
     * @return float|null
     */
    private function extractTwoDiscountRuleNetAmount($rule, $gross_amount)
    {
        if (isset($rule['value_tax_exc']) && is_numeric($rule['value_tax_exc'])) {
            return abs((float)$rule['value_tax_exc']);
        }

        if (isset($rule['reduction_tax']) && (int)$rule['reduction_tax'] === 0) {
            return (float)$gross_amount;
        }

        return null;
    }

    /**
     * Build discount line descriptor for a single cart rule.
     *
     * @param array $rule
     * @return array{name:string,description:string}
     */
    private function buildTwoSingleDiscountDescriptor($rule)
    {
        $name = isset($rule['name']) && !Tools::isEmpty($rule['name'])
            ? trim((string)$rule['name'])
            : $this->l('Discount');

        $description = isset($rule['description']) && !Tools::isEmpty($rule['description'])
            ? trim((string)$rule['description'])
            : $name;

        if (!empty($rule['code'])) {
            $description .= ' (' . trim((string)$rule['code']) . ')';
        }

        return [
            'name' => $name,
            'description' => Tools::substr(strip_tags($description), 0, 255),
        ];
    }

    /**
     * Build discount line descriptor from cart rules.
     *
     * @param Cart $cart
     * @return array ['name' => string, 'description' => string]
     */
    private function buildTwoDiscountDescriptor($cart)
    {
        $cart_rules = $cart->getCartRules();
        $discount_name = $this->l('Discount');
        $discount_description = $this->l('Order discount');

        if (empty($cart_rules)) {
            return [
                'name' => $discount_name,
                'description' => Tools::substr(strip_tags($discount_description), 0, 255),
            ];
        }

        $primary_rule = reset($cart_rules);
        $discount_name = isset($primary_rule['name']) ? $primary_rule['name'] : $discount_name;

        $discount_parts = [];
        foreach ($cart_rules as $rule) {
            $rule_desc = isset($rule['name']) ? $rule['name'] : $this->l('Discount');
            if (!empty($rule['code'])) {
                $rule_desc .= ' (' . $rule['code'] . ')';
            }
            if (isset($rule['value']) && $rule['value']) {
                if (!empty($rule['reduction_percent'])) {
                    $rule_desc .= ' - ' . $rule['reduction_percent'] . '%';
                } elseif (!empty($rule['reduction_amount'])) {
                    $rule_desc .= ' - ' . Tools::displayPrice($rule['reduction_amount']);
                }
            }
            $discount_parts[] = $rule_desc;
        }

        $discount_description = implode(', ', $discount_parts);
        if (strlen($discount_description) > 200) {
            $discount_description = !empty($primary_rule['description'])
                ? Tools::substr(strip_tags($primary_rule['description']), 0, 200)
                : sprintf($this->l('Discount: %s'), $discount_name);
        }

        if (count($cart_rules) > 1) {
            $discount_name = sprintf($this->l('%s (+%d more)'), $discount_name, count($cart_rules) - 1);
        }

        return [
            'name' => $discount_name,
            'description' => Tools::substr(strip_tags($discount_description), 0, 255),
        ];
    }

    /**
     * Collect tax contexts from positive existing payload lines.
     *
     * @param array $existingItems
     * @return array
     */
    private function collectDiscountTaxContextsFromItems($existingItems)
    {
        $contexts = [];

        foreach ($existingItems as $item) {
            $line_gross = isset($item['gross_amount']) ? round((float)$item['gross_amount'], 2) : 0.0;
            $line_net = isset($item['net_amount']) ? round((float)$item['net_amount'], 2) : 0.0;
            if ($line_gross <= 0 || $line_net <= 0) {
                continue;
            }

            $line_tax = round($line_gross - $line_net, 2);
            $line_rate = isset($item['tax_rate']) ? round(max(0, (float)$item['tax_rate']), self::TAX_RATE_PRECISION) : 0.0;
            $context_key = $this->formatTwoTaxRate($line_rate);

            if (!isset($contexts[$context_key])) {
                $contexts[$context_key] = [
                    'tax_rate' => $line_rate,
                    'net_weight' => 0.0,
                    'tax_weight' => 0.0,
                ];
            }

            $contexts[$context_key]['net_weight'] += $line_net;
            $contexts[$context_key]['tax_weight'] += max(0, $line_tax);
        }

        return $contexts;
    }

    /**
     * Collect configured product tax rates from cart lines as decimal contexts.
     *
     * @param array $line_items
     * @return array
     */
    private function collectTwoKnownTaxRatesFromConfiguredProductRates($line_items)
    {
        $known_rates = [];
        foreach ($line_items as $line_item) {
            if (!isset($line_item['rate']) || !is_numeric($line_item['rate'])) {
                continue;
            }

            $rate_percent = max(0, (float)$line_item['rate']);
            if ($rate_percent <= 0) {
                continue;
            }

            $rate_decimal = $this->normalizeTwoTaxRateToPercentPrecision($rate_percent / 100);
            $normalized = $this->formatTwoTaxRate($rate_decimal);
            $known_rates[$normalized] = (float)$normalized;
        }

        return array_values($known_rates);
    }

    /**
     * Determine whether ES canonical tax-rate policy should be applied for this cart.
     *
     * @param Cart $cart
     * @return bool
     */
    private function shouldApplyTwoSpanishTaxRatePolicy($cart)
    {
        if (!Validate::isLoadedObject($cart)) {
            return false;
        }

        $address_ids = array_unique(array_filter([
            (int)$cart->id_address_invoice,
            (int)$cart->id_address_delivery,
        ]));

        foreach ($address_ids as $address_id) {
            $address = new Address((int)$address_id);
            if (!Validate::isLoadedObject($address)) {
                continue;
            }

            $country_iso = Country::getIsoById((int)$address->id_country);
            if (is_string($country_iso) && strtoupper($country_iso) === 'ES') {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply ES canonical tax-rate fallback across unresolved payload lines.
     * Default fallback rate for unresolved lines is 0.21 when formula-safe.
     *
     * @param array $items
     * @return array
     */
    private function applyTwoSpanishCanonicalTaxRateFallbackToItems($items)
    {
        if (empty($items)) {
            return $items;
        }

        $canonical_rates = [
            0.21,
            0.10,
            0.04,
        ];

        $known_canonical_rates = [];
        foreach ($items as $item) {
            if (!isset($item['tax_rate'])) {
                continue;
            }

            $rate = $this->normalizeTwoTaxRateToPercentPrecision((float)$item['tax_rate']);
            foreach ($canonical_rates as $canonical_rate) {
                if (abs($rate - $canonical_rate) <= 0.000001) {
                    $known_canonical_rates[(string)$canonical_rate] = $canonical_rate;
                    break;
                }
            }
        }

        $fallback_candidates = array_values($known_canonical_rates);
        if (empty($fallback_candidates)) {
            $fallback_candidates[] = self::SPANISH_FALLBACK_TAX_RATE;
        } elseif (!in_array(self::SPANISH_FALLBACK_TAX_RATE, $fallback_candidates, true)) {
            $fallback_candidates[] = self::SPANISH_FALLBACK_TAX_RATE;
        }

        foreach ($items as $index => $item) {
            if (!isset($item['tax_rate']) || !isset($item['net_amount']) || !isset($item['tax_amount'])) {
                continue;
            }

            $line_rate = $this->normalizeTwoTaxRateToPercentPrecision((float)$item['tax_rate']);
            $line_net = round((float)$item['net_amount'], 2);
            $line_tax = round((float)$item['tax_amount'], 2);

            if (abs($line_net) < 0.01) {
                continue;
            }

            $is_already_canonical = false;
            foreach ($canonical_rates as $canonical_rate) {
                if (abs($line_rate - $canonical_rate) <= 0.000001) {
                    $is_already_canonical = true;
                    break;
                }
            }
            if ($is_already_canonical) {
                continue;
            }

            $selected_rate = null;
            $nearest_diff = null;
            foreach ($fallback_candidates as $candidate_rate) {
                $candidate_rate = $this->normalizeTwoTaxRateToPercentPrecision((float)$candidate_rate);
                $diff = abs($line_rate - $candidate_rate);
                if ($nearest_diff === null || $diff < $nearest_diff) {
                    $nearest_diff = $diff;
                    $selected_rate = $candidate_rate;
                }
            }

            if ($selected_rate === null) {
                $selected_rate = self::SPANISH_FALLBACK_TAX_RATE;
            }

            $is_formula_safe = abs($line_tax - ($line_net * $selected_rate)) <= self::TAX_FORMULA_TOLERANCE;
            if (!$is_formula_safe && abs($line_tax - ($line_net * self::SPANISH_FALLBACK_TAX_RATE)) <= self::TAX_FORMULA_TOLERANCE) {
                $selected_rate = self::SPANISH_FALLBACK_TAX_RATE;
                $is_formula_safe = true;
            }

            if (!$is_formula_safe) {
                continue;
            }

            $items[$index]['tax_rate'] = $this->formatTwoTaxRate($selected_rate);
            $items[$index]['tax_class_name'] = 'VAT ' . $this->getTwoRoundAmount($selected_rate * 100) . '%';
        }

        return $items;
    }

    /**
     * Collect unique tax-rate contexts from positive payload lines.
     *
     * @param array $items
     * @return array
     */
    private function collectTwoKnownTaxRatesFromPositiveItems($items)
    {
        $known_rates = [];
        foreach ($items as $item) {
            $line_gross = isset($item['gross_amount']) ? round((float)$item['gross_amount'], 2) : 0.0;
            $line_net = isset($item['net_amount']) ? round((float)$item['net_amount'], 2) : 0.0;
            if ($line_gross <= 0 || $line_net <= 0 || !isset($item['tax_rate'])) {
                continue;
            }

            $normalized_rate = $this->formatTwoTaxRate((float)$item['tax_rate']);
            $known_rates[$normalized_rate] = (float)$normalized_rate;
        }

        return array_values($known_rates);
    }

    /**
     * Resolve carrier tax-rule rate as decimal (e.g. 0.21 for 21%).
     *
     * @param Carrier $carrier
     * @param Cart $cart
     * @return float
     */
    private function getTwoCarrierConfiguredTaxRateDecimal($carrier, $cart)
    {
        if (!Validate::isLoadedObject($carrier) || !method_exists($carrier, 'getIdTaxRulesGroup')) {
            return 0.0;
        }

        $taxRulesGroupId = (int)$carrier->getIdTaxRulesGroup();
        if ($taxRulesGroupId <= 0) {
            return 0.0;
        }

        $address = new Address((int)$cart->id_address_delivery);
        if (!Validate::isLoadedObject($address)) {
            $address = new Address((int)$cart->id_address_invoice);
        }

        try {
            $taxManager = TaxManagerFactory::getManager($address, $taxRulesGroupId);
            if (!is_object($taxManager) || !method_exists($taxManager, 'getTaxCalculator')) {
                return 0.0;
            }

            $taxCalculator = $taxManager->getTaxCalculator();
            if (!is_object($taxCalculator) || !method_exists($taxCalculator, 'getTotalRate')) {
                return 0.0;
            }

            $ratePercent = max(0, (float)$taxCalculator->getTotalRate());
            if ($ratePercent <= 0) {
                return 0.0;
            }

            return $this->normalizeTwoTaxRateToPercentPrecision($ratePercent / 100);
        } catch (Exception $e) {
            if (Configuration::get('PS_TWO_DEBUG_MODE')) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Unable to resolve carrier tax rate from tax rules - ' . $e->getMessage(),
                    2
                );
            }
        }

        return 0.0;
    }

    /**
     * Allocate a monetary amount across weighted buckets using cent-accurate largest-remainder distribution.
     *
     * @param float $totalAmount Positive amount to distribute
     * @param array $weights map[string]float
     * @return array map[string]float (2-decimal amounts summing to totalAmount)
     */
    private function allocateTwoAmountByWeights($totalAmount, $weights)
    {
        $total_cents = $this->convertAmountToCents($totalAmount);
        if ($total_cents <= 0 || empty($weights)) {
            return [];
        }

        $normalized_weights = [];
        foreach ($weights as $key => $weight) {
            $normalized_weights[$key] = max(0.0, (float)$weight);
        }

        $total_weight = array_sum($normalized_weights);
        if ($total_weight <= 0) {
            $normalized_weights = array_fill_keys(array_keys($weights), 1.0);
            $total_weight = (float)count($normalized_weights);
        }

        $allocated_cents = [];
        $remainders = [];
        $distributed_cents = 0;
        foreach ($normalized_weights as $key => $weight) {
            $raw_share = ($total_cents * $weight) / $total_weight;
            $base_cents = (int)floor($raw_share);
            $allocated_cents[$key] = $base_cents;
            $remainders[$key] = $raw_share - $base_cents;
            $distributed_cents += $base_cents;
        }

        $remaining_cents = $total_cents - $distributed_cents;
        if ($remaining_cents > 0) {
            arsort($remainders);
            $remainder_keys = array_keys($remainders);
            $remainder_count = count($remainder_keys);
            for ($i = 0; $i < $remaining_cents; $i++) {
                $target_key = $remainder_keys[$i % $remainder_count];
                $allocated_cents[$target_key] += 1;
            }
        }

        $allocated_amounts = [];
        foreach ($allocated_cents as $key => $cents) {
            $allocated_amounts[$key] = round($cents / 100, 2);
        }

        return $allocated_amounts;
    }

    /**
     * Calculate order totals from tax subtotals (Two API requirement)
     * Ensures gross_amount = sum(tax_subtotals) for API validation
     * 
     * @param array $tax_subtotals Tax subtotals array from getTwoTaxSubtotals()
     * @return array ['net' => float, 'tax' => float, 'gross' => float]
     */
    private function calculateOrderTotalsFromTaxSubtotals($tax_subtotals)
    {
        $net = 0;
        $tax = 0;
        foreach ($tax_subtotals as $subtotal) {
            $net += (float)$subtotal['taxable_amount'];
            $tax += (float)$subtotal['tax_amount'];
        }
        return [
            'net' => $net,
            'tax' => $tax,
            'gross' => $net + $tax
        ];
    }

    /**
     * Determine whether tax subtotals should be sent in outbound payloads.
     *
     * @return bool
     */
    private function shouldIncludeTaxSubtotals()
    {
        return (bool)Configuration::get('PS_TWO_ENABLE_TAX_SUBTOTALS', 1);
    }

    /**
     * Get phone number with PrestaShop-native fallback chain
     * Priority: phone → phone_mobile → empty (let Two API validate)
     * 
     * @param Address $address PrestaShop Address object
     * @return string Phone number or empty string
     */
    private function getPhoneWithFallback($address)
    {
        // Validate address object
        if (!Validate::isLoadedObject($address)) {
            return '';
        }
        
        // Priority 1: Main phone
        if (!empty($address->phone)) {
            return trim($address->phone);
        }
        
        // Priority 2: Mobile phone
        if (!empty($address->phone_mobile)) {
            return trim($address->phone_mobile);
        }
        
        // No phone found - log warning but let Two API handle validation
        PrestaShopLogger::addLog(
            'TwoPayment: No phone number found for address ID ' . $address->id . ' - Two API will validate',
            2
        );
        return '';
    }

    /**
     * Retrieve validated company data from session cookie for a given country.
     *
     * @param string $country_iso
     * @return array ['company_name' => string, 'organization_number' => string]
     */
    public function getTwoValidatedSessionCompanyData($country_iso)
    {
        $country_iso = strtoupper(trim((string)$country_iso));
        $session_company = isset($this->context->cookie->two_company_name) ? trim((string)$this->context->cookie->two_company_name) : '';
        $session_company_id = isset($this->context->cookie->two_company_id) ? trim((string)$this->context->cookie->two_company_id) : '';
        $session_company_country = isset($this->context->cookie->two_company_country) ? strtoupper(trim((string)$this->context->cookie->two_company_country)) : '';

        if (Tools::isEmpty($session_company) || Tools::isEmpty($session_company_id)) {
            return array(
                'company_name' => '',
                'organization_number' => '',
            );
        }

        if (Tools::isEmpty($session_company_country) && !Tools::isEmpty($country_iso)) {
            // Legacy session values without country marker cannot be safely reused across countries.
            unset($this->context->cookie->two_company_name);
            unset($this->context->cookie->two_company_id);
            unset($this->context->cookie->two_company_country);
            unset($this->context->cookie->two_company_address_id);
            if (method_exists($this->context->cookie, 'write')) {
                $this->context->cookie->write();
            }

            PrestaShopLogger::addLog(
                'TwoPayment: Cleared legacy session company without country marker for address country=' . $country_iso,
                2
            );

            return array(
                'company_name' => '',
                'organization_number' => '',
            );
        }

        if (!Tools::isEmpty($session_company_country) && !Tools::isEmpty($country_iso) && $session_company_country !== $country_iso) {
            // Prevent cross-country stale company reuse when customer changes address country.
            unset($this->context->cookie->two_company_name);
            unset($this->context->cookie->two_company_id);
            unset($this->context->cookie->two_company_country);
            unset($this->context->cookie->two_company_address_id);
            if (method_exists($this->context->cookie, 'write')) {
                $this->context->cookie->write();
            }

            PrestaShopLogger::addLog(
                'TwoPayment: Cleared stale session company due to country mismatch. Session country=' .
                $session_company_country . ', address country=' . $country_iso,
                2
            );

            return array(
                'company_name' => '',
                'organization_number' => '',
            );
        }

        return array(
            'company_name' => $session_company,
            'organization_number' => $session_company_id,
        );
    }

    /**
     * Public checkout resolver for company data.
     * Uses the same fallback chain as order payload building so checkout guard logic
     * behaves consistently across supported countries.
     *
     * @param Address $address Invoice address
     * @return array ['company_name' => string, 'organization_number' => string, 'country_iso' => string]
     */
    public function getTwoCheckoutCompanyData($address)
    {
        try {
            $data = $this->getCompanyDataWithFallbacks($address);
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'TwoPayment: Failed resolving checkout company data - ' . $e->getMessage(),
                2
            );
            return array(
                'company_name' => '',
                'organization_number' => '',
                'country_iso' => '',
            );
        }

        return array(
            'company_name' => isset($data['company_name']) ? trim((string) $data['company_name']) : '',
            'organization_number' => isset($data['organization_number']) ? trim((string) $data['organization_number']) : '',
            'country_iso' => isset($data['country_iso']) ? strtoupper(trim((string) $data['country_iso'])) : '',
        );
    }

    /**
     * Get company name and organization number with fallback chain
     * Priority: Cookie (verified) → Address fields (dni, vat_number) → Cookie (unverified)
     * 
     * ENHANCED: Now checks multiple address fields for org numbers across all countries,
     * not just dni for Spain. This supports addresses where org numbers are stored in
     * dni, vat_number, or other fields.
     * 
     * @param Address $address Invoice or delivery address
     * @return array ['company_name' => string, 'organization_number' => string, 'country_iso' => string]
     */
    private function getCompanyDataWithFallbacks($address)
    {
        // Validate address object is loaded
        if (!Validate::isLoadedObject($address)) {
            PrestaShopLogger::addLog('TwoPayment: Invalid address object passed to getCompanyDataWithFallbacks', 3);
            throw new Exception('Invalid address object');
        }
        
        // CRITICAL: Validate country ID and handle false return from Country::getIsoById()
        $country_iso = Country::getIsoById($address->id_country);
        if (!$country_iso || !is_string($country_iso)) {
            PrestaShopLogger::addLog('TwoPayment: Invalid country ID: ' . $address->id_country . ' for address ID: ' . $address->id, 3);
            throw new Exception('Invalid country in address');
        }

        $address_company = trim((string) $address->company);
        $current_address_id = (int) $address->id;
        $session_address_id = isset($this->context->cookie->two_company_address_id)
            ? (int) $this->context->cookie->two_company_address_id
            : 0;
        $allow_cookie_company_fallback = true;

        // Priority 1: Session cookie (from company search - already verified and country-validated)
        $validated_session_company = $this->getTwoValidatedSessionCompanyData($country_iso);
        if (!empty($validated_session_company['company_name']) && !empty($validated_session_company['organization_number'])) {
            $session_company_name = trim((string) $validated_session_company['company_name']);

            if ($session_address_id > 0 && $current_address_id > 0 && $session_address_id !== $current_address_id) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Ignoring session company due to address switch. Session address=' .
                    $session_address_id . ', current address=' . $current_address_id,
                    2
                );
                $allow_cookie_company_fallback = false;
            } else {
                return [
                    'company_name' => $session_company_name,
                    'organization_number' => $validated_session_company['organization_number'],
                    'country_iso' => $country_iso
                ];
            }
        }
        
        // Priority 2: Extract org number from address fields (dni, vat_number, companyid)
        // This uses the enhanced extraction method that works across all countries
        $org_number = $this->extractOrgNumberFromAddress($address, $country_iso);
        
        // Company name: Address → Cookie
        $company_name = !Tools::isEmpty($address_company)
            ? $address_company
            : (($allow_cookie_company_fallback && isset($this->context->cookie->two_company_name))
                ? trim($this->context->cookie->two_company_name) 
                : '');
        
        // If we found org number from address but no company name, we can still use it
        // Two's order API will accept org number and resolve company name
        
        return [
            'company_name' => $company_name,
            'organization_number' => $org_number,
            'country_iso' => $country_iso
        ];
    }

    /**
     * Build address array for Two API
     * 
     * @param Address $address PrestaShop Address object
     * @param string|null $organization_name Company name (may differ from address->company)
     * @return array Two API address format
     */
    private function buildTwoAddress($address, $organization_name = null, $country_iso = null)
    {
        // Validate address object is loaded
        if (!Validate::isLoadedObject($address)) {
            PrestaShopLogger::addLog('TwoPayment: Invalid address object passed to buildTwoAddress', 3);
            throw new Exception('Invalid address object');
        }
        
        if ($organization_name === null) {
            $organization_name = $address->company;
        }
        
        // Use provided country_iso or fetch it (validate false return)
        if ($country_iso === null) {
            $country_iso = Country::getIsoById($address->id_country);
            if (!$country_iso || !is_string($country_iso)) {
                PrestaShopLogger::addLog('TwoPayment: Invalid country ID: ' . $address->id_country . ' for address ID: ' . $address->id, 3);
                throw new Exception('Invalid country in address');
            }
        }
        
        // Validate street_address is not empty (Two API requirement)
        $street_address = trim($address->address1 . (!empty($address->address2) ? ' ' . $address->address2 : ''));
        if (empty($street_address)) {
            PrestaShopLogger::addLog('TwoPayment: Empty street address for address ID: ' . $address->id, 3);
            // Use fallback instead of throwing (allows order to proceed)
            $street_address = 'N/A';
        }
        
        return [
            'city' => $address->city,
            'country' => $country_iso,
            'organization_name' => $organization_name,
            'postal_code' => $address->postcode,
            'region' => $address->id_state ? State::getNameById($address->id_state) : '',
            'street_address' => $street_address
        ];
    }

    /**
     * Calculate tax subtotals for Two API compliance
     * Groups line items by tax rate and calculates taxable_amount and tax_amount per rate
     *
     * @param array $line_items Array of line items with tax_rate, net_amount, and tax_amount
     * @return array Tax subtotals array for Two API
     */
    public function getTwoTaxSubtotals($line_items)
    {
        $tax_subtotals = [];
        $tax_groups = [];
        
        // Group line items by tax rate
        foreach ($line_items as $item) {
            $tax_rate = $this->formatTwoTaxRate(
                isset($item['tax_rate']) ? (float)$item['tax_rate'] : 0,
                self::TAX_SUBTOTAL_RATE_PRECISION
            );
            // Round amounts before summing to prevent floating point precision issues
            $net_amount = round((float)$item['net_amount'], 2);
            $tax_amount = round((float)$item['tax_amount'], 2);
            
            if (!isset($tax_groups[$tax_rate])) {
                $tax_groups[$tax_rate] = [
                    'taxable_amount' => 0,
                    'tax_amount' => 0,
                    'tax_rate' => $tax_rate
                ];
            }
            
            // Round after each addition to prevent precision drift
            $tax_groups[$tax_rate]['taxable_amount'] = round($tax_groups[$tax_rate]['taxable_amount'] + $net_amount, 2);
            $tax_groups[$tax_rate]['tax_amount'] = round($tax_groups[$tax_rate]['tax_amount'] + $tax_amount, 2);
        }
        
        // Convert to Two API format
        foreach ($tax_groups as $rate => $group) {
            $tax_subtotals[] = [
                'tax_rate' => $rate,
                'taxable_amount' => (string)($this->getTwoRoundAmount($group['taxable_amount'])),
                'tax_amount' => (string)($this->getTwoRoundAmount($group['tax_amount']))
            ];
        }
        
        // Sort by tax rate for consistency
        usort($tax_subtotals, function($a, $b) {
            return (float)$a['tax_rate'] <=> (float)$b['tax_rate'];
        });
        
        return $tax_subtotals;
    }

    /**
     * Validate all line items against Two API formulas (streamlined)
     * Only logs critical validation failures
     *
     * @param array $line_items Array of line items to validate
     * @return bool True if all validations pass, false otherwise
     */
    public function validateTwoLineItems($line_items)
    {
        $validation_issues = 0;
        
        foreach ($line_items as $item) {
            $net_amount = (float)$item['net_amount'];
            $tax_amount = (float)$item['tax_amount'];
            $tax_rate = (float)$item['tax_rate'];
            $unit_price = (float)$item['unit_price'];
            $quantity = (int)$item['quantity'];
            $discount_amount = (float)$item['discount_amount'];
            
            // Critical validation: tax_amount = net_amount * tax_rate (tax_rate is now decimal)
            // Allow 0.01 tolerance for rounding differences (2 decimal places = ±0.005 rounding error)
            $expected_tax_amount = $net_amount * $tax_rate;
            if (abs($tax_amount - $expected_tax_amount) > self::TAX_FORMULA_TOLERANCE) {
                PrestaShopLogger::addLog(
                    'TwoPayment CRITICAL Tax Formula Error - Item: ' . $item['name'] . 
                    ', Got: ' . $tax_amount . ', Expected: ' . $expected_tax_amount . 
                    ' (diff: ' . abs($tax_amount - $expected_tax_amount) . ')', 
                    3
                );
                $validation_issues++;
            }
            
            // Critical validation: net_amount = (quantity * unit_price) - discount_amount
            // Allow 0.05 tolerance for rounding differences (accounts for multiple rounding operations)
            $expected_net_amount = ($quantity * $unit_price) - $discount_amount;
            if (abs($net_amount - $expected_net_amount) > self::NET_FORMULA_TOLERANCE) {
                PrestaShopLogger::addLog(
                    'TwoPayment CRITICAL Net Formula Error - Item: ' . $item['name'] . 
                    ', Got: ' . $net_amount . ', Expected: ' . $expected_net_amount . 
                    ' (diff: ' . abs($net_amount - $expected_net_amount) . ')', 
                    3
                );
                $validation_issues++;
            }
        }
        
        return $validation_issues === 0;
    }

    /**
     * Format monetary amount to 2 decimals as string (Two API requirement).
     */
    public function getTwoRoundAmount($amount)
    {
        return number_format((float)$amount, 2, '.', '');
    }

    /**
     * Format tax rate decimal to a fixed precision (2dp).
     *
     * @param float $tax_rate Decimal tax rate (e.g. 0.21 for 21%)
     * @return string
     */
    private function formatTwoTaxRate($tax_rate, $precision = null)
    {
        $precision = $precision === null ? self::TAX_RATE_PRECISION : (int)$precision;
        $normalized = round(max(0, (float)$tax_rate), $precision);
        $formatted = number_format($normalized, $precision, '.', '');
        if (strpos($formatted, '.') !== false) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * Normalize decimal tax rate so it carries at most 2 decimals in percent.
     *
     * Example: 0.210098 => 21.0098% => 21.01% => 0.2101
     *
     * @param float $rate Decimal tax rate
     * @return float
     */
    private function normalizeTwoTaxRateToPercentPrecision($rate)
    {
        $percent = round(max(0, (float)$rate) * 100, self::TAX_RATE_PERCENT_PRECISION);
        return $percent / 100;
    }

    /**
     * Snap rate to known cart contexts when only minor rounding drift exists.
     *
     * @param float $rate
     * @param array $known_rates
     * @return float
     */
    private function snapTwoTaxRateToKnownContexts($rate, $known_rates)
    {
        $rate = max(0, (float)$rate);
        if (empty($known_rates)) {
            return $rate;
        }

        $nearest = null;
        $nearest_diff = null;
        foreach ($known_rates as $candidate) {
            $candidate = max(0, (float)$candidate);
            $diff = abs($rate - $candidate);
            if ($nearest_diff === null || $diff < $nearest_diff) {
                $nearest = $candidate;
                $nearest_diff = $diff;
            }
        }

        // Only snap when difference is tiny (pure rounding drift).
        if (
            $nearest !== null &&
            $nearest_diff !== null &&
            $nearest_diff <= self::TAX_RATE_CONTEXT_SNAP_TOLERANCE
        ) {
            return $nearest;
        }

        return $rate;
    }

    /**
     * Calculate effective order-level tax rate from final net and tax totals.
     *
     * @param float $net_amount
     * @param float $tax_amount
     * @return float Decimal tax rate
     */
    private function calculateTwoOrderTaxRate($net_amount, $tax_amount)
    {
        $net_amount = (float)$net_amount;
        $tax_amount = (float)$tax_amount;

        if (abs($net_amount) < 0.000001) {
            return 0.0;
        }

        $rate = $tax_amount / $net_amount;
        if ($rate < 0) {
            return 0.0;
        }

        return round($rate, self::TAX_RATE_PRECISION);
    }

    public function getTwoCheckoutHostUrl()
    {
        $override = $this->getDevEnvOverride('TWO_API_BASE_URL');
        if ($override !== null) {
            return $override;
        }
        return $this->getTwoCheckoutHostUrlForEnvironment(Configuration::get('PS_TWO_ENVIRONMENT'));
    }

    /**
     * Explicit environment -> API host map, mirroring the Woo/Magento plugins'
     * templated 'api.<mode>.two.inc' host builder. Any value not in this map
     * (including the legacy 'development' option and empty/unset config)
     * falls back to sandbox — the same default this plugin has always used
     * for "not production".
     */
    private const ENVIRONMENT_HOSTS = array(
        'production' => 'https://api.two.inc',
        'staging' => 'https://api.staging.two.inc',
    );

    /**
     * Explicit environment -> merchant portal host map, mirroring ENVIRONMENT_HOSTS.
     * Any value not in this map (legacy 'development', empty/unset) falls back to
     * the sandbox portal.
     */
    private const PORTAL_HOSTS = array(
        'production' => 'https://portal.two.inc',
        'staging' => 'https://portal.staging.two.inc',
    );

    /**
     * Explicit environment -> buyer portal login URL map, mirroring ENVIRONMENT_HOSTS.
     * Any value not in this map (legacy 'development', empty/unset) falls back to
     * the sandbox buyer portal.
     */
    private const BUYER_PORTAL_HOSTS = array(
        'production' => 'https://buyer.two.inc/login',
        'staging' => 'https://buyer.staging.two.inc/login',
    );

    /**
     * Get base API host for a specific environment value (without relying on saved config)
     */
    private function getTwoCheckoutHostUrlForEnvironment($environment)
    {
        $override = $this->getDevEnvOverride('TWO_API_BASE_URL');
        if ($override !== null) {
            return $override;
        }
        return self::ENVIRONMENT_HOSTS[strtolower((string) $environment)] ?? 'https://api.sandbox.two.inc';
    }

    /**
     * Returns an env-var-supplied URL when PrestaShop is in dev mode (_PS_MODE_DEV_),
     * or null otherwise. Lets internal devs route the plugin at staging / a local
     * mock without exposing a staging mode in the merchant admin UI. Mirrors the
     * convention used by magento-plugin (Model/Config/Repository::getCheckoutApiUrl).
     *
     * @param string $name Env var name (e.g. TWO_API_BASE_URL)
     * @return string|null
     */
    private function getDevEnvOverride($name)
    {
        if (!defined('_PS_MODE_DEV_') || !_PS_MODE_DEV_) {
            return null;
        }
        $value = getenv($name);
        if ($value === false || $value === '') {
            return null;
        }
        return $value;
    }

    /**
     * Verify API key directly against selected environment using submitted API key
     * Returns decoded response array on success, or false on failure
     */
    private function verifyTwoApiKey($apiKey, $environment)
    {
        $base = $this->getTwoCheckoutHostUrlForEnvironment($environment);
        $url = $base . '/v1/merchant/verify_api_key?client=PS&client_v=' . $this->version;
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'X-API-Key:' . $apiKey,
        ];
        PrestaShopLogger::addLog('TwoPayment: Verifying API key against ' . $base, 1);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::API_TIMEOUT_SHORT);
        
        // SSL VERIFICATION - Secure by default
        $this->configureSslVerification($ch);
        
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // Handle SSL/connection errors
        if ($response === false || !empty($curl_error)) {
            PrestaShopLogger::addLog(
                'TwoPayment: API key verification failed - cURL error: ' . $curl_error . 
                ' (URL: ' . $url . ')',
                3
            );
            return false;
        }

        if ($httpCode !== self::HTTP_STATUS_OK || !$response) {
            PrestaShopLogger::addLog('TwoPayment: API key verification failed. HTTP ' . (int)$httpCode . ' Response: ' . (is_string($response) ? $response : ''), 2);
            return false;
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            PrestaShopLogger::addLog('TwoPayment: API key verification returned invalid JSON', 2);
            return false;
        }
        PrestaShopLogger::addLog('TwoPayment: API key verified. Merchant ID: ' . (isset($decoded['id']) ? $decoded['id'] : 'N/A') . ', Short name: ' . (isset($decoded['short_name']) ? $decoded['short_name'] : 'N/A'), 1);
        return $decoded;
    }

    /**
     * Get the Two portal URL based on environment configuration
     * @return string Portal URL for the current environment
     */
    public function getTwoPortalUrl()
    {
        $override = $this->getDevEnvOverride('TWO_PORTAL_BASE_URL');
        if ($override !== null) {
            return $override;
        }
        $environment = strtolower((string) Configuration::get('PS_TWO_ENVIRONMENT'));
        return self::PORTAL_HOSTS[$environment] ?? 'https://portal.sandbox.two.inc';
    }

    /**
     * Get the Two buyer portal login URL based on environment
     * @return string Buyer portal login URL for the current environment
     */
    public function getTwoBuyerPortalUrl()
    {
        $environment = strtolower((string) Configuration::get('PS_TWO_ENVIRONMENT'));
        // Development/non-production environments fall back to the sandbox buyer portal.
        return self::BUYER_PORTAL_HOSTS[$environment] ?? 'https://buyer.sandbox.two.inc/login';
    }

    /**
     * Get the PDF invoice URL for a Two order
     * @param string $two_order_id The Two order ID
     * @param string $lang Language code (optional, defaults to null)
     * @param bool $generate Whether to generate a new PDF (optional, defaults to false)
     * @param string $version Version parameter (optional, defaults to null)
     * @return string PDF URL for the order
     */
    public function getTwoPdfUrl($two_order_id, $lang = null, $generate = false, $version = null)
    {
        $pdf_url = $this->getTwoCheckoutHostUrl() . '/v1/invoice/' . urlencode($two_order_id) . '/pdf';
        
        $params = array();
        if ($generate) {
            $params['generate'] = 'true';
        }
        if ($lang) {
            $params['lang'] = $lang;
        }
        if ($version) {
            $params['v'] = $version;
        }
        
        if (!empty($params)) {
            $pdf_url .= '?' . http_build_query($params);
        }

        return $pdf_url;
    }

    /**
     * Fetch the invoice PDF bytes for a Two order.
     *
     * Unlike setTwoPaymentRequest this returns the raw response body (a PDF must
     * not be run through json_decode) together with the response content type and,
     * when the API returned a JSON error body, the decoded error code/payload.
     *
     * @param string $two_order_id The Two order ID
     * @param array $params Optional query parameters (e.g. lang)
     * @param int|null $timeout Optional per-call timeout override (seconds)
     * @return array{http_status:int, body:string, content_type:string, error_code:string, data:array|null}
     */
    public function getTwoInvoicePdf($two_order_id, $params = [], $timeout = null)
    {
        $url = $this->getTwoCheckoutHostUrl() . '/v1/invoice/' . urlencode($two_order_id) . '/pdf';
        $query = array_merge(
            array('client' => 'PS', 'client_v' => $this->version),
            is_array($params) ? $params : array()
        );
        $url .= '?' . http_build_query($query);

        $headers = $this->getTwoRequestHeaders('/v1/invoice/' . $two_order_id . '/pdf');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout !== null ? max(1, (int)$timeout) : self::API_TIMEOUT_SHORT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::API_CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        // SSL VERIFICATION - Secure by default
        $this->configureSslVerification($ch);

        $response_body = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response_body === false || !empty($curl_error)) {
            PrestaShopLogger::addLog(
                'TwoPayment: cURL error on invoice PDF fetch - ' . $curl_error . ' (Two order: ' . $two_order_id . ')',
                3
            );

            return array(
                'http_status' => 0,
                'body' => '',
                'content_type' => '',
                'error_code' => '',
                'data' => array(
                    'error' => 'Connection error',
                    'error_message' => 'Unable to connect to Two API. Please check your server configuration.',
                ),
            );
        }

        $decoded = null;
        $error_code = '';
        $looks_like_pdf = strncmp((string)$response_body, '%PDF-', 5) === 0;
        if (!$looks_like_pdf) {
            $decoded = json_decode((string)$response_body, true);
            if (is_array($decoded)) {
                if (isset($decoded['error_code']) && is_scalar($decoded['error_code'])) {
                    $error_code = strtoupper(trim((string)$decoded['error_code']));
                } elseif (isset($decoded['data']['error_code']) && is_scalar($decoded['data']['error_code'])) {
                    $error_code = strtoupper(trim((string)$decoded['data']['error_code']));
                }
            }
        }

        return array(
            'http_status' => (int)$http_status,
            'body' => (string)$response_body,
            'content_type' => $content_type,
            'error_code' => $error_code,
            'data' => is_array($decoded) ? $decoded : null,
        );
    }

    /**
     * Resolve the Two order ID for invoice retrieval from the persisted order row,
     * falling back to attempt telemetry (public wrapper around the admin resolver).
     *
     * @param int $id_order
     * @param array $twopaymentdata
     * @return string Empty string when no Two order reference exists
     */
    public function resolveTwoOrderIdForInvoice($id_order, $twopaymentdata)
    {
        return $this->resolveTwoOrderIdForAdmin((int)$id_order, is_array($twopaymentdata) ? $twopaymentdata : array());
    }

    /**
     * Fetch the current Two order state via GET /v1/order/{id}.
     *
     * @param string $two_order_id
     * @param int|null $timeout Optional per-call timeout override (seconds)
     * @return array{http_status:int, state:string} Normalized (uppercase, trimmed) state; empty when unavailable
     */
    public function fetchTwoOrderStateFromApi($two_order_id, $timeout = null)
    {
        $response = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, array(), 'GET', array(), $timeout);
        $http_status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
        $payload = $this->extractTwoOrderPayloadFromApiResponse($response);
        $state = isset($payload['state']) ? strtoupper(trim((string)$payload['state'])) : '';

        return array(
            'http_status' => $http_status,
            'state' => $state,
        );
    }

    /**
     * Confirm a Two order that is in VERIFIED state to move it to CONFIRMED state
     * This signals that the buyer has returned to the merchant site after verification
     * @param string $two_order_id The Two order ID
     * @return array Result array with success status and final state
     */
    public function confirmTwoOrder($two_order_id)
    {
        PrestaShopLogger::addLog('TwoPayment: Attempting to confirm Two order ID: ' . $two_order_id, 1);
        
        $confirm_response = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id . '/confirm', [], 'POST');
        $confirm_err = $this->getTwoErrorMessage($confirm_response);
        
        if ($confirm_err) {
            PrestaShopLogger::addLog('TwoPayment: Order confirmation failed for Two order ID: ' . $two_order_id . ', Error: ' . $confirm_err, 2);
            return array(
                'success' => false,
                'error' => $confirm_err,
                'state' => null
            );
        } else {
            PrestaShopLogger::addLog('TwoPayment: Order successfully confirmed for Two order ID: ' . $two_order_id, 1);
            return array(
                'success' => true,
                'error' => null,
                'state' => isset($confirm_response['state']) ? $confirm_response['state'] : 'CONFIRMED',
                'status' => isset($confirm_response['status']) ? $confirm_response['status'] : null,
                'response' => $confirm_response
            );
        }
    }

    /**
     * The merchant's offerable payment terms (net days, ascending) sourced from
     * `available_terms` on GET /v1/merchant/{id} - the backend resolves them from
     * the merchant's pricing packages, so this is the authoritative set the admin
     * narrows from (TWO-24813). An empty result means the set is not currently
     * resolved (no verified API key / merchant id yet, or no successful fetch yet)
     * OR the backend explicitly returned an empty list.
     *
     * Cache-only by default: this is read from checkout / cart / admin-render
     * paths that must not stall on HTTP. A TTL-gated fetch (15 min, 10s request
     * cap) runs only when $refresh === true, from the two sanctioned refresh
     * points (the checkout media hook and the admin config render). The cached
     * list is overwritten only by a successful response carrying an
     * `available_terms` array; a fetch failure (or an older backend omitting the
     * field) serves the last-known list for another TTL rather than blanking the
     * term set on an API blip.
     *
     * The cache lives in two dedicated Configuration keys, NOT the general
     * settings blob, so a checkout-render refresh can never race a concurrent
     * admin settings save.
     *
     * @param bool $refresh Allow a TTL-gated backend fetch on this call.
     * @return int[] Ascending, unique day counts; empty when unresolved.
     */
    public function getMerchantAvailableTerms($refresh = false)
    {
        if ($refresh) {
            $checked_on = (int) Configuration::get(self::CONFIG_MERCHANT_AVAILABLE_TERMS_TS);
            if ($checked_on <= 0 || ($checked_on + self::MERCHANT_AVAILABLE_TERMS_TTL) <= time()) {
                $merchant_id = Configuration::get('PS_TWO_MERCHANT_ID');
                $api_key = Configuration::get('PS_TWO_MERCHANT_API_KEY');
                if (!Tools::isEmpty($merchant_id) && !Tools::isEmpty($api_key)) {
                    // Bump the shared clock BEFORE the wire call so a concurrent
                    // render at expiry serves the stale cache instead of firing a
                    // second, redundant fetch (anti-stampede - TWO-24859 review).
                    Configuration::updateValue(self::CONFIG_MERCHANT_AVAILABLE_TERMS_TS, time());
                    // On a render path even when refreshing, so cap tight.
                    $response = $this->setTwoPaymentRequest(
                        '/v1/merchant/' . rawurlencode((string) $merchant_id),
                        array(),
                        'GET',
                        array(),
                        self::API_TIMEOUT_STATE_CHECK
                    );
                    $http_status = isset($response['http_status']) ? (int) $response['http_status'] : 0;
                    if ($http_status === self::HTTP_STATUS_OK && is_array($response)) {
                        // ONE fetch feeds BOTH merchant-record caches: the
                        // offerable term list (TWO-24813) and the default-term
                        // seed (due_in_days, TWO-24859). A field absent from an
                        // otherwise-valid response is a legitimate answer (leave
                        // the term list untouched; treat an absent default as
                        // "unset" = 0), NOT a fetch failure to retry.
                        if (isset($response['available_terms']) && is_array($response['available_terms'])) {
                            $normalised = $this->normaliseMerchantTerms($response['available_terms']);
                            Configuration::updateValue(self::CONFIG_MERCHANT_AVAILABLE_TERMS, json_encode($normalised));
                        }
                        $due = isset($response['due_in_days']) ? $response['due_in_days'] : null;
                        $due_days = (is_numeric($due) && (int) $due > 0) ? (int) $due : 0;
                        Configuration::updateValue(self::CONFIG_MERCHANT_DUE_IN_DAYS, $due_days);
                        // Success: keep the full-TTL clock set above.
                    } else {
                        // Failed fetch (network blip / 5xx / bad body). Roll the
                        // pre-bumped clock back so retry happens after the short
                        // backoff, not a whole TTL, while a concurrent burst is
                        // still absorbed until then. Last-known-good values keep
                        // being served meanwhile (serve-stale - TWO-24859 review).
                        Configuration::updateValue(
                            self::CONFIG_MERCHANT_AVAILABLE_TERMS_TS,
                            time() - self::MERCHANT_AVAILABLE_TERMS_TTL + self::MERCHANT_RECORD_RETRY_BACKOFF
                        );
                    }
                }
            }
        }

        $cached = Configuration::get(self::CONFIG_MERCHANT_AVAILABLE_TERMS);
        if (Tools::isEmpty($cached)) {
            return array();
        }
        $decoded = json_decode($cached, true);
        if (!is_array($decoded)) {
            return array();
        }
        return $this->normaliseMerchantTerms($decoded);
    }

    /**
     * Normalise a raw term list into ascending, unique, positive int day counts.
     * The is_numeric guard drops malformed elements (nested arrays, bools, null)
     * rather than intval'ing them into a phantom "1 day" term.
     *
     * @param mixed $terms
     * @return int[]
     */
    private function normaliseMerchantTerms($terms)
    {
        $days = array();
        foreach ((array) $terms as $t) {
            if (!is_numeric($t)) {
                continue;
            }
            $d = (int) $t;
            if ($d > 0) {
                $days[$d] = $d;
            }
        }
        $days = array_values($days);
        sort($days);
        return $days;
    }

    /**
     * Drop the cached merchant term list. Called when the merchant identity
     * changes (new API key / merchant id) - serve-stale caching must never
     * serve the old merchant's terms under a new identity (TWO-24813).
     */
    public function invalidateMerchantAvailableTerms()
    {
        Configuration::updateValue(self::CONFIG_MERCHANT_AVAILABLE_TERMS, '');
        // Clear the sibling default-term cache too: both are sourced from the
        // same merchant record, so an identity change must drop both together
        // or the new merchant would inherit the old merchant's default term
        // (TWO-24859). Shared timestamp reset last, forcing a re-fetch.
        Configuration::updateValue(self::CONFIG_MERCHANT_DUE_IN_DAYS, 0);
        Configuration::updateValue(self::CONFIG_MERCHANT_AVAILABLE_TERMS_TS, 0);
    }

    /**
     * The offerable term source set (before the admin narrows it): the backend's
     * `available_terms` when resolved, else the historical hardcoded option list.
     * The fallback preserves pre-feature behaviour on a cold cache (fresh install
     * / API blip) rather than blanking the term UI and checkout - the serve-stale
     * degrade posture (TWO-24813).
     *
     * @param bool $refresh Allow a TTL-gated backend fetch.
     * @return int[]
     */
    private function getOfferableTermSource($refresh = false)
    {
        $backend = $this->getMerchantAvailableTerms($refresh);
        if (!empty($backend)) {
            return $backend;
        }
        return array_map('intval', self::PAYMENT_TERMS_OPTIONS);
    }

    /**
     * Available payment terms offered at checkout (ascending). THE runtime seam:
     * the backend's offerable set (GET /v1/merchant `available_terms`), narrowed
     * by the merchant's admin checkbox subset, then constrained by the term type
     * (EOM only supports 30/45/60). A term the backend has withdrawn drops out
     * even while the admin box is still ticked (TWO-24813). Cache-only - never
     * blocks on HTTP; the sanctioned refresh points prime the cache.
     *
     * @return int[] Array of available payment term durations (e.g., [30, 45, 60])
     */
    /* ===================================================================
     * Offset pricing fee (buyer surcharge) — TWO-24752 / TWO-24893.
     *
     * All fee decisioning lives here in PHP; templates/JS only render the
     * results (ps_checkout compatibility, TWO-24770). Arithmetic is done
     * server-side by POST /v1/pricing/order/fee — the plugin relays a
     * buyer_fee_share block via TwoSurchargeCalculator, mirroring Magento's
     * Service/Order/SurchargeCalculator and the WooCommerce plugin's
     * WC_Twoinc_Payment_Terms::build_buyer_fee_share.
     * =================================================================== */

    /** @var array request-scoped fee-quote cache keyed by term|gross|country|currency */
    protected $twoFeeCache = array();

    /**
     * Read a brand-config value (brands/two.php), cached per request. Returns
     * null for unknown keys. A minimal seam pending the full brand-config
     * foundation (TWO-24746); mirrors the WooCommerce plugin's WC_Twoinc_Brand.
     *
     * @param string $key
     * @return mixed
     */
    public function getTwoBrandConfig($key)
    {
        static $brand = null;
        if ($brand === null) {
            $file = dirname(__FILE__) . '/brands/two.php';
            $brand = is_file($file) ? (array) (require $file) : array();
        }

        return array_key_exists($key, $brand) ? $brand[$key] : null;
    }

    /**
     * Resolve the surcharge settings from module Configuration into the shape
     * TwoSurchargeCalculator expects. Mirrors the WooCommerce plugin's
     * get_surcharge_settings.
     *
     * @return array
     */
    public function getTwoSurchargeSettings()
    {
        $type = TwoSurchargeCalculator::normalizeType(Configuration::get('PS_TWO_SURCHARGE_TYPE'));

        $grid = array();
        foreach ($this->getAvailablePaymentTerms() as $days) {
            $days = (int) $days;
            $grid[$days] = array(
                'percentage' => (float) Configuration::get('PS_TWO_SURCHARGE_PCT_' . $days),
                'fixed' => (float) Configuration::get('PS_TWO_SURCHARGE_FIXED_' . $days),
                'limit' => (float) Configuration::get('PS_TWO_SURCHARGE_CAP_' . $days),
            );
        }

        $step = (float) Configuration::get('PS_TWO_SURCHARGE_ROUNDING_STEP');

        return array(
            'type' => $type,
            'enabled' => $type !== 'none',
            'differential' => (bool) Configuration::get('PS_TWO_SURCHARGE_DIFFERENTIAL'),
            'grid' => $grid,
            'rounding_basis' => (string) Configuration::get('PS_TWO_SURCHARGE_ROUNDING_BASIS'),
            'rounding_step' => $step > 0 ? $step : null,
        );
    }

    /**
     * Build the buyer_fee_share block for one term (or null when no surcharge
     * is configured), from module Configuration.
     *
     * @param int $days
     * @return array|null
     */
    public function buildTwoBuyerFeeShare($days)
    {
        $settings = $this->getTwoSurchargeSettings();
        $isEom = Configuration::get('PS_TWO_PAYMENT_TERM_TYPE') === 'EOM';
        $default = $this->getDefaultPaymentTerm();

        return TwoSurchargeCalculator::buildBuyerFeeShare($settings, (int) $days, $default, $isEom);
    }

    /**
     * Quote the buyer's fee share for one term via POST /v1/pricing/order/fee.
     * Fail-soft: returns null on any error (the fee line is simply omitted and
     * checkout is never blocked on a quote, TWO-24752). Request-scoped cache.
     *
     * @param int    $days
     * @param float  $gross_amount fee basis (product + shipping gross)
     * @param string $buyer_country ISO-2 code
     * @param string $currency_iso  store currency
     * @return array|null {buyer_fee_share, total_fee_tax_rate, currency}
     */
    public function fetchTwoTermFee($days, $gross_amount, $buyer_country, $currency_iso)
    {
        $days = (int) $days;
        $gross_amount = (float) $gross_amount;
        $cacheKey = $days . '|' . $this->getTwoRoundAmount($gross_amount) . '|' . $buyer_country . '|' . $currency_iso;
        if (array_key_exists($cacheKey, $this->twoFeeCache)) {
            return $this->twoFeeCache[$cacheKey];
        }

        $share = $this->buildTwoBuyerFeeShare($days);
        if ($share === null || $gross_amount <= 0) {
            return $this->twoFeeCache[$cacheKey] = null;
        }

        $isEom = Configuration::get('PS_TWO_PAYMENT_TERM_TYPE') === 'EOM';
        $payload = array(
            'currency' => (string) $currency_iso,
            'gross_amount' => $this->getTwoRoundAmount($gross_amount),
            'buyer_country_code' => (string) $buyer_country,
            // Hardcoded false for parity with Magento/WooCommerce — no admin
            // recourse-pricing config on any plugin yet.
            'approved_on_recourse' => false,
            'order_terms' => TwoSurchargeCalculator::buildTermsBlock($days, $isEom),
            'buyer_fee_share' => $share,
        );

        // Tight timeout: this sits on the checkout/order-build path and must
        // never stall checkout on a slow pricing call.
        $response = $this->setTwoPaymentRequest('/v1/pricing/order/fee', $payload, 'POST', array(), self::API_TIMEOUT_STATE_CHECK);
        if (!is_array($response)) {
            return $this->twoFeeCache[$cacheKey] = null;
        }
        $status = isset($response['http_status']) ? (int) $response['http_status'] : 0;
        if ($status < 200 || $status >= 300) {
            return $this->twoFeeCache[$cacheKey] = null;
        }
        if (!isset($response['buyer_fee_share'])) {
            return $this->twoFeeCache[$cacheKey] = null;
        }

        // Guard against a reinterpreted currency — applying a figure quoted in
        // a different currency would need FX the plugin does not do.
        $respCurrency = isset($response['currency']) ? (string) $response['currency'] : (string) $currency_iso;
        if ($currency_iso !== '' && $respCurrency !== (string) $currency_iso) {
            return $this->twoFeeCache[$cacheKey] = null;
        }

        return $this->twoFeeCache[$cacheKey] = array(
            'buyer_fee_share' => (string) $response['buyer_fee_share'],
            'total_fee_tax_rate' => isset($response['total_fee_tax_rate']) ? (string) $response['total_fee_tax_rate'] : null,
            'currency' => $respCurrency,
        );
    }

    /**
     * Normalise the API's fee tax rate to the plugin's decimal-fraction
     * convention (e.g. 0.25 for 25%). Guards against a percentage form (25) by
     * scaling anything > 1. Never hard-codes zero — the API rate passes
     * through (TWO-24752).
     *
     * @param mixed $rate
     * @return float
     */
    private function normalizeTwoFeeTaxRate($rate)
    {
        if ($rate === null || $rate === '') {
            return 0.0;
        }
        $rate = (float) $rate;
        if ($rate <= 0) {
            return 0.0;
        }
        if ($rate > 1.0) {
            $rate = $rate / 100.0;
        }

        return $rate;
    }

    /**
     * Resolve the buyer's country ISO from the cart's invoice address.
     *
     * @param Cart $cart
     * @return string
     */
    private function resolveTwoBuyerCountryIso($cart)
    {
        if (!Validate::isLoadedObject($cart)) {
            return '';
        }
        $address = new Address((int) $cart->id_address_invoice);
        if (Validate::isLoadedObject($address) && (int) $address->id_country > 0) {
            $iso = Country::getIsoById((int) $address->id_country);
            if (!empty($iso)) {
                return (string) $iso;
            }
        }

        return '';
    }

    /**
     * Build the buyer-surcharge line item for the Two order payload, or null
     * when no surcharge is configured / the quote fails / the fee is zero.
     * The line's tax_rate carries the API's total_fee_tax_rate (never a
     * hard-coded zero, TWO-24752); net/tax/gross satisfy the Two line-item
     * formulas so validateTwoLineItems accepts it.
     *
     * @param Cart     $cart
     * @param float    $gross_basis product + shipping gross (fee basis)
     * @param int|null $paymentTermDays explicit term (update/admin context has no
     *                 buyer cookie); null falls back to the selected term.
     * @return array|null
     */
    public function buildTwoSurchargeLineItemForCart($cart, $gross_basis, $paymentTermDays = null)
    {
        $settings = $this->getTwoSurchargeSettings();
        if (empty($settings['enabled'])) {
            return null;
        }

        // In the create/intent (buyer) path the term comes from the buyer's
        // cookie; in the update path (admin edit / tracking webhook) there is no
        // cookie, so the caller passes the persisted order term instead —
        // otherwise the fee would be recomputed for the default term and the
        // update gross would diverge from the created-order gross. TWO-24752.
        $days = $paymentTermDays !== null ? (int) $paymentTermDays : $this->getSelectedPaymentTerm();
        $currencyIso = '';
        $currency = new Currency((int) $cart->id_currency);
        if (Validate::isLoadedObject($currency)) {
            $currencyIso = (string) $currency->iso_code;
        }
        $buyerCountry = $this->resolveTwoBuyerCountryIso($cart);

        $fee = $this->fetchTwoTermFee($days, (float) $gross_basis, $buyerCountry, $currencyIso);
        if ($fee === null) {
            return null;
        }

        $net = round((float) $fee['buyer_fee_share'], 2);
        if ($net <= 0) {
            return null;
        }
        // Compute tax from the SAME rate string that is sent (formatTwoTaxRate
        // snaps to TAX_RATE_PRECISION dp). Using the full-precision rate to
        // compute tax while sending a rounded rate makes the line fail
        // validateTwoLineItems (tax_amount vs net*sent_rate) for any rate that
        // needs more than TAX_RATE_PRECISION decimals, silently dropping the
        // whole surcharge line. Snapping first mirrors the product-line
        // convention (snapped_product_rate). TWO-24752.
        $taxRate = $this->normalizeTwoFeeTaxRate($fee['total_fee_tax_rate']);
        $taxRateString = $this->formatTwoTaxRate($taxRate);
        $sentRate = (float) $taxRateString;
        $tax = round($net * $sentRate, 2);
        $gross = round($net + $tax, 2);
        $label = $this->getTwoSurchargeLineLabel($days);

        return array(
            'name' => $label,
            'description' => Tools::substr(strip_tags($label), 0, 255),
            'gross_amount' => (string) $this->getTwoRoundAmount($gross),
            'net_amount' => (string) $this->getTwoRoundAmount($net),
            'discount_amount' => '0.00',
            'tax_amount' => (string) $this->getTwoRoundAmount($tax),
            'tax_class_name' => 'VAT ' . $this->getTwoRoundAmount(round($sentRate * 100, 2)) . '%',
            'tax_rate' => $taxRateString,
            'unit_price' => (string) $this->getTwoRoundAmount($net),
            'quantity' => 1,
            'quantity_unit' => 'item',
            'type' => 'SERVICE',
        );
    }

    /**
     * Buyer-facing label for the surcharge line. A merchant-set description
     * wins (with %s replaced by the term days, Magento/WooCommerce parity);
     * else the brand label; else a translated default.
     *
     * @param int $days
     * @return string
     */
    public function getTwoSurchargeLineLabel($days)
    {
        $template = trim((string) Configuration::get('PS_TWO_SURCHARGE_LINE_DESC'));
        if ($template !== '') {
            return str_replace('%s', (string) (int) $days, $template);
        }
        $brandLabel = $this->getTwoBrandConfig('fee_line_label');
        if (!empty($brandLabel)) {
            return $this->l((string) $brandLabel);
        }

        return $this->l('Service charge');
    }

    /**
     * Brand-driven rounding-step options for the admin select, formatted to a
     * canonical two-decimal string so the stored value round-trips. Mirrors the
     * WooCommerce plugin's get_rounding_step_options and Magento's RoundingStep.
     *
     * @return array<string, string>
     */
    public function getTwoRoundingStepOptions()
    {
        $options = array();
        $steps = $this->getTwoBrandConfig('rounding_steps');
        foreach (is_array($steps) ? $steps : array() as $step) {
            if (!is_numeric($step) || (float) $step <= 0) {
                continue;
            }
            $value = number_format((float) $step, 2, '.', '');
            $options[$value] = $value;
        }
        ksort($options, SORT_NUMERIC);

        return $options;
    }

    /**
     * Reset the request-scoped fee cache (tests).
     */
    public function resetTwoFeeCache()
    {
        $this->twoFeeCache = array();
    }

    /**
     * HelperForm input entries for the surcharge settings (appended to the
     * Other Settings form). Presentation only — all decisioning is in the
     * methods above.
     *
     * @return array
     */
    protected function getTwoSurchargeFormInputs()
    {
        $inputs = array();
        $inputs[] = array(
            'type' => 'select',
            'label' => $this->l('Buyer Surcharge Method'),
            'name' => 'PS_TWO_SURCHARGE_TYPE',
            'desc' => $this->l('Add an offset pricing fee to the buyer for the selected payment term. The fee amount is computed by Two; the plugin only sends the configuration.'),
            'options' => array(
                'query' => array(
                    array('id' => 'none', 'name' => $this->l('No surcharge applied')),
                    array('id' => 'percentage', 'name' => $this->l('Percentage')),
                    array('id' => 'fixed', 'name' => $this->l('Fixed fee')),
                    array('id' => 'fixed_and_percentage', 'name' => $this->l('Fixed fee and percentage')),
                ),
                'id' => 'id',
                'name' => 'name',
            ),
        );
        $inputs[] = array(
            'type' => 'select',
            'label' => $this->l('Surcharge Calculation Basis'),
            'name' => 'PS_TWO_SURCHARGE_DIFFERENTIAL',
            'desc' => $this->l('Total fee charges the configured surcharge for the chosen term. Fee difference charges only the difference versus the default payment term.'),
            // Presented as a dropdown to match Magento's Surcharge Calculation
            // Basis field (Two\Gateway\Model\Config\Source\SurchargeCalculationBasis).
            // Values keep the original 0/1 boolean semantics: 0 = total fee,
            // 1 = differential — so the stored config and downstream behaviour
            // (getTwoSurchargeSettings 'differential') are unchanged.
            'options' => array(
                'query' => array(
                    array('id' => 0, 'name' => $this->l('Total fee for selected term')),
                    array('id' => 1, 'name' => $this->l('Fee difference vs default payment term')),
                ),
                'id' => 'id',
                'name' => 'name',
            ),
        );
        $inputs[] = array(
            'type' => 'text',
            'label' => $this->l('Surcharge Line Description'),
            'name' => 'PS_TWO_SURCHARGE_LINE_DESC',
            'desc' => $this->l('Buyer-facing label for the surcharge line. Use %s for the term length in days. Leave blank to use the brand default.'),
        );
        $inputs[] = array(
            'type' => 'select',
            'label' => $this->l('Surcharge Rounding'),
            'name' => 'PS_TWO_SURCHARGE_ROUNDING_BASIS',
            'desc' => $this->l('Snap the buyer surcharge line to a clean increment. Select None for standard two-decimal amounts.'),
            'options' => array(
                'query' => array(
                    array('id' => 'none', 'name' => $this->l('None')),
                    array('id' => 'up', 'name' => $this->l('Up')),
                    array('id' => 'down', 'name' => $this->l('Down')),
                    array('id' => 'standard', 'name' => $this->l('Standard')),
                ),
                'id' => 'id',
                'name' => 'name',
            ),
        );
        $stepQuery = array(array('id' => '', 'name' => $this->l('No rounding')));
        foreach ($this->getTwoRoundingStepOptions() as $value => $label) {
            $stepQuery[] = array('id' => $value, 'name' => $label);
        }
        $inputs[] = array(
            'type' => 'select',
            'label' => $this->l('Rounding Step'),
            'name' => 'PS_TWO_SURCHARGE_ROUNDING_STEP',
            'desc' => $this->l('Increment the surcharge is rounded to (e.g. 1 = whole units, 0.50 = nearest half). Applies only when a rounding direction is selected.'),
            'options' => array('query' => $stepQuery, 'id' => 'id', 'name' => 'name'),
        );
        $inputs[] = array(
            'type' => 'html',
            'label' => $this->l('Per-term surcharge'),
            'name' => 'PS_TWO_SURCHARGE_GRID',
            'html_content' => $this->getTwoSurchargeGridHtml(),
        );

        return $inputs;
    }

    /**
     * Build the per-term surcharge grid as an HTML table. HelperForm does NOT
     * auto-populate values for type=>'html' fields, so each input's current
     * value is read here (POSTed value, falling back to the stored config) and
     * written into the value="" attribute, htmlspecialchars-escaped. Field
     * names are IDENTICAL to the previous per-term text inputs so the existing
     * save/validation path (saveTwoSurchargeFormValues / validTwoSurchargeFormValues)
     * is unchanged.
     *
     * @return string
     */
    protected function getTwoSurchargeGridHtml()
    {
        $cell_style = 'width:110px;';
        $html = '<table class="table" style="width:auto;margin-bottom:0;">';
        $html .= '<thead><tr>'
            . '<th>' . $this->l('Term') . '</th>'
            . '<th>' . $this->l('Percentage') . '</th>'
            . '<th>' . $this->l('Fixed fee') . '</th>'
            . '<th>' . $this->l('Cap on percentage') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($this->getAvailablePaymentTerms() as $days) {
            $days = (int) $days;
            $pct_name = 'PS_TWO_SURCHARGE_PCT_' . $days;
            $fixed_name = 'PS_TWO_SURCHARGE_FIXED_' . $days;
            $cap_name = 'PS_TWO_SURCHARGE_CAP_' . $days;

            $pct = htmlspecialchars((string) Tools::getValue($pct_name, Configuration::get($pct_name)), ENT_QUOTES, 'UTF-8');
            $fixed = htmlspecialchars((string) Tools::getValue($fixed_name, Configuration::get($fixed_name)), ENT_QUOTES, 'UTF-8');
            $cap = htmlspecialchars((string) Tools::getValue($cap_name, Configuration::get($cap_name)), ENT_QUOTES, 'UTF-8');

            $html .= '<tr>'
                . '<td style="vertical-align:middle;">' . sprintf($this->l('%d days'), $days) . '</td>'
                . '<td><div class="input-group" style="' . $cell_style . '">'
                . '<input type="text" class="form-control" name="' . $pct_name . '" value="' . $pct . '">'
                . '<span class="input-group-addon">%</span></div></td>'
                . '<td><input type="text" class="form-control" style="' . $cell_style . '" name="' . $fixed_name . '" value="' . $fixed . '"></td>'
                . '<td><input type="text" class="form-control" style="' . $cell_style . '" name="' . $cap_name . '" value="' . $cap . '"></td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Current values for the surcharge form fields.
     *
     * @return array
     */
    protected function getTwoSurchargeFormValues()
    {
        $values = array(
            'PS_TWO_SURCHARGE_TYPE' => Tools::getValue('PS_TWO_SURCHARGE_TYPE', Configuration::get('PS_TWO_SURCHARGE_TYPE')),
            'PS_TWO_SURCHARGE_DIFFERENTIAL' => Tools::getValue('PS_TWO_SURCHARGE_DIFFERENTIAL', Configuration::get('PS_TWO_SURCHARGE_DIFFERENTIAL')),
            'PS_TWO_SURCHARGE_LINE_DESC' => Tools::getValue('PS_TWO_SURCHARGE_LINE_DESC', Configuration::get('PS_TWO_SURCHARGE_LINE_DESC')),
            'PS_TWO_SURCHARGE_ROUNDING_BASIS' => Tools::getValue('PS_TWO_SURCHARGE_ROUNDING_BASIS', Configuration::get('PS_TWO_SURCHARGE_ROUNDING_BASIS')),
            'PS_TWO_SURCHARGE_ROUNDING_STEP' => Tools::getValue('PS_TWO_SURCHARGE_ROUNDING_STEP', Configuration::get('PS_TWO_SURCHARGE_ROUNDING_STEP')),
        );
        foreach ($this->getAvailablePaymentTerms() as $days) {
            $days = (int) $days;
            foreach (array('PCT', 'FIXED', 'CAP') as $suffix) {
                $name = 'PS_TWO_SURCHARGE_' . $suffix . '_' . $days;
                $values[$name] = Tools::getValue($name, Configuration::get($name));
            }
        }

        return $values;
    }

    /**
     * Validate the surcharge form: enforce rounding-step membership and
     * non-negative numeric grid values. Appends to $this->errors.
     */
    protected function validTwoSurchargeFormValues()
    {
        $type = TwoSurchargeCalculator::normalizeType(Tools::getValue('PS_TWO_SURCHARGE_TYPE'));
        if ($type === 'none') {
            return;
        }
        $step = trim((string) Tools::getValue('PS_TWO_SURCHARGE_ROUNDING_STEP'));
        if ($step !== '' && !array_key_exists($step, $this->getTwoRoundingStepOptions())) {
            $this->errors[] = $this->l('Rounding step must be one of the offered values.');
        }
        foreach ($this->getAvailablePaymentTerms() as $days) {
            $days = (int) $days;
            foreach (array('PCT', 'FIXED', 'CAP') as $suffix) {
                $raw = Tools::getValue('PS_TWO_SURCHARGE_' . $suffix . '_' . $days);
                if ($raw !== false && $raw !== '' && (!is_numeric($raw) || (float) $raw < 0)) {
                    $this->errors[] = $this->l('Surcharge values must be non-negative numbers.');

                    return;
                }
            }
        }
    }

    /**
     * Persist the surcharge form values, coercing to safe stored forms.
     */
    protected function saveTwoSurchargeFormValues()
    {
        Configuration::updateValue('PS_TWO_SURCHARGE_TYPE', TwoSurchargeCalculator::normalizeType(Tools::getValue('PS_TWO_SURCHARGE_TYPE')));
        Configuration::updateValue('PS_TWO_SURCHARGE_DIFFERENTIAL', (int) Tools::getValue('PS_TWO_SURCHARGE_DIFFERENTIAL', 0));
        Configuration::updateValue('PS_TWO_SURCHARGE_LINE_DESC', (string) Tools::getValue('PS_TWO_SURCHARGE_LINE_DESC', ''));

        $basis = (string) Tools::getValue('PS_TWO_SURCHARGE_ROUNDING_BASIS', 'none');
        if ($basis !== 'none' && !array_key_exists($basis, TwoSurchargeCalculator::ROUNDING_BASIS_TO_API)) {
            $basis = 'none';
        }
        Configuration::updateValue('PS_TWO_SURCHARGE_ROUNDING_BASIS', $basis);

        $step = trim((string) Tools::getValue('PS_TWO_SURCHARGE_ROUNDING_STEP', ''));
        if ($step !== '' && !array_key_exists($step, $this->getTwoRoundingStepOptions())) {
            $step = '';
        }
        Configuration::updateValue('PS_TWO_SURCHARGE_ROUNDING_STEP', $step);

        foreach ($this->getAvailablePaymentTerms() as $days) {
            $days = (int) $days;
            foreach (array('PCT', 'FIXED', 'CAP') as $suffix) {
                $name = 'PS_TWO_SURCHARGE_' . $suffix . '_' . $days;
                $raw = Tools::getValue($name, '');
                Configuration::updateValue($name, is_numeric($raw) ? (string) (float) $raw : '');
            }
        }
    }

    public function getAvailablePaymentTerms()
    {
        $term_type = Configuration::get('PS_TWO_PAYMENT_TERM_TYPE');

        // Source set the admin narrows FROM (backend list, else hardcoded).
        $source = $this->getOfferableTermSource(false);

        // EOM is only offerable for a fixed subset; intersect the source with it.
        if ($term_type === 'EOM') {
            $source = array_values(array_intersect($source, self::EOM_PAYMENT_TERMS_OPTIONS));
        }

        $available_terms = array();
        foreach ($source as $term) {
            if (Configuration::get('PS_TWO_PAYMENT_TERMS_' . (int) $term)) {
                $available_terms[] = (int) $term;
            }
        }

        // If nothing is configured/offerable, default to DEFAULT_PAYMENT_TERM_DAYS
        if (empty($available_terms)) {
            $available_terms = array(self::DEFAULT_PAYMENT_TERM_DAYS);
        }

        sort($available_terms); // Ensure they're in ascending order
        return $available_terms;
    }

    /**
     * The merchant's default invoice payment term (the API `due_in_days` field
     * on GET /v1/merchant), in net days, or null when it is unset or unresolved.
     *
     * CACHE-ONLY - never blocks on HTTP. The value is primed by the SAME fetch
     * as the available_terms list (see getMerchantAvailableTerms): the sanctioned
     * refresh points (checkout media render, admin config render) call
     * getMerchantAvailableTerms(true), which populates both caches from one wire
     * call. On a cold cache this returns null and getDefaultPaymentTerm() falls
     * back to the historical 30-day default - the same serve-stale degrade
     * posture the available_terms seam uses (TWO-24813 / TWO-24859).
     *
     * `due_in_days` is NOT guaranteed to be a member of the offered term set -
     * callers must honour it only when it is an available term (see
     * getDefaultPaymentTerm). A cached 0 means "unset" (all real terms are > 0).
     *
     * @return int|null
     */
    public function getMerchantDueInDays()
    {
        $cached = (int) Configuration::get(self::CONFIG_MERCHANT_DUE_IN_DAYS);
        return $cached > 0 ? $cached : null;
    }

    /**
     * Get the default payment term.
     *
     * Preference order when more than one term is offered:
     *   1. the merchant's API default term (due_in_days) when it is offered;
     *   2. the historical DEFAULT_PAYMENT_TERM_DAYS (30) when it is offered;
     *   3. the lowest offered term.
     * A single offered term always wins outright.
     *
     * @return int Default payment term in days
     */
    public function getDefaultPaymentTerm()
    {
        $available_terms = $this->getAvailablePaymentTerms();

        // If only one term is available, use it as default
        if (count($available_terms) === 1) {
            return $available_terms[0];
        }

        // Prefer the merchant's API default term (due_in_days) when it is one
        // of the offered terms, so a freshly-installed or never-tuned plugin
        // lands on the merchant's real default out of the box - matching
        // magento-plugin and woocommerce-plugin (TWO-24859). Read-only and
        // non-destructive: it never overwrites the merchant's own term config,
        // it only chooses among terms that are already offered.
        $api_default = $this->getMerchantDueInDays();
        if ($api_default !== null && in_array($api_default, $available_terms, true)) {
            return $api_default;
        }

        // If DEFAULT_PAYMENT_TERM_DAYS is available, use it as default
        if (in_array(self::DEFAULT_PAYMENT_TERM_DAYS, $available_terms)) {
            return self::DEFAULT_PAYMENT_TERM_DAYS;
        }

        // Otherwise, use the first available term
        return !empty($available_terms) ? $available_terms[0] : self::DEFAULT_PAYMENT_TERM_DAYS;
    }

    /**
     * SHARED UTILITY: Restore duplicate cart for failed orders
     * Used across multiple controllers to maintain consistency
     */
    public function restoreDuplicateCart($id_order, $id_customer)
    {
        try {
            $oldCart = new Cart(Order::getCartIdStatic($id_order, $id_customer));
            if (!Validate::isLoadedObject($oldCart)) {
                PrestaShopLogger::addLog('TwoPayment: Cannot restore cart - original cart not found for order ' . $id_order, 2);
                return false;
            }
            
            $duplication = $oldCart->duplicate();
            if (!$duplication || !isset($duplication['cart']) || !Validate::isLoadedObject($duplication['cart'])) {
                PrestaShopLogger::addLog('TwoPayment: Cart duplication failed for order ' . $id_order, 2);
                return false;
            }
            
            $this->context->cookie->id_cart = $duplication['cart']->id;
            $context = $this->context;
            $context->cart = $duplication['cart'];
            CartRule::autoAddToCart($context);
            $this->context->cookie->write();
            
            PrestaShopLogger::addLog('TwoPayment: Cart restored successfully for order ' . $id_order . ', new cart ID: ' . $duplication['cart']->id, 1);
            return true;
            
        } catch (Exception $e) {
            PrestaShopLogger::addLog('TwoPayment: Exception during cart restoration for order ' . $id_order . ': ' . $e->getMessage(), 3);
            return false;
        }
    }

    /**
     * SHARED UTILITY: Delete order completely from database
     * Used when Two API rejects order creation (non-201 response)
     * Ensures no phantom orders in PrestaShop database
     * 
     * @param int $id_order Order ID to delete
     * @return bool True on success, false on failure
     */
    public function deleteOrder($id_order)
    {
        try {
            if (!$id_order) {
                PrestaShopLogger::addLog('TwoPayment: Cannot delete order - invalid ID', 3);
                return false;
            }
            
            $order = new Order((int) $id_order);
            if (!Validate::isLoadedObject($order)) {
                PrestaShopLogger::addLog('TwoPayment: Cannot delete order ' . $id_order . ' - not found', 2);
                return false;
            }
            
            // Log order details before deletion for audit trail
            PrestaShopLogger::addLog(
                'TwoPayment: Deleting order ' . $id_order . ' - ' .
                'Customer: ' . $order->id_customer . ', ' .
                'Cart: ' . $order->id_cart . ', ' .
                'Total: ' . $order->total_paid . ', ' .
                'Status: ' . $order->current_state,
                2
            );
            
            // Delete Two payment data from our custom table
            try {
                // Use PrestaShop's delete() method for proper escaping and security
                Db::getInstance()->delete('twopayment', 'id_order = ' . (int)$id_order);
                PrestaShopLogger::addLog('TwoPayment: Deleted Two payment data for order ' . $id_order, 1);
            } catch (Exception $e) {
                PrestaShopLogger::addLog('TwoPayment: Failed to delete Two payment data for order ' . $id_order . ': ' . $e->getMessage(), 2);
            }
            
            // Use PrestaShop's native delete method (handles cascading deletes)
            // This removes: order_detail, order_history, order_carrier, order_invoice, etc.
            $delete_result = $order->delete();
            
            if ($delete_result) {
                PrestaShopLogger::addLog('TwoPayment: Successfully deleted order ' . $id_order . ' from database', 1);
                return true;
            } else {
                PrestaShopLogger::addLog('TwoPayment: Failed to delete order ' . $id_order . ' - PrestaShop delete() returned false', 3);
                return false;
            }
            
        } catch (Exception $e) {
            PrestaShopLogger::addLog('TwoPayment: Exception during order deletion for order ' . $id_order . ': ' . $e->getMessage(), 3);
            return false;
        }
    }

    /**
     * SHARED UTILITY: Change order status with proper validation
     * Used across multiple controllers to maintain consistency
     */
    public function changeOrderStatus($id_order, $id_order_status)
    {
        try {
            if (!$id_order || !$id_order_status) {
                PrestaShopLogger::addLog('TwoPayment: Invalid parameters for order status change - Order: ' . $id_order . ', Status: ' . $id_order_status, 2);
                return false;
            }
            
            $order = new Order((int) $id_order);
            if (!Validate::isLoadedObject($order)) {
                PrestaShopLogger::addLog('TwoPayment: Order not found for status change: ' . $id_order, 2);
                return false;
            }
            
            // Only change status if it's different
            if ($order->current_state == (int) $id_order_status) {
                PrestaShopLogger::addLog('TwoPayment: Order ' . $id_order . ' already in target status ' . $id_order_status, 1);
                return true;
            }
            
            $history = new OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState((int) $id_order_status, $order, true);
            $history->addWithemail(true);
            
            PrestaShopLogger::addLog('TwoPayment: Order status changed successfully for order ' . $id_order . ' to status ' . $id_order_status, 1);
            return true;
            
        } catch (Exception $e) {
            PrestaShopLogger::addLog('TwoPayment: Exception during order status change for order ' . $id_order . ': ' . $e->getMessage(), 3);
            return false;
        }
    }

    /**
     * Get the selected payment term for the current order
     * @return int Selected payment term in days
     */
    public function getSelectedPaymentTerm()
    {
        $available_terms = $this->getAvailablePaymentTerms();
        $default_term = $this->getDefaultPaymentTerm();
        
        // Try to get from PrestaShop context cookie first
        $cookie_term_raw = isset($this->context->cookie->two_payment_term)
            ? (string)$this->context->cookie->two_payment_term
            : '';
        $selected_term = (int)$cookie_term_raw;
        
        // If not found, try to get from browser cookies
        if (!$selected_term && isset($_COOKIE['two_payment_term'])) {
            $selected_term = (int)$_COOKIE['two_payment_term'];
        }
        
        PrestaShopLogger::addLog('TwoPayment: Getting payment term - Context Cookie: ' . $cookie_term_raw . ', Browser Cookie: ' . (isset($_COOKIE['two_payment_term']) ? $_COOKIE['two_payment_term'] : 'not set') . ', Selected: ' . $selected_term . ', Available: ' . implode(',', $available_terms) . ', Default: ' . $default_term, 1);
        
        if ($selected_term && in_array($selected_term, $available_terms)) {
            PrestaShopLogger::addLog('TwoPayment: Using selected payment term: ' . $selected_term . ' days', 1);
            return $selected_term;
        }
        
        // Fallback to default payment term
        PrestaShopLogger::addLog('TwoPayment: Using default payment term: ' . $default_term . ' days', 1);
        return $default_term;
    }
    
    /**
     * Build payment terms payload for Two API
     * Adds duration_days_calculated_from for EOM terms
     * 
     * @return array Terms payload
     * 
     * PHP COMPATIBILITY: PHP 7.1+ compatible (no spread operators)
     */
    public function buildTermsPayload()
    {
        $term_type = Configuration::get('PS_TWO_PAYMENT_TERM_TYPE');
        $duration_days = $this->getSelectedPaymentTerm();
        
        // Base terms structure
        $terms = array(
            'type' => 'NET_TERMS',
            'duration_days' => $duration_days
        );
        
        // Add duration_days_calculated_from for EOM terms
        if ($term_type === 'EOM') {
            $terms['duration_days_calculated_from'] = 'END_OF_MONTH';
        }
        
        return $terms;
    }

    /**
     * Resolve local stored payment terms from a Two order response.
     * Supports STANDARD and EOM only; unsupported schemes fall back to STANDARD.
     *
     * @param array $order_response Two API response payload
     * @param string $fallback_days Existing/fallback duration days
     * @param string $fallback_type Existing/fallback term type
     * @return array{two_day_on_invoice:string,two_payment_term_type:string}
     */
    public function resolveTwoPaymentTermsFromOrderResponse($order_response, $fallback_days = '', $fallback_type = 'STANDARD')
    {
        $resolved_days = trim((string)$fallback_days);

        $resolved_type = strtoupper(trim((string)$fallback_type));
        if ($resolved_type !== 'EOM') {
            $resolved_type = 'STANDARD';
        }

        if (!is_array($order_response)) {
            return array(
                'two_day_on_invoice' => $resolved_days,
                'two_payment_term_type' => $resolved_type,
            );
        }

        $terms_container = $order_response;
        if (
            (!isset($terms_container['terms']) || !is_array($terms_container['terms'])) &&
            isset($order_response['data']) &&
            is_array($order_response['data'])
        ) {
            $terms_container = $order_response['data'];
        }

        if (!isset($terms_container['terms']) || !is_array($terms_container['terms'])) {
            return array(
                'two_day_on_invoice' => $resolved_days,
                'two_payment_term_type' => $resolved_type,
            );
        }

        $terms = $terms_container['terms'];
        $terms_type = isset($terms['type']) ? strtoupper(trim((string)$terms['type'])) : '';
        if (!Tools::isEmpty($terms_type) && $terms_type !== 'NET_TERMS') {
            PrestaShopLogger::addLog(
                'TwoPayment: Unsupported terms.type "' . $terms_type . '" returned by Two API. Keeping fallback local term values.',
                2
            );
            return array(
                'two_day_on_invoice' => $resolved_days,
                'two_payment_term_type' => $resolved_type,
            );
        }

        if (isset($terms['duration_days'])) {
            $duration_days = (int)$terms['duration_days'];
            if ($duration_days > 0) {
                $resolved_days = (string)$duration_days;
            }
        }

        $calculation_scheme = isset($terms['duration_days_calculated_from'])
            ? strtoupper(trim((string)$terms['duration_days_calculated_from']))
            : '';

        if ($calculation_scheme === 'END_OF_MONTH') {
            $resolved_type = 'EOM';
        } elseif (!Tools::isEmpty($calculation_scheme)) {
            // Plugin intentionally supports STANDARD and EOM only.
            $resolved_type = 'STANDARD';
            PrestaShopLogger::addLog(
                'TwoPayment: Unsupported duration_days_calculated_from "' . $calculation_scheme . '" returned by Two API. Storing as STANDARD.',
                2
            );
        } else {
            $resolved_type = 'STANDARD';
        }

        return array(
            'two_day_on_invoice' => $resolved_days,
            'two_payment_term_type' => $resolved_type,
        );
    }

    public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
    {
        $request_timeout = $timeout !== null ? max(1, (int)$timeout) : self::API_TIMEOUT_LONG;
        if ($method == "POST" || $method == "PUT") {
            $url = sprintf('%s%s', $this->getTwoCheckoutHostUrl(), $endpoint);
            $url = $url . '?client=PS&client_v=' . $this->version;
            $params = empty($payload) ? '' : json_encode($payload);
            $headers = $this->getTwoRequestHeaders($endpoint, $additional_headers);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $request_timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::API_CONNECT_TIMEOUT);
            
            // SSL VERIFICATION - Secure by default
            $this->configureSslVerification($ch);
            
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            
            $response_body = curl_exec($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            // Handle SSL/connection errors
            if ($response_body === false || !empty($curl_error)) {
                PrestaShopLogger::addLog(
                    'TwoPayment: cURL error - ' . $curl_error . 
                    ' (URL: ' . $url . ', Endpoint: ' . $endpoint . ')',
                    3
                );
                
                return [
                    'http_status' => 0,
                    'data' => [
                        'error' => $this->l('Connection error'),
                        'error_message' => 'Unable to connect to Two API. Please check your server configuration.',
                        'curl_error' => $curl_error
                    ],
                    'error' => 'Connection error',
                    'error_message' => 'Unable to connect to Two API'
                ];
            }
            
            $response_data = json_decode($response_body, true);
            
            // Return array with HTTP status and response data for proper error handling
            // BACKWARD COMPATIBILITY: Merge data into root for existing code
            return array_merge([
                'http_status' => (int)$http_status,
                'data' => $response_data,
            ], is_array($response_data) ? $response_data : []);
        } else {
            $url = sprintf('%s%s', $this->getTwoCheckoutHostUrl(), $endpoint);
            $url = $url . '?client=PS&client_v=' . $this->version;
            $headers = $this->getTwoRequestHeaders($endpoint, $additional_headers);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $request_timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::API_CONNECT_TIMEOUT);
            
            // SSL VERIFICATION - Secure by default
            $this->configureSslVerification($ch);
            
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            
            $response_body = curl_exec($ch);
            $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            // Handle SSL/connection errors
            if ($response_body === false || !empty($curl_error)) {
                PrestaShopLogger::addLog(
                    'TwoPayment: cURL error - ' . $curl_error . 
                    ' (URL: ' . $url . ', Endpoint: ' . $endpoint . ')',
                    3
                );
                
                return [
                    'http_status' => 0,
                    'data' => [
                        'error' => 'Connection error',
                        'error_message' => 'Unable to connect to Two API. Please check your server configuration.',
                        'curl_error' => $curl_error
                    ],
                    'error' => 'Connection error',
                    'error_message' => 'Unable to connect to Two API'
                ];
            }
            
            $response_data = json_decode($response_body, true);
            
            // Return array with HTTP status and response data for proper error handling
            // BACKWARD COMPATIBILITY: Merge data into root for existing code
            return array_merge([
                'http_status' => (int)$http_status,
                'data' => $response_data,
            ], is_array($response_data) ? $response_data : []);
        }
    }

    /**
     * Build outbound request headers for Two API calls.
     * Security policy: never attach X-API-Key to order intent calls.
     *
     * @param string $endpoint
     * @param array $additional_headers
     * @return array
     */
    public function getTwoRequestHeaders($endpoint, $additional_headers = [])
    {
        $headers = [
            'Content-Type: application/json; charset=utf-8',
        ];

        $includeApiKey = $this->shouldAttachTwoApiKey($endpoint);
        if ($includeApiKey && !Tools::isEmpty($this->api_key)) {
            $headers[] = 'X-API-Key:' . $this->api_key;
        }

        if (!empty($additional_headers) && is_array($additional_headers)) {
            foreach ($additional_headers as $header) {
                if (!is_string($header) || Tools::isEmpty(trim($header))) {
                    continue;
                }

                // Hard block accidental auth header leakage on order-intent path.
                if (!$includeApiKey) {
                    $normalizedHeader = strtolower(trim($header));
                    if (
                        strpos($normalizedHeader, 'x-api-key:') === 0 ||
                        strpos($normalizedHeader, 'authorization:') === 0 ||
                        strpos($normalizedHeader, 'proxy-authorization:') === 0
                    ) {
                        continue;
                    }
                }

                $headers[] = $header;
            }
        }

        return $headers;
    }

    /**
     * Determine if API key auth should be attached for a given endpoint.
     *
     * @param string $endpoint
     * @return bool
     */
    private function shouldAttachTwoApiKey($endpoint)
    {
        $normalized = strtolower(trim((string)$endpoint));
        if (Tools::isEmpty($normalized)) {
            return true;
        }

        if (strpos($normalized, 'http://') === 0 || strpos($normalized, 'https://') === 0) {
            $path = parse_url($normalized, PHP_URL_PATH);
            if (is_string($path)) {
                $normalized = strtolower($path);
            }
        } else {
            $query_pos = strpos($normalized, '?');
            if ($query_pos !== false) {
                $normalized = substr($normalized, 0, $query_pos);
            }
        }

        if ($normalized === '/v1/order_intent' || strpos($normalized, '/v1/order_intent/') === 0) {
            return false;
        }

        return true;
    }

    /**
     * Configure SSL verification for cURL requests
     * Secure by default, with fallback for corporate networks
     * 
     * @param resource|CurlHandle $ch cURL handle
     * @return void
     */
    private function configureSslVerification($ch)
    {
        // Check if SSL verification is disabled via configuration (for corporate networks)
        $disable_ssl_verify = (bool)Configuration::get('PS_TWO_DISABLE_SSL_VERIFY', false);
        $environment = (string)Configuration::get('PS_TWO_ENVIRONMENT', 'development');
        
        if ($disable_ssl_verify) {
            if ($environment === 'production') {
                // Production hardening: never allow insecure TLS in live traffic.
                PrestaShopLogger::addLog(
                    'TwoPayment: SSL verification disable flag ignored in production. Enforcing secure TLS verification.',
                    3
                );
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                $ca_bundle = $this->findCaBundle();
                if ($ca_bundle) {
                    curl_setopt($ch, CURLOPT_CAINFO, $ca_bundle);
                }
                return;
            }

            // Only if explicitly configured (corporate networks with custom certificates)
            PrestaShopLogger::addLog(
                'TwoPayment: SSL verification disabled by configuration (security risk - corporate networks only)',
                2
            );
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        } else {
            // Enable SSL verification (secure by default)
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            // Try to find CA certificate bundle
            $ca_bundle = $this->findCaBundle();
            if ($ca_bundle) {
                curl_setopt($ch, CURLOPT_CAINFO, $ca_bundle);
            }
        }
    }
    
    /**
     * Find CA certificate bundle for SSL verification
     * Checks common system locations for CA certificates
     * 
     * @return string|null Path to CA bundle or null if not found
     */
    private function findCaBundle()
    {
        $ca_locations = [
            _PS_CACHE_DIR_ . 'ca-bundle.crt',
            '/etc/ssl/certs/ca-certificates.crt',  // Debian/Ubuntu
            '/etc/pki/tls/certs/ca-bundle.crt',    // CentOS/RHEL
            '/usr/local/etc/openssl/cert.pem',      // macOS Homebrew
            '/etc/ssl/cert.pem',                    // Alpine Linux
            '/usr/share/ssl/certs/ca-bundle.crt',   // Some Linux distributions
            '/opt/local/share/curl/curl-ca-bundle.crt', // macOS MacPorts
        ];
        
        foreach ($ca_locations as $location) {
            if (file_exists($location) && is_readable($location)) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Using CA bundle: ' . $location,
                    1
                );
                return $location;
            }
        }
        
        // Log warning if no CA bundle found (but still try with system defaults)
        PrestaShopLogger::addLog(
            'TwoPayment: No CA bundle found in common locations. Using system default CA certificates.',
            2
        );
        
        return null;
    }

    public function checkTwoStartsWithString($string, $startString)
    {
        $len = Tools::strlen($startString);
        return (Tools::substr($string, 0, $len) === $startString);
    }

    public function getTwoErrorMessage($body)
    {
        if (!$body) {
            return $this->l('Something went wrong please contact store owner.');
        }

        if (is_string($body)) {
            // ENHANCED: Parse validation errors and return user-friendly messages
            $friendly_message = $this->parseValidationErrorToFriendlyMessage($body);
            if ($friendly_message) {
                return $friendly_message;
            }
            return $body;
        }

        if (!is_array($body)) {
            return null;
        }

        $http_status = isset($body['http_status']) ? (int)$body['http_status'] : 0;
        $is_http_error = $http_status >= self::HTTP_STATUS_BAD_REQUEST;
        $candidates = array($body);
        if (isset($body['data']) && is_array($body['data'])) {
            $candidates[] = $body['data'];
        }

        foreach ($candidates as $candidate) {
            $has_explicit_error_keys = isset($candidate['error']) ||
                isset($candidate['error_message']) ||
                isset($candidate['error_details']) ||
                isset($candidate['error_code']);

            if (isset($candidate['response']['code']) && $candidate['response'] && $candidate['response']['code'] && $candidate['response']['code'] >= self::HTTP_STATUS_BAD_REQUEST) {
                return sprintf($this->l('Two response code %d'), $candidate['response']['code']);
            }

            if (isset($candidate['error_details']) && $candidate['error_details']) {
                $friendly_message = $this->parseValidationErrorToFriendlyMessage($candidate['error_details']);
                if ($friendly_message) {
                    return $friendly_message;
                }
                return (string)$candidate['error_details'];
            }

            if (isset($candidate['error_code']) && $candidate['error_code']) {
                if (isset($candidate['error_message'])) {
                    $friendly_message = $this->parseValidationErrorToFriendlyMessage($candidate['error_message']);
                    if ($friendly_message) {
                        return $friendly_message;
                    }
                }
                if (isset($candidate['error_message']) && !Tools::isEmpty($candidate['error_message'])) {
                    return (string)$candidate['error_message'];
                }
                if (isset($candidate['message']) && !Tools::isEmpty($candidate['message'])) {
                    return (string)$candidate['message'];
                }
            }

            if (isset($candidate['error_message']) && !Tools::isEmpty($candidate['error_message'])) {
                $friendly_message = $this->parseValidationErrorToFriendlyMessage($candidate['error_message']);
                if ($friendly_message) {
                    return $friendly_message;
                }
                return (string)$candidate['error_message'];
            }

            if (($is_http_error || $has_explicit_error_keys) && isset($candidate['message']) && !Tools::isEmpty($candidate['message'])) {
                return (string)$candidate['message'];
            }

            if (($is_http_error || $has_explicit_error_keys) && isset($candidate['detail']) && !Tools::isEmpty($candidate['detail'])) {
                return (string)$candidate['detail'];
            }

            if (isset($candidate['error']) && is_scalar($candidate['error']) && !Tools::isEmpty($candidate['error'])) {
                $friendly_message = $this->parseValidationErrorToFriendlyMessage((string)$candidate['error']);
                if ($friendly_message) {
                    return $friendly_message;
                }
                return (string)$candidate['error'];
            }
        }

        if ($http_status >= self::HTTP_STATUS_BAD_REQUEST) {
            return sprintf($this->l('Two response code %d'), $http_status);
        }

        return null;
    }

    /**
     * Build a redacted API response summary safe for production logs.
     *
     * @param mixed $response
     * @return array
     */
    public function buildTwoApiResponseLogSummary($response)
    {
        $summary = array(
            'http_status' => 0,
        );

        if (!is_array($response)) {
            return $summary;
        }

        if (isset($response['http_status'])) {
            $summary['http_status'] = (int)$response['http_status'];
        }
        if (isset($response['id'])) {
            $summary['two_order_id'] = (string)$response['id'];
        }
        if (isset($response['state'])) {
            $summary['two_order_state'] = (string)$response['state'];
        }
        if (isset($response['status'])) {
            $summary['two_order_status'] = (string)$response['status'];
        }
        if (isset($response['merchant_reference'])) {
            $summary['two_order_reference'] = (string)$response['merchant_reference'];
        }

        if (isset($response['error'])) {
            $summary['error'] = is_scalar($response['error']) ? (string)$response['error'] : 'structured_error';
        } elseif (isset($response['error_message'])) {
            $summary['error'] = (string)$response['error_message'];
        } elseif (isset($response['data']) && is_array($response['data']) && isset($response['data']['error'])) {
            $summary['error'] = is_scalar($response['data']['error']) ? (string)$response['data']['error'] : 'structured_error';
        }

        return $summary;
    }
    
    /**
     * Parse Two API validation errors and return user-friendly messages
     * Handles common validation errors like invalid phone numbers, missing fields, etc.
     * 
     * @param string $error_string Raw error string from Two API
     * @return string|null User-friendly message or null if not a recognized pattern
     */
    private function parseValidationErrorToFriendlyMessage($error_string)
    {
        if (!is_string($error_string)) {
            return null;
        }
        
        $error_lower = strtolower($error_string);
        
        // Phone number validation errors
        if (strpos($error_lower, 'invalid phone number') !== false || 
            strpos($error_lower, 'phone_number') !== false && strpos($error_lower, 'value_error') !== false) {
            return $this->l('The phone number in your billing address appears to be invalid. Please go back and ensure you have entered a valid phone number for your country.');
        }
        
        // Email validation errors
        if (strpos($error_lower, 'invalid email') !== false || 
            strpos($error_lower, 'email') !== false && strpos($error_lower, 'value_error') !== false) {
            return $this->l('The email address provided is invalid. Please check your email and try again.');
        }
        
        // Company/organization validation errors
        if (strpos($error_lower, 'invalid company') !== false || 
            strpos($error_lower, 'organization_number') !== false && strpos($error_lower, 'value_error') !== false) {
            return $this->l('The company information provided is invalid. Please go back to your billing address and search for your company name to select a valid company.');
        }
        
        // Address validation errors
        if (strpos($error_lower, 'invalid address') !== false || 
            strpos($error_lower, 'address') !== false && strpos($error_lower, 'value_error') !== false) {
            return $this->l('The address provided is invalid. Please go back and verify your billing address details.');
        }
        
        // General validation error - provide helpful generic message
        if (strpos($error_lower, 'validation error') !== false || strpos($error_lower, 'value_error') !== false) {
            return $this->l('Some of the information provided is invalid. Please check your billing address details and try again.');
        }
        
        return null;
    }

    /**
     * Generate a unique attempt token for the provider-first checkout flow.
     *
     * @param int $id_cart Cart ID
     * @param int $id_customer Customer ID
     * @return string
     */
    public function generateTwoCheckoutAttemptToken($id_cart, $id_customer)
    {
        $seed = (int)$id_cart . '|' . (int)$id_customer . '|' . microtime(true) . '|' . mt_rand();
        $random = '';
        try {
            $random = bin2hex(random_bytes(8));
        } catch (Exception $e) {
            $random = md5($seed . '|' . uniqid('', true));
        }

        return strtolower($this->generateUuidV4FromSeed($seed . '|' . $random));
    }

    /**
     * Validate whether a checkout callback is authorized for the stored attempt.
     *
     * @param array $attempt Attempt record from twopayment_attempt
     * @param string $provided_secure_key Optional key from callback query string
     * @param int $context_customer_id Current context customer ID
     * @param string $context_customer_secure_key Current context customer secure key
     * @return bool
     */
    public function isTwoAttemptCallbackAuthorized($attempt, $provided_secure_key = '', $context_customer_id = 0, $context_customer_secure_key = '')
    {
        if (!is_array($attempt)) {
            return false;
        }

        $expected_secure_key = isset($attempt['customer_secure_key']) ? trim((string)$attempt['customer_secure_key']) : '';
        if (Tools::isEmpty($expected_secure_key)) {
            return false;
        }

        $provided_secure_key = trim((string)$provided_secure_key);
        if (!Tools::isEmpty($provided_secure_key)) {
            return hash_equals($expected_secure_key, $provided_secure_key);
        }

        $attempt_customer_id = isset($attempt['id_customer']) ? (int)$attempt['id_customer'] : 0;
        $context_customer_id = (int)$context_customer_id;
        $context_customer_secure_key = trim((string)$context_customer_secure_key);

        if (
            $attempt_customer_id > 0 &&
            $context_customer_id === $attempt_customer_id &&
            !Tools::isEmpty($context_customer_secure_key)
        ) {
            return hash_equals($expected_secure_key, $context_customer_secure_key);
        }

        return false;
    }

    /**
     * Shared customer-ownership guard for order-scoped front controller access
     * (invoice download, legacy cancel/confirmation callbacks).
     *
     * Grants access when either:
     * - a `key` query parameter matching the order customer's secure key is provided
     *   (timing-safe compare; this path also covers guest checkout), or
     * - the logged-in context customer owns the order and their secure key matches.
     *
     * @param Order $order
     * @param Customer $customer Customer that owns the order
     * @param string $provided_key Optional key from the query string
     * @param int $context_customer_id Current context customer ID
     * @param string $context_customer_secure_key Current context customer secure key
     * @return bool
     */
    public function isTwoOrderCustomerAccessAuthorized($order, $customer, $provided_key = '', $context_customer_id = 0, $context_customer_secure_key = '')
    {
        if (!Validate::isLoadedObject($order) || !Validate::isLoadedObject($customer)) {
            return false;
        }

        $expected_secure_key = trim((string)$customer->secure_key);
        if (Tools::isEmpty($expected_secure_key)) {
            return false;
        }

        $provided_key = trim((string)$provided_key);
        if (!Tools::isEmpty($provided_key)) {
            return hash_equals($expected_secure_key, $provided_key);
        }

        $context_customer_id = (int)$context_customer_id;
        $context_customer_secure_key = trim((string)$context_customer_secure_key);

        return $context_customer_id === (int)$order->id_customer &&
            !Tools::isEmpty($context_customer_secure_key) &&
            hash_equals($expected_secure_key, $context_customer_secure_key);
    }

    /**
     * Translated, brand-safe user-facing message for an invoice download notice code.
     *
     * @param string $code One of TwoInvoiceRetrievalService::NOTICE_* codes
     * @param string $state Two order state (only used by the unavailable-state message)
     * @return string
     */
    public function getTwoInvoiceNoticeMessage($code, $state = '')
    {
        switch ($code) {
            case 'not_ready':
                return $this->l('The invoice is not ready yet because the order is still being fulfilled. Please try again later.');
            case 'unavailable_state':
                return sprintf(
                    $this->l('No invoice is available because the order is in state: %s.'),
                    $state !== '' ? $state : $this->l('UNKNOWN')
                );
            case 'no_reference':
                return $this->l('No payment provider order reference is set for this order.');
            case 'error':
            default:
                return $this->l('The invoice could not be retrieved. Please try again later or contact the store owner.');
        }
    }

    /**
     * Read an invoice download notice from the current request query string
     * (set by the admin invoice controller redirect). Only whitelisted notice
     * codes are honored and the state token is sanitized, so a crafted URL can
     * only ever surface one of the module's own messages.
     *
     * @return array{level:string, code:string, message:string}|null
     */
    public function getTwoInvoiceNoticeFromRequest()
    {
        $allowed = array(
            'not_ready' => 'info',
            'unavailable_state' => 'info',
            'no_reference' => 'error',
            'error' => 'error',
        );

        $code = trim((string)Tools::getValue('two_invoice_notice'));
        if (!isset($allowed[$code])) {
            return null;
        }

        $state = strtoupper((string)preg_replace('/[^A-Za-z0-9_]/', '', (string)Tools::getValue('two_invoice_state')));
        $state = Tools::substr($state, 0, 32);

        return array(
            'level' => $allowed[$code],
            'code' => $code,
            'message' => $this->getTwoInvoiceNoticeMessage($code, $state),
        );
    }

    /**
     * Stream a resolved invoice PDF to the browser and terminate the request.
     *
     * @param array $result Stream result from TwoInvoiceRetrievalService (action=stream)
     * @param string $order_reference Local order reference used to build the filename
     * @return void
     */
    public function streamTwoInvoicePdf($result, $order_reference)
    {
        $reference = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$order_reference);
        $filename = 'invoice-' . ($reference !== '' ? $reference : 'order') . '.pdf';
        $body = isset($result['body']) ? (string)$result['body'] : '';

        // Discard any open output buffers (stray notices, BOMs, template output)
        // and disable on-the-fly compression: gzip re-encoding would make the
        // response body diverge from the exact Content-Length declared below.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        @ini_set('zlib.output_compression', '0');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        echo $body;
        exit;
    }

    /**
     * Build a compact merchant_order_id for Two order creation before local order exists.
     *
     * @param string $attempt_token Unique attempt token
     * @param int $id_cart Cart ID
     * @return string
     */
    public function buildTwoMerchantOrderId($attempt_token, $id_cart)
    {
        $attempt_fragment = Tools::substr(str_replace('-', '', (string)$attempt_token), 0, 24);
        return 'ps-cart-' . (int)$id_cart . '-att-' . $attempt_fragment;
    }

    /**
     * Build deterministic hash for order creation idempotency key.
     *
     * @param Cart $cart
     * @param string $snapshot_hash
     * @return string
     */
    public function buildTwoOrderCreateIdempotencyKey($cart, $snapshot_hash)
    {
        // Keep retries idempotent for the same cart snapshot.
        $seed = 'create_order|' .
            (int)$cart->id . '|' .
            (int)$cart->id_customer . '|' .
            (string)$snapshot_hash . '|' .
            (string)Configuration::get('PS_TWO_ENVIRONMENT');

        return 'create_' . Tools::substr(hash('sha256', $seed), 0, 48);
    }

    /**
     * Detect whether an existing local order is already bound to a different Two order.
     *
     * @param array|false $existing_payment_data
     * @param string $incoming_two_order_id
     * @return bool
     */
    public function hasTwoOrderRebindingConflict($existing_payment_data, $incoming_two_order_id)
    {
        if (!is_array($existing_payment_data)) {
            return false;
        }

        $existing_two_order_id = isset($existing_payment_data['two_order_id'])
            ? trim((string)$existing_payment_data['two_order_id'])
            : '';
        $incoming_two_order_id = trim((string)$incoming_two_order_id);
        if (Tools::isEmpty($existing_two_order_id) || Tools::isEmpty($incoming_two_order_id)) {
            return false;
        }

        return !hash_equals($existing_two_order_id, $incoming_two_order_id);
    }

    /**
     * Calculate cart snapshot hash used to guard callback-time local order creation.
     *
     * @param Cart $cart
     * @param array $paymentdata
     * @return string
     */
    public function calculateTwoCheckoutSnapshotHash($cart, $paymentdata)
    {
        $snapshot = $this->buildTwoCheckoutSnapshot($cart, $paymentdata);
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Build normalized checkout snapshot with stable ordering.
     *
     * @param Cart $cart
     * @param array $paymentdata
     * @return array
     */
    private function buildTwoCheckoutSnapshot($cart, $paymentdata)
    {
        $line_items = array();
        if (isset($paymentdata['line_items']) && is_array($paymentdata['line_items'])) {
            foreach ($paymentdata['line_items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $line_items[] = array(
                    'type' => isset($item['type']) ? (string)$item['type'] : '',
                    'quantity' => isset($item['quantity']) ? (int)$item['quantity'] : 0,
                    'unit_price' => $this->normalizeSnapshotAmount(isset($item['unit_price']) ? $item['unit_price'] : 0),
                    'net_amount' => $this->normalizeSnapshotAmount(isset($item['net_amount']) ? $item['net_amount'] : 0),
                    'tax_amount' => $this->normalizeSnapshotAmount(isset($item['tax_amount']) ? $item['tax_amount'] : 0),
                    'gross_amount' => $this->normalizeSnapshotAmount(isset($item['gross_amount']) ? $item['gross_amount'] : 0),
                    'discount_amount' => $this->normalizeSnapshotAmount(isset($item['discount_amount']) ? $item['discount_amount'] : 0),
                    'tax_rate' => $this->normalizeSnapshotRate(isset($item['tax_rate']) ? $item['tax_rate'] : 0),
                );
            }
            usort($line_items, function ($a, $b) {
                return strcmp(json_encode($a), json_encode($b));
            });
        }

        $tax_subtotals = array();
        if (isset($paymentdata['tax_subtotals']) && is_array($paymentdata['tax_subtotals'])) {
            foreach ($paymentdata['tax_subtotals'] as $subtotal) {
                if (!is_array($subtotal)) {
                    continue;
                }
                $tax_subtotals[] = array(
                    'tax_rate' => $this->normalizeSnapshotRate(isset($subtotal['tax_rate']) ? $subtotal['tax_rate'] : 0),
                    'taxable_amount' => $this->normalizeSnapshotAmount(isset($subtotal['taxable_amount']) ? $subtotal['taxable_amount'] : 0),
                    'tax_amount' => $this->normalizeSnapshotAmount(isset($subtotal['tax_amount']) ? $subtotal['tax_amount'] : 0),
                );
            }
            usort($tax_subtotals, function ($a, $b) {
                return strcmp($a['tax_rate'], $b['tax_rate']);
            });
        }

        return array(
            'id_cart' => (int)$cart->id,
            'id_customer' => (int)$cart->id_customer,
            'id_currency' => (int)$cart->id_currency,
            'id_address_invoice' => (int)$cart->id_address_invoice,
            'id_address_delivery' => (int)$cart->id_address_delivery,
            'id_carrier' => (int)$cart->id_carrier,
            'currency' => isset($paymentdata['currency']) ? (string)$paymentdata['currency'] : '',
            'gross_amount' => $this->normalizeSnapshotAmount(isset($paymentdata['gross_amount']) ? $paymentdata['gross_amount'] : 0),
            'net_amount' => $this->normalizeSnapshotAmount(isset($paymentdata['net_amount']) ? $paymentdata['net_amount'] : 0),
            'tax_amount' => $this->normalizeSnapshotAmount(isset($paymentdata['tax_amount']) ? $paymentdata['tax_amount'] : 0),
            'discount_amount' => $this->normalizeSnapshotAmount(isset($paymentdata['discount_amount']) ? $paymentdata['discount_amount'] : 0),
            'tax_subtotals' => $tax_subtotals,
            'line_items' => $line_items,
        );
    }

    /**
     * Normalize snapshot numeric fields to fixed string decimals.
     *
     * @param mixed $amount
     * @return string
     */
    private function normalizeSnapshotAmount($amount)
    {
        return number_format((float)$amount, 2, '.', '');
    }

    /**
     * Normalize tax rate fields in checkout snapshots with fixed precision.
     *
     * @param mixed $rate
     * @return string
     */
    private function normalizeSnapshotRate($rate)
    {
        return number_format(max(0, (float)$rate), self::SNAPSHOT_TAX_RATE_PRECISION, '.', '');
    }

    /**
     * Periodically purge stale checkout attempts to keep table size bounded.
     *
     * @param bool $force
     * @return void
     */
    public function maybeCleanupStaleTwoCheckoutAttempts($force = false)
    {
        $now = time();
        $last_run = (int)Configuration::get('PS_TWO_ATTEMPT_CLEANUP_LAST_RUN', 0);
        if (!$force && $last_run > 0 && ($now - $last_run) < self::ATTEMPT_CLEANUP_INTERVAL_SECONDS) {
            return;
        }

        $cutoff = date('Y-m-d H:i:s', $now - (self::ATTEMPT_RETENTION_DAYS * 86400));
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'twopayment_attempt` WHERE `updated_at` < "' . pSQL($cutoff) . '"';
        $ok = Db::getInstance()->execute($sql);
        if (!$ok) {
            PrestaShopLogger::addLog(
                'TwoPayment: Failed to purge stale checkout attempts older than ' . $cutoff,
                2
            );
            return;
        }

        Configuration::updateValue('PS_TWO_ATTEMPT_CLEANUP_LAST_RUN', (string)$now);
    }

    /**
     * Insert or update a checkout attempt.
     *
     * @param string $attempt_token Unique attempt token
     * @param array $attempt_data Attempt payload
     * @return bool
     */
    public function setTwoCheckoutAttempt($attempt_token, $attempt_data)
    {
        $attempt_token = trim((string)$attempt_token);
        if (Tools::isEmpty($attempt_token) || !is_array($attempt_data)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $status = isset($attempt_data['status']) ? $this->normalizeTwoAttemptStatus($attempt_data['status']) : 'CREATED';
        $secure_key = isset($attempt_data['customer_secure_key']) ? (string)$attempt_data['customer_secure_key'] : '';
        $merchant_order_id = isset($attempt_data['merchant_order_id']) ? (string)$attempt_data['merchant_order_id'] : '';

        if (Tools::isEmpty($secure_key) || Tools::isEmpty($merchant_order_id)) {
            return false;
        }

        $data = array(
            'attempt_token' => pSQL($attempt_token),
            'id_cart' => isset($attempt_data['id_cart']) ? (int)$attempt_data['id_cart'] : 0,
            'id_customer' => isset($attempt_data['id_customer']) ? (int)$attempt_data['id_customer'] : 0,
            'id_order' => isset($attempt_data['id_order']) ? (int)$attempt_data['id_order'] : null,
            'customer_secure_key' => pSQL($secure_key),
            'merchant_order_id' => pSQL($merchant_order_id),
            'two_order_id' => isset($attempt_data['two_order_id']) ? pSQL($attempt_data['two_order_id']) : null,
            'two_order_reference' => isset($attempt_data['two_order_reference']) ? pSQL($attempt_data['two_order_reference']) : null,
            'two_order_state' => isset($attempt_data['two_order_state']) ? pSQL($attempt_data['two_order_state']) : null,
            'two_order_status' => isset($attempt_data['two_order_status']) ? pSQL($attempt_data['two_order_status']) : null,
            'two_day_on_invoice' => isset($attempt_data['two_day_on_invoice']) ? pSQL($attempt_data['two_day_on_invoice']) : null,
            'two_payment_term_type' => isset($attempt_data['two_payment_term_type']) ? pSQL($attempt_data['two_payment_term_type']) : 'STANDARD',
            'two_invoice_url' => isset($attempt_data['two_invoice_url']) ? pSQL($attempt_data['two_invoice_url'], true) : null,
            'two_invoice_id' => isset($attempt_data['two_invoice_id']) ? pSQL($attempt_data['two_invoice_id']) : null,
            'cart_snapshot_hash' => isset($attempt_data['cart_snapshot_hash']) ? pSQL($attempt_data['cart_snapshot_hash']) : null,
            'order_create_idempotency_key' => isset($attempt_data['order_create_idempotency_key']) ? pSQL($attempt_data['order_create_idempotency_key']) : null,
            'status' => pSQL($status),
            'updated_at' => pSQL($now),
        );

        $existing = $this->getTwoCheckoutAttempt($attempt_token);
        if ($existing) {
            unset($data['attempt_token']);
            return Db::getInstance()->update(
                'twopayment_attempt',
                $data,
                'attempt_token = "' . pSQL($attempt_token) . '"'
            );
        }

        $data['created_at'] = pSQL($now);
        return Db::getInstance()->insert('twopayment_attempt', $data);
    }

    /**
     * Retrieve a checkout attempt by token.
     *
     * @param string $attempt_token
     * @return array|false
     */
    public function getTwoCheckoutAttempt($attempt_token)
    {
        $attempt_token = trim((string)$attempt_token);
        if (Tools::isEmpty($attempt_token)) {
            return false;
        }

        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'twopayment_attempt` WHERE `attempt_token` = "' . pSQL($attempt_token) . '"';
        return Db::getInstance()->getRow($sql);
    }

    /**
     * Update attempt status and selected columns.
     *
     * @param string $attempt_token
     * @param string $status
     * @param array $extra_data
     * @return bool
     */
    public function updateTwoCheckoutAttemptStatus($attempt_token, $status, $extra_data = array())
    {
        $attempt_token = trim((string)$attempt_token);
        if (Tools::isEmpty($attempt_token)) {
            return false;
        }

        $normalized_status = $this->normalizeTwoAttemptStatus($status);
        $existing_attempt = $this->getTwoCheckoutAttempt($attempt_token);
        $existing_status = is_array($existing_attempt) && isset($existing_attempt['status']) ? (string)$existing_attempt['status'] : '';
        $cancelled_terminal = $this->isTwoAttemptStatusTerminal($existing_status) && !$this->isTwoAttemptStatusTerminal($normalized_status);

        if ($cancelled_terminal) {
            PrestaShopLogger::addLog(
                'TwoPayment: Ignoring non-terminal attempt status transition for token ' . $attempt_token .
                ' (' . strtoupper(trim((string)$existing_status)) . ' -> ' . $normalized_status . ')',
                2
            );
            $normalized_status = 'CANCELLED';
        }

        $data = array(
            'status' => pSQL($normalized_status),
            'updated_at' => pSQL(date('Y-m-d H:i:s')),
        );

        if (isset($extra_data['id_order'])) {
            $data['id_order'] = (int)$extra_data['id_order'];
        }
        if (!$cancelled_terminal && isset($extra_data['two_order_state'])) {
            $data['two_order_state'] = pSQL($extra_data['two_order_state']);
        }
        if (!$cancelled_terminal && isset($extra_data['two_order_status'])) {
            $data['two_order_status'] = pSQL($extra_data['two_order_status']);
        }
        if (!$cancelled_terminal && isset($extra_data['two_day_on_invoice'])) {
            $data['two_day_on_invoice'] = pSQL($extra_data['two_day_on_invoice']);
        }
        if (!$cancelled_terminal && isset($extra_data['two_payment_term_type'])) {
            $data['two_payment_term_type'] = pSQL($extra_data['two_payment_term_type']);
        }
        if (!$cancelled_terminal && isset($extra_data['two_invoice_url'])) {
            $data['two_invoice_url'] = pSQL($extra_data['two_invoice_url'], true);
        }
        if (!$cancelled_terminal && isset($extra_data['two_invoice_id'])) {
            $data['two_invoice_id'] = pSQL($extra_data['two_invoice_id']);
        }
        if (!$cancelled_terminal && isset($extra_data['cart_snapshot_hash'])) {
            $data['cart_snapshot_hash'] = pSQL($extra_data['cart_snapshot_hash']);
        }
        if (!$cancelled_terminal && isset($extra_data['order_create_idempotency_key'])) {
            $data['order_create_idempotency_key'] = pSQL($extra_data['order_create_idempotency_key']);
        }

        return Db::getInstance()->update(
            'twopayment_attempt',
            $data,
            'attempt_token = "' . pSQL($attempt_token) . '"'
        );
    }

    /**
     * Link an attempt to the created local order.
     *
     * @param string $attempt_token
     * @param int $id_order
     * @return bool
     */
    public function linkTwoCheckoutAttemptToOrder($attempt_token, $id_order)
    {
        return $this->updateTwoCheckoutAttemptStatus($attempt_token, 'CONFIRMED', array(
            'id_order' => (int)$id_order,
        ));
    }

    /**
     * Update merchant_order_id for a stored checkout attempt.
     *
     * @param string $attempt_token
     * @param string $merchant_order_id
     * @return bool
     */
    public function setTwoCheckoutAttemptMerchantOrderId($attempt_token, $merchant_order_id)
    {
        $attempt_token = trim((string)$attempt_token);
        $merchant_order_id = trim((string)$merchant_order_id);
        if (Tools::isEmpty($attempt_token) || Tools::isEmpty($merchant_order_id)) {
            return false;
        }

        return Db::getInstance()->update(
            'twopayment_attempt',
            array(
                'merchant_order_id' => pSQL($merchant_order_id),
                'updated_at' => pSQL(date('Y-m-d H:i:s')),
            ),
            'attempt_token = "' . pSQL($attempt_token) . '"'
        );
    }

    /**
     * Resolve existing order ID by cart ID with framework fallback.
     *
     * @param int $id_cart
     * @return int
     */
    public function getTwoOrderIdByCart($id_cart)
    {
        $id_cart = (int)$id_cart;
        if ($id_cart <= 0) {
            return 0;
        }

        if (method_exists('Order', 'getOrderByCartId')) {
            return (int)Order::getOrderByCartId($id_cart);
        }

        $sql = 'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = ' . $id_cart . ' ORDER BY `id_order` DESC';
        return (int)Db::getInstance()->getValue($sql);
    }

    /**
     * Resolve a local order ID from an attempt record for cancellation paths.
     * Prefers direct attempt linkage and falls back to cart lookup for race windows.
     *
     * @param array $attempt
     * @return int
     */
    public function resolveTwoAttemptOrderIdForCancellation($attempt)
    {
        if (!is_array($attempt)) {
            return 0;
        }

        $attempt_order_id = isset($attempt['id_order']) ? (int)$attempt['id_order'] : 0;
        if ($attempt_order_id > 0) {
            return $attempt_order_id;
        }

        $attempt_cart_id = isset($attempt['id_cart']) ? (int)$attempt['id_cart'] : 0;
        if ($attempt_cart_id <= 0) {
            return 0;
        }

        return (int)$this->getTwoOrderIdByCart($attempt_cart_id);
    }

    /**
     * Determine whether callback confirmation must be blocked by attempt status.
     *
     * @param string $status
     * @return bool
     */
    public function shouldBlockTwoAttemptConfirmationByStatus($status)
    {
        $status = strtoupper(trim((string)$status));
        return $status === 'CANCELLED';
    }

    /**
     * Attempt status terminality guard for race-safe state transitions.
     *
     * @param string $status
     * @return bool
     */
    public function isTwoAttemptStatusTerminal($status)
    {
        return $this->shouldBlockTwoAttemptConfirmationByStatus($status);
    }

    /**
     * Block fulfillment flows for terminal provider-cancelled orders.
     *
     * @param string $two_state
     * @return bool
     */
    public function shouldBlockTwoFulfillmentByTwoState($two_state)
    {
        $two_state = strtoupper(trim((string)$two_state));
        return $two_state === 'CANCELLED';
    }

    /**
     * Determine whether provider order is in a fulfillable state.
     *
     * @param string $two_state
     * @return bool
     */
    public function isTwoOrderFulfillableState($two_state)
    {
        $two_state = strtoupper(trim((string)$two_state));
        return $two_state === 'CONFIRMED';
    }

    /**
     * Resolve the local status ID used when Two order is verified and ready for fulfillment.
     *
     * @return int
     */
    public function getTwoVerifiedPendingFulfillmentStatusId()
    {
        $verified_status = (int)Configuration::get('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT');
        if ($verified_status <= 0) {
            $verified_status = (int)Configuration::get('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP');
            if ($verified_status <= 0) {
                $verified_status = (int)Configuration::get('PS_OS_PREPARATION');
            }
        }

        return (int)$verified_status;
    }

    /**
     * Determine whether a local status transition must be blocked for cancelled Two orders.
     *
     * @param int $status_id
     * @return bool
     */
    public function shouldBlockTwoStatusTransitionByCancelledState($status_id)
    {
        $status_id = (int)$status_id;
        if ($status_id <= 0) {
            return false;
        }

        if ($this->isFulfillmentTriggerStatus($status_id)) {
            return true;
        }

        return $status_id === (int)$this->getTwoVerifiedPendingFulfillmentStatusId();
    }

    /**
     * Check whether a provider order response confirms terminal cancellation.
     *
     * @param mixed $response
     * @param int|null $http_status
     * @return bool
     */
    public function isTwoOrderCancelledResponse($response, $http_status = null)
    {
        if (!is_array($response)) {
            return false;
        }

        if ($http_status === null) {
            $http_status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
        } else {
            $http_status = (int)$http_status;
        }

        if ($http_status <= 0 || $http_status >= self::HTTP_STATUS_BAD_REQUEST) {
            return false;
        }

        $state = isset($response['state']) ? strtoupper(trim((string)$response['state'])) : '';
        return $state === 'CANCELLED';
    }

    /**
     * Verify that stored payment data contains a usable Two order mapping.
     *
     * @param mixed $orderpaymentdata
     * @return bool
     */
    public function hasTwoProviderOrderMapping($orderpaymentdata)
    {
        if (!is_array($orderpaymentdata)) {
            return false;
        }

        if (!isset($orderpaymentdata['two_order_id'])) {
            return false;
        }

        return !Tools::isEmpty(trim((string)$orderpaymentdata['two_order_id']));
    }

    /**
     * Push a warning message to the current back-office controller when available.
     *
     * @param string $message
     * @return bool True when warning queue was updated
     */
    public function addTwoBackOfficeWarning($message)
    {
        $message = trim((string)$message);
        if (Tools::isEmpty($message)) {
            return false;
        }

        if (!isset($this->context) || !is_object($this->context)) {
            $this->context = Context::getContext();
        }

        $controller = isset($this->context->controller) ? $this->context->controller : null;
        if (!is_object($controller)) {
            return false;
        }

        if (!isset($controller->warnings) || !is_array($controller->warnings)) {
            $controller->warnings = array();
        }

        if (!in_array($message, $controller->warnings, true)) {
            $controller->warnings[] = $message;
        }

        return true;
    }

    /**
     * Resolve configured PrestaShop cancelled status used for Two cancellations.
     *
     * @return int
     */
    public function getTwoCancelledOrderStatusId()
    {
        $cancelled_status = (int)Configuration::get('PS_TWO_OS_CANCELLED');
        if ($cancelled_status <= 0) {
            $cancelled_status = (int)Configuration::get('PS_TWO_OS_CANCELLED_MAP');
            if ($cancelled_status <= 0) {
                $cancelled_status = (int)Configuration::get('PS_OS_CANCELED');
            }
        }

        return (int)$cancelled_status;
    }

    /**
     * Morph a pending order-status object to the configured cancelled state profile.
     * This reduces side effects (shipping stock movement, delivery toggles) when a
     * cancelled Two order is forcefully moved to a fulfillment trigger status.
     *
     * @param object $order_status
     * @param int|null $id_lang
     * @return bool
     */
    public function applyTwoCancelledOrderStateProfileToStatusObject($order_status, $id_lang = null)
    {
        if (!is_object($order_status)) {
            return false;
        }

        $cancelled_status = $this->getTwoCancelledOrderStatusId();
        if ($cancelled_status <= 0) {
            return false;
        }

        $id_lang = (int)$id_lang;
        if ($id_lang <= 0) {
            $id_lang = isset($this->context->language->id) ? (int)$this->context->language->id : 0;
        }

        $cancelled_state = $id_lang > 0 ? new OrderState($cancelled_status, $id_lang) : new OrderState($cancelled_status);
        if (!Validate::isLoadedObject($cancelled_state)) {
            $cancelled_state = new OrderState($cancelled_status);
        }
        if (!Validate::isLoadedObject($cancelled_state)) {
            return false;
        }

        $order_status->id = (int)$cancelled_state->id;
        $morph_fields = array('invoice', 'delivery', 'shipped', 'paid', 'logable', 'send_email', 'template', 'name', 'color', 'hidden');
        foreach ($morph_fields as $field) {
            if (isset($cancelled_state->{$field})) {
                $order_status->{$field} = $cancelled_state->{$field};
            }
        }

        return true;
    }

    /**
     * Replace a pending fulfillment-trigger history row with cancelled status before insert.
     *
     * @param object $history
     * @param object $order
     * @param string $two_order_id
     * @param string $source
     * @param string $two_state
     * @return bool
     */
    public function forceTwoCancelledOrderHistoryStateBeforeInsert($history, $order, $two_order_id, $source, $two_state)
    {
        if (!is_object($history)) {
            return false;
        }

        $cancelled_status = $this->getTwoCancelledOrderStatusId();
        if ($cancelled_status <= 0) {
            return false;
        }

        $history->id_order_state = $cancelled_status;

        if (is_object($order) && Validate::isLoadedObject($order)) {
            $order->current_state = $cancelled_status;

            $cancelled_state = isset($order->id_lang) ? new OrderState($cancelled_status, (int)$order->id_lang) : new OrderState($cancelled_status);
            if (!Validate::isLoadedObject($cancelled_state)) {
                $cancelled_state = new OrderState($cancelled_status);
            }
            $order->valid = (Validate::isLoadedObject($cancelled_state) && isset($cancelled_state->logable)) ? (bool)$cancelled_state->logable : false;
            $order->update();

            if (method_exists('Order', 'cleanHistoryCache')) {
                Order::cleanHistoryCache();
            }
        }

        $this->addTwoBackOfficeWarning($this->l('Fulfillment blocked: this Two order is cancelled at provider. The order status has been reverted to cancelled.'));
        PrestaShopLogger::addLog(
            'TwoPayment: Blocked fulfillment status insert for cancelled Two order ' . $two_order_id .
            ' (state=' . strtoupper(trim((string)$two_state)) . ', source=' . trim((string)$source) . '). ' .
            'History row was rewritten to cancelled.',
            2
        );

        return true;
    }

    /**
     * Keep local order state aligned when provider reports terminal cancellation.
     *
     * @param int $id_order
     * @param string $two_state
     * @return bool
     */
    public function syncLocalOrderStatusFromTwoState($id_order, $two_state)
    {
        $id_order = (int)$id_order;
        if ($id_order <= 0) {
            return false;
        }

        $two_state = strtoupper(trim((string)$two_state));
        if ($two_state !== 'CANCELLED') {
            return false;
        }

        $cancelled_status = $this->getTwoCancelledOrderStatusId();
        if ($cancelled_status <= 0) {
            return false;
        }

        return (bool)$this->changeOrderStatus($id_order, $cancelled_status);
    }

    /**
     * Ensure attempt status values are consistent.
     *
     * @param string $status
     * @return string
     */
    private function normalizeTwoAttemptStatus($status)
    {
        $status = strtoupper((string)$status);
        $allowed = array('CREATED', 'REDIRECTED', 'CONFIRMED', 'CANCELLED', 'FAILED');
        if (!in_array($status, $allowed, true)) {
            return 'FAILED';
        }
        return $status;
    }

    public function setTwoOrderPaymentData($id_order, $payment_data)
    {
        // PrestaShop standard: (int) casting prevents SQL injection for integer IDs
        $id_order = (int)$id_order;
        
        $result = $this->getTwoOrderPaymentData($id_order);
        $data = array(
            'id_order' => pSQL($id_order),
            'two_order_id' => pSQL($payment_data['two_order_id']),
            'two_order_reference' => pSQL($payment_data['two_order_reference']),
            'two_order_state' => pSQL($payment_data['two_order_state']),
            'two_order_status' => pSQL($payment_data['two_order_status']),
            'two_day_on_invoice' => pSQL($payment_data['two_day_on_invoice']),
            'two_invoice_url' => pSQL($payment_data['two_invoice_url']),
            'two_invoice_id' => isset($payment_data['two_invoice_id']) ? pSQL($payment_data['two_invoice_id']) : null,
            'two_payment_term_type' => isset($payment_data['two_payment_term_type']) ? pSQL($payment_data['two_payment_term_type']) : 'STANDARD',
        );
        // Note: invoice_details (payment info) is NOT stored in DB - fetched from Two API when needed
        
        if ($result) {
            Db::getInstance()->update('twopayment', $data, 'id_order = ' . (int) $id_order);
        } else {
            Db::getInstance()->insert('twopayment', $data);
        }
    }

    public function getTwoNextOrderID()
    {
        $id_order = Db::getInstance()->getValue('SELECT o.id_order FROM `' . _DB_PREFIX_ . 'orders` o' . Shop::addSqlAssociation('orders', 'o') . ' ORDER BY o.id_order DESC', false);
        return $id_order + 1;
    }

    public function getTwoOrderPaymentData($id_order)
    {
        // PrestaShop standard: (int) casting prevents SQL injection for integer IDs
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'twopayment` WHERE `id_order` = ' . (int)$id_order;
        $result = Db::getInstance()->getRow($sql);
        return $result;
    }

    /**
     * Merge fallback payment-term data into order payment data without overriding
     * already persisted values.
     *
     * @param array $twopaymentdata Primary order payment row
     * @param array|false $fallback_data Fallback row (attempt/API)
     * @return array
     */
    public function mergeTwoPaymentTermFallback($twopaymentdata, $fallback_data)
    {
        if (!is_array($twopaymentdata)) {
            return array();
        }

        if (!is_array($fallback_data)) {
            return $twopaymentdata;
        }

        $merged = $twopaymentdata;
        $current_days = isset($merged['two_day_on_invoice']) ? trim((string)$merged['two_day_on_invoice']) : '';
        $current_type = isset($merged['two_payment_term_type']) ? trim((string)$merged['two_payment_term_type']) : '';
        $fallback_days = isset($fallback_data['two_day_on_invoice']) ? trim((string)$fallback_data['two_day_on_invoice']) : '';
        $fallback_type = isset($fallback_data['two_payment_term_type']) ? trim((string)$fallback_data['two_payment_term_type']) : '';

        if (Tools::isEmpty($current_days) && !Tools::isEmpty($fallback_days)) {
            $merged['two_day_on_invoice'] = $fallback_days;
        }

        if (Tools::isEmpty($current_type) && !Tools::isEmpty($fallback_type)) {
            $merged['two_payment_term_type'] = $fallback_type;
        }

        return $merged;
    }

    /**
     * Invoice links should only be exposed once Two marks the order as fulfilled.
     *
     * @param array $twopaymentdata
     * @return bool
     */
    public function shouldExposeTwoInvoiceActions($twopaymentdata)
    {
        if (!is_array($twopaymentdata)) {
            return false;
        }

        $state = isset($twopaymentdata['two_order_state']) ? strtoupper(trim((string)$twopaymentdata['two_order_state'])) : '';
        return $state === 'FULFILLED';
    }

    /**
     * Refresh admin order payment data from Two API GET /v1/order/{id}.
     * Falls back to stored snapshot if provider call fails.
     *
     * @param int $id_order
     * @param array $twopaymentdata
     * @return array
     */
    protected function syncTwoAdminOrderPaymentDataFromProvider($id_order, $twopaymentdata)
    {
        if (!is_array($twopaymentdata)) {
            return array();
        }

        $id_order = (int)$id_order;
        $two_order_id = $this->resolveTwoOrderIdForAdmin($id_order, $twopaymentdata);
        if ($id_order <= 0 || Tools::isEmpty($two_order_id)) {
            return $twopaymentdata;
        }

        if (!isset($twopaymentdata['two_order_id']) || Tools::isEmpty($twopaymentdata['two_order_id'])) {
            $twopaymentdata['two_order_id'] = $two_order_id;
        }

        // Avoid duplicate provider calls when both admin hooks render on the same request.
        static $request_cache = array();
        $cache_key = $id_order . ':' . $two_order_id;
        if (isset($request_cache[$cache_key])) {
            return $request_cache[$cache_key];
        }

        $response = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, array(), 'GET');
        $http_status = isset($response['http_status']) ? (int)$response['http_status'] : 0;
        $order_payload = $this->extractTwoOrderPayloadFromApiResponse($response);
        if (
            $http_status !== self::HTTP_STATUS_OK ||
            !is_array($order_payload) ||
            !isset($order_payload['id']) ||
            Tools::isEmpty($order_payload['id'])
        ) {
            if ($http_status > 0) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Admin order sync failed for id_order ' . $id_order . ', Two order ' . $two_order_id . ', HTTP ' . $http_status,
                    2
                );
            }
            $request_cache[$cache_key] = $twopaymentdata;
            return $twopaymentdata;
        }

        $existing_days = isset($twopaymentdata['two_day_on_invoice']) ? (string)$twopaymentdata['two_day_on_invoice'] : '';
        $existing_type = isset($twopaymentdata['two_payment_term_type']) ? $twopaymentdata['two_payment_term_type'] : 'STANDARD';
        $resolved_terms = $this->resolveTwoPaymentTermsFromOrderResponse($order_payload, $existing_days, $existing_type);

        $updated = array(
            'two_order_id' => (string)$order_payload['id'],
            'two_order_reference' => isset($order_payload['merchant_reference']) ? (string)$order_payload['merchant_reference'] : (isset($twopaymentdata['two_order_reference']) ? (string)$twopaymentdata['two_order_reference'] : ''),
            'two_order_state' => isset($order_payload['state']) ? (string)$order_payload['state'] : (isset($twopaymentdata['two_order_state']) ? (string)$twopaymentdata['two_order_state'] : ''),
            'two_order_status' => isset($order_payload['status']) ? (string)$order_payload['status'] : (isset($twopaymentdata['two_order_status']) ? (string)$twopaymentdata['two_order_status'] : ''),
            'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
            'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
            'two_invoice_url' => isset($order_payload['invoice_url']) ? (string)$order_payload['invoice_url'] : (isset($twopaymentdata['two_invoice_url']) ? (string)$twopaymentdata['two_invoice_url'] : ''),
            'two_invoice_id' => isset($order_payload['invoice_details']['id']) ? (string)$order_payload['invoice_details']['id'] : (isset($twopaymentdata['two_invoice_id']) ? (string)$twopaymentdata['two_invoice_id'] : ''),
        );

        $compare_fields = array(
            'two_order_id',
            'two_order_reference',
            'two_order_state',
            'two_order_status',
            'two_day_on_invoice',
            'two_payment_term_type',
            'two_invoice_url',
            'two_invoice_id',
        );

        $changed = false;
        foreach ($compare_fields as $field) {
            $old_value = isset($twopaymentdata[$field]) ? trim((string)$twopaymentdata[$field]) : '';
            $new_value = isset($updated[$field]) ? trim((string)$updated[$field]) : '';
            if ($old_value !== $new_value) {
                $changed = true;
                break;
            }
        }

        if ($changed) {
            $this->setTwoOrderPaymentData($id_order, $updated);
        }

        $synced = $twopaymentdata;
        foreach ($updated as $field => $value) {
            $synced[$field] = $value;
        }

        $this->syncLocalOrderStatusFromTwoState(
            $id_order,
            isset($synced['two_order_state']) ? (string)$synced['two_order_state'] : ''
        );

        $request_cache[$cache_key] = $synced;
        return $synced;
    }

    /**
     * Resolve Two order ID for admin sync from persisted order row or attempt fallback.
     *
     * @param int $id_order
     * @param array $twopaymentdata
     * @return string
     */
    protected function resolveTwoOrderIdForAdmin($id_order, $twopaymentdata)
    {
        $id_order = (int)$id_order;
        if ($id_order <= 0 || !is_array($twopaymentdata)) {
            return '';
        }

        $two_order_id = isset($twopaymentdata['two_order_id']) ? trim((string)$twopaymentdata['two_order_id']) : '';
        if (!Tools::isEmpty($two_order_id)) {
            return $two_order_id;
        }

        $attempt = $this->getLatestTwoCheckoutAttemptByOrder($id_order);
        if (is_array($attempt)) {
            $attempt_two_order_id = isset($attempt['two_order_id']) ? trim((string)$attempt['two_order_id']) : '';
            if (!Tools::isEmpty($attempt_two_order_id)) {
                return $attempt_two_order_id;
            }
        }

        return '';
    }

    /**
     * Normalize Two API order payload shape for handlers that may return wrapped data.
     *
     * @param mixed $response
     * @return array
     */
    protected function extractTwoOrderPayloadFromApiResponse($response)
    {
        if (!is_array($response)) {
            return array();
        }

        if (isset($response['id']) && !Tools::isEmpty($response['id'])) {
            return $response;
        }

        if (isset($response['data']) && is_array($response['data'])) {
            if (isset($response['data']['id']) && !Tools::isEmpty($response['data']['id'])) {
                return $response['data'];
            }

            if (
                isset($response['data']['order']) &&
                is_array($response['data']['order']) &&
                isset($response['data']['order']['id']) &&
                !Tools::isEmpty($response['data']['order']['id'])
            ) {
                return $response['data']['order'];
            }
        }

        return array();
    }

    /**
     * Get latest persisted attempt data for a local order (if available).
     *
     * @param int $id_order
     * @return array|false
     */
    protected function getLatestTwoCheckoutAttemptByOrder($id_order)
    {
        $id_order = (int)$id_order;
        if ($id_order <= 0) {
            return false;
        }

        $sql = 'SELECT `two_order_id`, `status`, `two_day_on_invoice`, `two_payment_term_type`, `two_order_state`, `two_order_status`, `two_invoice_url`, `two_invoice_id` ' .
            'FROM `' . _DB_PREFIX_ . 'twopayment_attempt` ' .
            'WHERE `id_order` = ' . (int)$id_order . ' ' .
            'ORDER BY `updated_at` DESC, `id_attempt` DESC';
        $rows = Db::getInstance()->executeS($sql);

        if (!is_array($rows) || empty($rows)) {
            return false;
        }

        return $rows[0];
    }

    /**
     * Enrich admin order data with fallback values and persist repaired terms.
     *
     * @param int $id_order
     * @param array $twopaymentdata
     * @return array
     */
    protected function enrichTwoAdminOrderPaymentData($id_order, $twopaymentdata)
    {
        if (!is_array($twopaymentdata)) {
            return array();
        }

        $fallback_data = $this->getLatestTwoCheckoutAttemptByOrder((int)$id_order);
        $merged = $this->mergeTwoPaymentTermFallback($twopaymentdata, $fallback_data);

        $updated_days = isset($merged['two_day_on_invoice']) ? trim((string)$merged['two_day_on_invoice']) : '';
        $updated_type = isset($merged['two_payment_term_type']) ? trim((string)$merged['two_payment_term_type']) : '';
        $original_days = isset($twopaymentdata['two_day_on_invoice']) ? trim((string)$twopaymentdata['two_day_on_invoice']) : '';
        $original_type = isset($twopaymentdata['two_payment_term_type']) ? trim((string)$twopaymentdata['two_payment_term_type']) : '';

        if ($updated_days !== $original_days || $updated_type !== $original_type) {
            Db::getInstance()->update(
                'twopayment',
                array(
                    'two_day_on_invoice' => $updated_days,
                    'two_payment_term_type' => $updated_type,
                ),
                'id_order = ' . (int)$id_order
            );
        }

        return $merged;
    }

    public function hookDisplayPaymentReturn($params)
    {
        $id_order = $params['order']->id;
        $twopaymentdata = $this->getTwoOrderPaymentData($id_order);
        if ($twopaymentdata) {
            // Check if order is in VERIFIED state and try to confirm it
            if (!empty($twopaymentdata['two_order_id']) && $twopaymentdata['two_order_state'] == 'VERIFIED') {
                PrestaShopLogger::addLog('TwoPayment: Payment return page - attempting to confirm VERIFIED order ID: ' . $twopaymentdata['two_order_id'], 1);
                
                $confirm_result = $this->confirmTwoOrder($twopaymentdata['two_order_id']);
                
                if ($confirm_result['success']) {
                    // Update the database with the new confirmed state
                    $payment_data = array(
                        'two_order_id' => $twopaymentdata['two_order_id'],
                        'two_order_reference' => $twopaymentdata['two_order_reference'],
                        'two_order_state' => $confirm_result['state'],
                        'two_order_status' => $confirm_result['status'] ?: $twopaymentdata['two_order_status'],
                        'two_day_on_invoice' => $twopaymentdata['two_day_on_invoice'],
                        'two_invoice_url' => $twopaymentdata['two_invoice_url'],
                        'two_invoice_id' => isset($confirm_result['invoice_details']['id']) ? $confirm_result['invoice_details']['id'] : $twopaymentdata['two_invoice_id'],
                        'two_payment_term_type' => isset($twopaymentdata['two_payment_term_type']) ? $twopaymentdata['two_payment_term_type'] : 'STANDARD',
                    );
                    $this->setTwoOrderPaymentData($id_order, $payment_data);
                    
                    // Update local data for template
                    $twopaymentdata['two_order_state'] = $confirm_result['state'];
                    if ($confirm_result['status']) {
                        $twopaymentdata['two_order_status'] = $confirm_result['status'];
                    }
                }
            }
            
            // Generate PDF URL if Two order ID is available
            $pdf_url = null;
            if (!empty($twopaymentdata['two_order_id'])) {
                $pdf_url = $this->getTwoPdfUrl($twopaymentdata['two_order_id']);
            }
            
            $this->context->smarty->assign(array(
                'twopaymentdata' => $twopaymentdata,
                'two_buyer_portal_url' => $this->getTwoBuyerPortalUrl(),
            ));
            return $this->context->smarty->fetch('module:twopayment/views/templates/hook/displayPaymentReturnBuyer.tpl');
        }
    }

    public function hookDisplayOrderDetail($params)
    {
        $id_order = $params['order']->id;
        $twopaymentdata = $this->getTwoOrderPaymentData($id_order);
        if ($twopaymentdata) {
            // Route the invoice download through the module front controller so the
            // plugin can check the Two order state instead of exposing a raw API URL
            // (which returns a bare 400 ORDER_NOT_FULFILLED before fulfillment).
            $pdf_url = null;
            if (!empty($twopaymentdata['two_order_id'])) {
                $link_params = array('id_order' => (int)$id_order);
                $order_customer = new Customer((int)$params['order']->id_customer);
                if (Validate::isLoadedObject($order_customer) && !Tools::isEmpty((string)$order_customer->secure_key)) {
                    // Same secure-key fallback the cancel/confirmation callbacks use;
                    // this keeps guest-checkout invoice access working.
                    $link_params['key'] = (string)$order_customer->secure_key;
                }
                $pdf_url = $this->context->link->getModuleLink($this->name, 'invoice', $link_params, true);
            }

            $this->context->smarty->assign(array(
                'twopaymentdata' => $twopaymentdata,
                'two_portal_url' => $this->getTwoPortalUrl(),
                'two_pdf_url' => $pdf_url,
            ));
            return $this->context->smarty->fetch('module:twopayment/views/templates/hook/displayOrderDetail.tpl');
        }
    }

    public function hookDisplayAdminOrderLeft($params)
    {
        $id_order = $params['id_order'];
        $twopaymentdata = $this->getTwoOrderPaymentData($id_order);
        if ($twopaymentdata) {
            $twopaymentdata = $this->syncTwoAdminOrderPaymentDataFromProvider((int)$id_order, $twopaymentdata);
            $twopaymentdata = $this->enrichTwoAdminOrderPaymentData((int)$id_order, $twopaymentdata);
            $invoice_actions_available = $this->shouldExposeTwoInvoiceActions($twopaymentdata);

            // Route the invoice download through the module admin controller so the
            // plugin can check the Two order state (covers the race where the order
            // just flipped to FULFILLED but the PDF is not generated yet).
            $pdf_url = null;
            if ($invoice_actions_available && !empty($twopaymentdata['two_order_id'])) {
                $pdf_url = $this->context->link->getAdminLink(
                    'AdminTwopaymentInvoice',
                    true,
                    array(),
                    array('id_order' => (int)$id_order)
                );
            }
            
            $this->context->smarty->assign(array(
                'twopaymentdata' => $twopaymentdata,
                'two_portal_url' => $this->getTwoPortalUrl(), // Dynamic portal URL based on environment
                'two_pdf_url' => $pdf_url, // PDF invoice URL if available
                'two_invoice_actions_available' => $invoice_actions_available,
                'two_invoice_notice' => $this->getTwoInvoiceNoticeFromRequest(),
            ));
            return $this->context->smarty->fetch('module:twopayment/views/templates/hook/displayAdminOrderLeft.tpl');
        }
    }

    public function hookDisplayAdminOrderTabLink($params)
    {
        $id_order = $params['id_order'];
        $twopaymentdata = $this->getTwoOrderPaymentData($id_order);
        if ($twopaymentdata) {
            return $this->context->smarty->fetch('module:twopayment/views/templates/hook/displayAdminOrderTabLink.tpl');
        }
    }

    public function hookDisplayAdminOrderTabContent($params)
    {
        $id_order = $params['id_order'];
        $twopaymentdata = $this->getTwoOrderPaymentData($id_order);

        if ($twopaymentdata) {
            $twopaymentdata = $this->syncTwoAdminOrderPaymentDataFromProvider((int)$id_order, $twopaymentdata);
            $twopaymentdata = $this->enrichTwoAdminOrderPaymentData((int)$id_order, $twopaymentdata);
            $invoice_actions_available = $this->shouldExposeTwoInvoiceActions($twopaymentdata);

            // Route the invoice download through the module admin controller so the
            // plugin can check the Two order state (covers the race where the order
            // just flipped to FULFILLED but the PDF is not generated yet).
            $pdf_url = null;
            if ($invoice_actions_available && !empty($twopaymentdata['two_order_id'])) {
                $pdf_url = $this->context->link->getAdminLink(
                    'AdminTwopaymentInvoice',
                    true,
                    array(),
                    array('id_order' => (int)$id_order)
                );
            }
            
            $this->context->smarty->assign(array(
                'twopaymentdata' => $twopaymentdata,
                'two_portal_url' => $this->getTwoPortalUrl(), // Dynamic portal URL based on environment
                'two_pdf_url' => $pdf_url, // PDF invoice URL if available
                'use_own_invoices' => (bool)Configuration::get('PS_TWO_USE_OWN_INVOICES'),
                'two_invoice_actions_available' => $invoice_actions_available,
                'two_invoice_notice' => $this->getTwoInvoiceNoticeFromRequest(),
            ));
            return $this->context->smarty->fetch('module:twopayment/views/templates/hook/displayAdminOrderTabContent.tpl');
        }
    }

    /**
     * Hook: actionCustomerAddressSave
     * Capture company data when customer saves address for session persistence
     */
    public function hookActionCustomerAddressSave($params)
    {
        if (!isset($params['address']) || !is_object($params['address'])) {
            return;
        }
        
        $address = $params['address'];
        
        // Only process if this address has company information
        if (empty($address->company)) {
            return;
        }
        
        // Store company data in session for persistence across checkout steps
        if (isset($this->context->cookie)) {
            $this->context->cookie->two_company_name = $address->company;
            if (!empty($address->id)) {
                $this->context->cookie->two_company_address_id = (string) (int) $address->id;
            }
            
            // Try to get organization number from form data if available
            $companyId = Tools::getValue('companyid', '');
            if (!empty($companyId)) {
                $this->context->cookie->two_company_id = $companyId;
            }
            
            // Set cookie expiration (1 hour)
            $this->context->cookie->setExpire(time() + self::COOKIE_EXPIRY_ONE_HOUR);
            
            PrestaShopLogger::addLog('TwoPayment: Company data captured from address save - Company: ' . $address->company, 1);                                 
        }
    }
    
    /**
     * Upload PrestaShop invoice to Two after successful fulfillment
     * 
     * This method is called when an order is fulfilled and "Using Own Invoices" is enabled.
     * It uploads the PrestaShop-generated invoice PDF to Two using the three-step upload process.
     * 
     * @param int $id_order PrestaShop order ID
     * @param array $orderpaymentdata Two payment data from database
     * @return void
     */
    private function uploadInvoiceAfterFulfillment($id_order, $orderpaymentdata)
    {
        try {
            // Validate we have the invoice ID
            if (!isset($orderpaymentdata['two_invoice_id']) || empty($orderpaymentdata['two_invoice_id'])) {
                PrestaShopLogger::addLog(
                    'TwoInvoiceUpload: Cannot upload invoice - Two invoice ID missing for Order ' . $id_order . 
                    '. Payment data keys: ' . implode(', ', array_keys($orderpaymentdata)) . 
                    '. two_invoice_id value: ' . (isset($orderpaymentdata['two_invoice_id']) ? $orderpaymentdata['two_invoice_id'] : 'NOT SET'),
                    2,
                    null,
                    'Order',
                    $id_order
                );
                
                // Update status to NOT_APPLICABLE (no invoice ID available)
                Db::getInstance()->update(
                    'twopayment',
                    array('two_invoice_upload_status' => 'NOT_APPLICABLE'),
                    'id_order = ' . (int)$id_order
                );
                return;
            }
            
            $two_invoice_id = $orderpaymentdata['two_invoice_id'];
            
            // Check if already uploaded
            if (isset($orderpaymentdata['two_invoice_upload_status']) && 
                $orderpaymentdata['two_invoice_upload_status'] === 'UPLOADED') {
                PrestaShopLogger::addLog(
                    'TwoInvoiceUpload: Invoice already uploaded for Order ' . $id_order,
                    1,
                    null,
                    'Order',
                    $id_order
                );
                return;
            }
            
            // Update status to UPLOADING
            Db::getInstance()->update(
                'twopayment',
                array('two_invoice_upload_status' => 'UPLOADING'),
                'id_order = ' . (int)$id_order
            );
            
            PrestaShopLogger::addLog(
                'TwoInvoiceUpload: Starting invoice upload process for Order ' . $id_order,
                1,
                null,
                'Order',
                $id_order
            );
            
            // Load the invoice upload service
            require_once dirname(__FILE__) . '/classes/TwoInvoiceUploadService.php';
            $uploadService = new TwoInvoiceUploadService($this);
            
            // Upload invoice (index 0 for first/only document)
            $result = $uploadService->uploadInvoice($id_order, $two_invoice_id, 0);
            
            // Update status based on result
            if ($result['success']) {
                Db::getInstance()->update(
                    'twopayment',
                    array(
                        'two_invoice_upload_status' => 'UPLOADED',
                        'two_invoice_upload_reference' => isset($result['reference']) ? pSQL($result['reference']) : null,
                        'two_invoice_uploaded_at' => date('Y-m-d H:i:s'),
                        'two_invoice_upload_error' => null, // Clear any previous error
                    ),
                    'id_order = ' . (int)$id_order
                );
                
                PrestaShopLogger::addLog(
                    'TwoInvoiceUpload: ✓ Invoice upload completed successfully for Order ' . $id_order,
                    1,
                    null,
                    'Order',
                    $id_order
                );
            } else {
                $errorMessage = isset($result['error']) ? $result['error'] : 'Unknown error';
                
                Db::getInstance()->update(
                    'twopayment',
                    array(
                        'two_invoice_upload_status' => 'FAILED',
                        'two_invoice_upload_error' => pSQL($errorMessage),
                    ),
                    'id_order = ' . (int)$id_order
                );
                
                PrestaShopLogger::addLog(
                    'TwoInvoiceUpload: ✗ Invoice upload failed for Order ' . $id_order . ' - Error: ' . $errorMessage,
                    3,
                    null,
                    'Order',
                    $id_order
                );
            }
            
        } catch (Exception $e) {
            // Log exception but don't break the fulfillment process
            PrestaShopLogger::addLog(
                'TwoInvoiceUpload: Exception during invoice upload for Order ' . $id_order . ' - ' . $e->getMessage(),
                3,
                null,
                'Order',
                $id_order
            );
            
            // Update status to FAILED
            Db::getInstance()->update(
                'twopayment',
                array(
                    'two_invoice_upload_status' => 'FAILED',
                    'two_invoice_upload_error' => pSQL('Exception: ' . $e->getMessage()),
                ),
                'id_order = ' . (int)$id_order
            );
        }
    }
    
    /**
     * Verify and resolve company data using organization number via Two's company search API
     * 
     * CRITICAL: This enables smart UX for logged-in users with existing addresses.
     * Instead of searching by company name, we search by organization 
     * number which gives an EXACT match.
     * 
     * Two's API supports searching by org number: /companies/v2/company?q={org_number}&country={iso}
     * Example: https://api.two.inc/companies/v2/company?q=A81304487&country=ES
     * 
     * @param string $orgNumber The organization number to search for (CIF, NIF, company number, etc.)
     * @param string $countryIso Two-letter country ISO code (e.g., 'GB', 'NO', 'SE', 'ES')
     * @return array|null Returns ['name' => string, 'organization_number' => string] on success, null on failure
     */
    public function verifyCompanyByOrgNumber($orgNumber, $countryIso)
    {
        if (empty($orgNumber) || empty($countryIso)) {
            return null;
        }
        
        // Normalize inputs
        $orgNumber = trim($orgNumber);
        $countryIso = strtoupper(trim($countryIso));
        
        // Build the search URL - search by organization number for exact match
        // This is the key insight: Two's API accepts org numbers in the 'q' parameter
        $searchUrl = $this->getTwoCheckoutHostUrl() . '/companies/v2/company';
        $searchUrl .= '?' . http_build_query([
            'q' => $orgNumber,
            'country' => $countryIso
        ]);
        
        PrestaShopLogger::addLog(
            'TwoPayment: Verifying company by org number: ' . $orgNumber . ' in ' . $countryIso,
            1
        );
        
        // Make the request (no API key required for company search)
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $searchUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::API_TIMEOUT_SHORT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json'
        ]);
        
        // Configure SSL verification
        $this->configureSslVerification($ch);
        
        $response_body = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // Handle errors
        if ($response_body === false || !empty($curl_error) || $http_status !== 200) {
            PrestaShopLogger::addLog(
                'TwoPayment: Company verification failed - HTTP ' . $http_status . 
                ', Error: ' . ($curl_error ?: 'Unknown') . 
                ', OrgNumber: ' . $orgNumber . ', Country: ' . $countryIso,
                2
            );
            return null;
        }
        
        $response = json_decode($response_body, true);
        
        if (!isset($response['items']) || !is_array($response['items']) || empty($response['items'])) {
            PrestaShopLogger::addLog(
                'TwoPayment: Company verification - no results for org number: ' . $orgNumber . ' in ' . $countryIso,
                1
            );
            return null;
        }
        
        $companies = $response['items'];
        
        // When searching by org number, we expect an exact match
        // The API should return the company with matching organization number
        foreach ($companies as $company) {
            $foundOrgNumber = $this->extractOrganizationNumber($company);
            
            // Normalize both for comparison (remove spaces, dashes, make uppercase)
            $normalizedSearch = strtoupper(preg_replace('/[\s\-]/', '', $orgNumber));
            $normalizedFound = strtoupper(preg_replace('/[\s\-]/', '', $foundOrgNumber));
            
            if ($normalizedSearch === $normalizedFound) {
                PrestaShopLogger::addLog(
                    'TwoPayment: ✓ Company verified by org number - ' . $orgNumber . 
                    ' => ' . $company['name'] . ' in ' . $countryIso,
                    1
                );
                return [
                    'name' => $company['name'],
                    'organization_number' => $foundOrgNumber
                ];
            }
        }
        
        // If no exact org number match, but we got a single result, it might be valid
        // (API might have found it via partial match)
        if (count($companies) === 1) {
            $company = $companies[0];
            $foundOrgNumber = $this->extractOrganizationNumber($company);
            if (!empty($foundOrgNumber)) {
                PrestaShopLogger::addLog(
                    'TwoPayment: ✓ Company resolved (single result) - searched: ' . $orgNumber . 
                    ' => ' . $company['name'] . ' (' . $foundOrgNumber . ') in ' . $countryIso,
                    1
                );
                return [
                    'name' => $company['name'],
                    'organization_number' => $foundOrgNumber
                ];
            }
        }
        
        PrestaShopLogger::addLog(
            'TwoPayment: Company verification - org number not matched: ' . $orgNumber . 
            ' in ' . $countryIso . ' (found ' . count($companies) . ' companies)',
            2
        );
        return null;
    }
    
    /**
     * Extract organization number from address fields
     * Checks various PrestaShop address fields where org numbers might be stored
     * 
     * @param Address $address PrestaShop address object
     * @param string $countryIso Country ISO code for context-aware extraction
     * @return string Organization number or empty string
     */
    public function extractOrgNumberFromAddress($address, $countryIso)
    {
        if (!Validate::isLoadedObject($address)) {
            return '';
        }
        
        $countryIso = strtoupper(trim($countryIso));
        
        // Priority 1: dni field (commonly used in ES, PT, IT for fiscal numbers like CIF/NIF)
        if (!empty($address->dni)) {
            $dni = trim($address->dni);
            // Validate it looks like an org number (alphanumeric, reasonable length)
            if (preg_match('/^[A-Z0-9\-]{5,20}$/i', $dni)) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Found org number in dni field: ' . $dni . ' for ' . $countryIso,
                    1
                );
                return $dni;
            }
        }
        
        // Priority 2: vat_number field (if available in address)
        if (property_exists($address, 'vat_number') && !empty($address->vat_number)) {
            $vatNumber = trim($address->vat_number);
            // VAT numbers often have a country prefix (e.g. GB123...). Only strip when it matches address country.
            if (preg_match('/^([A-Z]{2})([A-Z0-9\-]{3,})$/i', $vatNumber, $matches)) {
                $prefix = strtoupper($matches[1]);
                if ($prefix === $countryIso) {
                    $vatNumber = $matches[2];
                }
            }
            if (preg_match('/^[A-Z0-9\-]{5,20}$/i', $vatNumber)) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Found org number in vat_number field: ' . $vatNumber . ' for ' . $countryIso,
                    1
                );
                return $vatNumber;
            }
        }
        
        // Priority 3: companyid field (if it was set previously)
        if (property_exists($address, 'companyid') && !empty($address->companyid)) {
            PrestaShopLogger::addLog(
                'TwoPayment: Found org number in companyid field: ' . $address->companyid . ' for ' . $countryIso,
                1
            );
            return trim($address->companyid);
        }
        
        return '';
    }
    
    /**
     * Extract organization number from Two company search result
     * Handles various field naming conventions across different countries
     * 
     * @param array $company Company data from Two API
     * @return string Organization number or empty string
     */
    private function extractOrganizationNumber($company)
    {
        // Primary: national_identifier object (most countries)
        if (isset($company['national_identifier']) && is_array($company['national_identifier'])) {
            $ni = $company['national_identifier'];
            $orgNumber = $ni['id'] ?? $ni['value'] ?? $ni['organisationNumber'] ?? 
                        $ni['organizationNumber'] ?? $ni['registration_number'] ?? 
                        $ni['company_number'] ?? '';
            if (!empty($orgNumber)) {
                return trim($orgNumber);
            }
        }
        
        // Fallback: Direct fields (commonly used in GB)
        $directFields = ['registration_number', 'company_number', 'organization_number', 'organisation_number'];
        foreach ($directFields as $field) {
            if (isset($company[$field]) && !empty($company[$field])) {
                return trim($company[$field]);
            }
        }
        
        return '';
    }
}
