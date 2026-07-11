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
    class PaymentOption
    {
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
        public static array $cartTotals = [];
        public static array $cartShipping = [];
        public static array $cartRules = [];
        public static array $moduleCurrencies = [];
        public static array $productCategories = [];
        public static array $images = [];
        public static array $taxRuleRates = [];
        public static array $dbExecuteSResponses = [];
        public static array $dbLastExecuteS = [];
        public static array $orderCarriers = [];
        public static array $carts = [];
        /** @var array<string,int> Registered admin tab ids by class name */
        public static array $tabIds = ['AdminTwopaymentInvoice' => 1];
        /** @var string[] Class names passed to Tab::add() */
        public static array $tabAddCalls = [];
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
            self::$cartTotals = [];
            self::$cartShipping = [];
            self::$cartRules = [];
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
            self::$productCategories = [];
            self::$images = [];
            self::$taxRuleRates = [];
            self::$dbExecuteSResponses = [];
            self::$dbLastExecuteS = [];
            self::$products = [];
            self::$specificPrices = [];
            self::$stock = [];
            self::$taxes = [];
            self::$taxRulesGroups = [];
            self::$taxRules = [];
            self::$dbLocks = [];
            self::$surchargeSyncSeqs = [];
            self::$orderDetails = [];
            self::$nextId = 90000;

            $context = Context::getContext();
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
    }

    class PaymentModule extends Module
    {
        public string $name = 'twopayment';
        public string $version = '2.4.0';
        public string $displayName = 'Two';
        public string $merchant_short_name = 'merchant';
        public string $api_key = 'test-api-key';
        public bool $active = true;
        public array $languages = [];
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

    class Context
    {
        public $cookie;
        public $link;
        public $controller;
        public $cart;
        public $language;
        public $smarty;

        private static ?self $instance = null;

        public static function getContext(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
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
        public function setExpire(int $timestamp): void
        {
        }

        public function write(): void
        {
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
        }

        public static function getToken($page = false): string
        {
            return 'token';
        }

        public static function strtolower($value): string
        {
            return strtolower((string) $value);
        }
    }

    class Country
    {
        public static function getIsoById($id)
        {
            return StubStore::$countries[(int) $id] ?? false;
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
            // Mirror core behavior for the TaxManager stub: the group now
            // applies the referenced tax's rate.
            $tax = new Tax((int) $this->id_tax);
            StubStore::$taxRuleRates[(int) $this->id_tax_rules_group] = (float) $tax->rate;
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

        public function __construct(int $groupId)
        {
            $this->groupId = $groupId;
        }

        public function getTaxCalculator(): TaxCalculator
        {
            return new TaxCalculator((float) (StubStore::$taxRuleRates[$this->groupId] ?? 0.0));
        }
    }

    class TaxManagerFactory
    {
        public static function getManager($address, $taxRulesGroupId): TaxManager
        {
            return new TaxManager((int) $taxRulesGroupId);
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
            }
        }

        public function getIdTaxRulesGroup(): int
        {
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

    class FormField
    {
        private string $name = '';
        private string $type = 'text';
        private string $label = '';
        private bool $required = false;
        private array $availableValues = [];
        private array $constraints = [];
        private ?int $maxLength = null;

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
            $rate = (float) (StubStore::$taxRuleRates[(int) $product->id_tax_rules_group] ?? 0.0);

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
            if (preg_match('/REPLACE INTO `ps_twopayment_surcharge_sync` \(`id_cart`, `seq`, `updated_at`\) VALUES \((\d+), (\d+)/', $sql, $m)) {
                StubStore::$surchargeSyncSeqs[(int) $m[1]] = (int) $m[2];
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

    class OrderState
    {
        public bool $loaded = true;
        public int $id = 1;
        public $name = '';
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
            return true;
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
        }

        public function l($string)
        {
            return $string;
        }
    }

    StubStore::reset();
}
