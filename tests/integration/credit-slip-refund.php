<?php

/**
 * INTEGRATION PROBE - credit slips and the Refunded status reach Two as
 * refunds (TWO-26093), against a real PrestaShop engine and database.
 *
 * Places a Two order with two tax rates and shipping, and one whose product
 * compounds two taxes, then refunds them the way the back office does: through
 * core's partial-refund command on 1.7.7 and later, through OrderSlip::create()
 * and the hook call AdminOrdersController makes on 1.7.6. Core fires the hook
 * itself, into this probe's module instance, whose API calls are captured and
 * answered as Two would. What it proves, on real SQL:
 *  - a slip for 1 of 3 units, a specific-amount slip and a compound-tax slip
 *    each send the amount core refunded, with tax_subtotals summing to it;
 *  - two slips created before either hook call runs are sent once each;
 *  - marking the order Refunded afterwards sends only what is left.
 *
 * Hermetic: no browser, no network, no Two credentials.
 *
 * Usage: php tests/integration/credit-slip-refund.php
 */

if (!defined('_PS_VERSION_')) {
    require '/var/www/html/config/config.inc.php';
}
require_once _PS_MODULE_DIR_ . 'twopayment/twopayment.php';

/**
 * The back-office kernel where there is one: the partial-refund command bus lives there.
 *
 * @return void
 */
function slipProbeBootKernel()
{
    if (PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance() !== null) {
        return;
    }
    foreach (array('AdminKernel', 'AppKernel') as $class) {
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
    throw new RuntimeException('no bootable back-office kernel class found (tried AdminKernel, AppKernel)');
}

/**
 * @param float[] $rates percentages, applied in order
 * @param int $behavior TaxRule behavior: 0 this tax only, 1 combine, 2 one after another
 * @param int $id_country
 * @param int $id_lang
 * @return int tax rules group id
 */
function slipProbeTaxGroup(array $rates, $behavior, $id_country, $id_lang)
{
    $group = new TaxRulesGroup();
    $group->name = 'Probe VAT ' . implode('+', $rates) . ' b' . $behavior;
    $group->active = 1;
    $group->add();
    foreach ($rates as $rate) {
        $tax = new Tax();
        $tax->name = array($id_lang => 'Probe VAT ' . $rate);
        $tax->rate = $rate;
        $tax->active = 1;
        $tax->add();
        $rule = new TaxRule();
        $rule->id_tax_rules_group = (int) $group->id;
        $rule->id_country = $id_country;
        $rule->id_tax = (int) $tax->id;
        $rule->behavior = $behavior;
        $rule->add();
    }

    return (int) $group->id;
}

/**
 * @param float $price
 * @param int $id_tax_rules_group
 * @param int $id_zone
 * @return Carrier
 */
function slipProbeCarrier($price, $id_tax_rules_group, $id_zone)
{
    $carrier = new Carrier();
    $carrier->name = 'Probe slip carrier';
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
 * @param int $id_lang
 * @return Product
 */
function slipProbeProduct($name, $price, $id_tax_rules_group, $id_lang)
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
    // No stock movement: PrestaShop 1.7 refuses one from a CLI run (see line-item-image.php).
    StockAvailable::setQuantity((int) $product->id, 0, 100, null, false);

    return $product;
}

/** Answers the module's API calls as Two would, and keeps every refund it was sent. */
class SlipProbeModule extends Twopayment
{
    /** @var array[] refund POSTs: order, key, payload */
    public $refunds = array();
    /** @var array Two orders by id: gross_amount, currency */
    public $twoOrders = array();

    public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
    {
        if (preg_match('#^/v1/order/([^/]+)/refund$#', $endpoint, $m) && $method === 'POST') {
            $this->refunds[] = array('two_order_id' => $m[1], 'key' => implode(' ', $additional_headers), 'payload' => $payload);

            return array('http_status' => 201, 'id' => 'probe-refund-' . count($this->refunds));
        }
        if (preg_match('#^/v1/order/([^/]+)$#', $endpoint, $m) && $method === 'GET' && isset($this->twoOrders[$m[1]])) {
            $refunds = array();
            foreach ($this->refunds as $refund) {
                if ($refund['two_order_id'] === $m[1]) {
                    $refunds[] = array('total_amount' => '-' . $refund['payload']['amount']);
                }
            }

            return array_merge($this->twoOrders[$m[1]], array(
                'id' => $m[1],
                'state' => empty($refunds) ? 'FULFILLED' : 'REFUNDED',
                'status' => 'APPROVED',
                'refunds' => $refunds,
            ));
        }

        return array('http_status' => 503);
    }
}

/**
 * @param SlipProbeModule $module
 * @param Customer $customer
 * @param Address $address
 * @param Carrier $carrier
 * @param array $lines [product, quantity]
 * @return Order
 */
function slipProbePlaceOrder($module, $customer, $address, $carrier, array $lines)
{
    $context = Context::getContext();
    $cart = new Cart();
    $cart->id_lang = (int) $context->language->id;
    $cart->id_currency = (int) $context->currency->id;
    $cart->id_shop = (int) $context->shop->id;
    $cart->id_customer = (int) $customer->id;
    $cart->id_address_invoice = (int) $address->id;
    $cart->id_address_delivery = (int) $address->id;
    $cart->id_carrier = (int) $carrier->id;
    $cart->secure_key = $customer->secure_key;
    $cart->add();
    $context->cart = $cart;
    foreach ($lines as $line) {
        $cart->updateQty($line[1], (int) $line[0]->id);
    }
    $cart->setDeliveryOption(array((int) $address->id => (int) $carrier->id . ','));
    $cart->update();
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
    $order = new Order((int) $module->currentOrder);
    $two_order_id = 'probe-slip-' . (int) $order->id;
    Db::getInstance()->insert('twopayment', array(
        'id_order' => (int) $order->id,
        'two_order_id' => $two_order_id,
        'two_order_reference' => $two_order_id,
        'two_day_on_invoice' => '30',
    ));
    $module->twoOrders[$two_order_id] = array(
        'gross_amount' => number_format((float) $order->total_paid_tax_incl, 2, '.', ''),
        'currency' => (new Currency((int) $order->id_currency))->iso_code,
    );

    return $order;
}

/**
 * @param Order $order
 * @param int $id_product
 * @return int
 */
function slipProbeDetailId($order, $id_product)
{
    foreach ($order->getProductsDetail() as $row) {
        if ((int) $row['product_id'] === (int) $id_product) {
            return (int) $row['id_order_detail'];
        }
    }
    throw new RuntimeException('no order_detail for product ' . $id_product . ' on order ' . $order->id);
}

/**
 * Refund as the back office does, so core writes the slip and fires the hook.
 *
 * @param Order $order
 * @param int $id_order_detail
 * @param int $quantity
 * @param float $shipping_incl refunded shipping, tax included
 * @param float|null $chosen a specific amount to refund instead of the products
 * @return void
 */
function slipProbeRefund($order, $id_order_detail, $quantity, $shipping_incl, $chosen = null)
{
    $order = new Order((int) $order->id);
    $detail = new OrderDetail($id_order_detail);
    $command = 'PrestaShop\PrestaShop\Core\Domain\Order\Command\IssuePartialRefundCommand';
    if (class_exists($command)) {
        $type = $chosen !== null
            ? PrestaShop\PrestaShop\Core\Domain\Order\VoucherRefundType::SPECIFIC_AMOUNT_REFUND
            : PrestaShop\PrestaShop\Core\Domain\Order\VoucherRefundType::PRODUCT_PRICES_EXCLUDING_VOUCHER_REFUND;
        $args = array(
            (int) $order->id,
            array($id_order_detail => array('quantity' => $quantity, 'amount' => (string) round($detail->unit_price_tax_incl * $quantity, 2))),
            (string) $shipping_incl,
            false,
            true,
            false,
            $type,
        );
        if ($chosen !== null) {
            $args[] = (string) $chosen;
        }
        $GLOBALS['kernel']->getContainer()->get('prestashop.core.command_bus')->handle(new $command(...$args));

        return;
    }
    // 1.7.6: AdminOrdersController::postProcess() 'partialRefund', tax-included entry.
    $list = array($id_order_detail => array(
        'id_order_detail' => $id_order_detail,
        'quantity' => $quantity,
        'unit_price' => (float) $detail->unit_price_tax_incl,
        'amount' => $detail->unit_price_tax_incl * $quantity,
    ));
    if (!OrderSlip::create($order, $list, $shipping_incl > 0 ? $shipping_incl : false, $chosen !== null ? $chosen : 0, $chosen !== null, false)) {
        throw new RuntimeException('OrderSlip::create() refused the refund');
    }
    Hook::exec('actionOrderSlipAdd', array('order' => $order, 'productList' => $list, 'qtyList' => array($id_order_detail => $quantity)), null, false, true, false, $order->id_shop);
}

/**
 * @param array $refund a captured POST
 * @return string what it sends, for the log
 */
function slipProbeDescribe($refund)
{
    $parts = array();
    foreach ($refund['payload']['tax_subtotals'] as $subtotal) {
        $parts[] = $subtotal['taxable_amount'] . '+' . $subtotal['tax_amount'] . '@' . $subtotal['tax_rate'];
    }

    return $refund['payload']['amount'] . ' = ' . implode(', ', $parts);
}

slipProbeBootKernel();
Configuration::updateValue('PS_MAIL_METHOD', 3);
Configuration::updateValue('PS_STOCK_MANAGEMENT', 0);
$armed_gross = Configuration::get('TWO_CARRIERLESS_TEST_GROSS');
Configuration::updateValue('TWO_CARRIERLESS_TEST_GROSS', '0');
register_shutdown_function(function () use ($armed_gross) {
    Configuration::updateValue('TWO_CARRIERLESS_TEST_GROSS', (string) $armed_gross);
});
$context = Context::getContext();
$id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
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

// Core's hooks reach the module through its instance cache: put this probe's instance there.
$module = new SlipProbeModule();
$instances = new ReflectionProperty('Module', '_INSTANCE');
$instances->setAccessible(true);
$instances->setValue(null, array_merge((array) $instances->getValue(), array('twopayment' => $module)));

$vat25 = slipProbeTaxGroup(array(25.0), 0, $id_country, $id_lang);
$vat15 = slipProbeTaxGroup(array(15.0), 0, $id_country, $id_lang);
$compound = slipProbeTaxGroup(array(10.0, 5.0), 2, $id_country, $id_lang);
$carrier = slipProbeCarrier(20.00, $vat15, (int) $country->id_zone);
$product_a = slipProbeProduct('Probe slip A', 100.00, $vat25, $id_lang);
$product_b = slipProbeProduct('Probe slip B', 40.00, $vat15, $id_lang);
$product_c = slipProbeProduct('Probe slip compound', 100.00, $compound, $id_lang);

$customer = new Customer();
$customer->firstname = 'Probe';
$customer->lastname = 'Buyer';
$customer->email = 'credit-slip-probe-' . time() . '@example.com';
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

$failures = array();
$check = function ($label, $refund, $amount, array $rates) use (&$failures) {
    if ($refund === null) {
        $failures[] = $label . ': nothing was sent to Two';

        return;
    }
    echo $label . ': ' . slipProbeDescribe($refund) . PHP_EOL;
    $sum = 0.0;
    $got_rates = array();
    foreach ($refund['payload']['tax_subtotals'] as $subtotal) {
        $sum += (float) $subtotal['taxable_amount'] + (float) $subtotal['tax_amount'];
        $got_rates[] = $subtotal['tax_rate'];
    }
    if (number_format($sum, 2, '.', '') !== $refund['payload']['amount']) {
        $failures[] = $label . ': tax_subtotals sum to ' . number_format($sum, 2, '.', '') . ', amount is ' . $refund['payload']['amount'];
    }
    if ($amount !== null && $refund['payload']['amount'] !== $amount) {
        $failures[] = $label . ': sent ' . $refund['payload']['amount'] . ', core refunded ' . $amount;
    }
    if ($got_rates !== $rates) {
        $failures[] = $label . ': rates ' . implode(', ', $got_rates) . ', expected ' . implode(', ', $rates);
    }
};
$sent_since = function ($count) use ($module) {
    return array_slice($module->refunds, $count);
};

// Order 1: 3 x A at 25%, 2 x B at 15%, shipping at 15%.
$order = slipProbePlaceOrder($module, $customer, $address, $carrier, array(array($product_a, 3), array($product_b, 2)));
echo 'order ' . $order->id . ': ' . round((float) $order->total_paid_tax_incl, 2) . ' incl, shipping ' . round((float) $order->total_shipping_tax_incl, 2) . ' at ' . $order->carrier_tax_rate . '%' . PHP_EOL;
$detail_a = slipProbeDetailId($order, $product_a->id);
$unit_a = number_format((float) (new OrderDetail($detail_a))->unit_price_tax_incl, 2, '.', '');

$n = count($module->refunds);
slipProbeRefund($order, $detail_a, 1, 0);
$new = $sent_since($n);
$check('1 of 3 units', isset($new[0]) ? $new[0] : null, $unit_a, array('0.250000'));

$n = count($module->refunds);
slipProbeRefund($order, $detail_a, 1, 0, 30.00);
$new = $sent_since($n);
$check('specific amount 30.00 for 1 unit', isset($new[0]) ? $new[0] : null, '30.00', array('0.250000'));

// Two slips written before either hook call: each must be sent once.
$n = count($module->refunds);
$detail_b = slipProbeDetailId($order, $product_b->id);
$fresh = new Order((int) $order->id);
foreach (array(1, 1) as $quantity) {
    $row = new OrderDetail($detail_b);
    OrderSlip::create($fresh, array($detail_b => array('id_order_detail' => $detail_b, 'quantity' => $quantity, 'unit_price' => (float) $row->unit_price_tax_incl, 'amount' => $row->unit_price_tax_incl * $quantity)), false, 0, false, false);
}
foreach (array(1, 2) as $call) {
    Hook::exec('actionOrderSlipAdd', array('order' => $fresh, 'productList' => array(), 'qtyList' => array()), null, false, true, false, $fresh->id_shop);
}
$new = $sent_since($n);
$keys = array_map(function ($refund) {
    return $refund['key'];
}, $new);
echo 'two slips, two hook calls: ' . implode(' | ', $keys) . PHP_EOL;
if (count($new) !== 2 || count(array_unique($keys)) !== 2) {
    $failures[] = 'two slips created together were sent as ' . count(array_unique($keys)) . ' distinct refund(s) in ' . count($new) . ' call(s)';
}
foreach ($new as $i => $refund) {
    $check('slip ' . ($i + 1) . ' of 2 created together', $refund, null, array('0.150000'));
}

// Refunded: only what is left goes to Two.
$sent_total = 0.0;
foreach ($module->refunds as $refund) {
    $sent_total += (float) $refund['payload']['amount'];
}
$expected_rest = number_format(round((float) $order->total_paid_tax_incl, 2) - $sent_total, 2, '.', '');
$n = count($module->refunds);
$history = new OrderHistory();
$history->id_order = (int) $order->id;
$history->id_employee = (int) $context->employee->id;
$history->changeIdOrderState((int) Configuration::get('PS_TWO_OS_REFUNDED_MAP'), new Order((int) $order->id));
$new = $sent_since($n);
$check('Refunded after the slips', isset($new[0]) ? $new[0] : null, $expected_rest, array('0.150000', '0.250000'));

$n = count($module->refunds);
$history = new OrderHistory();
$history->id_order = (int) $order->id;
$history->changeIdOrderState((int) Configuration::get('PS_TWO_OS_REFUNDED_MAP'), new Order((int) $order->id));
if (count($sent_since($n)) !== 0) {
    $failures[] = 'marking the order Refunded again sent another refund';
}

// Order 2: one product compounding 10% then 5%.
$order2 = slipProbePlaceOrder($module, $customer, $address, $carrier, array(array($product_c, 1)));
$detail_c = slipProbeDetailId($order2, $product_c->id);
$n = count($module->refunds);
slipProbeRefund($order2, $detail_c, 1, 0);
$new = $sent_since($n);
$check('compound 10% then 5%', isset($new[0]) ? $new[0] : null, number_format((float) (new OrderDetail($detail_c))->unit_price_tax_incl, 2, '.', ''), array('0.155000'));

if (!empty($failures)) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo 'credit-slip-refund: slips and the Refunded remainder reach Two on PrestaShop ' . _PS_VERSION_ . PHP_EOL;
