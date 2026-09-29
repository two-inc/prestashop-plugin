<?php

/**
 * INTEGRATION PROBE - order update across a multi-carrier split (TWO-26085),
 * against a real PrestaShop engine.
 *
 * A cart whose products can only ship with different carriers is split by
 * PaymentModule::validateOrder() into one order per carrier. They share the
 * reference and one OrderPayment for the whole amount, while only the order
 * validateOrder() returns gets the module's Two row. The Two order covers the
 * whole cart, so an update, from whichever of the orders, must carry all of
 * them, and a back-office edit must leave the shared payment at their total.
 *
 * This probe builds its own taxes, carriers, products, buyer and cart through
 * ObjectModel, places the order through validateOrder(), records a Two row on
 * the returned order as the confirmation does, then fires the back-office edit
 * hook on the OTHER order with the API call captured.
 *
 * Hermetic: no browser, no network, no Two credentials. Needs only the module
 * installed.
 *
 * Usage: php tests/integration/multi-carrier-update.php
 */

if (!defined('_PS_VERSION_')) {
    require '/var/www/html/config/config.inc.php';
}
require_once _PS_MODULE_DIR_ . 'twopayment/twopayment.php';

/**
 * Same kernel boot as line-item-image.php: PrestaShop 9 prices a cart through a
 * Symfony service, and a CLI request has no container until one is booted.
 *
 * @return void
 */
function multiCarrierProbeBootKernel()
{
    if (PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance() !== null) {
        return;
    }
    foreach (array('FrontKernel', 'AppKernel') as $class) {
        if (!class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }
        $kernel = new $class(defined('_PS_MODE_DEV_') && _PS_MODE_DEV_ ? 'dev' : 'prod', false);
        $kernel->boot();
        $GLOBALS['kernel'] = $kernel;
        PrestaShop\PrestaShop\Adapter\SymfonyContainer::resetStaticCache();
        Context::getContext()->container = $kernel->getContainer();

        return;
    }
    throw new RuntimeException('no bootable PrestaShop kernel class found (tried FrontKernel, AppKernel)');
}

/**
 * @param float $rate
 * @param int $id_country
 * @param int $id_lang
 * @return int tax rules group id
 */
function multiCarrierProbeTaxGroup($rate, $id_country, $id_lang)
{
    $tax = new Tax();
    $tax->name = array($id_lang => 'Probe VAT ' . $rate);
    $tax->rate = $rate;
    $tax->active = 1;
    $tax->add();
    $group = new TaxRulesGroup();
    $group->name = 'Probe VAT ' . $rate;
    $group->active = 1;
    $group->add();
    $rule = new TaxRule();
    $rule->id_tax_rules_group = (int) $group->id;
    $rule->id_country = $id_country;
    $rule->id_tax = (int) $tax->id;
    $rule->add();

    return (int) $group->id;
}

/**
 * @param string $name
 * @param float $price
 * @param int $id_tax_rules_group
 * @param int $id_zone
 * @return Carrier
 */
function multiCarrierProbeCarrier($name, $price, $id_tax_rules_group, $id_zone)
{
    $carrier = new Carrier();
    $carrier->name = $name;
    $carrier->delay = array((int) Configuration::get('PS_LANG_DEFAULT') => 'Probe');
    $carrier->active = 1;
    $carrier->is_free = 0;
    $carrier->shipping_handling = 0;
    $carrier->range_behavior = 0;
    $carrier->shipping_method = Carrier::SHIPPING_METHOD_PRICE;
    $carrier->add();
    $carrier->id_reference = (int) $carrier->id;
    $carrier->update();
    $carrier->addZone($id_zone);
    $carrier->setGroups(array_column(Group::getGroups((int) Configuration::get('PS_LANG_DEFAULT')), 'id_group'));
    $carrier->setTaxRulesGroup($id_tax_rules_group);
    $range = new RangePrice();
    $range->id_carrier = (int) $carrier->id;
    $range->delimiter1 = 0;
    $range->delimiter2 = 1000000;
    $range->add();
    // RangePrice::add() writes a zero-priced shop row; a CLI run in all-shops context reads the shopless row instead.
    Db::getInstance()->update('delivery', array('price' => $price), 'id_carrier = ' . (int) $carrier->id . ' AND id_range_price = ' . (int) $range->id);
    Db::getInstance()->insert('delivery', array(
        'id_carrier' => (int) $carrier->id,
        'id_range_price' => (int) $range->id,
        'id_range_weight' => null,
        'id_zone' => $id_zone,
        'price' => $price,
    ), true);

    return $carrier;
}

/**
 * @param string $name
 * @param float $price
 * @param int $id_tax_rules_group
 * @param Carrier $carrier the only carrier it may ship with
 * @param int $id_lang
 * @return Product
 */
function multiCarrierProbeProduct($name, $price, $id_tax_rules_group, $carrier, $id_lang)
{
    $product = new Product();
    $product->name = array($id_lang => $name);
    $product->link_rewrite = array($id_lang => Tools::str2url($name));
    $product->price = $price;
    $product->id_tax_rules_group = $id_tax_rules_group;
    $product->active = 1;
    $product->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
    $product->add();
    $product->addToCategories(array((int) Configuration::get('PS_HOME_CATEGORY')));
    $product->setCarriers(array((int) $carrier->id_reference));
    // No stock movement: PrestaShop 1.7 refuses one from a CLI run (see line-item-image.php).
    StockAvailable::setQuantity((int) $product->id, 0, 100, null, false);

    return $product;
}

class MultiCarrierProbeModule extends Twopayment
{
    /** @var array[] */
    public $puts = array();

    public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
    {
        if ($method === 'PUT') {
            $this->puts[] = $payload;

            return array('http_status' => 200);
        }

        return parent::setTwoPaymentRequest($endpoint, $payload, $method, $additional_headers, $timeout);
    }
}

multiCarrierProbeBootKernel();
// No mail transport in the probe container, and no stock movement from validateOrder() on 1.7.
Configuration::updateValue('PS_MAIL_METHOD', 3);
Configuration::updateValue('PS_STOCK_MANAGEMENT', 0);
// The carrier-less fixture, where CI seeds it armed, replaces every delivery option with its own: disarm it for this run.
$armed_gross = Configuration::get('TWO_CARRIERLESS_TEST_GROSS');
Configuration::updateValue('TWO_CARRIERLESS_TEST_GROSS', '0');
register_shutdown_function(function () use ($armed_gross) {
    Configuration::updateValue('TWO_CARRIERLESS_TEST_GROSS', (string) $armed_gross);
});
$context = Context::getContext();
$id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
$context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
$context->language = new Language($id_lang);
$context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
$context->employee = new Employee((int) Db::getInstance()->getValue('SELECT MIN(id_employee) FROM `' . _DB_PREFIX_ . 'employee`'));
$id_country = (int) Country::getByIso('NO') ?: (int) Configuration::get('PS_COUNTRY_DEFAULT');
$country = new Country($id_country);
if (!$country->active) {
    $country->active = 1;
    $country->update();
}
$context->country = $country;

$vat25 = multiCarrierProbeTaxGroup(25.0, $id_country, $id_lang);
$vat15 = multiCarrierProbeTaxGroup(15.0, $id_country, $id_lang);
$first_carrier = multiCarrierProbeCarrier('Probe first carrier', 8.00, $vat25, (int) $country->id_zone);
$second_carrier = multiCarrierProbeCarrier('Probe second carrier', 4.00, $vat25, (int) $country->id_zone);
$first_product = multiCarrierProbeProduct('Probe first parcel', 10.00, $vat25, $first_carrier, $id_lang);
$second_product = multiCarrierProbeProduct('Probe second parcel', 40.00, $vat15, $second_carrier, $id_lang);

$customer = new Customer();
$customer->firstname = 'Probe';
$customer->lastname = 'Buyer';
$customer->email = 'multi-carrier-probe-' . time() . '@example.com';
// Tools::encrypt() is gone in PrestaShop 9.
$customer->passwd = method_exists('Tools', 'encrypt') ? Tools::encrypt('probe-password-1') : password_hash('probe-password-1', PASSWORD_BCRYPT);
$customer->add();
$context->customer = $customer;
$address = new Address();
$address->id_customer = (int) $customer->id;
$address->id_country = $id_country;
$address->alias = 'Probe';
$address->company = 'Probe AS';
$address->firstname = 'Probe';
$address->lastname = 'Buyer';
$address->address1 = 'Testgata 1';
$address->postcode = '0150';
$address->city = 'Oslo';
$address->phone = '+4740000000';
$address->add();

$cart = new Cart();
$cart->id_lang = $id_lang;
$cart->id_currency = (int) $context->currency->id;
$cart->id_shop = (int) $context->shop->id;
$cart->id_customer = (int) $customer->id;
$cart->id_address_invoice = (int) $address->id;
$cart->id_address_delivery = (int) $address->id;
$cart->secure_key = $customer->secure_key;
$cart->add();
$context->cart = $cart;
$cart->updateQty(2, (int) $first_product->id);
$cart->updateQty(1, (int) $second_product->id);
// The option shipping each product with its own carrier; another module may offer more.
$option_key = null;
$options = $cart->getDeliveryOptionList();
foreach (array_keys(isset($options[(int) $address->id]) ? $options[(int) $address->id] : array()) as $key) {
    $carriers = array_map('intval', array_filter(explode(',', (string) $key)));
    sort($carriers);
    if ($carriers === array((int) $first_carrier->id, (int) $second_carrier->id)) {
        $option_key = $key;
    }
}
if ($option_key === null) {
    fwrite(STDERR, 'no delivery option ships with both probe carriers: ' . json_encode(array_keys(isset($options[(int) $address->id]) ? $options[(int) $address->id] : array())) . PHP_EOL);
    exit(1);
}
$cart->setDeliveryOption(array((int) $address->id => $option_key));
$cart->update();

$module = new MultiCarrierProbeModule();
$module->validateOrder(
    (int) $cart->id,
    (int) Configuration::get('PS_OS_PAYMENT'),
    (float) $cart->getOrderTotal(true, Cart::BOTH),
    $module->displayName,
    null,
    array(),
    (int) $cart->id_currency,
    false,
    $customer->secure_key
);
$primary = new Order((int) $module->currentOrder);
$orders = array((int) $primary->id => $primary);
foreach ($primary->getBrother() as $sibling) {
    $orders[(int) $sibling->id] = $sibling;
}
ksort($orders);
$failures = array();
if (count($orders) !== 2) {
    $failures[] = 'expected the cart to split into 2 orders, got ' . count($orders) . ' (delivery option ' . $option_key . ')';
}
$other = null;
$paid = 0.0;
foreach ($orders as $order) {
    $paid += (float) $order->total_paid_tax_incl;
    if ((int) $order->id !== (int) $primary->id) {
        $other = $order;
    }
    echo 'order ' . $order->id . ': carrier ' . $order->id_carrier . ', paid ' . round((float) $order->total_paid_tax_incl, 2)
        . ', shipping ' . round((float) $order->total_shipping_tax_incl, 2) . ' at ' . $order->carrier_tax_rate . '%' . PHP_EOL;
}
Db::getInstance()->insert('twopayment', array(
    'id_order' => (int) $primary->id,
    'two_order_id' => 'probe-' . (int) $primary->id,
    'two_order_reference' => 'probe-' . (int) $primary->id,
    'two_day_on_invoice' => '30',
));

$put = null;
if ($other !== null) {
    $module->hookActionOrderEdited(array('order' => $other));
    $put = end($module->puts) ?: null;
}
if ($put === null) {
    $failures[] = 'an edit of order ' . ($other !== null ? $other->id : '?') . ' sent no update to Two';
} else {
    $lines = array();
    foreach ($put['line_items'] as $line) {
        $lines[] = $line['type'] . ' ' . $line['gross_amount'] . '@' . $line['tax_rate'];
    }
    echo 'update: ' . implode('; ', $lines) . ' = ' . $put['gross_amount'] . ', merchant_order_id ' . $put['merchant_order_id'] . PHP_EOL;
    if (abs((float) $put['gross_amount'] - $paid) > 0.001) {
        $failures[] = 'the update carries ' . $put['gross_amount'] . ', the orders total ' . round($paid, 2);
    }
    if ((string) $put['merchant_order_id'] !== (string) $primary->id) {
        $failures[] = 'the update names order ' . $put['merchant_order_id'] . ', the Two row is on order ' . $primary->id;
    }
}
$payments = $primary->getOrderPaymentCollection();
$payment_amounts = array();
foreach ($payments as $payment) {
    $payment_amounts[] = round((float) $payment->amount, 2);
}
echo 'payments: ' . implode(', ', $payment_amounts) . PHP_EOL;
if ($payment_amounts !== array(round($paid, 2))) {
    $failures[] = 'the shared payment is ' . implode(', ', $payment_amounts) . ', the orders total ' . round($paid, 2);
}

if (!empty($failures)) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo 'multi-carrier-update: the update carries both orders on PrestaShop ' . _PS_VERSION_ . PHP_EOL;
