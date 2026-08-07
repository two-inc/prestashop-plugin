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
require_once dirname(__FILE__) . '/classes/TwoSoleTrader.php';
require_once dirname(__FILE__) . '/classes/TwoCheckoutAmountException.php';

class Twopayment extends PaymentModule
{
    // Constants for order building logic
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
    // Cached GET /v1/merchant `invoice_distributed_by_merchant` flag (TWO-25111).
    // Gates the plugin-side invoice upload (TwoInvoiceUploadService) - the
    // merchant-controlled admin toggle PS_TWO_USE_OWN_INVOICES it replaces is
    // retired (TWO-25106, Option A: flag-driven only, no admin toggle).
    // Populated by the SAME fetch as CONFIG_MERCHANT_AVAILABLE_TERMS and gated
    // by the shared CONFIG_MERCHANT_AVAILABLE_TERMS_TS. Null-safe: a response
    // without the field caches `0` (absent = false).
    const CONFIG_MERCHANT_INVOICE_DISTRIBUTED = 'PS_TWO_MERCHANT_INVOICE_DISTRIBUTED';
    // Cached GET /v1/merchant minimum-order tuple (min_order_amount /
    // min_order_currency / min_order_basis - the funding-partner default with
    // any merchant override, resolved server-side, the same value checkout-api
    // enforces at order create/intent; TWO-24775). Populated by the SAME fetch
    // as CONFIG_MERCHANT_AVAILABLE_TERMS and gated by the shared
    // CONFIG_MERCHANT_AVAILABLE_TERMS_TS. JSON {amount,currency,basis}, or ''
    // when the API declares no minimum - the no-minimum outcome is cached too,
    // so the common case costs no refetch per checkout render.
    const CONFIG_PLATFORM_MIN_ORDER = 'PS_TWO_PLATFORM_MIN_ORDER';
    // The merchant's own optional minimum order value (admin config field,
    // interpreted in the shop default currency). Stacks ON TOP of the platform
    // minimum: it may only raise the effective bar, never lower it below the
    // platform floor (validated on save - TWO-24775).
    const CONFIG_MERCHANT_MIN_ORDER = 'PS_TWO_MERCHANT_MIN_ORDER';
    const CONFIG_MERCHANT_MIN_ORDER_BASIS = 'PS_TWO_MERCHANT_MIN_ORDER_BASIS';

    // Cached FX spot-rate table from GET /refdata/v1/fx-rates (TWO-25105):
    // JSON {base:'EUR', as_of:'YYYY-MM-DD', rates:{ISO: value of 1 ISO in
    // EUR}}. Replaces PrestaShop core's own conversion rates for every
    // Two-side conversion (minimum-order gate, decline hint, admin floor,
    // fixed-surcharge/cap re-denomination) so the plugin converts with the
    // SAME rates checkout-api enforces server-side. Refreshed TTL-gated (6h)
    // from the checkout media hook and on-demand on a cache miss; a fetch
    // failure serves the last-known-good table (gate conversions fail closed
    // ONLY when no table was ever fetched - ticket fail semantics).
    const CONFIG_FX_RATES = 'PS_TWO_FX_RATES';
    const CONFIG_FX_RATES_TS = 'PS_TWO_FX_RATES_TS';
    const FX_RATES_TTL = 21600; // 6 hours
    // On a FAILED fx-rates fetch, retry after this short backoff instead of
    // waiting the full TTL (same serve-stale + backoff discipline as the
    // merchant-record cache).
    const FX_RATES_RETRY_BACKOFF = 300; // 5 minutes

    // Cached, categorised outcome of GET /v1/merchant/verify_api_key for the
    // STORED key (TWO-25326): JSON {status, code, key_hash, claim}.
    // Shop scoping is Configuration's own, exactly as for the FX table and the
    // merchant record - deliberately not hand-scoped here. The slot is bound to
    // the key AND environment it was reached for, so the worst a multistore
    // context can produce is a MISS (a re-verification) on a shop holding a
    // different key, never another shop's verdict applied to this one. Read by the
    // checkout gates, which must not fire a live HTTP call per render, and
    // written by both the cache-miss path and the config-page save. key_hash
    // binds the verdict to the key it was reached for, so pasting a new key
    // never inherits the old key's verdict.
    const CONFIG_API_KEY_STATUS = 'PS_TWO_API_KEY_STATUS';
    const CONFIG_API_KEY_STATUS_TS = 'PS_TWO_API_KEY_STATUS_TS';
    // A verified key is re-checked every 5 minutes; a FAILING one every
    // minute, so recovery (or a rotated key) reaches checkout quickly while a
    // healthy shop is not re-verified for nothing. Both are far shorter than
    // the FX/merchant-record TTLs: this verdict decides whether Two is offered
    // at all, so lag here is lost orders in one direction and broken checkouts
    // in the other.
    const API_KEY_STATUS_TTL = 300;
    const API_KEY_STATUS_FAILURE_TTL = 60;
    // How long the anti-stampede claim (written BEFORE the wire call, so
    // concurrent renders on a cold cache do not each fire their own
    // verification) stands in for a real verdict. Short, because a claim that
    // is never superseded means the claiming process died mid-call, and the
    // right recovery there is to ask again - not to keep serving a guess for a
    // whole TTL. Must comfortably exceed API_TIMEOUT_STATE_CHECK so the claim
    // does not expire while the call it is covering is still in flight.
    const API_KEY_STATUS_CLAIM_WINDOW = 15;
    // Oldest a verdict may be and still ride along on a claim (serve-stale). A
    // claim re-stamps the slot's clock, so without a cap on the age of the
    // VERDICT itself, a shop whose verification never completes - a fatal, a
    // killed worker - could re-carry and re-freshen the same ancient 'ok'
    // indefinitely (review round 3). Past this, a claim carries nothing and the
    // gates close until a call actually finishes.
    const API_KEY_STATUS_CARRY_MAX_AGE = 900;

    // Verification outcome categories (TWO-25326). Held apart because the
    // merchant's remedy differs per category: fix the key, wait for Two, or
    // fix this shop's outbound connectivity. Every non-OK status withholds Two
    // from checkout - the categories only ever change what the merchant is
    // TOLD, never whether Two is served.
    const API_KEY_STATUS_OK = 'ok';
    const API_KEY_STATUS_INVALID = 'invalid_key';     // 401/403 - the key itself was rejected
    const API_KEY_STATUS_SERVICE_ERROR = 'service_error'; // 5xx - Two answered, badly
    const API_KEY_STATUS_UNREACHABLE = 'unreachable'; // no response at all: DNS/TLS/route/timeout
    const API_KEY_STATUS_ERROR = 'error';             // any other non-200, or an unreadable 200 body
    const API_KEY_STATUS_NOT_CONFIGURED = 'not_configured'; // no key stored yet
    // Not a verdict: the marker a request writes while it is still asking, when
    // there is no previous verdict to serve meanwhile. Gates treat it like any
    // other non-ok status (Two withheld) - the alternative is offering Two for
    // the length of the claim window on a shop nobody has verified yet - but the
    // merchant-facing notice stays silent for it, because "still asking" is not
    // a diagnosis.
    const API_KEY_STATUS_VERIFYING = 'verifying';

    // Constants for API timeouts (seconds)
    const API_TIMEOUT_SHORT = 30; // Standard API timeout
    const API_TIMEOUT_LONG = 60; // Extended timeout for file uploads
    const API_TIMEOUT_STATE_CHECK = 10; // Tight timeout for render-path fetches (invoice-download state check, merchant-record and FX-rate refreshes, fee quotes)
    const API_TIMEOUT_PDF_FETCH = 10; // Tight timeout for synchronous invoice PDF fetches (buyer + admin download clicks)
    const API_CONNECT_TIMEOUT = 5; // Connection-establishment timeout for all Two API calls
    
    // Constants for validation tolerances
    const TAX_FORMULA_TOLERANCE = 0.02; // Tolerance for tax formula validation
    const NET_FORMULA_TOLERANCE = 0.05; // Tolerance for net formula validation. Deliberately NOT tightened to 0.02 yet: the emitted unit_price is 2dp while the discount is derived at 6dp, so a legitimate high-quantity line with a >2dp unit price can drift up to qty*0.005. Tighten only after that absorption gap is fixed (design 4.3 pt 4).
    const ORDER_RECONCILIATION_TOLERANCE = 0.02; // Warn-level parity tolerance against cart totals (PrestaShop rounding can drift by up to 2 cents)
    const TAX_RATE_PRECISION = 6; // Decimal precision for line-item tax rates sent to Two (native PrestaShop precision; must stay >= 4dp so the 2dp-of-percent normaliser output survives formatting)
    const TAX_SUBTOTAL_RATE_PRECISION = 2; // Keep tax subtotal grouping stable for compatibility
    const SNAPSHOT_TAX_RATE_PRECISION = 2; // Keep snapshot hash behavior stable across minor rate precision drift
    const TAX_RATE_PERCENT_PRECISION = 2; // Provider expects VAT rates rounded to 2 decimals in percent
    // Two module currency coverage baseline: keep these provider currencies explicitly allowed.
    // Required coverage: NOK, GBP, SEK, USD, DKK, EUR
    const TWO_SUPPORTED_CURRENCY_ISOS = ['NOK', 'GBP', 'SEK', 'USD', 'DKK', 'EUR'];

    // Optional buyer reference fields, in the STANDARD FIELD ORDER: invoice
    // email, purchase order number, project, department. That order is
    // deliberate and shared - the admin switches render in the same sequence as
    // the checkout fields, so the pane reads like the thing it configures.
    // Adding a field means putting it in the right place in BOTH this array and
    // getTwoPaymentSettingsForm()'s input list, not appending to either.
    //
    // The order note is the fifth field in that standard sequence and is
    // deliberately absent from here: it is PrestaShop core's own
    // `delivery_message` textarea on the checkout SHIPPING step, not a field
    // this module renders (ABN-472). So "order note last" has no expression in
    // the payment tile - there is nothing to sort - and no plugin order-note
    // field was invented to give it one. The module relays core's value to Two
    // as `order_note`; see getCartOrderNote().
    //
    // Single source of truth
    // for the admin switch that gates each one, the POST parameter that
    // carries it from the payment tile, and its length/validation shape
    // (ABN-472). Every one of them renders inside the Two payment tile at the
    // payment step: PrestaShop asks for the shipping address FIRST and only
    // reveals the billing address block when the buyer ticks "Billing address
    // differs from shipping address", so a field hosted there - which is where
    // department and project used to live - is invisible to most buyers.
    //
    // The array key is the key this plugin uses internally; the Two payload
    // names are applied at the payload-building call site, because they are
    // not uniform (two `buyer_*` scalars, one conditional scalar, one entry in
    // an `invoice_details` sub-object).
    // Cap on the relayed order note. PrestaShop's `message.message` column is a
    // TEXT, so core imposes no useful bound of its own; this keeps one buyer's
    // pasted essay from being the reason an order-create call is rejected.
    const ORDER_NOTE_MAX_LENGTH = 1000;

    const OPTIONAL_CHECKOUT_FIELDS = array(
        'invoice_email' => array(
            'config' => 'PS_TWO_ENABLE_INVOICE_EMAIL',
            'input' => 'two_invoice_email',
            'type' => 'email',
            'max_length' => 255,
        ),
        'purchase_order_number' => array(
            'config' => 'PS_TWO_ENABLE_PO_NUMBER',
            'input' => 'two_purchase_order_number',
            'type' => 'text',
            'max_length' => 255,
        ),
        'project' => array(
            'config' => 'PS_TWO_ENABLE_PROJECT',
            'input' => 'two_project',
            'type' => 'text',
            'max_length' => 255,
        ),
        'department' => array(
            'config' => 'PS_TWO_ENABLE_DEPARTMENT',
            'input' => 'two_department',
            'type' => 'text',
            'max_length' => 255,
        ),
    );

    // Hidden virtual product that mirrors the Two buyer surcharge as a REAL
    // PrestaShop cart line, so the fee shows in the order summary, cart,
    // order and invoice (not only on the Two-side invoice). Lazily created
    // on first use; identified by Configuration id + reference cross-check.
    const TWO_SURCHARGE_PRODUCT_REFERENCE = 'TWO-SURCHARGE-FEE';
    const CONFIG_SURCHARGE_PRODUCT_ID = 'PS_TWO_SURCHARGE_PRODUCT_ID';
    // Merchant-selected TaxRulesGroup applied to the hidden surcharge
    // product - the SAME id_tax_rules_group field every real Product uses,
    // so the fee line gets PrestaShop's full native tax capability
    // (per-country/state rules, additive stacking, destination-based
    // zero-rating when no rule matches). 0 is core's first-class "No tax"
    // sentinel. TWO-25071 (replaces the module-managed synthetic
    // Tax/TaxRulesGroup/TaxRule graph + flat PS_TWO_SURCHARGE_TAX_RATE).
    const CONFIG_SURCHARGE_TAX_RULES_GROUP = 'PS_TWO_SURCHARGE_TAX_RULES_GROUP';
    // Set by upgrade-2.5.0.php when a pre-release flat surcharge tax rate
    // (PS_TWO_SURCHARGE_TAX_RATE) was configured but no TaxRulesGroup has
    // been selected yet: on upgrade the fee silently became untaxed, so a
    // persistent back-office warning nags until the merchant saves a
    // real TaxRulesGroup selection ("No tax" is refused and does not
    // clear it, since TWO-25279).
    const CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE = 'PS_TWO_SURCHARGE_TAX_MIGRATION_NOTICE';

    // Merchant-declared TaxRulesGroup assumed for SHIPPING when, and only
    // when, the carrier's own declared group cannot be resolved for the order
    // (TWO-25200). PrestaShop keeps the shipping tax declaration on the
    // carrier row (`carrier_tax_rules_group_shop`) and nowhere else, so a
    // merchant who prices shipping outside the carrier table (custom
    // logistics, `id_carrier = 0`) has no carrier row to declare it on. This
    // is that declaration, moved onto the module - still the merchant's own,
    // never inferred from amounts. Unset (the shipped state) keeps the loud
    // refusal; '0' is core's first-class "No tax" sentinel and is only ever
    // stored when the merchant selected it.
    const CONFIG_DEFAULT_SHIPPING_TAX_RULES_GROUP = 'PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP';
    // Per-install activation constant for the field above. Set in
    // `config/defines_custom.inc.php` - PrestaShop core's sanctioned override
    // file, include_once'd by `config/config.inc.php` on every request (front,
    // back office and CLI) before any module loads, preserved across core
    // upgrades, and editable over plain FTP on shared hosting:
    //
    //     define('_TWO_ENABLE_DEFAULT_SHIPPING_TAX_CODE_', true);
    //
    // It gates VISIBILITY of the admin field only. A value already stored by
    // a merchant keeps working if the constant later disappears (a host
    // migration must not silently start declining that merchant's orders),
    // and the save path never writes the key while the field is hidden, so
    // the stored selection survives the field being switched off and on.
    const FLAG_DEFAULT_SHIPPING_TAX_CODE = '_TWO_ENABLE_DEFAULT_SHIPPING_TAX_CODE_';

    // Constants for delivery dates
    const DEFAULT_DELIVERY_DAYS_OFFSET = 7; // Default expected delivery date offset
    
    // Constants for HTTP status codes
    const HTTP_STATUS_OK = 200;
    const HTTP_STATUS_CREATED = 201;
    const HTTP_STATUS_BAD_REQUEST = 400;
    const HTTP_STATUS_UNAUTHORIZED = 401;
    const HTTP_STATUS_FORBIDDEN = 403;
    const HTTP_STATUS_SERVER_ERROR = 500;
    
    // Constants for cookie/session expiry (seconds)
    const COOKIE_EXPIRY_ONE_HOUR = 3600; // 1 hour
    const ATTEMPT_RETENTION_DAYS = 90; // Keep attempt telemetry for 90 days
    const ATTEMPT_CLEANUP_INTERVAL_SECONDS = 86400; // Run cleanup at most once per day
    // TWO-24799: how long a UX-only order-intent decision stays reusable for an
    // unchanged decision snapshot. Deliberately short - this only suppresses the
    // repeat /v1/order_intent round trip behind an identical snapshot hash, and
    // the authoritative check at payment submit
    // (checkTwoOrderIntentApprovalAtPayment) is never served from this cache.
    const ORDER_INTENT_DECISION_CACHE_TTL = 300; // 5 minutes
    // Cross-request cache for the buyer fee-share quote (POST /v1/pricing/order/fee).
    // fetchTwoTermFee() already request-scopes the quote via $this->twoFeeCache, but
    // that array is rebuilt from scratch on every HTTP request (e.g. each order-intent
    // poll from the Payment step), so repeat polls with an unchanged cart/address/term
    // were re-quoting the fee every time. Session-cache the quote for a short TTL,
    // keyed on the same signature (days|gross|country|currency) already used for the
    // request-scoped cache, so it is invalidated the instant any of those change.
    // TWO-25040 / order-intent poll perf.
    const FEE_QUOTE_CACHE_TTL_SECONDS = 60;

    protected $output = '';
    protected $errors = array();
    protected $verifiedMerchantId = null;
    protected $verifiedMerchantShortName = null;
    /** @var string|null Memoised `client_v` value (version + optional +<sha7>) */
    protected $two_client_version_cache = null;

    // Module metadata fields ModuleCore does not declare on all supported
    // PrestaShop versions ($bootstrap was only added to ModuleCore in PS 8;
    // $author_address and $languages are never declared by core), plus this
    // module's own configuration-backed fields. Declared explicitly so they
    // are real properties (not PHP dynamic properties) and visible to
    // static analysis.
    /** @var bool */
    public $bootstrap;
    /** @var string */
    public $author_address;
    /** @var array */
    public $languages = array();
    /** @var string|false */
    public $merchant_short_name;
    /** @var string|false */
    public $api_key;
    /** @var string|false */
    public $enable_company_name;
    /** @var string|false */
    public $enable_department;
    /** @var string|false */
    public $enable_project;
    /** @var int */
    public $enable_order_intent;
    /** @var string|false */
    public $finalize_purchase_shipping;

    public function __construct()
    {
        $this->name = 'twopayment';
        $this->tab = 'payments_gateways';
        $this->version = '2.7.4';
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
        $this->enable_department = Configuration::get('PS_TWO_ENABLE_DEPARTMENT');
        $this->enable_project = Configuration::get('PS_TWO_ENABLE_PROJECT');
        // The two optional-field switches added in 2.7.0
        // (PS_TWO_ENABLE_PO_NUMBER, PS_TWO_ENABLE_INVOICE_EMAIL) deliberately
        // get no property mirror here: isOptionalCheckoutFieldEnabled() is
        // their single reader, and the two mirrors above are read by nothing.
        // Order intent pre-check is mandatory for all checkouts.
        $this->enable_order_intent = 1;
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
                // JSON array, matching install() and the admin form save path - this
                // recovery path used to write a bare status ID, leaving three
                // divergent storage formats for one configuration key.
                Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode(array((int)Configuration::get('PS_OS_SHIPPING'))));
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
            'actionFrontControllerInitAfter',
            'actionObjectOrderDetailAddBefore',
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
            $this->registerHook('actionFrontControllerInitAfter') &&
            $this->registerHook('actionObjectOrderDetailAddBefore') &&
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
        // Optional buyer reference fields, all four rendered in the Two
        // payment tile. Default ON, deliberately: they are the fields a B2B
        // buyer needs to get an invoice routed and reconciled internally, and
        // the cross-platform agreement is that they are visible out of the
        // box. Before this release department/project had NO install default
        // at all, so a fresh shop silently started with both OFF, and the
        // other two fields did not exist.
        Configuration::updateValue('PS_TWO_ENABLE_DEPARTMENT', 1);
        Configuration::updateValue('PS_TWO_ENABLE_PROJECT', 1);
        Configuration::updateValue('PS_TWO_ENABLE_PO_NUMBER', 1);
        Configuration::updateValue('PS_TWO_ENABLE_INVOICE_EMAIL', 1);
        Configuration::updateValue('PS_TWO_ADDRESS_LOOKUP', 1); // Default: address lookup fills the address step, matching every other plugin
        Configuration::updateValue('PS_TWO_FINALIZE_PURCHASE', 1);
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

        // Per-cart last-applied surcharge sync sequence (buyer AJAX ordering
        // guard). Also lazily created in ensureTwoSurchargeSyncTable() for
        // installations upgraded from versions without it.
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'twopayment_surcharge_sync` (
            `id_cart` INT(11) UNSIGNED NOT NULL,
            `seq` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_cart`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';
        // Note: invoice_details (payment info) is NOT stored in DB - fetched from Two API when needed
        // This ensures payment details are always current and avoids stale data issues

        foreach ($sql as $query) {
            if (Db::getInstance()->execute($query) == false) {
                return false;
            }
        }

        // DB-level guard against manual/duplicate fee-product order rows.
        // Best-effort (logs loudly on failure) - must not break install.
        $this->installTwoOrderDetailFeeGuardTrigger();

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
            $this->unregisterHook('actionFrontControllerInitAfter') &&
            $this->unregisterHook('actionObjectOrderDetailAddBefore') &&
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
        Configuration::deleteByName(self::CONFIG_PLATFORM_MIN_ORDER);
        Configuration::deleteByName(self::CONFIG_MERCHANT_MIN_ORDER);
        Configuration::deleteByName(self::CONFIG_MERCHANT_MIN_ORDER_BASIS);
        Configuration::deleteByName('PS_TWO_API_KEY_VERIFIED');
        // Cached verification verdict + its clock (TWO-25326): a verdict left
        // behind belongs to a key this shop no longer has.
        Configuration::deleteByName(self::CONFIG_API_KEY_STATUS);
        Configuration::deleteByName(self::CONFIG_API_KEY_STATUS_TS);
        Configuration::deleteByName('PS_TWO_DISABLE_SSL_VERIFY');
        Configuration::deleteByName('PS_TWO_ENABLE_COMPANY_NAME');
        Configuration::deleteByName('PS_TWO_ADDRESS_LOOKUP');
        // Retired admin toggle (TWO-25190) - the org.id auto-complete switch
        // was rendered and stored but no JavaScript ever read the
        // `company_id_search` variable it fed. upgrade-2.6.5 deletes the row;
        // this covers uninstall-without-upgrade.
        Configuration::deleteByName('PS_TWO_ENABLE_COMPANY_ID');
        Configuration::deleteByName('PS_TWO_ENABLE_DEPARTMENT');
        Configuration::deleteByName('PS_TWO_ENABLE_PROJECT');
        Configuration::deleteByName('PS_TWO_ENABLE_PO_NUMBER');
        Configuration::deleteByName('PS_TWO_ENABLE_INVOICE_EMAIL');
        Configuration::deleteByName('PS_TWO_ENABLE_TAX_SUBTOTALS');
        // Never an admin field and never read for a behavioural decision - the
        // advanced-settings save wrote it blindly on every submit (TWO-24739).
        // upgrade-2.6.4 deletes the row; this covers uninstall-without-upgrade.
        Configuration::deleteByName('PS_TWO_ENABLE_B2B_B2C');
        Configuration::deleteByName('PS_TWO_FINALIZE_PURCHASE');
        // Retired admin toggle (TWO-25166) - sole trader is gated on the
        // registry's country answer alone now. Shops upgraded from <=2.6.2
        // may still carry the row; upgrade-2.6.3 deletes it, this covers
        // uninstall-without-upgrade.
        Configuration::deleteByName('PS_TWO_ENABLE_SOLE_TRADER');
        Configuration::deleteByName('PS_TWO_DEBUG_MODE');
        Configuration::deleteByName(self::CONFIG_MERCHANT_INVOICE_DISTRIBUTED);
        // Retired admin toggle (TWO-25111) - shops upgraded from <=2.5.0 may
        // still carry the row; the upgrade script deletes it, this covers
        // uninstall-without-upgrade.
        Configuration::deleteByName('PS_TWO_USE_OWN_INVOICES');
        $this->deleteTwoSurchargeCartProduct();
        Configuration::deleteByName(self::CONFIG_SURCHARGE_PRODUCT_ID);
        // The merchant's own TaxRulesGroup referenced by this config is NOT
        // module-owned: delete only the reference, never the group.
        Configuration::deleteByName(self::CONFIG_SURCHARGE_TAX_RULES_GROUP);
        // Legacy config rows from the retired synthetic-tax implementation
        // (pre-release builds only; the surcharge feature never shipped with
        // them). The synthetic Tax/TaxRulesGroup/TaxRule objects themselves
        // are no longer created and no longer cleaned up here.
        Configuration::deleteByName('PS_TWO_SURCHARGE_TAX_RATE');
        Configuration::deleteByName('PS_TWO_SURCHARGE_TAX_SETUP');
        Configuration::deleteByName(self::CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE);
        // Like the surcharge group above: the merchant's own TaxRulesGroup is
        // NOT module-owned, so only the reference goes.
        Configuration::deleteByName(self::CONFIG_DEFAULT_SHIPPING_TAX_RULES_GROUP);
        return true;
    }

    /**
     * Best-effort removal of the hidden surcharge product at uninstall.
     * Order details keep their own copied rows, so deleting the product does
     * not damage historical orders. Never blocks uninstall on failure.
     */
    protected function deleteTwoSurchargeCartProduct()
    {
        try {
            $productId = (int) Configuration::get(self::CONFIG_SURCHARGE_PRODUCT_ID);
            if ($productId > 0 && class_exists('Product')) {
                $product = new Product($productId);
                if (Validate::isLoadedObject($product) && method_exists($product, 'delete')) {
                    $product->delete();
                }
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog('TwoPayment: Failed deleting surcharge product at uninstall - ' . $e->getMessage(), 2);
        }
    }

    protected function deleteTwoTables()
    {
        $this->dropTwoOrderDetailFeeGuardTrigger();
        $sql = array();
        $sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'twopayment_surcharge_sync`';
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

        // Post-upgrade nag (upgrade-2.5.0.php): a pre-release flat surcharge
        // tax rate existed but no TaxRulesGroup is selected - the fee is
        // untaxed until the merchant re-selects. Rendered on every config
        // page load until a surcharge-settings save clears the flag.
        $migrationNotice = $this->getTwoSurchargeTaxMigrationNotice();
        if ($migrationNotice !== '') {
            $this->output .= $this->displayWarning($migrationNotice);
        }

        // A stored never-taxed treatment (TWO-25279). displayError, not
        // displayWarning: the shop is charging an untaxed fee and cannot save
        // its Payment settings until this is fixed, which is an error state
        // rather than advice. The migration nag above self-retires once ANY
        // value is stored - including the "No tax" sentinel - so this is the
        // only thing that reports such a shop.
        $neverTaxedNotice = $this->getTwoSurchargeNeverTaxedNotice();
        if ($neverTaxedNotice !== '') {
            $this->output .= $this->displayError($neverTaxedNotice);
        }

        // Stored key that does not currently verify (TWO-25326). displayError,
        // not displayWarning: while this shows, Two is not offered at checkout
        // at all. Rendered from the cached verdict, so opening this page costs
        // at most one live re-check per TTL rather than one per page load - and
        // a save above has already refreshed it.
        $apiKeyNotice = $this->getTwoApiKeyStatusNotice();
        if ($apiKeyNotice !== '') {
            $this->output .= $this->displayError($apiKeyNotice);
        }

        $this->context->smarty->assign(
            array(
                'renderTwoGeneralForm' => $this->renderTwoGeneralForm(),
                'renderTwoPaymentSettingsForm' => $this->renderTwoPaymentSettingsForm(),
                'renderTwoOtherForm' => $this->renderTwoOtherForm(),
                'renderTwoOrderStatusForm' => $this->renderTwoOrderStatusForm(),
                'renderTwoPluginInfo' => $this->renderTwoPluginInfo(),
                'twotabvalue' => Configuration::get('PS_TWO_TAB_VALUE'),
                // The CURRENT verdict, not the sticky save-time flag
                // (TWO-25326): a key that has since expired, been rotated or
                // stopped being reachable would otherwise render the green
                // "verified" panel directly above the red notice saying Two is
                // hidden from checkout.
                'two_api_verified' => (int) $this->isTwoApiKeyVerified(),
                'two_merchant_id' => Configuration::get('PS_TWO_MERCHANT_ID'),
                'two_merchant_short_name' => Configuration::get('PS_TWO_MERCHANT_SHORT_NAME'),
                'two_env' => Configuration::get('PS_TWO_ENVIRONMENT'),
                // Module admin AJAX endpoint for the inline merchant-fee
                // display beside each payment-term checkbox. Dispatched by
                // AdminController::postProcess() to
                // ajaxProcessFetchMerchantFeeRates() on this module.
                'two_fee_rates_url' => $this->context->link->getAdminLink('AdminModules', false)
                    . '&configure=' . $this->name
                    . '&token=' . Tools::getAdminTokenLite('AdminModules')
                    . '&ajax=1&action=FetchMerchantFeeRates',
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
                    // Debug mode lives in General Settings for parity with
                    // Magento's two_general > general group (which includes it).
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

    /**
     * Build the "Available Payment Terms" checkbox rows, restricted to the
     * merchant's backend available_terms (TWO-24813) and falling back to the
     * hardcoded option list on a cold cache. The class drives the client-side
     * STANDARD/EOM show-hide toggle: 30/45/60 are valid under both, the rest are
     * STANDARD-only. Refreshes the cache (admin config render is a sanctioned
     * refresh point).
     *
     * Each label carries an empty `.two-term-fee` placeholder span. The
     * figure that belongs on this screen is the FEE TWO CHARGES THE MERCHANT
     * for offering that term (NOT the buyer surcharge - a prior change
     * wrongly appended a buyer-surcharge rate preview here and was reverted).
     * The span is populated live by admin AJAX against
     * POST /pricing/v1/merchant/rates (fetchTwoMerchantFeeRates /
     * ajaxProcessFetchMerchantFeeRates), mirroring magento-plugin's
     * Controller/Adminhtml/Config/Fees.php + payment-terms-config.js: fees
     * are never pre-fetched synchronously on page render, and on API failure
     * the span silently stays empty so the admin page never breaks. The raw
     * HTML is safe here: the core HelperForm checkbox template emits the
     * label name unescaped ({$value[$input.values.name]}).
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
                'name' => sprintf($this->l('%d days'), $term)
                    . ' <span class="two-term-fee text-muted" data-term="' . $term . '"></span>',
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
        $fields_values['PS_TWO_DEBUG_MODE'] = Tools::getValue('PS_TWO_DEBUG_MODE', Configuration::get('PS_TWO_DEBUG_MODE'));
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
            // Held for the SAVE to publish, not published here (TWO-25326).
            // Validation can still fail on an unrelated field - an empty title,
            // a bad environment - in which case nothing is stored and this
            // verdict describes a key the shop does not have.
            $this->verifiedApiKeyResult = $verify;
            // Unless the key AND environment being validated are the stored ones,
            // in which case the verdict describes the live shop whatever happens
            // to the rest of the form - and is the only way a FAILING verdict ever
            // gets published from this page, since a failing key adds an error and
            // the save never runs (review round 2).
            //
            // The environment half is not decoration (review round 3). The check
            // above ran against the SUBMITTED environment while the slot is keyed
            // to the STORED one, which the skipped save leaves unchanged - so a
            // merchant merely switching the dropdown to an environment their key
            // is not valid for would otherwise publish an 'invalid_key' verdict
            // against their still-perfectly-good stored configuration, and take
            // Two off a healthy checkout over a save that never happened.
            if ((string) $apiKey === (string) Configuration::get('PS_TWO_MERCHANT_API_KEY')
                && (string) $env === (string) Configuration::get('PS_TWO_ENVIRONMENT')) {
                $this->cacheTwoApiKeyVerificationStatus($apiKey, $verify);
            }
            if ($verify['status'] !== self::API_KEY_STATUS_OK) {
                // Category-specific, so the merchant is not left choosing
                // between "my key is wrong" and "Two is down" (TWO-25326).
                $this->errors[] = $this->getTwoApiKeyFailureMessage($verify['status'], $verify['code']);
            } else {
                $body = isset($verify['body']) && is_array($verify['body']) ? $verify['body'] : array();
                if (!isset($body['id']) || !isset($body['short_name'])) {
                    $this->errors[] = $this->l('Invalid verification response from Two.');
                } else {
                    $this->verifiedMerchantId = $body['id'];
                    $this->verifiedMerchantShortName = $body['short_name'];
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
        Configuration::updateValue('PS_TWO_DEBUG_MODE', Tools::getValue('PS_TWO_DEBUG_MODE'));
        // The verdict from the live check the validation above just made, now
        // that the key it describes is the stored one (TWO-25326). This is the
        // freshest that key will ever have had, so it becomes what the checkout
        // gates read: a merchant who has just fixed a broken key sees Two
        // return to checkout at once instead of waiting out the TTL.
        if (is_array($this->verifiedApiKeyResult)) {
            $this->cacheTwoApiKeyVerificationStatus(
                trim(Tools::getValue('PS_TWO_MERCHANT_API_KEY')),
                $this->verifiedApiKeyResult
            );
        }

        if ($this->verifiedMerchantId) {
            if ((string) Configuration::get('PS_TWO_MERCHANT_ID') !== (string) $this->verifiedMerchantId) {
                // Merchant identity changed: drop the cached term list so
                // serve-stale never bridges the old merchant's terms (TWO-24813).
                $this->invalidateMerchantAvailableTerms();
                // Same for the FX refresh clock (TWO-25184): the rates
                // themselves are merchant-independent, so the last-known-good
                // TABLE stays (it is the gate's only fallback), but the new
                // identity may be a different environment - and a clock still
                // inside its 6h TTL would suppress the warm-up fetch that
                // follows this save for up to six hours.
                Configuration::updateValue(self::CONFIG_FX_RATES_TS, 0);
            }
            Configuration::updateValue('PS_TWO_MERCHANT_ID', $this->verifiedMerchantId);
            Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 1);
        } else {
            // Ensure flag not stale when verification fails/non-run
            Configuration::updateValue('PS_TWO_API_KEY_VERIFIED', 0);
        }

        // Warm the FX cache (TWO-25184). The API key has just been written,
        // so this is the earliest moment a refdata fetch can authenticate,
        // and the module has no scheduler of its own: without this the FIRST
        // shopper to reach checkout pays the fetch inline, and every
        // conversion in that request fails closed if it fails, because no
        // table has ever been stored. refreshTwoFxRates is TTL/backoff-gated
        // and bumps its clock before the wire call, so repeated saves cannot
        // hammer the endpoint; it also no-ops without an API key.
        $this->refreshTwoFxRates();

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

        // Checkout fields (Magento two_payment > checkout_fields parity):
        // optional buyer inputs, placed after the term selection and before
        // the surcharge configuration.
        //
        // All four render inside the Two payment tile at the payment step, NOT
        // in the billing address block. PrestaShop asks for the SHIPPING
        // address first and only reveals the billing block when the buyer ticks
        // "Billing address differs from shipping address", so anything hosted
        // there is invisible to most buyers - which is exactly what happened to
        // department and project before this release.
        //
        // ORDER IS LOAD-BEARING and must stay in step with
        // self::OPTIONAL_CHECKOUT_FIELDS, which is what the checkout tile
        // iterates: invoice email, purchase order number, project, department.
        // The switches read top-to-bottom in the same sequence the buyer sees.
        // The order note completes that standard sequence but has no switch and
        // no tile field - it is PrestaShop core's own `delivery_message` on the
        // shipping step (see the constant's comment).
        $inputs[] = array(
            'type' => 'switch',
            'label' => $this->l('Show Invoice email field'),
            'name' => 'PS_TWO_ENABLE_INVOICE_EMAIL',
            'is_bool' => true,
            'desc' => $this->l('If you choose YES then customers will see an invoice email field in the Two payment section at checkout. It sits with the payment method rather than the address so the buyer is prompted to consider a dedicated invoicing address even when their billing and shipping addresses match.'),
            'required' => true,
            'values' => array(
                array(
                    'id' => 'PS_TWO_ENABLE_INVOICE_EMAIL_ON',
                    'value' => 1,
                    'label' => $this->l('Yes')
                ),
                array(
                    'id' => 'PS_TWO_ENABLE_INVOICE_EMAIL_OFF',
                    'value' => 0,
                    'label' => $this->l('No')
                ),
            ),
        );

        $inputs[] = array(
            'type' => 'switch',
            'label' => $this->l('Show PO Number field'),
            'name' => 'PS_TWO_ENABLE_PO_NUMBER',
            'is_bool' => true,
            'desc' => $this->l('If you choose YES then customers will see a PO Number field in the Two payment section at checkout.'),
            'required' => true,
            'values' => array(
                array(
                    'id' => 'PS_TWO_ENABLE_PO_NUMBER_ON',
                    'value' => 1,
                    'label' => $this->l('Yes')
                ),
                array(
                    'id' => 'PS_TWO_ENABLE_PO_NUMBER_OFF',
                    'value' => 0,
                    'label' => $this->l('No')
                ),
            ),
        );
        $inputs[] = array(
            'type' => 'switch',
            'label' => $this->l('Show Project field'),
            'name' => 'PS_TWO_ENABLE_PROJECT',
            'is_bool' => true,
            'desc' => $this->l('If you choose YES then customers will see a project field in the Two payment section at checkout.'),
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
        );
        $inputs[] = array(
            'type' => 'switch',
            'label' => $this->l('Show Department field'),
            'name' => 'PS_TWO_ENABLE_DEPARTMENT',
            'is_bool' => true,
            'desc' => $this->l('If you choose YES then customers will see a department field in the Two payment section at checkout.'),
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
        );
        // Offset pricing fee (buyer surcharge) fields — appended so the
        // per-term grid reflects the merchant's currently-offered terms.
        // TWO-24752 / TWO-24893.
        $inputs = array_merge($inputs, $this->getTwoSurchargeFormInputs());

        // Minimum order value (TWO-24775): the merchant's own optional bar,
        // stacked on top of the API-resolved platform minimum. Field UX
        // mirrors woocommerce-plugin / magento-plugin: currency in the label
        // ("Minimum Order Value, EUR"), platform floor in the description,
        // net/gross basis selector.
        $default_currency_iso = $this->getTwoShopDefaultCurrencyIso();
        $inputs[] = array(
            'type' => 'text',
            'label' => $default_currency_iso !== ''
                ? sprintf($this->l('Minimum Order Value, %s'), $default_currency_iso)
                : $this->l('Minimum Order Value'),
            'name' => 'PS_TWO_MERCHANT_MIN_ORDER',
            'desc' => $this->getTwoMerchantMinimumOrderDescription(),
        );
        $inputs[] = array(
            'type' => 'select',
            'label' => $this->l('Minimum Order Value Tax Basis'),
            'name' => 'PS_TWO_MERCHANT_MIN_ORDER_BASIS',
            'desc' => $this->l('Whether the basket is compared against the minimum including or excluding tax.'),
            'options' => array(
                'query' => array(
                    array('id_option' => 'gross', 'name' => $this->l('Including tax (gross)')),
                    array('id_option' => 'net', 'name' => $this->l('Excluding tax (net)')),
                ),
                'id' => 'id_option',
                'name' => 'name',
            ),
        );

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

        // Checkout fields (moved from the former "Other Settings" tab).
        // Standard field order, same as the form inputs and the checkout tile.
        $fields_values['PS_TWO_ENABLE_INVOICE_EMAIL'] = Tools::getValue('PS_TWO_ENABLE_INVOICE_EMAIL', Configuration::get('PS_TWO_ENABLE_INVOICE_EMAIL'));
        $fields_values['PS_TWO_ENABLE_PO_NUMBER'] = Tools::getValue('PS_TWO_ENABLE_PO_NUMBER', Configuration::get('PS_TWO_ENABLE_PO_NUMBER'));
        $fields_values['PS_TWO_ENABLE_PROJECT'] = Tools::getValue('PS_TWO_ENABLE_PROJECT', Configuration::get('PS_TWO_ENABLE_PROJECT'));
        $fields_values['PS_TWO_ENABLE_DEPARTMENT'] = Tools::getValue('PS_TWO_ENABLE_DEPARTMENT', Configuration::get('PS_TWO_ENABLE_DEPARTMENT'));

        // Payment terms checkboxes
        $payment_terms = array_map('strval', self::PAYMENT_TERMS_OPTIONS);
        foreach ($payment_terms as $term) {
            $fields_values['PS_TWO_PAYMENT_TERMS_' . $term] = Tools::getValue('PS_TWO_PAYMENT_TERMS_' . $term, Configuration::get('PS_TWO_PAYMENT_TERMS_' . $term));
        }

        $fields_values['PS_TWO_MERCHANT_MIN_ORDER'] = Tools::getValue(
            'PS_TWO_MERCHANT_MIN_ORDER',
            Configuration::get(self::CONFIG_MERCHANT_MIN_ORDER)
        );
        $saved_basis = Configuration::get(self::CONFIG_MERCHANT_MIN_ORDER_BASIS);
        $fields_values['PS_TWO_MERCHANT_MIN_ORDER_BASIS'] = Tools::getValue(
            'PS_TWO_MERCHANT_MIN_ORDER_BASIS',
            in_array($saved_basis, array('net', 'gross'), true) ? $saved_basis : 'gross'
        );

        $fields_values = array_merge($fields_values, $this->getTwoSurchargeFormValues());

        return $fields_values;
    }

    /**
     * Dynamic description for the Minimum Order Value field: shows the
     * platform minimum the merchant's value must meet or exceed, in the shop
     * default currency when a conversion rate is available (with the native
     * figure alongside when the currencies differ). Mirrors
     * woocommerce-plugin's get_merchant_minimum_order_description() with
     * Two's own FX rates (/refdata/v1/fx-rates, TWO-25105) filling the gap
     * WooCommerce has (TWO-24776).
     *
     * @return string
     */
    protected function getTwoMerchantMinimumOrderDescription()
    {
        $platform_minimum = $this->getPlatformMinimumOrder();
        if (!$platform_minimum) {
            return $this->l('Hide the payment method below this order value (shop default currency, on the tax basis selected below). Leave empty for no minimum.');
        }
        $basis_label = $platform_minimum['basis'] === 'gross'
            ? $this->l('including')
            : $this->l('excluding');
        $native_display = $platform_minimum['amount'] . ' ' . $platform_minimum['currency'];
        $shop_iso = $this->getTwoShopDefaultCurrencyIso();
        $floor = $this->convertTwoAmountBetweenCurrencies(
            $platform_minimum['amount'],
            $platform_minimum['currency'],
            $shop_iso
        );
        if ($floor === null) {
            return sprintf(
                $this->l('Platform minimum %1$s, %2$s tax. A value here is interpreted in the shop default currency on the tax basis selected below; it cannot be checked against the platform minimum (no conversion rate) - both minimums are enforced independently.'),
                $native_display,
                $basis_label
            );
        }
        $floor_display = $shop_iso === $platform_minimum['currency']
            ? $native_display
            : $floor . ' ' . $shop_iso . ' (' . $native_display . ')';
        return sprintf(
            $this->l('Platform minimum %1$s, %2$s tax. A value here is interpreted in the shop default currency on the tax basis selected below and must be at least the platform minimum.'),
            $floor_display,
            $basis_label
        );
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

        // Minimum order value (TWO-24775): numeric and non-negative always;
        // at least the platform floor when one is resolved AND expressible in
        // the shop default currency. A floor that cannot be converted (no
        // rate) skips the numeric check - the checkout gate enforces both
        // minima independently (magento-plugin beforeSave parity).
        $raw_minimum = trim((string) Tools::getValue('PS_TWO_MERCHANT_MIN_ORDER'));
        if ($raw_minimum !== '') {
            $normalised = str_replace(',', '.', $raw_minimum);
            if (!is_numeric($normalised) || (float) $normalised < 0) {
                $this->errors[] = $this->l('Minimum Order Value must be a non-negative number.');
            } elseif ((float) $normalised > 0) {
                $platform_minimum = $this->getPlatformMinimumOrder();
                if ($platform_minimum) {
                    $floor = $this->convertTwoAmountBetweenCurrencies(
                        $platform_minimum['amount'],
                        $platform_minimum['currency'],
                        $this->getTwoShopDefaultCurrencyIso()
                    );
                    if ($floor !== null && (float) $normalised < $floor) {
                        $this->errors[] = sprintf(
                            $this->l('Minimum Order Value must be at least the platform minimum of %1$s, %2$s tax.'),
                            $floor . ' ' . $this->getTwoShopDefaultCurrencyIso()
                                . ($this->getTwoShopDefaultCurrencyIso() !== $platform_minimum['currency']
                                    ? ' (' . $platform_minimum['amount'] . ' ' . $platform_minimum['currency'] . ')'
                                    : ''),
                            $platform_minimum['basis'] === 'gross' ? $this->l('including') : $this->l('excluding')
                        );
                    }
                }
            }
        }
        $basis = (string) Tools::getValue('PS_TWO_MERCHANT_MIN_ORDER_BASIS');
        if ($basis !== '' && !in_array($basis, array('net', 'gross'), true)) {
            $this->errors[] = $this->l('Minimum Order Value Tax Basis must be either including or excluding tax.');
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

        // Checkout fields (moved from the former "Other Settings" tab).
        // Standard field order, same as the form inputs and the checkout tile.
        Configuration::updateValue('PS_TWO_ENABLE_INVOICE_EMAIL', Tools::getValue('PS_TWO_ENABLE_INVOICE_EMAIL'));
        Configuration::updateValue('PS_TWO_ENABLE_PO_NUMBER', Tools::getValue('PS_TWO_ENABLE_PO_NUMBER'));
        Configuration::updateValue('PS_TWO_ENABLE_PROJECT', Tools::getValue('PS_TWO_ENABLE_PROJECT'));
        Configuration::updateValue('PS_TWO_ENABLE_DEPARTMENT', Tools::getValue('PS_TWO_ENABLE_DEPARTMENT'));

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

        // Minimum order value (TWO-24775). Normalise the decimal comma; store
        // '' when empty (no merchant minimum). Values reaching here passed
        // validTwoPaymentSettingsFormValues (the caller aborts on errors).
        $raw_minimum = trim((string) Tools::getValue('PS_TWO_MERCHANT_MIN_ORDER'));
        Configuration::updateValue(
            self::CONFIG_MERCHANT_MIN_ORDER,
            $raw_minimum === '' ? '' : str_replace(',', '.', $raw_minimum)
        );
        $basis = (string) Tools::getValue('PS_TWO_MERCHANT_MIN_ORDER_BASIS');
        if (in_array($basis, array('net', 'gross'), true)) {
            Configuration::updateValue(self::CONFIG_MERCHANT_MIN_ORDER_BASIS, $basis);
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
                    'title' => $this->l('Advanced Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enable company search in address entry'),
                        'name' => 'PS_TWO_ENABLE_COMPANY_NAME',
                        'is_bool' => true,
                        // TWO-25326 §7.1 (2026-08-03 design ruling): this switch now
                        // governs WHERE the one company-search control (dropdown /
                        // query field / manual entry) renders, not whether it exists
                        // - the control is never off.
                        //
                        // SENTENCE CASE, matching every other label on this
                        // page ("Autofill company address", "Automatically
                        // fulfill orders with Two",
                        // "Send tax subtotals in request payloads"). This was
                        // Title Case for cross-platform word-for-word parity
                        // with woocommerce-plugin/magento-plugin; house style
                        // on this page wins, so the capitalisation - and ONLY
                        // the capitalisation - now differs from those plugins.
                        //
                        // BEHAVIOUR CHANGE for shops that already have this switched
                        // OFF: before this ticket, "No" turned company search off
                        // entirely (a plain, unsearched text field). It now instead
                        // relocates the search into the payment tile - those shops
                        // will see company search appear for the first time, just
                        // not in the address area.
                        'desc' => $this->l('When enabled, the buyer may search for their company within the address entry section of the checkout. Otherwise, company search will be visible within the payment method.'),
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
                        // TWO-25326: caption aligned word-for-word with the
                        // magento-plugin and woocommerce-plugin settings for the
                        // same switch. Sentence case, as everything else on this
                        // page is, so the alignment costs no house style here.
                        // Only the CAPTION changed - the setting key, its
                        // default and every behaviour it governs are untouched.
                        'label' => $this->l('Autofill company address'),
                        'name' => 'PS_TWO_ADDRESS_LOOKUP',
                        'is_bool' => true,
                        'desc' => $this->l('Governs the company address lookup on the checkout ADDRESS step only. When enabled, picking a company from the company search overwrites the address fields (street, postcode, city) and the organisation-number fields (DNI / VAT number) with the registry data for that company - including on a re-search, where picking a different company replaces the previous company\'s values. When disabled, the company search still works and still records the company name and organisation number, but nothing is written into the address or identifier fields and the customer fills them in themselves. This setting is unavailable and forced off when "Enable company search in address entry" is set to "No" - there is no address-area lookup to govern once the search itself has moved to the payment tile.'),
                        'required' => true,
                        'values' => array(
                            array(
                                'id' => 'PS_TWO_ADDRESS_LOOKUP_ON',
                                'value' => 1,
                                'label' => $this->l('Yes')
                            ),
                            array(
                                'id' => 'PS_TWO_ADDRESS_LOOKUP_OFF',
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
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );

        // Hidden unless the install opts in (TWO-25200). Ordinary shops
        // declare shipping VAT on their carriers and must not be offered a
        // second place to do it; the field exists for merchants who price
        // shipping outside the carrier table entirely.
        if ($this->isTwoDefaultShippingTaxCodeFieldEnabled()) {
            $fields_form['form']['input'][] = array(
                'type' => 'select',
                'label' => $this->l('Default shipping tax code'),
                'name' => self::CONFIG_DEFAULT_SHIPPING_TAX_RULES_GROUP,
                'desc' => $this->l('Tax rules group ASSUMED FOR SHIPPING ONLY when the carrier\'s tax rate cannot be resolved for the order - for example when shipping is priced outside PrestaShop\'s carrier table, so no carrier declares a tax rules group. It is never used when a carrier does declare one: the carrier\'s own group always wins. Leave unset to keep refusing such orders rather than assuming a rate.'),
                'options' => array(
                    'query' => $this->getTwoDefaultShippingTaxRulesGroupOptions(),
                    'id' => 'id',
                    'name' => 'name',
                ),
            );
        }

        return $fields_form;
    }

    /**
     * Effective value of the address-lookup toggle (TWO-25203), as the '1'/'0'
     * string the checkout JS compares against.
     *
     * An absent row means an install carrying the pre-toggle behaviour whose
     * upgrade script has not run yet. That behaviour was always-on, so absent
     * resolves to enabled - a missing row must never silently disable the fill.
     *
     * @return string
     */
    protected function getAddressLookupEnabled()
    {
        // Forced off while the company search is not in the address area
        // (TWO-25326 §7.1 follow-up). Derived from the STORED position rather
        // than through isAddressLookupSettingAvailable(), which also consults
        // the request: this method is read on the front office, and a resolver
        // the checkout runs must not be steerable by a query parameter.
        //
        // Gating the READ as well as the save is what keeps the admin form,
        // the stored row and the value handed to the checkout JS from
        // disagreeing on an install that has not re-saved its advanced
        // settings since the search moved into the payment tile.
        if ($this->isCompanySearchInAddressArea() !== '1') {
            return '0';
        }

        $value = Configuration::get('PS_TWO_ADDRESS_LOOKUP');

        if ($value === false || $value === null || $value === '') {
            return '1';
        }

        return ((int) $value) === 1 ? '1' : '0';
    }

    /**
     * Effective value of PS_TWO_ENABLE_COMPANY_NAME, as the '1'/'0' string
     * the checkout JS compares against - '1' means the company-search
     * control renders in the address area, '0' means it has relocated to
     * the payment tile (TWO-25326 §7.1, 2026-08-03 design ruling).
     *
     * This is "here vs there" for the ONE shared control (TwoCompanySearch.js),
     * never "on vs off" - the control always exists somewhere. Reusing this
     * existing switch rather than adding a new one is a deliberate BEHAVIOUR
     * CHANGE for shops that already have it set to "No": that used to turn
     * company search off entirely, and now relocates it to the payment tile
     * instead (see the switch's own 'desc' in getTwoOtherForm()).
     *
     * An absent row resolves to '1' (address area) - the install default,
     * and the only behaviour that ever existed before this switch could mean
     * anything else.
     *
     * @return string
     */
    protected function isCompanySearchInAddressArea()
    {
        $value = Configuration::get('PS_TWO_ENABLE_COMPANY_NAME');

        if ($value === false || $value === null || $value === '') {
            return '1';
        }

        return ((int) $value) === 1 ? '1' : '0';
    }

    /**
     * Is the address-lookup switch (PS_TWO_ADDRESS_LOOKUP) available at all?
     *
     * It governs what a company selection writes into the checkout ADDRESS
     * step, so it means nothing once the company search itself has moved out
     * of the address step and into the payment tile - there is no address-area
     * lookup left to govern (TWO-25326 §7.1). In that state it is not merely
     * inert, it is forced off: greyed out in the admin form by the config
     * page's JS, rendered as "No", and refused on save however it was posted.
     * Mirrors woocommerce-plugin's admin.js, which disables and unchecks its
     * `enable_address_lookup` field whenever company search is off.
     *
     * Reads the SUBMITTED company-search position where there is one, falling
     * back to the stored one, so the same POST that turns the search into a
     * tile control also disables the lookup - rather than leaving it enabled
     * for one save cycle.
     *
     * @return bool
     */
    protected function isAddressLookupSettingAvailable()
    {
        $posted = Tools::getValue('PS_TWO_ENABLE_COMPANY_NAME', $this->isCompanySearchInAddressArea());

        return (string) $posted === '1';
    }

    /**
     * ISO 3166-1 alpha-2 country of the cart's billing address, or '' when
     * there is no usable one yet.
     *
     * Handed to the checkout JS so the company search can establish which
     * company register to query when the address form - and with it the
     * country select the browser side reads - is not on the page. That is the
     * normal state of the payment step, and therefore the normal state of the
     * payment-tile-mounted search control (TWO-25326 §7.1).
     *
     * Resolves or returns empty, exactly like the browser-side chain it feeds:
     * no shop-country fallback, no geolocation. A wrong register is worse than
     * no register, because the buyer is shown companies from a country their
     * company is not registered in with nothing on screen saying so.
     *
     * @return string uppercase ISO code, or '' when unresolvable
     */
    protected function getCheckoutBillingCountryIso()
    {
        $cart = isset($this->context->cart) ? $this->context->cart : null;
        if (!Validate::isLoadedObject($cart) || (int) $cart->id_address_invoice === 0) {
            return '';
        }

        $address = new Address((int) $cart->id_address_invoice);
        if (!Validate::isLoadedObject($address)) {
            return '';
        }

        $iso = Country::getIsoById((int) $address->id_country);

        return is_string($iso) ? Tools::strtoupper($iso) : '';
    }

    /**
     * Dropdown options for the default shipping tax code. Same construction
     * as getTwoSurchargeTaxRulesGroupOptions() - string ids so PHP 7's loose
     * `'' == 0` cannot conflate the unselected placeholder with "No tax", and
     * a currently-configured group that has been deactivated is always kept
     * in the list (suffixed "(inactive)") so an unrelated save cannot silently
     * drop the merchant's selection to the first option.
     *
     * @return array<int,array{id:string,name:string}>
     */
    protected function getTwoDefaultShippingTaxRulesGroupOptions()
    {
        $options = array(
            array('id' => '', 'name' => $this->l('-- Not set: refuse the order instead --')),
            array('id' => '0', 'name' => $this->l('No tax')),
        );
        $seen = array(0 => true);
        foreach ((array) TaxRulesGroup::getTaxRulesGroups(true) as $group) {
            if (!isset($group['id_tax_rules_group'])) {
                continue;
            }
            $id = (int) $group['id_tax_rules_group'];
            $options[] = array('id' => (string) $id, 'name' => (string) $group['name']);
            $seen[$id] = true;
        }

        $configured = $this->getTwoDefaultShippingTaxRulesGroupId();
        if ($configured !== null && $configured > 0 && !isset($seen[$configured])) {
            $group = new TaxRulesGroup($configured);
            if (Validate::isLoadedObject($group)) {
                $options[] = array(
                    'id' => (string) $configured,
                    'name' => (string) $group->name . ' (' . $this->l('inactive') . ')',
                );
            }
        }

        return $options;
    }

    /**
     * Pre-selection for the default shipping tax code dropdown: the stored
     * selection, else '' (unselected). Never auto-defaults - not to "No tax"
     * either, which is a tax treatment rather than the absence of one.
     *
     * @return string
     */
    protected function getTwoDefaultShippingTaxRulesGroupFormDefault()
    {
        $configured = $this->getTwoDefaultShippingTaxRulesGroupId();

        return $configured === null ? '' : (string) $configured;
    }

    protected function getTwoOtherFormValues()
    {
        $fields_values = array();
        // Read through the same default-on resolver the checkout uses, so an
        // install whose upgrade script has not run yet renders the switch in
        // the position it is actually behaving in (TWO-25326 §7.1: this is
        // also the address-area/payment-tile location switch now).
        $fields_values['PS_TWO_ENABLE_COMPANY_NAME'] = Tools::getValue('PS_TWO_ENABLE_COMPANY_NAME', $this->isCompanySearchInAddressArea());
        // Rendered through the same gate the save enforces, so the switch is
        // never drawn in a position the module will not honour: the
        // address-area lookup is unavailable, and shown off, whenever the
        // company search itself is not in the address area. The gate reads the
        // POSTED company-search value where there is one, so a failed-
        // validation re-render agrees with what the merchant just submitted
        // rather than with the stored row.
        $fields_values['PS_TWO_ADDRESS_LOOKUP'] = $this->isAddressLookupSettingAvailable()
            ? Tools::getValue('PS_TWO_ADDRESS_LOOKUP', $this->getAddressLookupEnabled())
            : '0';
        $fields_values['PS_TWO_FINALIZE_PURCHASE'] = Tools::getValue('PS_TWO_FINALIZE_PURCHASE', Configuration::get('PS_TWO_FINALIZE_PURCHASE'));
        $fields_values['PS_TWO_ENABLE_TAX_SUBTOTALS'] = Tools::getValue('PS_TWO_ENABLE_TAX_SUBTOTALS', Configuration::get('PS_TWO_ENABLE_TAX_SUBTOTALS', 1));
        $fields_values['PS_TWO_DISABLE_SSL_VERIFY'] = Tools::getValue('PS_TWO_DISABLE_SSL_VERIFY', Configuration::get('PS_TWO_DISABLE_SSL_VERIFY'));
        if ($this->isTwoDefaultShippingTaxCodeFieldEnabled()) {
            // Kept a STRING: an (int) cast would turn the unselected state
            // into 0 and silently pre-select "No tax".
            $fields_values[self::CONFIG_DEFAULT_SHIPPING_TAX_RULES_GROUP] = (string) Tools::getValue(
                self::CONFIG_DEFAULT_SHIPPING_TAX_RULES_GROUP,
                $this->getTwoDefaultShippingTaxRulesGroupFormDefault()
            );
        }
        return $fields_values;
    }

    protected function validTwoOtherFormValues()
    {
        if (!$this->isTwoDefaultShippingTaxCodeFieldEnabled()) {
            return;
        }
        $raw = Tools::getValue(self::CONFIG_DEFAULT_SHIPPING_TAX_RULES_GROUP, false);
        if ($raw === false) {
            return;
        }
        $trimmed = is_string($raw) ? trim($raw) : '';
        if ($trimmed === '') {
            // Unselected is a legitimate, and the shipped, state: it means
            // "keep refusing". Nothing to validate.
            return;
        }
        $group_id = ctype_digit($trimmed) ? (int) $trimmed : -1;
        if ($group_id < 0 || ($group_id > 0 && !Validate::isLoadedObject(new TaxRulesGroup($group_id)))) {
            $this->errors[] = $this->l('Default shipping tax code must be "No tax" or one of the shop\'s existing tax rules groups.');
        }
    }

    protected function saveTwoOtherFormValues()
    {
        // BEFORE the writes below: the gate reads the submitted (or, absent a
        // submission, the currently stored) company-search position, and
        // updateValue() would otherwise have already overwritten the stored
        // row this falls back to.
        $address_lookup_available = $this->isAddressLookupSettingAvailable();

        Configuration::updateValue('PS_TWO_ENABLE_COMPANY_NAME', Tools::getValue('PS_TWO_ENABLE_COMPANY_NAME'));
        // Server-side half of the "unavailable when the search is not in the
        // address area" gate (TWO-25326 §7.1 follow-up). The admin JS greys
        // the switch out, and a disabled control posts nothing - but a
        // hand-crafted or replayed POST can still carry a ticked box, and it
        // must not take effect. Matched by the same gate in
        // getTwoOtherFormValues() so the rendered position never disagrees
        // with what is stored.
        Configuration::updateValue(
            'PS_TWO_ADDRESS_LOOKUP',
            $address_lookup_available ? (int) Tools::getValue('PS_TWO_ADDRESS_LOOKUP', 1) : 0
        );
        Configuration::updateValue('PS_TWO_FINALIZE_PURCHASE', Tools::getValue('PS_TWO_FINALIZE_PURCHASE'));
        Configuration::updateValue('PS_TWO_ENABLE_TAX_SUBTOTALS', (int) Tools::getValue('PS_TWO_ENABLE_TAX_SUBTOTALS', 1));
        Configuration::updateValue('PS_TWO_DISABLE_SSL_VERIFY', (int) Tools::getValue('PS_TWO_DISABLE_SSL_VERIFY', 0));

        // Write the default shipping tax code ONLY when the field was
        // actually rendered AND actually submitted. A form that never showed
        // the field posts nothing for it, and blindly writing Tools::getValue
        // with a '' default there would wipe a stored declaration on the next
        // unrelated advanced-settings save - exactly the failure mode the
        // payment-terms checkbox loop was fixed for under TWO-24813.
        if ($this->isTwoDefaultShippingTaxCodeFieldEnabled()) {
            $raw = Tools::getValue(self::CONFIG_DEFAULT_SHIPPING_TAX_RULES_GROUP, false);
            if ($raw !== false) {
                $trimmed = is_string($raw) ? trim($raw) : '';
                $value = '';
                if ($trimmed !== '' && ctype_digit($trimmed)) {
                    $group_id = (int) $trimmed;
                    if ($group_id === 0 || Validate::isLoadedObject(new TaxRulesGroup($group_id))) {
                        $value = (string) $group_id;
                    }
                }
                Configuration::updateValue(self::CONFIG_DEFAULT_SHIPPING_TAX_RULES_GROUP, $value);
            }
        }

        $this->output .= $this->displayConfirmation($this->l('Advanced settings are updated.'));
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
        $commit_hash = $this->getTwoDeployedCommitHash();
        $deployed_at = $this->getTwoDeployedAtLabel();
        $version_line = $this->l('Plugin Version:') . ' ' . $this->version . ' | ' . $this->l('PrestaShop:') . ' ' . _PS_VERSION_;
        if ($commit_hash !== null) {
            $version_line .= ' | ' . $this->l('Commit:') . ' ' . htmlspecialchars($commit_hash, ENT_QUOTES, 'UTF-8');
        }
        if ($deployed_at !== null) {
            $version_line .= ' | ' . $this->l('Deployed:') . ' ' . htmlspecialchars($deployed_at, ENT_QUOTES, 'UTF-8');
        }

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
                    <li style="margin-bottom:8px;"><i class="icon-info-circle text-info"></i> <strong>' . $this->l('Amount mismatch errors?') . '</strong> ' . $this->l('Enable Debug Mode in General Settings and contact Two support with the logs') . '</li>
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
                <p style="margin-top:15px;"><small class="text-muted">' . $version_line . '</small></p>
            </div>
        </div>';
        
        return $html;
    }

    /**
     * Best-effort short commit hash of the deployed module code.
     *
     * Resolution order — live state first, build-time stamp last (TWO-25194):
     *   1. `.git` gitlink FILE (git-synced shops: reflects what is checked out RIGHT NOW)
     *   2. `.git` DIRECTORY (local dev checkout)
     *   3. `.two-deployed-commit` sidecar (frozen at package-release.sh build time,
     *      so it goes stale if an artifact tree is later checked out over, and an
     *      interrupted release run can leave one behind in the working tree)
     *
     * Each source falls THROUGH to the next when it cannot produce a valid sha;
     * null is only returned when all three fail.
     * Plain file reads only — no exec. Returns null (never throws/fatals) if unavailable.
     *
     * @param string|null $git_dir Overridable for tests; defaults to this module's .git
     * @param string|null $sidecar_file Overridable for tests; defaults to this module's sidecar file
     *
     * @return string|null
     */
    protected function getTwoDeployedCommitHash($git_dir = null, $sidecar_file = null)
    {
        if ($git_dir === null) {
            $git_dir = __DIR__ . '/.git';
        }
        if ($sidecar_file === null) {
            $sidecar_file = __DIR__ . '/.two-deployed-commit';
        }

        // 1. Git-synced staging shops materialise the module as a linked worktree, so
        // `.git` is a gitlink FILE, not a directory:
        //   gitdir: ../../.git/worktrees/<40-hex-sha>
        // The last path segment is the commit the sync loop checked out.
        if (is_file($git_dir) && is_readable($git_dir)) {
            $gitlink_contents = (string) @file_get_contents($git_dir);
            if (preg_match('#gitdir:\s*.*/([0-9a-f]{7,40})\s*$#i', $gitlink_contents, $gitlink_match)) {
                return substr($gitlink_match[1], 0, 7);
            }
        }

        // 2. Plain `.git` directory (local dev checkout).
        $git_dir_sha = $this->readShaFromGitDirectory($git_dir);
        if ($git_dir_sha !== null) {
            return $git_dir_sha;
        }

        // 3. Deploy-time sidecar stamp, last because it is frozen at build time.
        if (is_file($sidecar_file) && is_readable($sidecar_file)) {
            $sidecar_contents = trim((string) @file_get_contents($sidecar_file));
            if ($sidecar_contents !== '' && preg_match('/^[0-9a-f]{7,40}$/i', $sidecar_contents)) {
                return substr($sidecar_contents, 0, 7);
            }
        }

        return null;
    }

    /**
     * Resolve the short HEAD sha from a plain `.git` DIRECTORY.
     * Plain file reads only — no exec. Returns null when it cannot be resolved.
     *
     * @param string $git_dir
     *
     * @return string|null
     */
    private function readShaFromGitDirectory($git_dir)
    {
        if (!is_dir($git_dir) || !is_readable($git_dir)) {
            return null;
        }

        $head_file = $git_dir . '/HEAD';
        if (!is_file($head_file) || !is_readable($head_file)) {
            return null;
        }

        $head_contents = trim((string) @file_get_contents($head_file));
        if ($head_contents === '') {
            return null;
        }

        $sha = null;
        if (strpos($head_contents, 'ref:') === 0) {
            $ref_path = trim(substr($head_contents, 4));
            $ref_file = $git_dir . '/' . $ref_path;
            if (is_file($ref_file) && is_readable($ref_file)) {
                $sha = trim((string) @file_get_contents($ref_file));
            } else {
                // Fall back to packed-refs (loose ref file may not exist after a gc/pack).
                $packed_refs_file = $git_dir . '/packed-refs';
                if (is_file($packed_refs_file) && is_readable($packed_refs_file)) {
                    $packed = @file($packed_refs_file, FILE_IGNORE_NEW_LINES);
                    if (is_array($packed)) {
                        foreach ($packed as $line) {
                            // Skip comments/pragmas ("# pack-refs...") and peeled-tag lines ("^<sha>").
                            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                                continue;
                            }
                            $space_pos = strpos($line, ' ');
                            if ($space_pos === false) {
                                continue;
                            }
                            $line_ref = substr($line, $space_pos + 1);
                            if ($line_ref === $ref_path) {
                                $sha = substr($line, 0, $space_pos);
                                break;
                            }
                        }
                    }
                }
            }
        } elseif (preg_match('/^[0-9a-f]{40}$/i', $head_contents)) {
            // Detached HEAD: file contains the sha directly.
            $sha = $head_contents;
        }

        if (!$sha || !preg_match('/^[0-9a-f]{7,40}$/i', $sha)) {
            return null;
        }

        return substr($sha, 0, 7);
    }

    /**
     * Best-effort deployment timestamp label based on this file's mtime.
     * Returns null (never throws/fatals) if unavailable.
     *
     * @return string|null
     */
    private function getTwoDeployedAtLabel()
    {
        $mtime = @filemtime(__FILE__);
        if (!$mtime) {
            return null;
        }
        return date('Y-m-d H:i:s', $mtime);
    }

    /**
     * Render a compact operational health summary for plugin configuration.
     *
     * @return string HTML
     */
    protected function renderTwoPluginHealthChecklist()
    {
        $environment = (string) Configuration::get('PS_TWO_ENVIRONMENT', 'development');
        // Same live verdict the checkout gate uses (TWO-25326) - a health row
        // reporting "Verified" while Two is being withheld is worse than no row.
        $api_verified = $this->isTwoApiKeyVerified();
        $ssl_disabled = (bool) Configuration::get('PS_TWO_DISABLE_SSL_VERIFY');
        $merchant_short_name = (string) Configuration::get('PS_TWO_MERCHANT_SHORT_NAME');

        $status_rows = array(
            array(
                'label' => $this->l('API key'),
                'value' => $api_verified
                    ? $this->l('Verified') . ($merchant_short_name !== '' ? ' (' . htmlspecialchars($merchant_short_name, ENT_QUOTES, 'UTF-8') . ')' : '')
                    : $this->l('Not verified'),
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
        
        // Handle multi-select for fulfillment statuses.
        //
        // PrestaShop core's HelperForm::generate() rewrites a multi-select field's
        // name in place ($params['name'] .= '[]', classes/helper/HelperForm.php) and
        // the form template then looks the pre-selection up under that rewritten
        // name ($fields_value[$input.name] in
        // admin/themes/default/template/helpers/form/form.tpl). That is the same in
        // PS 1.7.6.x, 8.x and 9.x, so the '[]'-suffixed key is the one that decides
        // which options render as selected; the plain key is kept populated for any
        // reader that addresses the field by its declared name.
        //
        // The IDs are normalised to strings because the template compares each
        // stored value against the option's id_order_state, which comes back from
        // the database as a string; a strict comparison in a future PS release
        // would otherwise silently drop every pre-selection.
        $fulfilled_map = Configuration::get('PS_TWO_OS_FULFILLED_MAP');
        $fulfilled_ids = $fulfilled_map ? json_decode($fulfilled_map, true) : null;
        if (!is_array($fulfilled_ids)) {
            // Backward compatibility: a single bare status ID (the pre-2.1.2 format,
            // and what the custom-state recovery path wrote before 2.6.2).
            $fulfilled_ids = $fulfilled_map ? array($fulfilled_map) : array(Configuration::get('PS_OS_SHIPPING'));
        }
        $fulfilled_ids_value = array_values(array_map('strval', array_filter(array_map('intval', $fulfilled_ids))));
        $fields_values['PS_TWO_OS_FULFILLED_MAP'] = $fulfilled_ids_value;
        $fields_values['PS_TWO_OS_FULFILLED_MAP[]'] = $fulfilled_ids_value;
        
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
                            
                            // Invoice Upload: upload the PrestaShop invoice to Two when the
                            // merchant's invoice_distributed_by_merchant flag is set (TWO-25111).
                            // Prime the merchant-record cache first (TTL-gated, a no-op while
                            // fresh): fulfilment may be the first merchant-record touch since
                            // deploy/upgrade, and the gate must not read an unresolved flag and
                            // silently skip the upload for a one-shot fulfilment transition.
                            // This path already makes synchronous Two calls, so one more
                            // capped GET (at most once per TTL) is acceptable here.
                            $this->getMerchantAvailableTerms(true);
                            $use_own_invoices = $this->isMerchantInvoiceDistributed();
                            PrestaShopLogger::addLog(
                                'TwoPayment: Invoice upload check - invoice_distributed_by_merchant=' . ($use_own_invoices ? 'YES' : 'NO') . ', Order ID=' . $id_order,
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
     * optional) - confirmed against the refund endpoint's documented request
     * contract - so we avoid mapping PrestaShop's credit-slip product list to
     * Two line items.
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
            // TWO-25326 §7.3 (2026-08-03 design ruling): the tile is
            // text-only when the address-area control is active - no
            // separate company name/number label, just these two sentences
            // with the company folded straight in. Exact wording, matched
            // by the cross-platform test script - do not paraphrase.
            'invoice_likely_accepted_for' => $this->l('This order by %s (%s) is likely to be accepted by Two'),
            'invoice_cannot_be_approved_for' => $this->l('Two is not available for this order by %s (%s)'),
            // Name-only fallback: a company captured without an organisation
            // number (should not occur once §6 gating is enforced, but kept
            // so a stray no-number case never renders "Example Ltd ()").
            'invoice_likely_accepted_for_no_number' => $this->l('This order by %s is likely to be accepted by Two'),
            'invoice_cannot_be_approved_for_no_number' => $this->l('Two is not available for this order by %s'),
            'invoice_likely_accepted' => $this->l('Your invoice with Two is likely to be accepted, subject to additional checks.'),
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
            'invalid_invoice_email' => $this->l('Please enter a valid invoice email address, or leave the field empty.'),
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
            'company_search_searching' => $this->l('Searching...'),
            'company_search_unavailable' => $this->l('Company search is temporarily unavailable. Please try again.'),
            // Distinct from company_search_unavailable on purpose: nothing is
            // broken and retrying will not help. The search could not establish
            // which country's company register to query, and the only action
            // that resolves it is the buyer selecting a country.
            'company_search_select_country' => $this->l('Select your country above to search for your company.'),
            // Placeholder for the empty company field. Also set server-side by
            // the address-form override; this copy is what reaches a theme that
            // renders its own address form, and what survives PrestaShop
            // replacing the input on an address-form update.
            'company_search_placeholder' => $this->l('Enter company name to search'),
            // The same slot, reworded for manual entry. The search wording is
            // an instruction the field stops honouring the moment the buyer
            // chooses "my company is not on the list" - it no longer searches
            // anything, it is the plain input they type into - so leaving it
            // there tells them to do something that will not happen. Only ever
            // swapped for the search wording above, never over a placeholder a
            // theme supplied; see syncCompanyFieldPlaceholder().
            'company_manual_placeholder' => $this->l('Enter your company name'),
            // Query field placeholder once the buyer has clicked into the
            // search panel (TWO-40 follow-up). Folds the separate "Please
            // enter %d or more characters" dropdown-row message this key used
            // to hold into the placeholder itself, so the length requirement
            // is not duplicated on screen alongside a placeholder that used to
            // just repeat the unclicked field's own watermark wording. `%d` is
            // deliberately left UNRESOLVED here for the same reason as
            // `end_of_month_plus_days` above: the browser JS holds the one
            // threshold constant and interpolates it, so the number this
            // sentence claims cannot drift from the number the search
            // enforces.
            'company_search_query_placeholder' => $this->l('Enter %d or more characters'),
            // The query field's accessible NAME, deliberately a different
            // string from the placeholder above (adversarial review finding,
            // TWO-40 follow-up round 2). `aria-label` is set once and never
            // re-synced, so naming the field after the length-requirement hint
            // left a screen reader still announcing "Enter N or more
            // characters" as what the field IS long after the buyer has typed
            // enough - see getQueryAriaLabelText() in TwoCompanySearch.js.
            'company_search_query_label' => $this->l('Search for a company'),
            // The manual-entry CHIP (TWO-25288, reworked by TWO-25326, reworked
            // again TWO-40) and the reverse link out of the manual-entry mode
            // it switches to. TWO-40 replaced the plain-link wording "My
            // company is not on the list" with a short chip label -
            // deliberately DIVERGES from the other three plugins' current
            // wording for this affordance, pending their own rollout of the
            // same three-chip pattern.
            'company_search_manual_entry' => $this->l('Enter Manually'),
            'company_search_back_to_search' => $this->l('Search for company'),
            // Zero-result wording (TWO-25326 §1). EXACT across all four
            // plugins - "No results found" is a different string and the
            // cross-platform test script checks for this one verbatim.
            'company_search_no_matches' => $this->l('No matches found'),
            // Accessible name for the company-name field once it acts as the
            // trigger that opens the search panel (TWO-25326 §1). Its visible
            // value is the confirmed company name itself.
            'company_search_edit' => $this->l('Search for a different company'),
            // The three-chip mode selector (TWO-40 design revision). Shown
            // immediately on interacting with the search control, no
            // upfront choice OUTSIDE the control any more, no waiting for
            // characters to be typed. "Registered Company" is the default;
            // "Enter Manually" (above) is always visible alongside it;
            // "Sole Trader" is added to the set only when the registry says
            // the currently-selected billing country supports sole traders
            // (TwoSoleTrader::isAvailable), and removed again live if the
            // buyer changes the country selector to one that does not.
            'company_search_registered_entry' => $this->l('Registered Company'),
            'company_search_sole_trader_entry' => $this->l('Sole Trader'),
            // Fallback status label shown once enrolment succeeds but the
            // registration carries no displayable company name or number
            // (TwoCompanyNumber.forDisplay() answers '' for both a blank name
            // and a suppressed `TWO:`-prefixed number). Distinct from the row
            // above: that one is a first-person prompt to START enrolling,
            // this is a noun phrase describing what the buyer already IS.
            'sole_trader_status_label' => $this->l('Sole trader'),
        );

        // Checkout media render is a sanctioned refresh point for the backend
        // term list (TWO-24813); prime the cache before the cache-only reads
        // in getAvailablePaymentTerms / getDefaultPaymentTerm below.
        $this->getMerchantAvailableTerms(true);
        // Same sanctioned refresh point keeps the FX table warm (TTL-gated
        // 6h, TWO-25105) so cross-currency conversions on the checkout hot
        // path hit the cache instead of fetching inline.
        $this->refreshTwoFxRates();

        Media::addJsDef(array('twopayment' => array(
                'search_empty_text' => $this->l('No result found'),
                'checkout_host' => $this->getTwoCheckoutHostUrl(),
                // TWO-25326 §7.1 (2026-08-03 ruling): this used to gate the
                // search widget's existence (on/off). It now decides WHERE
                // the one control renders instead: '1' = address area
                // (default, unchanged behaviour), '0' = the same control
                // relocates into the payment tile. Never a second control,
                // never fully off.
                'company_name_search' => $this->isCompanySearchInAddressArea(),
                // TWO-25326: may the company-search affordance render? A real
                // PHP bool, so addJsDef emits a real JS boolean.
                //
                // Withheld on a known verification failure because the only
                // thing that NEEDS a captured company is a Two order, and Two is
                // not offered at all in that state (hookPaymentOptions withholds
                // it on any non-ok verdict). The selection does still write the
                // shop's own address record - which is the cost noted below, not
                // a reason to keep the affordance. What is left is a Two-branded search
                // whose result nothing consumes, and a "verify your company"
                // journey that cannot complete - in tile mode the tile is
                // already gone with the payment option, and the ADDRESS-step
                // control would otherwise stay behind on its own.
                //
                // NOT because the search needs the key: that endpoint is called
                // unauthenticated (TwoCompanySearch.buildPublicApiBeforeSend()
                // strips the auth headers deliberately), so it would keep
                // working. Round-6 review corrected the reasoning here - the
                // behaviour matches the sibling plugins either way, and the
                // cost of the gate is a search + address auto-fill a buyer
                // could otherwise still have used.
                //
                // The SAME predicate the address-form override asks (review round
                // 5), because the JS control and the server-rendered placeholder
                // are two halves of one affordance and this flag is that
                // affordance's only reader. Asking "verified?" here while the
                // override asks "warranted?" left them disagreeing on exactly one
                // state - a claim in flight with nothing to carry - which is the
                // state where a shop with a back-office translation of the core
                // placeholder kept the hint on a field with no search behind it.
                //
                // A live check ONLY on the real checkout page (review round 4).
                // This hook also runs on the module's own front controllers, and
                // one of those is the payment POST - where the verification gate
                // deliberately refuses to make an HTTP call, because a stall
                // there is a stall in the buyer's submit. Letting the media hook
                // make it on the same request handed back exactly the stall the
                // gate had just declined to take. Those pages render no
                // company-search control anyway, so a cache-only answer costs
                // them nothing.
                'api_key_verified' => $this->isTwoCompanySearchAffordanceWarranted($is_checkout_page),
                // Separate from company_name_search: that (now) gates only
                // WHERE the search widget renders, this gates only what a
                // selection writes into the address step (TWO-25203) - and
                // only matters at all when the control is in the address area.
                'address_lookup' => $this->getAddressLookupEnabled(),
                // Deliberately NOT handing the optional-field switches to the
                // JS (ABN-472). Nothing ever read `enable_department` /
                // `enable_project` here - the gate is server-side and total:
                // a disabled field renders no input in the tile and declares
                // no hidden twin in the payment form, so there is nothing for
                // the client to hide, and nothing for it to decide. Adding
                // `enable_po_number` / `enable_invoice_email` alongside two
                // already-dead keys would just have grown the dead set.
                'enable_order_intent' => $this->enable_order_intent,
                'shop_country' => (string) Context::getContext()->country->iso_code,
                // The country of the cart's OWN billing address, resolved
                // server-side (TWO-25326 §7.1 follow-up). This is what makes
                // the company search work at all once the control has moved
                // into the payment tile: PrestaShop only renders the address
                // FORM - and therefore `select[name='id_country']`, the only
                // thing TwoCompanySearch.getCurrentCountry() could read -
                // while the buyer is actually editing an address
                // (checkout/_partials/steps/addresses.tpl renders
                // address-form.tpl behind `$show_delivery_address_form`).
                // On the payment step that form is gone and the step shows an
                // address SELECTOR instead, so the tile-mounted control could
                // never resolve a country and declined to search on every
                // keystroke.
                //
                // Not a guess and not a substitute for the select: it is the
                // country of the address the order will actually be billed
                // to, and getCurrentCountry() still prefers the live select
                // whenever one is on the page (a buyer mid-edit may have
                // picked a country that is not saved yet).
                'billing_country' => $this->getCheckoutBillingCountryIso(),
                'order_intent_url' => $this->context->link->getModuleLink($this->name, 'orderintent'),
                'ajax_token' => Tools::getToken(false),
                'module_dir' => $this->_path,
                'client' => 'PS',
                'client_version' => $this->version,
                'countries' => $param_countries,
                'available_payment_terms' => $this->getAvailablePaymentTerms(),
                'default_payment_term' => $this->getDefaultPaymentTerm(),
                // Enables the checkout JS to mirror the buyer surcharge as a
                // real PrestaShop cart line on payment-option selection.
                'surcharge_cart_line' => !empty($this->getTwoSurchargeSettings()['enabled']),
                'payment_term_type' => Configuration::get('PS_TWO_PAYMENT_TERM_TYPE'),
                // Per-brand order-intent APPROVED notice, TWO-25218. TWO KEYS,
                // deliberately separate: one boolean decides on/off, one string
                // overrides the wording. They are not collapsed back into a
                // single key - the previous single-key design expressed "off"
                // as the absence of copy, so an unfinished string and an
                // intentional off switch were indistinguishable.
                //
                // A real PHP bool, so addJsDef emits a real JS boolean and the
                // consumers can gate on `typeof === 'boolean'` rather than on
                // the falsiness of a copy string.
                'intent_approved_notice_enabled' => $this->isIntentApprovedNoticeEnabled(),
                // Copy override only, and a sibling of 'i18n' rather than a key
                // inside it because a non-empty value is used verbatim rather
                // than translated. Empty/absent is inert here: it means default
                // copy, never off.
                'intent_approved_notice' => $this->getIntentApprovedNotice(),
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
        // Cache-busting: 'version' is PrestaShop's own register{Stylesheet,Javascript}()
        // param (classes/controller/FrontController.php), applied by core AFTER it
        // resolves the plain relative path to a real file via getFullPath()'s
        // file_exists() check (classes/assets/AbstractAssetManager.php). See TWO-53PS -
        // assets were previously served with no version/cache-busting at all, so a
        // stale copy could be served for hours after deploy.
        //
        // NOT appended to the path itself (the previous, broken approach): getFullPath()
        // does a literal file_exists() on the exact relativePath string to resolve it to
        // a real file on disk before core ever turns it into a URL. A path with a
        // "?v=<mtime>" suffix is never a real file, so that check silently fails and
        // JavascriptManager::register() / StylesheetManager::register() drop the asset
        // entirely with no error, exception, or log line - it just never enters the
        // render list. That is what PR #127 (TWO-53PS) shipped and broke checkout: config
        // (Media::addJsDef) rendered fine because it doesn't go through this path, but
        // every registerJavascript()/registerStylesheet() call silently no-opped.
        $this->context->controller->registerStylesheet('two-css', $this->getTwoModuleAssetPath('views/css/two.css'), array('priority' => 200, 'media' => 'all', 'version' => $this->getTwoAssetVersion('views/css/two.css')));

        // CRITICAL FIX: Remove async loading and ensure proper load order for reliable initialization
        // Ensures they load AFTER jQuery
        // Shared company-number DISPLAY rule (TWO-25326 §12), used by both the
        // search control and the order-intent sentence - so it has to be in
        // place before either of them, hence a priority below both.
        $this->context->controller->registerJavascript('two-company-number', $this->getTwoModuleAssetPath('views/js/modules/TwoCompanyNumber.js'), array('priority' => 200, 'async' => false, 'version' => $this->getTwoAssetVersion('views/js/modules/TwoCompanyNumber.js')));
        $this->context->controller->registerJavascript('two-company-search', $this->getTwoModuleAssetPath('views/js/modules/TwoCompanySearch.js'), array('priority' => 201, 'async' => false, 'version' => $this->getTwoAssetVersion('views/js/modules/TwoCompanySearch.js')));
        $this->context->controller->registerJavascript('two-order-intent', $this->getTwoModuleAssetPath('views/js/modules/TwoOrderIntent.js'), array('priority' => 202, 'async' => false, 'version' => $this->getTwoAssetVersion('views/js/modules/TwoOrderIntent.js')));
        $this->context->controller->registerJavascript('two-sole-trader', $this->getTwoModuleAssetPath('views/js/modules/TwoSoleTrader.js'), array('priority' => 204, 'async' => false, 'version' => $this->getTwoAssetVersion('views/js/modules/TwoSoleTrader.js')));
        $this->context->controller->registerJavascript('two-optional-fields', $this->getTwoModuleAssetPath('views/js/modules/TwoOptionalFields.js'), array('priority' => 204, 'async' => false, 'version' => $this->getTwoAssetVersion('views/js/modules/TwoOptionalFields.js')));
        // TwoCompanySummary.js (read-only tile label, TWO-25288) REMOVED by
        // TWO-25326 §7.3 (2026-08-03 ruling): the captured company now lives
        // only inside the intent-message sentence, never a separate label.
        // Phone validation removed - Two API handles phone number validation
        $this->context->controller->registerJavascript('two-checkout-manager', $this->getTwoModuleAssetPath('views/js/modules/TwoCheckoutManager.js'), array('priority' => 205, 'async' => false, 'version' => $this->getTwoAssetVersion('views/js/modules/TwoCheckoutManager.js')));
        $this->context->controller->registerJavascript('two-script', $this->getTwoModuleAssetPath('views/js/twopayment.js'), array('priority' => 206, 'async' => false, 'version' => $this->getTwoAssetVersion('views/js/twopayment.js')));
    }

    /**
     * Plain module-relative asset path for register{Javascript,Stylesheet}(), e.g.
     * 'modules/twopayment/views/js/twopayment.js'. Deliberately carries NO query
     * string: core resolves this exact string to a real file via file_exists()
     * before it ever becomes a URL (classes/assets/AbstractAssetManager.php
     * getFullPath()), so anything that isn't a literal path on disk is silently
     * dropped - no exception, no log line, the asset just never renders. Version
     * the asset via the 'version' param on the register{Stylesheet,Javascript}()
     * call instead (see getTwoAssetVersion()), which core applies to the URL only
     * after that resolution has already succeeded.
     *
     * @param string $relative_path path relative to this module's own directory,
     *                               e.g. 'views/js/twopayment.js'
     *
     * @return string 'modules/twopayment/<relative_path>'
     */
    private function getTwoModuleAssetPath($relative_path)
    {
        return 'modules/' . $this->name . '/' . ltrim($relative_path, '/');
    }

    /**
     * Cache-busting value for the register{Stylesheet,Javascript}() 'version' param:
     * the asset file's mtime, or null if it can't be stat'd (e.g. moved/missing) -
     * core treats a null/falsy 'version' as "don't version this asset" and falls
     * back to the plain resolved URL, matching this module's existing best-effort
     * filemtime usage elsewhere (getTwoDeployedAtLabel).
     *
     * PrestaShop only reads 'version' on 8.0+ (classes/controller/FrontController.php);
     * on the 1.7.x versions this module still supports the array key is simply
     * ignored, so assets load unversioned there rather than not loading at all.
     *
     * @param string $relative_path path relative to this module's own directory
     *
     * @return string|null filemtime as a string, or null
     */
    private function getTwoAssetVersion($relative_path)
    {
        $mtime = @filemtime(rtrim($this->local_path, '/') . '/' . ltrim($relative_path, '/'));

        return $mtime ? (string) $mtime : null;
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
                $this->getTwoModuleAssetPath('views/css/two.css'),
                array('media' => 'all', 'priority' => 200, 'version' => $this->getTwoAssetVersion('views/css/two.css'))
            );

            return;
        }

        if (method_exists($controller, 'addCSS')) {
            // addCSS() has no 'version' param and its legacy path (Controller::addCSS()
            // -> getAssetUriFromLegacyDeprecatedMethod() -> registerStylesheet($id, $uri))
            // hits the exact same file_exists()-on-a-query-string trap as above if a
            // "?v=..." suffix is appended here - unversioned but loading beats versioned
            // and silently dropped. This branch only runs on controllers old enough to
            // lack registerStylesheet() at all, so it's not worth a parallel version
            // mechanism.
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

        // API-key verification gate (TWO-25326). Withhold Two whenever the
        // stored key cannot currently be verified, for ANY reason - a rejected
        // key, a Two service error, or this shop being unable to reach Two at
        // all. Offering a payment method whose integration is not answering
        // hands the buyer a dead end at the last step of checkout. Cached, so
        // this costs a Configuration read per render, not an HTTP call.
        $apiKeyStatus = $this->getTwoApiKeyVerificationStatus();
        if ($apiKeyStatus['status'] !== self::API_KEY_STATUS_OK) {
            // Log it: a silently absent payment method is precisely the
            // "nobody could tell why" failure this ticket exists to remove.
            // Once per request, not once per evaluation: PrestaShop asks for
            // payment options several times per payment-step render, and a
            // broken shop with traffic would otherwise fill ps_log with the
            // same line rather than making it findable. Instance-scoped, not a
            // static: a static would also silence the SECOND shop in a
            // multistore request, and every spec after the first in a suite
            // run.
            if (!$this->twoApiKeyWithholdLogged) {
                $this->twoApiKeyWithholdLogged = true;
                PrestaShopLogger::addLog(
                    'TwoPayment: Payment option hidden - API key verification status "' . $apiKeyStatus['status'] . '"'
                    . ($apiKeyStatus['code'] ? ' (HTTP ' . (int) $apiKeyStatus['code'] . ')' : ''),
                    2
                );
            }
            return [];
        }

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

        // Country-restriction gate (TWO-25387). The merchant's native per-module
        // country allowlist was only enforced by core at final order submission,
        // so a buyer outside it saw the tile and was refused at the last step.
        // Withhold it at display time instead, exactly like the currency check
        // above. See checkCountry().
        if (!$this->checkCountry($cart, $billing_address)) {
            // Once per request, not once per evaluation - the same reason the
            // API-key gate above uses an instance flag. This gate is worse than
            // that one for repetition: a narrow allowlist is a permanent
            // merchant setting, so EVERY out-of-allowlist buyer trips it on
            // every payment-step render, and core asks for payment options
            // several times per render.
            if (!$this->twoCountryWithholdLogged) {
                $this->twoCountryWithholdLogged = true;
                $idCountry = (int)$billing_address->id_country;
                PrestaShopLogger::addLog(
                    'TwoPayment: Payment option hidden - '
                    // Distinct wording: an address with no country at all is a
                    // different fault from a country the merchant disallowed,
                    // and "country 0 not enabled" sends the reader to the
                    // Payment Restrictions screen for nothing.
                    . ($idCountry > 0
                        ? 'billing country ' . $idCountry . ' not enabled for this module'
                        : 'billing address carries no country')
                    . ', cart ' . (int)$cart->id,
                    2
                );
            }
            return [];
        }

        // Minimum-order gate (TWO-24775): hide the payment option when the
        // cart is below the platform minimum (API-resolved, cache-only read
        // primed by the checkout media hook) or the merchant's own configured
        // minimum. Display/UX gate only - the API still enforces the platform
        // minimum server-side at order creation.
        if (!$this->isTwoMinimumOrderSatisfied($cart)) {
            return [];
        }

        // Surcharge-quotability gate (TWO-25269): hide the payment option
        // when a configured buyer surcharge cannot be denominated in the
        // cart currency. Same mechanism as the minimum-order gate above -
        // withholding the option, never erroring the checkout - because the
        // alternative outcome is an order created with NO surcharge at all,
        // a silent undercharge. See isTwoSurchargeQuotableForCart.
        if (!$this->isTwoSurchargeQuotableForCart($cart)) {
            return [];
        }

        // B2B checkout: Two shows for any company-bearing buyer. There is
        // no account-type selector to also gate on (TWO-24755 rework) -
        // the front-end prompts for the company at payment time when
        // needed, and sole traders enrol from an entry point folded into
        // the company search control itself (TWO-40); there is no separate
        // upfront chip choosing between the two any more. The order-intent
        // pre-check enforces a verified company + org number either way.
        PrestaShopLogger::addLog('TwoPayment: Payment option shown', 1);
        
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

        $optional_fields = $this->getOptionalCheckoutFieldsForDisplay();

        // Sole-trader AVAILABILITY, resolved HERE rather than in the browser
        // (TWO-25326 bug 9, round 3; TWO-40 removed the chip UI this used to
        // drive). TwoSoleTrader.js used to build Business / Sole trader chips
        // only after its own availability round trip, so they were absent from
        // every first paint of the payment step and appeared a few hundred
        // milliseconds later - a visible flicker on any page load. There is no
        // toggle to flicker any more (TWO-40 folded sole-trader enrolment into
        // the company search control), but the same server-resolved answer is
        // still what TwoSoleTrader.js adopts as its settled availability cache,
        // so the search control's "I'm a sole trader" row can appear on first
        // paint with no round trip of its own either.
        //
        // Same source of truth as the endpoint that JS was calling
        // (TwoSoleTrader::isAvailable -> the registry's supported-company-types
        // answer), so the markup can never disagree with what the client would
        // have been told. Cost is bounded: that answer is memoised per request
        // and cached in the context cookie for the endpoint's own max-age, and
        // it REPLACES the per-page-load AJAX call rather than adding to it.
        // THREE-state, not two (round 3 review). A registry timeout and a genuine
        // business-only country are both `false` to isAvailable() - right for a
        // capability gate, wrong here, because the browser adopts this answer as
        // settled and never re-asks, so flattening a blip into "no" would launder
        // it into a cached "no" for the rest of the page's life. "Unresolved"
        // renders as NO answer and the client's own retrying request path stays
        // live.
        //
        // CACHE-ONLY, and never a live call (round 3 review, finding 2). This runs
        // inside a shopper's checkout render, and a payment-option change reloads
        // that page - so resolving live meant every payment-step render on a shop
        // that cannot reach the registry paid the request timeout again (the
        // per-request failure marker bounds it per request, not per session, since
        // only a success is cached). The browser's availability request resolves an
        // unknown answer off the render path and the endpoint answering it writes
        // the cookie, so at most the FIRST payment-step render of a session shows
        // no toggle and every render after it - including all the surcharge-driven
        // reloads that made the flicker visible - is served from cache. Same shape
        // as this module's other checkout-render reads.
        $sole_trader_country = $this->getCheckoutBillingCountryIso();
        $sole_trader_resolved = $sole_trader_country === ''
            ? false
            : TwoSoleTrader::resolveAvailabilityFromCache($sole_trader_country);
        $sole_trader_available = $sole_trader_resolved === true;

        // Order intent is now handled on frontend via AJAX
        $this->context->smarty->assign(array(
            // The handover to TwoSoleTrader.adoptServerRenderedToggle(). Three
            // vars, each doing one job:
            //  - `sole_trader_answer` is what the BROWSER adopts: '1', '0', or ''
            //    for unresolved. A pre-rendered string rather than a nested {if},
            //    so the template stays one condition deep.
            //  - `sole_trader_available` is kept for template parity/debugging
            //    even though the template no longer draws a toggle from it
            //    (TWO-40); the browser-side cache is what actually gates the
            //    company-search "I'm a sole trader" row now.
            //  - `sole_trader_country` is the country the answer is ABOUT, so a
            //    later or different country is re-resolved client-side rather than
            //    trusting a stale render.
            'sole_trader_answer' => $sole_trader_resolved === null ? '' : ($sole_trader_resolved ? '1' : '0'),
            'sole_trader_available' => $sole_trader_available,
            'sole_trader_country' => $sole_trader_country,
            'subtitle' => $subtitle,
            'enable_order_intent' => $this->enable_order_intent,
            'payment_enable' => true, // Always enable, frontend will handle approval
            'message' => '',
            'module_dir' => $this->_path, // Module directory path for assets
            'two_portal_url' => $this->getTwoPortalUrl(), // Dynamic portal URL based on environment
            // Optional buyer reference fields, rendered inside the tile rather
            // than in the billing address block (ABN-472).
            'two_optional_fields' => $optional_fields,
            // TWO-25326 §7.1 (2026-08-03 ruling): "here vs there" for the ONE
            // company-search control (TwoCompanySearch.js), driven by the
            // EXISTING PS_TWO_ENABLE_COMPANY_NAME switch rather than a new
            // setting. When true, the tile renders its own mount point for
            // that control and the address-area control is suppressed
            // client-side.
            'company_search_tile' => $this->isCompanySearchInAddressArea() !== '1',
        ));

        $inputs = ['token' => ['name' => 'token', 'type' => 'hidden', 'value' => Tools::getToken(false)]];
        // The tile markup (additionalInformation) and the module's payment form
        // are SIBLINGS in PrestaShop's payment step template, not nested - the
        // form only ever contains the inputs declared here. So each visible
        // field in the tile gets a hidden twin inside the form, and
        // TwoOptionalFields.js mirrors the value across on input and again on
        // submit. No hidden twin is declared for a disabled field, which is
        // what keeps a disabled field out of the POST entirely.
        foreach ($optional_fields as $field) {
            $inputs[$field['input_name']] = array(
                'name' => $field['input_name'],
                'type' => 'hidden',
                'value' => '',
            );
        }

        $preTwoOption = new PaymentOption();
        $preTwoOption->setModuleName($this->name)
            ->setCallToActionText($title)
            ->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true))
            ->setInputs($inputs)
            ->setAdditionalInformation($this->context->smarty->fetch('module:twopayment/views/templates/hook/paymentinfo.tpl'));

        return $preTwoOption;
    }

    /**
     * Whether one optional buyer reference field is switched on.
     *
     * Strict: an absent Configuration row reads as OFF. There is deliberately
     * no default-on fallback here - install() seeds all four keys to 1 and
     * upgrade-2.7.0 writes them on existing shops, so the stored row IS the
     * default. A getter fallback would make the admin switch disagree with the
     * rendered checkout on any shop whose row was missing.
     *
     * @param string $key One of the keys of self::OPTIONAL_CHECKOUT_FIELDS
     * @return bool
     */
    public function isOptionalCheckoutFieldEnabled($key)
    {
        // array_key_exists rather than isset() on a class-constant subscript:
        // the module supports PrestaShop back to 1.7.6, so it has to parse on
        // the oldest PHP that floor allows, and passing the whole constant as
        // an argument is unambiguously fine on all of them.
        if (!array_key_exists($key, self::OPTIONAL_CHECKOUT_FIELDS)) {
            return false;
        }
        $definition = self::OPTIONAL_CHECKOUT_FIELDS;

        return (int) Configuration::get($definition[$key]['config']) === 1;
    }

    /**
     * The enabled optional fields, in render order, with everything the tile
     * template and the payment form need.
     *
     * @return array<string,array<string,string>>
     */
    public function getOptionalCheckoutFieldsForDisplay()
    {
        $labels = array(
            'department' => $this->l('Department'),
            'project' => $this->l('Project'),
            'purchase_order_number' => $this->l('PO Number'),
            'invoice_email' => $this->l('Invoice email address'),
        );
        $placeholders = array(
            'department' => '',
            'project' => '',
            'purchase_order_number' => '',
            'invoice_email' => '',
        );

        $fields = array();
        foreach (self::OPTIONAL_CHECKOUT_FIELDS as $key => $definition) {
            if (!$this->isOptionalCheckoutFieldEnabled($key)) {
                continue;
            }
            $fields[$key] = array(
                'key' => $key,
                'input_name' => $definition['input'],
                'type' => $definition['type'],
                'max_length' => (string) $definition['max_length'],
                'label' => $labels[$key],
                'placeholder' => $placeholders[$key],
            );
        }

        return $fields;
    }

    /**
     * The buyer's order comment for this cart, for Two's `order_note`.
     *
     * This is PrestaShop CORE's field, not one of ours: the "If you would like
     * to add a comment about your order" textarea (`name="delivery_message"`)
     * on the checkout shipping step. Core's CheckoutDeliveryStep hands it to
     * CheckoutSession::setMessage(), which stores one row per cart in the
     * `message` table - so it is readable from any request that knows the cart,
     * including the ones with no buyer submission in scope. No plugin
     * order-note field exists and none should be added (ABN-472).
     *
     * Core writes the value through Tools::safeOutput(), i.e. htmlentities, so
     * it is decoded back to plain text here rather than shipping `&amp;` and
     * `&quot;` to Two.
     *
     * @param Cart|null $cart
     * @return string Empty when the buyer left the comment blank
     */
    public function getCartOrderNote($cart)
    {
        if (!Validate::isLoadedObject($cart) || (int) $cart->id <= 0) {
            return '';
        }

        // Reading core's storage directly rather than through CheckoutSession:
        // that class resolves the cart from the request context, which is wrong
        // (or absent) on the webhook and admin paths that also build payloads.
        $row = Message::getMessageByCartId((int) $cart->id);
        if (!is_array($row) || !isset($row['message'])) {
            return '';
        }

        $note = trim(html_entity_decode((string) $row['message'], ENT_QUOTES, 'UTF-8'));
        if ($note === '') {
            return '';
        }

        return Tools::substr($note, 0, self::ORDER_NOTE_MAX_LENGTH);
    }

    /**
     * The optional field values the buyer submitted with the payment form.
     *
     * Front-office, same-request only: these arrive as POST parameters on the
     * payment submit, so this returns an empty array anywhere the buyer's
     * submission is not in scope (admin order edit, provider webhooks). A
     * disabled field is never read, so turning a switch off keeps its value
     * out of the payload even if the parameter is forged onto the request.
     *
     * @return array<string,string> Keyed by payload field key, empty values omitted
     */
    public function getSubmittedOptionalCheckoutFields()
    {
        $values = array();
        foreach (self::OPTIONAL_CHECKOUT_FIELDS as $key => $definition) {
            if (!$this->isOptionalCheckoutFieldEnabled($key)) {
                continue;
            }

            $raw = Tools::getValue($definition['input'], '');
            if (!is_string($raw)) {
                continue;
            }

            $value = trim(strip_tags($raw));
            if ($value === '') {
                continue;
            }
            $value = Tools::substr($value, 0, (int) $definition['max_length']);

            // An invalid optional email is dropped, never a checkout blocker:
            // the buyer-side script rejects it before submit, and by the time
            // the order is being created the alternative would be failing a
            // checkout over a field the buyer did not have to fill in at all.
            if ($definition['type'] === 'email' && !Validate::isEmail($value)) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Dropped invalid optional checkout field "' . $key . '" from the Two order payload',
                    2
                );
                continue;
            }

            $values[$key] = $value;
        }

        return $values;
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
     * Check if the cart's billing country is allowed for this module according to
     * PrestaShop's native per-module payment restrictions (the `module_country`
     * table, edited from the back office's Payment Restrictions screen). This is
     * a separate allowlist from the shop's own active-country list.
     *
     * Matched on the INVOICE address country and the current shop, because that
     * is exactly the pair core matches at final order submission: the submission
     * gate in controllers/front/payment.php defers to core, and core's
     * authorisation query filters `module_country` on the invoice address.
     *
     * Core's own DISPLAY-time filter disagrees with its submission-time one, and
     * that disagreement is the bug. The display filter matches this table
     * against the *contextual* country, and the front controller resolves that
     * from whichever address `PS_TAX_ADDRESS_TYPE` names - which defaults to the
     * DELIVERY address (verified on a stock PrestaShop 8 install, not assumed).
     * So on any cart whose delivery and invoice countries differ, core rendered
     * the tile against one country and then refused the order against the other,
     * at the last click. Two further cases reach here for the same reason: a
     * merchant who has switched that setting to the invoice address still gets
     * no protection when the contextual Country fails to load at all, because
     * core skips its country clause entirely in that state and dispatches to
     * every payment module.
     *
     * Gating here closes those at display time, the same way checkCurrency()
     * withholds the tile rather than letting an unsupported currency fail at
     * submission. It can only ever narrow what core dispatched: a cart core
     * withheld the hook for never reaches this method, so core's opposite
     * failure - refusing to dispatch when the CONTEXTUAL country is not
     * allowlisted but the invoice country is - is untouched and remains core's.
     *
     * Fail-closed on a genuine "no such row" answer, because a row core cannot
     * find here is a row it will not find at submission either - showing the
     * tile would only move the same refusal to a worse place in the flow.
     * Fail-OPEN on a query that could not be answered at all: a thrown DB error
     * is not a restriction verdict, and treating it as one would hide Two on
     * every shop at once. That distinction is not hypothetical - the first cut
     * of this method shipped a redundant `LIMIT 1` (core's Db::getValue()
     * appends its own, so the query was a syntax error) and the fail-closed
     * branch turned it into a silently missing payment method.
     *
     * @param Cart $cart
     * @param Address|null $billing_address already-loaded invoice address, if the
     *   caller has one - hookPaymentOptions() does, and core asks it for payment
     *   options several times per payment-step render
     * @return bool
     */
    private function checkCountry($cart, $billing_address = null)
    {
        if (!Validate::isLoadedObject($cart) || (int) $cart->id_address_invoice <= 0) {
            return false;
        }

        // Accept the caller's copy only if it IS this cart's invoice address.
        // The verdict inverts if a delivery address is passed instead, and the
        // whole point of the gate is that it matches what core checks at
        // submission - so this is checked rather than trusted.
        if (!Validate::isLoadedObject($billing_address)
            || (int) $billing_address->id !== (int) $cart->id_address_invoice
        ) {
            $billing_address = new Address((int) $cart->id_address_invoice);
            if (!Validate::isLoadedObject($billing_address)) {
                return false;
            }
        }

        $id_country = (int) $billing_address->id_country;
        if ($id_country <= 0) {
            return false;
        }

        // No convenience helper exists for this. Core exposes Module::getCurrency()
        // for the currency allowlist but nothing equivalent for module_country -
        // every core reader of that table inlines the query - so this one does
        // too, scoped to this module and this shop.
        // NO `LIMIT 1`: Db::getValue() -> Db::getRow() appends one itself, and
        // core's own docblock documents the argument as "the select query
        // (without LIMIT 1)". Supplying one produces `LIMIT 1 LIMIT 1`.
        $sql = 'SELECT `id_country` FROM `' . _DB_PREFIX_ . 'module_country`'
            . ' WHERE `id_module` = ' . (int) $this->id
            . ' AND `id_country` = ' . $id_country
            . ' AND `id_shop` = ' . (isset($this->context->shop->id) ? (int) $this->context->shop->id : 0);

        $db = Db::getInstance();

        try {
            $matched = $db->getValue($sql);
        } catch (Exception $e) {
            // PS 8 + the PDO driver: PHP 8 defaults PDO to ERRMODE_EXCEPTION and
            // DbPDO::_query() lets it surface.
            $this->logTwoCountryLookupFailure(get_class($e));
            return true;
        }

        if ($matched === false) {
            // "false" is BOTH "no such row" and "the query failed", and on the
            // module's declared floor (ps_versions_compliancy min 1.7.6.0) a
            // failure does not throw at all: DbPDO::_query() is a bare
            // link->query(), and DbMySQLi's is unwrapped too. Without asking the
            // driver, a broken query is silently read as a country restriction
            // and Two disappears from every shop - which is exactly how the
            // LIMIT bug behaved. Ask.
            //
            // getNumberError() reports the LAST query on the connection, which
            // is this one. If some earlier unrelated failure leaks in, the worst
            // outcome is failing OPEN on a cart that was genuinely restricted -
            // the status quo before this gate existed, not an outage.
            $errno = 0;
            if (method_exists($db, 'getNumberError')) {
                $errno = (int) $db->getNumberError();
            }
            if ($errno !== 0) {
                $this->logTwoCountryLookupFailure('SQL error ' . $errno);
                return true;
            }

            return false;
        }

        return (int) $matched === $id_country;
    }

    /**
     * Report that the country allowlist is NOT being enforced (TWO-25387).
     *
     * Once per request: unlike a transient API-key outage, a lookup that fails
     * fails on every evaluation forever, and core asks for payment options
     * several times per payment-step render.
     *
     * Deliberately NOT the driver's message: it carries the SQL, and the payment
     * controller already refuses to put SQL text where a buyer or a log reader
     * could pick it up. The class name or errno is what makes this diagnosable.
     *
     * @param string $reason
     */
    private function logTwoCountryLookupFailure($reason)
    {
        if ($this->twoCountryLookupFailureLogged) {
            return;
        }
        $this->twoCountryLookupFailureLogged = true;

        PrestaShopLogger::addLog(
            'TwoPayment: module_country lookup failed (' . $reason
            . ') - country restriction NOT enforced for this request',
            3
        );
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
    private function buildTwoOrderPricingData($cart, $contextLabel = 'order payload', $strictReconciliation = false, $paymentTermDays = null, $syncSurchargeCartLine = false)
    {
        // Money-critical self-heal (create + strict-submit paths only; never
        // the update path, whose cart belongs to an already-placed order):
        // reconcile the cart's surcharge line with the fee this payload will
        // carry BEFORE totals are read, so a missed/failed frontend sync
        // (broken theme JS, raced AJAX) cannot ship a PrestaShop total that
        // diverges from the Two invoice. The parity gate below then verifies
        // the result and fails closed on any residual mismatch.
        if ($syncSurchargeCartLine) {
            $this->syncTwoSurchargeCartLine($cart, true);
        }

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
                // Carry the numbers in the exception, not just the log: this
                // message is what the buyer-facing decline path renders, and an
                // opaque rejection here is what made TWO-25161 take two weeks
                // of email to diagnose. TwoCheckoutAmountException is what
                // authorises that relay — see the class docblock.
                throw new TwoCheckoutAmountException(
                    'Order totals do not reconcile with cart totals: cart total ' .
                    $this->getTwoRoundAmount(round((float)$cart->getOrderTotal(true, Cart::BOTH), 2)) .
                    ' vs order lines ' . $this->getTwoRoundAmount($lineTotals['gross']) .
                    ' (difference ' . $this->getTwoRoundAmount($max_reconciliation_diff_cents / 100) . ')'
                );
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
            throw new TwoCheckoutAmountException('Tax subtotals do not reconcile with line items');
        }

        // Offset pricing fee (buyer surcharge) — appended AFTER product-line
        // reconciliation so it never perturbs the cart-vs-lines gate. The fee
        // is quoted from POST /v1/pricing/order/fee. A missing quote omits the
        // line HERE, but that is no longer a silent undercharge: TWO-25269
        // withholds the payment option entirely when the surcharge cannot be
        // quoted in the cart currency, so a buyer never reaches this builder
        // in that state, and a quote that fails only at this point leaves the
        // hidden cart line in place for the parity gate to catch. Applying it in the
        // shared pricing builder keeps the intent, create and update payloads
        // consistent, so the order-intent approval reconciles against the same
        // gross the create call sends. TWO-24752 / TWO-24893.
        $surchargeLine = $this->buildTwoSurchargeLineItemForCart($cart, $subtotalsTotals['gross'], $paymentTermDays);
        if ($surchargeLine !== null && $this->validateTwoLineItems(array($surchargeLine))) {
            $line_items[] = $surchargeLine;
            $tax_subtotals = $this->getTwoTaxSubtotals($line_items);
            $subtotalsTotals = $this->calculateOrderTotalsFromTaxSubtotals($tax_subtotals);
        } else {
            $surchargeLine = null;
        }

        // PARITY GATE (the single most important correctness edge of the
        // surcharge-as-cart-line feature): the fee PrestaShop charges the
        // buyer (hidden virtual product line) and the fee the Two payload
        // carries must be the same money. On the create/strict paths a
        // mismatch beyond ORDER_RECONCILIATION_TOLERANCE throws - checkout
        // fails with a retryable error instead of ever creating an order
        // whose PrestaShop total diverges from the Two invoice. The update
        // path and the non-strict intent precheck log a warning only
        // (pre-feature orders legitimately have no cart line).
        $cartSurchargeLine = $this->getTwoSurchargeCartLine($cart);
        $payloadFeeGrossCents = $surchargeLine !== null ? $this->convertAmountToCents($surchargeLine['gross_amount']) : 0;
        $payloadFeeNetCents = $surchargeLine !== null ? $this->convertAmountToCents($surchargeLine['net_amount']) : 0;
        $cartFeeGrossCents = $cartSurchargeLine !== null ? $this->convertAmountToCents($cartSurchargeLine['gross']) : 0;
        $cartFeeNetCents = $cartSurchargeLine !== null ? $this->convertAmountToCents($cartSurchargeLine['net']) : 0;
        $surchargeParityDiffCents = max(
            abs($payloadFeeGrossCents - $cartFeeGrossCents),
            abs($payloadFeeNetCents - $cartFeeNetCents)
        );
        $enforceSurchargeParity = (bool) $syncSurchargeCartLine;
        if ($surchargeParityDiffCents > $this->convertAmountToCents(self::ORDER_RECONCILIATION_TOLERANCE)) {
            PrestaShopLogger::addLog(
                'TwoPayment: ' . $contextLabel . ' surcharge parity mismatch - cart line (net/gross)=(' .
                $this->getTwoRoundAmount($cartFeeNetCents / 100) . '/' . $this->getTwoRoundAmount($cartFeeGrossCents / 100) .
                ') vs payload fee line (net/gross)=(' .
                $this->getTwoRoundAmount($payloadFeeNetCents / 100) . '/' . $this->getTwoRoundAmount($payloadFeeGrossCents / 100) . ')',
                $enforceSurchargeParity ? 3 : 2
            );
            if ($enforceSurchargeParity) {
                throw new Exception('Surcharge line mismatch between cart and Two payload');
            }
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
        // The hidden surcharge line is excluded from the product line items
        // (its payload counterpart is appended AFTER this gate), so subtract
        // its cart-side totals to compare like with like.
        $surchargeCartLine = $this->getTwoSurchargeCartLine($cart);
        if ($surchargeCartLine !== null && ($cartGross != 0.0 || $cartNet != 0.0)) {
            $cartGross = round($cartGross - $surchargeCartLine['gross'], 2);
            $cartNet = round($cartNet - $surchargeCartLine['net'], 2);
        }
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
        // Strict submit is the authoritative pre-create check: self-heal the
        // cart's surcharge line and enforce cart-vs-payload fee parity.
        $pricingData = $this->buildTwoOrderPricingData($cart, $contextLabel, (bool)$strictReconciliation, null, (bool)$strictReconciliation);
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
                // Specific, not generic: name the actual failure and quote the
                // amounts, so a merchant-side cart/shipping misconfiguration is
                // diagnosable from the checkout page instead of only from the
                // shop log (TWO-25161).
                $result['message'] = $this->l('Two could not accept this order because the cart total does not match the total of the order lines.')
                    . ' ' . $exceptionMessage . '. '
                    . $this->l('This is usually a shipping or discount amount the cart has not applied yet. Please refresh your cart and try again, or contact the store.');
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

                    // Surface the platform minimum when the decline is
                    // attributable to it (TWO-24775) - decline_reason first,
                    // strictly-below fallback; fail-soft (no hint) otherwise.
                    $minimum_hint = $this->getTwoMinimumOrderDeclineHint($response, $cart);
                    if (!Tools::isEmpty($minimum_hint)) {
                        $result['message'] .= ' ' . $minimum_hint;
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

    /**
     * @param string $merchant_order_id
     * @param Cart $cart
     * @param array|null $merchant_urls
     * @param bool $syncSurchargeCartLine when true (default, order-create
     *        paths) the cart's surcharge line is self-healed and fee parity
     *        is ENFORCED before totals are read; pass false for pure
     *        comparison/snapshot builds that must not mutate the cart.
     * @return array
     * @throws Exception
     */
    public function getTwoNewOrderData($merchant_order_id, $cart, $merchant_urls = null, $syncSurchargeCartLine = true)
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

        $pricingData = $this->buildTwoOrderPricingData($cart, 'order data (merchant_order_id=' . $merchant_order_id . ')', false, null, (bool) $syncSurchargeCartLine);
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

        // Optional buyer reference fields, submitted with the payment form from
        // the Two payment tile (ABN-472).
        $optional_fields = $this->getSubmittedOptionalCheckoutFields();

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
            'buyer_department' => isset($optional_fields['department']) ? $optional_fields['department'] : '',
            'buyer_project' => isset($optional_fields['project']) ? $optional_fields['project'] : '',
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
            // PrestaShop core's own checkout order comment (`delivery_message`
            // on the shipping step), relayed rather than duplicated by a plugin
            // field (ABN-472). Read from the cart, so the value survives into
            // the requests that carry no buyer submission.
            'order_note' => $this->getCartOrderNote($cart),
            'line_items' => $line_items,
            'terms' => $this->buildTermsPayload(),
        ];

        if ($this->shouldIncludeTaxSubtotals()) {
            $request_data['tax_subtotals'] = $tax_subtotals;
        }

        // Two's payload shape for the remaining two optional fields is not a
        // pair of always-present scalars like buyer_department / buyer_project,
        // so they are added only when the buyer actually filled them in - the
        // same conditional shape the WooCommerce plugin sends.
        if (isset($optional_fields['purchase_order_number'])) {
            $request_data['buyer_purchase_order_number'] = $optional_fields['purchase_order_number'];
        }
        if (isset($optional_fields['invoice_email'])) {
            $request_data['invoice_details']['invoice_emails'] = array($optional_fields['invoice_email']);
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
            // Unchanged behaviour, now stated outright instead of hidden behind
            // a property check that could never be true. This is the order
            // UPDATE payload: it is built from admin order edits, provider
            // webhooks and status transitions, none of which carry the buyer's
            // payment-step submission, and the optional fields are not
            // persisted locally. They were always sent empty here - the
            // property check read `department` / `project` off the Address
            // entity, and PrestaShop's address table has no such columns.
            'buyer_department' => '',
            'buyer_project' => '',
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
            // PrestaShop core's own checkout order comment (`delivery_message`
            // on the shipping step), relayed rather than duplicated by a plugin
            // field (ABN-472). Read from the cart, so the value survives into
            // the requests that carry no buyer submission.
            'order_note' => $this->getCartOrderNote($cart),
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

        //  Validate cart has products
        if (empty($line_items)) {
            PrestaShopLogger::addLog('TwoPayment: Cart is empty, cannot build line items', 3);
            return $items; // Return empty array (caller should handle empty cart)
        }
        $surchargeProductId = $this->getTwoSurchargeCartProductId(false);

        foreach ($line_items as $line_item) {
            // The hidden surcharge product is NOT merchandise: the Two payload
            // carries the fee as its own appended SERVICE line
            // (buildTwoSurchargeLineItemForCart), and the fee basis must never
            // include the fee itself. Skip it here; reconciliation subtracts
            // its cart totals symmetrically.
            if ($surchargeProductId > 0 && (int) $line_item['id_product'] === $surchargeProductId) {
                continue;
            }
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
                $gross_amount_prestashop,
                $cart
            );
            if (!empty($ecotax_breakdown['enabled'])) {
                $net_amount_prestashop = (float)$ecotax_breakdown['product_net'];
                $gross_amount_prestashop = (float)$ecotax_breakdown['product_gross'];
                $tax_amount_prestashop = round($gross_amount_prestashop - $net_amount_prestashop, 2);

                if (isset($line_item['price']) && is_numeric($line_item['price'])) {
                    $line_item['price'] = max(0, (float)$line_item['price'] - (float)$ecotax_breakdown['unit_net']);
                }
            }
            
            // DECLARED-RATE RELAY (TWO-24880): the line's tax rate is the
            // merchant's own configured rate — the product's tax-rules group
            // resolved at the cart's PS_TAX_ADDRESS_TYPE address, exactly the
            // resolution PrestaShop used to compute the amounts. We never
            // derive the rate from tax/net, never snap it toward canonical
            // values, and never substitute a fallback. NOTE: the row's
            // 'rate' field from getProducts() is country-only (it ignores
            // state/zip tax rules) and must not be used.
            $declared_tax_rules_group_id = (int) Product::getIdTaxRulesGroupByIdProduct(
                (int) $line_item['id_product'],
                $this->context
            );
            $tax_rate = $this->getTwoConfiguredTaxRateDecimalForGroup($declared_tax_rules_group_id, $cart);

            if (Configuration::get('PS_TWO_DEBUG_MODE')) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Product tax debug - ID: ' . $line_item['id_product'] .
                    ' | tax rules group: ' . $declared_tax_rules_group_id .
                    ' | declared rate: ' . round($tax_rate * 100, 2) . '%' .
                    ' | total (net): ' . $net_amount_prestashop .
                    ' | total_wt (gross): ' . $gross_amount_prestashop,
                    1 // Info level
                );
            }

            // Fail loud when the declared rate contradicts PrestaShop's own
            // applied amounts: we cannot faithfully represent an internally
            // inconsistent line, and silently correcting it would be
            // substituting our judgment for the merchant's declaration.
            $this->assertTwoDeclaredRateReconcilesWithAmounts(
                'product ' . $line_item['id_product'],
                $net_amount_prestashop,
                $tax_amount_prestashop,
                $tax_rate
            );

            // Use PrestaShop unit price when available; otherwise derive from net total.
            $unit_price_net_prestashop = isset($line_item['price']) ? (float)$line_item['price'] : null;
            
            if ($unit_price_net_prestashop !== null) {
                // Derive the discount at PrestaShop's native precision (6dp) and
                // round once at the payload boundary. Rounding the unit price to
                // 2dp before multiplying manufactures phantom +/-0.01 discounts
                // whenever the third decimal of the unit price rounds opposite to
                // the line total (e.g. 3 x 8.344: 3x8.34=25.02 vs total 25.03).
                $expected_total = $quantity * $unit_price_net_prestashop;
                $discount_amount = round($expected_total - $net_amount_prestashop, 2);

                // A negative discount means quantity * unit_price < net total - a data
                // inconsistency we must surface, not silently correct. The checkout-api
                // validates order amounts and rejects bad payloads with a clear error.
                if ($discount_amount < 0) {
                    PrestaShopLogger::addLog('TwoPayment: Negative discount calculated for product ' . $line_item['id_product'] . ' (quantity ' . $quantity . ' x unit price ' . $unit_price_net_prestashop . ' = ' . $expected_total . ' < net total ' . $net_amount_prestashop . ')', 3);
                    throw new Exception('Negative discount calculated for product ' . $line_item['id_product']);
                }

                $unit_price_net = round($unit_price_net_prestashop, 2);
                $net_amount = $net_amount_prestashop;
            } else {
                // Round to currency precision before the sign check; sub-cent float
                // residue in PrestaShop's computed reduction is not a data error.
                $discount_amount = isset($line_item['reduction']) ? round((float)$line_item['reduction'], 2) : 0;

                // A negative reduction is a data inconsistency; surface it rather
                // than silently zeroing it.
                if ($discount_amount < 0) {
                    PrestaShopLogger::addLog('TwoPayment: Negative reduction for product ' . $line_item['id_product'] . ' (reduction ' . $discount_amount . ')', 3);
                    throw new Exception('Negative reduction for product ' . $line_item['id_product']);
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

        // SHIPPING AMOUNT SOURCING (TWO-25161): the CART is the authority, not
        // the Carrier object. Cart::getOrderTotal(..., Cart::ONLY_SHIPPING) is
        // the very figure PrestaShop folded into Cart::BOTH, so it is the only
        // shipping amount that can reconcile with the cart total - and it
        // resolves without a loadable Carrier. Merchants who price shipping
        // through symbolic "logistics carriers" leave id_carrier = 0 on a cart
        // that nonetheless carries a real shipping cost; keying the shipping
        // line off `new Carrier($cart->id_carrier)` silently dropped that cost
        // and the reconciliation gate then rejected the whole order.
        //
        // getPackageShippingCost() survives only as a fallback for the case it
        // was originally added for: a shop where a free-shipping cart rule
        // zeroes ONLY_SHIPPING while the same amount reappears as a shipping
        // discount, so the payload needs the pre-discount carrier price to stay
        // coherent. That fallback is inherently carrier-bound and stays gated
        // on a loadable carrier.
        $shipping_gross = round((float)$cart->getOrderTotal(true, Cart::ONLY_SHIPPING), 2);
        $shipping_net = round((float)$cart->getOrderTotal(false, Cart::ONLY_SHIPPING), 2);

        if ($shipping_gross <= 0 && Validate::isLoadedObject($carrier)) {
            // Parameters: id_carrier, use_tax, country, product_list, id_zone
            $package_gross = (float)$cart->getPackageShippingCost((int)$cart->id_carrier, true, null, null, null);
            $package_net = (float)$cart->getPackageShippingCost((int)$cart->id_carrier, false, null, null, null);
            if ($package_gross > 0) {
                $shipping_gross = round($package_gross, 2);
                $shipping_net = round($package_net, 2);
            }
        }

        if ($shipping_gross > 0) {
            // Keep shipping monetary values canonical to PrestaShop totals.
            $shipping_tax_amount = round($shipping_gross - $shipping_net, 2);

            $carrier_is_loaded = Validate::isLoadedObject($carrier);
            $shipping_name = ($carrier_is_loaded && $carrier->name) ? $carrier->name : $this->l('Shipping');
            $shipping_delay = '';
            if ($carrier_is_loaded && $carrier->delay && is_array($carrier->delay)) {
                $shipping_delay = isset($carrier->delay[$cart->id_lang]) ?
                    $carrier->delay[$cart->id_lang] :
                    reset($carrier->delay);
            } elseif ($carrier_is_loaded && $carrier->delay) {
                $shipping_delay = $carrier->delay;
            }

            $shipping_description = $shipping_delay ? $shipping_delay : $this->l('Shipping cost for order');
            if ($carrier_is_loaded && $carrier->shipping_method == Carrier::SHIPPING_METHOD_WEIGHT) {
                $shipping_description .= ' ' . sprintf($this->l('(by weight)'));
            } elseif ($carrier_is_loaded && $carrier->shipping_method == Carrier::SHIPPING_METHOD_PRICE) {
                $shipping_description .= ' ' . sprintf($this->l('(by price)'));
            }

            $shipping_line_template = [
                'name' => $shipping_name,
                'description' => Tools::substr(strip_tags($shipping_description), 0, 255),
                'quantity_unit' => 'pcs',
                'type' => 'SHIPPING_FEE',
            ];

            if ($this->isTwoAtcpShipWrapEnabled()) {
                // PS_ATCP_SHIPWRAP taxes shipping at the average product tax
                // rate (a blended, non-canonical rate). Split the charge
                // across the cart's canonical product rate classes instead of
                // ever emitting the blended rate.
                foreach ($this->splitTwoChargeAcrossProductRateClasses(
                    $shipping_net,
                    $shipping_tax_amount,
                    $items,
                    'shipping'
                ) as $segment) {
                    $items[] = $this->buildTwoChargeLineFromSegment($shipping_line_template, $segment);
                }
            } else {
                // DECLARED-RATE RELAY: the shipping rate is always a tax-rules
                // group the merchant declared on a carrier, resolved at the
                // cart's tax address — never derived from tax/net, never
                // snapped, never blended. A rate computed from the shipping
                // amounts is NOT an option: 2dp-rounded figures cannot tell a
                // clean 21% from 20.98%, and the relayed rate is asserted
                // against the amounts again at invoicing time.
                //
                // The question that decides one line or several is how many
                // tax-rules groups the SELECTED DELIVERY OPTION spans — NOT
                // whether `$cart->id_carrier` happens to load. Core's
                // Cart::setDeliveryOption() only recomputes `id_carrier` when
                // the cart has a single delivery option, so a ship-to-multiple-
                // addresses cart keeps a stale non-zero `id_carrier`. Gating the
                // split on carrier loadability applied that one carrier's group
                // to a shipping total spanning several, and the resulting blend
                // hid inside the 2-cent reconciliation tolerance instead of
                // failing — the exact class of silent approximation this change
                // exists to remove. `id_carrier` now only supplies the line's
                // name, delay text and by-weight/by-price suffix (above).
                $shipping_rate_classes = $this->resolveTwoCartShippingRateClasses(
                    $cart,
                    $shipping_gross,
                    $carrier_is_loaded ? $carrier : null
                );

                if (count($shipping_rate_classes) > 1) {
                    // MIXED DECLARED RATES: the delivery option spans carriers
                    // whose tax-rules groups resolve differently. Emit one
                    // SHIPPING_FEE line per rate, weighted by the per-carrier
                    // nets the cart already holds — never one carrier's rate
                    // applied to all of them.
                    foreach ($this->splitTwoChargeAcrossRateClasses(
                        $shipping_net,
                        $shipping_tax_amount,
                        $shipping_rate_classes,
                        'shipping'
                    ) as $segment) {
                        $items[] = $this->buildTwoChargeLineFromSegment($shipping_line_template, $segment);
                    }
                } else {
                    // The resolver either returns a non-empty map or throws, so
                    // this is belt-and-braces — but the failure mode it guards
                    // is silent: `reset([])` is `false`, and `false['rate']` is
                    // a PHP warning that evaluates to null, i.e. a 0% shipping
                    // VAT rate relayed on a taxed shipping line. Refuse instead.
                    $shipping_rate_class = reset($shipping_rate_classes);
                    if (!is_array($shipping_rate_class) || !isset($shipping_rate_class['rate'])) {
                        throw $this->buildTwoShippingRateUnresolvableException(
                            $cart,
                            $shipping_gross,
                            (int) $cart->id_address_delivery,
                            '',
                            (int) $cart->id_carrier,
                            'the declared shipping tax rate resolver produced no usable rate class'
                        );
                    }
                    $shipping_tax_rate_decimal = (float) $shipping_rate_class['rate'];
                    $this->assertTwoDeclaredRateReconcilesWithAmounts(
                        'shipping (' . $shipping_name . ')',
                        $shipping_net,
                        $shipping_tax_amount,
                        $shipping_tax_rate_decimal
                    );
                    $items[] = $this->buildTwoChargeLineFromSegment($shipping_line_template, [
                        'net' => $shipping_net,
                        'tax' => $shipping_tax_amount,
                        'gross' => $shipping_gross,
                        'rate' => $shipping_tax_rate_decimal,
                    ]);
                }
            }
        }

        $wrapping_totals = $this->getTwoGiftWrappingTotals($cart);
        if ($wrapping_totals['gross'] > 0) {
            $wrapping_line_template = [
                'name' => $this->l('Gift wrapping'),
                'description' => Tools::substr(strip_tags($this->l('Gift wrapping for this order')), 0, 255),
                'quantity_unit' => 'item',
                'type' => 'DIGITAL',
            ];

            if ($this->isTwoAtcpShipWrapEnabled()) {
                // PS_ATCP_SHIPWRAP also taxes wrapping at the blended average
                // product rate — same canonical split as shipping.
                foreach ($this->splitTwoChargeAcrossProductRateClasses(
                    $wrapping_totals['net'],
                    $wrapping_totals['tax'],
                    $items,
                    'gift wrapping'
                ) as $segment) {
                    $items[] = $this->buildTwoChargeLineFromSegment($wrapping_line_template, $segment);
                }
            } else {
                // DECLARED-RATE RELAY: wrapping is taxed by the shop's
                // configured PS_GIFT_WRAPPING_TAX_RULES_GROUP.
                $wrapping_rate_decimal = $this->getTwoConfiguredTaxRateDecimalForGroup(
                    (int) Configuration::get('PS_GIFT_WRAPPING_TAX_RULES_GROUP'),
                    $cart
                );
                $this->assertTwoDeclaredRateReconcilesWithAmounts(
                    'gift wrapping',
                    $wrapping_totals['net'],
                    $wrapping_totals['tax'],
                    $wrapping_rate_decimal
                );
                $items[] = $this->buildTwoChargeLineFromSegment($wrapping_line_template, [
                    'net' => $wrapping_totals['net'],
                    'tax' => $wrapping_totals['tax'],
                    'gross' => $wrapping_totals['gross'],
                    'rate' => $wrapping_rate_decimal,
                ]);
            }
        }

        // Add cart-level discounts as one or more lines split by tax context when applicable.
        $discount_lines = $this->buildTwoDiscountLinesFromCartTotals($cart, $items);
        if (!empty($discount_lines)) {
            foreach ($discount_lines as $discount_line) {
                $items[] = $discount_line;
            }
        }

        return $items;
    }

    /**
     * Whether PrestaShop's "average tax of cart products for shipping and
     * wrapping" mode is enabled (PS_ATCP_SHIPWRAP).
     *
     * @return bool
     */
    private function isTwoAtcpShipWrapEnabled()
    {
        return (bool) Configuration::get('PS_ATCP_SHIPWRAP');
    }

    /**
     * Materialize a payload line from a shared template plus a
     * net/tax/gross/rate segment.
     *
     * @param array $template name/description/quantity_unit/type
     * @param array{net:float,tax:float,gross:float,rate:float} $segment
     * @return array
     */
    private function buildTwoChargeLineFromSegment($template, $segment)
    {
        $rate = max(0, (float) $segment['rate']);
        $rate_percent = round($rate * 100, 2);

        return [
            'name' => $template['name'],
            'description' => $template['description'],
            'gross_amount' => (string) $this->getTwoRoundAmount($segment['gross']),
            'net_amount' => (string) $this->getTwoRoundAmount($segment['net']),
            'discount_amount' => '0.00',
            'tax_amount' => (string) $this->getTwoRoundAmount($segment['tax']),
            'tax_class_name' => 'VAT ' . $this->getTwoRoundAmount($rate_percent) . '%',
            'tax_rate' => $this->formatTwoTaxRate($rate),
            'unit_price' => (string) $this->getTwoRoundAmount($segment['net']),
            'quantity' => 1,
            'quantity_unit' => $template['quantity_unit'],
            'image_url' => '',
            'product_page_url' => '',
            'type' => $template['type'],
        ];
    }

    /**
     * Split a charge taxed at PrestaShop's blended average product rate
     * (PS_ATCP_SHIPWRAP) across the cart's canonical product rate classes,
     * reconciling rounded sub-lines to the PrestaShop-authoritative totals
     * cent-exactly (largest-remainder on net, bounded per-line nudge on tax).
     * Fails loud when no rate-consistent distribution can hit the target.
     *
     * @param float $charge_net PrestaShop-authoritative net for the charge
     * @param float $charge_tax PrestaShop-authoritative tax for the charge
     * @param array $items Positive payload lines built so far (rate classes)
     * @param string $label For error messages ('shipping', 'gift wrapping')
     * @return array<int,array{net:float,tax:float,gross:float,rate:float}>
     */
    private function splitTwoChargeAcrossProductRateClasses($charge_net, $charge_tax, $items, $label)
    {
        $charge_net = round(max(0, (float) $charge_net), 2);
        $charge_tax = round((float) $charge_tax, 2);
        if ($charge_net <= 0 && $charge_tax <= 0) {
            return [];
        }

        // Collect canonical rate classes from positive lines, net-weighted —
        // the same basis PrestaShop's getAverageProductsTaxRate() blends.
        $classes = [];
        foreach ($items as $item) {
            $line_net = isset($item['net_amount']) ? round((float) $item['net_amount'], 2) : 0.0;
            $line_gross = isset($item['gross_amount']) ? round((float) $item['gross_amount'], 2) : 0.0;
            if ($line_net <= 0 || $line_gross <= 0 || !isset($item['tax_rate'])) {
                continue;
            }
            $rate = max(0, (float) $item['tax_rate']);
            $key = $this->formatTwoTaxRate($rate);
            if (!isset($classes[$key])) {
                $classes[$key] = ['rate' => $rate, 'net_weight' => 0.0];
            }
            $classes[$key]['net_weight'] += $line_net;
        }

        if (empty($classes)) {
            PrestaShopLogger::addLog(
                'TwoPayment: Cannot split ' . $label . ' across product tax-rate classes - no positive product lines',
                3
            );
            throw new Exception('Cannot attribute ' . $label . ' tax under PS_ATCP_SHIPWRAP: no product rate classes');
        }

        return $this->splitTwoChargeAcrossRateClasses($charge_net, $charge_tax, $classes, $label);
    }

    /**
     * Split a charge across an already-resolved set of canonical rate classes,
     * reconciling the rounded sub-lines to the PrestaShop-authoritative totals
     * cent-exactly (largest-remainder on net, bounded per-line nudge on tax).
     * Fails loud when no rate-consistent distribution can hit the target.
     *
     * Shared by the PS_ATCP_SHIPWRAP product-rate-class split and the
     * mixed-declared-rate shipping split (TWO-25161): both need one line per
     * canonical rate whose amounts still sum to the cart's own totals.
     *
     * @param float $charge_net PrestaShop-authoritative net for the charge
     * @param float $charge_tax PrestaShop-authoritative tax for the charge
     * @param array<string,array{rate:float,net_weight:float}> $classes Keyed by formatted rate
     * @param string $label For error messages ('shipping', 'gift wrapping')
     * @return array<int,array{net:float,tax:float,gross:float,rate:float}>
     * @throws Exception
     */
    private function splitTwoChargeAcrossRateClasses($charge_net, $charge_tax, $classes, $label)
    {
        $charge_net = round(max(0, (float) $charge_net), 2);
        $charge_tax = round((float) $charge_tax, 2);
        if (empty($classes) || ($charge_net <= 0 && $charge_tax <= 0)) {
            return [];
        }

        $net_weights = [];
        foreach ($classes as $key => $class) {
            $net_weights[$key] = (float) $class['net_weight'];
        }
        $allocated_nets = $this->allocateTwoAmountByWeights($charge_net, $net_weights);

        // Per-class tax at the class's canonical rate, then reconcile the
        // rounding residual to PrestaShop's authoritative tax total with a
        // bounded per-line nudge that stays inside TAX_FORMULA_TOLERANCE.
        $target_tax_cents = $this->convertAmountToCents($charge_tax);
        $tax_cents = [];
        $allocated_tax_cents_total = 0;
        foreach ($classes as $key => $class) {
            $class_net = isset($allocated_nets[$key]) ? (float) $allocated_nets[$key] : 0.0;
            $tax_cents[$key] = (int) round($this->convertAmountToCents($class_net) * $class['rate'], 0);
            $allocated_tax_cents_total += $tax_cents[$key];
        }

        $residual_cents = $target_tax_cents - $allocated_tax_cents_total;
        $max_nudge_cents = (int) round(self::TAX_FORMULA_TOLERANCE * 100);
        // Nudge largest classes first: bigger nets absorb a cent with the
        // least relative distortion.
        arsort($net_weights);
        $nudged = [];
        foreach (array_keys($net_weights) as $key) {
            $nudged[$key] = 0;
        }
        while ($residual_cents !== 0) {
            $applied = false;
            foreach (array_keys($net_weights) as $key) {
                if ($residual_cents === 0) {
                    break;
                }
                $step = $residual_cents > 0 ? 1 : -1;
                if (abs($nudged[$key] + $step) > $max_nudge_cents) {
                    continue;
                }
                if ($step < 0 && $tax_cents[$key] <= 0) {
                    continue;
                }
                $tax_cents[$key] += $step;
                $nudged[$key] += $step;
                $residual_cents -= $step;
                $applied = true;
            }
            if (!$applied) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Cannot reconcile ' . $label . ' tax split to PrestaShop totals. ' .
                    'Residual=' . $residual_cents . 'c beyond per-line tolerance',
                    3
                );
                throw new Exception('Cannot reconcile ' . $label . ' tax under PS_ATCP_SHIPWRAP with canonical rates');
            }
        }

        $segments = [];
        foreach ($classes as $key => $class) {
            $segment_net = isset($allocated_nets[$key]) ? round((float) $allocated_nets[$key], 2) : 0.0;
            $segment_tax = round($tax_cents[$key] / 100, 2);
            $segment_gross = round($segment_net + $segment_tax, 2);
            if ($segment_gross <= 0 && $segment_net <= 0) {
                continue;
            }
            $segments[] = [
                'net' => $segment_net,
                'tax' => $segment_tax,
                'gross' => $segment_gross,
                'rate' => $class['rate'],
            ];
        }

        return $segments;
    }

    /**
     * Fail loud when a line's declared (merchant-configured) tax rate does
     * not reconcile with PrestaShop's own applied amounts. This is the
     * deterministic, plugin-side divergence gate of the declared-rate relay
     * design: we never derive, snap or substitute a rate — an internally
     * contradictory line is surfaced to the merchant instead.
     *
     * @param string $label Line description for logs/errors
     * @param float $net_amount Emitted 2dp net amount
     * @param float $tax_amount Emitted 2dp tax amount
     * @param float $rate_decimal Declared decimal rate (e.g. 0.21)
     * @return void
     * @throws TwoCheckoutAmountException
     */
    private function assertTwoDeclaredRateReconcilesWithAmounts($label, $net_amount, $tax_amount, $rate_decimal)
    {
        $net_amount = round((float) $net_amount, 2);
        $tax_amount = round((float) $tax_amount, 2);
        $expected_tax = round($net_amount * max(0, (float) $rate_decimal), 2);
        if (abs($tax_amount - $expected_tax) <= self::TAX_FORMULA_TOLERANCE) {
            return;
        }

        PrestaShopLogger::addLog(
            'TwoPayment: Declared tax rate does not reconcile with applied amounts for ' . $label .
            '. Declared rate=' . round(max(0, (float) $rate_decimal) * 100, 2) . '%' .
            ', net=' . $this->getTwoRoundAmount($net_amount) .
            ', applied tax=' . $this->getTwoRoundAmount($tax_amount) .
            ', expected tax at declared rate=' . $this->getTwoRoundAmount($expected_tax) .
            '. Check the tax rules configured for this line (tax rules group, address-specific rules).',
            3
        );
        throw new TwoCheckoutAmountException(
            'Declared tax rate diverges from applied tax amounts for ' . $label
        );
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
            // Silently clamping gross to net would mask a genuine divergence
            // in PrestaShop's own wrapping totals — fail loud instead.
            PrestaShopLogger::addLog(
                'TwoPayment: Gift wrapping totals diverge - gross (' . $this->getTwoRoundAmount($wrapping_gross) .
                ') is below net (' . $this->getTwoRoundAmount($wrapping_net) . ')',
                3
            );
            throw new Exception('Gift wrapping totals diverge (gross below net)');
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
     * @param Cart $cart
     * @return array{enabled:bool,net:float,tax:float,gross:float,rate:float,product_net:float,product_gross:float,unit_net:float}
     */
    private function extractTwoEcotaxLineBreakdown($line_item, $quantity, $line_net, $line_gross, $cart)
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

        // Declared-rate relay: ecotax is taxed by the shop-configured
        // PS_ECOTAX_TAX_RULES_GROUP_ID group (the rate PrestaShop itself
        // embedded in total_ecotax), resolved at the cart's tax address via
        // the shared helper. Never the row's country-only 'rate' field.
        $ecotax_rate_decimal = $this->getTwoConfiguredTaxRateDecimalForGroup(
            (int) Configuration::get('PS_ECOTAX_TAX_RULES_GROUP_ID'),
            $cart
        );
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
                // Declared-rate relay: a discount that cannot be attributed
                // to the cart's declared rates (exactly or within rounding
                // tolerance) is internally contradictory — fail loud, never
                // emit a derived tax/net blend.
                PrestaShopLogger::addLog(
                    'TwoPayment: Discount allocation does not reconcile with any declared cart tax rate. ' .
                    'net=' . $this->getTwoRoundAmount($line_net) . ', tax=' . $this->getTwoRoundAmount($line_tax),
                    3
                );
                throw new Exception('Discount amounts diverge from all declared cart tax rates');
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

        // DECLARED-RATE RELAY: the free-shipping discount line MIRRORS the
        // emitted rate of the shipping line it offsets, so the pair nets to
        // zero at one consistent rate. Never derived from tax/net.
        $shipping_rate = isset($shipping_line['tax_rate']) ? max(0, (float) $shipping_line['tax_rate']) : 0.0;

        $shipping_ratio = $shipping_gross > 0 ? ($shipping_net / $shipping_gross) : 1.0;
        $alloc_net_uncapped = round($alloc_gross * $shipping_ratio, 2);
        $alloc_net = min($alloc_net_uncapped, round(max(0, (float)$discountNetTotal), 2), $alloc_gross);
        if ($alloc_net < $alloc_net_uncapped) {
            // The cap bit (multi-discount cart): re-derive gross from the
            // capped net at the mirrored rate so gross/net/tax stay
            // rate-consistent — never clamp net independently of gross.
            $alloc_tax = round($alloc_net * $shipping_rate, 2);
            $alloc_gross = round($alloc_net + $alloc_tax, 2);
        } else {
            $alloc_tax = round($alloc_gross - $alloc_net, 2);
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
                // Declared-rate relay: fail loud instead of emitting a
                // derived tax/net blend for an unattributable rule discount.
                PrestaShopLogger::addLog(
                    'TwoPayment: Cart-rule discount "' . $descriptor['name'] . '" does not reconcile with any ' .
                    'declared cart tax rate. net=' . $this->getTwoRoundAmount($line_net) .
                    ', tax=' . $this->getTwoRoundAmount($line_tax),
                    3
                );
                throw new Exception('Discount amounts diverge from all declared cart tax rates');
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

        // A zero-tax discount row is exactly representable at rate 0 —
        // tax == rate * net holds identically. Not a derivation.
        if ($tax_cents === 0) {
            return [[
                'net' => round($net_cents / 100, 2),
                'tax' => 0.0,
                'gross' => round($net_cents / 100, 2),
                'rate' => 0.0,
            ]];
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
            return $this->buildTwoToleranceSingleRateSegment($net_cents, $tax_cents, $rates);
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

        return $this->buildTwoToleranceSingleRateSegment($net_cents, $tax_cents, $rates);
    }

    /**
     * Last resort before failing loud: represent the row at ONE declared
     * cart rate whose implied tax is within TAX_FORMULA_TOLERANCE of the
     * actual tax (PrestaShop rounds discount buckets once; per-line rounding
     * can legitimately differ by a cent or two). The amounts are preserved
     * as-is — the rate is only accepted, never derived from the amounts.
     *
     * UNIQUE-FIT RULE: if MORE THAN ONE declared rate fits within the
     * tolerance the row is ambiguous (this only happens on tiny nets where
     * neighbouring rates are cents apart) — returns [] so the caller fails
     * loud rather than risk relabeling a line to a neighbouring rate, the
     * exact failure mode the deleted snap/fallback machinery had. Also
     * returns [] when no declared rate reconciles at all.
     *
     * @param int $net_cents
     * @param int $tax_cents
     * @param array $rates Declared decimal rates present on the cart
     * @return array<int,array{net:float,tax:float,gross:float,rate:float}>
     */
    private function buildTwoToleranceSingleRateSegment($net_cents, $tax_cents, $rates)
    {
        $tolerance_cents = (int) round(self::TAX_FORMULA_TOLERANCE * 100);
        $fitting_rates = [];
        foreach ((array) $rates as $rate) {
            $rate = max(0, (float) $rate);
            $diff = abs((int) round($net_cents * $rate, 0) - $tax_cents);
            if ($diff <= $tolerance_cents) {
                $fitting_rates[$this->formatTwoTaxRate($rate)] = $rate;
            }
        }

        if (count($fitting_rates) !== 1) {
            // 0 fits: genuine divergence. 2+ fits: ambiguous attribution.
            // Both fail loud at the caller.
            return [];
        }
        $best_rate = reset($fitting_rates);

        return [[
            'net' => round($net_cents / 100, 2),
            'tax' => round($tax_cents / 100, 2),
            'gross' => round(($net_cents + $tax_cents) / 100, 2),
            'rate' => $best_rate,
        ]];
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

        // Route through the shared address-correct helper so the shipping
        // RATE source uses the same PS_TAX_ADDRESS_TYPE address PrestaShop's
        // getPackageShippingCost() used for the shipping AMOUNTS.
        return $this->getTwoConfiguredTaxRateDecimalForGroup((int) $carrier->getIdTaxRulesGroup(), $cart);
    }

    /**
     * Declared shipping tax rate classes for a cart, resolved from the cart's
     * own selected delivery option (TWO-25161).
     *
     * The delivery option is the authority here, not `$cart->id_carrier`: core
     * only recomputes `id_carrier` when the cart has a single delivery option,
     * so a ship-to-multiple-addresses cart carries a stale non-zero
     * `id_carrier` while its shipping total spans several tax-rules groups. The
     * caller therefore always asks this resolver, and passes the loaded carrier
     * (when there is one) only as the fallback for a cart whose delivery option
     * cannot be read at all.
     *
     * PrestaShop keeps the shipping tax-rules group on the carrier row and
     * nowhere else — there is no shop-level shipping tax group — but the
     * carriers that priced the shipping stay enumerable from the cart even when
     * `id_carrier` is 0. `Cart::getOrderTotal(*, ONLY_SHIPPING)` nulls a
     * non-positive `id_carrier` and routes to `Cart::getTotalShippingCost()`,
     * which sums the delivery-option entry selected by
     * `Cart::getDeliveryOption()` out of `Cart::getDeliveryOptionList()`. Each
     * entry's `carrier_list` is keyed by REAL carrier IDs and carries a loaded
     * `Carrier` under 'instance', whose declared tax-rules group is the very
     * group core taxed the shipping with (`$carrier->getTaxesRate($address)`).
     * So the rate relayed here is the merchant's own declaration, resolved
     * through the shared group resolver, and is never computed from amounts.
     *
     * Deriving the rate from tax/net was rejected on rounding grounds: at 2dp a
     * clean 21% and 20.98% are indistinguishable, and checkout-api asserts the
     * relayed rate against the amounts again during invoice validation, so a
     * derived rate surfaces later as a bad invoice rather than a loud failure
     * here.
     *
     * @param Cart $cart
     * @param float $shipping_gross Authoritative 2dp shipping gross, for the failure message
     * @param Carrier|null $fallback_carrier Cart's loaded carrier, used only when the
     *                                       delivery option yields no carrier at all
     * @return array<string,array{rate:float,net_weight:float}> Keyed by formatted rate
     * @throws TwoCheckoutAmountException When no carrier with a declared tax-rules group can be resolved
     */
    private function resolveTwoCartShippingRateClasses($cart, $shipping_gross, $fallback_carrier = null)
    {
        try {
            return $this->resolveTwoCartShippingRateClassesFromCarriers(
                $cart,
                $shipping_gross,
                $fallback_carrier
            );
        } catch (TwoCheckoutAmountException $e) {
            // Resolution order (TWO-25200): carrier's declared group first -
            // it is the only source that is per-option and per-address - then
            // the merchant's module-level default shipping tax code, then the
            // loud refusal. The default is consulted ONLY here, on the path
            // where no carrier group was resolvable at all, so a shop with a
            // working carrier table never sees it.
            $default_classes = $this->resolveTwoDefaultShippingRateClasses($cart, $shipping_gross, $e->getMessage());
            if ($default_classes !== null) {
                return $default_classes;
            }

            throw $e;
        }
    }

    /**
     * The merchant's declared default shipping tax code, expressed as the
     * single rate class the whole shipping charge belongs to (TWO-25200).
     *
     * Returns NULL - never a rate - when no default is configured, which is
     * the shipped state and leaves the caller's loud refusal untouched. The
     * whole shipping gross goes into one class: the default is a declaration
     * about shipping as such, and the carrier-level split it replaces was
     * unavailable by definition on this path.
     *
     * @param Cart $cart
     * @param float $shipping_gross Authoritative 2dp shipping gross
     * @param string $carrier_failure Why the carrier path could not resolve, for the log
     * @return array<string,array{rate:float,net_weight:float}>|null
     */
    private function resolveTwoDefaultShippingRateClasses($cart, $shipping_gross, $carrier_failure = '')
    {
        $group_id = $this->getTwoDefaultShippingTaxRulesGroupId();
        if ($group_id === null) {
            return null;
        }

        try {
            $rate = $this->getTwoConfiguredTaxRateDecimalForGroup($group_id, $cart);
        } catch (Throwable $e) {
            // A configured-but-unresolvable default is not a rate either.
            // Returning null hands the caller back its loud refusal rather
            // than relaying a silent 0%.
            PrestaShopLogger::addLog(
                'TwoPayment: Cart ' . (int) $cart->id . ' could not resolve the configured default shipping ' .
                'tax code (tax_rules_group=' . $group_id . ') either (' . get_class($e) . ': ' .
                $e->getMessage() . '); refusing rather than relaying a guessed rate.',
                3
            );

            return null;
        }

        // Severity 2 (warning), matching the file's other "this worked, but
        // not on the normal path" logs: the shop IS on the fallback, and that
        // must be visible in the log without reading the code.
        PrestaShopLogger::addLog(
            'TwoPayment: Cart ' . (int) $cart->id . ' (id_carrier=' . (int) $cart->id_carrier . ') has no ' .
            'resolvable carrier shipping tax rate (' . ($carrier_failure === '' ? 'unknown cause' : $carrier_failure) .
            '); assuming the configured Default shipping tax code "' .
            $this->getTwoDefaultShippingTaxRulesGroupLabel($group_id) . '" (tax_rules_group=' . $group_id .
            ', rate=' . round($rate * 100, 2) . '%) for the whole shipping charge of ' .
            $this->getTwoRoundAmount($shipping_gross) . '.',
            2
        );

        return [
            $this->formatTwoTaxRate($rate) => [
                'rate' => $rate,
                'net_weight' => max(0.0, (float) $shipping_gross),
            ],
        ];
    }

    /**
     * Human-readable name of a tax rules group for log messages. Falls back
     * to core's "No tax" sentinel label and, for a group that no longer
     * loads, to a bare marker - a log line must never raise.
     *
     * @param int $group_id
     * @return string
     */
    private function getTwoDefaultShippingTaxRulesGroupLabel($group_id)
    {
        $group_id = (int) $group_id;
        if ($group_id === 0) {
            return 'No tax';
        }
        $group = new TaxRulesGroup($group_id);

        return Validate::isLoadedObject($group) ? (string) $group->name : '(deleted group)';
    }

    /**
     * The merchant's stored default shipping tax rules group.
     *
     * NOT gated on FLAG_DEFAULT_SHIPPING_TAX_CODE: the constant controls
     * whether the admin field is rendered, not whether a declaration the
     * merchant already made is honoured. Losing the constant (host migration,
     * restored config directory) must not silently start declining that
     * merchant's carrier-less orders.
     *
     * @return int|null Group id (0 = "No tax"), or null when unset/invalid -
     *                  null being the shipped state and the loud-refusal path
     */
    private function getTwoDefaultShippingTaxRulesGroupId()
    {
        $stored = Configuration::get(self::CONFIG_DEFAULT_SHIPPING_TAX_RULES_GROUP);
        if ($stored === false || $stored === null) {
            return null;
        }
        $stored = trim((string) $stored);
        // ctype_digit: a whole non-negative integer only. '', '-1' and '0.5'
        // are "not configured", never truncated into a selection the merchant
        // did not make.
        if ($stored === '' || !ctype_digit($stored)) {
            return null;
        }
        $group_id = (int) $stored;
        if ($group_id > 0 && !Validate::isLoadedObject(new TaxRulesGroup($group_id))) {
            // The merchant deleted the group after selecting it. That is not
            // a declaration any more - refuse loudly instead of relaying 0%.
            PrestaShopLogger::addLog(
                'TwoPayment: The configured Default shipping tax code refers to tax rules group ' .
                $group_id . ', which no longer exists; treating shipping tax as unresolvable.',
                3
            );

            return null;
        }

        return $group_id;
    }

    /**
     * Is the Default shipping tax code admin field switched on for this
     * install? See FLAG_DEFAULT_SHIPPING_TAX_CODE for the activation line.
     *
     * @return bool
     */
    protected function isTwoDefaultShippingTaxCodeFieldEnabled()
    {
        return defined(self::FLAG_DEFAULT_SHIPPING_TAX_CODE)
            && (bool) constant(self::FLAG_DEFAULT_SHIPPING_TAX_CODE);
    }

    /**
     * Carrier-sourced shipping rate classes - the primary and, on a shop with
     * a working carrier table, the only path. See
     * resolveTwoCartShippingRateClasses() for the doc block; this is its
     * body, split out so the default-shipping-tax-code fallback (TWO-25200)
     * has exactly one place to sit.
     *
     * @param Cart $cart
     * @param float $shipping_gross
     * @param Carrier|null $fallback_carrier
     * @return array<string,array{rate:float,net_weight:float}>
     * @throws TwoCheckoutAmountException
     */
    private function resolveTwoCartShippingRateClassesFromCarriers($cart, $shipping_gross, $fallback_carrier = null)
    {
        // NEITHER CORE CALL IS EXCEPTION-FREE. Both run
        // Cart::getPackageList() and, per candidate carrier,
        // Cart::getPackageShippingCost() - which instantiates ObjectModels
        // (Address, Country, Carrier; ObjectModel::__construct and its
        // EntityMapper hit the database and throw PrestaShopException), reads
        // cart rules over Db::executeS, and for an external/module-priced
        // carrier calls straight into third-party module code via
        // getPackageShippingCostFromModule(). A raise from any of those is a
        // 500 on the checkout page today, bypassing both the fallback and the
        // loud refusal designed below. Treat it as exactly what it is - the
        // delivery option is unreadable - and let the same fallback/refusal
        // decision run. Throwable, not Exception: a broken carrier module
        // raises TypeError/Error just as easily.
        $selected_options = [];
        $option_list = [];
        $lookup_failure = '';
        try {
            $selected_options = $cart->getDeliveryOption(null, false, false);
            $option_list = $cart->getDeliveryOptionList();
        } catch (Throwable $e) {
            $lookup_failure = get_class($e) . ': ' . $e->getMessage();
            $selected_options = [];
            $option_list = [];
            PrestaShopLogger::addLog(
                'TwoPayment: Cart ' . (int) $cart->id . ' delivery-option lookup raised while resolving the ' .
                'declared shipping tax rate (' . $lookup_failure . ').',
                3
            );
        }

        $classes = [];
        $diagnostics = [];

        if (is_array($selected_options) && is_array($option_list)) {
            foreach ($selected_options as $id_address => $option_key) {
                // Core's auto-select assigns `key($options)`, which is NULL for
                // an address whose option set is empty; a non-scalar key would
                // fatal on array access. Normalise before indexing.
                $option_key = is_scalar($option_key) ? (string) $option_key : '';
                if (
                    !isset($option_list[$id_address][$option_key]['carrier_list'])
                    || !is_array($option_list[$id_address][$option_key]['carrier_list'])
                ) {
                    continue;
                }

                foreach ($option_list[$id_address][$option_key]['carrier_list'] as $id_carrier => $data) {
                    $id_carrier = (int) $id_carrier;
                    $instance = (is_array($data) && isset($data['instance'])) ? $data['instance'] : null;
                    if (
                        $id_carrier <= 0
                        || !is_object($instance)
                        || !Validate::isLoadedObject($instance)
                        || !method_exists($instance, 'getIdTaxRulesGroup')
                    ) {
                        // PrestaShop's no-available-carrier sentinel: when a
                        // product has no carrier that can deliver it,
                        // Cart::getPackageList() sets carrier_list = [0 => 0]
                        // (1.7.6.0 Cart.php:2439, 8.1.7:2680, 9.0.0:2462), so
                        // there is no carrier row and no declared tax-rules
                        // group — while getPackageShippingCost() can still
                        // return a non-zero price from its own internal
                        // PS_CARRIER_DEFAULT / cheapest-in-range fallback.
                        //
                        // What actually arrives here is NOT the literal
                        // [0 => 0] (TWO-25180): getDeliveryOptionList()
                        // discards the whole option list on that sentinel
                        // before returning — unconditionally on 8.x/9.x
                        // (8.1.7:2909, 9.0.0:2674) and, on 1.7.6.0, only for a
                        // single-package cart (1.7.6.0:2647). The surviving
                        // 1.7 multi-package case falls through the loop, so
                        // carrier id 0 reaches the caller as a normal
                        // aggregated entry whose 'instance' is an UNLOADED
                        // `new Carrier(0)`. Every version also rewrites
                        // 'instance' for every entry in the final pass
                        // (1.7.6.0:2858, 8.1.7:3129, 9.0.0:2894), so a carrier
                        // row deleted mid-checkout lands here the same way.
                        // Both are this branch.
                        // Inferring a rate from that price is exactly what must
                        // not happen, and silently sending 0% would misreport
                        // the merchant's VAT. Refuse the order instead — also
                        // when `$cart->id_carrier` happens to load, because a
                        // stale carrier's group is not the group core taxed
                        // this option's shipping with.
                        throw $this->buildTwoShippingRateUnresolvableException(
                            $cart,
                            $shipping_gross,
                            (int) $id_address,
                            (string) $option_key,
                            $id_carrier
                        );
                    }

                    // Carrier::getIdTaxRulesGroup() is a database read
                    // (carrier_tax_rules_group_shop) behind a Context lookup,
                    // and the group resolver instantiates an Address; either
                    // can raise. A carrier that priced this shipping but whose
                    // declared group cannot be read is precisely the case
                    // where no rate may be invented — refuse, naming the
                    // cause, rather than 500 or fall through to 0%.
                    try {
                        $group_id = (int) $instance->getIdTaxRulesGroup();
                        $rate = $this->getTwoConfiguredTaxRateDecimalForGroup($group_id, $cart);
                    } catch (Throwable $e) {
                        throw $this->buildTwoShippingRateUnresolvableException(
                            $cart,
                            $shipping_gross,
                            (int) $id_address,
                            (string) $option_key,
                            $id_carrier,
                            'the declared tax-rules group of carrier ' . $id_carrier . ' could not be read (' .
                            get_class($e) . ': ' . $e->getMessage() . ')'
                        );
                    }
                    $rate_key = $this->formatTwoTaxRate($rate);
                    if (!isset($classes[$rate_key])) {
                        $classes[$rate_key] = ['rate' => $rate, 'net_weight' => 0.0];
                    }
                    // Per-carrier nets are already in carrier_list and sum to
                    // the option's own total_price_without_tax, so they are the
                    // correct weights for splitting a mixed-rate option.
                    $carrier_net_is_usable = isset($data['price_without_tax'])
                        && is_numeric($data['price_without_tax']);
                    $classes[$rate_key]['net_weight'] += $carrier_net_is_usable
                        ? max(0.0, round((float) $data['price_without_tax'], 2))
                        : 0.0;
                    $diagnostics[] = 'carrier=' . $id_carrier . ' tax_rules_group=' . $group_id .
                        ' rate=' . round($rate * 100, 2) . '%';
                }
            }
        }

        if (empty($classes)) {
            // The delivery option could not be read at all: no option list (the
            // shape core returns for the no-available-carrier sentinel), an
            // empty carrier list for the selected key, or a raise from the
            // lookup itself. A cart whose `id_carrier` loads still has a
            // merchant-declared group on that carrier row, which is a declared
            // rate and not an approximation, so relay it — this is the
            // pre-TWO-25161 behaviour, kept as the fallback rather than as the
            // primary path.
            //
            // It is also the only fallback that is coherent WITH the amounts:
            // when the option list is unreadable, Cart::getTotalShippingCost()
            // has nothing to sum, so ONLY_SHIPPING is 0 and the amounts on the
            // line came from the caller's getPackageShippingCost($cart->
            // id_carrier) fallback — i.e. from this very carrier, taxed by this
            // very group (core: `$carrier_tax = $carrier->getTaxesRate(
            // $address)`, Cart.php 1.7.6.0:3525 / 8.1.7:3790 / 9.0.0:3532).
            // There is no shop-level shipping tax-rules group to fall back to
            // instead: core defines only PS_ECOTAX_TAX_RULES_GROUP(_ID) and
            // PS_GIFT_WRAPPING_TAX_RULES_GROUP, and keeps the shipping group
            // per carrier in `carrier_tax_rules_group_shop`. Inferring one
            // (Carrier::getIdTaxRulesGroupMostUsed(), PS_CARRIER_DEFAULT, or a
            // rate derived from the amounts) is the one thing this design
            // forbids, so where no declared group is reachable the only
            // remaining source is another MERCHANT DECLARATION - the optional
            // Default shipping tax code (TWO-25200), which the caller applies
            // when the refusal below is raised - and failing that, the
            // refusal itself.
            if (is_object($fallback_carrier) && Validate::isLoadedObject($fallback_carrier)) {
                try {
                    $fallback_group_id = method_exists($fallback_carrier, 'getIdTaxRulesGroup')
                        ? (int) $fallback_carrier->getIdTaxRulesGroup()
                        : 0;
                    $fallback_rate = $this->getTwoCarrierConfiguredTaxRateDecimal($fallback_carrier, $cart);
                } catch (Throwable $e) {
                    throw $this->buildTwoShippingRateUnresolvableException(
                        $cart,
                        $shipping_gross,
                        0,
                        '',
                        (int) $cart->id_carrier,
                        'the declared tax-rules group of the cart\'s own carrier ' . (int) $cart->id_carrier .
                        ' could not be read either (' . get_class($e) . ': ' . $e->getMessage() . ')'
                    );
                }
                PrestaShopLogger::addLog(
                    'TwoPayment: Cart ' . (int) $cart->id . ' exposed no readable delivery-option carrier ' .
                    'list' . ($lookup_failure === '' ? '' : ' (' . $lookup_failure . ')') .
                    '; relaying the declared shipping tax rate from its own carrier ' .
                    (int) $cart->id_carrier . ' (tax_rules_group=' . $fallback_group_id . ', rate=' .
                    round($fallback_rate * 100, 2) . '%).',
                    1
                );

                return [
                    $this->formatTwoTaxRate($fallback_rate) => [
                        'rate' => $fallback_rate,
                        'net_weight' => max(0.0, (float) $shipping_gross),
                    ],
                ];
            }

            throw $this->buildTwoShippingRateUnresolvableException(
                $cart,
                $shipping_gross,
                0,
                '',
                0,
                $lookup_failure === ''
                    ? 'PrestaShop exposes no readable delivery-option carrier list for this cart and its own '
                        . 'carrier does not load either'
                    : 'the cart\'s delivery-option lookup failed (' . $lookup_failure .
                        ') and its own carrier does not load either'
            );
        }

        // Diagnostic (TWO-25161): this chain was verified against PrestaShop
        // core sources, never executed against a real merchant's carrier table.
        // Logging the resolved carrier and tax-rules-group IDs is how the case
        // a live cart actually hits gets confirmed in the field.
        PrestaShopLogger::addLog(
            'TwoPayment: Cart ' . (int) $cart->id . ' (id_carrier=' . (int) $cart->id_carrier .
            ') resolved ' . count($classes) . ' declared shipping tax rate(s) from its delivery-option ' .
            'carrier list: ' . implode('; ', $diagnostics) . '.',
            1
        );

        return $classes;
    }

    /**
     * Build the loud rejection for a cart whose shipping cost has no declared
     * tax rate behind it (TWO-25161).
     *
     * Returned rather than thrown so the call sites read as `throw $this->...`
     * and static analysis keeps seeing them as terminal.
     *
     * @param Cart $cart
     * @param float $shipping_gross
     * @param int $id_address Delivery address whose option failed, 0 if none resolved
     * @param string $option_key Selected delivery-option key ('0,' for the sentinel)
     * @param int $id_carrier Offending carrier id (0 for the sentinel)
     * @param string $reason Why no declared group is reachable; defaults to the
     *                       no-available-carrier sentinel this gate was added for
     * @return TwoCheckoutAmountException
     */
    private function buildTwoShippingRateUnresolvableException(
        $cart,
        $shipping_gross,
        $id_address,
        $option_key,
        $id_carrier,
        $reason = ''
    ) {
        if ($reason === '') {
            $reason = 'PrestaShop reports no available carrier (carrier_list = [0 => 0]) for this cart';
        }

        $detail = 'cart ' . (int) $cart->id . ', id_carrier=' . (int) $cart->id_carrier .
            ', shipping=' . $this->getTwoRoundAmount($shipping_gross) .
            ', delivery address=' . (int) $id_address .
            ', delivery option key=' . ($option_key === '' ? '(none)' : $option_key) .
            ', carrier in list=' . (int) $id_carrier;

        // A configured Default shipping tax code (TWO-25200) turns this into
        // a "not on the normal path" event rather than a failure: the caller
        // catches the exception and relays that declaration instead. Logging
        // it at error severity anyway would put a permanent red line in every
        // such merchant's log for the designed behaviour.
        $default_configured = $this->getTwoDefaultShippingTaxRulesGroupId() !== null;

        PrestaShopLogger::addLog(
            'TwoPayment: No deliverable carrier for the cart shipping cost, so no declared shipping ' .
            'tax rate exists to relay (' . $detail . '): ' . $reason . ', while the shipping is still ' .
            'priced. ' . ($default_configured
                ? 'Falling back to the configured Default shipping tax code.'
                : 'Configure a carrier that covers this delivery address and the cart contents.'),
            $default_configured ? 2 : 3
        );

        // Buyer-facing by type: the detail is nothing but the cart's own
        // amounts and identifiers, and naming the condition on the checkout
        // page is the whole point of the loud refusal (TWO-25161).
        return new TwoCheckoutAmountException(
            'No deliverable carrier for the cart shipping cost: ' . $reason . ', so there is no declared ' .
            'shipping tax-rules group to relay (' . $detail . ')'
        );
    }

    /**
     * Shared declared-rate resolver: the effective tax rate (decimal
     * fraction, e.g. 0.21) a TaxRulesGroup applies for THIS cart's tax
     * destination, resolved through the same core machinery PrestaShop's own
     * pricing uses (TaxManagerFactory over the PS_TAX_ADDRESS_TYPE address,
     * with the shop-wide PS_TAX gate and the vatnumber-module B2B
     * exemption). This is the single rate source for product, ecotax,
     * shipping and wrapping lines — the plugin relays the merchant's
     * declared rate and never derives one from amounts.
     *
     * Group id 0/unset returns 0.0 (core's "No tax" sentinel — ecotax and
     * wrapping groups are legitimately unset on many shops). Resolution
     * failures are logged UNCONDITIONALLY (not gated on debug mode) so a
     * swallowed-to-zero rate surfaces its root cause in the merchant log
     * instead of only appearing downstream as a formula mismatch.
     *
     * @param int $taxRulesGroupId
     * @param Cart $cart
     * @return float Decimal rate normalised to 2dp-of-percent
     */
    private function getTwoConfiguredTaxRateDecimalForGroup($taxRulesGroupId, $cart)
    {
        $taxRulesGroupId = (int) $taxRulesGroupId;
        if ($taxRulesGroupId <= 0 || !Validate::isLoadedObject($cart)) {
            return 0.0;
        }

        // Shop-wide "disable taxes" switch (core: Tax::excludeTaxeOption
        // inside Product::priceCalculation zeroes every product's tax).
        if (class_exists('Tax') && method_exists('Tax', 'excludeTaxeOption')) {
            if (Tax::excludeTaxeOption()) {
                return 0.0;
            }
        } elseif (!Configuration::get('PS_TAX')) {
            return 0.0;
        }

        // Same address granularity core used for the amounts: the cart's
        // PS_TAX_ADDRESS_TYPE address (the Configuration value string IS the
        // Cart property name), with a delivery fallback.
        $taxAddressField = (string) Configuration::get('PS_TAX_ADDRESS_TYPE');
        if ($taxAddressField !== 'id_address_delivery') {
            $taxAddressField = 'id_address_invoice';
        }
        $address = new Address((int) $cart->{$taxAddressField});
        if (!Validate::isLoadedObject($address)) {
            $address = new Address((int) $cart->id_address_delivery);
        }
        if (!Validate::isLoadedObject($address)) {
            PrestaShopLogger::addLog(
                'TwoPayment: No usable tax address on cart ' . (int) $cart->id .
                ' while resolving declared rate for tax rules group ' . $taxRulesGroupId,
                3
            );
            return 0.0;
        }

        // vatnumber-module B2B exemption — the exact condition core's
        // Product::priceCalculation flips usetax off with.
        if (
            !empty($address->vat_number)
            && (int) $address->id_country !== (int) Configuration::get('VATNUMBER_COUNTRY')
            && Configuration::get('VATNUMBER_MANAGEMENT')
        ) {
            return 0.0;
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

            $ratePercent = max(0, (float) $taxCalculator->getTotalRate());

            return $this->normalizeTwoTaxRateToPercentPrecision($ratePercent / 100);
        } catch (Exception $e) {
            // Unconditional: a broken/deleted tax group resolving to 0 must
            // show its cause here, not only as a downstream formula mismatch.
            PrestaShopLogger::addLog(
                'TwoPayment: Unable to resolve declared tax rate for tax rules group ' .
                $taxRulesGroupId . ' - ' . $e->getMessage(),
                3
            );
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
            // Validate the EMITTED 2dp amounts exactly as the API sees them.
            $net_amount = round((float)$item['net_amount'], 2);
            $tax_amount = round((float)$item['tax_amount'], 2);
            $gross_amount = round((float)$item['gross_amount'], 2);
            $tax_rate = (float)$item['tax_rate'];
            $unit_price = (float)$item['unit_price'];
            $quantity = (int)$item['quantity'];
            $discount_amount = (float)$item['discount_amount'];

            // Critical validation: tax_amount = net_amount * tax_rate (tax_rate is decimal)
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

            // Critical validation: gross_amount == net_amount + tax_amount EXACTLY
            // (2dp, compared in cents). The API enforces this per line in
            // addition to the tax-formula tolerance check.
            if ($this->convertAmountToCents($gross_amount) !== $this->convertAmountToCents(round($net_amount + $tax_amount, 2))) {
                PrestaShopLogger::addLog(
                    'TwoPayment CRITICAL Gross Formula Error - Item: ' . $item['name'] .
                    ', Got gross: ' . $gross_amount . ', Expected net+tax: ' . round($net_amount + $tax_amount, 2),
                    3
                );
                $validation_issues++;
            }

            // Critical validation: net_amount = (quantity * unit_price) - discount_amount
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
     * Verify the API key against the selected environment and CATEGORISE the
     * outcome (TWO-25326). Every non-200 used to collapse into a single
     * `false` - an expired key, a 5xx on Two's side and a network/routing
     * failure that never reached anything were indistinguishable, both to the
     * merchant reading the config page and to every gate that consumed the
     * result. They are separate conditions with separate remedies (fix the
     * key / wait / fix connectivity), so they are separate statuses here.
     *
     * @param string      $apiKey
     * @param string      $environment
     * @param int|null    $timeout seconds, defaulting to API_TIMEOUT_SHORT.
     *   The cached-status path passes API_TIMEOUT_STATE_CHECK because it can
     *   run inline in a shopper's page render; the config-page save keeps the
     *   longer default, since a merchant waiting on a save wants a certain
     *   answer more than a fast one. Note the config page's own NOTICE reads
     *   the cached status, so it inherits the tight timeout - deliberately:
     *   that path can equally be a cache miss on any admin page load, and the
     *   save above is where the patient check belongs.
     *
     * @return array{status:string,code:int|null,body:array|null}
     */
    protected function verifyTwoApiKey($apiKey, $environment, $timeout = null)
    {
        if (Tools::isEmpty($apiKey)) {
            return array('status' => self::API_KEY_STATUS_NOT_CONFIGURED, 'code' => null, 'body' => null);
        }

        $outcome = $this->requestTwoApiKeyVerification($apiKey, $environment, $timeout);
        $httpCode = isset($outcome['code']) ? (int) $outcome['code'] : 0;
        $response = isset($outcome['response']) ? $outcome['response'] : false;
        $transportError = isset($outcome['error']) ? (string) $outcome['error'] : '';

        // Nothing came back at all: a DNS/TLS/routing/timeout failure. Never a
        // credential verdict - reporting it as a bad key sends the merchant to
        // re-copy a key that was fine all along.
        if ($response === false || $transportError !== '') {
            PrestaShopLogger::addLog(
                'TwoPayment: API key verification could not reach the Two API - transport error: ' . $transportError,
                3
            );
            return array('status' => self::API_KEY_STATUS_UNREACHABLE, 'code' => null, 'body' => null);
        }

        if ($httpCode === self::HTTP_STATUS_UNAUTHORIZED || $httpCode === self::HTTP_STATUS_FORBIDDEN) {
            PrestaShopLogger::addLog('TwoPayment: API key rejected by the Two API (HTTP ' . $httpCode . ')', 2);
            return array('status' => self::API_KEY_STATUS_INVALID, 'code' => $httpCode, 'body' => null);
        }

        if ($httpCode >= self::HTTP_STATUS_SERVER_ERROR) {
            PrestaShopLogger::addLog('TwoPayment: API key verification hit a Two service error (HTTP ' . $httpCode . ')', 2);
            return array('status' => self::API_KEY_STATUS_SERVICE_ERROR, 'code' => $httpCode, 'body' => null);
        }

        if ($httpCode !== self::HTTP_STATUS_OK || !$response) {
            PrestaShopLogger::addLog('TwoPayment: API key verification returned an unexpected HTTP ' . $httpCode, 2);
            return array('status' => self::API_KEY_STATUS_ERROR, 'code' => $httpCode ?: null, 'body' => null);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            // A 200 whose body is not the merchant record is not a verified
            // key: something is answering on the endpoint's behalf (a captive
            // portal, a proxy error page). 'error' rather than 'invalid_key' -
            // the key was never judged.
            PrestaShopLogger::addLog('TwoPayment: API key verification returned an unreadable body on HTTP 200', 2);
            return array('status' => self::API_KEY_STATUS_ERROR, 'code' => $httpCode, 'body' => null);
        }

        PrestaShopLogger::addLog('TwoPayment: API key verified. Merchant ID: ' . (isset($decoded['id']) ? $decoded['id'] : 'N/A') . ', Short name: ' . (isset($decoded['short_name']) ? $decoded['short_name'] : 'N/A'), 1);
        return array('status' => self::API_KEY_STATUS_OK, 'code' => $httpCode, 'body' => $decoded);
    }

    /**
     * The wire call behind verifyTwoApiKey(), kept as its own seam so the
     * categorisation above is exercisable without a network.
     *
     * @param string   $apiKey
     * @param string   $environment
     * @param int|null $timeout
     *
     * @return array{response:string|false,code:int,error:string}
     */
    protected function requestTwoApiKeyVerification($apiKey, $environment, $timeout = null)
    {
        $base = $this->getTwoCheckoutHostUrlForEnvironment($environment);
        $url = $base . '/v1/merchant/verify_api_key?' . http_build_query($this->getTwoClientParams());
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'X-API-Key:' . $apiKey,
        ];
        PrestaShopLogger::addLog('TwoPayment: Verifying API key against ' . $base, 1);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout !== null ? max(1, (int) $timeout) : self::API_TIMEOUT_SHORT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::API_CONNECT_TIMEOUT);

        // SSL VERIFICATION - Secure by default
        $this->configureSslVerification($ch);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = (string) curl_error($ch);
        curl_close($ch);

        return array('response' => $response, 'code' => $httpCode, 'error' => $curlError);
    }

    /**
     * The cached, categorised verification status of the STORED API key
     * (TWO-25326). Consulted by the checkout gates - hookPaymentOptions() and
     * the checkout media hook that decides whether the company-search control
     * may run - so both agree, and so neither fires a live HTTP call per page
     * render. Same TTL-clock-in-Configuration shape as the FX table and the
     * merchant record; memoised per request on top, because a single checkout
     * render asks several times.
     *
     * A cached verdict is bound to the key it was reached for: a merchant who
     * pastes a different key misses the cache immediately rather than
     * inheriting the previous key's verdict.
     *
     * @return array{status:string,code:int|null}
     */
    public function getTwoApiKeyVerificationStatus($allowLiveCheck = true)
    {
        // The memo first: a verdict already reached in THIS request is the
        // answer, whatever the stored configuration says now (the config-page
        // save reaches one for the key it just validated, before the gates that
        // read it further down the same request).
        if (is_array($this->twoApiKeyStatusMemo)) {
            return $this->twoApiKeyStatusMemo;
        }

        $apiKey = (string) Configuration::get('PS_TWO_MERCHANT_API_KEY');
        if (Tools::isEmpty($apiKey)) {
            return array('status' => self::API_KEY_STATUS_NOT_CONFIGURED, 'code' => null);
        }

        $cached = $this->readCachedTwoApiKeyStatus($apiKey);
        if ($cached !== null) {
            return $cached;
        }

        // Callers that must not pay for a verification: a payment POST (a 10s
        // stall there is a stall in the buyer's submit) and the address-form
        // override, which also renders on my-account pages. They get "not
        // verified yet" and decide what that is worth to them - see
        // isTwoApiKeyDefinitelyUnusable().
        if (!$allowLiveCheck) {
            return array('status' => self::API_KEY_STATUS_VERIFYING, 'code' => null);
        }

        // Anti-stampede, same discipline as refreshTwoFxRates(): claim the slot
        // BEFORE the wire call, so concurrent renders on a cold cache read a
        // fresh-looking slot and stand down instead of each firing their own
        // verification. Without this, an unreachable API costs every concurrent
        // shopper the full timeout, once per failure TTL, for as long as the
        // outage lasts.
        //
        // What the claim CARRIES is the load-bearing part. A previous verdict
        // for this same key rides along (serve-stale: a shop that was healthy a
        // moment ago is not reported broken by a request that has not finished
        // asking - and, just as importantly, a re-verification every TTL does
        // not blink Two off a working shop). With NO previous verdict there is
        // nothing to serve, and the claim says exactly that rather than
        // guessing 'ok': guessing would offer Two - and let the payment POST
        // through - for the whole claim window on a shop whose key has never
        // verified, which is the failure this whole change exists to remove.
        // That state is reachable without a config-page save: install seeding, a
        // DB clone, direct SQL.
        $previous = $this->readStoredTwoApiKeyStatus();
        $carryable = $previous !== null
            && $previous['key_hash'] === self::verificationSlotKey($apiKey)
            && $previous['status'] !== self::API_KEY_STATUS_VERIFYING
            // Age of the VERDICT, not of the slot write. A zero here (a slot
            // with neither field readable) fails this comparison on its own, so
            // it needs no separate guard.
            && ($previous['verified_on'] + self::API_KEY_STATUS_CARRY_MAX_AGE) > time();
        $carry = $carryable
            ? $previous
            : array('status' => self::API_KEY_STATUS_VERIFYING, 'code' => null, 'verified_on' => 0);
        $this->writeTwoApiKeyStatusSlot($apiKey, $carry['status'], $carry['code'], true, $carry['verified_on']);

        // Tight timeout: on a cold cache this runs inline in a shopper's
        // checkout render. The TTL bounds how OFTEN the call happens, never
        // how long one call may block, so the timeout has to do that job.
        $result = $this->verifyTwoApiKey(
            $apiKey,
            Configuration::get('PS_TWO_ENVIRONMENT'),
            self::API_TIMEOUT_STATE_CHECK
        );

        return $this->cacheTwoApiKeyVerificationStatus($apiKey, $result);
    }

    /**
     * Whether the stored API key currently verifies. The single question every
     * gate asks; the category behind a `false` is for the merchant-facing
     * notice, never for deciding whether to serve Two.
     *
     * @return bool
     */
    public function isTwoApiKeyVerified($allowLiveCheck = true)
    {
        return $this->getTwoApiKeyVerificationStatus($allowLiveCheck)['status'] === self::API_KEY_STATUS_OK;
    }

    /**
     * Whether the stored key is known to be unusable, as opposed to merely
     * unconfirmed (TWO-25326, review round 2). Only the two DEFINITIVE
     * categories count: a key Two rejected, and no key at all. A 5xx, a network
     * blip or a cache miss are all "ask again", not "refuse".
     *
     * This is the question the payment POST asks, and it is deliberately a
     * different question from the one the render paths ask. Withholding the
     * payment option from a page not yet rendered costs a buyer nothing; turning
     * a submitted order into "this payment method is not available" over one
     * transient blip costs them the order - and the order-creation call they
     * would otherwise have reached has its own longer timeout and its own
     * decline handling. Consulted cache-only for the same reason.
     *
     * @return bool
     */
    public function isTwoApiKeyDefinitelyUnusable()
    {
        $status = $this->getTwoApiKeyVerificationStatus(false)['status'];
        if (self::isDefinitiveFailureStatus($status)) {
            return true;
        }

        // Deliberately reads PAST the TTL for the definitive categories only
        // (review round 3). This gate exists for the buyer who was already on the
        // payment step when the verdict changed - which is to say, minutes later -
        // and a fresh verdict is exactly what it does NOT have: it may not make
        // the call itself, and the failure TTL is a minute. "Two rejected this
        // key, when last asked" does not go out of date in a way that makes
        // accepting the submission the better guess, and it is the only definitive
        // information available. Transient categories are still ignored here, so
        // no blip can refuse an order however long it sits in the slot, and a
        // claim in flight is not a verdict at all.
        // key_hash: the slot must belong to the key AND environment the shop
        // holds NOW. Without it a stale rejection would refuse submissions on a
        // replacement key - a merchant fixing their key and immediately being
        // told the method is unavailable.
        //
        // claim: an EXPIRED claim is not read as a verdict here even though it
        // carries one. Fail-open, deliberately: a claim outliving its window
        // means the request that made it never finished, so nothing confirmed
        // that carried verdict, and refusing a submitted order needs firmer
        // ground than that. A claim still inside its window is served by the
        // branch above, where the carried verdict does count.
        $stored = $this->readStoredTwoApiKeyStatus();
        if ($stored === null || $stored['claim'] || $stored['key_hash'] !== self::verificationSlotKey((string) Configuration::get('PS_TWO_MERCHANT_API_KEY'))) {
            return false;
        }

        return self::isDefinitiveFailureStatus($stored['status']);
    }

    /**
     * Whether the company-search affordance this module adds to the checkout is
     * warranted right now (TWO-25326, review round 4) - the browser control and
     * the server-rendered placeholder both, since they are two halves of one
     * thing and this is the one question both ask.
     *
     * Warranted means "a captured company can still be used for something",
     * which on any known verification failure it cannot: Two is withheld from
     * checkout entirely in that state, so the search has nothing left to feed.
     * It does NOT mean "the search would work" - that endpoint is called
     * unauthenticated and works regardless of the key (round-6 review corrected
     * the reasoning; the behaviour is unchanged and matches the sibling
     * plugins).
     *
     * Distinct from isTwoApiKeyDefinitelyUnusable(): an affordance is not an
     * order, so this side may stand down on ANY known failure rather than only a
     * definitive one.
     *
     * 'verifying' (nothing known yet) counts as warranted: a cold cache is not
     * evidence of a broken shop, and the caller that most needs this - the
     * address-form override - must not be able to block on an HTTP call, hence
     * cache-only by default. The checkout media hook opts into a live check,
     * because that page is a render and the verdict is what its whole
     * company-search bootstrap turns on.
     *
     * @return bool
     */
    public function isTwoCompanySearchAffordanceWarranted($allowLiveCheck = false)
    {
        $status = $this->getTwoApiKeyVerificationStatus($allowLiveCheck)['status'];

        return $status === self::API_KEY_STATUS_OK || $status === self::API_KEY_STATUS_VERIFYING;
    }

    /**
     * The categories that mean "this integration cannot take an order", as
     * opposed to "ask again later". The ONE definition of that set - the
     * payment POST's gate asks through this, so nothing re-lists it.
     *
     * @param string $status
     *
     * @return bool
     */
    public static function isDefinitiveFailureStatus($status)
    {
        return $status === self::API_KEY_STATUS_INVALID || $status === self::API_KEY_STATUS_NOT_CONFIGURED;
    }

    /**
     * Stores a categorised verification outcome as the cached verdict for
     * $apiKey. The ONE writer, shared by the cache-miss path above and by the
     * config-page save - a merchant who has just fixed a broken key must not
     * have to wait out the TTL for checkout to notice.
     *
     * @param string $apiKey
     * @param array  $result verifyTwoApiKey()'s return value
     *
     * @return array{status:string,code:int|null}
     */
    public function cacheTwoApiKeyVerificationStatus($apiKey, $result)
    {
        $status = array(
            'status' => isset($result['status']) ? (string) $result['status'] : self::API_KEY_STATUS_ERROR,
            'code' => isset($result['code']) && $result['code'] !== null ? (int) $result['code'] : null,
        );

        $this->writeTwoApiKeyStatusSlot($apiKey, $status['status'], $status['code']);

        if ((string) $apiKey === (string) Configuration::get('PS_TWO_MERCHANT_API_KEY')) {
            $this->twoApiKeyStatusMemo = $status;
        }

        return $status;
    }

    /**
     * Writes one verdict into $apiKey's cache slot and stamps the clock. The
     * only place either config key is written.
     *
     * @param string   $apiKey
     * @param string   $status
     * @param int|null $code
     * @param bool     $asClaim marks the slot as a claim rather than a verdict:
     *   it then expires after API_KEY_STATUS_CLAIM_WINDOW instead of a full TTL,
     *   so a claim whose process died mid-call stops standing in for a verdict
     *   within seconds. Recorded as a flag rather than by back-dating the clock,
     *   which would write a future-dated timestamp the moment any TTL was
     *   shortened below the claim window.
     */
    private function writeTwoApiKeyStatusSlot($apiKey, $status, $code, $asClaim = false, $verifiedOn = null)
    {
        if (Tools::isEmpty($apiKey)) {
            return;
        }

        Configuration::updateValue(self::CONFIG_API_KEY_STATUS, json_encode(array(
            'status' => (string) $status,
            'code' => $code === null ? null : (int) $code,
            'key_hash' => self::verificationSlotKey($apiKey),
            'claim' => (bool) $asClaim,
            // When the VERDICT was reached, as distinct from when the slot was
            // last written: a claim re-writes the slot but carries the original
            // verdict's age with it, which is what bounds serve-stale.
            'verified_on' => $verifiedOn === null ? time() : (int) $verifiedOn,
        )));
        Configuration::updateValue(self::CONFIG_API_KEY_STATUS_TS, time());
    }

    /**
     * Slot identity: the API key AND the environment it was verified against. A
     * key is only valid for one environment, so an environment change by any
     * route that does not go through the config-page save (an upgrade script,
     * dev/configure.php, direct SQL) must miss the cache rather than carry the
     * other environment's verdict for a full TTL.
     *
     * @param string $apiKey
     *
     * @return string
     */
    private static function verificationSlotKey($apiKey)
    {
        return md5((string) $apiKey . '|' . Tools::strtolower((string) Configuration::get('PS_TWO_ENVIRONMENT')));
    }

    /**
     * How long a verdict of $status stays usable. A failed verdict expires
     * sooner than a successful one: an outage or a just-rotated key should stop
     * hiding Two within a minute, while a healthy shop should not re-verify
     * every minute for nothing.
     *
     * @param string $status
     *
     * @return int seconds
     */
    private static function statusTtlFor($status)
    {
        return $status === self::API_KEY_STATUS_OK
            ? self::API_KEY_STATUS_TTL
            : self::API_KEY_STATUS_FAILURE_TTL;
    }

    /**
     * The stored verdict as written, ignoring the TTL and ignoring which key it
     * belongs to - callers decide what to do with both. Null when nothing
     * usable is stored.
     *
     * @return array{status:string,code:int|null,key_hash:string,claim:bool,verified_on:int,checked_on:int}|null
     */
    private function readStoredTwoApiKeyStatus()
    {
        $raw = Configuration::get(self::CONFIG_API_KEY_STATUS);
        if (Tools::isEmpty($raw)) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || !isset($decoded['status'], $decoded['key_hash'])) {
            return null;
        }

        return array(
            'status' => (string) $decoded['status'],
            'code' => isset($decoded['code']) && $decoded['code'] !== null ? (int) $decoded['code'] : null,
            'key_hash' => (string) $decoded['key_hash'],
            'claim' => !empty($decoded['claim']),
            // A slot written before this field existed is not ageless: fall back
            // to the slot's own clock, which for a verdict IS when it was
            // reached. Reading it as 0 made such a slot uncarryable, so the first
            // re-verification against it withheld Two for the length of one claim
            // window (review round 4). No released version ever wrote this slot -
            // it arrives with this change - so the only shops holding a
            // field-less one are those that ran an intermediate build of this
            // branch; the fallback stays because the JSON shape is not something
            // to assume about a value read back out of Configuration.
            'verified_on' => isset($decoded['verified_on'])
                ? (int) $decoded['verified_on']
                : (int) Configuration::get(self::CONFIG_API_KEY_STATUS_TS),
            'checked_on' => (int) Configuration::get(self::CONFIG_API_KEY_STATUS_TS),
        );
    }

    /**
     * The still-fresh cached verdict for $apiKey, or null when there is none to
     * use (never stored, stored for a different key, or expired).
     *
     * @param string $apiKey
     *
     * @return array{status:string,code:int|null}|null
     */
    private function readCachedTwoApiKeyStatus($apiKey)
    {
        if (is_array($this->twoApiKeyStatusMemo)) {
            return $this->twoApiKeyStatusMemo;
        }

        $stored = $this->readStoredTwoApiKeyStatus();
        if ($stored === null || $stored['key_hash'] !== self::verificationSlotKey($apiKey)) {
            return null;
        }
        $ttl = $stored['claim'] ? self::API_KEY_STATUS_CLAIM_WINDOW : self::statusTtlFor($stored['status']);
        if ($stored['checked_on'] <= 0 || ($stored['checked_on'] + $ttl) <= time()) {
            return null;
        }

        $this->twoApiKeyStatusMemo = array('status' => $stored['status'], 'code' => $stored['code']);

        return $this->twoApiKeyStatusMemo;
    }

    /**
     * Merchant-facing wording for a verification failure category
     * (TWO-25326). Category and HTTP status only - the response body is
     * deliberately not surfaced in the back office; it belongs in the log.
     *
     * @param string   $status
     * @param int|null $code
     *
     * @return string
     */
    public function getTwoApiKeyFailureMessage($status, $code = null)
    {
        switch ($status) {
            case self::API_KEY_STATUS_INVALID:
                return $this->l('This API key was rejected by Two. It may be invalid or expired - check the key in your Two portal.');
            case self::API_KEY_STATUS_SERVICE_ERROR:
                return sprintf(
                    $this->l('Two could not verify the API key right now (HTTP %d). This is usually temporary - try again shortly.'),
                    (int) $code
                );
            case self::API_KEY_STATUS_UNREACHABLE:
                return $this->l('This shop could not reach the Two API at all (network, DNS or firewall). The API key itself has not been judged.');
            case self::API_KEY_STATUS_NOT_CONFIGURED:
                return $this->l('Enter your Two API key to enable Two.');
            default:
                return $code
                    ? sprintf($this->l('Two returned an unexpected response while verifying the API key (HTTP %d).'), (int) $code)
                    : $this->l('The API key could not be verified.');
        }
    }

    /**
     * Config-page notice for a stored key that does not currently verify
     * (TWO-25326), or '' when there is nothing to say. Not merely
     * informational: while this shows, Two is withheld from checkout, so the
     * notice states that too.
     *
     * @return string
     */
    protected function getTwoApiKeyStatusNotice()
    {
        $status = $this->getTwoApiKeyVerificationStatus();
        if ($status['status'] === self::API_KEY_STATUS_OK
            // A verification another request is still making is not a diagnosis,
            // and must not be reported as one - the default wording below would
            // otherwise tell the merchant their key "could not be verified" while
            // it is being verified. The panel above reads "not verified" for the
            // same seconds, which is true and self-correcting on reload.
            || $status['status'] === self::API_KEY_STATUS_VERIFYING
            || $status['status'] === self::API_KEY_STATUS_NOT_CONFIGURED) {
            // No stored key at all is what the form's own "Enter an API key"
            // validation is for - two notices saying it is noise.
            return '';
        }

        return $this->getTwoApiKeyFailureMessage($status['status'], $status['code'])
            . ' ' . $this->l('Two is hidden from checkout until the key verifies.');
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
            $this->getTwoClientParams(),
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
                        // The invoice-upload gate is fed by this same fetch
                        // (TWO-25111). Unlike the term list, an absent
                        // field IS an answer here - the backend omitting the
                        // flag means uploads are not enabled for this
                        // merchant, so cache 0 rather than serving stale
                        // (null-safe absent-is-false, per TWO-25106).
                        Configuration::updateValue(
                            self::CONFIG_MERCHANT_INVOICE_DISTRIBUTED,
                            (isset($response['invoice_distributed_by_merchant']) && $response['invoice_distributed_by_merchant'] === true) ? 1 : 0
                        );
                        // Third cache fed by the same fetch: the platform
                        // minimum-order tuple (TWO-24775). Unlike the term
                        // list, an absent or malformed tuple IS the answer
                        // ("no minimum configured") - overwrite with '' so
                        // the no-minimum outcome is cached and the gate does
                        // not refetch on every checkout render.
                        $platform_minimum = $this->parseTwoPlatformMinimumOrder($response);
                        Configuration::updateValue(
                            self::CONFIG_PLATFORM_MIN_ORDER,
                            $platform_minimum ? json_encode($platform_minimum) : ''
                        );
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
        // The platform minimum is part of the same merchant record: a new
        // identity must not be gated by the old merchant's minimum. '' means
        // "no minimum" - the correct fail-open posture until the re-fetch
        // (the API still enforces the real minimum at order create).
        Configuration::updateValue(self::CONFIG_PLATFORM_MIN_ORDER, '');
        // The invoice-upload gate is sourced from the same merchant record:
        // an identity change must never leave the OLD merchant's upload
        // entitlement in force for the new one (TWO-25111). Fail closed.
        Configuration::updateValue(self::CONFIG_MERCHANT_INVOICE_DISTRIBUTED, 0);
        Configuration::updateValue(self::CONFIG_MERCHANT_AVAILABLE_TERMS_TS, 0);
    }

    /**
     * Whether the merchant distributes their own invoices - the server-side
     * `invoice_distributed_by_merchant` flag from the cached GET /v1/merchant
     * record. This is the ONLY gate for the plugin-side invoice upload
     * (TwoInvoiceUploadService): the manual PS_TWO_USE_OWN_INVOICES admin
     * toggle is retired (TWO-25111 / TWO-25106 Option A) and any leftover
     * value of it in the configuration table has zero effect. checkout-api
     * enforces the same flag server-side (403 when false, TWO-24761), so this
     * plugin-side gate only avoids doomed upload attempts; it is not a
     * security boundary.
     *
     * Cache-only (never fetches): refreshed by the same TTL-gated fetch as
     * the available-terms cache. Null-safe: unresolved/absent caches as 0.
     *
     * @return bool
     */
    public function isMerchantInvoiceDistributed()
    {
        return (bool) Configuration::get(self::CONFIG_MERCHANT_INVOICE_DISTRIBUTED);
    }

    /**
     * Project the platform minimum-order tuple out of a GET /v1/merchant
     * response body (TWO-24775). The API omits all three min_order_* fields
     * when no minimum is configured; a partial or malformed tuple is treated
     * the same way rather than gating on a guess. Mirrors woocommerce-plugin's
     * get_platform_minimum_order() parsing and magento-plugin's
     * MinimumOrderProvider::parseMinimum().
     *
     * @param mixed $response Decoded response body.
     * @return array{amount: float, currency: string, basis: string}|null
     */
    public function parseTwoPlatformMinimumOrder($response)
    {
        if (!is_array($response)) {
            return null;
        }
        $amount = isset($response['min_order_amount']) ? $response['min_order_amount'] : null;
        $currency = isset($response['min_order_currency']) ? $response['min_order_currency'] : null;
        $basis = isset($response['min_order_basis']) ? $response['min_order_basis'] : null;
        if (
            !is_numeric($amount) || (float) $amount <= 0
            || !is_string($currency) || trim($currency) === ''
            || !in_array($basis, array('net', 'gross'), true)
        ) {
            return null;
        }
        return array(
            'amount' => (float) $amount,
            'currency' => Tools::strtoupper(trim($currency)),
            'basis' => $basis,
        );
    }

    /**
     * The platform's minimum order value for this merchant (funding-partner
     * default with any merchant override, resolved server-side on
     * GET /v1/merchant/{id} - the same value checkout-api enforces at order
     * create/intent), as ['amount','currency','basis'] or null when none is
     * configured or the record is not yet resolved (TWO-24775).
     *
     * CACHE-ONLY - never blocks on HTTP. Primed by the SAME fetch as the
     * available_terms list (getMerchantAvailableTerms) from the sanctioned
     * refresh points (checkout media render, admin config render). A cold or
     * failed cache resolves to null = no minimum: the server still enforces,
     * and hiding the payment method on an API blip would be the worse failure
     * (fail-open, matching woocommerce-plugin and magento-plugin).
     *
     * @return array{amount: float, currency: string, basis: string}|null
     */
    public function getPlatformMinimumOrder()
    {
        $cached = Configuration::get(self::CONFIG_PLATFORM_MIN_ORDER);
        if (Tools::isEmpty($cached)) {
            return null;
        }
        $decoded = json_decode((string) $cached, true);
        // Re-validate on read: Configuration is shared mutable state and the
        // tuple shape is the gate's contract.
        return $this->parseTwoPlatformMinimumOrder(array(
            'min_order_amount' => isset($decoded['amount']) ? $decoded['amount'] : null,
            'min_order_currency' => isset($decoded['currency']) ? $decoded['currency'] : null,
            'min_order_basis' => isset($decoded['basis']) ? $decoded['basis'] : null,
        ));
    }

    /**
     * The merchant's own optional minimum order value from the admin config,
     * as ['amount','currency','basis'] or null when unset. Interpreted in the
     * SHOP DEFAULT currency on the tax basis the merchant selects, falling
     * back to the platform minimum's basis when unset, else gross - the same
     * semantics as woocommerce-plugin's get_merchant_minimum_order()
     * (TWO-24775).
     *
     * @return array{amount: float, currency: string, basis: string}|null
     */
    public function getMerchantMinimumOrder()
    {
        $value = (float) Configuration::get(self::CONFIG_MERCHANT_MIN_ORDER);
        if ($value <= 0) {
            return null;
        }
        $currency_iso = $this->getTwoShopDefaultCurrencyIso();
        if ($currency_iso === '') {
            return null;
        }
        $basis = (string) Configuration::get(self::CONFIG_MERCHANT_MIN_ORDER_BASIS);
        if (!in_array($basis, array('net', 'gross'), true)) {
            $platform_minimum = $this->getPlatformMinimumOrder();
            $basis = $platform_minimum ? $platform_minimum['basis'] : 'gross';
        }
        return array(
            'amount' => $value,
            'currency' => $currency_iso,
            'basis' => $basis,
        );
    }

    /**
     * ISO code of the shop's default currency ('' when unresolvable).
     *
     * @return string
     */
    public function getTwoShopDefaultCurrencyIso()
    {
        $default_currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        if (!Validate::isLoadedObject($default_currency)) {
            return '';
        }
        return Tools::strtoupper(trim((string) $default_currency->iso_code));
    }

    /**
     * Convert an amount between two currencies via Two's own FX spot rates
     * (GET /refdata/v1/fx-rates, TWO-25105) - NOT PrestaShop core's
     * conversion rates, so the plugin reasons with the same rates
     * checkout-api enforces server-side. Returns null when no rate table has
     * ever been fetched or either currency is absent from it - the CALLER
     * decides the failure posture, and since TWO-25269 every path that
     * decides an amount the buyer is CHARGED fails closed: the
     * platform-minimum gate and the buyer-surcharge gate both withhold the
     * payment option. Only DISPLAY paths (the decline hint, the admin
     * descriptions, the per-term chip previews) still degrade.
     *
     * Deliberately silent: this method is shared by fail-closed and
     * fail-soft callers, so an error log here would fire on every preview
     * degrade. The fail-closed decision sites log instead - see
     * isTwoSurchargeQuotableForCart, convertTwoBuyerFeeShareCurrency and
     * logTwoMinimumOrderGateDecision.
     *
     * A stale table is still served (last-known-good, ticket fail
     * semantics); a cache miss triggers ONE TTL/backoff-gated on-demand
     * fetch so a checkout in a not-yet-cached currency resolves within the
     * same request and lands in the shared cache for the next render.
     *
     * @param float $amount
     * @param string $from_iso
     * @param string $to_iso
     * @return float|null Converted amount rounded to 2 decimals, or null.
     */
    public function convertTwoAmountBetweenCurrencies($amount, $from_iso, $to_iso)
    {
        $from_iso = Tools::strtoupper(trim((string) $from_iso));
        $to_iso = Tools::strtoupper(trim((string) $to_iso));
        if ($from_iso === '' || $to_iso === '') {
            return null;
        }
        if ($from_iso === $to_iso) {
            return round((float) $amount, 2);
        }

        $rates = $this->getTwoFxRatesForPair($from_iso, $to_iso);
        if ($rates === null) {
            return null;
        }
        // rates[CCY] = value of 1 CCY in EUR (the endpoint's EUR pivot):
        // FROM -> EUR -> TO. Full-precision arithmetic, rounded once at the
        // boundary.
        return round((float) $amount * $rates[$from_iso] / $rates[$to_iso], 2);
    }

    /**
     * Usable EUR-pivot rates for a currency pair, or null. Serves the cached
     * table (stale included - serve-stale is the gate's last-known-good
     * contract); when the table is missing or lacks either currency, allows
     * one TTL/backoff-gated refresh before giving up, so a first-ever
     * checkout in an uncached currency is fetched on demand and cached.
     *
     * @param string $from_iso normalised ISO code
     * @param string $to_iso normalised ISO code
     * @return array<string,float>|null
     */
    private function getTwoFxRatesForPair($from_iso, $to_iso)
    {
        foreach (array(false, true) as $refreshed) {
            if ($refreshed) {
                if (!$this->refreshTwoFxRates()) {
                    return null;
                }
            }
            $table = $this->getTwoFxRatesTable();
            if ($table !== null) {
                $from_rate = isset($table['rates'][$from_iso]) ? (float) $table['rates'][$from_iso] : 0.0;
                $to_rate = isset($table['rates'][$to_iso]) ? (float) $table['rates'][$to_iso] : 0.0;
                if ($from_rate > 0 && $to_rate > 0) {
                    return array($from_iso => $from_rate, $to_iso => $to_rate);
                }
            }
        }
        return null;
    }

    /**
     * The cached FX spot-rate table (TWO-25105), or null when none was ever
     * stored. CACHE-ONLY - never blocks on HTTP; staleness is deliberately
     * NOT checked here (a stale table is the last-known-good the gate must
     * keep using; only refreshTwoFxRates consults the clock). Re-validated
     * on read: Configuration is shared mutable state.
     *
     * @return array{base:string,as_of:string,rates:array<string,float>}|null
     */
    public function getTwoFxRatesTable()
    {
        if ($this->twoFxRatesMemo !== false) {
            return $this->twoFxRatesMemo;
        }
        $this->twoFxRatesMemo = null;
        $cached = Configuration::get(self::CONFIG_FX_RATES);
        if (!Tools::isEmpty($cached)) {
            $decoded = json_decode((string) $cached, true);
            if (is_array($decoded) && isset($decoded['rates']) && is_array($decoded['rates'])) {
                $rates = array();
                foreach ($decoded['rates'] as $iso => $rate) {
                    if (is_string($iso) && $iso !== '' && is_numeric($rate) && (float) $rate > 0) {
                        $rates[Tools::strtoupper($iso)] = (float) $rate;
                    }
                }
                if (!empty($rates)) {
                    $this->twoFxRatesMemo = array(
                        'base' => isset($decoded['base']) ? (string) $decoded['base'] : 'EUR',
                        'as_of' => isset($decoded['as_of']) ? (string) $decoded['as_of'] : '',
                        'rates' => $rates,
                    );
                }
            }
        }
        return $this->twoFxRatesMemo;
    }

    /**
     * TTL-gated fetch of GET /refdata/v1/fx-rates (TWO-25105). Called from
     * the checkout media hook (the same sanctioned refresh point as the
     * merchant record - the "6h background refresh") and on-demand from a
     * conversion cache miss. Same discipline as getMerchantAvailableTerms:
     * the clock is bumped BEFORE the wire call (anti-stampede), a failure
     * rolls it back to the short retry backoff and keeps serving the
     * last-known-good table, and the whole thing is skipped without an API
     * key (the endpoint is merchant-API-key authenticated; also keeps unit
     * specs off the wire). The stored `as_of` is the endpoint's staleness
     * floor for the rates and rides along for logging/QA; freshness gating
     * uses the local fetch clock, so an unchanged `as_of` on refetch simply
     * renews the TTL rather than forcing extra fetches.
     *
     * @return bool Whether a wire fetch was attempted AND succeeded.
     */
    public function refreshTwoFxRates()
    {
        $checked_on = (int) Configuration::get(self::CONFIG_FX_RATES_TS);
        if ($checked_on > 0 && ($checked_on + self::FX_RATES_TTL) > time()) {
            return false;
        }
        $api_key = Configuration::get('PS_TWO_MERCHANT_API_KEY');
        if (Tools::isEmpty($api_key)) {
            return false;
        }
        Configuration::updateValue(self::CONFIG_FX_RATES_TS, time());
        // Refreshes run on render/checkout paths - cap tight, never stall.
        $response = $this->setTwoPaymentRequest(
            '/refdata/v1/fx-rates',
            array(),
            'GET',
            array(),
            self::API_TIMEOUT_STATE_CHECK
        );
        $http_status = isset($response['http_status']) ? (int) $response['http_status'] : 0;
        // Validate BEFORE merging/storing: only positive-numeric rates may
        // displace cached last-known-good values, otherwise a 200 carrying
        // zero/junk rates would erode (or wholesale destroy) the validated
        // table the serve-stale contract depends on.
        $rates = array();
        if (is_array($response) && isset($response['rates']) && is_array($response['rates'])) {
            foreach ($response['rates'] as $iso => $rate) {
                if (is_string($iso) && $iso !== '' && is_numeric($rate) && (float) $rate > 0) {
                    $rates[Tools::strtoupper($iso)] = (float) $rate;
                }
            }
        }
        if ($http_status !== self::HTTP_STATUS_OK || empty($rates)) {
            // Failed fetch: roll the pre-bumped clock back so retry happens
            // after the short backoff, not a whole TTL; the last-known-good
            // table keeps being served meanwhile (serve-stale).
            Configuration::updateValue(
                self::CONFIG_FX_RATES_TS,
                time() - self::FX_RATES_TTL + self::FX_RATES_RETRY_BACKOFF
            );
            PrestaShopLogger::addLog(
                'TwoPayment: FX rates fetch failed (HTTP ' . $http_status . ') - serving last-known-good rates',
                2
            );
            return false;
        }
        // Merge the fresh rates OVER the cached table rather than replacing
        // it: a 200 that transiently drops a previously-known currency must
        // not erode that currency's last-known-good rate (which would fail
        // its gate closed for a full TTL). Fresh values always win; retained
        // stragglers may predate the stored as_of, which is the freshness
        // floor of the NEW response.
        $previous = $this->getTwoFxRatesTable();
        if ($previous !== null) {
            $rates = array_merge($previous['rates'], $rates);
        }
        Configuration::updateValue(self::CONFIG_FX_RATES, json_encode(array(
            'base' => isset($response['base']) ? (string) $response['base'] : 'EUR',
            'as_of' => isset($response['as_of']) ? (string) $response['as_of'] : '',
            'rates' => $rates,
        )));
        $this->twoFxRatesMemo = false; // drop the request-scoped memo
        return true;
    }

    /**
     * The cart's total on the given tax basis, via PrestaShop's own cart
     * total API (net = everything excluding tax, gross = including tax).
     *
     * @param Cart $cart
     * @param string $basis 'net' or 'gross'
     * @return float
     */
    public function getTwoMinimumOrderBasketValue($cart, $basis)
    {
        return (float) $cart->getOrderTotal($basis === 'gross', Cart::BOTH);
    }

    /**
     * Whether the cart satisfies the platform minimum order value AND the
     * merchant's own optional minimum (TWO-24775). Both bars must pass; each
     * is compared on its own declared tax basis, inclusive (an
     * exactly-minimum basket passes - woocommerce/magento parity).
     *
     * Failure posture per bar, matching magento-plugin's MinimumOrderGate:
     * - No minimum resolved (cold cache, API blip, none configured): pass -
     *   the API still enforces the platform minimum at order create.
     * - Cross-currency basket that CANNOT be converted to the platform
     *   minimum's currency (currency not installed / no usable rate): fail
     *   CLOSED - an order that cannot be proven to satisfy the funding
     *   partner's product minimum must not be offered the method.
     * - Merchant's own bar unjudgeable for the same reason: fail OPEN - it is
     *   the merchant's optional preference, and the platform floor above
     *   still applies.
     *
     * @param Cart $cart
     * @return bool
     */
    public function isTwoMinimumOrderSatisfied($cart)
    {
        $platform_minimum = $this->getPlatformMinimumOrder();
        $merchant_minimum = $this->getMerchantMinimumOrder();
        if (!$platform_minimum && !$merchant_minimum) {
            return true;
        }
        if (!Validate::isLoadedObject($cart)) {
            return true;
        }

        $cart_currency = new Currency((int) $cart->id_currency);
        $cart_iso = Validate::isLoadedObject($cart_currency)
            ? Tools::strtoupper(trim((string) $cart_currency->iso_code))
            : '';

        if ($platform_minimum) {
            $basket_value = $this->getTwoMinimumOrderBasketValue($cart, $platform_minimum['basis']);
            $converted = $this->convertTwoAmountBetweenCurrencies($basket_value, $cart_iso, $platform_minimum['currency']);
            if ($converted === null) {
                // Fail closed: cannot compare apples to apples.
                $this->logTwoMinimumOrderGateDecision($cart, $platform_minimum, 'platform minimum unjudgeable (no FX rate ' . ($cart_iso ?: '?') . '->' . $platform_minimum['currency'] . '), failing closed');
                return false;
            }
            if ($converted < $platform_minimum['amount']) {
                $this->logTwoMinimumOrderGateDecision($cart, $platform_minimum, 'below platform minimum (basket ' . $converted . ' ' . $platform_minimum['currency'] . ' ' . $platform_minimum['basis'] . ')');
                return false;
            }
        }

        if ($merchant_minimum) {
            $basket_value = $this->getTwoMinimumOrderBasketValue($cart, $merchant_minimum['basis']);
            $converted = $this->convertTwoAmountBetweenCurrencies($basket_value, $cart_iso, $merchant_minimum['currency']);
            // $converted === null: fail open on the merchant's own optional
            // bar (see docblock) - only a resolved comparison may hide.
            if ($converted !== null && $converted < $merchant_minimum['amount']) {
                $this->logTwoMinimumOrderGateDecision($cart, $merchant_minimum, 'below merchant minimum (basket ' . $converted . ' ' . $merchant_minimum['currency'] . ' ' . $merchant_minimum['basis'] . ')');
                return false;
            }
        }

        return true;
    }

    /**
     * Removing a payment method is invisible to the merchant - log the
     * failing basket so a gate misconfiguration doesn't read as the payment
     * option silently vanishing.
     *
     * @param Cart $cart
     * @param array $minimum
     * @param string $reason
     * @return void
     */
    private function logTwoMinimumOrderGateDecision($cart, $minimum, $reason)
    {
        PrestaShopLogger::addLog(
            'TwoPayment: Payment option hidden for cart ' . (int) $cart->id . ' - ' . $reason
            . ' (minimum ' . $minimum['amount'] . ' ' . $minimum['currency'] . ' ' . $minimum['basis'] . ')',
            2
        );
    }

    /**
     * Buyer-facing minimum-order hint for a declined order create / order
     * intent response (TWO-24775): '' when the decline is not attributable to
     * the platform minimum, else a hint formatted in the CART currency
     * ("Minimum order value is <symbol><amount> excluding|including tax." -
     * woocommerce/magento wording parity).
     *
     * Attribution: primarily the API's machine-readable decline reason
     * (decline_reason === ORDER_BELOW_MIN_INVOICE_AMOUNT), with a
     * strictly-below-minimum check on the cart value as fallback while older
     * backends carry only a generic reason. Fail-soft throughout: an
     * unresolvable FX conversion means no hint, never a blocked message
     * (mirrors magento-plugin's isBelowMinimum/getMinimumForDisplay).
     *
     * @param mixed $response Decoded provider response body (order create or intent).
     * @param Cart $cart
     * @return string
     */
    public function getTwoMinimumOrderDeclineHint($response, $cart)
    {
        $platform_minimum = $this->getPlatformMinimumOrder();
        if (!$platform_minimum || !Validate::isLoadedObject($cart)) {
            return '';
        }

        $decline_reason = null;
        if (is_array($response)) {
            if (isset($response['decline_reason'])) {
                $decline_reason = $response['decline_reason'];
            } elseif (isset($response['data']['decline_reason'])) {
                $decline_reason = $response['data']['decline_reason'];
            }
        }

        $cart_currency = new Currency((int) $cart->id_currency);
        if (!Validate::isLoadedObject($cart_currency)) {
            return '';
        }
        $cart_iso = Tools::strtoupper(trim((string) $cart_currency->iso_code));

        $declined_on_minimum = ($decline_reason === 'ORDER_BELOW_MIN_INVOICE_AMOUNT');
        if (!$declined_on_minimum) {
            // Fallback: strictly below the minimum on the minimum's basis.
            $basket_value = $this->getTwoMinimumOrderBasketValue($cart, $platform_minimum['basis']);
            $converted = $this->convertTwoAmountBetweenCurrencies($basket_value, $cart_iso, $platform_minimum['currency']);
            $declined_on_minimum = ($converted !== null && $converted < $platform_minimum['amount']);
        }
        if (!$declined_on_minimum) {
            return '';
        }

        // Express the minimum in the buyer's (cart) currency for display.
        $display_amount = $this->convertTwoAmountBetweenCurrencies(
            $platform_minimum['amount'],
            $platform_minimum['currency'],
            $cart_iso
        );
        if ($display_amount === null) {
            return '';
        }
        return sprintf(
            $this->l('Minimum order value is %1$s%2$s %3$s tax.'),
            $this->getTwoCurrencyDisplaySymbol($cart_currency),
            number_format($display_amount, 2),
            $platform_minimum['basis'] === 'gross' ? $this->l('including') : $this->l('excluding')
        );
    }

    /**
     * Display symbol for a currency, falling back to "ISO " when the
     * installation carries no symbol.
     *
     * @param Currency $currency
     * @return string
     */
    private function getTwoCurrencyDisplaySymbol($currency)
    {
        if (isset($currency->symbol) && !Tools::isEmpty($currency->symbol)) {
            return (string) $currency->symbol;
        }
        if (isset($currency->sign) && !Tools::isEmpty($currency->sign)) {
            return (string) $currency->sign;
        }
        return Tools::strtoupper(trim((string) $currency->iso_code)) . ' ';
    }

    /**
     * Fetch the merchant fee (what Two charges the merchant, NOT the buyer
     * surcharge) per net-term via POST /pricing/v1/merchant/rates, for the
     * inline fee display beside each "Available Payment Terms" checkbox on
     * the admin config page. Mirrors magento-plugin's
     * Controller/Adminhtml/Config/Fees.php.
     *
     * Fail-soft contract (identical to Magento's): ANY failure - missing API
     * key, empty term list, connection error, non-200, malformed body -
     * returns array('success' => false) and the admin page renders without
     * fees. This method must never throw and never block long (tight
     * timeout): it sits behind an AJAX call on a config-page render path.
     *
     * The buyer_country_code is a best-effort stand-in (store default
     * country): there is no cart/buyer context on a config page. Magento
     * uses its store's default country the same way.
     *
     * @param array $days Requested term day-counts (raw, will be normalised).
     * @return array{success:bool,currency?:string,fees?:array<string,array{percentage:float,fixed:float}>}
     */
    public function fetchTwoMerchantFeeRates($days)
    {
        $net_terms = $this->normaliseMerchantTerms($days);
        if (empty($net_terms)) {
            return array('success' => false);
        }
        $api_key = Configuration::get('PS_TWO_MERCHANT_API_KEY');
        if (Tools::isEmpty($api_key)) {
            return array('success' => false);
        }

        $response = $this->setTwoPaymentRequest(
            '/pricing/v1/merchant/rates',
            array(
                'buyer_country_code' => $this->getTwoAdminBuyerCountryCode(),
                // No admin recourse-pricing config exists (Magento parity:
                // its Fees controller hardcodes false too).
                'recourse_pricing' => false,
                'net_terms' => $net_terms,
            ),
            'POST',
            array(),
            // Render-path call: cap tight rather than block the admin page.
            self::API_TIMEOUT_STATE_CHECK
        );

        $http_status = (is_array($response) && isset($response['http_status'])) ? (int) $response['http_status'] : 0;
        if ($http_status !== self::HTTP_STATUS_OK || !isset($response['rates']) || !is_array($response['rates'])) {
            return array('success' => false);
        }

        $fees = array();
        foreach ($response['rates'] as $rate) {
            if (!is_array($rate) || !isset($rate['net_terms']) || !is_numeric($rate['net_terms'])) {
                continue;
            }
            $fee_days = (int) $rate['net_terms'];
            if ($fee_days <= 0) {
                continue;
            }
            $fees[(string) $fee_days] = array(
                // API sends numbers as strings - cast for JSON numeric output.
                'percentage' => isset($rate['percentage_fee']) && is_numeric($rate['percentage_fee']) ? (float) $rate['percentage_fee'] : 0.0,
                'fixed' => isset($rate['fixed_fee']) && is_numeric($rate['fixed_fee']) ? (float) $rate['fixed_fee'] : 0.0,
            );
        }

        return array(
            'success' => true,
            // Currency MUST come from the response - the fee amounts do too.
            // The JS appends it as a code suffix; an empty value makes the JS
            // drop the fixed component rather than guess its currency.
            'currency' => isset($response['currency']) ? (string) $response['currency'] : '',
            'fees' => $fees,
        );
    }

    /**
     * Buyer country stand-in for admin-side rate previews: the shop's default
     * country (PS_COUNTRY_DEFAULT resolved to ISO), since a config page has
     * no cart/buyer context. Falls back to 'NL' - the same stand-in
     * magento-plugin's Fees controller uses when no country is configured.
     *
     * @return string Two-letter uppercase ISO country code.
     */
    private function getTwoAdminBuyerCountryCode()
    {
        $id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        if ($id_country > 0) {
            $iso = Country::getIsoById($id_country);
            if (is_string($iso) && trim($iso) !== '') {
                return strtoupper(trim($iso));
            }
        }
        return 'NL';
    }

    /**
     * Admin AJAX entry point for the inline term-fee display. PrestaShop's
     * AdminController::postProcess() dispatches
     * index.php?controller=AdminModules&configure=twopayment&ajax=1&action=FetchMerchantFeeRates
     * to this module method (core dispatches ajax actions to the configured
     * module on the AdminModules controller; present in 1.7.x and 8.x). The
     * admin token is validated by the controller before postProcess runs.
     *
     * Reads a JSON-encoded `terms` array from the request and echoes the
     * normalised fee payload. Always responds 200 with {"success":false} on
     * failure - the JS blanks the fee spans silently (Magento parity).
     */
    public function ajaxProcessFetchMerchantFeeRates()
    {
        $raw = Tools::getValue('terms', '');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        $result = $this->fetchTwoMerchantFeeRates(is_array($decoded) ? $decoded : array());
        header('Content-Type: application/json');
        die(json_encode($result));
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
     * Request-scoped memo of the FX table read (false = not yet resolved this
     * request; null = unresolvable; array = the table). Configuration is
     * cheap but the JSON decode + validation is not free on hot render paths.
     *
     * @var false|null|array{base:string,as_of:string,rates:array<string,float>}
     */
    protected $twoFxRatesMemo = false;

    /**
     * Request-scoped memo of the categorised API-key verification verdict
     * (TWO-25326). null = not resolved yet this request. An instance property
     * rather than a static one on purpose: a static would carry one key's
     * verdict across every module instance by name, which is wrong the moment
     * two keys are in play (a specs run, a multistore save).
     *
     * @var null|array{status:string,code:int|null}
     */
    protected $twoApiKeyStatusMemo = null;

    /**
     * The categorised result of the API-key verification the general-form
     * VALIDATION performed, held for the SAVE to publish as the cached verdict
     * once the key it describes is actually stored (TWO-25326). Null when no
     * verification ran this request.
     *
     * @var null|array{status:string,code:int|null,body:array|null}
     */
    protected $verifiedApiKeyResult = null;

    /**
     * Whether this instance has already logged that it is withholding Two over
     * a verification failure (TWO-25326). PrestaShop asks for payment options
     * several times per payment-step render; the reason only needs saying once.
     *
     * @var bool
     */
    protected $twoApiKeyWithholdLogged = false;

    /**
     * Whether this instance has already logged that it is withholding Two over
     * the module's country allowlist (TWO-25387). Same once-per-request reason
     * as the flag above, and more load-bearing: a narrow allowlist is a
     * permanent merchant setting, so every out-of-allowlist buyer trips it on
     * every render rather than only while an outage lasts.
     *
     * @var bool
     */
    protected $twoCountryWithholdLogged = false;

    /**
     * Whether this instance has already reported that the country allowlist
     * could not be consulted (TWO-25387). Separate from the flag above because
     * it is a different event - "not enforced" rather than "enforced and
     * refused" - and it is the persistent case: a failing lookup fails on every
     * evaluation, so it floods harder than any withhold ever could.
     *
     * @var bool
     */
    protected $twoCountryLookupFailureLogged = false;

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
     * Resolve the per-brand order-intent APPROVED notice ON/OFF switch
     * (brands/two.php 'intent_approved_notice_enabled', TWO-25218) into the
     * boolean the checkout JS receives.
     *
     * A malformed declaration is reported and then treated as enabled - see
     * normalizeIntentApprovedNoticeEnabled() for why that is a log and not a
     * throw.
     *
     * @return bool
     */
    public function isIntentApprovedNoticeEnabled()
    {
        $error = null;
        $enabled = self::normalizeIntentApprovedNoticeEnabled(
            $this->getTwoBrandConfig('intent_approved_notice_enabled'),
            $error
        );

        if ($error !== null) {
            PrestaShopLogger::addLog($error, 3);
        }

        return $enabled;
    }

    /**
     * Normalize a brand 'intent_approved_notice_enabled' value into a bool.
     * Pure and static so it is testable without touching the `static` brand-file
     * cache inside getTwoBrandConfig() - same reason
     * normalizeIntentApprovedNotice() is split out this way.
     *
     *   true / false => that boolean. The switch is an explicit bool ONLY.
     *   null         => true. getTwoBrandConfig() returns null both for an
     *       absent key and for an explicit null, so the two are one input here;
     *       both mean the documented default, notice ON. Absent-means-ON is
     *       what keeps a third-party overlay that declares nothing on ON.
     *   anything else ('' , 0, 'yes', array, ...) => true, and $error is set to
     *       a message naming the key, the offending value's type and the brand
     *       code, for the caller to log.
     *
     * Deliberately NOT a throw. This resolves while rendering a buyer-facing
     * checkout, and a white screen is a worse failure than a notice that stays
     * on. Erring to ON is also the fail-safe direction: a brand that wanted it
     * off gets a visible, reported wrong state and nobody loses a sale.
     *
     * @param mixed $configured
     * @param string|null $error Out-param: null when the value was valid.
     * @param string $brandCode
     * @return bool
     */
    public static function normalizeIntentApprovedNoticeEnabled($configured, &$error = null, $brandCode = 'two')
    {
        $error = null;

        if ($configured === null) {
            return true;
        }

        if (is_bool($configured)) {
            return $configured;
        }

        $error = sprintf(
            'TwoPayment: brand "%s" declares intent_approved_notice_enabled as %s, but only a boolean is accepted.'
                . ' Falling back to the documented default (notice enabled). Fix brands/%s.php.',
            $brandCode,
            gettype($configured),
            $brandCode
        );

        return true;
    }

    /**
     * Resolve the per-brand order-intent APPROVED notice COPY OVERRIDE
     * (brands/two.php 'intent_approved_notice', TWO-25218) into the value the
     * checkout JS receives. This key no longer carries the on/off meaning it
     * had under TWO-25213 - see isIntentApprovedNoticeEnabled() for that.
     *
     * @return string|null
     */
    public function getIntentApprovedNotice()
    {
        return self::normalizeIntentApprovedNotice($this->getTwoBrandConfig('intent_approved_notice'));
    }

    /**
     * Normalize a brand 'intent_approved_notice' COPY value:
     *
     *   null (key absent, or any non-string) => null: platform default
     *       translated copy.
     *   '' or whitespace-only                => null: INERT. Under TWO-25213
     *       this suppressed the notice; it no longer does, and nothing about
     *       this key can turn the notice off. An overlay that still carries an
     *       empty string from the old contract resolves to the default copy
     *       with the notice ON - wrong for that brand, but not broken, and
     *       fixed by declaring intent_approved_notice_enabled => false.
     *   non-empty string                     => that string, used verbatim by
     *       the JS as the company-variant template (%s = company name).
     *
     * @param mixed $configured
     * @return string|null
     */
    public static function normalizeIntentApprovedNotice($configured)
    {
        if (!is_string($configured) || trim($configured) === '') {
            return null;
        }

        return $configured;
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
            // The cap is deliberately NOT cast unconditionally: an unset
            // Configuration key reads back as false and (float) false is 0.0,
            // which would make "no cap configured" indistinguishable from a
            // cap of zero — and those are different instructions to the
            // pricing API (absent = uncapped, 0 = bound the fee at zero).
            // Blank stays null; anything numeric, zero included, is a real
            // configured cap (TWO-25289). The save path stores '' for a blank
            // cell, so blank is the honest "unconfigured" signal.
            // A NEGATIVE stored cap is absent too. It is nonsense the admin
            // form rejects, so it can only arrive by a direct Configuration
            // write or an import - the same routes that justify relaying a
            // stored zero - and relaying it would be refused upstream anyway.
            $cap_raw = Configuration::get('PS_TWO_SURCHARGE_CAP_' . $days);
            $cap_trimmed = trim((string) $cap_raw);
            // is_numeric() is already false for false and null, so no
            // separate guards for them.
            $cap_set = is_numeric($cap_trimmed) && (float) $cap_trimmed >= 0;
            $grid[$days] = array(
                'percentage' => (float) Configuration::get('PS_TWO_SURCHARGE_PCT_' . $days),
                'fixed' => (float) Configuration::get('PS_TWO_SURCHARGE_FIXED_' . $days),
                'limit' => $cap_set ? (float) $cap_trimmed : null,
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
     * Re-denominate the currency-carrying members of a buyer_fee_share block
     * (fixed `surcharge`, `cap`) from the shop default currency they are
     * configured in into the quote currency, via Two's FX rates (TWO-25105).
     * Percentage members are currency-agnostic and pass through untouched.
     *
     * Returns null in exactly ONE case: no FX rate for the pair, so nothing
     * can be denominated at all. That is a FAIL-CLOSED signal (TWO-25269):
     * the caller omits the quote and the payment option is withheld, rather
     * than quoting a fixed fee denominated in the wrong currency, which is
     * what the old currency pinning silently did on multi-currency stores.
     *
     * Neither rounds-to-zero case is a failure (TWO-25276 - the earlier
     * zero-cap guard was reverted, its premise was wrong):
     *
     *  - A converted `cap` of 0.00 is passed straight through as a zero cap.
     *    The pricing service bounds the fee at zero for it: it tests the cap
     *    for PRESENCE rather than truthiness, and its own suite pins that, so
     *    a zero cap means the surcharge is simply not applied. It is NOT read
     *    as "no cap", and there is no overcharge to guard against. (Source
     *    references live on TWO-25269, not here: this repository is public
     *    and that service's is not.)
     *  - A fixed `surcharge` that converts to 0.00 is a legitimately tiny
     *    configured amount, genuinely negligible in a stronger currency, and
     *    0.00 is the arithmetically correct answer. Logged at info level.
     *
     * An ABSENT cap is a different thing again, and also never a failure - it
     * means an uncapped percentage surcharge, which continues to be charged.
     *
     * @param array $share buyer_fee_share block from TwoSurchargeCalculator
     * @param string $quote_currency_iso the currency the fee is quoted in
     * @param int|null $days term the block belongs to, for the failure log
     * @return array|null
     */
    public function convertTwoBuyerFeeShareCurrency(array $share, $quote_currency_iso, $days = null)
    {
        if (!isset($share['surcharge']) && !isset($share['cap'])) {
            return $share;
        }
        $quote_iso = Tools::strtoupper(trim((string) $quote_currency_iso));
        $shop_iso = $this->getTwoShopDefaultCurrencyIso();
        if ($quote_iso === '' || $shop_iso === '' || $quote_iso === $shop_iso) {
            return $share;
        }
        $pair = ($shop_iso ?: '?') . '->' . ($quote_iso ?: '?');
        $term = $days === null ? 'unspecified' : (int) $days;
        foreach (array('surcharge', 'cap') as $member) {
            if (!isset($share[$member])) {
                // An absent cap is uncapped-by-design, not a failure.
                continue;
            }
            // Zero needs no rate: it is zero in every currency. Skipping it
            // matters because this method FAILS CLOSED on a missing rate, and
            // a term whose only currency-bearing member is a zero cap would
            // otherwise take Two offline for every buyer on the shop over a
            // conversion that has no work to do (TWO-25289). That is the
            // TWO-25276 regression shape exactly, reached by a different
            // route: a stored zero cap is relayed rather than dropped now, so
            // it reaches here where it never used to.
            if ((float) $share[$member] === 0.0) {
                continue;
            }
            $configured = (float) $share[$member];
            $converted = $this->convertTwoAmountBetweenCurrencies($configured, $shop_iso, $quote_iso);
            if ($converted === null) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Buyer surcharge unquotable - no FX rate ' . $pair
                    . ' for the configured ' . $member . ' on term ' . $term . ' days; failing closed',
                    3
                );
                return null;
            }
            if ($member === 'surcharge' && $configured > 0 && round($converted, 2) <= 0.0) {
                // Legitimately negligible in a stronger currency - correct,
                // not a failure.
                PrestaShopLogger::addLog(
                    'TwoPayment: Configured fixed buyer surcharge ' . $this->getTwoRoundAmount($configured)
                    . ' ' . $shop_iso . ' rounds to 0.00 ' . $quote_iso . ' (' . $pair . ') on term ' . $term
                    . ' days; quoting 0.00, the surcharge is negligible in the cart currency',
                    1
                );
            }
            // Already 2dp: convertTwoAmountBetweenCurrencies() rounds once at
            // its own boundary, which is what the pricing API requires (it
            // refuses a finer value rather than rounding it). The
            // same-currency path never reaches here, so the configured value
            // is rounded in TwoSurchargeCalculator instead (TWO-25289).
            $share[$member] = $converted;
        }
        return $share;
    }

    /**
     * Whether a configured buyer surcharge can be denominated in the cart
     * currency at all (TWO-25269). False withholds the payment option.
     *
     * WHY THIS EXISTS. Before this gate, a store whose cart currency had no
     * FX rate against the shop default offered Two normally, the fee quote
     * came back null, applyTwoSurchargeCartLineSync removed the hidden
     * surcharge cart line as though the buyer had deselected it, and the
     * order was created with ZERO surcharge and nothing logged. A silent
     * undercharge on every affected order.
     *
     * THE CONDITION IS TERM-INDEPENDENT, deliberately. No term is selected
     * when payment options render, so the gate cannot ask "is the chosen
     * term quotable". It does not need to: the rate lookup for the
     * (shop default -> cart) pair fails identically for every term, so one
     * unresolvable pair condemns all of them. Gating instead on "any offered
     * term is unquotable" would over-reject a whole store because of one
     * misconfigured term.
     *
     *   surcharge enabled
     *   AND cart currency !== the currency the surcharge is configured in
     *   AND at least one offered term carries a fixed or cap component
     *   AND the rate is unresolvable
     *   -> withhold
     *
     * A percentage-only grid needs no conversion (percentages are
     * currency-agnostic), so it never trips the gate.
     *
     * The no-FX-rate condition is the ONLY thing this gate trips on. Because
     * it is term-independent, the loop below reaches the same answer on the
     * first currency-bearing term it sees; it is written as a loop only so it
     * can skip terms with no currency-bearing member at all. It must never
     * grow a per-term charge condition: any single term rejecting would take
     * the whole store offline for every buyer. (TWO-25276 removed exactly
     * such a condition - a per-term "configured cap rounds to 0.00" check -
     * which withheld Two from every buyer on affected shops. Its premise was
     * wrong: the pricing service bounds the fee at zero for a zero cap rather
     * than treating it as uncapped, so there was never an overcharge to guard.
     * References live on TWO-25269 - this repository is public and that
     * service's is not.)
     *
     * @param Cart $cart
     * @return bool
     */
    public function isTwoSurchargeQuotableForCart($cart)
    {
        $settings = $this->getTwoSurchargeSettings();
        if (empty($settings['enabled'])) {
            return true;
        }
        if (!Validate::isLoadedObject($cart)) {
            return true;
        }

        $shop_iso = $this->getTwoShopDefaultCurrencyIso();
        $cart_currency = new Currency((int) $cart->id_currency);
        $cart_iso = Validate::isLoadedObject($cart_currency)
            ? Tools::strtoupper(trim((string) $cart_currency->iso_code))
            : '';
        if ($cart_iso === '' || $shop_iso === '' || $cart_iso === $shop_iso) {
            // No re-denomination needed at all.
            return true;
        }

        foreach ($this->getAvailablePaymentTerms() as $days) {
            $days = (int) $days;
            $share = $this->buildTwoBuyerFeeShare($days);
            if ($share === null) {
                continue;
            }
            // Percentage-only terms carry no currency-bearing member.
            if (!isset($share['surcharge']) && !isset($share['cap'])) {
                continue;
            }
            if ($this->convertTwoBuyerFeeShareCurrency($share, $cart_iso, $days) === null) {
                // convertTwoBuyerFeeShareCurrency has already logged the
                // reason - no FX rate for the pair - at error level with the
                // currency pair and term.
                PrestaShopLogger::addLog(
                    'TwoPayment: Payment option hidden for cart ' . (int) $cart->id
                    . ' - buyer surcharge cannot be quoted in ' . $cart_iso
                    . ' (configured in ' . $shop_iso . '), failing closed rather than charging the wrong amount',
                    3
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Quote the buyer's fee share for one term via POST /v1/pricing/order/fee.
     * Returns null on any error. Request-scoped cache.
     *
     * A null is NOT fail-soft any more (TWO-25269 reverses the TWO-24752
     * contract). What null means now depends on the caller, and the two kinds
     * of caller are held apart deliberately:
     *
     *  - CHARGE paths - buildTwoSurchargeLineItemForCart, and through it the
     *    order payload builder and the hidden surcharge cart line - treat
     *    null as a failure. isTwoSurchargeQuotableForCart withholds the
     *    payment option up front for the whole-store case (no FX rate for the
     *    pair), and applyTwoSurchargeCartLineSync reports failure rather than
     *    removing the line, for anything that fails later.
     *  - DISPLAY paths - getTwoOfferedTermSurchargeAmounts' per-term chip
     *    previews - still degrade that one chip to no fee text. They show a
     *    number, they never decide one.
     *
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

        $sessionCached = $this->getTwoFeeQuoteFromSession($cacheKey);
        if ($sessionCached !== null) {
            return $this->twoFeeCache[$cacheKey] = $sessionCached;
        }

        $share = $this->buildTwoBuyerFeeShare($days);
        if ($share === null || $gross_amount <= 0) {
            return $this->twoFeeCache[$cacheKey] = null;
        }
        // Fixed amounts and caps are configured in the shop default currency;
        // re-denominate them into the quote currency via Two's FX rates
        // (TWO-25105 - replaces the previous single-currency-stores-only
        // pinning). No FX rate for the pair omits the quote (TWO-25269
        // fail-closed: the payment option is withheld) rather than sending a
        // figure in the wrong currency. A cap or fixed amount that merely
        // rounds to 0.00 passes through - it is the correct answer, not a
        // failure. convertTwoBuyerFeeShareCurrency logs the reason.
        $share = $this->convertTwoBuyerFeeShareCurrency($share, (string) $currency_iso, $days);
        if ($share === null) {
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

        // Guard against a reinterpreted currency — the quote must be
        // denominated in the currency it was requested in; a mismatch is an
        // API contract violation, not something to paper over with a second
        // conversion (the request's fixed figures were already FX-converted
        // into the quote currency, TWO-25105).
        $respCurrency = isset($response['currency']) ? (string) $response['currency'] : (string) $currency_iso;
        if ($currency_iso !== '' && $respCurrency !== (string) $currency_iso) {
            return $this->twoFeeCache[$cacheKey] = null;
        }

        $quote = array(
            'buyer_fee_share' => (string) $response['buyer_fee_share'],
            'total_fee_tax_rate' => isset($response['total_fee_tax_rate']) ? (string) $response['total_fee_tax_rate'] : null,
            'currency' => $respCurrency,
        );
        $this->storeTwoFeeQuoteInSession($cacheKey, $quote);

        return $this->twoFeeCache[$cacheKey] = $quote;
    }

    /**
     * Read a cross-request-cached fee quote from the session cookie, honouring
     * FEE_QUOTE_CACHE_TTL_SECONDS and requiring an exact signature match
     * (days|gross|country|currency) — any change in cart total, term, buyer
     * country or currency invalidates the cache immediately regardless of TTL.
     * Fail-soft: any malformed/missing cache data is treated as a miss.
     *
     * @param string $cacheKey
     * @return array|null
     */
    private function getTwoFeeQuoteFromSession($cacheKey)
    {
        if (!isset($this->context->cookie)) {
            return null;
        }
        $cachedKey = isset($this->context->cookie->two_fee_quote_key) ? (string) $this->context->cookie->two_fee_quote_key : '';
        if ($cachedKey === '' || $cachedKey !== $cacheKey) {
            return null;
        }
        $cachedTs = isset($this->context->cookie->two_fee_quote_ts) ? (int) $this->context->cookie->two_fee_quote_ts : 0;
        if ($cachedTs <= 0 || (time() - $cachedTs) > self::FEE_QUOTE_CACHE_TTL_SECONDS) {
            return null;
        }
        $cachedData = isset($this->context->cookie->two_fee_quote_data) ? (string) $this->context->cookie->two_fee_quote_data : '';
        if ($cachedData === '') {
            return null;
        }
        $decoded = json_decode($cachedData, true);
        if (!is_array($decoded) || !isset($decoded['buyer_fee_share'])) {
            return null;
        }

        return array(
            'buyer_fee_share' => (string) $decoded['buyer_fee_share'],
            'total_fee_tax_rate' => isset($decoded['total_fee_tax_rate']) ? $decoded['total_fee_tax_rate'] : null,
            'currency' => isset($decoded['currency']) ? (string) $decoded['currency'] : '',
        );
    }

    /**
     * Persist a freshly-fetched fee quote to the session cookie for reuse by
     * subsequent requests within FEE_QUOTE_CACHE_TTL_SECONDS (e.g. repeat
     * order-intent polls on the Payment step). Best-effort: failures to write
     * the cookie never block the quote itself being returned to the caller.
     *
     * @param string $cacheKey
     * @param array  $quote
     * @return void
     */
    private function storeTwoFeeQuoteInSession($cacheKey, array $quote)
    {
        if (!isset($this->context->cookie)) {
            return;
        }
        try {
            // Deliberately do NOT call $this->context->cookie->setExpire() here:
            // PrestaShop's cookie has a single expiry for the whole cookie (not
            // per-key), and other code paths rely on it being COOKIE_EXPIRY_ONE_HOUR
            // (company verification cache, rate limiting, order-intent-approved
            // flag). Shortening it to this cache's TTL would silently truncate
            // those. Staleness here is bounded instead by the two_fee_quote_ts
            // field checked in getTwoFeeQuoteFromSession().
            $this->context->cookie->two_fee_quote_key = $cacheKey;
            $this->context->cookie->two_fee_quote_data = json_encode($quote);
            $this->context->cookie->two_fee_quote_ts = (string) time();

            // AJAX controllers (e.g. order-intent polling in orderintent.php's
            // ajaxProcessCheckOrderIntent()) end the request via ajaxDie()/exit
            // rather than returning normally, which is not guaranteed to run
            // PrestaShop's Cookie::__destruct() in every PHP/webserver
            // configuration. Force an immediate write so the quote is durably
            // cached rather than relying on destructor timing (precedent:
            // getTwoValidatedSessionCompanyData() above does the same).
            if (method_exists($this->context->cookie, 'write')) {
                $this->context->cookie->write();
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog('TwoPayment: Failed to cache fee quote in session - ' . $e->getMessage(), 2);
        }
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
    /**
     * Merchant-selected TaxRulesGroup id for the hidden surcharge product
     * (CONFIG_SURCHARGE_TAX_RULES_GROUP). 0 - also the unset/blank/garbage
     * fallback - is PrestaShop's first-class "No tax" sentinel: fail-safe is
     * an untaxed fee, never an invented rate. TWO-25071.
     *
     * @return int
     */
    public function getTwoSurchargeTaxRulesGroupId()
    {
        $raw = Configuration::get(self::CONFIG_SURCHARGE_TAX_RULES_GROUP);
        if ($raw === false || $raw === null || !is_numeric($raw)) {
            return 0;
        }

        return max(0, (int) $raw);
    }

    /**
     * THE decision point for "does this surcharge tax treatment leave the fee
     * untaxed everywhere?" (TWO-25279).
     *
     * Four places need that answer and they MUST agree exactly:
     *  - getTwoSurchargeTaxRulesGroupOptions(), which omits such a treatment
     *    from the dropdown;
     *  - validTwoSurchargeFormValues(), which refuses the save;
     *  - saveTwoSurchargeFormValues(), which refuses to persist it even on
     *    the surcharges-disabled path where validation does not run;
     *  - getTwoSurchargeNeverTaxedNotice(), which fails loud when a shop is
     *    already sitting on one.
     *
     * A shop that is warned but can still save, or blocked but never told
     * why, would each be worse than either alone - so the rule lives here
     * once instead of being restated four times.
     *
     * One shape is never-taxed on PrestaShop: the core "No tax" sentinel, tax
     * rules group pseudo-id 0. The test is deliberately the same
     * normalisation getTwoSurchargeTaxRulesGroupId() applies at checkout -
     * numeric, then int-cast and floored at 0 - rather than a string
     * comparison against '0'. That makes the answer here true exactly when
     * the checkout would in fact leave the fee untaxed, so '0', ' 0 ', '0.0'
     * and '-5' are all caught. A direct DB edit or an import can produce any
     * of those shapes.
     *
     * Unset, blank and non-numeric are NOT never-taxed: those read as
     * "unselected", a different state with its own message
     * (validTwoSurchargeFormValues asks the merchant to choose). Reporting
     * them here would put the wrong error on the page.
     *
     * @param mixed $value stored or submitted treatment value
     * @return bool
     */
    public function isTwoSurchargeNeverTaxedTreatment($value)
    {
        if (!is_scalar($value) || is_bool($value)) {
            return false;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return false;
        }

        return max(0, (int) $trimmed) === 0;
    }

    /**
     * Fail-loud error text for a shop whose STORED surcharge tax treatment is
     * a never-taxed one, or '' when there is nothing to report (TWO-25279).
     *
     * There is deliberately no grandfathering and no silent rewrite of the
     * merchant's tax configuration, which leaves exactly one case to handle
     * honestly: a shop configured before this change, or written to from
     * outside this module, that still stores the "No tax" sentinel. Doing
     * nothing would be the worst option - the select cannot render a value
     * absent from its options, so it falls back to the placeholder and the
     * field simply looks unset, giving no hint that the fee is currently
     * being charged untaxed.
     *
     * Rendered unconditionally, not only while surcharges are enabled: the
     * treatment is wrong either way, and a shop that re-enables surcharges
     * must not discover it then.
     *
     * @return string
     */
    public function getTwoSurchargeNeverTaxedNotice()
    {
        if (!$this->isTwoSurchargeNeverTaxedTreatment(
            Configuration::get(self::CONFIG_SURCHARGE_TAX_RULES_GROUP)
        )) {
            return '';
        }

        return $this->l('Surcharge tax treatment is invalid: this shop is set to leave the surcharge UNTAXED in every country. That treatment is no longer available and these settings can no longer be saved while it is stored. Under Payment settings, select a tax rules group - to leave the fee untaxed, create a group with a 0% rate and select that.');
    }

    /**
     * Post-upgrade "surcharge tax needs re-selection" warning text, or ''
     * when no warning is due. Due when upgrade-2.5.0.php flagged a shop that
     * had the pre-release flat rate (PS_TWO_SURCHARGE_TAX_RATE) configured
     * and no TaxRulesGroup has been saved since: the surcharge is silently
     * untaxed until the merchant picks a group. Self-retires if a selection
     * exists (covers saves made outside this module's own form path).
     *
     * @return string
     */
    public function getTwoSurchargeTaxMigrationNotice()
    {
        if (!Configuration::get(self::CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE)) {
            return '';
        }
        $stored = Configuration::get(self::CONFIG_SURCHARGE_TAX_RULES_GROUP);
        if ($stored !== false && $stored !== null && trim((string) $stored) !== '') {
            Configuration::updateValue(self::CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE, '');

            return '';
        }

        return $this->l('Surcharge tax needs re-selection: this shop previously used a flat surcharge tax rate, which has been replaced by a tax rules group. Until you select and save a "Surcharge Tax Treatment" under Payment settings, the surcharge is NOT taxed.');
    }

    /**
     * Effective tax rate (decimal FRACTION, e.g. 0.25) the selected
     * TaxRulesGroup applies to the surcharge for THIS cart's tax destination
     * - resolved through the SAME core machinery PrestaShop's own cart
     * pricing uses for the hidden fee product (Product::priceCalculation):
     * TaxManagerFactory over the cart's PS_TAX_ADDRESS_TYPE address, with
     * the same shop-wide gates (PS_TAX off, vatnumber-module B2B exemption).
     * Using one resolution for both the PS cart line and the Two payload is
     * what makes the PR #64 parity gate unable to trip on destination-based
     * rates. Empirically verified on PS 8.2.6 core: no matching rule for the
     * destination -> 0 (zero-rating for free), combined multi-rate rules sum
     * (6%+2% -> 8), group id 0 -> 0 everywhere.
     *
     * @param Cart $cart
     * @return float
     */
    public function getTwoSurchargeTaxRateForCart($cart)
    {
        $groupId = $this->getTwoSurchargeTaxRulesGroupId();
        if ($groupId <= 0 || !Validate::isLoadedObject($cart)) {
            return 0.0;
        }

        // Shop-wide "disable taxes" switch (core: Tax::excludeTaxeOption
        // inside Product::priceCalculation zeroes every product's tax).
        if (class_exists('Tax') && method_exists('Tax', 'excludeTaxeOption')) {
            if (Tax::excludeTaxeOption()) {
                return 0.0;
            }
        } elseif (!Configuration::get('PS_TAX')) {
            return 0.0;
        }

        $taxAddressField = (string) Configuration::get('PS_TAX_ADDRESS_TYPE');
        if ($taxAddressField !== 'id_address_delivery') {
            $taxAddressField = 'id_address_invoice';
        }
        $address = new Address((int) $cart->{$taxAddressField});
        if (!Validate::isLoadedObject($address)) {
            // No usable tax destination. Cannot legitimately happen at the
            // payment step (PS requires addresses first); if it ever does,
            // the payload carries no tax and the fail-closed parity gate /
            // post-write sync verification blocks any divergence.
            return 0.0;
        }

        // vatnumber-module B2B exemption - the exact condition core's
        // Product::priceCalculation flips usetax off with.
        if (
            !empty($address->vat_number)
            && (int) $address->id_country !== (int) Configuration::get('VATNUMBER_COUNTRY')
            && Configuration::get('VATNUMBER_MANAGEMENT')
        ) {
            return 0.0;
        }

        $calculator = TaxManagerFactory::getManager($address, $groupId)->getTaxCalculator();

        return max(0.0, (float) $calculator->getTotalRate()) / 100.0;
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
     * The line's tax_rate is the merchant-selected TaxRulesGroup's effective
     * rate for THIS cart's tax destination (getTwoSurchargeTaxRateForCart -
     * the same core resolution PrestaShop applies to the hidden fee cart
     * line, so the two sides cannot drift) — NOT the pricing-preview
     * response's total_fee_tax_rate, which the plugin never populates in its
     * own /v1/pricing/order/fee request and is therefore always empty; net/
     * tax/gross satisfy the Two line-item formulas so validateTwoLineItems
     * accepts it.
     *
     * @param Cart     $cart
     * @param float    $gross_basis product + shipping gross (fee basis)
     * @param int|null $paymentTermDays explicit term (update/admin context has no
     *                 buyer cookie); null falls back to the selected term.
     * @param bool|null $quoteUnavailable out-param (TWO-25269): true when the
     *                 null is an unavailable fee QUOTE rather than an absent
     *                 or genuinely zero surcharge. Callers that CHARGE must
     *                 distinguish the two - see applyTwoSurchargeCartLineSync.
     * @return array|null
     */
    public function buildTwoSurchargeLineItemForCart($cart, $gross_basis, $paymentTermDays = null, &$quoteUnavailable = null)
    {
        $quoteUnavailable = false;
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
            // TWO-25269: tell the caller this null is an unavailable QUOTE,
            // not an absent surcharge. applyTwoSurchargeCartLineSync must not
            // read it as the buyer deselecting.
            $quoteUnavailable = true;
            return null;
        }

        $net = round((float) $fee['buyer_fee_share'], 2);
        if ($net <= 0) {
            // A quoted zero (or a fixed amount that converted to 0.00) is a
            // real answer, not a failure: no line to add.
            return null;
        }
        // The rate is the selected TaxRulesGroup's destination-resolved rate
        // (getTwoSurchargeTaxRateForCart) — the pricing-preview response's
        // total_fee_tax_rate is never populated (the plugin sends no
        // tax-rate field in its own fee request), so it is NOT a usable
        // source. Compute tax from the SAME rate string that
        // is sent (formatTwoTaxRate snaps to TAX_RATE_PRECISION dp). Using
        // the full-precision rate to compute tax while sending a rounded rate
        // makes the line fail validateTwoLineItems (tax_amount vs
        // net*sent_rate) for any rate that needs more than TAX_RATE_PRECISION
        // decimals, silently dropping the whole surcharge line. Snapping
        // first mirrors the product-line convention (snapped_product_rate).
        // TWO-24752.
        $taxRate = $this->getTwoSurchargeTaxRateForCart($cart);
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
     * Live per-term buyer surcharge amounts for the checkout term chips: the
     * REAL quoted fee (buyer_fee_share net, via POST /v1/pricing/order/fee per
     * offered term through fetchTwoTermFee) for the CURRENT cart, replacing
     * each chip's loading indicator when it resolves (the buyer is never
     * shown the configured rate). Mirrors magento-plugin's
     * Model/Webapi/Surcharges.php: basis
     * from the live cart, loop over every offered term, per-term failure
     * degrades that term to 0.0 while the others keep their quotes.
     *
     * Fail-soft contract (same discipline as fetchTwoMerchantFeeRates), and
     * deliberately RETAINED through TWO-25269's fail-closed sweep: these are
     * DISPLAY previews, not a charge. They show a number, they never decide
     * one, so a failure degrades the affected chip instead of removing the
     * payment option. Matches magento-plugin, whose preview paths also
     * degrade while only the authoritative total path fails closed. The
     * charge-deciding paths (isTwoSurchargeQuotableForCart,
     * applyTwoSurchargeCartLineSync) are where the posture flipped.
     *
     * This method must never throw and never
     * break checkout. {success:false} — surcharge disabled, no loaded cart,
     * no offered terms, or a zero/empty cart basis — tells the JS to clear
     * the chips' loading indicators (no fee text is shown; the buyer is never
     * shown a configured rate). A nonzero basis where every term's quote fails
     * still returns {success:true} with all-zero amounts (per-term degrade,
     * Magento parity), NOT {success:false}.
     *
     * @return array{success:bool,currency?:string,amounts?:array<int,float>}
     */
    public function getTwoOfferedTermSurchargeAmounts()
    {
        try {
            $settings = $this->getTwoSurchargeSettings();
            if (empty($settings['enabled'])) {
                return array('success' => false);
            }

            $cart = isset($this->context->cart) ? $this->context->cart : null;
            if (!Validate::isLoadedObject($cart)) {
                return array('success' => false);
            }

            $terms = $this->getAvailablePaymentTerms();
            if (empty($terms)) {
                return array('success' => false);
            }

            // Lightweight cart-gross read (products + shipping, tax incl.) —
            // the same idiom the order builder uses for its reconciliation
            // gross (getTwoNewOrderData) — NOT the full line-item pipeline,
            // which is far too heavy for a render-time preview call.
            $gross_basis = round((float) $cart->getOrderTotal(true, Cart::BOTH), 2);
            // The hidden surcharge line must never feed its own basis: quote
            // every term against the merchandise+shipping total only.
            $surchargeCartLine = $this->getTwoSurchargeCartLine($cart);
            if ($surchargeCartLine !== null) {
                $gross_basis = round($gross_basis - $surchargeCartLine['gross'], 2);
            }
            if ($gross_basis <= 0) {
                // Empty cart / anonymous probe: nothing to quote against —
                // the JS clears the chips' loading indicators to blank.
                return array('success' => false);
            }

            $currencyIso = '';
            $currency = new Currency((int) $cart->id_currency);
            if (Validate::isLoadedObject($currency)) {
                $currencyIso = (string) $currency->iso_code;
            }
            $buyerCountry = $this->resolveTwoBuyerCountryIso($cart);

            $amounts = array();
            foreach ($terms as $days) {
                $days = (int) $days;
                $fee = $this->fetchTwoTermFee($days, $gross_basis, $buyerCountry, $currencyIso);
                // Per-term degrade: a failed quote zeroes THAT chip's fee
                // (the JS hides a zero fee) without failing the whole map.
                $amounts[$days] = ($fee !== null && isset($fee['buyer_fee_share']))
                    ? round((float) $fee['buyer_fee_share'], 2)
                    : 0.0;
            }

            return array(
                'success' => true,
                'currency' => $currencyIso,
                'amounts' => $amounts,
            );
        } catch (\Throwable $e) {
            // Checkout render must never break on a preview quote.
            return array('success' => false);
        }
    }

    /* ------------------------------------------------------------------ *
     *  Surcharge as a REAL PrestaShop cart line (hidden virtual product)  *
     * ------------------------------------------------------------------ */

    /**
     * Id of the hidden virtual product mirroring the Two buyer surcharge in
     * the PrestaShop cart. Lazily created on first use (more robust than
     * install-time creation: survives DB restores, module upgrades from
     * versions without the product, and accidental catalog deletion). The
     * stored id is cross-checked against the product reference so a recycled
     * id can never silently point at a different catalog product.
     *
     * @param bool $createIfMissing
     * @return int 0 when missing and creation was not requested / failed
     */
    public function getTwoSurchargeCartProductId($createIfMissing = false)
    {
        $productId = (int) Configuration::get(self::CONFIG_SURCHARGE_PRODUCT_ID);
        if ($productId > 0) {
            $product = new Product($productId);
            if (
                Validate::isLoadedObject($product)
                && isset($product->reference)
                && (string) $product->reference === self::TWO_SURCHARGE_PRODUCT_REFERENCE
            ) {
                return $productId;
            }
            // Stored id no longer points at OUR product. If the object still
            // exists AND carries our hidden-fee shape (virtual + invisible -
            // e.g. its reference was edited in the BO), delete it best-effort
            // so it is not orphaned forever behind its replacement. A
            // recycled id pointing at a real catalog product will not match
            // the shape and is never touched.
            if (
                Validate::isLoadedObject($product)
                && (int) $product->is_virtual === 1
                && (string) $product->visibility === 'none'
            ) {
                try {
                    if (method_exists($product, 'delete')) {
                        $product->delete();
                        PrestaShopLogger::addLog('TwoPayment: Deleted stale surcharge product ' . $productId . ' (reference mismatch)', 2);
                    }
                } catch (Exception $e) {
                    PrestaShopLogger::addLog('TwoPayment: Failed deleting stale surcharge product ' . $productId . ' - ' . $e->getMessage(), 2);
                }
            }
            $productId = 0;
        }

        if (!$createIfMissing) {
            return 0;
        }

        // Advisory lock: two concurrent FIRST-EVER requests (fresh install,
        // two buyers selecting Two near-simultaneously) would otherwise each
        // pass the read-check above and create a duplicate hidden product,
        // orphaning the loser's copy forever.
        if (!$this->acquireTwoDbLock('two_surcharge_product_create')) {
            PrestaShopLogger::addLog('TwoPayment: Surcharge product creation lock not acquired', 2);
            return 0;
        }
        try {
            // Double-check UNDER the lock, straight from the DB: the winner
            // of the race wrote Configuration in ITS request, which this
            // request's per-request Configuration cache cannot see.
            $storedId = (int) Db::getInstance()->getValue(
                'SELECT `value` FROM `' . _DB_PREFIX_ . "configuration` WHERE `name` = '" . pSQL(self::CONFIG_SURCHARGE_PRODUCT_ID) . "'"
            );
            if ($storedId > 0) {
                $product = new Product($storedId);
                if (
                    Validate::isLoadedObject($product)
                    && isset($product->reference)
                    && (string) $product->reference === self::TWO_SURCHARGE_PRODUCT_REFERENCE
                ) {
                    return $storedId;
                }
            }

            try {
                $productId = $this->createTwoSurchargeCartProduct();
            } catch (Exception $e) {
                PrestaShopLogger::addLog('TwoPayment: Failed creating surcharge cart product - ' . $e->getMessage(), 3);
                return 0;
            }
            if ($productId > 0) {
                Configuration::updateValue(self::CONFIG_SURCHARGE_PRODUCT_ID, $productId);
            }

            return $productId;
        } finally {
            $this->releaseTwoDbLock('two_surcharge_product_create');
        }
    }

    /**
     * Create the hidden virtual surcharge product. Empirically verified
     * pattern on PS8 core: visibility 'none' keeps it out of catalog, search
     * and listings; indexed=0 keeps it out of the search index; is_virtual=1
     * bypasses all shipping/carrier logic; active=1 + available_for_order=1
     * + out_of_stock=1 (+ large stock) are REQUIRED for Cart::updateQty and
     * Cart::checkQuantities to accept it at order time.
     *
     * The catalog product name is term-agnostic (a shared catalog object
     * cannot carry per-buyer term days; concurrent carts may hold different
     * terms). The Two-side payload line keeps the term-specific label from
     * getTwoSurchargeLineLabel; amounts - not labels - are the parity
     * contract between the two lines.
     *
     * @return int new product id (0 on failure)
     */
    protected function createTwoSurchargeCartProduct()
    {
        $label = $this->getTwoBrandConfig('fee_line_label');
        $label = !empty($label) ? $this->l((string) $label) : $this->l('Payment terms fee');

        $languageIds = array();
        foreach ((array) Language::getLanguages(false) as $lang) {
            if (isset($lang['id_lang'])) {
                $languageIds[] = (int) $lang['id_lang'];
            }
        }
        if (empty($languageIds)) {
            $languageIds[] = isset($this->context->language->id) ? (int) $this->context->language->id : 1;
        }

        $product = new Product();
        $product->name = array();
        $product->link_rewrite = array();
        foreach ($languageIds as $idLang) {
            $product->name[$idLang] = $label;
            $product->link_rewrite[$idLang] = 'two-payment-terms-fee';
        }
        $product->reference = self::TWO_SURCHARGE_PRODUCT_REFERENCE;
        $product->price = 0;
        $product->id_tax_rules_group = $this->getTwoSurchargeTaxRulesGroupId();
        $product->active = 1;
        $product->available_for_order = 1;
        $product->visibility = 'none';
        $product->indexed = 0;
        $product->is_virtual = 1;
        $product->out_of_stock = 1; // allow orders regardless of stock
        $product->minimal_quantity = 1;
        $product->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');

        if (!$product->add()) {
            return 0;
        }

        if (class_exists('StockAvailable')) {
            StockAvailable::setQuantity((int) $product->id, 0, 1000000);
            if (method_exists('StockAvailable', 'setProductOutOfStock')) {
                StockAvailable::setProductOutOfStock((int) $product->id, 1);
            }
        }

        PrestaShopLogger::addLog('TwoPayment: Created hidden surcharge cart product ' . (int) $product->id, 1);

        return (int) $product->id;
    }

    /**
     * Ensure the hidden surcharge product carries the merchant-selected
     * TaxRulesGroup (CONFIG_SURCHARGE_TAX_RULES_GROUP) - the exact same
     * id_tax_rules_group assignment PrestaShop's own product-edit page
     * makes, so core resolves the fee line's tax natively (per-destination
     * rules, stacking, "No tax" id 0). Idempotent single-row self-heal: a
     * merchant re-pointing or clearing the selection is picked up on the
     * next cart sync. No synthetic Tax/TaxRulesGroup/TaxRule objects are
     * created any more (TWO-25071); if the merchant deletes the selected
     * group in the BO, core resolves 0 for every destination on BOTH the
     * cart line and the Two payload (same resolution path), so parity holds.
     *
     * @param int $productId
     * @return bool false when the product could not be loaded/updated
     */
    public function ensureTwoSurchargeProductTaxRulesGroup($productId)
    {
        $product = new Product((int) $productId);
        if (!Validate::isLoadedObject($product)) {
            return false;
        }

        $groupId = $this->getTwoSurchargeTaxRulesGroupId();
        if ((int) $product->id_tax_rules_group !== $groupId) {
            $product->id_tax_rules_group = $groupId;
            if (!$product->update()) {
                return false;
            }
            $this->flushTwoProductTaxRulesGroupCache((int) $product->id);
        }

        return true;
    }

    /**
     * Invalidate core's per-request tax-rules-group cache for one product.
     *
     * EMPIRICALLY REQUIRED (verified live on PS8 core): cart pricing resolves
     * the product's group via Product::getIdTaxRulesGroupByIdProduct, which
     * memoises 'product_id_tax_rules_group_{id}_{shop}' in Cache. The value
     * gets primed with 0 during product creation, and Product::update does
     * NOT clean it - so the very first request that re-points the product's
     * tax rules group would price the fee line WITHOUT tax (gross == net)
     * while the Two payload carries tax, and the stale line would then never self-correct
     * (the sync no-op check compares net, which matches). Cleaning the exact
     * key here makes the first request price correctly.
     *
     * @param int $productId
     */
    protected function flushTwoProductTaxRulesGroupCache($productId)
    {
        if (!class_exists('Cache') || !method_exists('Cache', 'clean')) {
            return;
        }
        $shopId = isset($this->context->shop->id) ? (int) $this->context->shop->id : 0;
        Cache::clean('product_id_tax_rules_group_' . (int) $productId . '_' . $shopId);
        if ($shopId !== 0) {
            // Defensive: some call paths resolve with a null/default shop.
            Cache::clean('product_id_tax_rules_group_' . (int) $productId . '_0');
        }
    }

    /**
     * MySQL advisory lock (GET_LOCK). Used to serialise the surcharge
     * check-then-create/check-then-act sequences across concurrent requests.
     * NOTE: paths below may hold two differently-named locks at once; that
     * requires MySQL >= 5.7.5 or MariaDB >= 10.0.2 (older servers silently
     * release the first lock on the second GET_LOCK) - both far below any
     * realistic PrestaShop 8 database floor.
     *
     * @param string $name
     * @param int $timeoutSeconds
     * @return bool
     */
    protected function acquireTwoDbLock($name, $timeoutSeconds = 5)
    {
        try {
            return (string) Db::getInstance()->getValue(
                "SELECT GET_LOCK('" . pSQL($name) . "', " . (int) $timeoutSeconds . ')'
            ) === '1';
        } catch (Exception $e) {
            PrestaShopLogger::addLog('TwoPayment: GET_LOCK failed for ' . $name . ' - ' . $e->getMessage(), 3);
            return false;
        }
    }

    /**
     * Release a lock taken with acquireTwoDbLock. Callers use try/finally so
     * the lock is released even when the guarded section throws.
     *
     * @param string $name
     */
    protected function releaseTwoDbLock($name)
    {
        try {
            Db::getInstance()->getValue("SELECT RELEASE_LOCK('" . pSQL($name) . "')");
        } catch (Exception $e) {
            // Session teardown releases the lock anyway; log only.
            PrestaShopLogger::addLog('TwoPayment: RELEASE_LOCK failed for ' . $name . ' - ' . $e->getMessage(), 2);
        }
    }

    /**
     * Lazily create the per-cart surcharge sync-sequence table. Also created
     * at install; the lazy path covers module upgrades from versions without
     * it (install() does not re-run on upgrade).
     */
    protected function ensureTwoSurchargeSyncTable()
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'twopayment_surcharge_sync` (
                `id_cart` INT(11) UNSIGNED NOT NULL,
                `seq` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `updated_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_cart`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8'
        );
        $ensured = true;
    }

    /**
     * Name of the order_detail fee-guard trigger (prefixed like the module's
     * tables so several shops sharing one database never collide).
     *
     * @return string
     */
    protected function getTwoFeeGuardTriggerName()
    {
        return _DB_PREFIX_ . 'twopayment_fee_guard';
    }

    /**
     * DDL for the DB-level fee-row guard. This trigger - not the
     * actionObjectOrderDetailAddBefore hook - is the actual enforcement
     * layer: Hook::callHookOn() swallows module exceptions unless the shop
     * runs in debug mode (verified against PS8 core), so a thrown
     * PrestaShopException protects nothing in a production shop. A BEFORE
     * INSERT trigger holds on EVERY insertion path (front order creation,
     * back-office AddProductToOrderHandler, webservice order_details
     * resource, direct SQL).
     *
     * Rules enforced for the hidden fee product (looked up LIVE from the
     * configuration table, so recreating the product never requires
     * recreating the trigger):
     *  1. at most ONE fee row per order, ever - the single legitimate row
     *     is written by PaymentModule::validateOrder at order creation
     *     (Order::add() precedes OrderDetail::createList(), verified against
     *     PS8 core), and a fee line is never edited or re-added afterwards;
     *  2. a fee row is only accepted for an order whose originating CART
     *     actually carries the fee line - which is true for every genuine
     *     Two order at creation time and false for a webservice/back-office
     *     caller grafting the fee onto an arbitrary existing order.
     *
     * Empirically verified on the live PS8 container (MariaDB 10.11):
     * legitimate first insert passes, duplicate insert and fee-less-cart
     * insert are rejected with SIGNAL 45000, ordinary products unaffected.
     * SIGNAL requires MySQL >= 5.5 / MariaDB >= 5.5 - far below any PS8
     * platform floor.
     *
     * @return string
     */
    protected function buildTwoFeeGuardTriggerSql()
    {
        return 'CREATE TRIGGER `' . $this->getTwoFeeGuardTriggerName() . '`
            BEFORE INSERT ON `' . _DB_PREFIX_ . 'order_detail`
            FOR EACH ROW
            BEGIN
                IF NEW.product_id > 0
                    AND EXISTS (
                        SELECT 1 FROM `' . _DB_PREFIX_ . 'configuration`
                        WHERE `name` = \'' . pSQL(self::CONFIG_SURCHARGE_PRODUCT_ID) . '\'
                          AND CAST(`value` AS UNSIGNED) = NEW.product_id
                    )
                THEN
                    IF EXISTS (
                        SELECT 1 FROM `' . _DB_PREFIX_ . 'order_detail`
                        WHERE `id_order` = NEW.id_order
                          AND `product_id` = NEW.product_id
                    ) THEN
                        SIGNAL SQLSTATE \'45000\'
                            SET MESSAGE_TEXT = \'TwoPayment: rejected duplicate payment terms fee row (fee is added once, at order creation only)\';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1
                        FROM `' . _DB_PREFIX_ . 'orders` o
                        INNER JOIN `' . _DB_PREFIX_ . 'cart_product` cp
                            ON cp.`id_cart` = o.`id_cart`
                           AND cp.`id_product` = NEW.product_id
                        WHERE o.`id_order` = NEW.id_order
                    ) THEN
                        SIGNAL SQLSTATE \'45000\'
                            SET MESSAGE_TEXT = \'TwoPayment: rejected payment terms fee row for an order whose cart does not carry the fee line\';
                    END IF;
                END IF;
            END';
    }

    /**
     * Create the fee-guard trigger if absent. Best-effort by design: a DB
     * user without the TRIGGER privilege (or a pathologically old server)
     * must not break install or checkout - the failure is logged loudly and
     * the hook-based guard remains as the (debug-mode-only) fallback.
     *
     * @return bool trigger present after the call
     */
    public function installTwoOrderDetailFeeGuardTrigger()
    {
        try {
            $exists = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM information_schema.TRIGGERS' .
                " WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = '" . pSQL($this->getTwoFeeGuardTriggerName()) . "'"
            );
            if ($exists > 0) {
                return true;
            }
            if (Db::getInstance()->execute($this->buildTwoFeeGuardTriggerSql())) {
                PrestaShopLogger::addLog('TwoPayment: Installed order_detail fee-guard trigger ' . $this->getTwoFeeGuardTriggerName(), 1);
                return true;
            }
            PrestaShopLogger::addLog('TwoPayment: Could not create fee-guard trigger ' . $this->getTwoFeeGuardTriggerName() . ' - duplicate fee rows are NOT DB-enforced (TRIGGER privilege missing?)', 3);
            return false;
        } catch (Exception $e) {
            PrestaShopLogger::addLog('TwoPayment: Fee-guard trigger creation failed - duplicate fee rows are NOT DB-enforced. ' . $e->getMessage(), 3);
            return false;
        }
    }

    /**
     * Lazily (re)install the fee-guard trigger once per request. Covers
     * module upgrades from versions without it (install() does not re-run on
     * upgrade), DB restores that dropped triggers, and manual drops -
     * self-healing on the next checkout instead of trusting a stale flag.
     */
    protected function ensureTwoOrderDetailFeeGuardTrigger()
    {
        if ($this->twoFeeGuardTriggerEnsured) {
            return;
        }
        $this->twoFeeGuardTriggerEnsured = true;
        $this->installTwoOrderDetailFeeGuardTrigger();
    }

    /** @var bool once-per-request memo for ensureTwoOrderDetailFeeGuardTrigger */
    protected $twoFeeGuardTriggerEnsured = false;

    /**
     * Best-effort trigger drop at uninstall - never blocks uninstall.
     */
    protected function dropTwoOrderDetailFeeGuardTrigger()
    {
        try {
            Db::getInstance()->execute('DROP TRIGGER IF EXISTS `' . $this->getTwoFeeGuardTriggerName() . '`');
        } catch (Exception $e) {
            PrestaShopLogger::addLog('TwoPayment: Failed dropping fee-guard trigger at uninstall - ' . $e->getMessage(), 2);
        }
    }

    /**
     * Last-applied buyer-driven sync sequence for a cart, or null when the
     * cart has never synced with a sequence number.
     *
     * @param int $cartId
     * @return int|null
     */
    protected function getTwoSurchargeSyncLastSeq($cartId)
    {
        $this->ensureTwoSurchargeSyncTable();
        $value = Db::getInstance()->getValue(
            'SELECT `seq` FROM `' . _DB_PREFIX_ . 'twopayment_surcharge_sync` WHERE `id_cart` = ' . (int) $cartId
        );
        if ($value === false || $value === null) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Persist the last-applied sync sequence for a cart (caller holds the
     * per-cart advisory lock, so plain REPLACE is race-free here).
     *
     * @param int $cartId
     * @param int $seq
     */
    protected function setTwoSurchargeSyncLastSeq($cartId, $seq)
    {
        $this->ensureTwoSurchargeSyncTable();
        Db::getInstance()->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . 'twopayment_surcharge_sync` (`id_cart`, `seq`, `updated_at`) ' .
            'VALUES (' . (int) $cartId . ', ' . (int) $seq . ", '" . pSQL(date('Y-m-d H:i:s')) . "')"
        );
    }

    /**
     * The surcharge product's line in the given cart, or null when absent.
     * Amounts are PrestaShop's OWN applied totals for the line (the figures
     * the buyer sees), used both for idempotency checks and for the
     * cart-vs-payload parity gate.
     *
     * @param Cart $cart
     * @return array{quantity:int,net:float,gross:float}|null
     */
    public function getTwoSurchargeCartLine($cart)
    {
        if (!Validate::isLoadedObject($cart)) {
            return null;
        }
        $productId = $this->getTwoSurchargeCartProductId(false);
        if ($productId <= 0) {
            return null;
        }
        foreach ((array) $cart->getProducts(true) as $row) {
            if ((int) $row['id_product'] === $productId) {
                return array(
                    'quantity' => (int) $row['cart_quantity'],
                    'net' => round((float) $row['total'], 2),
                    'gross' => round((float) $row['total_wt'], 2),
                );
            }
        }

        return null;
    }

    /**
     * Reconcile the cart's surcharge line with the buyer's Two selection:
     * upsert exactly one unit priced at the CURRENT quoted net fee when Two
     * is selected, remove it otherwise. This is the single writer for the
     * line - AJAX selection changes, term changes, and the order-create
     * self-heal all converge through here.
     *
     * AMOUNT SOURCE (requirement: zero drift vs the Two payload): the net is
     * taken from buildTwoSurchargeLineItemForCart() - the exact function
     * that builds the Two order payload's fee line - fed with the SAME basis
     * derivation the payload builder uses (calculateTwoLineItemTotals over
     * getTwoProductItems, which excludes this very product), so the quote
     * cache key (days|gross|country|currency) is byte-identical and both
     * sides read the same cached quote. There is no second computation that
     * could drift.
     *
     * IDEMPOTENCY: add-if-absent / remove-if-present keyed on the product id
     * via getTwoSurchargeCartLine; repeated calls with the same selection are
     * no-ops ('changed' => false). A quantity other than 1 or a stale amount
     * is corrected by delete + re-add. Never throws (fail-soft AJAX
     * contract); 'success' => false tells the caller nothing was reconciled.
     *
     * ORDERING (buyer-driven AJAX only): two rapid selection changes can have
     * their requests complete on the server in the wrong order (switch away
     * from Two, then back - the "back" request may finish first). When the
     * caller passes a monotonically increasing $syncSeq (the checkout JS
     * sends one), the last-applied sequence is stored per cart and any
     * request whose sequence is not strictly greater is a no-op that reports
     * the current state unchanged. Callers that pass null (the order-create
     * self-heal in buildTwoOrderPricingData - the final authoritative sync
     * before charging - and legacy frontends) bypass the guard entirely and
     * always apply.
     *
     * @param Cart $cart
     * @param bool $selected Two is the buyer's selected payment option
     * @param int|null $syncSeq buyer-driven request ordering guard (null = authoritative, always applies)
     * @return array{success:bool,changed:bool,present:bool}
     */
    public function syncTwoSurchargeCartLine($cart, $selected, $syncSeq = null)
    {
        // Upgrades from versions without the fee-guard trigger (and DB
        // restores that dropped it) get it back on the next checkout.
        $this->ensureTwoOrderDetailFeeGuardTrigger();

        $result = array('success' => false, 'changed' => false, 'present' => false);
        if ($syncSeq === null) {
            return $this->applyTwoSurchargeCartLineSync($cart, $selected);
        }

        try {
            if (!Validate::isLoadedObject($cart)) {
                return $result;
            }
            // Serialise buyer-driven syncs per cart so the seq check-then-act
            // and the cart mutation cannot interleave between two requests.
            $lockName = 'two_surcharge_sync_' . (int) $cart->id;
            if (!$this->acquireTwoDbLock($lockName)) {
                PrestaShopLogger::addLog('TwoPayment: Surcharge sync lock not acquired for cart ' . (int) $cart->id, 2);
                return $result;
            }
            try {
                $lastSeq = $this->getTwoSurchargeSyncLastSeq((int) $cart->id);
                if ($lastSeq !== null && (int) $syncSeq <= $lastSeq) {
                    // Stale request (an out-of-order older click): leave the
                    // cart exactly as the newer request left it.
                    PrestaShopLogger::addLog(
                        'TwoPayment: Ignored stale surcharge sync (seq ' . (int) $syncSeq . ' <= ' . $lastSeq . ') for cart ' . (int) $cart->id,
                        1
                    );
                    $result['success'] = true;
                    $result['present'] = $this->getTwoSurchargeCartLine($cart) !== null;
                    return $result;
                }
                $this->setTwoSurchargeSyncLastSeq((int) $cart->id, (int) $syncSeq);
                return $this->applyTwoSurchargeCartLineSync($cart, $selected);
            } finally {
                $this->releaseTwoDbLock($lockName);
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog('TwoPayment: Surcharge sync ordering guard failed for cart ' . (isset($cart->id) ? (int) $cart->id : 0) . ' - ' . $e->getMessage(), 3);
            return $result;
        }
    }

    /**
     * The actual reconciliation body of syncTwoSurchargeCartLine (see its
     * docblock); split out so the buyer-AJAX ordering guard wraps it without
     * touching the money logic.
     *
     * @param Cart $cart
     * @param bool $selected
     * @return array{success:bool,changed:bool,present:bool}
     */
    protected function applyTwoSurchargeCartLineSync($cart, $selected)
    {
        $result = array('success' => false, 'changed' => false, 'present' => false);
        try {
            if (!Validate::isLoadedObject($cart)) {
                return $result;
            }

            // Only materialise the product when actually adding a line.
            $productId = $this->getTwoSurchargeCartProductId((bool) $selected);
            if ($productId <= 0) {
                // Nothing ever created and nothing to remove -> vacuous success.
                $result['success'] = !$selected || empty($this->getTwoSurchargeSettings()['enabled']);
                return $result;
            }

            $existing = $this->getTwoSurchargeCartLine($cart);

            $expectedNet = null;
            $expectedGross = null;
            if ($selected) {
                $settings = $this->getTwoSurchargeSettings();
                if (!empty($settings['enabled'])) {
                    // Same basis derivation as buildTwoOrderPricingData:
                    // product+shipping line items, surcharge product excluded.
                    $basisTotals = $this->calculateTwoLineItemTotals($this->getTwoProductItems($cart));
                    $basis = round((float) $basisTotals['gross'], 2);
                    if ($basis > 0) {
                        $quoteUnavailable = false;
                        $line = $this->buildTwoSurchargeLineItemForCart($cart, $basis, null, $quoteUnavailable);
                        if ($quoteUnavailable) {
                            // TWO-25269: an unavailable quote is a FAILURE,
                            // not a deselection. Falling through to the
                            // removal branch below is what produced the
                            // silent undercharge this ticket closes: the line
                            // was deleted, success was reported, and the
                            // order was created with no surcharge at all.
                            // Leave the cart untouched and report failure -
                            // the payment option is withheld up front for the
                            // whole-store case (isTwoSurchargeQuotableForCart),
                            // and for anything that only fails here the
                            // order-create parity gate blocks loudly rather
                            // than mischarging.
                            PrestaShopLogger::addLog(
                                'TwoPayment: Surcharge cart line sync failed for cart ' . (int) $cart->id
                                . ' - fee quote unavailable; refusing to remove the surcharge line, which'
                                . ' would create the order with no surcharge at all',
                                3
                            );
                            $result['present'] = $existing !== null;
                            return $result;
                        }
                        if ($line !== null) {
                            $expectedNet = round((float) $line['net_amount'], 2);
                            $expectedGross = round((float) $line['gross_amount'], 2);
                        }
                    }
                }
            }

            if ($expectedNet === null || $expectedNet <= 0) {
                // Deselected / disabled / genuinely zero fee / empty basis:
                // the Two payload will carry no fee line either (same quote
                // source), so removing keeps both sides consistent. An
                // unavailable QUOTE no longer reaches here - it returned
                // failure above (TWO-25269).
                if ($existing !== null) {
                    $this->removeTwoSurchargeCartLineInternal($cart, $productId);
                    $result['changed'] = true;
                }
                $this->clearTwoSurchargeCartCookie();
                $result['success'] = true;
                return $result;
            }

            if (
                $existing !== null
                && (int) $existing['quantity'] === 1
                && $this->convertAmountToCents($existing['net']) === $this->convertAmountToCents($expectedNet)
                // Gross must match too: a net-only check would let a stale
                // tax application (rate change, primed tax-group cache)
                // persist forever, since net comes from the SpecificPrice
                // and never drifts on its own.
                && abs($this->convertAmountToCents($existing['gross']) - $this->convertAmountToCents($expectedGross))
                    <= $this->convertAmountToCents(self::ORDER_RECONCILIATION_TOLERANCE)
            ) {
                // Already exactly one unit at the current quote: no-op.
                $this->setTwoSurchargeCartCookie($cart);
                $result['success'] = true;
                $result['present'] = true;
                return $result;
            }

            if (!$this->ensureTwoSurchargeProductTaxRulesGroup($productId)) {
                PrestaShopLogger::addLog('TwoPayment: Surcharge cart line skipped - tax rules group could not be applied to product ' . (int) $productId . ' for cart ' . (int) $cart->id, 3);
                return $result;
            }

            $this->upsertTwoSurchargeSpecificPrice($cart, $productId, $expectedNet);
            if ($existing !== null) {
                // Wrong quantity or stale amount: rebuild the line cleanly.
                $cart->deleteProduct($productId);
            }
            if (!$cart->updateQty(1, $productId)) {
                PrestaShopLogger::addLog('TwoPayment: Failed adding surcharge line to cart ' . (int) $cart->id, 3);
                SpecificPrice::deleteByIdCart((int) $cart->id, $productId);
                return $result;
            }

            // Post-write verification: PrestaShop's own applied amounts must
            // match what the Two payload will carry. If they don't (broken
            // tax config the ensure step could not represent), REMOVE the
            // line and report success=false with changed=false - reporting a
            // change here would let the frontend refresh/restore cycle retry
            // forever. With no cart line and a fee-bearing payload, the
            // order-create parity gate blocks Two checkout loudly instead of
            // ever mischarging.
            $written = $this->getTwoSurchargeCartLine($cart);
            $toleranceCents = $this->convertAmountToCents(self::ORDER_RECONCILIATION_TOLERANCE);
            if (
                $written === null
                || $this->convertAmountToCents($written['net']) !== $this->convertAmountToCents($expectedNet)
                || abs($this->convertAmountToCents($written['gross']) - $this->convertAmountToCents($expectedGross)) > $toleranceCents
            ) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Surcharge line verification failed for cart ' . (int) $cart->id .
                    ' - expected (net/gross)=(' . $this->getTwoRoundAmount($expectedNet) . '/' . $this->getTwoRoundAmount($expectedGross) .
                    '), cart applied ' . ($written === null ? 'no line' :
                        '(net/gross)=(' . $this->getTwoRoundAmount($written['net']) . '/' . $this->getTwoRoundAmount($written['gross']) . ')') .
                    '. Line removed; check the selected surcharge tax rules group configuration.',
                    3
                );
                $this->removeTwoSurchargeCartLineInternal($cart, $productId);
                return $result;
            }

            $this->setTwoSurchargeCartCookie($cart);
            $result['success'] = true;
            $result['changed'] = true;
            $result['present'] = true;
            return $result;
        } catch (Exception $e) {
            PrestaShopLogger::addLog('TwoPayment: Surcharge cart line sync failed for cart ' . (isset($cart->id) ? (int) $cart->id : 0) . ' - ' . $e->getMessage(), 3);
            return $result;
        }
    }

    /**
     * Cart-scoped, currency-denominated SpecificPrice carrying the quoted
     * net fee. id_cart scopes the price to THIS cart only (concurrent buyers
     * hold their own rows); id_currency makes core take the amount as
     * already denominated in the cart currency, so a Two-side converted
     * figure enters the basket at the conversion output with no PS
     * re-conversion on top (TWO-25105 requirement; empirically verified:
     * Product::priceCalculation skips Tools::convertPrice when the specific
     * price's currency matches).
     *
     * @param Cart $cart
     * @param int $productId
     * @param float $net tax-excluded fee amount in the cart currency
     */
    protected function upsertTwoSurchargeSpecificPrice($cart, $productId, $net)
    {
        $existingIds = SpecificPrice::getIdsByProductId((int) $productId, false, (int) $cart->id);
        $row = is_array($existingIds) && !empty($existingIds) ? $existingIds[0] : null;
        $specificPriceId = is_array($row) ? (int) reset($row) : (int) $row;

        $sp = $specificPriceId > 0 ? new SpecificPrice($specificPriceId) : new SpecificPrice();
        $sp->id_product = (int) $productId;
        $sp->id_product_attribute = 0;
        $sp->id_shop = 0;
        $sp->id_currency = (int) $cart->id_currency;
        $sp->id_country = 0;
        $sp->id_group = 0;
        $sp->id_customer = 0;
        $sp->id_cart = (int) $cart->id;
        $sp->price = (float) $net;
        $sp->from_quantity = 1;
        $sp->reduction = 0;
        $sp->reduction_type = 'amount';
        $sp->reduction_tax = 1;
        $sp->from = '0000-00-00 00:00:00';
        $sp->to = '0000-00-00 00:00:00';
        if ($specificPriceId > 0 && Validate::isLoadedObject($sp)) {
            $sp->update();
        } else {
            $sp->add();
        }

        // Same-request price caches would otherwise serve the pre-update
        // figure to the parity gate right after a self-heal resync.
        if (method_exists('SpecificPrice', 'flushCache')) {
            SpecificPrice::flushCache();
        }
        if (method_exists('Product', 'flushPriceCache')) {
            // No arguments: flushPriceCache() takes none on any supported
            // PrestaShop version (1.7.6 through 8.2) — it always clears the
            // whole static price cache. The previously passed product id was
            // silently ignored.
            Product::flushPriceCache();
        }
    }

    /**
     * Remove the surcharge line and its cart-scoped price row.
     *
     * @param Cart $cart
     * @param int $productId
     */
    protected function removeTwoSurchargeCartLineInternal($cart, $productId)
    {
        $cart->deleteProduct((int) $productId);
        SpecificPrice::deleteByIdCart((int) $cart->id, (int) $productId);
        $this->clearTwoSurchargeCartCookie();
    }

    /**
     * Session marker legitimising the surcharge line for THIS cart. Absent
     * marker + present line = stale (abandoned/resumed cart in a fresh
     * session) and the stale-guard removes the line.
     *
     * @param Cart $cart
     */
    protected function setTwoSurchargeCartCookie($cart)
    {
        if (isset($this->context->cookie)) {
            $this->context->cookie->two_surcharge_cart_id = (string) (int) $cart->id;
            // Force an immediate write: the sync AJAX request ends via
            // ajaxDie()/exit, which does not guarantee Cookie::__destruct in
            // every PHP/webserver configuration (same precedent as
            // storeTwoFeeQuoteInSession). A lost marker would make the
            // stale-guard strip the line on the very next request.
            if (method_exists($this->context->cookie, 'write')) {
                $this->context->cookie->write();
            }
        }
    }

    protected function clearTwoSurchargeCartCookie()
    {
        if (isset($this->context->cookie) && isset($this->context->cookie->two_surcharge_cart_id)) {
            unset($this->context->cookie->two_surcharge_cart_id);
            if (method_exists($this->context->cookie, 'write')) {
                $this->context->cookie->write();
            }
        }
    }

    /**
     * Make the address form's phone field MANDATORY for as long as this module
     * is enabled (TWO-25326).
     *
     * WHY. A Two order is a credit decision, and a reachable phone number is
     * part of it - the provider validates the number and rejects the order
     * without one, at which point the buyer is already at the payment step with
     * nothing to do but go back and edit an address they were never told was
     * incomplete. The field exists in PrestaShop's default address format for
     * every country; core just leaves it optional.
     *
     * MECHANISM, and it is core's own rather than anything hand-rolled:
     * `AddressFormat::$requireFormFieldsList` is the public static list core
     * itself seeds with firstname/lastname/address1/city/Country:name, and
     * `AddressFormat::getFieldsRequired()` merges it with the merchant's
     * back-office selections. `CustomerAddressFormatter::getFormat()` reads that
     * merged list and calls `FormField::setRequired(true)`, which is BOTH halves
     * of the requirement in one move: the theme renders the field as required,
     * and `AbstractForm::validate()` refuses an empty value server-side. Verified
     * against 8.1.x and against `develop` (PS9) - the list, the merge and the
     * formatter's use of it are unchanged across both.
     *
     * WHY NOT the `required_field` TABLE, which is the other way to spell this:
     * `ObjectModel::addFieldsRequiredDatabase()` DELETEs every existing row for
     * the object before inserting, so writing through it would silently discard
     * whatever the merchant had configured in Customers > Addresses. Worse, a
     * field required in that table is enforced by
     * `ObjectModel::validateFieldsRequiredDatabase()` on EVERY save, including
     * the programmatic ones - the sole-trader autofill path here, other modules,
     * CSV imports - so a shop with any of those would start failing address
     * saves outright. The static list is scoped to the FORM, which is exactly
     * the scope asked for.
     *
     * WHY HERE rather than only in the module's `CustomerAddressFormatter`
     * override, which also calls `setRequired(true)`: overrides live as a COPY in
     * the shop's own `override/` directory and are only refreshed on
     * install/upgrade, so a shop can and does run a stale one (observed live on
     * staging - a 2.4.0-stamped copy on a 2.7.0 install). This runs from the
     * module file itself on every front request, so the requirement cannot be
     * lost that way. The override's line stays as-is: same outcome, and on a
     * current shop the two agree.
     *
     * NOT SCOPED TO THE CHECKOUT CONTROLLER, and that was a decision rather than
     * an oversight (review round 2 raised it). The address form is filled in
     * BEFORE a payment method is chosen, so "require it only when Two is the
     * selected method" does not exist as a question at the moment the buyer is
     * being asked - and that ordering is exactly the failure this closes. An
     * address saved from My Account is used at checkout later, so exempting that
     * form just moves the dead end one step back. The cost to a shop's other
     * buyers is one field they were already being shown, now marked required;
     * the cost of the narrower scope is the bug.
     *
     * Front office only, as a consequence of the hook rather than of a test: the
     * back office's own required-fields screen reads the DATABASE list, which
     * this never touches, so a merchant still sees exactly what they configured.
     *
     * Idempotent. Guarded on the class and on the property still being an array
     * so a future core that reshapes either degrades to "phone stays optional"
     * rather than to a fatal on every front page.
     *
     * @return void
     */
    private function requirePhoneOnAddressForms()
    {
        if (!class_exists('AddressFormat')) {
            return;
        }
        if (!is_array(AddressFormat::$requireFormFieldsList)) {
            return;
        }
        if (in_array('phone', AddressFormat::$requireFormFieldsList, true)) {
            return;
        }

        AddressFormat::$requireFormFieldsList[] = 'phone';
    }

    /**
     * Stale-line guard (runs on every front request, cheap early-outs).
     *
     * Two removal rules, both money-protective:
     * 1. ANOTHER payment module's front controller is executing (its order
     *    validation POST included): the buyer is not paying with Two, so the
     *    Two fee must never be charged - remove before that module computes
     *    totals. False positives (non-payment module controllers) only cost
     *    a re-add on the next Two selection / order-create self-heal.
     * 2. The session marker does not match the cart (abandoned cart resumed
     *    in a fresh session, cookie expired): the selection that justified
     *    the line is gone - remove so the buyer never sees a fee line
     *    without having selected Two.
     *
     * Own-module controllers are exempt so the payment/confirmation flow and
     * the sync endpoint itself never race their own line.
     *
     * @param array $params
     */
    public function hookActionFrontControllerInitAfter($params)
    {
        // Unconditional and FIRST, ahead of the surcharge stale-guard below and
        // outside its early returns: it has nothing to do with the cart, and it
        // has to be in place before the address form is built.
        $this->requirePhoneOnAddressForms();

        try {
            $cart = isset($this->context->cart) ? $this->context->cart : null;
            if (!Validate::isLoadedObject($cart)) {
                return;
            }
            $productId = $this->getTwoSurchargeCartProductId(false);
            if ($productId <= 0) {
                return;
            }
            if ($this->getTwoSurchargeCartLine($cart) === null) {
                return;
            }

            $controller = isset($params['controller']) ? $params['controller'] : (isset($this->context->controller) ? $this->context->controller : null);
            $controllerModuleName = '';
            if (is_object($controller) && isset($controller->module) && is_object($controller->module) && isset($controller->module->name)) {
                $controllerModuleName = (string) $controller->module->name;
            }
            if ($controllerModuleName === $this->name) {
                return;
            }

            $isOtherModuleController = $controllerModuleName !== '';
            $cookieCartId = isset($this->context->cookie->two_surcharge_cart_id) ? (int) $this->context->cookie->two_surcharge_cart_id : 0;
            $markerValid = $cookieCartId === (int) $cart->id;

            if ($isOtherModuleController || !$markerValid) {
                $this->removeTwoSurchargeCartLineInternal($cart, $productId);
                PrestaShopLogger::addLog(
                    'TwoPayment: Removed stale surcharge line from cart ' . (int) $cart->id .
                    ($isOtherModuleController ? ' (other module controller: ' . $controllerModuleName . ')' : ' (session marker mismatch)'),
                    1
                );
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog('TwoPayment: Surcharge stale-guard failed - ' . $e->getMessage(), 2);
        }
    }

    /**
     * Guard the ORDER (post-creation) against ever gaining an illegitimate
     * surcharge fee row. All the idempotency logic in this feature protects
     * the CART before order creation; nothing in core stops a back-office
     * employee from using the AdminOrders "Add product" search to add the
     * hidden "Payment terms fee" product to an already-placed, already-
     * invoiced Two order - a real duplicate financial line. OrderDetail rows
     * are only ever legitimately created for this product by PrestaShop's
     * own order-creation pipeline (validateOrder over a cart this module
     * synced), which runs in a FRONT context and always as the product's
     * FIRST row on the order.
     *
     * Fires on ObjectModel::add for every OrderDetail (verified against PS8
     * core: OrderDetail::create() -> save() -> add() -> this hook, both at
     * order creation and in the BO AddProductToOrderHandler).
     *
     * IMPORTANT - this hook is dev-environment UX ONLY, not the enforcement
     * layer. Hook::callHookOn() (PS8 core, verified) catches every module
     * exception and only re-throws when Environment::isDebug() is true
     * (_PS_MODE_DEV_, false in production) - in a production shop a throw
     * here is silently swallowed and the insert proceeds. The ACTUAL
     * enforcement is the database BEFORE INSERT trigger installed by
     * installTwoOrderDetailFeeGuardTrigger(), which rejects illegitimate fee
     * rows on every insertion path regardless of PHP context. This hook is
     * kept because in debug/dev shops it produces a friendlier,
     * properly-surfaced admin error before the SQL layer is ever reached.
     *
     * @param array $params ['object' => OrderDetail]
     * @throws PrestaShopException when the add must be blocked
     */
    public function hookActionObjectOrderDetailAddBefore($params)
    {
        $orderDetail = isset($params['object']) ? $params['object'] : null;
        if (!is_object($orderDetail) || !isset($orderDetail->product_id)) {
            return;
        }

        $surchargeProductId = $this->getTwoSurchargeCartProductId(false);
        if ($surchargeProductId <= 0 || (int) $orderDetail->product_id !== $surchargeProductId) {
            return;
        }

        // Manual back-office adds are NEVER legitimate for this product:
        // only the module's own automated cart-sync + order creation may
        // materialise it.
        if ($this->isTwoAdminContext()) {
            throw new PrestaShopException(
                'The payment terms fee product is managed automatically by the Two payment module and cannot be added to an order manually.'
            );
        }

        // Belt-and-braces for any other path: a SECOND fee row on the same
        // order is always a duplicate charge - fail loudly, never silently.
        $idOrder = isset($orderDetail->id_order) ? (int) $orderDetail->id_order : 0;
        if ($idOrder > 0) {
            $existingRows = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_detail` WHERE `id_order` = ' . $idOrder .
                ' AND `product_id` = ' . $surchargeProductId
            );
            if ($existingRows >= 1) {
                throw new PrestaShopException(
                    'Order ' . $idOrder . ' already carries the payment terms fee line; refusing to add a duplicate fee row.'
                );
            }
        }
    }

    /**
     * Whether the current request executes in a back-office context.
     * Primary signal is the controller_type PrestaShop stamps on every
     * controller; _PS_ADMIN_DIR_ is the fallback for early/legacy paths.
     *
     * @return bool
     */
    protected function isTwoAdminContext()
    {
        $controller = isset($this->context->controller) ? $this->context->controller : null;
        if (is_object($controller) && isset($controller->controller_type)) {
            return in_array((string) $controller->controller_type, array('admin', 'moduleadmin'), true);
        }

        return defined('_PS_ADMIN_DIR_');
    }

    /**
     * Buyer-facing label for the surcharge line. A merchant-set description
     * wins (with %s replaced by the term days, Magento/WooCommerce parity);
     * else the brand label; else a translated default that names the term.
     *
     * The default is "Payment terms fee - <n> days" (with the selected term's
     * day count); a merchant who has typed their own Surcharge Line Description
     * keeps it — this default only applies when that field is left blank.
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

        return sprintf($this->l('Payment terms fee - %d days'), (int) $days);
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
     * Payment Settings form). Presentation only — all decisioning is in the
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
            'type' => 'select',
            // Label matches the Magento and WooCommerce selectors verbatim
            // (TWO-25279) - the field is the merchant's tax treatment
            // decision, and the fact that PrestaShop expresses it as a tax
            // rules group is an implementation detail of this platform.
            'label' => $this->l('Surcharge Tax Treatment'),
            'name' => self::CONFIG_SURCHARGE_TAX_RULES_GROUP,
            'desc' => $this->l('Tax rules group applied to the payment terms fee - the same tax rules groups you assign to products. Country and state rules, combined rates and zero-rating apply exactly as they do for any product. To leave the fee untaxed, create a tax rules group with a 0% rate and select it here. A selection is required while surcharges are enabled.'),
            'options' => array(
                'query' => $this->getTwoSurchargeTaxRulesGroupOptions(),
                'id' => 'id',
                'name' => 'name',
            ),
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
     * A row is rendered for EVERY offerable term (getOfferableTermSource — the
     * same set the "Available Payment Terms" checkboxes render for), NOT just
     * the saved/available subset, so the admin JS (configuration.tpl,
     * updateSurchargeGridRows) can show/hide rows live as term checkboxes are
     * toggled without a save+reload. Initial visibility is computed
     * server-side with the same gates getAvailablePaymentTerms() applies: the
     * term's checkbox config is truthy AND the term is valid for the current
     * term type (EOM only allows EOM_PAYMENT_TERMS_OPTIONS). Row classes
     * mirror the checkbox type split (two-term-both / two-term-standard).
     *
     * @return string
     */
    protected function getTwoSurchargeGridHtml()
    {
        $cell_style = 'width:110px;';
        // id + per-column classes let the admin JS (configuration.tpl) show/hide
        // the whole grid and individual columns by the selected surcharge type,
        // without fragile positional selectors.
        $html = '<table id="two-surcharge-grid" class="table" style="width:auto;margin-bottom:0;">';
        $html .= '<thead><tr>'
            . '<th>' . $this->l('Term') . '</th>'
            . '<th class="two-col-percentage">' . $this->l('Percentage') . '</th>'
            . '<th class="two-col-fixed">' . $this->l('Fixed fee') . '</th>'
            // "Cap", not "Cap on percentage": the cap bounds the whole fee
            // line - the percentage and the fixed fee together - so the old
            // heading described it wrongly (TWO-25289).
            . '<th class="two-col-cap">' . $this->l('Cap') . '</th>'
            . '</tr></thead><tbody>';

        $term_type = Configuration::get('PS_TWO_PAYMENT_TERM_TYPE');
        $source = $this->getOfferableTermSource(false);
        sort($source);
        foreach ($source as $days) {
            $days = (int) $days;
            $pct_name = 'PS_TWO_SURCHARGE_PCT_' . $days;
            $fixed_name = 'PS_TWO_SURCHARGE_FIXED_' . $days;
            $cap_name = 'PS_TWO_SURCHARGE_CAP_' . $days;

            $pct = htmlspecialchars((string) Tools::getValue($pct_name, Configuration::get($pct_name)), ENT_QUOTES, 'UTF-8');
            $fixed = htmlspecialchars((string) Tools::getValue($fixed_name, Configuration::get($fixed_name)), ENT_QUOTES, 'UTF-8');
            $cap = htmlspecialchars((string) Tools::getValue($cap_name, Configuration::get($cap_name)), ENT_QUOTES, 'UTF-8');

            $is_eom_capable = in_array($days, self::EOM_PAYMENT_TERMS_OPTIONS, true);
            $type_class = $is_eom_capable ? 'two-term-both' : 'two-term-standard';
            $checked = (bool) Configuration::get('PS_TWO_PAYMENT_TERMS_' . $days);
            $valid_for_type = $term_type !== 'EOM' || $is_eom_capable;
            $row_style = ($checked && $valid_for_type) ? '' : ' style="display:none"';

            $html .= '<tr class="two-surcharge-row two-surcharge-row-' . $days . ' ' . $type_class . '"'
                . ' data-term="' . $days . '"' . $row_style . '>'
                . '<td style="vertical-align:middle;">' . sprintf($this->l('%d days'), $days) . '</td>'
                . '<td class="two-col-percentage"><div class="input-group" style="' . $cell_style . '">'
                . '<input type="text" class="form-control" name="' . $pct_name . '" value="' . $pct . '">'
                . '<span class="input-group-addon">%</span></div></td>'
                . '<td class="two-col-fixed"><input type="text" class="form-control" style="' . $cell_style . '" name="' . $fixed_name . '" value="' . $fixed . '"></td>'
                . '<td class="two-col-cap"><input type="text" class="form-control" style="' . $cell_style . '" name="' . $cap_name . '" value="' . $cap . '"></td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        // Cap semantics, stated where the cap is entered. Both sentences
        // exist because the grid otherwise invites exactly the mistake it
        // then refuses on save (TWO-25289).
        // two-col-cap so the admin JS hides this alongside the cap COLUMN: on
        // a fixed-only surcharge the column is hidden and cap-only copy left
        // on screen describes a field the merchant cannot see.
        // Initial visibility computed SERVER-side, like the rows above: the
        // admin JS hides it on load, but relying on that alone flashes
        // cap-only copy on every render and leaves it up permanently wherever
        // the JS does not run.
        $cap_help_style = in_array(
            TwoSurchargeCalculator::normalizeType(Configuration::get('PS_TWO_SURCHARGE_TYPE')),
            array('percentage', 'fixed_and_percentage'),
            true
        ) ? '' : 'display:none;';
        $html .= '<p class="help-block two-col-cap" style="margin-top:8px;' . $cap_help_style . '">'
            . htmlspecialchars(
                $this->l('The cap applies to the whole fee: the percentage and the fixed fee together, not the percentage alone. Leave it empty for no cap.'),
                ENT_QUOTES,
                'UTF-8'
            )
            . ' '
            . htmlspecialchars(
                $this->l('A cap of 0 is not allowed. To charge nothing on a term, set the percentage and the fixed fee for that term to 0 instead.'),
                ENT_QUOTES,
                'UTF-8'
            )
            . '</p>';

        return $html;
    }

    /**
     * Dropdown options for the surcharge tax treatment: an unselected
     * placeholder (id '' - never a valid selection, save-blocked while
     * surcharges are enabled, see validTwoSurchargeFormValues), then the
     * merchant's active tax rules groups - the same list core's own
     * product-edit page offers (its
     * TaxRulesGroup::getTaxRulesGroupsForOptions duplicates a group per
     * rate, so the deduplicated getTaxRulesGroups source is used). Ids are
     * emitted as STRINGS so the form template's loose == never conflates
     * the placeholder ('') with "No tax" ('0') on PHP 7 shops ('' == 0 is
     * true there).
     *
     * PrestaShop's built-in "No tax" sentinel (id 0) is NOT offered
     * (TWO-25279). It is a core default rather than a tax rules group the
     * merchant set up, and choosing it silently means "the fee is never
     * taxed, in any country" - a tax decision made by picking an option we
     * handed them. To leave the fee untaxed a merchant creates a 0%-rate
     * group and selects that, so the treatment stays visible in Tax Rules.
     * Same rule across the WooCommerce / PrestaShop / Magento plugins.
     *
     * There is deliberately NO grandfathering: a shop already storing '0'
     * does not get the option back. Such a shop's select falls back to the
     * placeholder and getTwoSurchargeNeverTaxedNotice() puts a loud error at
     * the top of the configuration page, so "looks unset" cannot be mistaken
     * for "is unset" while the fee is in fact untaxed.
     *
     * The currently-configured group is still present even when deactivated
     * (suffixed "(inactive)"): a real group the merchant chose must not
     * silently drop to the first option on an unrelated save.
     *
     * @return array<int,array{id:string,name:string}>
     */
    protected function getTwoSurchargeTaxRulesGroupOptions()
    {
        $options = array(
            array('id' => '', 'name' => $this->l('-- Select surcharge tax treatment --')),
        );
        $groups = TaxRulesGroup::getTaxRulesGroups(true);
        // No 0 seed: the "No tax" pseudo-id is no longer an option.
        $seen = array();
        foreach ((array) $groups as $group) {
            if (!isset($group['id_tax_rules_group'])) {
                continue;
            }
            // Filtered through the shared predicate rather than assuming core
            // never lists the sentinel, so the option list cannot drift from
            // what the save guard refuses.
            if ($this->isTwoSurchargeNeverTaxedTreatment($group['id_tax_rules_group'])) {
                continue;
            }
            $id = (int) $group['id_tax_rules_group'];
            $options[] = array(
                'id' => (string) $id,
                'name' => (string) $group['name'],
            );
            $seen[$id] = true;
        }

        $configuredId = $this->getTwoSurchargeTaxRulesGroupId();
        if ($configuredId > 0 && !isset($seen[$configuredId])) {
            $configured = new TaxRulesGroup($configuredId);
            if (Validate::isLoadedObject($configured)) {
                $options[] = array(
                    'id' => (string) $configuredId,
                    'name' => (string) $configured->name . ' (' . $this->l('inactive') . ')',
                );
            }
        }

        return $options;
    }

    /**
     * Pre-selection for the surcharge tax rules group dropdown: the stored
     * selection, else '' - the unselected placeholder. NEVER auto-defaults:
     * not Product::getIdTaxRulesGroupMostUsed() (a full-catalog COUNT/GROUP
     * BY re-run on every unsaved config page render, pre-selecting a taxing
     * group the merchant never chose), and not "No tax" either - untaxed is
     * a tax treatment, not an absence of one, and pre-selecting it invites
     * an accidental save. The merchant must pick explicitly; while
     * surcharges are enabled the save is blocked until they do
     * (validTwoSurchargeFormValues).
     *
     * @return string '' (unselected) or the stored group id ('0' = No tax)
     */
    protected function getTwoSurchargeTaxRulesGroupFormDefault()
    {
        $stored = Configuration::get(self::CONFIG_SURCHARGE_TAX_RULES_GROUP);
        if ($stored !== false && $stored !== null && $stored !== '' && is_numeric($stored)) {
            return (string) max(0, (int) $stored);
        }

        return '';
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
            // Kept a STRING ('' = unselected placeholder): an (int) cast
            // would turn the unselected state into 0 and silently
            // pre-select "No tax".
            self::CONFIG_SURCHARGE_TAX_RULES_GROUP => (string) Tools::getValue(
                self::CONFIG_SURCHARGE_TAX_RULES_GROUP,
                $this->getTwoSurchargeTaxRulesGroupFormDefault()
            ),
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
        // Surcharges are enabled (type !== 'none' - the early return above):
        // an explicit tax treatment is REQUIRED. The unselected placeholder
        // ('' / absent) blocks the save server-side - never silently falls
        // back to "No tax".
        $groupRaw = Tools::getValue(self::CONFIG_SURCHARGE_TAX_RULES_GROUP);
        $groupTrimmed = is_string($groupRaw) ? trim($groupRaw) : '';
        if ($groupTrimmed === '') {
            $this->errors[] = $this->l('Select a surcharge tax treatment: surcharges are enabled, so you must explicitly choose a tax rules group before saving.');
        } else {
            // ctype_digit: a whole non-negative integer only - '0.5', '-5'
            // and friends are rejected, never truncated into a selection
            // the merchant did not make.
            //
            // A never-taxed treatment is refused outright, with its own
            // message (TWO-25279). Removing it from the dropdown is a UI rule
            // only; without this check a crafted POST could still persist a
            // fee that is untaxed in every country. There is no
            // already-stored exemption - a shop sitting on the sentinel is
            // told to pick a real group, not allowed to re-save it.
            if ($this->isTwoSurchargeNeverTaxedTreatment($groupTrimmed)) {
                $this->errors[] = $this->l('That surcharge tax treatment leaves the surcharge untaxed in every country and is no longer available. Create a tax rules group with a 0% rate and select that instead.');
            } else {
                $groupId = ctype_digit($groupTrimmed) ? (int) $groupTrimmed : -1;
                if ($groupId <= 0 || !Validate::isLoadedObject(new TaxRulesGroup($groupId))) {
                    $this->errors[] = $this->l('Surcharge tax treatment must be one of the shop\'s existing tax rules groups. To leave the fee untaxed, create a group with a 0% rate.');
                }
            }
        }
        // The RENDERED term set, not getAvailablePaymentTerms(): the grid
        // renders (and therefore posts) a row per OFFERABLE term, and the
        // ticked subset is rewritten by saveTwoPaymentSettingsFormValues()
        // BEFORE saveTwoSurchargeFormValues() reads it. Validating the stored
        // subset therefore skipped any cell on a term ticked in the same
        // submit - so a cap of 0 typed on a newly-ticked term was stored
        // unvalidated and then relayed, silently wiping the whole fee. The
        // offerable source does not move during a save, and it is a superset
        // of what gets persisted, so nothing storable escapes the checks.
        // The cap column is only VISIBLE alongside a percentage, and the admin
        // JS hides it (and, with it, the help text explaining this very rule)
        // otherwise - but a hidden input still posts, so a cap stored while the
        // type was percentage keeps arriving. Refusing it would abort the whole
        // Payment Settings save over a field the merchant can neither see nor
        // read about. The value is still stored either way; only the zero rule
        // is skipped, and a legacy zero resurfaces when the column comes back
        // into view, which is where they can act on it.
        $cap_column_visible = in_array($type, array('percentage', 'fixed_and_percentage'), true);

        $rendered_terms = array_map('intval', $this->getOfferableTermSource(false));
        foreach ($rendered_terms as $days) {
            $days = (int) $days;
            foreach (array('PCT', 'FIXED', 'CAP') as $suffix) {
                $raw = Tools::getValue('PS_TWO_SURCHARGE_' . $suffix . '_' . $days);
                // An UNSUBMITTED cell is nothing to validate. `false` is what
                // core returns for an absent key; null is included because a
                // rendered-but-cleared input and a genuinely absent one must
                // behave the same, and the earlier `!== false && !== ''` pair
                // let null through to be reported as a non-numeric value.
                if ($raw === false || $raw === null || trim((string) $raw) === '') {
                    continue;
                }
                if (!is_numeric($raw) || (float) $raw < 0) {
                    $this->errors[] = $this->l('Surcharge values must be non-negative numbers.');

                    return;
                }
                // A cap of exactly 0 is refused (TWO-25289). It is never what
                // a merchant means by it: the cap bounds the WHOLE fee - the
                // percentage and the fixed fee together, not the percentage
                // alone - so a cap of 0 silently wipes a configured fixed fee
                // too, and nothing in the grid says so. The intent it gets
                // mistaken for ("charge nothing on this term") is expressible
                // directly, with 0% and a 0 fixed fee. A BLANK cap stays
                // valid and still means "no cap" - the guard above already
                // skipped it, so absence and zero stay distinguishable.
                // round() first, not `=== 0.0`: TwoSurchargeCalculator rounds
                // the cap to 2dp before sending it, so a sub-cent cap (0.001)
                // would pass an exact-zero check and then become a hard cap of
                // 0.00 on the wire - the very outcome being refused, reached a
                // step later. Refusing everything that rounds away is what
                // makes "the rounding direction cannot matter" actually true.
                if ($suffix === 'CAP'
                    && $cap_column_visible
                    && round((float) $raw, TwoSurchargeCalculator::MONEY_DECIMALS) === 0.0
                ) {
                    $this->errors[] = sprintf(
                        $this->l('Surcharge cap for the %d-day term cannot be 0. To charge nothing on this term, set the percentage and the fixed fee to 0 instead, and leave the cap empty.'),
                        $days
                    );

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

        // NEVER silently coerce to "No tax": absent/blank/invalid input is
        // stored as '' (unselected - the dropdown re-renders on its
        // placeholder). While surcharges are enabled this path is
        // unreachable with '' (validTwoSurchargeFormValues blocks the save
        // first); while disabled, staying unselected is the point - the
        // merchant must pick explicitly before enabling.
        //
        // 0 ("No tax") can no longer be stored at all (TWO-25279), so a
        // crafted POST cannot persist a never-taxed fee even on the
        // surcharges-disabled path, where validTwoSurchargeFormValues does
        // not run.
        $groupRaw = Tools::getValue(self::CONFIG_SURCHARGE_TAX_RULES_GROUP, '');
        $groupTrimmed = is_string($groupRaw) ? trim($groupRaw) : '';
        $groupValue = '';
        if (
            $groupTrimmed !== ''
            && ctype_digit($groupTrimmed)
            // Defensive, not operative: on PrestaShop the sentinel is id 0,
            // which is never a loadable TaxRulesGroup, so the check below
            // already rejects it - removing this line does not turn the
            // suite red. It is here so the refusal is stated by NAME at
            // every enforcement site, and survives any future relaxation of
            // the isLoadedObject check.
            && !$this->isTwoSurchargeNeverTaxedTreatment($groupTrimmed)
        ) {
            $groupId = (int) $groupTrimmed;
            if (Validate::isLoadedObject(new TaxRulesGroup($groupId))) {
                $groupValue = (string) $groupId;
            }
        }
        Configuration::updateValue(self::CONFIG_SURCHARGE_TAX_RULES_GROUP, $groupValue);
        // An explicit merchant selection of a real group retires the
        // post-upgrade "needs re-selection" nag from upgrade-2.5.0.php. A
        // save that stored '' (still unselected, which now includes a
        // refused never-taxed submission) does NOT - the nag is accurate
        // until a real choice is made.
        if ($groupValue !== '') {
            Configuration::updateValue(self::CONFIG_SURCHARGE_TAX_MIGRATION_NOTICE, '');
        }

        // Apply the selection to the hidden fee product immediately (the
        // same id_tax_rules_group field every real Product uses); if the
        // product does not exist yet, lazy creation picks the config up.
        $productId = (int) Configuration::get(self::CONFIG_SURCHARGE_PRODUCT_ID);
        if ($productId > 0) {
            $this->ensureTwoSurchargeProductTaxRulesGroup($productId);
        }

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
            $url = $url . '?' . http_build_query($this->getTwoClientParams());
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
            $url = $url . '?' . http_build_query($this->getTwoClientParams());
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
    /**
     * Client version string reported to the Two API as `client_v`.
     *
     * Semver from $this->version, suffixed with `+<short-sha>` when the deployed
     * commit can be resolved (sidecar file or .git gitlink/directory). Never emits
     * a bare trailing `+`.
     *
     * NOTE: callers MUST url-encode this — `+` decodes to a space in a query string.
     * Use getTwoClientParams() with http_build_query() rather than concatenating.
     *
     * @return string
     */
    public function getTwoClientVersion()
    {
        if ($this->two_client_version_cache === null) {
            $client_version = (string) $this->version;
            $sha = $this->getTwoDeployedCommitHash();
            if (is_string($sha) && $sha !== '') {
                $client_version .= '+' . $sha;
            }
            $this->two_client_version_cache = $client_version;
        }

        return $this->two_client_version_cache;
    }

    /**
     * Standard client identification query params for Two API calls.
     *
     * @return array<string, string>
     */
    public function getTwoClientParams()
    {
        return array('client' => 'PS', 'client_v' => $this->getTwoClientVersion());
    }

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
    public function configureSslVerification($ch)
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
     * Calculate the decision snapshot hash for a UX-only order-intent check
     * (TWO-24799).
     *
     * Composed from - never a replacement for - the cart snapshot hash used by
     * order-create idempotency. That hash covers the cart, currency, address
     * IDs and every line item, but it deliberately says nothing about WHO is
     * buying, and the buyer's company is the primary input Two decides on. A
     * buyer who re-runs company search and picks a different company against
     * the same saved address would otherwise produce an identical hash, so the
     * buyer identity is mixed in explicitly here.
     *
     * @param Cart $cart
     * @param array $paymentdata Order-intent payload as built by getTwoIntentOrderData()
     * @return string
     */
    public function calculateTwoOrderIntentSnapshotHash($cart, $paymentdata)
    {
        $company = array();
        if (isset($paymentdata['buyer']['company']) && is_array($paymentdata['buyer']['company'])) {
            $company = $paymentdata['buyer']['company'];
        }

        $identity = array(
            'company_name' => isset($company['company_name']) ? trim((string)$company['company_name']) : '',
            'organization_number' => isset($company['organization_number']) ? trim((string)$company['organization_number']) : '',
            'country_prefix' => isset($company['country_prefix']) ? strtoupper(trim((string)$company['country_prefix'])) : '',
            // Country lives on the address blocks too, and a country switch MUST
            // bust this hash (TWO-24867 tracks country-switch staleness as a bug
            // class here), so both are folded in rather than trusted separately.
            'billing_country' => isset($paymentdata['billing_address']['country'])
                ? strtoupper(trim((string)$paymentdata['billing_address']['country']))
                : '',
            'shipping_country' => isset($paymentdata['shipping_address']['country'])
                ? strtoupper(trim((string)$paymentdata['shipping_address']['country']))
                : '',
            'invoice_type' => isset($paymentdata['invoice_type']) ? (string)$paymentdata['invoice_type'] : '',
        );

        $seed = 'order_intent|'
            . $this->calculateTwoCheckoutSnapshotHash($cart, $paymentdata) . '|'
            . json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $seed);
    }

    /**
     * Record the snapshot hash the browser is about to run an intent check for,
     * so the decision it reports back can be bound to the right snapshot
     * (TWO-24799).
     *
     * The browser makes the /v1/order_intent call today, so it - not the server
     * - learns the outcome. Rather than trusting a client-supplied hash, the
     * server remembers the hash it just computed and binds the reported
     * decision to that. When TWO-25162 moves the call behind the plugin backend
     * the server will know the outcome first-hand and can write the decision
     * directly; the cache read/write pair below is unchanged by that.
     *
     * @param string $snapshot_hash
     * @return void
     */
    public function markTwoPendingOrderIntentSnapshot($snapshot_hash)
    {
        $snapshot_hash = trim((string)$snapshot_hash);
        if (Tools::isEmpty($snapshot_hash)) {
            return;
        }

        $this->context->cookie->two_order_intent_pending_hash = $snapshot_hash;
        $this->context->cookie->setExpire(time() + self::COOKIE_EXPIRY_ONE_HOUR);
    }

    /**
     * Bind the decision the browser reported to the pending snapshot hash
     * (TWO-24799).
     *
     * @param bool $approved
     * @return string The snapshot hash the decision was stored against, '' when none was pending
     */
    public function storeTwoOrderIntentDecisionForPendingSnapshot($approved)
    {
        $snapshot_hash = isset($this->context->cookie->two_order_intent_pending_hash)
            ? trim((string)$this->context->cookie->two_order_intent_pending_hash)
            : '';
        if (Tools::isEmpty($snapshot_hash)) {
            return '';
        }

        $this->context->cookie->two_order_intent_decision = json_encode(array(
            'hash' => $snapshot_hash,
            'approved' => (bool)$approved ? 1 : 0,
            'at' => time(),
        ));
        $this->context->cookie->setExpire(time() + self::COOKIE_EXPIRY_ONE_HOUR);

        return $snapshot_hash;
    }

    /**
     * Read back a still-valid intent decision for a snapshot hash (TWO-24799).
     *
     * Returns null - never a stale decision - whenever the snapshot differs, the
     * entry has aged past ORDER_INTENT_DECISION_CACHE_TTL, or the stored value
     * is unreadable. Any cart, address, country or company change produces a
     * different hash, so this cannot survive the buyer editing their order.
     *
     * @param string $snapshot_hash
     * @return array{approved:bool,timestamp:int}|null
     */
    public function getTwoCachedOrderIntentDecision($snapshot_hash)
    {
        $snapshot_hash = trim((string)$snapshot_hash);
        if (Tools::isEmpty($snapshot_hash)) {
            return null;
        }

        $encoded = isset($this->context->cookie->two_order_intent_decision)
            ? (string)$this->context->cookie->two_order_intent_decision
            : '';
        if (Tools::isEmpty($encoded)) {
            return null;
        }

        $decoded = json_decode($encoded, true);
        if (!is_array($decoded) || !isset($decoded['hash'], $decoded['approved'], $decoded['at'])) {
            return null;
        }

        if (!hash_equals((string)$decoded['hash'], $snapshot_hash)) {
            return null;
        }

        $age = time() - (int)$decoded['at'];
        if ($age < 0 || $age > self::ORDER_INTENT_DECISION_CACHE_TTL) {
            return null;
        }

        return array(
            'approved' => (bool)(int)$decoded['approved'],
            'timestamp' => (int)$decoded['at'],
        );
    }

    /**
     * Drop any cached intent decision (TWO-24799).
     *
     * @return void
     */
    public function clearTwoCachedOrderIntentDecision()
    {
        unset($this->context->cookie->two_order_intent_decision);
        unset($this->context->cookie->two_order_intent_pending_hash);
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
                // Show the upload-status section when the merchant currently
                // has the feature OR this order already carries upload history
                // (an order uploaded under a past entitlement must keep showing
                // what happened to it even if the flag is later revoked).
                'use_own_invoices' => $this->isMerchantInvoiceDistributed()
                    || !empty($twopaymentdata['two_invoice_upload_status']),
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
            $previousCompanyName = isset($this->context->cookie->two_company_name)
                ? (string) $this->context->cookie->two_company_name
                : '';

            $this->context->cookie->two_company_name = $address->company;
            if (!empty($address->id)) {
                $this->context->cookie->two_company_address_id = (string) (int) $address->id;
            }

            // Try to get organization number from form data if available
            $companyId = Tools::getValue('companyid', '');
            if (!empty($companyId)) {
                $this->context->cookie->two_company_id = $companyId;
            } elseif (
                isset($this->context->cookie->two_company_id)
                && !$this->twoCompanyNamesMatch($previousCompanyName, (string) $address->company)
            ) {
                // TWO-25288. The buyer saved a DIFFERENT company name with no
                // organisation number beside it - which is what disowning a
                // selected company looks like by the time it reaches the server.
                //
                // The line above has just overwritten the cookie's company NAME,
                // so leaving the old organisation number in place would pair one
                // company's number with another company's name, and the resolver
                // consults this cookie FIRST, ahead of the address. That pairing
                // is the wrong-company credit check in its purest form.
                //
                // This is also the server-side backstop for the browser's clear
                // being fire-and-forget: a clear request that is dropped, or is
                // still in flight when the address saves, lands here instead. So
                // the guarantee does not depend on that request arriving.
                //
                // The country marker goes with it. A marker with no organisation
                // number behind it is the half-record state the clearCompany
                // action exists to avoid, and the two readers of this cookie
                // disagree about how to interpret it.
                unset($this->context->cookie->two_company_id);
                unset($this->context->cookie->two_company_country);

                PrestaShopLogger::addLog(
                    'TwoPayment: Dropped session company number - address company changed from "'
                    . $previousCompanyName . '" to "' . $address->company . '" with no companyid supplied',
                    1
                );
            }

            // Set cookie expiration (1 hour)
            $this->context->cookie->setExpire(time() + self::COOKIE_EXPIRY_ONE_HOUR);

            PrestaShopLogger::addLog('TwoPayment: Company data captured from address save - Company: ' . $address->company, 1);
        }
    }

    /**
     * Whether two company names are the same company as far as this cookie is
     * concerned.
     *
     * Case- and whitespace-insensitive, but this is NOT claimed to mirror the
     * browser's normalizeCompanyName() exactly: JS's `\s` collapses a non-breaking
     * space (the browser regex engine treats it as whitespace), PCRE's `\s` here
     * does not without the unicode modifier. So a name that differs only by an
     * NBSP-vs-space swap normalizes as unchanged in the browser but as a real
     * difference here. That is conservative rather than a bug worth chasing: on
     * divergence this side is more willing to say "changed" and drop a stale
     * organisation number than to risk calling two different names the same one.
     * A buyer tidying the capitalisation of the company they selected has not
     * disowned it, and treating that as a change would throw away a perfectly
     * good organisation number.
     *
     * An empty previous name never matches, so the first company saved on a
     * session cannot be read as an unchanged one. That guard is live, not
     * decorative: the caller only reaches this function once the address's
     * company is confirmed non-empty (`empty($address->company)` above already
     * returned), but a whitespace-only company name survives that check and
     * normalizes to '' - same as an unset previous name. Without the guard, a
     * previous name of '' would compare equal to that whitespace-only company,
     * read as "unchanged", and leave a stale organisation number in place under
     * a blank-looking name. A literally-empty previous name paired with an
     * already-set company id is not otherwise reachable - no writer of the
     * cookie ever stores an empty name (each guards on a non-empty company
     * before writing, and no path unsets the name while leaving the id behind)
     * - so this is the one case the guard exists for.
     *
     * Note for anyone chasing non-ASCII behaviour under the PHP test suite:
     * tests/bootstrap.php stubs Tools::strtolower() as a byte-wise ASCII
     * strtolower(), not the real mb_strtolower(). That gap predates this
     * change (the stub already backed the other Tools::strtolower() call
     * site) and is not newly introduced here - a non-ASCII capitalisation
     * tidy-up is untested by this suite either way.
     */
    private function twoCompanyNamesMatch($left, $right)
    {
        $normalize = function ($value) {
            return preg_replace('/\s+/', ' ', trim(Tools::strtolower((string) $value)));
        };

        $normalizedLeft = $normalize($left);

        return $normalizedLeft !== '' && $normalizedLeft === $normalize($right);
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
}
