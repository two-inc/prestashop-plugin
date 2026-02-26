<?php

declare(strict_types=1);

namespace {
    if (!defined('_PS_VERSION_')) {
        define('_PS_VERSION_', '8.0.0');
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
        public static array $productCategories = [];
        public static array $images = [];
        public static array $taxRuleRates = [];

        public static function reset(): void
        {
            self::$configuration = [
                'PS_TWO_DEBUG_MODE' => false,
                'PS_TWO_PAYMENT_TERM_TYPE' => 'STANDARD',
                'PS_TWO_ENVIRONMENT' => 'development',
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
            self::$productCategories = [];
            self::$images = [];
            self::$taxRuleRates = [];

            $context = Context::getContext();
            $context->cookie = new Cookie();
            $context->link = new Link();
            $context->controller = new \stdClass();
        }
    }

    class Module
    {
        public int $id = 1;
    }

    class PaymentModule extends Module
    {
        public string $name = 'twopayment';
        public string $version = '2.4.0';
        public string $displayName = 'Two';
        public string $merchant_short_name = 'merchant';
        public string $api_key = 'test-api-key';
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
    }

    class Context
    {
        public $cookie;
        public $link;
        public $controller;

        private static ?self $instance = null;

        public static function getContext(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
                self::$instance->cookie = new Cookie();
                self::$instance->link = new Link();
                self::$instance->controller = new \stdClass();
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
        public static function addLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false): bool
        {
            return true;
        }
    }

    class Tools
    {
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
        public static function getProductCategoriesFull($idProduct, $idLang)
        {
            return StubStore::$productCategories[(int) $idProduct] ?? [];
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
            return true;
        }

        public function executeS($sql): array
        {
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
        public array $name = [];

        public function add(): bool
        {
            return true;
        }
    }

    class Tab
    {
        public static function getIdFromClassName($className): int
        {
            return 1;
        }
    }

    require_once dirname(__DIR__) . '/twopayment.php';

    class TwopaymentTestHarness extends Twopayment
    {
        public function __construct()
        {
            $this->context = Context::getContext();
            $this->name = 'twopayment';
            $this->version = '2.4.0';
            $this->merchant_short_name = 'merchant';
            $this->api_key = 'test-api-key';
        }

        public function l($string)
        {
            return $string;
        }
    }

    StubStore::reset();
}
