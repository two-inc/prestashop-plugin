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

    protected $output = '';
    protected $errors = array();
    protected $verifiedMerchantId = null;
    protected $verifiedMerchantShortName = null;

    public function __construct()
    {
        $this->name = 'twopayment';
        $this->tab = 'payments_gateways';
        $this->version = '2.1.2';
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
        $this->api_key = Configuration::get('PS_TWO_MERACHANT_API_KEY');
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
            $this->registerHook('actionOrderSlipAdd') &&
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
            $installData['PS_TWO_SUB_TITLE'][(int) $language['id_lang']] = 'Receive the invoice via EHF and PDF';
        }
        Configuration::updateValue('PS_TWO_TAB_VALUE', 1);
        Configuration::updateValue('PS_TWO_TITLE', $installData['PS_TWO_TITLE']);
        Configuration::updateValue('PS_TWO_SUB_TITLE', $installData['PS_TWO_SUB_TITLE']);
        Configuration::updateValue('PS_TWO_ENVIRONMENT', 'development'); // Default to development for safety
        Configuration::updateValue('PS_TWO_MERCHANT_SHORT_NAME', '');
        Configuration::updateValue('PS_TWO_MERACHANT_API_KEY', '');
        Configuration::updateValue('PS_TWO_MERCHANT_ID', '');
        Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 0);
        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', 1);
        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_ID', 1);
        Configuration::updateValue('PS_TWO_FINALIZE_PURCHASE', 1);
        Configuration::updateValue('PS_TWO_ENABLE_ORDER_INTENT', 1);
        Configuration::updateValue('PS_TWO_USE_ACCOUNT_TYPE', 0);
        Configuration::updateValue('PS_TWO_PAYMENT_TERMS_30', 1); // Default: 30 days enabled
        // Custom Two order states will be created by createTwoOrderState()
        // Set sensible default mappings to standard PrestaShop states
        // Processing states default to their Two-branded states out-of-the-box
        Configuration::updateValue('PS_TWO_OS_AWAITING_VERIFICATION_MAP', Configuration::get('PS_TWO_OS_AWAITING_VERIFICATION'));
        Configuration::updateValue('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP', Configuration::get('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT'));
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', Configuration::get('PS_OS_SHIPPING')); // "Shipped"
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
            `two_invoice_url` TEXT NULL,
            PRIMARY KEY  (`id_two`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

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
            $this->unregisterHook('actionOrderSlipAdd') &&
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
        Configuration::deleteByName('PS_TWO_MERACHANT_API_KEY');
        Configuration::deleteByName('PS_TWO_MERCHANT_ID');
        Configuration::deleteByName('PS_TWO_API_KEY_VERIFIED');
        Configuration::deleteByName('PS_TWO_ENABLE_COMPANY_NAME');
        Configuration::deleteByName('PS_TWO_ENABLE_COMPANY_ID');
        Configuration::deleteByName('PS_TWO_ENABLE_DEPARTMENT');
        Configuration::deleteByName('PS_TWO_ENABLE_PROJECT');
        Configuration::deleteByName('PS_TWO_FINALIZE_PURCHASE');
        Configuration::deleteByName('PS_TWO_ENABLE_ORDER_INTENT');
        Configuration::deleteByName('PS_TWO_USE_ACCOUNT_TYPE');
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
                        'name' => 'PS_TWO_MERACHANT_API_KEY',
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
                        'type' => 'checkbox',
                        'label' => $this->l('Available Payment Terms'),
                        'name' => 'PS_TWO_PAYMENT_TERMS',
                        'desc' => $this->l('Select which payment terms you want to offer to your customers at checkout. If only one term is selected, it will be used as the default. Multiple terms will show a selector.'),
                        'values' => array(
                            'query' => array(
                                array(
                                    'id' => '7',
                                    'name' => $this->l('7 days'),
                                    'val' => '1'
                                ),
                                array(
                                    'id' => '15',
                                    'name' => $this->l('15 days'),
                                    'val' => '1'
                                ),
                                array(
                                    'id' => '20',
                                    'name' => $this->l('20 days'),
                                    'val' => '1'
                                ),
                                array(
                                    'id' => '30',
                                    'name' => $this->l('30 days'),
                                    'val' => '1'
                                ),
                                array(
                                    'id' => '45',
                                    'name' => $this->l('45 days'),
                                    'val' => '1'
                                ),
                                array(
                                    'id' => '60',
                                    'name' => $this->l('60 days'),
                                    'val' => '1'
                                ),
                                array(
                                    'id' => '90',
                                    'name' => $this->l('90 days'),
                                    'val' => '1'
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
        $fields_values['PS_TWO_MERACHANT_API_KEY'] = Tools::getValue('PS_TWO_MERACHANT_API_KEY', Configuration::get('PS_TWO_MERACHANT_API_KEY'));
        $fields_values['PS_TWO_ENVIRONMENT'] = Tools::getValue('PS_TWO_ENVIRONMENT', Configuration::get('PS_TWO_ENVIRONMENT'));
        
        // Payment terms checkboxes
        $payment_terms = array('7', '15', '20', '30', '45', '60', '90');
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
        if (Tools::isEmpty(Tools::getValue('PS_TWO_MERACHANT_API_KEY'))) {
            $this->errors[] = $this->l('Enter an API key.');
        }
        
        // Validate environment
        $environment = Tools::getValue('PS_TWO_ENVIRONMENT');
        if (Tools::isEmpty($environment) || !in_array($environment, array('production', 'development'))) {
            $this->errors[] = $this->l('Please select a valid environment (Production or Development).');
        }
        
        // Validate payment terms
        $payment_terms = array('7', '15', '20', '30', '45', '60', '90');
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
        $apiKey = trim(Tools::getValue('PS_TWO_MERACHANT_API_KEY'));
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
        Configuration::updateValue('PS_TWO_MERACHANT_API_KEY', trim(Tools::getValue('PS_TWO_MERACHANT_API_KEY')));
        Configuration::updateValue('PS_TWO_ENVIRONMENT', Tools::getValue('PS_TWO_ENVIRONMENT'));
        if ($this->verifiedMerchantId) {
            Configuration::updateValue('PS_TWO_MERCHANT_ID', $this->verifiedMerchantId);
            Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 1);
        } else {
            // Ensure flag not stale when verification fails/non-run
            Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 0);
        }
        
        // Save payment terms checkboxes
        $payment_terms = array('7', '15', '20', '30', '45', '60', '90');
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
                        'label' => $this->l('Finalize purchase when order is shipped'),
                        'name' => 'PS_TWO_FINALIZE_PURCHASE',
                        'is_bool' => true,
                        'desc' => $this->l('If you choose YES then order status of shipped to be passed to Two.'),
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
        $fields_values['PS_TWO_ENABLE_ORDER_INTENT'] = Tools::getValue('PS_TWO_ENABLE_ORDER_INTENT', Configuration::get('PS_TWO_ENABLE_ORDER_INTENT'));
        $fields_values['PS_TWO_ENABLE_B2B_B2C'] = Tools::getValue('PS_TWO_ENABLE_B2B_B2C', Configuration::get('PS_TWO_ENABLE_B2B_B2C'));
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
        Configuration::updateValue('PS_TWO_ENABLE_ORDER_INTENT', Tools::getValue('PS_TWO_ENABLE_ORDER_INTENT'));
        Configuration::updateValue('PS_TWO_ENABLE_B2B_B2C', Tools::getValue('PS_TWO_ENABLE_B2B_B2C'));

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
                        'label' => $this->l('Two: Order Fulfilled - Payment Terms Active'),
                        'desc' => $this->l('Order has been fulfilled with Two. Buyer payment terms are now active and payout cycle begins for merchant. Default: Shipped'),
                        'required' => true,
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
        $fields_values['PS_TWO_OS_FULFILLED_MAP'] = Tools::getValue('PS_TWO_OS_FULFILLED_MAP', Configuration::get('PS_TWO_OS_FULFILLED_MAP'));
        $fields_values['PS_TWO_OS_PAYMENT_ERROR_MAP'] = Tools::getValue('PS_TWO_OS_PAYMENT_ERROR_MAP', Configuration::get('PS_TWO_OS_PAYMENT_ERROR_MAP'));
        $fields_values['PS_TWO_OS_CANCELLED_MAP'] = Tools::getValue('PS_TWO_OS_CANCELLED_MAP', Configuration::get('PS_TWO_OS_CANCELLED_MAP'));
        $fields_values['PS_TWO_OS_REFUNDED_MAP'] = Tools::getValue('PS_TWO_OS_REFUNDED_MAP', Configuration::get('PS_TWO_OS_REFUNDED_MAP'));
        return $fields_values;
    }

    protected function saveTwoOrderStatusFormValues()
    {
        Configuration::updateValue('PS_TWO_OS_AWAITING_VERIFICATION_MAP', Tools::getValue('PS_TWO_OS_AWAITING_VERIFICATION_MAP'));
        Configuration::updateValue('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP', Tools::getValue('PS_TWO_OS_VERIFIED_PENDING_FULFILLMENT_MAP'));
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', Tools::getValue('PS_TWO_OS_FULFILLED_MAP'));
        Configuration::updateValue('PS_TWO_OS_PAYMENT_ERROR_MAP', Tools::getValue('PS_TWO_OS_PAYMENT_ERROR_MAP'));
        Configuration::updateValue('PS_TWO_OS_CANCELLED_MAP', Tools::getValue('PS_TWO_OS_CANCELLED_MAP'));
        Configuration::updateValue('PS_TWO_OS_REFUNDED_MAP', Tools::getValue('PS_TWO_OS_REFUNDED_MAP'));

        $this->output .= $this->displayConfirmation($this->l('Two order status mapping updated successfully.'));
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
                            'two_invoice_url' => $response['invoice_url'],
                        );
                        $this->setTwoOrderPaymentData($id_order, $payment_data);
                    }
                } else if (($new_order_status->id == Configuration::get('PS_TWO_OS_FULFILLED_MAP')) && $this->finalize_purchase_shipping) {
                    // Complete fulfillment using the new fulfillments endpoint - wrapped in try-catch for safety
                    try {
                        PrestaShopLogger::addLog('TwoPayment: Initiating complete fulfillment for Two order ID: ' . $two_order_id . ', Order ID: ' . $id_order, 1);
                        
                        // Validate order state before attempting fulfillment
                        $current_two_order = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
                        if (!$current_two_order || !isset($current_two_order['state'])) {
                            PrestaShopLogger::addLog('TwoPayment: Cannot retrieve Two order state for fulfillment. Two order ID: ' . $two_order_id, 3);
                            return;
                        }
                        
                        // Only attempt fulfillment if order is in a fulfillable state
                        // Both VERIFIED and CONFIRMED orders can be fulfilled according to Two API
                        if (!in_array($current_two_order['state'], ['VERIFIED', 'CONFIRMED'])) {
                            PrestaShopLogger::addLog('TwoPayment: Two order not in fulfillable state. Current state: ' . $current_two_order['state'] . ', Two order ID: ' . $two_order_id, 2);
                            return;
                        }
                        
                        $response = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id . '/fulfillments', [], 'POST');
                        
                        if (isset($response['id']) && $response['id']) {
                            PrestaShopLogger::addLog('TwoPayment: Fulfillment successful for Two order ID: ' . $two_order_id . ', Fulfillment ID: ' . $response['id'], 1);
                            // Refresh order data from Two to avoid overwriting the stored Two order ID with fulfillment ID
                            $order_after = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
                            if (isset($order_after['id']) && $order_after['id']) {
                                $payment_data = array(
                                    'two_order_id' => $two_order_id,
                                    'two_order_reference' => isset($order_after['merchant_reference']) ? $order_after['merchant_reference'] : (isset($orderpaymentdata['two_order_reference']) ? $orderpaymentdata['two_order_reference'] : ''),
                                    'two_order_state' => isset($order_after['state']) ? $order_after['state'] : (isset($orderpaymentdata['two_order_state']) ? $orderpaymentdata['two_order_state'] : ''),
                                    'two_order_status' => isset($order_after['status']) ? $order_after['status'] : (isset($orderpaymentdata['two_order_status']) ? $orderpaymentdata['two_order_status'] : ''),
                                    'two_day_on_invoice' => (string)$this->getSelectedPaymentTerm(), // Selected payment term
                                    'two_invoice_url' => isset($order_after['invoice_url']) ? $order_after['invoice_url'] : (isset($orderpaymentdata['two_invoice_url']) ? $orderpaymentdata['two_invoice_url'] : ''),
                                );
                                $this->setTwoOrderPaymentData($id_order, $payment_data);
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
                    // Full refund: issue refund call with no request body
                    $response = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id . '/refund', [], 'POST');
                    if (isset($response['id']) && $response['id']) {
                        // Fetch latest order snapshot to update local state/status
                        $order_after = $this->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
                        if (isset($order_after['id']) && $order_after['id']) {
                            $payment_data = array(
                                'two_order_id' => $two_order_id,
                                'two_order_reference' => isset($order_after['merchant_reference']) ? $order_after['merchant_reference'] : (isset($orderpaymentdata['two_order_reference']) ? $orderpaymentdata['two_order_reference'] : ''),
                                'two_order_state' => isset($order_after['state']) ? $order_after['state'] : (isset($orderpaymentdata['two_order_state']) ? $orderpaymentdata['two_order_state'] : ''),
                                'two_order_status' => isset($order_after['status']) ? $order_after['status'] : (isset($orderpaymentdata['two_order_status']) ? $orderpaymentdata['two_order_status'] : ''),
                                'two_day_on_invoice' => (string)$this->getSelectedPaymentTerm(), // Selected payment term
                                'two_invoice_url' => isset($order_after['invoice_url']) ? $order_after['invoice_url'] : (isset($orderpaymentdata['two_invoice_url']) ? $orderpaymentdata['two_invoice_url'] : ''),
                            );
                            $this->setTwoOrderPaymentData($id_order, $payment_data);
                        }
                    } else {
                        // Log refund failure
                        $error_message = isset($response['error']) ? (is_array($response['error']) ? json_encode($response['error']) : $response['error']) : 'Unknown error';
                        PrestaShopLogger::addLog('TwoPayment: Refund failed for Two order ID: ' . $two_order_id . ', Error: ' . $error_message . ', Response: ' . json_encode($response), 3);
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
            'company_name_required' => $this->l('Please enter your company name to continue with Two payment.'),
            'organization_number_required' => $this->l('Please search and select a valid company to continue with Two payment.'),
            'select_company_to_use_two' => $this->l('To pay with Two, please select your company from the search results so we can verify your business and offer invoice terms.'),
            'invalid_company' => $this->l('The company information provided is not valid. Please search and select a valid company.'),
            'company_not_found' => $this->l('We could not find your company. Please try a different company name or contact support.'),
            'credit_unavailable' => $this->l('Two payment is not available for this order. Please choose another payment method.'),
            'network_issue' => $this->l('There was a temporary issue verifying your payment. Please try again or choose another payment method.'),
            'approval_required' => $this->l('Payment approval required before proceeding'),
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
        $this->context->controller->addJqueryUI('ui.autocomplete');
        $this->context->controller->registerStylesheet('two-css', 'modules/twopayment/views/css/two.css', array('priority' => 200, 'media' => 'all'));
        
        // CRITICAL FIX: Remove async loading and ensure proper load order for reliable initialization
        $this->context->controller->registerJavascript('two-company-search', 'modules/twopayment/views/js/modules/TwoCompanySearch.js', array('priority' => 201, 'async' => false));
        $this->context->controller->registerJavascript('two-order-intent', 'modules/twopayment/views/js/modules/TwoOrderIntent.js', array('priority' => 202, 'async' => false));
        $this->context->controller->registerJavascript('two-field-validation', 'modules/twopayment/views/js/modules/TwoFieldValidation.js', array('priority' => 203, 'async' => false));
        $this->context->controller->registerJavascript('two-phone-validation', 'modules/twopayment/views/js/modules/TwoPhoneValidation.js', array('priority' => 204, 'async' => false));
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
            $subtitle = $this->l('Get 30 days to pay your invoice via EHF and PDF');
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



    public function getTwoIntentOrderData($cart, $cutomer, $currency, $address)
    {
        // Get detailed line items for proper validation
        $line_items = $this->getTwoProductItems($cart);
        
        // Calculate totals from line items to ensure accuracy
        $calculated_gross = 0;
        $calculated_net = 0;
        $calculated_tax = 0;
        $calculated_discount = 0;
        
        foreach ($line_items as $item) {
            $calculated_gross += (float)$item['gross_amount'];
            $calculated_net += (float)$item['net_amount'];
            $calculated_tax += (float)$item['tax_amount'];
            $calculated_discount += (float)$item['discount_amount'];
        }
        
        // Get PrestaShop cart totals for validation
        $cart_gross = $cart->getOrderTotal(true, Cart::BOTH);
        $cart_net = $cart->getOrderTotal(false, Cart::BOTH);
        $cart_tax = $cart_gross - $cart_net;
        $cart_discount = $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
        
        // STREAMLINED ORDER VALIDATION: Use line item totals (built with Two API compliance)
        $final_gross = $calculated_gross;
        $final_net = $calculated_net;
        $final_tax = $calculated_tax;
        $final_discount = abs($calculated_discount); // Two API expects positive discount amount at order level
        
        // Simple validation against PrestaShop cart totals (for monitoring only)
        $net_diff = abs($cart_net - $calculated_net);
        $gross_diff = abs($cart_gross - $calculated_gross);
        
        // Only log significant discrepancies
        if ($net_diff > 0.50 || $gross_diff > 0.50) {
            PrestaShopLogger::addLog(
                'TwoPayment Order Intent - Notable difference from PrestaShop totals. ' .
                'Cart Net: ' . $cart_net . ' → Two Net: ' . $calculated_net . ' (diff: ' . $net_diff . '), ' .
                'Cart Gross: ' . $cart_gross . ' → Two Gross: ' . $calculated_gross . ' (diff: ' . $gross_diff . ')',
                1
            );
        }
        
        // Calculate tax subtotals for enhanced validation
        $tax_subtotals = $this->getTwoTaxSubtotals($line_items);
        
        // Validate all line items against Two API formulas
        $validation_passed = $this->validateTwoLineItems($line_items);
        if (!$validation_passed) {
            PrestaShopLogger::addLog('TwoPayment Order Intent - Line item validation failed, but proceeding with request', 2);
        }
        
        // Organization number resolution with country-aware fallback
        $org_number = '';
        $buyer_country_iso = Country::getIsoById($address->id_country);
        if (!empty($address->companyid)) {
            $org_number = $address->companyid;
        } elseif ($buyer_country_iso === 'ES' && !empty($address->dni)) {
            // Only use DNI fallback for Spain
            $org_number = $address->dni;
        } elseif (!empty($this->context->cookie->two_company_id)) {
            // Fallback to cookie where FE saved selected org number (e.g., GB)
            $org_number = trim($this->context->cookie->two_company_id);
        }

        $request_data = array(
            'gross_amount' => (string)($this->getTwoRoundAmount($final_gross)),
            'net_amount' => (string)($this->getTwoRoundAmount($final_net)),
            'tax_amount' => (string)($this->getTwoRoundAmount($final_tax)),
            'discount_amount' => (string)($this->getTwoRoundAmount($final_discount)),
            'tax_subtotals' => $tax_subtotals,
            'buyer' => array(
                'company' => array(
                    'company_name' => (!empty($address->company) ? $address->company : (isset($this->context->cookie->two_company_name) ? trim($this->context->cookie->two_company_name) : '')),
                    'country_prefix' => $buyer_country_iso,
                    'organization_number' => $org_number,
                    'website' => '',
                ),
                'representative' => array(
                    'email' => $cutomer->email,
                    'first_name' => $cutomer->firstname,
                    'last_name' => $cutomer->lastname,
                    'phone_number' => $address->phone,
                ),
            ),
            'currency' => $currency->iso_code,
            'merchant_short_name' => $this->merchant_short_name,
            'invoice_type' => 'FUNDED_INVOICE', // Default product type
            'line_items' => $line_items,
        );

        return $request_data;
    }

    public function getTwoNewOrderData($id_order, $cart)
    {
        $order_reference = round(microtime(1) * 1000);
        $cutomer = new Customer($cart->id_customer);
        $currency = new Currency($cart->id_currency);
        $invoice_address = new Address($cart->id_address_invoice);
        $delivery_address = new Address($cart->id_address_delivery);
        $carrier_name = '';
        $tracking_number = '';
        $carrier = new Carrier($cart->id_carrier, $cart->id_lang);
        if (Validate::isLoadedObject($carrier)) {
            $carrier_name = $carrier->name;
        }

        // Get line items first for validation
        $line_items = $this->getTwoProductItems($cart);
        
        // Calculate totals from line items to ensure accuracy
        $calculated_gross = 0;
        $calculated_net = 0;
        $calculated_tax = 0;
        $calculated_discount = 0;
        
        foreach ($line_items as $item) {
            $calculated_gross += (float)$item['gross_amount'];
            $calculated_net += (float)$item['net_amount'];
            $calculated_tax += (float)$item['tax_amount'];
            $calculated_discount += (float)$item['discount_amount'];
        }
        
        // Get PrestaShop cart totals
        $cart_gross = $cart->getOrderTotal(true, Cart::BOTH);
        $cart_net = $cart->getOrderTotal(false, Cart::BOTH);
        $cart_tax = $cart_gross - $cart_net;
        $cart_discount = $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
        
        // ROUNDING ALIGNMENT: Ensure perfect match with PrestaShop cart totals
        $net_diff = abs($cart_net - $calculated_net);
        $gross_diff = abs($cart_gross - $calculated_gross);
        
        // If difference is minimal (rounding issue), use PrestaShop cart totals
        if ($net_diff <= 0.02 && $gross_diff <= 0.02) {
            PrestaShopLogger::addLog(
                'TwoPayment Create Order - Minor rounding difference detected, aligning with PrestaShop totals. ' .
                'Cart Net: ' . $cart_net . ' → Line Items Net: ' . $calculated_net . ' (diff: ' . $net_diff . '), ' .
                'Cart Gross: ' . $cart_gross . ' → Line Items Gross: ' . $calculated_gross . ' (diff: ' . $gross_diff . ')',
                1
            );
            
            // Use PrestaShop cart totals for perfect alignment
            $final_gross = $cart_gross;
            $final_net = $cart_net;
            $final_tax = $cart_tax;
            $final_discount = abs($cart_discount);
        } else {
            // Use line item totals for larger discrepancies (indicates possible data issue)
            $final_gross = $calculated_gross;
            $final_net = $calculated_net;
            $final_tax = $calculated_tax;
            $final_discount = abs($calculated_discount);
            
            if ($net_diff > 0.50 || $gross_diff > 0.50) {
                PrestaShopLogger::addLog(
                    'TwoPayment Create Order - Significant difference from PrestaShop totals. ' .
                    'Cart Net: ' . $cart_net . ' → Line Items Net: ' . $calculated_net . ' (diff: ' . $net_diff . '), ' .
                    'Cart Gross: ' . $cart_gross . ' → Line Items Gross: ' . $calculated_gross . ' (diff: ' . $gross_diff . ')',
                    2
                );
            }
        }

        // Calculate tax subtotals - align with final tax amount if using cart totals
        $tax_subtotals = $this->getTwoTaxSubtotals($line_items);
        
        // If we're using cart totals, adjust tax subtotals to match
        if ($net_diff <= 0.02 && $gross_diff <= 0.02 && !empty($tax_subtotals)) {
            $calculated_tax_total = 0;
            foreach ($tax_subtotals as $subtotal) {
                $calculated_tax_total += (float)$subtotal['tax_amount'];
            }
            
            $tax_diff = abs($final_tax - $calculated_tax_total);
            if ($tax_diff > 0.01) {
                // Adjust the largest tax subtotal to match final tax amount
                $largest_index = 0;
                $largest_amount = 0;
                foreach ($tax_subtotals as $index => $subtotal) {
                    if ((float)$subtotal['tax_amount'] > $largest_amount) {
                        $largest_amount = (float)$subtotal['tax_amount'];
                        $largest_index = $index;
                    }
                }
                
                $adjustment = $final_tax - $calculated_tax_total;
                $tax_subtotals[$largest_index]['tax_amount'] = (string)$this->getTwoRoundAmount($largest_amount + $adjustment);
                
                PrestaShopLogger::addLog(
                    'TwoPayment Create Order - Adjusted tax subtotal by €' . number_format($adjustment, 2) . ' to align with cart total',
                    1
                );
            }
        }

        // Validate all line items against Two API formulas
        $validation_passed = $this->validateTwoLineItems($line_items);
        if (!$validation_passed) {
            PrestaShopLogger::addLog('TwoPayment Create Order - Line item validation failed, but proceeding with request', 2);
        }

        // COMPANY DATA FALLBACKS: ensure company and organization number are present when creating the Two order
        $cookie = $this->context->cookie;
        $cookieCompanyName = isset($cookie->two_company_name) ? trim($cookie->two_company_name) : '';
        $cookieCompanyId = isset($cookie->two_company_id) ? trim($cookie->two_company_id) : '';

        $buyerCompanyName = !empty($invoice_address->company) ? $invoice_address->company : $cookieCompanyName;
        $organizationNumber = '';
        if (!empty($invoice_address->companyid)) {
            $organizationNumber = $invoice_address->companyid;
        } elseif (!empty($cookieCompanyId)) {
            $organizationNumber = $cookieCompanyId;
        } elseif (!empty($invoice_address->dni)) {
            $organizationNumber = $invoice_address->dni;
        }

        $shippingOrgName = !empty($delivery_address->company) ? $delivery_address->company : $buyerCompanyName;

        $request_data = array(
            'gross_amount' => (string)($this->getTwoRoundAmount($final_gross)),
            'net_amount' => (string)($this->getTwoRoundAmount($final_net)),
            'currency' => $currency->iso_code,
            'discount_amount' => (string)($this->getTwoRoundAmount($final_discount)),
            'discount_rate' => '0',
            'invoice_type' => 'FUNDED_INVOICE', // Default product type
            'tax_amount' => (string)($this->getTwoRoundAmount($final_tax)),
            'tax_rate' => (string)($cart->getAverageProductsTaxRate()),
            'tax_subtotals' => $tax_subtotals,
            'buyer' => array(
                'company' => array(
                    'company_name' => $buyerCompanyName,
                    'country_prefix' => Country::getIsoById($invoice_address->id_country),
                    'organization_number' => $organizationNumber,
                    'website' => '',
                ),
                'representative' => array(
                    'email' => $cutomer->email,
                    'first_name' => $cutomer->firstname,
                    'last_name' => $cutomer->lastname,
                    'phone_number' => $invoice_address->phone,
                ),
            ),
            'buyer_department' => $invoice_address->department,
            'buyer_project' => $invoice_address->project,
            'merchant_additional_info' => '',
            'merchant_order_id' => (string)($id_order),
            'merchant_reference' => (string)($order_reference),
            'merchant_urls' => array(
                'merchant_confirmation_url' => $this->context->link->getModuleLink($this->name, 'confirmation', array('id_order' => $id_order), true),
                'merchant_cancel_order_url' => $this->context->link->getModuleLink($this->name, 'cancel', array('id_order' => $id_order), true),
                'merchant_edit_order_url' => '',
                'merchant_order_verification_failed_url' => '',
                'merchant_invoice_url' => '',
                'merchant_shipping_document_url' => ''
            ),
            'billing_address' => array(
                'city' => $invoice_address->city,
                'country' => Country::getIsoById($invoice_address->id_country),
                'organization_name' => $buyerCompanyName,
                'postal_code' => $invoice_address->postcode,
                'region' => $invoice_address->id_state ? State::getNameById($invoice_address->id_state) : "",
                'street_address' => $invoice_address->address1 . (isset($invoice_address->address2) ? $invoice_address->address2 : "")
            ),
            'shipping_address' => array(
                'city' => $delivery_address->city,
                'country' => Country::getIsoById($delivery_address->id_country),
                'organization_name' => $shippingOrgName,
                'postal_code' => $delivery_address->postcode,
                'region' => $delivery_address->id_state ? State::getNameById($delivery_address->id_state) : "",
                'street_address' => $delivery_address->address1 . (isset($delivery_address->address2) ? $delivery_address->address2 : "")
            ),
            'shipping_details' => array(
                'carrier_name' => $carrier_name,
                'tracking_number' => $tracking_number,
                'expected_delivery_date' => date('Y-m-d', strtotime('+ 7 days'))
            ),
            'recurring' => false,
            'order_note' => '',
            'line_items' => $line_items,
            'terms' => array(
                'type' => 'NET_TERMS',
                'duration_days' => $this->getSelectedPaymentTerm()
            ),
        );

        PrestaShopLogger::addLog('TwoPayment: Order creation with terms - duration_days: ' . $request_data['terms']['duration_days'], 1);
        
        return $request_data;
    }

    public function getTwoUpdateOrderData($order, $orderpaymentdata)
    {
        $cart = new Cart($order->id_cart);
        $currency = new Currency($cart->id_currency);
        $invoice_address = new Address($cart->id_address_invoice);
        $delivery_address = new Address($cart->id_address_delivery);
        $carrier_name = '';
        $tracking_number = '';
        $carrier = new Carrier($cart->id_carrier, $cart->id_lang);
        if (Validate::isLoadedObject($carrier)) {
            $carrier_name = $carrier->name;
        }

        // Get line items first for validation
        $line_items = $this->getTwoProductItems($cart);
        
        // Calculate totals from line items to ensure accuracy
        $calculated_gross = 0;
        $calculated_net = 0;
        $calculated_tax = 0;
        $calculated_discount = 0;
        
        foreach ($line_items as $item) {
            $calculated_gross += (float)$item['gross_amount'];
            $calculated_net += (float)$item['net_amount'];
            $calculated_tax += (float)$item['tax_amount'];
            $calculated_discount += (float)$item['discount_amount'];
        }
        
        // Get PrestaShop cart totals
        $cart_gross = $cart->getOrderTotal(true, Cart::BOTH);
        $cart_net = $cart->getOrderTotal(false, Cart::BOTH);
        $cart_tax = $cart_gross - $cart_net;
        $cart_discount = $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
        
        // STREAMLINED ORDER VALIDATION: Use line item totals (built with Two API compliance)
        $final_gross = $calculated_gross;
        $final_net = $calculated_net;
        $final_tax = $calculated_tax;
        $final_discount = abs($calculated_discount); // Two API expects positive discount amount at order level
        
        // Simple validation against PrestaShop cart totals (for monitoring only)
        $net_diff = abs($cart_net - $calculated_net);
        $gross_diff = abs($cart_gross - $calculated_gross);
        
        // Only log significant discrepancies
        if ($net_diff > 0.50 || $gross_diff > 0.50) {
            PrestaShopLogger::addLog(
                'TwoPayment Update Order - Notable difference from PrestaShop totals. ' .
                'Cart Net: ' . $cart_net . ' → Two Net: ' . $calculated_net . ' (diff: ' . $net_diff . '), ' .
                'Cart Gross: ' . $cart_gross . ' → Two Gross: ' . $calculated_gross . ' (diff: ' . $gross_diff . ')',
                1
            );
        }

        // Calculate tax subtotals for enhanced validation
        $tax_subtotals = $this->getTwoTaxSubtotals($line_items);

        // Validate all line items against Two API formulas
        $validation_passed = $this->validateTwoLineItems($line_items);
        if (!$validation_passed) {
            PrestaShopLogger::addLog('TwoPayment Update Order - Line item validation failed, but proceeding with request', 2);
        }

        $request_data = array(
            'gross_amount' => (string)($this->getTwoRoundAmount($final_gross)),
            'net_amount' => (string)($this->getTwoRoundAmount($final_net)),
            'currency' => $currency->iso_code,
            'discount_amount' => (string)($this->getTwoRoundAmount($final_discount)),
            'discount_rate' => '0',
            'invoice_type' => 'FUNDED_INVOICE', // Default product type
            'tax_amount' => (string)($this->getTwoRoundAmount($final_tax)),
            'tax_rate' => (string)($cart->getAverageProductsTaxRate()),
            'tax_subtotals' => $tax_subtotals,
            'buyer_department' => $invoice_address->department,
            'buyer_project' => $invoice_address->project,
            'merchant_additional_info' => '',
            'merchant_reference' => (string)($orderpaymentdata['two_order_reference']),
            'billing_address' => array(
                'city' => $invoice_address->city,
                'country' => Country::getIsoById($invoice_address->id_country),
                'organization_name' => $invoice_address->company,
                'postal_code' => $invoice_address->postcode,
                'region' => $invoice_address->id_state ? State::getNameById($invoice_address->id_state) : "",
                'street_address' => $invoice_address->address1 . (isset($invoice_address->address2) ? $invoice_address->address2 : "")
            ),
            'shipping_address' => array(
                'city' => $delivery_address->city,
                'country' => Country::getIsoById($delivery_address->id_country),
                'organization_name' => $delivery_address->company,
                'postal_code' => $delivery_address->postcode,
                'region' => $delivery_address->id_state ? State::getNameById($delivery_address->id_state) : "",
                'street_address' => $delivery_address->address1 . (isset($delivery_address->address2) ? $delivery_address->address2 : "")
            ),
            'shipping_details' => array(
                'carrier_name' => $carrier_name,
                'tracking_number' => $tracking_number,
                'expected_delivery_date' => date('Y-m-d', strtotime('+ 7 days'))
            ),
            'recurring' => false,
            'order_note' => '',
            'line_items' => $line_items,
        );

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

    public function getTwoProductItems($cart)
    {
        $items = [];
        $carrier = new Carrier($cart->id_carrier, $cart->id_lang);
        $line_items = $cart->getProducts(true);
        
        foreach ($line_items as $line_item) {
            $categories = Product::getProductCategoriesFull($line_item['id_product'], $cart->id_lang);
            $image = Image::getCover($line_item['id_product']);
            $imagePath = $this->context->link->getImageLink($line_item['link_rewrite'], $image['id_image'], ImageType::getFormattedName('home'));
            
            // Get base prices (PrestaShop provides these accurately)
            $unit_price_net = (float)$line_item['price']; // Price without tax per unit
            $unit_price_gross = (float)$line_item['price_wt']; // Price with tax per unit
            $quantity = (int)$line_item['cart_quantity'];
            $unit_tax_rate = (float)$line_item['rate']; // Tax rate percentage
            
            // CONSERVATIVE APPROACH: Only use discount if we can verify it exists
            // The validation error shows phantom discounts, so let's be more careful
            $line_discount_amount = 0; // Start with no discount
            
            // Only apply line-level discount if there's clear evidence of it
            if (isset($line_item['reduction']) && (float)$line_item['reduction'] > 0) {
                // Check if this reduction makes mathematical sense
                $ps_unit_price_net = (float)$line_item['price'];
                $ps_total_net = (float)$line_item['total'];
                $quantity = (int)$line_item['cart_quantity'];
                
                // Calculate what the total SHOULD be without discount
                $expected_total_without_discount = $ps_unit_price_net * $quantity;
                
                // If PrestaShop's total is less than expected, there might be a real discount
                if ($expected_total_without_discount > $ps_total_net) {
                    $calculated_discount = $expected_total_without_discount - $ps_total_net;
                    
                    // Only use the discount if it matches PrestaShop's reduction field (within tolerance)
                    if (abs($calculated_discount - (float)$line_item['reduction']) < 0.01) {
                        $line_discount_amount = $calculated_discount;
                    }
                }
            }
            
            
            // Calculate amounts following Two's API requirements:
            // net_amount = (quantity * unit_price_net) - discount_amount
            $calculated_net_before_discount = $unit_price_net * $quantity;
            $calculated_net_amount = $calculated_net_before_discount - $line_discount_amount;
            
            // Calculate tax amount based on net amount after discount
            $calculated_tax_amount = $calculated_net_amount * ($unit_tax_rate / 100);
            
            // Calculate gross amount = net amount + tax amount
            $calculated_gross_amount = $calculated_net_amount + $calculated_tax_amount;
            
            // Validate against PrestaShop's calculations (with tolerance for rounding)
            $ps_total_net = (float)$line_item['total'];
            $ps_total_gross = (float)$line_item['total_wt'];
            $ps_total_tax = $ps_total_gross - $ps_total_net;
            
            
            // STREAMLINED APPROACH: Always use PrestaShop's actual totals as the source of truth
            // Then adjust only what's necessary for Two API compliance
            
            $final_net_amount = $ps_total_net;
            $final_gross_amount = $ps_total_gross;
            $final_discount_amount = $line_discount_amount;
            
            // Calculate the effective unit price to ensure Two API formula compliance
            // net_amount = (quantity * unit_price) - discount_amount
            // Therefore: unit_price = (net_amount + discount_amount) / quantity
            $calculated_unit_price_net = ($final_net_amount + $final_discount_amount) / $quantity;
            $unit_price_net = $calculated_unit_price_net;
            
            // CRITICAL: Calculate tax amount using Two's exact formula for API compliance
            // This ensures tax_amount = net_amount * tax_rate validation passes
            $final_tax_amount = $final_net_amount * ($unit_tax_rate / 100);
            
            // Update gross amount to match the recalculated tax
            $final_gross_amount = $final_net_amount + $final_tax_amount;
            
            // Log significant differences for debugging (but don't fail the process)
            $ps_tax_diff = abs($ps_total_tax - $final_tax_amount);
            $ps_gross_diff = abs($ps_total_gross - $final_gross_amount);
            
            if ($ps_tax_diff > 0.01 || $ps_gross_diff > 0.01) {
                PrestaShopLogger::addLog(
                    'TwoPayment Line Item Adjustment - Product: ' . $line_item['name'] . 
                    ', PS Tax: ' . $ps_total_tax . ' → Two Tax: ' . $final_tax_amount . 
                    ', PS Gross: ' . $ps_total_gross . ' → Two Gross: ' . $final_gross_amount . 
                    ', Tax Rate: ' . $unit_tax_rate . '%', 
                    1
                );
            }
            
            // Validation: Ensure Two API formulas will pass
            $two_net_formula_check = ($quantity * $unit_price_net) - $final_discount_amount;
            $two_tax_formula_check = $final_net_amount * ($unit_tax_rate / 100); // Still calculate with percentage internally
            
            
            // Create a simple debug file for easier access
            $debug_data = [
                'product' => $line_item['name'],
                'unit_price_net' => $unit_price_net,
                'quantity' => $quantity,
                'discount_amount' => $final_discount_amount,
                'calculated_net' => $two_net_formula_check,
                'actual_net' => $final_net_amount,
                'ps_price' => $line_item['price'],
                'ps_total' => $line_item['total'],
                'ps_reduction' => $line_item['reduction'] ?? 0
            ];
            
            file_put_contents('/tmp/two_debug.json', json_encode($debug_data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
            
            // Only log if there are significant formula violations
            if (abs($two_net_formula_check - $final_net_amount) > 0.02) {
                PrestaShopLogger::addLog(
                    'TwoPayment Net Formula Issue - Product: ' . $line_item['name'] . 
                    ', Expected: ' . $two_net_formula_check . ', Actual: ' . $final_net_amount, 
                    2
                );
            }
            
            if (abs($two_tax_formula_check - $final_tax_amount) > 0.001) {
                PrestaShopLogger::addLog(
                    'TwoPayment Tax Formula Issue - Product: ' . $line_item['name'] . 
                    ', Expected: ' . $two_tax_formula_check . ', Actual: ' . $final_tax_amount, 
                    2
                );
            }
            
            $product = array(
                'name' => $line_item['name'],
                'description' => Tools::substr(strip_tags($line_item['description_short']), 0, 255),
                'gross_amount' => (string)($this->getTwoRoundAmount($final_gross_amount)),
                'net_amount' => (string)($this->getTwoRoundAmount($final_net_amount)),
                'discount_amount' => (string)($this->getTwoRoundAmount($final_discount_amount)),
                'tax_amount' => (string)($this->getTwoRoundAmount($final_tax_amount)),
                'tax_class_name' => 'VAT ' . $this->getTwoRoundAmount($unit_tax_rate) . '%',
                'tax_rate' => (string)($this->getTwoRoundAmount($unit_tax_rate / 100)), // Convert percentage to decimal
                'unit_price' => (string)($this->getTwoRoundAmount($unit_price_net)), // Two API expects net unit price
                'quantity' => $quantity,
                'quantity_unit' => 'pcs',
                'image_url' => $imagePath,
                'product_page_url' => $this->context->link->getProductLink($line_item['id_product']),
                'type' => 'PHYSICAL',
                'details' => array(
                    'brand' => $line_item['manufacturer_name'],
                    'barcodes' => array(
                        array(
                            'type' => 'SKU',
                            'value' => $line_item['ean13']
                        ),
                        array(
                            'type' => 'UPC',
                            'value' => $line_item['upc']
                        ),
                    ),
                ),
            );
            $product['details']['categories'] = [];
            if ($categories) {
                foreach ($categories as $category) {
                    $product['details']['categories'][] = $category['name'];
                }
            }

            $items[] = $product;
        }

        // Add shipping as a line item if applicable
        if (Validate::isLoadedObject($carrier) && $cart->getOrderTotal(true, Cart::ONLY_SHIPPING) > 0) {
            $shipping_gross = (float)$cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
            $shipping_net = (float)$cart->getOrderTotal(false, Cart::ONLY_SHIPPING);
            $shipping_tax = $shipping_gross - $shipping_net;
            
            // STREAMLINED SHIPPING: Use PrestaShop totals, recalculate tax for Two API compliance
            $shipping_tax_rate = 0;
            if ($shipping_net > 0) {
                $shipping_tax_rate = ($shipping_tax / $shipping_net) * 100;
            }
            
            // Shipping line item structure
            $shipping_discount = 0;
            $shipping_quantity = 1;
            $shipping_unit_price_net = $shipping_net;
            
            // Calculate tax using Two's exact formula (percentage to decimal conversion happens in API output)
            $final_shipping_tax = $shipping_net * ($shipping_tax_rate / 100);
            $final_shipping_gross = $shipping_net + $final_shipping_tax;
            
            // Verify Two API formula for shipping
            $shipping_formula_check = ($shipping_quantity * $shipping_unit_price_net) - $shipping_discount;
            if (abs($shipping_formula_check - $shipping_net) > 0.001) {
                PrestaShopLogger::addLog(
                    'TwoPayment Shipping Formula Validation - Formula Result: ' . $shipping_formula_check . 
                    ', Net Amount: ' . $shipping_net . ', Difference: ' . abs($shipping_formula_check - $shipping_net), 
                    2
                );
            }
            
            // Get proper shipping name and description from PrestaShop
            $shipping_name = $carrier->name ? $carrier->name : $this->l('Shipping');
            
            // Get shipping delay/description in the correct language
            $shipping_delay = '';
            if ($carrier->delay && is_array($carrier->delay)) {
                $shipping_delay = isset($carrier->delay[$cart->id_lang]) ? 
                    $carrier->delay[$cart->id_lang] : 
                    reset($carrier->delay); // Fallback to first available language
            } elseif ($carrier->delay) {
                $shipping_delay = $carrier->delay;
            }
            
            // Create meaningful description
            $shipping_description = $shipping_delay ? $shipping_delay : $this->l('Shipping cost for order');
            
            // Add shipping cost information if available
            if ($carrier->shipping_method == Carrier::SHIPPING_METHOD_WEIGHT) {
                $shipping_description .= ' ' . sprintf($this->l('(by weight)'));
            } elseif ($carrier->shipping_method == Carrier::SHIPPING_METHOD_PRICE) {
                $shipping_description .= ' ' . sprintf($this->l('(by price)'));
            }
            
            $shipping_line = array(
                'name' => $shipping_name,
                'description' => Tools::substr(strip_tags($shipping_description), 0, 255),
                'gross_amount' => (string)($this->getTwoRoundAmount($final_shipping_gross)),
                'net_amount' => (string)($this->getTwoRoundAmount($shipping_net)),
                'discount_amount' => (string)($this->getTwoRoundAmount($shipping_discount)),
                'tax_amount' => (string)($this->getTwoRoundAmount($final_shipping_tax)),
                'tax_class_name' => 'VAT ' . $this->getTwoRoundAmount($shipping_tax_rate) . '%',
                'tax_rate' => (string)($this->getTwoRoundAmount($shipping_tax_rate / 100)), // Convert percentage to decimal
                'unit_price' => (string)($this->getTwoRoundAmount($shipping_unit_price_net)), // Two API expects net unit price
                'quantity' => $shipping_quantity,
                'quantity_unit' => 'pcs',
                'image_url' => '',
                'product_page_url' => '',
                'type' => 'SHIPPING_FEE'
            );

            $items[] = $shipping_line;
        }

        // CONSERVATIVE CART-LEVEL DISCOUNT HANDLING
        $discount_gross_total = (float)$cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
        $cart_rules = $cart->getCartRules();
        
        if ($discount_gross_total > 0) {
            $discount_net_total = (float)$cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS);
            $discount_tax_total = $discount_gross_total - $discount_net_total;
            
            // Calculate average tax rate for discounts
            $discount_tax_rate = 0;
            if ($discount_net_total > 0) {
                $discount_tax_rate = ($discount_tax_total / $discount_net_total) * 100;
            }
            
            // STREAMLINED DISCOUNT: Negative amounts for discount line item
            $discount_quantity = 1;
            $discount_unit_price_net = -$discount_net_total; // Negative unit price
            $discount_discount_amount = 0; // No additional discount on discount line
            $discount_net_amount = -$discount_net_total; // Negative net amount
            
            // Calculate tax using Two's exact formula (percentage to decimal conversion happens in API output)
            $final_discount_tax = $discount_net_amount * ($discount_tax_rate / 100);
            $final_discount_gross = $discount_net_amount + $final_discount_tax;
            
            // Verify Two API formula for discount
            $discount_formula_check = ($discount_quantity * $discount_unit_price_net) - $discount_discount_amount;
            $expected_discount_net = -$discount_net_total; // Should be negative
            
            if (abs($discount_formula_check - $expected_discount_net) > 0.001) {
                PrestaShopLogger::addLog(
                    'TwoPayment Discount Formula Validation - Formula Result: ' . $discount_formula_check . 
                    ', Expected Net: ' . $expected_discount_net . ', Difference: ' . abs($discount_formula_check - $expected_discount_net), 
                    2
                );
            }
            
            // Get actual discount information from PrestaShop cart rules
            $cart_rules = $cart->getCartRules();
            $discount_name = $this->l('Discount');
            $discount_description = $this->l('Order discount');
            
            if (!empty($cart_rules)) {
                // Use the first cart rule name as the primary discount name
                $primary_rule = reset($cart_rules);
                $discount_name = $primary_rule['name'];
                
                // Build comprehensive description
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
                
                // If description is too long, use primary rule description or fallback
                if (strlen($discount_description) > 200) {
                    $discount_description = $primary_rule['description'] ? 
                        Tools::substr(strip_tags($primary_rule['description']), 0, 200) : 
                        sprintf($this->l('Discount: %s'), $primary_rule['name']);
                }
                
                // If multiple cart rules, update name to show count
                if (count($cart_rules) > 1) {
                    $discount_name = sprintf($this->l('%s (+%d more)'), $primary_rule['name'], count($cart_rules) - 1);
                }
            }
            
            $discount_line = array(
                'name' => $discount_name,
                'description' => Tools::substr(strip_tags($discount_description), 0, 255),
                'gross_amount' => (string)($this->getTwoRoundAmount($final_discount_gross)), // Use recalculated gross
                'net_amount' => (string)($this->getTwoRoundAmount($discount_net_amount)), // Negative net amount
                'discount_amount' => (string)($this->getTwoRoundAmount($discount_discount_amount)),
                'tax_amount' => (string)($this->getTwoRoundAmount($final_discount_tax)), // Use recalculated tax
                'tax_class_name' => 'VAT ' . $this->getTwoRoundAmount($discount_tax_rate) . '%',
                'tax_rate' => (string)($this->getTwoRoundAmount($discount_tax_rate / 100)), // Convert percentage to decimal
                'unit_price' => (string)($this->getTwoRoundAmount($discount_unit_price_net)), // Two API expects net unit price (negative)
                'quantity' => $discount_quantity,
                'quantity_unit' => 'item',
                'image_url' => '',
                'product_page_url' => '',
                'type' => 'DIGITAL'
            );

            $items[] = $discount_line;
        }

        return $items;
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
            $net_amount = (float)$item['net_amount'];
            $tax_amount = (float)$item['tax_amount'];
            
            
            if (!isset($tax_groups[$tax_rate])) {
                $tax_groups[$tax_rate] = [
                    'taxable_amount' => 0,
                    'tax_amount' => 0,
                    'tax_rate' => $tax_rate
                ];
            }
            
            $tax_groups[$tax_rate]['taxable_amount'] += $net_amount;
            $tax_groups[$tax_rate]['tax_amount'] += $tax_amount;
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
            $expected_tax_amount = $net_amount * $tax_rate;
            if (abs($tax_amount - $expected_tax_amount) > 0.001) {
                PrestaShopLogger::addLog(
                    'TwoPayment CRITICAL Tax Formula Error - Item: ' . $item['name'] . 
                    ', Got: ' . $tax_amount . ', Expected: ' . $expected_tax_amount, 
                    3
                );
                $validation_issues++;
            }
            
            // Critical validation: net_amount = (quantity * unit_price) - discount_amount
            $expected_net_amount = ($quantity * $unit_price) - $discount_amount;
            if (abs($net_amount - $expected_net_amount) > 0.02) {
                PrestaShopLogger::addLog(
                    'TwoPayment CRITICAL Net Formula Error - Item: ' . $item['name'] . 
                    ', Got: ' . $net_amount . ', Expected: ' . $expected_net_amount, 
                    3
                );
                $validation_issues++;
            }
        }
        
        return $validation_issues === 0;
    }

    public function getTwoRoundAmount($amount)
    {
        return number_format($amount, 2, '.', '');
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
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
    public function getAvailablePaymentTerms()
    {
        $payment_terms = array('7', '15', '20', '30', '45', '60', '90');
        $available_terms = array();
        
        foreach ($payment_terms as $term) {
            if (Configuration::get('PS_TWO_PAYMENT_TERMS_' . $term)) {
                $available_terms[] = (int)$term;
            }
        }
        
        // If no terms are configured, default to 30 days
        if (empty($available_terms)) {
            $available_terms = array(30);
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
        
        // If 30 days is available, use it as default
        if (in_array(30, $available_terms)) {
            return 30;
        }
        
        // Otherwise, use the first available term
        return !empty($available_terms) ? $available_terms[0] : 30;
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


    public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST')
    {
        if ($method == "POST" || $method == "PUT") {
            $url = sprintf('%s%s', $this->getTwoCheckoutHostUrl(), $endpoint);
            $url = $url . '?client=PS&client_v=' . $this->version;
            $params = empty($payload) ? '' : json_encode($payload);
            $headers = [
                'Content-Type: application/json; charset=utf-8',
                'X-API-Key:' . $this->api_key,
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            $response = curl_exec($ch);
            $response = json_decode($response, true);
            curl_getinfo($ch);
            curl_close($ch);
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            $response = curl_exec($ch);
            $response = json_decode($response, true);
            curl_getinfo($ch);
            curl_close($ch);
        }

        return $response;
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

        if (isset($body['response']['code']) && $body['response'] && $body['response']['code'] && $body['response']['code'] >= 400) {
            return sprintf($this->l('Two response code %d'), $body['response']['code']);
        }

        if (is_string($body)) {
            return $body;
        }

        if (isset($body['error_details']) && $body['error_details']) {
            return $body['error_details'];
        }

        if (isset($body['error_code']) && $body['error_code']) {
            return $body['error_message'];
        }
    }

    public function setTwoOrderPaymentData($id_order, $payment_data)
    {
        $result = $this->getTwoOrderPaymentData($id_order);
        if ($result) {
            $data = array(
                'id_order' => pSQL($id_order),
                'two_order_id' => pSQL($payment_data['two_order_id']),
                'two_order_reference' => pSQL($payment_data['two_order_reference']),
                'two_order_state' => pSQL($payment_data['two_order_state']),
                'two_order_status' => pSQL($payment_data['two_order_status']),
                'two_day_on_invoice' => pSQL($payment_data['two_day_on_invoice']),
                'two_invoice_url' => pSQL($payment_data['two_invoice_url']),
            );
            Db::getInstance()->update('twopayment', $data, 'id_order = ' . (int) $id_order);
        } else {
            $data = array(
                'id_order' => pSQL($id_order),
                'two_order_id' => pSQL($payment_data['two_order_id']),
                'two_order_reference' => pSQL($payment_data['two_order_reference']),
                'two_order_state' => pSQL($payment_data['two_order_state']),
                'two_order_status' => pSQL($payment_data['two_order_status']),
                'two_day_on_invoice' => pSQL($payment_data['two_day_on_invoice']),
                'two_invoice_url' => pSQL($payment_data['two_invoice_url']),
            );
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
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'twopayment WHERE id_order = ' . (int) $id_order;
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
            $this->context->cookie->setExpire(time() + 3600);
            
            PrestaShopLogger::addLog('TwoPayment: Company data captured from address save - Company: ' . $address->company, 1);
        }
    }
}

