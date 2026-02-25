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

class Twopayment extends PaymentModule
{
    // Constants for order building logic
    const GROSS_AMOUNT_TOLERANCE = 0.02; // 2 cents tolerance for rounding differences
    const ORDER_INTENT_EXPIRY_SECONDS = 1800; // 30 minutes
    
    // Constants for payment terms
    const DEFAULT_PAYMENT_TERM_DAYS = 30; // Default payment term in days
    const PAYMENT_TERMS_OPTIONS = [7, 15, 20, 30, 45, 60, 90]; // Available payment term options
    
    // Constants for API timeouts (seconds)
    const API_TIMEOUT_SHORT = 30; // Standard API timeout
    const API_TIMEOUT_LONG = 60; // Extended timeout for file uploads
    
    // Constants for validation tolerances
    const TAX_FORMULA_TOLERANCE = 0.01; // Tolerance for tax formula validation
    const NET_FORMULA_TOLERANCE = 0.05; // Tolerance for net formula validation
    
    // Constants for delivery dates
    const DEFAULT_DELIVERY_DAYS_OFFSET = 7; // Default expected delivery date offset
    
    // Constants for HTTP status codes
    const HTTP_STATUS_OK = 200;
    const HTTP_STATUS_CREATED = 201;
    const HTTP_STATUS_BAD_REQUEST = 400;
    const HTTP_STATUS_SERVER_ERROR = 500;
    
    // Constants for cookie/session expiry (seconds)
    const COOKIE_EXPIRY_ONE_HOUR = 3600; // 1 hour

    protected $output = '';
    protected $errors = array();
    protected $verifiedMerchantId = null;
    protected $verifiedMerchantShortName = null;

    public function __construct()
    {
        $this->name = 'twopayment';
        $this->tab = 'payments_gateways';
        $this->version = '2.3.2';
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
        $this->enable_order_intent = Configuration::get('PS_TWO_ENABLE_ORDER_INTENT');
        $this->use_account_type = Configuration::get('PS_TWO_USE_ACCOUNT_TYPE');
        $this->finalize_purchase_shipping = Configuration::get('PS_TWO_FINALIZE_PURCHASE');
        
        // Ensure custom Two states exist (for existing installations)
        $this->ensureCustomStatesExist();
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
    

    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        return parent::install() &&
            $this->registerHook('actionAdminControllerSetMedia') &&
            $this->registerHook('actionFrontControllerSetMedia') &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('paymentOptions') &&
            $this->registerHook('displayPaymentReturn') &&
            $this->registerHook('displayAdminOrderLeft') &&
            $this->registerHook('displayAdminOrderTabLink') &&
            $this->registerHook('displayAdminOrderTabContent') &&
            $this->registerHook('displayOrderDetail') &&
            $this->registerHook('actionOrderEdited') &&
            $this->registerHook('actionAdminOrdersTrackingNumberUpdate') &&
            $this->registerHook('actionCustomerAddressSave') &&
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
        Configuration::updateValue('PS_TWO_ENABLE_ORDER_INTENT', 1);
        Configuration::updateValue('PS_TWO_USE_ACCOUNT_TYPE', 0);
        Configuration::updateValue('PS_TWO_USE_OWN_INVOICES', 0); // Disabled by default - must be enabled after coordinating with Two
        Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', 'STANDARD'); // Default: Standard payment terms (not EOM)
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1); // Default: 30 days enabled
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
        // Only create our own payment tracking table - no modifications to core PrestaShop tables
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
            $this->unregisterHook('paymentOptions') &&
            $this->unregisterHook('displayPaymentReturn') &&
            $this->unregisterHook('displayAdminOrderLeft') &&
            $this->unregisterHook('displayAdminOrderTabLink') &&
            $this->unregisterHook('displayAdminOrderTabContent') &&
            $this->unregisterHook('displayOrderDetail') &&
            $this->unregisterHook('actionOrderEdited') &&
            $this->unregisterHook('actionAdminOrdersTrackingNumberUpdate') &&
            $this->unregisterHook('actionCustomerAddressSave') &&
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
        Configuration::deleteByName('PS_TWO_FINALIZE_PURCHASE');
        Configuration::deleteByName('PS_TWO_ENABLE_ORDER_INTENT');
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
                        'desc' => $this->l('Select the Two API environment to use. Production for live transactions, Development for testing.'),
                        'required' => true,
                        'options' => array(
                            'query' => array(
                                array('id_option' => 'development', 'name' => $this->l('Development')),
                                array('id_option' => 'production', 'name' => $this->l('Production')),
                            ),
                            'id' => 'id_option',
                            'name' => 'name'
                        )
                    ),
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
                            'query' => array(
                                array(
                                    'id' => '7',
                                    'name' => $this->l('7 days'),
                                    'val' => '1',
                                    'class' => 'two-term-option two-term-7 two-term-standard'
                                ),
                                array(
                                    'id' => '15',
                                    'name' => $this->l('15 days'),
                                    'val' => '1',
                                    'class' => 'two-term-option two-term-15 two-term-standard'
                                ),
                                array(
                                    'id' => '20',
                                    'name' => $this->l('20 days'),
                                    'val' => '1',
                                    'class' => 'two-term-option two-term-20 two-term-standard'
                                ),
                                array(
                                    'id' => '30',
                                    'name' => $this->l('30 days'),
                                    'val' => '1',
                                    'class' => 'two-term-option two-term-30 two-term-both'
                                ),
                                array(
                                    'id' => '45',
                                    'name' => $this->l('45 days'),
                                    'val' => '1',
                                    'class' => 'two-term-option two-term-45 two-term-both'
                                ),
                                array(
                                    'id' => '60',
                                    'name' => $this->l('60 days'),
                                    'val' => '1',
                                    'class' => 'two-term-option two-term-60 two-term-both'
                                ),
                                array(
                                    'id' => '90',
                                    'name' => $this->l('90 days'),
                                    'val' => '1',
                                    'class' => 'two-term-option two-term-90 two-term-standard'
                                ),
                            ),
                            'id' => 'id',
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
        
        // Payment term type (STANDARD or EOM)
        $fields_values['PS_TWO_PAYMENT_TERM_TYPE'] = Tools::getValue('PS_TWO_PAYMENT_TERM_TYPE', Configuration::get('PS_TWO_PAYMENT_TERM_TYPE'));
        
        // Payment terms checkboxes
        $payment_terms = array_map('strval', self::PAYMENT_TERMS_OPTIONS);
        foreach ($payment_terms as $term) {
            $fields_values['PS_TWO_PAYMENT_TERMS_' . $term] = Tools::getValue('PS_TWO_PAYMENT_TERMS_' . $term, Configuration::get('PS_TWO_PAYMENT_TERMS_' . $term));
        }
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
        if (Tools::isEmpty($environment) || !in_array($environment, array('production', 'development'))) {
            $this->errors[] = $this->l('Please select a valid environment (Production or Development).');
        }
        
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
        // Verify API key with Two against selected environment and capture merchant id and short name
        $apiKey = trim(Tools::getValue('PS_TWO_MERCHANT_API_KEY'));
        $env = Tools::getValue('PS_TWO_ENVIRONMENT');
        if (!empty($apiKey) && in_array($env, array('production','development'))) {
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
        Configuration::updateValue('PS_TWO_DISABLE_SSL_VERIFY', (int)Tools::getValue('PS_TWO_DISABLE_SSL_VERIFY', 0));
        if ($this->verifiedMerchantId) {
            Configuration::updateValue('PS_TWO_MERCHANT_ID', $this->verifiedMerchantId);
            Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 1);
        } else {
            // Ensure flag not stale when verification fails/non-run
            Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 0);
        }
        
        // Save payment term type (STANDARD or EOM)
        $term_type = Tools::getValue('PS_TWO_PAYMENT_TERM_TYPE');
        if ($term_type === 'STANDARD' || $term_type === 'EOM') {
            Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', $term_type);
        }
        
        // Save payment terms checkboxes
        $payment_terms = array_map('strval', self::PAYMENT_TERMS_OPTIONS);
        foreach ($payment_terms as $term) {
            Configuration::updateValue('PS_TWO_PAYMENT_TERMS_' . $term, Tools::getValue('PS_TWO_PAYMENT_TERMS_' . $term) ? 1 : 0);
        }

        $this->output .= $this->displayConfirmation($this->l('General settings are updated.'));
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
                        'label' => $this->l('Pre-approve the buyer during checkout and disable two if the buyer is declined'),
                        'name' => 'PS_TWO_ENABLE_ORDER_INTENT',
                        'is_bool' => true,
                        'desc' => $this->l('If you choose YES then pre-approve the buyer during checkout and disable two if the buyer is declined.'),
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'PS_TWO_ENABLE_ORDER_INTENT_ON',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'PS_TWO_ENABLE_ORDER_INTENT_OFF',
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
        $fields_values['PS_TWO_ENABLE_ORDER_INTENT'] = Tools::getValue('PS_TWO_ENABLE_ORDER_INTENT', Configuration::get('PS_TWO_ENABLE_ORDER_INTENT'));
        $fields_values['PS_TWO_ENABLE_B2B_B2C'] = Tools::getValue('PS_TWO_ENABLE_B2B_B2C', Configuration::get('PS_TWO_ENABLE_B2B_B2C'));
        $fields_values['PS_TWO_DISABLE_SSL_VERIFY'] = Tools::getValue('PS_TWO_DISABLE_SSL_VERIFY', Configuration::get('PS_TWO_DISABLE_SSL_VERIFY'));
        $fields_values['PS_TWO_DEBUG_MODE'] = Tools::getValue('PS_TWO_DEBUG_MODE', Configuration::get('PS_TWO_DEBUG_MODE'));
        return $fields_values;
    }

    protected function validTwoOtherFormValues()
    {
        // No validation needed for current Other Settings
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
        Configuration::updateValue('PS_TWO_ENABLE_ORDER_INTENT', Tools::getValue('PS_TWO_ENABLE_ORDER_INTENT'));
        Configuration::updateValue('PS_TWO_ENABLE_B2B_B2C', Tools::getValue('PS_TWO_ENABLE_B2B_B2C'));
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
                        $payment_data = array(
                            'two_order_id' => $response['id'],
                            'two_order_reference' => $response['merchant_reference'],
                            'two_order_state' => $response['state'],
                            'two_order_status' => $response['status'],
                            'two_day_on_invoice' => (string)$this->getSelectedPaymentTerm(), // Selected payment term
                            'two_payment_term_type' => Configuration::get('PS_TWO_PAYMENT_TERM_TYPE'), // Term type (STANDARD or EOM)
                            'two_invoice_url' => $response['invoice_url'],
                            'two_invoice_id' => isset($response['invoice_details']['id']) ? $response['invoice_details']['id'] : (isset($orderpaymentdata['two_invoice_id']) ? $orderpaymentdata['two_invoice_id'] : null),
                        );
                        $this->setTwoOrderPaymentData($id_order, $payment_data);
                    }
                } else if ($this->isFulfillmentTriggerStatus($new_order_status->id) && $this->finalize_purchase_shipping) {
                    // Complete fulfillment using the new fulfillments endpoint - wrapped in try-catch for safety
                    try {
                        PrestaShopLogger::addLog('TwoPayment: Initiating complete fulfillment for Two order ID: ' . $two_order_id . ', Order ID: ' . $id_order . ', Triggered by status: ' . $new_order_status->name . ' (ID: ' . $new_order_status->id . ')', 1);
                        
                        // Validate order state before attempting fulfillment
                        $current_two_order = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
                        if (!$current_two_order || !isset($current_two_order['state'])) {
                            PrestaShopLogger::addLog('TwoPayment: Cannot retrieve Two order state for fulfillment. Two order ID: ' . $two_order_id, 3);
                            return;
                        }
                        
                        // Only attempt fulfillment if order is in CONFIRMED state
                        // Only CONFIRMED orders can be fulfilled (VERIFIED orders must be confirmed first to ensure they have been sent to the checkout success page)
                        if ($current_two_order['state'] !== 'CONFIRMED') {
                            PrestaShopLogger::addLog('TwoPayment: Two order not in fulfillable state. Current state: ' . $current_two_order['state'] . ', Expected: CONFIRMED. Two order ID: ' . $two_order_id, 2);
                            return;
                        }
                        
                        $response = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id . '/fulfillments', [], 'POST');
                        
                        if (isset($response['fulfilled_order']['id']) && $response['fulfilled_order']['id']) {
                            PrestaShopLogger::addLog('TwoPayment: Fulfillment successful for Two order ID: ' . $two_order_id . ', Fulfilled order ID: ' . $response['fulfilled_order']['id'], 1);
                            // Refresh order data from Two to avoid overwriting the stored Two order ID with fulfillment ID
                            $order_after = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
                            if (isset($order_after['id']) && $order_after['id']) {
                                $payment_data = array(
                                    'two_order_id' => $two_order_id,
                                    'two_order_reference' => isset($order_after['merchant_reference']) ? $order_after['merchant_reference'] : (isset($orderpaymentdata['two_order_reference']) ? $orderpaymentdata['two_order_reference'] : ''),
                                    'two_order_state' => isset($order_after['state']) ? $order_after['state'] : (isset($orderpaymentdata['two_order_state']) ? $orderpaymentdata['two_order_state'] : ''),
                                    'two_order_status' => isset($order_after['status']) ? $order_after['status'] : (isset($orderpaymentdata['two_order_status']) ? $orderpaymentdata['two_order_status'] : ''),
                                    'two_day_on_invoice' => (string)$this->getSelectedPaymentTerm(), // Selected payment term
                                    'two_payment_term_type' => Configuration::get('PS_TWO_PAYMENT_TERM_TYPE'), // Term type (STANDARD or EOM)
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
                            PrestaShopLogger::addLog('TwoPayment: Fulfillment failed for Two order ID: ' . $two_order_id . ', Error: ' . $error_message . ', Response: ' . json_encode($response), 3);
                            
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
                                $payment_data = array(
                                    'two_order_id' => $two_order_id,
                                    'two_order_reference' => isset($order_after['merchant_reference']) ? $order_after['merchant_reference'] : (isset($orderpaymentdata['two_order_reference']) ? $orderpaymentdata['two_order_reference'] : ''),
                                    'two_order_state' => isset($order_after['state']) ? $order_after['state'] : (isset($orderpaymentdata['two_order_state']) ? $orderpaymentdata['two_order_state'] : ''),
                                    'two_order_status' => isset($order_after['status']) ? $order_after['status'] : (isset($orderpaymentdata['two_order_status']) ? $orderpaymentdata['two_order_status'] : ''),
                                    'two_day_on_invoice' => (string)$this->getSelectedPaymentTerm(),
                                    'two_payment_term_type' => Configuration::get('PS_TWO_PAYMENT_TERM_TYPE'), // Term type (STANDARD or EOM)
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
                            $log_message .= ', Response: ' . json_encode($response);
                            
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
        
        // Layer 3: GUARANTEED CDN fallback (critical for PrestaShop 1.7.6.5 compatibility)
        // This ensures jQuery loads even when PrestaShop's methods fail silently
        // Uses official jQuery CDN with crossorigin for security
        try {
            $this->context->controller->addJS(
                'https://code.jquery.com/jquery-3.6.0.min.js',
                false // Load in HEAD before other scripts
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Two Payment: CDN jQuery fallback failed - ' . $e->getMessage(),
                3, // Error level - this is critical
                null,
                'Module',
                $this->id
            );
        }

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
            'invoice_likely_accepted_for' => $this->l('Your invoice with Two is likely to be accepted for %s'),
            'invoice_cannot_be_approved_for' => $this->l('Your invoice with Two cannot be approved at this time for %s'),
            'invalid_phone_number' => $this->l('The phone number in your billing address appears to be invalid. Please go back and ensure you have entered a valid phone number for your country.'),
            'company_name_required' => $this->l('To pay with Two, go back to your billing address and enter your company name in the Company field.'),
            'organization_number_required' => $this->l('Please search and select a valid company to continue with Two payment.'),
            'select_company_to_use_two' => $this->l('To pay with Two, go back to your billing address and search for your company name. Select your company from the results to verify your business.'),
            'invalid_company' => $this->l('The company information provided is not valid. Please search and select a valid company.'),
            'company_not_found' => $this->l('We could not find your company. Please try a different company name or contact support.'),
            'credit_unavailable' => $this->l('Two payment is not available for this order. Please choose another payment method.'),
            'network_issue' => $this->l('There was a temporary issue verifying your payment. Please try again or choose another payment method.'),
            'approval_required' => $this->l('Payment approval required before proceeding'),
            'invoice_declined' => $this->l('Your invoice with Two cannot be approved at this time. Please select an alternative payment method.'),
            'invalid_email' => $this->l('The email address provided is invalid. Please check your email and try again.'),
            'company_incomplete' => $this->l('Company information is incomplete. Go back to your billing address and select your company from the search results.'),
            'validation_error' => $this->l('Some of the information provided is invalid. Please check your billing address details and try again.'),
            'company_verify_failed' => $this->l('Company information could not be verified. Go back to your billing address and select your company from the search results.'),
            'company_verification_needed' => $this->l('Company Verification Needed'),
            'company_auto_resolve_hint' => $this->l('We found your company name but need you to verify it. Please go back to your billing address and select your company from the search results.'),
            'pay_in' => $this->l('Pay in'),
            'days' => $this->l('days'),
            'from_end_of_month' => $this->l('from end of month'),
        );

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

        // If merchant uses account type selection, gate payment option to business accounts
        if ((int) Configuration::get('PS_TWO_USE_ACCOUNT_TYPE')) {
            if (empty($billing_address->account_type) || $billing_address->account_type !== 'business') {
                PrestaShopLogger::addLog('TwoPayment: Payment option hidden - account type is not business (current: ' . ($billing_address->account_type ?: 'not set') . ')', 1);
                return [];
            }
            PrestaShopLogger::addLog('TwoPayment: Payment option shown for business account', 1);
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



    public function getTwoIntentOrderData($cart, $customer, $currency, $address)
    {
        // Validate cart has products before building order data
        if (!Validate::isLoadedObject($cart) || $cart->nbProducts() <= 0) {
            PrestaShopLogger::addLog('TwoPayment: Cannot build order intent - cart is empty or invalid', 3);
            throw new Exception('Cart is empty or invalid');
        }
        
        // Get line items (using PrestaShop's native values)
        $line_items = $this->getTwoProductItems($cart);
        
        // Validate we have line items
        if (empty($line_items)) {
            PrestaShopLogger::addLog('TwoPayment: Cannot build order intent - no valid line items', 3);
            throw new Exception('No valid line items in cart');
        }
        
        // Calculate tax subtotals from line items first
        $tax_subtotals = $this->getTwoTaxSubtotals($line_items);
        
        // Calculate totals from tax_subtotals to ensure exact match
        $totals = $this->calculateOrderTotalsFromTaxSubtotals($tax_subtotals);
        $final_net = $totals['net'];
        $final_tax = $totals['tax'];
        $final_gross = $totals['gross'];
        
        // Get discount amount from PrestaShop (Two API expects positive discount amount)
        $final_discount = abs((float)$cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS));
        
        // Get company data with fallback chain
        $companyData = $this->getCompanyDataWithFallbacks($address);

        $request_data = [
            'gross_amount' => (string)($this->getTwoRoundAmount($final_gross)),
            'net_amount' => (string)($this->getTwoRoundAmount($final_net)),
            'tax_amount' => (string)($this->getTwoRoundAmount($final_tax)),
            'discount_amount' => (string)($this->getTwoRoundAmount($final_discount)),
            'tax_subtotals' => $tax_subtotals,
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
                    'phone_number' => $this->getPhoneWithFallback($address),
                ],
            ],
            'currency' => $currency->iso_code,
            'merchant_short_name' => $this->merchant_short_name,
            'invoice_type' => 'FUNDED_INVOICE', // Default product type
            'line_items' => $line_items,
        ];

        return $request_data;
    }

    public function getTwoNewOrderData($id_order, $cart)
    {
        // Validate cart has products before building order data
        if (!Validate::isLoadedObject($cart) || $cart->nbProducts() <= 0) {
            PrestaShopLogger::addLog('TwoPayment: Cannot build order data - cart is empty or invalid (Order ID: ' . $id_order . ')', 3);
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

        // Get line items (using PrestaShop's native values)
        $line_items = $this->getTwoProductItems($cart);
        
        // Validate we have line items
        if (empty($line_items)) {
            PrestaShopLogger::addLog('TwoPayment: Cannot build order data - no valid line items (Order ID: ' . $id_order . ')', 3);
            throw new Exception('No valid line items in cart');
        }
        
        // Calculate tax subtotals from line items first
        $tax_subtotals = $this->getTwoTaxSubtotals($line_items);
        
        // Two API requires gross_amount = sum(tax_subtotals)
        // Calculate totals from tax_subtotals to ensure exact match
        $totals = $this->calculateOrderTotalsFromTaxSubtotals($tax_subtotals);
        $final_net = $totals['net'];
        $final_tax = $totals['tax'];
        $final_gross = $totals['gross'];
        
        // Get discount amount from PrestaShop
        $final_discount = abs((float)$cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS));

        // Get company data with fallback chain (reused helper method)
        $buyerData = $this->getCompanyDataWithFallbacks($invoice_address);
        $shippingData = $this->getCompanyDataWithFallbacks($delivery_address);
        $buyerCompanyName = $buyerData['company_name'];
        $shippingOrgName = !empty($shippingData['company_name']) ? $shippingData['company_name'] : $buyerCompanyName;

        $request_data = [
            'gross_amount' => (string)($this->getTwoRoundAmount($final_gross)),
            'net_amount' => (string)($this->getTwoRoundAmount($final_net)),
            'currency' => $currency->iso_code,
            'discount_amount' => (string)($this->getTwoRoundAmount($final_discount)), // Two API expects positive discount amount at order level (already abs() at line 1532)
            'discount_rate' => '0',
            'invoice_type' => 'FUNDED_INVOICE', // Default product type
            'tax_amount' => (string)($this->getTwoRoundAmount($final_tax)),
            'tax_rate' => (string)($cart->getAverageProductsTaxRate()),
            'tax_subtotals' => $tax_subtotals,
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
            'buyer_department' => $invoice_address->department,
            'buyer_project' => $invoice_address->project,
            'merchant_additional_info' => '',
            'merchant_order_id' => (string)($id_order),
            'merchant_reference' => (string)($order_reference),
            'merchant_urls' => [
                'merchant_confirmation_url' => $this->context->link->getModuleLink($this->name, 'confirmation', ['id_order' => $id_order], true),
                'merchant_cancel_order_url' => $this->context->link->getModuleLink($this->name, 'cancel', ['id_order' => $id_order], true),
                'merchant_edit_order_url' => '',
                'merchant_order_verification_failed_url' => '',
                'merchant_invoice_url' => '',
                'merchant_shipping_document_url' => ''
            ],
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

        // Get line items (using PrestaShop's native values)
        $line_items = $this->getTwoProductItems($cart);
        
        // Validate we have line items
        if (empty($line_items)) {
            PrestaShopLogger::addLog('TwoPayment: Cannot build update order data - no valid line items (Order ID: ' . $order->id . ')', 3);
            throw new Exception('No valid line items in cart');
        }
        
        // Calculate tax subtotals from line items first
        $tax_subtotals = $this->getTwoTaxSubtotals($line_items);
        
        // Calculate totals from tax_subtotals to ensure exact match
        $totals = $this->calculateOrderTotalsFromTaxSubtotals($tax_subtotals);
        $final_net = $totals['net'];
        $final_tax = $totals['tax'];
        $final_gross = $totals['gross'];
        
        // Get discount amount from PrestaShop
        $final_discount = abs((float)$cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS));

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
            'tax_rate' => (string)($cart->getAverageProductsTaxRate()),
            'tax_subtotals' => $tax_subtotals,
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
            'buyer_department' => $invoice_address->department,
            'buyer_project' => $invoice_address->project,
            'merchant_additional_info' => '',
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
        
        //  Validate cart has products
        if (empty($line_items)) {
            PrestaShopLogger::addLog('TwoPayment: Cart is empty, cannot build line items', 3);
            return $items; // Return empty array (caller should handle empty cart)
        }
        
        foreach ($line_items as $line_item) {
            $categories = Product::getProductCategoriesFull($line_item['id_product'], $cart->id_lang);
            $image = Image::getCover($line_item['id_product']);
            $imagePath = $this->context->link->getImageLink($line_item['link_rewrite'], $image['id_image'], ImageType::getFormattedName('home'));
            
            // Use PrestaShop's calculated values directly (trust PrestaShop's calculations)
            $net_amount_prestashop = (float)$line_item['total']; // PrestaShop's net total (source of truth)
            $gross_amount_prestashop = (float)$line_item['total_wt']; // PrestaShop's gross total
            $quantity = (int)$line_item['cart_quantity'];
            
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
            
            // BEST PRACTICE: TAX RATE FROM PRESTASHOP'S NATIVE FIELD
            // PrestaShop provides the 'rate' field in cart products which is the configured tax rate
            // This is more accurate than calculating from amounts (avoids rounding errors)
            
            // Step 1: Get tax rate from PrestaShop's native rate field (PRIMARY SOURCE)
            $rate_from_field = isset($line_item['rate']) ? (float)$line_item['rate'] : 0;
            $tax_rate_decimal = $rate_from_field / 100; // Convert percentage to decimal
            
            // Step 2: Validate against actual amounts (VERIFICATION)
            // Calculate what the rate would be based on actual charged amounts
            $rate_from_amounts_decimal = 0;
            if ($net_amount_prestashop > 0 && $gross_amount_prestashop > $net_amount_prestashop) {
                $rate_from_amounts_decimal = ($gross_amount_prestashop - $net_amount_prestashop) / $net_amount_prestashop;
            }
            
            // Step 3: Decision logic - prefer native field, fall back to calculated when necessary
            $tax_rate = $tax_rate_decimal; // Default: use PrestaShop's configured rate
            
            // Handle edge cases where native field and amounts disagree
            if ($rate_from_field > 0 && $rate_from_amounts_decimal == 0) {
                // Tax rule is configured but no tax was actually applied (e.g., tax-exempt customer)
                // Use 0 because that's what the customer is actually paying
                $tax_rate = 0;
                if (Configuration::get('PS_TWO_DEBUG_MODE')) {
                    PrestaShopLogger::addLog(
                        'TwoPayment: Tax rate override - configured rate ' . $rate_from_field . '% but no tax in amounts. ' .
                        'Product: ' . $line_item['id_product'] . ' | Using 0% (customer not charged tax)',
                        1
                    );
                }
            } elseif ($rate_from_field == 0 && $rate_from_amounts_decimal > 0) {
                // No rate field but tax was applied (rare edge case)
                // Use calculated rate as fallback
                $tax_rate = round($rate_from_amounts_decimal, 4);
                PrestaShopLogger::addLog(
                    'TwoPayment: Tax rate fallback - rate field is 0 but tax was applied. ' .
                    'Product: ' . $line_item['id_product'] . ' | Using calculated: ' . round($rate_from_amounts_decimal * 100, 2) . '%',
                    2
                );
            } elseif ($rate_from_field > 0 && abs($tax_rate_decimal - $rate_from_amounts_decimal) > 0.005) {
                // Both exist but differ significantly (more than 0.5% difference)
                // Use the native field rate but log for investigation
                // The amounts may differ due to rounding, but the configured rate is canonical
                if (Configuration::get('PS_TWO_DEBUG_MODE')) {
                    PrestaShopLogger::addLog(
                        'TwoPayment: Tax rate variance - field: ' . $rate_from_field . '%, amounts: ' . 
                        round($rate_from_amounts_decimal * 100, 2) . '% | Product: ' . $line_item['id_product'] . 
                        ' | Using configured rate (rounding variance expected)',
                        1
                    );
                }
            }
            
            // CRITICAL: Validate quantity to prevent division by zero
            if ($quantity <= 0) {
                PrestaShopLogger::addLog('TwoPayment: Invalid quantity (0 or negative) for product ' . $line_item['id_product'], 3);
                continue; // Skip invalid line items
            }
            
            // Use PrestaShop's unit price if available, otherwise calculate from total
            // PrestaShop's 'price' field is the unit price (net, without tax)
            $unit_price_net_prestashop = isset($line_item['price']) ? (float)$line_item['price'] : null;
            
            if ($unit_price_net_prestashop !== null) {
                // Use PrestaShop's unit price directly (most accurate)
                $unit_price_net = round($unit_price_net_prestashop, 2);
                
                // Calculate discount from PrestaShop's values
                // Expected total without discount: quantity * unit_price
                $expected_total = $quantity * $unit_price_net;
                $discount_amount = round($expected_total - $net_amount_prestashop, 2);
                
                // Ensure discount is not negative (protect against edge cases)
                if ($discount_amount < 0) {
                    PrestaShopLogger::addLog('TwoPayment: Negative discount calculated for product ' . $line_item['id_product'] . ', clamping to 0', 2);
                    $discount_amount = 0;
                }
                
                // Use PrestaShop's net_amount directly (it's the source of truth)
                $net_amount = $net_amount_prestashop;
            } else {
                // Fallback: derive unit_price from net_amount (if PrestaShop doesn't provide price)
                // This happens when PrestaShop's price field is not available
                $discount_amount = isset($line_item['reduction']) ? (float)$line_item['reduction'] : 0;
                
                // Ensure discount is not negative
                if ($discount_amount < 0) {
                    $discount_amount = 0;
                }
                
                // Two API requires exact formula: net_amount = (quantity * unit_price) - discount_amount
                // Derive unit_price from net_amount to ensure formula compliance
                $unit_price_net = ($net_amount_prestashop + $discount_amount) / $quantity;
                $unit_price_net = round($unit_price_net, 2);
                
                // Recalculate net_amount with rounded unit_price to ensure exact formula match
                $net_amount = ($quantity * $unit_price_net) - $discount_amount;
                $net_amount = round($net_amount, 2);
            }
            
            // Calculate tax using Two's formula: tax_amount = net_amount * tax_rate
            $tax_amount = round($net_amount * $tax_rate, 2);
            
            // Use PrestaShop's gross_amount if it matches our calculation (within rounding tolerance)
            // Otherwise use calculated gross_amount to satisfy Two API formula
            $calculated_gross = $net_amount + $tax_amount;
            $gross_diff = abs($gross_amount_prestashop - $calculated_gross);
            $gross_tolerance = self::GROSS_AMOUNT_TOLERANCE;
            
            if ($gross_diff <= $gross_tolerance) {
                // PrestaShop's gross is very close, use it (matches what customer sees)
                $gross_amount = $gross_amount_prestashop;
            } else {
                // Use calculated gross to satisfy Two API formula
                // Log when tolerance is exceeded for investigation
                PrestaShopLogger::addLog(
                    'TwoPayment: Gross amount difference exceeds tolerance for product ' . $line_item['id_product'] . 
                    ' - PrestaShop: ' . $gross_amount_prestashop . ', Calculated: ' . $calculated_gross . ', Diff: ' . $gross_diff,
                    2
                );
                $gross_amount = $calculated_gross;
            }
            
            // Calculate actual tax rate percentage for display (tax_class_name)
            $tax_rate_percent_display = round($tax_rate * 100, 2);
            
            $product = [
                'name' => $line_item['name'],
                'description' => Tools::substr(strip_tags($line_item['description_short']), 0, 255),
                'gross_amount' => (string)$this->getTwoRoundAmount($gross_amount),
                'net_amount' => (string)$this->getTwoRoundAmount($net_amount),
                'discount_amount' => (string)$this->getTwoRoundAmount($discount_amount),
                'tax_amount' => (string)$this->getTwoRoundAmount($tax_amount),
                'tax_class_name' => 'VAT ' . $this->getTwoRoundAmount($tax_rate_percent_display) . '%',
                'tax_rate' => (string)$this->getTwoRoundAmount($tax_rate),
                'unit_price' => (string)$this->getTwoRoundAmount($unit_price_net),
                'quantity' => $quantity,
                'quantity_unit' => 'pcs',
                'image_url' => $imagePath,
                'product_page_url' => $this->context->link->getProductLink($line_item['id_product']),
                'type' => 'PHYSICAL',
                'details' => [
                    'brand' => $line_item['manufacturer_name'],
                    'barcodes' => [
                        [
                            'type' => 'SKU',
                            'value' => $line_item['ean13']
                        ],
                        [
                            'type' => 'UPC',
                            'value' => $line_item['upc']
                        ],
                    ],
                ],
            ];
            $product['details']['categories'] = [];
            if ($categories) {
                foreach ($categories as $category) {
                    $product['details']['categories'][] = $category['name'];
                }
            }

            $items[] = $product;
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
            // Use PrestaShop's shipping totals (source of truth)
            $shipping_net = round($shipping_cost_without_tax, 2);
            $shipping_gross_prestashop = $shipping_cost_with_tax;
            
            // BEST PRACTICE: Get tax rate from carrier's tax configuration instead of calculating from amounts
            // This uses PrestaShop's native tax system for accuracy
            $shipping_tax_rate_decimal = 0;
            $shipping_tax_rate_percent = 0;
            
            // Try to get tax rate from carrier's tax rules group (PrestaShop native method)
            $carrier_tax_rules_group_id = $carrier->getIdTaxRulesGroup();
            if ($carrier_tax_rules_group_id > 0) {
                // Get delivery address for tax calculation
                $delivery_address = new Address($cart->id_address_delivery);
                if (Validate::isLoadedObject($delivery_address)) {
                    // Use PrestaShop's TaxManagerFactory to get the correct tax calculator
                    $tax_manager = TaxManagerFactory::getManager(
                        $delivery_address,
                        $carrier_tax_rules_group_id
                    );
                    $tax_calculator = $tax_manager->getTaxCalculator();
                    
                    // Get the total tax rate from the calculator
                    $shipping_tax_rate_percent = $tax_calculator->getTotalRate();
                    $shipping_tax_rate_decimal = $shipping_tax_rate_percent / 100;
                }
            }
            
            // Fallback: Calculate rate from amounts if native method didn't work
            if ($shipping_tax_rate_decimal == 0 && $shipping_net > 0 && $shipping_gross_prestashop > $shipping_net) {
                $shipping_tax_rate_decimal = ($shipping_gross_prestashop - $shipping_net) / $shipping_net;
                $shipping_tax_rate_percent = round($shipping_tax_rate_decimal * 100, 2);
                $shipping_tax_rate_decimal = round($shipping_tax_rate_decimal, 4);
            }
            
            // Two API requires exact formula: tax_amount = net_amount * tax_rate
            // Recalculate tax_amount using the tax rate to ensure formula compliance
            $shipping_tax_amount = round($shipping_net * $shipping_tax_rate_decimal, 2);
            
            // Calculate gross: gross_amount = net_amount + tax_amount (Two API formula)
            $shipping_gross = $shipping_net + $shipping_tax_amount;
            
            // For shipping: quantity = 1, discount = 0, so unit_price = net_amount
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
                'tax_rate' => (string)$this->getTwoRoundAmount($shipping_tax_rate_decimal),
                'unit_price' => (string)$this->getTwoRoundAmount($shipping_unit_price),
                'quantity' => 1,
                'quantity_unit' => 'pcs',
                'image_url' => '',
                'product_page_url' => '',
                'type' => 'SHIPPING_FEE'
            ];

            $items[] = $shipping_line;
        }

        // Add cart-level discounts as line item if applicable
        // Note: PrestaShop returns discounts as positive values (the amount discounted)
        $discount_gross_prestashop = (float)$cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
        if ($discount_gross_prestashop > 0) {
            // Use PrestaShop's discount totals (source of truth)
            $discount_net_total = round((float)$cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS), 2);
            $discount_tax_prestashop = $discount_gross_prestashop - $discount_net_total;
            
            // Calculate tax rate from PrestaShop values (handle edge case where net_total might be 0)
            $discount_tax_rate_percent = 0;
            $discount_tax_rate_decimal = 0;
            if ($discount_net_total > 0) {
                // Calculate percentage first, then round, then convert to decimal
                // This preserves precision for non-standard tax rates (e.g., 20.5%)
                $discount_tax_rate_percent = ($discount_tax_prestashop / $discount_net_total) * 100;
                $discount_tax_rate_percent = round($discount_tax_rate_percent, 2); // Round percentage to 2 decimals
                $discount_tax_rate_decimal = $discount_tax_rate_percent / 100; // Convert to decimal
            } elseif ($discount_tax_prestashop > 0) {
                // Edge case: net_total = 0 but tax exists (shouldn't happen, but handle gracefully)
                // Cannot calculate percentage, default to 0 (tax_amount will be 0 anyway)
                PrestaShopLogger::addLog('TwoPayment: Discount net_total is 0 but tax exists, defaulting tax rate to 0', 2);
            }
            
            // Two API requires exact formula: tax_amount = net_amount * tax_rate
            // Recalculate tax_amount using rounded values to ensure formula compliance
            // Note: net_amount is negative (discount), so tax_amount will also be negative
            $discount_tax_amount = round($discount_net_total * $discount_tax_rate_decimal, 2);
            
            // Calculate gross: gross_amount = net_amount + tax_amount (Two API formula)
            $discount_gross_total = $discount_net_total + $discount_tax_amount;
            
            // For discount: quantity = 1, discount = 0, so unit_price = net_amount (negative)
            $discount_unit_price = $discount_net_total;
            
            $cart_rules = $cart->getCartRules();
            $discount_name = $this->l('Discount');
            $discount_description = $this->l('Order discount');
            
            if (!empty($cart_rules)) {
                $primary_rule = reset($cart_rules);
                $discount_name = $primary_rule['name'];
                
                $discount_parts = [];
                foreach ($cart_rules as $rule) {
                    $rule_desc = $rule['name'];
                    if ($rule['code']) {
                        $rule_desc .= ' (' . $rule['code'] . ')';
                    }
                    if ($rule['value']) {
                        if ($rule['reduction_percent'] > 0) {
                            $rule_desc .= ' - ' . $rule['reduction_percent'] . '%';
                        } elseif ($rule['reduction_amount'] > 0) {
                            $rule_desc .= ' - ' . Tools::displayPrice($rule['reduction_amount']);
                        }
                    }
                    $discount_parts[] = $rule_desc;
                }
                
                $discount_description = implode(', ', $discount_parts);
                if (strlen($discount_description) > 200) {
                    $discount_description = $primary_rule['description'] ? 
                        Tools::substr(strip_tags($primary_rule['description']), 0, 200) : 
                        sprintf($this->l('Discount: %s'), $primary_rule['name']);
                }
                
                if (count($cart_rules) > 1) {
                    $discount_name = sprintf($this->l('%s (+%d more)'), $primary_rule['name'], count($cart_rules) - 1);
                }
            }
            
            $discount_line = [
                'name' => $discount_name,
                'description' => Tools::substr(strip_tags($discount_description), 0, 255),
                'gross_amount' => (string)$this->getTwoRoundAmount(-$discount_gross_total),
                'net_amount' => (string)$this->getTwoRoundAmount(-$discount_net_total),
                'discount_amount' => '0.00',
                'tax_amount' => (string)$this->getTwoRoundAmount(-$discount_tax_amount),
                'tax_class_name' => 'VAT ' . $this->getTwoRoundAmount($discount_tax_rate_percent) . '%',
                'tax_rate' => (string)$this->getTwoRoundAmount($discount_tax_rate_decimal),
                'unit_price' => (string)$this->getTwoRoundAmount(-$discount_unit_price),
                'quantity' => 1,
                'quantity_unit' => 'item',
                'image_url' => '',
                'product_page_url' => '',
                'type' => 'DIGITAL'
            ];

            $items[] = $discount_line;
        }

        return $items;
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
        
        // Priority 1: Session cookie (from company search - already verified)
        if (!empty($this->context->cookie->two_company_id) && !empty($this->context->cookie->two_company_name)) {
            return [
                'company_name' => trim($this->context->cookie->two_company_name),
                'organization_number' => trim($this->context->cookie->two_company_id),
                'country_iso' => $country_iso
            ];
        }
        
        // Priority 2: Extract org number from address fields (dni, vat_number, companyid)
        // This uses the enhanced extraction method that works across all countries
        $org_number = $this->extractOrgNumberFromAddress($address, $country_iso);
        
        // Company name: Address → Cookie
        $company_name = !empty($address->company) 
            ? $address->company 
            : (isset($this->context->cookie->two_company_name) 
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
            $tax_rate = (string)$item['tax_rate'];
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
                'tax_rate' => (string)($this->getTwoRoundAmount((float)$rate)), // Rate is already in decimal format
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
     * Format amount to 2 decimals as string (Two API requirement)
     * PrestaShop values are already rounded, this just formats for Two API
     * Uses standard PHP number_format - no need for PrestaShop's rounding methods
     */
    public function getTwoRoundAmount($amount)
    {
        return number_format((float)$amount, 2, '.', '');
    }

    public function getTwoCheckoutHostUrl()
    {
        $environment = Configuration::get('PS_TWO_ENVIRONMENT');
        
        if ($environment === 'production') {
            return 'https://api.two.inc';
        } else {
            // Development environment (default)
            return 'https://api.sandbox.two.inc';
        }
    }

    /**
     * Get base API host for a specific environment value (without relying on saved config)
     */
    private function getTwoCheckoutHostUrlForEnvironment($environment)
    {
        return ($environment === 'production') ? 'https://api.two.inc' : 'https://api.sandbox.two.inc';
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
        $environment = Configuration::get('PS_TWO_ENVIRONMENT');
        
        if ($environment === 'production') {
            return 'https://portal.two.inc';
        } else {
            // Development environment (default)
            return 'https://portal.sandbox.two.inc';
        }
    }

    /**
     * Get the Two buyer portal login URL based on environment
     * @return string Buyer portal login URL for the current environment
     */
    public function getTwoBuyerPortalUrl()
    {
        $base = $this->getTwoPortalUrl();
        return rtrim($base, '/') . '/auth/buyer/login';
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
     * Get available payment terms configured by the merchant
     * @return array Array of available payment terms in days
     */
    /**
     * Get available payment terms filtered by term type (STANDARD or EOM)
     * @return array Array of available payment term durations (e.g., [30, 45, 60])
     */
    public function getAvailablePaymentTerms()
    {
        $term_type = Configuration::get('PS_TWO_PAYMENT_TERM_TYPE');
        
        // Determine which terms to check based on type
        if ($term_type === 'EOM') {
            // EOM only supports 30, 45, 60 day terms
            $terms_to_check = array('30', '45', '60');
        } else {
            // STANDARD supports all terms
            $terms_to_check = array_map('strval', self::PAYMENT_TERMS_OPTIONS);
        }
        
        $available_terms = array();
        
        foreach ($terms_to_check as $term) {
            if (Configuration::get('PS_TWO_PAYMENT_TERMS_' . $term)) {
                $available_terms[] = (int)$term;
            }
        }
        
        // If no terms are configured, default to DEFAULT_PAYMENT_TERM_DAYS
        if (empty($available_terms)) {
            $available_terms = array(self::DEFAULT_PAYMENT_TERM_DAYS);
        }
        
        sort($available_terms); // Ensure they're in ascending order
        return $available_terms;
    }

    /**
     * Get the default payment term (first available term or 30 days)
     * @return int Default payment term in days
     */
    public function getDefaultPaymentTerm()
    {
        $available_terms = $this->getAvailablePaymentTerms();
        
        // If only one term is available, use it as default
        if (count($available_terms) === 1) {
            return $available_terms[0];
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
        $selected_term = (int)$this->context->cookie->two_payment_term;
        
        // If not found, try to get from browser cookies
        if (!$selected_term && isset($_COOKIE['two_payment_term'])) {
            $selected_term = (int)$_COOKIE['two_payment_term'];
        }
        
        PrestaShopLogger::addLog('TwoPayment: Getting payment term - Context Cookie: ' . $this->context->cookie->two_payment_term . ', Browser Cookie: ' . (isset($_COOKIE['two_payment_term']) ? $_COOKIE['two_payment_term'] : 'not set') . ', Selected: ' . $selected_term . ', Available: ' . implode(',', $available_terms) . ', Default: ' . $default_term, 1);
        
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

    public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [])
    {
        if ($method == "POST" || $method == "PUT") {
            $url = sprintf('%s%s', $this->getTwoCheckoutHostUrl(), $endpoint);
            $url = $url . '?client=PS&client_v=' . $this->version;
            $params = empty($payload) ? '' : json_encode($payload);
            $headers = [
                'Content-Type: application/json; charset=utf-8',
                'X-API-Key:' . $this->api_key,
            ];
            
            // Merge additional headers (e.g., idempotency key)
            if (!empty($additional_headers) && is_array($additional_headers)) {
                $headers = array_merge($headers, $additional_headers);
            }
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::API_TIMEOUT_LONG);
            
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
            $headers = [
                'Content-Type: application/json; charset=utf-8',
                'X-API-Key:' . $this->api_key,
            ];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::API_TIMEOUT_LONG);
            
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
        
        if ($disable_ssl_verify) {
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

        if (isset($body['response']['code']) && $body['response'] && $body['response']['code'] && $body['response']['code'] >= self::HTTP_STATUS_BAD_REQUEST) {
            return sprintf($this->l('Two response code %d'), $body['response']['code']);
        }

        if (is_string($body)) {
            // ENHANCED: Parse validation errors and return user-friendly messages
            $friendly_message = $this->parseValidationErrorToFriendlyMessage($body);
            if ($friendly_message) {
                return $friendly_message;
            }
            return $body;
        }

        if (isset($body['error_details']) && $body['error_details']) {
            // ENHANCED: Parse validation errors in error_details
            $friendly_message = $this->parseValidationErrorToFriendlyMessage($body['error_details']);
            if ($friendly_message) {
                return $friendly_message;
            }
            return $body['error_details'];
        }

        if (isset($body['error_code']) && $body['error_code']) {
            // ENHANCED: Parse validation errors in error_message
            if (isset($body['error_message'])) {
                $friendly_message = $this->parseValidationErrorToFriendlyMessage($body['error_message']);
                if ($friendly_message) {
                    return $friendly_message;
                }
            }
            return $body['error_message'];
        }
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
            // Generate PDF URL if Two order ID is available
            $pdf_url = null;
            if (!empty($twopaymentdata['two_order_id'])) {
                $pdf_url = $this->getTwoPdfUrl($twopaymentdata['two_order_id']);
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
            // Generate PDF URL if Two order ID is available
            $pdf_url = null;
            if (!empty($twopaymentdata['two_order_id'])) {
                $pdf_url = $this->getTwoPdfUrl($twopaymentdata['two_order_id']);
            }
            
            $this->context->smarty->assign(array(
                'twopaymentdata' => $twopaymentdata,
                'two_portal_url' => $this->getTwoPortalUrl(), // Dynamic portal URL based on environment
                'two_pdf_url' => $pdf_url, // PDF invoice URL if available
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
            // Generate PDF URL if Two order ID is available
            $pdf_url = null;
            if (!empty($twopaymentdata['two_order_id'])) {
                $pdf_url = $this->getTwoPdfUrl($twopaymentdata['two_order_id']);
            }
            
            $this->context->smarty->assign(array(
                'twopaymentdata' => $twopaymentdata,
                'two_portal_url' => $this->getTwoPortalUrl(), // Dynamic portal URL based on environment
                'two_pdf_url' => $pdf_url, // PDF invoice URL if available
                'use_own_invoices' => (bool)Configuration::get('PS_TWO_USE_OWN_INVOICES'),
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
            // VAT numbers often have country prefix - strip it if present
            if (preg_match('/^[A-Z]{2}(.+)$/i', $vatNumber, $matches)) {
                $vatNumber = $matches[1];
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

