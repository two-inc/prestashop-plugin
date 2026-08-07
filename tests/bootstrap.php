<?php

declare(strict_types=1);

namespace {
    if (!defined('_PS_VERSION_')) {
        define('_PS_VERSION_', '8.0.0');
    }
    if (!defined('_MYSQL_ENGINE_')) {
        define('_MYSQL_ENGINE_', 'InnoDB');
    }
    if (!function_exists('pSQL')) {
        function pSQL($value, $htmlOK = false)
        {
            return addslashes((string) $value);
        }
    }
    if (!defined('_PS_CACHE_DIR_')) {
        define('_PS_CACHE_DIR_', sys_get_temp_dir() . DIRECTORY_SEPARATOR);
    }
    if (!defined('_DB_PREFIX_')) {
        define('_DB_PREFIX_', 'ps_');
    }
}

namespace PrestaShop\PrestaShop\Core\Payment {
    /**
     * Core's fluent payment-option value object. The setters were previously
     * absent, which was fine while nothing invoked getTwoPaymentOption() - it is
     * exercised now (TWO-25326 bug 9, round 3, for the server-rendered
     * sole-trader answer it assigns), and a fluent builder is unusable without
     * them: the first call fatals rather than failing an assertion.
     *
     * Records rather than validates - the specs that use this care about what the
     * module hands the TEMPLATE, and core's own contract for these values is not
     * something a stub can meaningfully assert.
     */
    class PaymentOption
    {
        /** @var array<string, mixed> */
        public $recorded = [];

        /**
         * The setters PrestaShop core's PaymentOption actually has. An
         * allowlist, not a `strpos($name, 'set') === 0` catch-all: a catch-all
         * records ANY setter and returns $this, so a module change calling a
         * setter core does NOT have passes every spec here and fatals in
         * production - which is precisely the class of mismatch a stub of a core
         * value object exists to catch.
         *
         * @var string[]
         */
        private const CORE_SETTERS = array(
            'setCallToActionText',
            'setAction',
            'setForm',
            'setInputs',
            'setAdditionalInformation',
            'setLogo',
            'setModuleName',
            'setBinary',
        );

        public function __call($name, $arguments)
        {
            if (in_array($name, self::CORE_SETTERS, true)) {
                $this->recorded[lcfirst(substr($name, 3))] = $arguments[0] ?? null;

                return $this;
            }
            if (strpos($name, 'get') === 0) {
                return $this->recorded[lcfirst(substr($name, 3))] ?? null;
            }

            throw new \BadMethodCallException(
                'PaymentOption stub has no ' . $name . '() - if PrestaShop core really does, add it to CORE_SETTERS'
            );
        }
    }
}

namespace {
    final class StubStore
    {
        public static array $configuration = [];
        public static array $countries = [];
        public static array $states = [];
        public static array $customers = [];
        public static array $currencies = [];
        public static array $addresses = [];
        public static array $carriers = [];
        public static array $cartProducts = [];
        /**
         * PrestaShop core's checkout order comment, one row per cart, as
         * Message::getMessageByCartId() returns it: [id_cart => ['id_message' =>
         * int, 'message' => string]]. Core stores it htmlentities-encoded.
         *
         * @var array<int,array<string,mixed>>
         */
        public static array $cartMessages = [];
        /**
         * Module instances Module::getInstanceByName() should hand back, keyed
         * by module name (TWO-25326 - the address-form override asks the module
         * whether the company search is available at all).
         *
         * @var array<string,object>
         */
        public static array $moduleInstances = [];
        public static array $cartTotals = [];
        public static array $cartShipping = [];
        public static array $cartRules = [];
        /**
         * Cart::getDeliveryOptionList() fixtures by cart id, core-shaped:
         *   [id_address => [option_key => ['carrier_list' => [id_carrier => [
         *       'price_with_tax' => float, 'price_without_tax' => float,
         *       'instance' => Carrier,
         *   ]]]]]
         * The no-available-carrier sentinel is carrier_list = [0 => 0].
         *
         * @var array<int,array>
         */
        public static array $cartDeliveryOptionLists = [];
        /**
         * Cart::getDeliveryOption() overrides by cart id. Unset means the stub
         * auto-selects the first option per address, like core does.
         *
         * @var array<int,array|false>
         */
        public static array $cartDeliveryOptions = [];
        /**
         * Cart ids whose delivery-option lookup must RAISE instead of
         * returning. Core's getDeliveryOption()/getDeliveryOptionList() walk
         * ObjectModel constructors, Db::executeS and third-party carrier
         * modules, any of which can throw; the value is the message.
         *
         * @var array<int,string>
         */
        public static array $cartDeliveryOptionListThrows = [];
        public static array $moduleCurrencies = [];
        /**
         * `module_country` rows, as PrestaShop's Payment Restrictions screen
         * writes them: [['id_module' => int, 'id_shop' => int, 'id_country' =>
         * int], ...]. Read by the Db::getValue() stub, so a spec restricts the
         * module to a country subset the same way a merchant does.
         *
         * NULL (the default) is NOT "the table is empty" - it means no spec has
         * expressed an opinion, so every country resolves as enabled. That is the
         * PrestaShop install default: PaymentModule::install() populates a row per
         * active country. An empty ARRAY is the genuinely-empty table, which core
         * treats as "no country enabled".
         *
         * @var array<int,array<string,int>>|null
         */
        public static ?array $moduleCountries = null;
        public static array $productCategories = [];
        public static array $images = [];
        /**
         * Effective tax rates by tax rules group, mirroring core's
         * TaxManagerFactory resolution (live-container verified on PS 8.2.6):
         *   - float: flat rate for EVERY destination (legacy shape)
         *   - array<int,float|float[]>: rate by COUNTRY id; a missing country
         *     resolves 0 (core: no matching TaxRule -> untaxed destination);
         *     a float[] value SUMS (core: combined multi-rate rules stack
         *     additively, e.g. 6% + 2% -> 8%).
         * Group id 0 always resolves 0 (core "No tax" sentinel).
         *
         * @var array<int,float|array<int,float|float[]>>
         */
        public static array $taxRuleRates = [];
        public static array $dbExecuteSResponses = [];
        public static array $dbLastExecuteS = [];
        /** @var string[] Every SQL string passed to Db::getValue() */
        public static array $dbLastGetValue = [];
        /**
         * Substring which, when present in a Db::getValue() query, makes the
         * stub RAISE instead of answering. Core's Db throws
         * PrestaShopDatabaseException on a failed query, and a gate's behaviour
         * on an unanswerable lookup is a real branch worth pinning (TWO-25387).
         *
         * @var string|null
         */
        public static ?string $dbGetValueThrowsOn = null;
        public static array $orderCarriers = [];
        /** @var array<int,array{id_order_state:string,name:string}> Override for OrderState::getOrderStates() */
        public static array $orderStates = [];
        public static array $carts = [];
        /** @var array<string,int> Registered admin tab ids by class name */
        public static array $tabIds = ['AdminTwopaymentInvoice' => 1];
        /** @var string[] Class names passed to Tab::add() */
        public static array $tabAddCalls = [];

        /**
         * Resolve a tax rules group's effective rate for a destination
         * country - the stub twin of core's
         * TaxManagerFactory::getManager($address, $groupId)
         *     ->getTaxCalculator()->getTotalRate().
         * See $taxRuleRates for the fixture shapes.
         */
        public static function resolveTaxRate(int $groupId, int $countryId): float
        {
            if ($groupId <= 0 || !isset(self::$taxRuleRates[$groupId])) {
                return 0.0;
            }
            $entry = self::$taxRuleRates[$groupId];
            if (is_array($entry)) {
                $entry = $entry[$countryId] ?? 0.0;
            }
            if (is_array($entry)) {
                // Combined multi-rate rules stack additively (core-verified).
                $sum = 0.0;
                foreach ($entry as $rate) {
                    $sum += (float) $rate;
                }
                return $sum;
            }

            return (float) $entry;
        }
        /** @var array<int,array> Catalog products by id (surcharge cart-line feature) */
        public static array $products = [];
        /** @var array<int,array> Specific prices by id */
        public static array $specificPrices = [];
        /** @var array<int,array> StockAvailable writes by product id */
        public static array $stock = [];
        /** @var array<int,array> Taxes by id */
        public static array $taxes = [];
        /** @var array<int,array> Tax rules groups by id */
        public static array $taxRulesGroups = [];
        /** @var array<int,array> Tax rules by id */
        public static array $taxRules = [];
        /** @var array<string,bool> Held MySQL advisory locks (GET_LOCK stubs) */
        public static array $dbLocks = [];
        /** @var array<int,int> Last-applied surcharge sync seq by cart id */
        public static array $surchargeSyncSeqs = [];
        /** @var array<int,array{id_order:int,product_id:int}> order_detail rows */
        public static array $orderDetails = [];
        /** @var string[] Every SQL string passed to Db::execute() */
        public static array $dbExecuted = [];
        /** @var array<int,array> Orders by id (controller specs) */
        public static array $orders = [];
        /** @var array<string,string> Existing DB triggers by name => CREATE sql */
        public static array $dbTriggers = [];
        /** @var int Shared auto-increment for ObjectModel-style stubs */
        public static int $nextId = 90000;

        public static function reset(): void
        {
            self::$configuration = [
                'PS_TWO_DEBUG_MODE' => false,
                'PS_TWO_PAYMENT_TERM_TYPE' => 'STANDARD',
                'PS_TWO_ENVIRONMENT' => 'development',
                'PS_OS_SHIPPING' => 4,
                'PS_OS_CANCELED' => 6,
                'PS_TAX' => 1, // core default: taxes enabled shop-wide
            ];
            self::$countries = [34 => 'ES', 47 => 'NO', 56 => 'BE'];
            self::$states = [
                1 => 'Madrid',
                2 => 'Oslo',
            ];
            self::$customers = [];
            self::$currencies = [];
            self::$addresses = [];
            self::$carriers = [];
            self::$cartProducts = [];
            self::$cartMessages = [];
            self::$moduleInstances = [];
            self::$cartTotals = [];
            self::$cartShipping = [];
            self::$cartRules = [];
            self::$cartDeliveryOptionLists = [];
            self::$cartDeliveryOptions = [];
            self::$cartDeliveryOptionListThrows = [];
            self::$orderCarriers = [];
            self::$carts = [];
            self::$tabIds = ['AdminTwopaymentInvoice' => 1];
            self::$tabAddCalls = [];
            self::$moduleCurrencies = [
                'twopayment' => [
                    ['id_currency' => 578], // NOK
                    ['id_currency' => 826], // GBP
                    ['id_currency' => 752], // SEK
                    ['id_currency' => 840], // USD
                    ['id_currency' => 208], // DKK
                    ['id_currency' => 978], // EUR
                ],
            ];
            self::$moduleCountries = null;
            self::$productCategories = [];
            self::$images = [];
            self::$taxRuleRates = [];
            self::$dbExecuteSResponses = [];
            self::$dbLastExecuteS = [];
            self::$dbLastGetValue = [];
            self::$dbGetValueThrowsOn = null;
            self::$products = [];
            self::$specificPrices = [];
            self::$stock = [];
            self::$taxes = [];
            self::$taxRulesGroups = [];
            self::$taxRules = [];
            self::$dbLocks = [];
            self::$surchargeSyncSeqs = [];
            self::$orderDetails = [];
            self::$orderStates = [];
            self::$dbExecuted = [];
            self::$dbTriggers = [];
            self::$orders = [];
            self::$nextId = 90000;

            $context = Context::getContext();
            $context->shop = new Shop();
            $context->cookie = new Cookie();
            $context->link = new Link();
            $context->controller = new \stdClass();
            $context->language = (object) ['id' => 1];
            $context->smarty = new class {
                public function assign($vars): void
                {
                }

                public function fetch($template): string
                {
                    return '';
                }
            };

            if (class_exists('Tools') && method_exists('Tools', 'resetTestValues')) {
                Tools::resetTestValues();
            }
        }
    }

    class PrestaShopException extends Exception
    {
    }

    /**
     * Core's DB failure type. Present here because the payload-build path walks
     * core (TaxManagerFactory, Address, Carrier, DB reads), so it is the shape
     * of "an exception the plugin did NOT raise" reaching the payment
     * controller's catch — and its message carries SQL text that must never be
     * relayed to a buyer (TWO-25161).
     */
    class PrestaShopDatabaseException extends PrestaShopException
    {
    }

    /**
     * Thrown by the front-controller stubs wherever real PrestaShop would
     * redirect-and-exit, so controller specs observe the redirect instead of
     * falling through code the real flow never reaches.
     */
    class StubRedirect extends Exception
    {
    }

    // Guarded the same way phpstan-stubs.php guards its PrestaShop core
    // stand-ins: these three classes are only ever loaded together with the
    // rest of this bootstrap in the offline test process, but the guard is
    // cheap insurance against a "cannot redeclare class" fatal if a future
    // refactor ever loads this file alongside another stub source in the
    // same PHP process.
    if (!class_exists('ModuleFrontController', false)) {
    class ModuleFrontController
    {
        public $module;
        public $context;
        public $errors = [];

        public function __construct()
        {
            $this->context = Context::getContext();
        }

        public function postProcess()
        {
        }

        public function redirectWithNotifications($url)
        {
            throw new StubRedirect((string) $url);
        }
    }
    }

    if (!class_exists('Module', false)) {
    class Module
    {
        public int $id = 1;

        public static function isInstalled($name): bool
        {
            return (string) $name === 'twopayment';
        }

        public static function isEnabled($name): bool
        {
            return (string) $name === 'twopayment';
        }

        /** @return array<int,array{name:string}> */
        public static function getPaymentModules(): array
        {
            return [['name' => 'twopayment']];
        }

        /**
         * Core's instance lookup, as the address-form override uses it
         * (TWO-25326). Null unless a spec registers an instance, which is the
         * override's fail-open path.
         */
        public static function getInstanceByName($name)
        {
            return StubStore::$moduleInstances[(string) $name] ?? null;
        }
    }
    }

    if (!class_exists('PaymentModule', false)) {
    class PaymentModule extends Module
    {
        public string $name = 'twopayment';
        public string $version = '2.4.0';
        public string $displayName = 'Two';
        public $merchant_short_name = 'merchant';
        public $api_key = 'test-api-key';
        public bool $active = true;
        public $languages = [];
        public $context;
        public int $currentOrder = 0;

        public function __construct()
        {
            $this->context = Context::getContext();
        }

        public function l($string)
        {
            return $string;
        }

        public function displayConfirmation($message): string
        {
            return (string) $message;
        }

        public function displayError($message): string
        {
            return (string) $message;
        }

        public function displayWarning($message): string
        {
            return (string) $message;
        }

        public function display($file, $template): string
        {
            return '';
        }

        public function getCurrency($idCurrency = null): array
        {
            $moduleName = property_exists($this, 'name') ? (string) $this->name : '';
            $currencies = StubStore::$moduleCurrencies[$moduleName] ?? [];
            if ($idCurrency === null) {
                return $currencies;
            }

            $idCurrency = (int) $idCurrency;
            $filtered = [];
            foreach ($currencies as $currency) {
                if ((int) ($currency['id_currency'] ?? 0) === $idCurrency) {
                    $filtered[] = $currency;
                }
            }

            return $filtered;
        }
    }
    }

    class Context
    {
        public $cookie;
        public $link;
        public $controller;
        public $cart;
        public $customer;
        public $currency;
        public $language;
        public $smarty;
        // Core has one; the checkout media hook reads its iso_code (TWO-25326).
        // Declared rather than left to a dynamic property, which PHP 8.2+
        // deprecates - and a deprecation notice on every suite run is noise the
        // next real one hides in.
        public $country;
        // Core has one on every request. Declared for the same reason as
        // $country above: the module scopes its module_country lookup to the
        // current shop (TWO-25387), and a dynamic property would raise a PHP
        // 8.2 deprecation on every suite run.
        public $shop;

        private static ?self $instance = null;

        public static function getContext(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
                self::$instance->shop = new Shop();
                self::$instance->cookie = new Cookie();
                self::$instance->link = new Link();
                self::$instance->controller = new \stdClass();
                self::$instance->language = (object) ['id' => 1];
                self::$instance->smarty = new class {
                    public function assign($vars): void
                    {
                    }

                    public function fetch($template): string
                    {
                        return '';
                    }
                };
            }

            return self::$instance;
        }
    }

    #[\AllowDynamicProperties]
    class Cookie
    {
        /**
         * How many times write() was called. Recorded because at least one
         * endpoint's correctness depends on writing the cookie explicitly before
         * it ends the request (TWO-25326: the payment tile renders the sole-trader
         * toggle from that cookie and never resolves it itself), and a stub that
         * silently swallows write() cannot tell that apart from not writing.
         *
         * @var int
         */
        public int $writes = 0;

        public function setExpire(int $timestamp): void
        {
        }

        public function write(): void
        {
            ++$this->writes;
        }
    }

    class Link
    {
        public function getImageLink($rewrite, $idImage, $type): string
        {
            return 'https://img.local/' . $idImage;
        }

        public function getProductLink($idProduct): string
        {
            return 'https://shop.local/product/' . $idProduct;
        }

        public function getModuleLink($module, $controller, $params = [], $ssl = true): string
        {
            $query = http_build_query((array) $params);
            return 'https://shop.local/module/' . $module . '/' . $controller . ($query !== '' ? '?' . $query : '');
        }

        public function getAdminLink($controller, $withToken = true, $sfRouteParams = [], $params = []): string
        {
            $query = http_build_query((array) $params);
            return 'https://shop.local/admin/' . $controller . ($query !== '' ? '?' . $query : '');
        }
    }

    class Validate
    {
        public static function isLoadedObject($object): bool
        {
            if (!is_object($object)) {
                return false;
            }

            if (property_exists($object, 'loaded')) {
                return (bool) $object->loaded;
            }

            return true;
        }

        public static function isEmail($email): bool
        {
            return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        }
    }

    /**
     * Core's order-comment storage. Only the one static reader the module uses;
     * core keys exactly one row per cart.
     */
    class Message
    {
        public static function getMessageByCartId($idCart)
        {
            return StubStore::$cartMessages[(int) $idCart] ?? false;
        }
    }

    class Configuration
    {
        public static function get($key, $default = null)
        {
            return array_key_exists($key, StubStore::$configuration) ? StubStore::$configuration[$key] : $default;
        }

        public static function updateValue($key, $value): bool
        {
            StubStore::$configuration[$key] = $value;
            return true;
        }

        public static function hasKey($key, $idLang = null, $idShopGroup = null, $idShop = null): bool
        {
            return array_key_exists($key, StubStore::$configuration);
        }

        public static function deleteByName($key): bool
        {
            unset(StubStore::$configuration[$key]);
            return true;
        }
    }

    class PrestaShopLogger
    {
        /** @var array<int,array{message:string,severity:int}> */
        public static array $logs = [];

        public static function addLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false): bool
        {
            self::$logs[] = ['message' => (string) $message, 'severity' => (int) $severity];
            return true;
        }

        public static function reset(): void
        {
            self::$logs = [];
        }
    }

    class Tools
    {
        private static array $testValues = [];
        private static bool $hasMediaServer = false;

        public static function hasMediaServer(): bool
        {
            return self::$hasMediaServer;
        }

        public static function setTestHasMediaServer(bool $value): void
        {
            self::$hasMediaServer = $value;
        }

        public static function substr($string, $start, $length = null)
        {
            return $length === null ? substr((string) $string, (int) $start) : substr((string) $string, (int) $start, (int) $length);
        }

        public static function strlen($string): int
        {
            return strlen((string) $string);
        }

        public static function isEmpty($value): bool
        {
            if ($value === null) {
                return true;
            }

            if (is_string($value)) {
                return trim($value) === '';
            }

            return $value === '';
        }

        public static function displayPrice($amount): string
        {
            return number_format((float) $amount, 2, '.', '');
        }

        public static function ps_round($value, $precision = 2): float
        {
            return round((float) $value, (int) $precision);
        }

        public static function getValue($key, $default = null)
        {
            return array_key_exists((string) $key, self::$testValues) ? self::$testValues[(string) $key] : $default;
        }

        public static function setTestValue($key, $value): void
        {
            self::$testValues[(string) $key] = $value;
        }

        public static function resetTestValues(): void
        {
            self::$testValues = [];
            self::$hasMediaServer = false;
        }

        public static function getToken($page = false): string
        {
            return 'token';
        }

        public static function redirect($url): void
        {
            throw new StubRedirect((string) $url);
        }

        public static function strtolower($value): string
        {
            return strtolower((string) $value);
        }

        public static function strtoupper($value): string
        {
            return strtoupper((string) $value);
        }
    }

    class Country
    {
        public static function getIsoById($id)
        {
            return StubStore::$countries[(int) $id] ?? false;
        }

        /**
         * Core's shape: one row per country, with the module only ever reading
         * id_country and iso_code out of it (see the checkout media hook).
         *
         * @return array<int,array{id_country:int,iso_code:string}>
         */
        public static function getCountries($idLang = null, $active = false, $containStates = false, $listStates = true): array
        {
            $rows = [];
            foreach (StubStore::$countries as $id => $iso) {
                // Keyed by id_country, as core keys it: the only consumer reads the
                // id out of the row, but a stub that keys sequentially would let a
                // future caller pass here and fail in production.
                $rows[(int) $id] = ['id_country' => (int) $id, 'iso_code' => (string) $iso];
            }

            return $rows;
        }
    }

    class Media
    {
        /** @var array<string,mixed> the last payload handed to the browser */
        public static array $jsDef = [];

        public static function addJsDef($vars): void
        {
            self::$jsDef = array_merge(self::$jsDef, (array) $vars);
        }

        public static function reset(): void
        {
            self::$jsDef = [];
        }
    }

    class State
    {
        public static function getNameById($id)
        {
            return StubStore::$states[(int) $id] ?? '';
        }
    }

    class Product
    {
        public bool $loaded = false;
        public int $id = 0;
        public $name = [];
        public $link_rewrite = [];
        public $reference = '';
        public $price = 0;
        public $id_tax_rules_group = 0;
        public $active = 0;
        public $available_for_order = 0;
        public $visibility = 'both';
        public $indexed = 1;
        public $is_virtual = 0;
        public $out_of_stock = 0;
        public $minimal_quantity = 1;
        public $id_category_default = 0;

        public function __construct($id = null, $full = false, $idLang = null, $idShop = null)
        {
            $id = (int) $id;
            if ($id > 0 && isset(StubStore::$products[$id])) {
                foreach (StubStore::$products[$id] as $property => $value) {
                    if (property_exists($this, $property)) {
                        $this->$property = $value;
                    }
                }
                $this->id = $id;
                $this->loaded = true;
            }
        }

        /**
         * Core-faithful static resolver: the product's tax rules group id
         * (product+shop scoped; combinations do not carry their own group).
         */
        public static function getIdTaxRulesGroupByIdProduct($idProduct, $context = null)
        {
            $idProduct = (int) $idProduct;
            if (isset(StubStore::$products[$idProduct]['id_tax_rules_group'])) {
                return (int) StubStore::$products[$idProduct]['id_tax_rules_group'];
            }

            return 0;
        }

        public function add(): bool
        {
            $this->id = StubStore::$nextId++;
            $this->loaded = true;
            $this->persist();
            return true;
        }

        public function update($nullValues = false): bool
        {
            $this->persist();
            return true;
        }

        public function delete(): bool
        {
            unset(StubStore::$products[$this->id]);
            return true;
        }

        private function persist(): void
        {
            $data = get_object_vars($this);
            unset($data['loaded']);
            StubStore::$products[$this->id] = $data;
        }

        public static function getProductCategoriesFull($idProduct, $idLang)
        {
            return StubStore::$productCategories[(int) $idProduct] ?? [];
        }
    }

    class SpecificPrice
    {
        public bool $loaded = false;
        public int $id = 0;
        public $id_product = 0;
        public $id_product_attribute = 0;
        public $id_shop = 0;
        public $id_currency = 0;
        public $id_country = 0;
        public $id_group = 0;
        public $id_customer = 0;
        public $id_cart = 0;
        public $price = 0;
        public $from_quantity = 1;
        public $reduction = 0;
        public $reduction_type = 'amount';
        public $reduction_tax = 1;
        public $from = '0000-00-00 00:00:00';
        public $to = '0000-00-00 00:00:00';

        public function __construct($id = null)
        {
            $id = (int) $id;
            if ($id > 0 && isset(StubStore::$specificPrices[$id])) {
                foreach (StubStore::$specificPrices[$id] as $property => $value) {
                    if (property_exists($this, $property)) {
                        $this->$property = $value;
                    }
                }
                $this->id = $id;
                $this->loaded = true;
            }
        }

        public function add(): bool
        {
            $this->id = StubStore::$nextId++;
            $this->loaded = true;
            $this->persist();
            return true;
        }

        public function update($nullValues = false): bool
        {
            $this->persist();
            return true;
        }

        private function persist(): void
        {
            $data = get_object_vars($this);
            unset($data['loaded']);
            StubStore::$specificPrices[$this->id] = $data;
        }

        /** Core-shape result: rows of ['id_specific_price' => n]. */
        public static function getIdsByProductId($idProduct, $idProductAttribute = false, $idCart = 0): array
        {
            $ids = [];
            foreach (StubStore::$specificPrices as $id => $row) {
                if ((int) $row['id_product'] === (int) $idProduct && (int) $row['id_cart'] === (int) $idCart) {
                    $ids[] = ['id_specific_price' => $id];
                }
            }
            return $ids;
        }

        public static function deleteByIdCart($idCart, $idProduct = false, $idProductAttribute = false): bool
        {
            foreach (StubStore::$specificPrices as $id => $row) {
                if ((int) $row['id_cart'] !== (int) $idCart) {
                    continue;
                }
                if ($idProduct !== false && (int) $row['id_product'] !== (int) $idProduct) {
                    continue;
                }
                unset(StubStore::$specificPrices[$id]);
            }
            return true;
        }

        /** Net unit price for a cart-scoped row, or null. */
        public static function getCartUnitPrice(int $idCart, int $idProduct): ?float
        {
            foreach (StubStore::$specificPrices as $row) {
                if ((int) $row['id_cart'] === $idCart && (int) $row['id_product'] === $idProduct) {
                    return (float) $row['price'];
                }
            }
            return null;
        }
    }

    class StockAvailable
    {
        public static function setQuantity($idProduct, $idProductAttribute, $quantity, $idShop = null): void
        {
            StubStore::$stock[(int) $idProduct]['quantity'] = (int) $quantity;
        }

        public static function setProductOutOfStock($idProduct, $outOfStock = false, $idShop = null, $idProductAttribute = 0): void
        {
            StubStore::$stock[(int) $idProduct]['out_of_stock'] = (int) $outOfStock;
        }
    }

    class Tax
    {
        public bool $loaded = false;
        public int $id = 0;
        public $name = [];
        public $rate = 0;
        public $active = 0;

        public function __construct($id = null)
        {
            $id = (int) $id;
            if ($id > 0 && isset(StubStore::$taxes[$id])) {
                foreach (StubStore::$taxes[$id] as $property => $value) {
                    if (property_exists($this, $property)) {
                        $this->$property = $value;
                    }
                }
                $this->id = $id;
                $this->loaded = true;
            }
        }

        public function add(): bool
        {
            $this->id = StubStore::$nextId++;
            $this->loaded = true;
            StubStore::$taxes[$this->id] = ['name' => $this->name, 'rate' => $this->rate, 'active' => $this->active];
            return true;
        }

        public function update($nullValues = false): bool
        {
            StubStore::$taxes[$this->id] = ['name' => $this->name, 'rate' => $this->rate, 'active' => $this->active];
            return true;
        }
    }

    class TaxRulesGroup
    {
        public bool $loaded = false;
        public int $id = 0;
        public $name = '';
        public $active = 0;

        public function __construct($id = null)
        {
            $id = (int) $id;
            if ($id > 0 && isset(StubStore::$taxRulesGroups[$id])) {
                foreach (StubStore::$taxRulesGroups[$id] as $property => $value) {
                    if (property_exists($this, $property)) {
                        $this->$property = $value;
                    }
                }
                $this->id = $id;
                $this->loaded = true;
            }
        }

        public function add(): bool
        {
            $this->id = StubStore::$nextId++;
            $this->loaded = true;
            StubStore::$taxRulesGroups[$this->id] = ['name' => $this->name, 'active' => $this->active];
            return true;
        }

        /** Core-shape rows: id_tax_rules_group / name / active. */
        public static function getTaxRulesGroups($onlyActive = true): array
        {
            $rows = [];
            foreach (StubStore::$taxRulesGroups as $id => $data) {
                if ($onlyActive && empty($data['active'])) {
                    continue;
                }
                $rows[] = [
                    'id_tax_rules_group' => $id,
                    'name' => (string) ($data['name'] ?? ''),
                    'active' => (int) ($data['active'] ?? 0),
                ];
            }
            return $rows;
        }
    }

    class TaxRule
    {
        public bool $loaded = false;
        public int $id = 0;
        public $id_tax_rules_group = 0;
        public $id_country = 0;
        public $id_state = 0;
        public $zipcode_from = 0;
        public $zipcode_to = 0;
        public $id_tax = 0;
        public $behavior = 0;
        public $description = '';

        public function __construct($id = null)
        {
            $id = (int) $id;
            if ($id > 0 && isset(StubStore::$taxRules[$id])) {
                foreach (StubStore::$taxRules[$id] as $property => $value) {
                    if (property_exists($this, $property)) {
                        $this->$property = $value;
                    }
                }
                $this->id = $id;
                $this->loaded = true;
            }
        }

        public function add(): bool
        {
            $this->id = StubStore::$nextId++;
            $this->loaded = true;
            $this->persist();
            return true;
        }

        public function update($nullValues = false): bool
        {
            $this->persist();
            return true;
        }

        private function persist(): void
        {
            $data = get_object_vars($this);
            unset($data['loaded']);
            StubStore::$taxRules[$this->id] = $data;
        }
    }

    class Image
    {
        public static function getCover($idProduct)
        {
            return StubStore::$images[(int) $idProduct] ?? ['id_image' => 1];
        }
    }

    class ImageType
    {
        public static function getFormattedName($name)
        {
            return $name;
        }
    }

    class TaxCalculator
    {
        private float $rate;

        public function __construct(float $rate)
        {
            $this->rate = $rate;
        }

        public function getTotalRate(): float
        {
            return $this->rate;
        }
    }

    class TaxManager
    {
        private int $groupId;
        private int $countryId;

        public function __construct(int $groupId, int $countryId)
        {
            $this->groupId = $groupId;
            $this->countryId = $countryId;
        }

        public function getTaxCalculator(): TaxCalculator
        {
            return new TaxCalculator(StubStore::resolveTaxRate($this->groupId, $this->countryId));
        }
    }

    class TaxManagerFactory
    {
        public static function getManager($address, $taxRulesGroupId): TaxManager
        {
            $countryId = is_object($address) && isset($address->id_country) ? (int) $address->id_country : 0;
            return new TaxManager((int) $taxRulesGroupId, $countryId);
        }
    }

    class Carrier
    {
        public const SHIPPING_METHOD_WEIGHT = 1;
        public const SHIPPING_METHOD_PRICE = 2;

        public bool $loaded = false;
        public string $name = '';
        public $delay = '';
        public int $shipping_method = 0;
        public int $max_delivery_days = 0;
        public int $min_delivery_days = 0;
        private int $taxRulesGroupId = 0;
        /**
         * Core's getIdTaxRulesGroup() is a Db::getValue() on
         * carrier_tax_rules_group_shop behind a Context->shop lookup, so it can
         * raise rather than return. Set 'tax_rules_group_throws' on the fixture
         * to drive that.
         */
        private string $taxRulesGroupThrows = '';

        public function __construct($idCarrier = null, $idLang = null)
        {
            $id = (int) $idCarrier;
            if ($id > 0 && isset(StubStore::$carriers[$id])) {
                $data = StubStore::$carriers[$id];
                $this->loaded = true;
                $this->name = (string) ($data['name'] ?? 'Carrier');
                $this->delay = $data['delay'] ?? '';
                $this->shipping_method = (int) ($data['shipping_method'] ?? 0);
                $this->max_delivery_days = (int) ($data['max_delivery_days'] ?? 0);
                $this->min_delivery_days = (int) ($data['min_delivery_days'] ?? 0);
                $this->taxRulesGroupId = (int) ($data['tax_rules_group_id'] ?? 0);
                $this->taxRulesGroupThrows = (string) ($data['tax_rules_group_throws'] ?? '');
            }
        }

        public function getIdTaxRulesGroup(): int
        {
            if ($this->taxRulesGroupThrows !== '') {
                throw new PrestaShopDatabaseException($this->taxRulesGroupThrows);
            }

            return $this->taxRulesGroupId;
        }
    }

    class Address
    {
        public static array $definition = [
            'fields' => [
                'account_type' => ['validate' => 'isGenericName', 'size' => 32],
                'department' => ['validate' => 'isGenericName', 'size' => 255],
                'project' => ['validate' => 'isGenericName', 'size' => 255],
            ],
        ];

        public bool $loaded = false;
        public int $id = 0;
        public int $id_country = 0;
        public int $id_state = 0;
        public string $company = '';
        public string $companyid = '';
        public string $vat_number = '';
        public string $dni = '';
        public string $address1 = '';
        public string $address2 = '';
        public string $city = '';
        public string $postcode = '';
        public string $phone = '';
        public string $phone_mobile = '';
        public string $department = '';
        public string $project = '';
        public string $account_type = '';

        public function __construct($id = null)
        {
            $id = (int) $id;
            if ($id > 0 && isset(StubStore::$addresses[$id])) {
                foreach (StubStore::$addresses[$id] as $key => $value) {
                    $this->{$key} = $value;
                }
                $this->id = $id;
                $this->loaded = true;
            }
        }
    }

    /**
     * Stub of the parts of core's AddressFormat the module touches (TWO-25326).
     *
     * `$requireFormFieldsList` is core's own public static "default required
     * form fields" list, seeded here with core's real defaults;
     * `getFieldsRequired()` merges it with the merchant's `required_field` table
     * rows exactly as core does (`array_unique(array_merge(...))`).
     * `$fieldsRequiredDatabase` stands in for those rows, and
     * `$addFieldsRequiredDatabaseCalls` counts writes to that table so a spec
     * can assert the module never makes one.
     */
    class AddressFormat
    {
        /** @var string[] */
        public static array $requireFormFieldsList = [
            'firstname',
            'lastname',
            'address1',
            'city',
            'Country:name',
        ];

        /** @var string[] The merchant's own Customers > Addresses selections. */
        public static array $fieldsRequiredDatabase = [];

        public static int $addFieldsRequiredDatabaseCalls = 0;

        /** @return string[] */
        public static function getFieldsRequired(): array
        {
            return array_values(array_unique(array_merge(
                self::$fieldsRequiredDatabase,
                self::$requireFormFieldsList
            )));
        }

        /**
         * Core reaches the table through ObjectModel, not through here - this
         * exists only so a call to it is COUNTABLE rather than silent.
         *
         * @param string[] $fields
         */
        public static function addFieldsRequiredDatabase(array $fields): bool
        {
            ++self::$addFieldsRequiredDatabaseCalls;
            self::$fieldsRequiredDatabase = $fields;

            return true;
        }
    }

    class FormField
    {
        private string $name = '';
        private string $type = 'text';
        private string $label = '';
        private bool $required = false;
        private array $availableValues = [];
        private array $constraints = [];
        private ?int $maxLength = null;
        private $value = null;
        private array $errors = [];

        public function setName($name): self
        {
            $this->name = (string) $name;
            return $this;
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function setType($type): self
        {
            $this->type = (string) $type;
            return $this;
        }

        public function getType(): string
        {
            return $this->type;
        }

        public function setLabel($label): self
        {
            $this->label = (string) $label;
            return $this;
        }

        public function getLabel(): string
        {
            return $this->label;
        }

        public function setRequired($required): self
        {
            $this->required = (bool) $required;
            return $this;
        }

        public function isRequired(): bool
        {
            return $this->required;
        }

        public function addAvailableValue($key, $value): self
        {
            $this->availableValues[(string) $key] = $value;
            return $this;
        }

        public function getAvailableValue($key)
        {
            return $this->availableValues[(string) $key] ?? null;
        }

        public function getAvailableValues(): array
        {
            return $this->availableValues;
        }

        public function addConstraint($constraint): self
        {
            $this->constraints[] = $constraint;
            return $this;
        }

        public function setMaxLength($maxLength): self
        {
            $this->maxLength = (int) $maxLength;
            return $this;
        }

        public function setValue($value): self
        {
            $this->value = $value;
            return $this;
        }

        public function getValue()
        {
            return $this->value;
        }

        /**
         * Core's FormField carries the field's submitted value and its own
         * error list; AbstractForm::validate() reads the first and writes the
         * second. Modelled here because the phone-required gate (TWO-25326) is
         * only observable through that pair.
         */
        public function addError($error): self
        {
            $this->errors[] = (string) $error;
            return $this;
        }

        public function getErrors(): array
        {
            return $this->errors;
        }
    }

    class Customer
    {
        public bool $loaded = false;
        public int $id = 0;
        public string $email = '';
        public string $firstname = '';
        public string $lastname = '';
        public string $secure_key = 'secure';

        public function __construct($id = null)
        {
            $id = (int) $id;
            if ($id > 0 && isset(StubStore::$customers[$id])) {
                foreach (StubStore::$customers[$id] as $key => $value) {
                    $this->{$key} = $value;
                }
                $this->id = $id;
                $this->loaded = true;
            }
        }
    }

    class Currency
    {
        public bool $loaded = false;
        public int $id = 0;
        public string $iso_code = 'EUR';
        /** Units of this currency per 1 unit of the shop default currency. */
        public float $conversion_rate = 1.0;
        public string $symbol = '';
        public string $sign = '';

        public function __construct($id = null)
        {
            $id = (int) $id;
            if ($id > 0 && isset(StubStore::$currencies[$id])) {
                foreach (StubStore::$currencies[$id] as $key => $value) {
                    $this->{$key} = $value;
                }
                $this->id = $id;
                $this->loaded = true;
            }
        }

        public static function getIdByIsoCode($isoCode, $idShop = 0)
        {
            foreach (StubStore::$currencies as $id => $props) {
                if (strcasecmp((string) ($props['iso_code'] ?? ''), (string) $isoCode) === 0) {
                    return (int) $id;
                }
            }
            return 0;
        }
    }

    class Cart
    {
        public const ONLY_DISCOUNTS = 1;
        public const ONLY_SHIPPING = 2;
        public const BOTH = 3;
        public const ONLY_WRAPPING = 4;

        public bool $loaded = true;
        public int $id = 0;
        public int $id_customer = 0;
        public int $id_currency = 0;
        public int $id_address_invoice = 0;
        public int $id_address_delivery = 0;
        public int $id_carrier = 0;
        public int $id_lang = 1;

        public function __construct($id = null)
        {
            $id = (int) $id;
            if ($id > 0) {
                $this->id = $id;
                // Hydrate by id so code that constructs its own Cart from
                // an order (getTwoUpdateOrderData) sees the fixture.
                foreach (StubStore::$carts[$id] ?? [] as $property => $value) {
                    $this->$property = $value;
                }
            }
        }

        public function nbProducts(): int
        {
            return count(StubStore::$cartProducts[$this->id] ?? []);
        }

        public function getProducts($refresh = false): array
        {
            return StubStore::$cartProducts[$this->id] ?? [];
        }

        public function containsProduct($idProduct, $idProductAttribute = 0, $idCustomization = false, $idAddressDelivery = 0)
        {
            foreach (StubStore::$cartProducts[$this->id] ?? [] as $row) {
                if ((int) $row['id_product'] === (int) $idProduct) {
                    return ['quantity' => (int) $row['cart_quantity']];
                }
            }
            return false;
        }

        /**
         * Minimal core-faithful updateQty: prices a NEW line from the
         * cart-scoped SpecificPrice (net) and the product's tax rules group
         * rate (gross), mirroring what PS core computes for the hidden
         * surcharge product. Repeat 'up' calls INCREMENT quantity - exactly
         * like core - so idempotency must come from the module, not the stub.
         */
        public function updateQty($quantity, $idProduct, $idProductAttribute = null, $idCustomization = false, $operator = 'up')
        {
            $idProduct = (int) $idProduct;
            $quantity = (int) $quantity;
            $rows = StubStore::$cartProducts[$this->id] ?? [];

            $net = SpecificPrice::getCartUnitPrice((int) $this->id, $idProduct);
            $product = new Product($idProduct);
            if ($net === null) {
                $net = $product->loaded ? (float) $product->price : 0.0;
            }
            // Destination-based rate, exactly like core Product::priceCalculation:
            // the product's tax rules group resolved against the cart's
            // PS_TAX_ADDRESS_TYPE address (invoice unless configured delivery).
            $taxAddressField = Configuration::get('PS_TAX_ADDRESS_TYPE') === 'id_address_delivery'
                ? 'id_address_delivery'
                : 'id_address_invoice';
            $taxAddress = new Address((int) $this->{$taxAddressField});
            // vatnumber-module B2B exemption, exactly like core
            // Product::priceCalculation: VAT number present, buyer country
            // differs from the module's configured country, management on.
            $vatExempt = !empty($taxAddress->vat_number)
                && (int) $taxAddress->id_country !== (int) Configuration::get('VATNUMBER_COUNTRY')
                && Configuration::get('VATNUMBER_MANAGEMENT');
            $rate = (Configuration::get('PS_TAX') && !$vatExempt)
                ? StubStore::resolveTaxRate((int) $product->id_tax_rules_group, (int) $taxAddress->id_country)
                : 0.0;

            $found = false;
            foreach ($rows as $i => $row) {
                if ((int) $row['id_product'] === $idProduct) {
                    $newQty = (int) $row['cart_quantity'] + ($operator === 'up' ? $quantity : -$quantity);
                    $this->applyCartTotalsDelta(-(float) $row['total'], -(float) $row['total_wt']);
                    if ($newQty <= 0) {
                        unset($rows[$i]);
                    } else {
                        $rows[$i]['cart_quantity'] = $newQty;
                        $rows[$i]['total'] = round($net * $newQty, 2);
                        $rows[$i]['total_wt'] = round($net * $newQty * (1 + $rate / 100), 2);
                        $this->applyCartTotalsDelta((float) $rows[$i]['total'], (float) $rows[$i]['total_wt']);
                    }
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                if ($operator !== 'up') {
                    return false;
                }
                $langId = 1;
                $name = 'Product ' . $idProduct;
                if ($product->loaded && is_array($product->name)) {
                    $name = (string) (reset($product->name) ?: $name);
                }
                $rewrite = 'product-' . $idProduct;
                if ($product->loaded && is_array($product->link_rewrite)) {
                    $rewrite = (string) (reset($product->link_rewrite) ?: $rewrite);
                }
                $row = [
                    'id_product' => $idProduct,
                    'link_rewrite' => $rewrite,
                    'name' => $name,
                    'description_short' => '',
                    'manufacturer_name' => '',
                    'ean13' => '',
                    'upc' => '',
                    'cart_quantity' => $quantity,
                    'price' => $net,
                    'total' => round($net * $quantity, 2),
                    'total_wt' => round($net * $quantity * (1 + $rate / 100), 2),
                    'rate' => $rate,
                    'reduction' => 0,
                    'is_virtual' => $product->loaded ? (int) $product->is_virtual : 0,
                ];
                $rows[] = $row;
                $this->applyCartTotalsDelta((float) $row['total'], (float) $row['total_wt']);
            }

            StubStore::$cartProducts[$this->id] = array_values($rows);
            return true;
        }

        public function deleteProduct($idProduct, $idProductAttribute = 0, $idCustomization = 0, $idAddressDelivery = 0)
        {
            $rows = StubStore::$cartProducts[$this->id] ?? [];
            foreach ($rows as $i => $row) {
                if ((int) $row['id_product'] === (int) $idProduct) {
                    $this->applyCartTotalsDelta(-(float) $row['total'], -(float) $row['total_wt']);
                    unset($rows[$i]);
                }
            }
            StubStore::$cartProducts[$this->id] = array_values($rows);
            return true;
        }

        private function applyCartTotalsDelta(float $netDelta, float $grossDelta): void
        {
            if (isset(StubStore::$cartTotals[$this->id][false][self::BOTH])) {
                StubStore::$cartTotals[$this->id][false][self::BOTH] = round(
                    (float) StubStore::$cartTotals[$this->id][false][self::BOTH] + $netDelta,
                    2
                );
            }
            if (isset(StubStore::$cartTotals[$this->id][true][self::BOTH])) {
                StubStore::$cartTotals[$this->id][true][self::BOTH] = round(
                    (float) StubStore::$cartTotals[$this->id][true][self::BOTH] + $grossDelta,
                    2
                );
            }
        }

        public function getOrderTotal($withTaxes, $type)
        {
            $withTaxes = (bool) $withTaxes;
            if (isset(StubStore::$cartTotals[$this->id][$withTaxes][$type])) {
                return StubStore::$cartTotals[$this->id][$withTaxes][$type];
            }
            return 0.0;
        }

        public function getPackageShippingCost($idCarrier, $useTax, $defaultCountry = null, $productList = null, $idZone = null)
        {
            $useTax = (bool) $useTax;
            if (isset(StubStore::$cartShipping[$this->id][$useTax])) {
                return StubStore::$cartShipping[$this->id][$useTax];
            }
            return 0.0;
        }

        public function getCartRules(): array
        {
            return StubStore::$cartRules[$this->id] ?? [];
        }

        public function getDeliveryOptionList($defaultCountry = null, $flush = false): array
        {
            if (isset(StubStore::$cartDeliveryOptionListThrows[$this->id])) {
                throw new PrestaShopDatabaseException(StubStore::$cartDeliveryOptionListThrows[$this->id]);
            }

            return StubStore::$cartDeliveryOptionLists[$this->id] ?? [];
        }

        /**
         * Core-faithful enough for the plugin's use: an explicit fixture wins,
         * otherwise auto-select the first option for each address, which is
         * what core falls back to when nothing is selected or the selection no
         * longer validates.
         *
         * @return array<int,string>|false
         */
        public function getDeliveryOption($defaultCountry = null, $dontAutoSelectOptions = false, $useCache = true)
        {
            // Core-faithful: getDeliveryOption() calls getDeliveryOptionList()
            // first, so a raise from the list build surfaces here too.
            if (isset(StubStore::$cartDeliveryOptionListThrows[$this->id])) {
                throw new PrestaShopDatabaseException(StubStore::$cartDeliveryOptionListThrows[$this->id]);
            }

            if (array_key_exists($this->id, StubStore::$cartDeliveryOptions)) {
                return StubStore::$cartDeliveryOptions[$this->id];
            }

            $selected = [];
            foreach (StubStore::$cartDeliveryOptionLists[$this->id] ?? [] as $idAddress => $options) {
                $keys = array_keys((array) $options);
                if ($keys !== []) {
                    $selected[(int) $idAddress] = (string) $keys[0];
                }
            }

            return $selected;
        }

        public function getAverageProductsTaxRate(): float
        {
            return (float) (StubStore::$cartTotals[$this->id]['average_products_tax_rate'] ?? 0.0);
        }
    }

    class Language
    {
        public static function getLanguages($active = false): array
        {
            return [];
        }
    }

    class Shop
    {
        public const CONTEXT_ALL = 1;

        /** Core's per-shop id; 1 is the default single-shop install. */
        public int $id = 1;

        public static function isFeatureActive(): bool
        {
            return false;
        }

        public static function setContext($context): void
        {
        }
    }

    class Db
    {
        public static function getInstance($useMaster = true): self
        {
            return new self();
        }

        public function execute($sql): bool
        {
            $sql = (string) $sql;
            StubStore::$dbExecuted[] = $sql;
            if (preg_match('/REPLACE INTO `ps_twopayment_surcharge_sync` \(`id_cart`, `seq`, `updated_at`\) VALUES \((\d+), (\d+)/', $sql, $m)) {
                StubStore::$surchargeSyncSeqs[(int) $m[1]] = (int) $m[2];
            }
            // Trigger bookkeeping so specs can assert the DB-enforcement DDL
            // is issued. The stub CANNOT evaluate trigger semantics (no SQL
            // engine); rejection behaviour is live-container verified only.
            if (preg_match('/^\s*CREATE TRIGGER `([^`]+)`/', $sql, $m)) {
                StubStore::$dbTriggers[$m[1]] = $sql;
            }
            if (preg_match('/^\s*DROP TRIGGER IF EXISTS `([^`]+)`/', $sql, $m)) {
                unset(StubStore::$dbTriggers[$m[1]]);
            }
            return true;
        }

        /**
         * Recognises exactly the scalar queries the module issues; anything
         * else answers false (core Db returns false on empty results).
         */
        public function getValue($sql)
        {
            $sql = (string) $sql;
            StubStore::$dbLastGetValue[] = $sql;
            if (StubStore::$dbGetValueThrowsOn !== null
                && strpos($sql, StubStore::$dbGetValueThrowsOn) !== false
            ) {
                throw new PrestaShopDatabaseException('stubbed query failure: ' . $sql);
            }
            // Core's Db::getValue() delegates to getRow(), which appends its OWN
            // ' LIMIT 1' - its docblock documents the argument as "the select
            // query (without LIMIT 1)". A caller that supplies one produces
            // `LIMIT 1 LIMIT 1` and a real MariaDB syntax error. The stub used
            // to accept it silently, so that bug could only be caught by the
            // Playwright e2e job against a live shop; it shipped once
            // (TWO-25387) and cost a full CI round. Reproduce the fatal here.
            if (preg_match('/\bLIMIT\s+\d+\s*;?\s*$/i', $sql)) {
                throw new PrestaShopDatabaseException(
                    'Db::getValue() appends its own LIMIT 1 - the query must not carry one: ' . $sql
                );
            }
            if (preg_match("/GET_LOCK\\('([^']+)'/", $sql, $m)) {
                if (!empty(StubStore::$dbLocks[$m[1]])) {
                    return '0'; // held by a simulated concurrent request
                }
                StubStore::$dbLocks[$m[1]] = true;
                return '1';
            }
            if (preg_match("/RELEASE_LOCK\\('([^']+)'/", $sql, $m)) {
                unset(StubStore::$dbLocks[$m[1]]);
                return '1';
            }
            if (preg_match('/SELECT `seq` FROM `ps_twopayment_surcharge_sync` WHERE `id_cart` = (\d+)/', $sql, $m)) {
                return StubStore::$surchargeSyncSeqs[(int) $m[1]] ?? false;
            }
            if (preg_match('/SELECT COUNT\(\*\) FROM `ps_order_detail` WHERE `id_order` = (\d+) AND `product_id` = (\d+)/', $sql, $m)) {
                $count = 0;
                foreach (StubStore::$orderDetails as $row) {
                    if ((int) $row['id_order'] === (int) $m[1] && (int) $row['product_id'] === (int) $m[2]) {
                        $count++;
                    }
                }
                return (string) $count;
            }
            if (preg_match("/SELECT `value` FROM `ps_configuration` WHERE `name` = '([A-Za-z0-9_]+)'/", $sql, $m)) {
                return StubStore::$configuration[$m[1]] ?? false;
            }
            if (preg_match("/FROM information_schema\.TRIGGERS.*TRIGGER_NAME = '([^']+)'/s", $sql, $m)) {
                return isset(StubStore::$dbTriggers[$m[1]]) ? '1' : '0';
            }
            // Native per-module payment restrictions (TWO-25387). Core returns the
            // matched id_country, or false when no row matches.
            if (preg_match(
                '/SELECT `id_country` FROM `' . _DB_PREFIX_ . 'module_country`'
                . ' WHERE `id_module` = (\d+) AND `id_country` = (\d+) AND `id_shop` = (\d+)/',
                $sql,
                $m
            )) {
                if (StubStore::$moduleCountries === null) {
                    return $m[2]; // unrestricted - see StubStore::$moduleCountries
                }
                foreach (StubStore::$moduleCountries as $row) {
                    if ((int) ($row['id_module'] ?? 0) === (int) $m[1]
                        && (int) ($row['id_country'] ?? 0) === (int) $m[2]
                        && (int) ($row['id_shop'] ?? 0) === (int) $m[3]
                    ) {
                        return $m[2];
                    }
                }
                return false;
            }
            return false;
        }

        public function executeS($sql): array
        {
            StubStore::$dbLastExecuteS[] = (string) $sql;
            if (!empty(StubStore::$dbExecuteSResponses)) {
                $next = array_shift(StubStore::$dbExecuteSResponses);
                return is_array($next) ? $next : [];
            }
            return [];
        }

        public function insert($table, $data): bool
        {
            return true;
        }

        public function update($table, $data, $where): bool
        {
            return true;
        }
    }

    class Order
    {
        public $id = 0;
        public $id_cart = 0;
        public $id_customer = 0;
        public $total_paid = 0.0;
        public bool $loaded = false;

        public function __construct($id = 0)
        {
            $id = (int) $id;
            if ($id > 0 && isset(StubStore::$orders[$id])) {
                $row = StubStore::$orders[$id];
                $this->id = $id;
                $this->loaded = true;
                $this->id_cart = (int) ($row['id_cart'] ?? 0);
                $this->id_customer = (int) ($row['id_customer'] ?? 0);
                $this->total_paid = (float) ($row['total_paid'] ?? 0.0);
            }
        }
    }

    #[\AllowDynamicProperties]
    class OrderState
    {
        public bool $loaded = true;
        public int $id = 1;
        /** @var string|array<int,string> String once loaded by id; array<id_lang,string> while being built. */
        public $name = [];
        public int $invoice = 0;
        public int $delivery = 0;
        public int $shipped = 0;
        public int $paid = 0;
        public int $logable = 0;
        public int $send_email = 0;
        public string $template = '';
        public string $color = '';
        public int $hidden = 0;

        public function __construct($id = 1, $idLang = null)
        {
            $id = (int)$id;
            if ($id > 0) {
                $this->id = $id;
            }

            $shipping_status = (int)(StubStore::$configuration['PS_OS_SHIPPING'] ?? 4);
            $cancelled_status = (int)(StubStore::$configuration['PS_OS_CANCELED'] ?? 6);
            $two_cancelled_status = (int)(StubStore::$configuration['PS_TWO_OS_CANCELLED'] ?? 0);
            $two_cancelled_map = (int)(StubStore::$configuration['PS_TWO_OS_CANCELLED_MAP'] ?? 0);

            if ($this->id === $shipping_status) {
                $this->name = 'Shipped';
                $this->shipped = 1;
                $this->logable = 1;
                return;
            }

            if ($this->id === $cancelled_status || ($two_cancelled_status > 0 && $this->id === $two_cancelled_status) || ($two_cancelled_map > 0 && $this->id === $two_cancelled_map)) {
                $this->name = 'Cancelled';
                $this->shipped = 0;
                $this->logable = 0;
                return;
            }
        }

        public function add(): bool
        {
            // Core assigns a fresh auto-increment id on insert. Returning a
            // distinct id per insert matters for createTwoOrderState(), which
            // creates six states in one pass and stores each id in configuration.
            $this->id = StubStore::$nextId++;

            return true;
        }

        /**
         * Mirrors OrderStateCore::getOrderStates(): id_order_state comes back from
         * the database as a string, which matters for pre-selection comparisons.
         *
         * @return array<int,array{id_order_state:string,name:string}>
         */
        public static function getOrderStates($idLang = null): array
        {
            if (!empty(StubStore::$orderStates)) {
                return StubStore::$orderStates;
            }

            return [
                ['id_order_state' => '2', 'name' => 'Payment accepted'],
                ['id_order_state' => '3', 'name' => 'Processing in progress'],
                ['id_order_state' => '4', 'name' => 'Shipped'],
                ['id_order_state' => '5', 'name' => 'Delivered'],
            ];
        }
    }

    #[\AllowDynamicProperties]
    class Tab
    {
        public $id = 0;
        public $class_name = '';
        public $module = '';
        public $id_parent = 0;
        public $active = 0;
        public $name = [];

        public function __construct($id = null)
        {
            $id = (int) $id;
            if ($id > 0) {
                $this->id = $id;
                $className = array_search($id, StubStore::$tabIds, true);
                if ($className !== false) {
                    $this->class_name = (string) $className;
                }
            }
        }

        public static function getIdFromClassName($className): int
        {
            return (int) (StubStore::$tabIds[(string) $className] ?? 0);
        }

        public function add(): bool
        {
            StubStore::$tabAddCalls[] = (string) $this->class_name;
            $this->id = count(StubStore::$tabIds) + 1;
            StubStore::$tabIds[(string) $this->class_name] = $this->id;
            return true;
        }

        public function delete(): bool
        {
            if ($this->class_name !== '') {
                unset(StubStore::$tabIds[$this->class_name]);
            }
            return true;
        }
    }

    require_once dirname(__DIR__) . '/twopayment.php';

    class OrderCarrier
    {
        public bool $loaded = false;
        public $tracking_number = '';

        public function __construct($id = null)
        {
            $id = (int) $id;
            if ($id > 0 && isset(StubStore::$orderCarriers[$id])) {
                $data = StubStore::$orderCarriers[$id];
                $this->loaded = true;
                $this->tracking_number = $data['tracking_number'] ?? '';
            }
        }
    }

    #[\AllowDynamicProperties]
    class TwopaymentTestHarness extends Twopayment
    {
        public function __construct()
        {
            $this->context = Context::getContext();
            $this->name = 'twopayment';
            $this->version = '2.4.0';
            $this->merchant_short_name = 'merchant';
            $this->api_key = 'test-api-key';
            $this->languages = [['id_lang' => 1]];
            $this->active = true;
            // Mirrors PrestaShop's real Module::$local_path: absolute path to
            // this module's own directory, trailing slash included. Used by
            // getTwoVersionedAssetPath() to filemtime() the real asset files.
            $this->local_path = dirname(__DIR__) . '/';
            // A verified API key by default (TWO-25326). Every checkout gate
            // now consults that verdict, and a harness without one would
            // either take the whole suite to the network on a cache miss or
            // silently hide the payment option from every unrelated spec.
            // ApiKeyVerificationSpec drives the real thing by clearing this
            // and stubbing the wire call.
            $this->primeTwoApiKeyStatus(Twopayment::API_KEY_STATUS_OK, 200);
        }

        /**
         * Sets (or, with null, clears) the request-scoped verification memo the
         * checkout gates read, without going near the network.
         *
         * @param string|null $status
         * @param int|null    $code
         */
        public function primeTwoApiKeyStatus($status, $code = null): void
        {
            $this->twoApiKeyStatusMemo = $status === null
                ? null
                : array('status' => (string) $status, 'code' => $code);
        }

        public function l($string)
        {
            return $string;
        }
    }

    StubStore::reset();
}
