<?php

/**
 * INTEGRATION PROBE - cart-rule discount lines traced to the lines each rule
 * discounts (TWO-27129), against a real PrestaShop engine.
 *
 * tests/CartRuleTracingSpec.php proves the tracing on stubbed totals. Here core
 * works the figures out itself: its own CartRule rows, Cart::getCartRules() and
 * Cart::getOrderTotal() (CartRuleCalculator from 1.7.6). Each case builds its
 * own taxes, carrier, products, buyer, cart and cart rules through ObjectModel,
 * then builds the order request the way checkout does (getTwoNewOrderData(),
 * as controllers/front/payment.php calls it), so the module's own whole-order
 * reconciliation against the cart runs on the result, and asserts on every
 * line of the payload:
 *   - gross == net + tax exactly, and |tax - net x rate| <= 0.02;
 *   - a discount line sits only at a rate one of its rule's lines has;
 *   - a 0% discount line carries a tax code, as Two's API requires one there.
 * Some cases also pin the discount lines' totals to core's ONLY_DISCOUNTS
 * totals, or the lines themselves.
 *
 * The cheapest-product percentage is left out: on 1.7.6 the module refuses it
 * by design.
 *
 * One process per case, for the same per-request caches reason as
 * default-shipping-tax-code.php. Hermetic: sends are answered by a subclass,
 * never made.
 *
 * Usage: php tests/integration/cart-rule-discounts.php [case]
 */

if (!defined('_PS_VERSION_')) {
    require '/var/www/html/config/config.inc.php';
}
require_once _PS_MODULE_DIR_ . 'twopayment/twopayment.php';
require_once __DIR__ . '/lib/probe-helpers.php';

/**
 * Answers every call instead of making it: the order request is only built here.
 */
class CrdProbeTwopayment extends Twopayment
{
    /** @var string[] */
    public $calls = array();

    public function setTwoPaymentRequest($endpoint, $payload = array(), $method = 'POST', $additional_headers = array(), $timeout = null)
    {
        $this->calls[] = $method . ' ' . $endpoint;

        return array('http_status' => 503);
    }
}

/**
 * The cases, one row each. Products are keyed so a rule can name one; a product with quantity 0 is created but not
 * put in the cart (a free gift, which core adds). Rule fields are CartRule fields; reduction_product and gift_product
 * name a product key. `rates` are the rates the rules' lines carry, the only rates a discount line may have.
 * `totals` pins the discount lines' net and gross to core's ONLY_DISCOUNTS totals; `lines` pins the discount lines.
 *
 * @return array<string,array>
 */
function crdCases()
{
    $amount = function ($value, $tax_included) {
        return array('reduction_amount' => $value, 'reduction_tax' => $tax_included ? 1 : 0);
    };
    $three_rates = array(
        'p21' => array(0.21, 100.00, 1),
        'p10' => array(0.10, 100.00, 1),
        'p4' => array(0.04, 100.00, 1),
    );

    return array(
        'zero_rate_shipping_amount' => array(
            'products' => array('p21' => array(0.21, 100.00, 1)),
            'shipping' => array(0.0, 10.00),
            'rules' => array('Probe 29.99' => $amount(29.99, true)),
            'rates' => array(0.21),
            'totals' => false,
            'lines' => array(array(0.21, '-29.99')),
            'description' => 'a whole-order 29.99 tax-included amount, one 21% product, shipping at 0% from a 0% tax rules group',
        ),
        'three_rates_amount' => array(
            'products' => $three_rates,
            'shipping' => array(0.21, 10.00),
            'rules' => array('Probe 150' => $amount(150.00, true)),
            'rates' => array(0.21, 0.10, 0.04),
            'totals' => true,
            'lines' => null,
            'description' => 'a whole-order 150.00 tax-included amount over 21%, 10% and 4% products',
        ),
        'three_rates_two_amounts' => array(
            'products' => $three_rates,
            'shipping' => array(0.21, 10.00),
            'rules' => array('Probe 150' => $amount(150.00, true), 'Probe 120' => $amount(120.00, true)),
            'rates' => array(0.21, 0.10, 0.04),
            'totals' => true,
            'lines' => null,
            'description' => 'whole-order 150.00 and 120.00 tax-included amounts over 21%, 10% and 4% products',
        ),
        'tax_excluded_amount' => array(
            'products' => array('p21' => array(0.21, 100.00, 1), 'p4' => array(0.04, 100.00, 1)),
            'shipping' => array(0.21, 10.00),
            'rules' => array('Probe 50 excl' => $amount(50.00, false)),
            'rates' => array(0.21, 0.04),
            'totals' => false,
            'lines' => null,
            'description' => 'a whole-order 50.00 tax-excluded amount over 21% and 4% products',
        ),
        'whole_order_percent' => array(
            'products' => $three_rates,
            'shipping' => array(0.21, 10.00),
            'rules' => array('Probe 10%' => array('reduction_percent' => 10, 'reduction_product' => 0)),
            'rates' => array(0.21, 0.10, 0.04),
            'totals' => false,
            'lines' => null,
            'description' => 'a whole-order 10% over 21%, 10% and 4% products',
        ),
        'specific_product_amount' => array(
            'products' => array('p21' => array(0.21, 100.00, 1), 'p10' => array(0.10, 100.00, 1)),
            'shipping' => array(0.21, 10.00),
            'rules' => array('Probe 20 on p10' => $amount(20.00, true) + array('reduction_product' => 'p10')),
            'rates' => array(0.10),
            'totals' => false,
            'lines' => null,
            'description' => 'a 20.00 tax-included amount on the 10% product of a 21% and 10% cart',
        ),
        'free_shipping' => array(
            'products' => array('p10' => array(0.10, 100.00, 1), 'p4' => array(0.04, 100.00, 1)),
            'shipping' => array(0.21, 10.00),
            'rules' => array('Probe free shipping' => array('free_shipping' => 1)),
            'rates' => array(0.21),
            'totals' => false,
            'lines' => null,
            'description' => 'free shipping on 21% shipping under 10% and 4% products',
        ),
        'free_gift' => array(
            'products' => array('p21' => array(0.21, 100.00, 1), 'g4' => array(0.04, 30.00, 0)),
            'shipping' => array(0.21, 10.00),
            'rules' => array('Probe gift' => array('gift_product' => 'g4')),
            'rates' => array(0.04),
            'totals' => false,
            'lines' => null,
            'description' => 'a free 4% gift in a 21% cart',
        ),
    );
}

/**
 * @param float $rate decimal
 * @param int $id_country
 * @param int $id_lang
 * @return int tax rules group id
 */
function crdTaxGroup($rate, $id_country, $id_lang)
{
    $label = 'Probe discount VAT ' . round($rate * 100, 3) . '% ' . uniqid();
    $tax = new Tax();
    $tax->name = array($id_lang => substr($label, 0, 32));
    $tax->rate = round($rate * 100, 3);
    $tax->active = 1;
    $tax->add();
    $group = new TaxRulesGroup();
    $group->name = $label;
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
 * Same shape as multi-carrier-update.php's carrier.
 *
 * @param float $price
 * @param int $id_tax_rules_group
 * @param int $id_zone
 * @return Carrier
 */
function crdCarrier($price, $id_tax_rules_group, $id_zone)
{
    $carrier = new Carrier();
    $carrier->name = 'Probe discount carrier';
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
 * @param float $price net
 * @param int $id_tax_rules_group
 * @param Carrier $carrier the only carrier it ships with, so the cart has one delivery option
 * @param int $id_lang
 * @return Product
 */
function crdProduct($name, $price, $id_tax_rules_group, $carrier, $id_lang)
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

/**
 * A cart rule with its own code, so core never adds it to another cart by itself.
 *
 * @param string $name
 * @param array $fields
 * @param int $id_customer
 * @param int $id_currency
 * @param int $id_lang
 * @return CartRule
 */
function crdCartRule($name, array $fields, $id_customer, $id_currency, $id_lang)
{
    $rule = new CartRule();
    $rule->name = array($id_lang => $name);
    $rule->code = 'PROBE' . strtoupper(substr(md5(uniqid($name, true)), 0, 10));
    $rule->id_customer = $id_customer;
    $rule->date_from = date('Y-m-d H:i:s', time() - 86400);
    $rule->date_to = date('Y-m-d H:i:s', time() + 86400);
    $rule->quantity = 10;
    $rule->quantity_per_user = 10;
    $rule->partial_use = 0;
    $rule->highlight = 0;
    $rule->active = 1;
    $rule->minimum_amount_currency = $id_currency;
    $rule->reduction_currency = $id_currency;
    foreach ($fields as $field => $value) {
        $rule->$field = $value;
    }
    $rule->add();

    return $rule;
}

/**
 * @param mixed $amount
 * @return int
 */
function crdCents($amount)
{
    return (int) round(round((float) $amount, 2) * 100);
}

/**
 * @param float $rate
 * @param float[] $rates
 * @return bool
 */
function crdRateIn($rate, array $rates)
{
    foreach ($rates as $candidate) {
        if (abs((float) $rate - (float) $candidate) < 0.00001) {
            return true;
        }
    }

    return false;
}

/**
 * @param string $name
 * @return int 0 when the case passes
 */
function crdRunCase($name)
{
    $cases = crdCases();
    if (!isset($cases[$name])) {
        fwrite(STDERR, 'unknown case ' . $name . PHP_EOL);

        return 1;
    }
    $case = $cases[$name];
    $description = $case['description'];

    probeBootKernel();
    // The carrier-less fixture, where CI seeds it armed, replaces every delivery option with its own.
    Configuration::updateValue('TWO_CARRIERLESS_TEST_GROSS', '0');
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

    $groups = array();
    $group_for = function ($rate) use (&$groups, $id_country, $id_lang) {
        $key = (string) $rate;
        if (!isset($groups[$key])) {
            $groups[$key] = crdTaxGroup($rate, $id_country, $id_lang);
        }

        return $groups[$key];
    };
    $carrier = crdCarrier($case['shipping'][1], $group_for($case['shipping'][0]), (int) $country->id_zone);
    $products = array();
    foreach ($case['products'] as $key => $product) {
        $products[$key] = crdProduct('Probe discount ' . $name . ' ' . $key, $product[1], $group_for($product[0]), $carrier, $id_lang);
    }

    $customer = new Customer();
    $customer->firstname = 'Probe';
    $customer->lastname = 'Buyer';
    $customer->email = 'cart-rule-probe-' . $name . '-' . time() . '@example.com';
    // Tools::encrypt() is gone in PrestaShop 9.
    $customer->passwd = method_exists('Tools', 'encrypt') ? Tools::encrypt('probe-password-1') : password_hash('probe-password-1', PASSWORD_BCRYPT);
    $customer->add();
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
    $cart->id_carrier = (int) $carrier->id;
    $cart->secure_key = $customer->secure_key;
    $cart->add();
    probeApplyContext($cart, $customer);
    foreach ($case['products'] as $key => $product) {
        if ($product[2] > 0) {
            $cart->updateQty($product[2], (int) $products[$key]->id);
        }
    }
    $cart->setDeliveryOption(array((int) $address->id => (int) $carrier->id . ','));
    $cart->update();

    $failures = array();
    $rules = array();
    foreach ($case['rules'] as $rule_name => $fields) {
        foreach (array('reduction_product', 'gift_product') as $field) {
            if (isset($fields[$field]) && is_string($fields[$field])) {
                $fields[$field] = (int) $products[$fields[$field]]->id;
            }
        }
        $rule = crdCartRule($rule_name, $fields, (int) $customer->id, (int) $cart->id_currency, $id_lang);
        $rules[$rule_name] = $rule;
        if (!$cart->addCartRule((int) $rule->id)) {
            $failures[] = 'core did not add cart rule "' . $rule_name . '" to the cart';
        }
    }
    register_shutdown_function(function () use ($rules) {
        foreach ($rules as $rule) {
            $rule->delete();
        }
    });

    $core_gross = round((float) $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS), 2);
    $core_net = round((float) $cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS), 2);
    echo '  core: ' . count($cart->getCartRules()) . ' cart rule(s), ONLY_DISCOUNTS ' . $core_gross . ' incl / ' . $core_net
        . ' excl, BOTH ' . round((float) $cart->getOrderTotal(true, Cart::BOTH), 2) . ' incl / '
        . round((float) $cart->getOrderTotal(false, Cart::BOTH), 2) . ' excl, shipping '
        . round((float) $cart->getOrderTotal(true, Cart::ONLY_SHIPPING), 2) . PHP_EOL;
    if ($core_gross <= 0 || count($cart->getCartRules()) !== count($case['rules'])) {
        $failures[] = 'cart shape not reproduced: core applied ' . count($cart->getCartRules()) . ' of ' . count($case['rules']) . ' rule(s) for a discount of ' . $core_gross;
    }

    $payload = null;
    if ($failures === array()) {
        $module = new CrdProbeTwopayment();
        try {
            $payload = $module->getTwoNewOrderData('crd-' . $name, $cart, array(
                'merchant_confirmation_url' => 'https://shop.local/confirm', 'merchant_cancel_order_url' => 'https://shop.local/cancel',
                'merchant_edit_order_url' => '', 'merchant_order_verification_failed_url' => '', 'merchant_invoice_url' => '', 'merchant_shipping_document_url' => '',
            ));
        } catch (Throwable $e) {
            $failures[] = 'checkout refused the order request: ' . get_class($e) . ': ' . $e->getMessage();
        }
    }

    if ($failures === array() && !is_array($payload)) {
        $failures[] = 'checkout built no order request';
    }

    if (is_array($payload)) {
        $discount_net = 0;
        $discount_gross = 0;
        $discount_lines = array();
        foreach ($payload['line_items'] as $i => $line) {
            $gross = crdCents($line['gross_amount']);
            $net = crdCents($line['net_amount']);
            $tax = crdCents($line['tax_amount']);
            $rate = (float) $line['tax_rate'];
            echo '  line ' . $i . ': ' . $line['type'] . ' "' . $line['name'] . '" ' . $line['gross_amount'] . ' = ' . $line['net_amount']
                . ' + ' . $line['tax_amount'] . ' @ ' . $line['tax_rate']
                . (isset($line['tax_code']) ? ' code ' . $line['tax_code'] : '') . PHP_EOL;
            if ($gross !== $net + $tax) {
                $failures[] = 'line ' . $i . ' "' . $line['name'] . '": gross ' . $line['gross_amount'] . ' != net ' . $line['net_amount'] . ' + tax ' . $line['tax_amount'];
            }
            if (abs($tax - $net * $rate) > 2.0000001) {
                $failures[] = 'line ' . $i . ' "' . $line['name'] . '": tax ' . $line['tax_amount'] . ' is not net ' . $line['net_amount'] . ' x ' . $line['tax_rate'] . ' within 0.02';
            }
            if ($gross >= 0) {
                continue;
            }
            $discount_lines[] = $line;
            $discount_net += $net;
            $discount_gross += $gross;
            if (!crdRateIn($rate, $case['rates'])) {
                $failures[] = 'discount line "' . $line['name'] . '" at ' . $line['tax_rate'] . ', a rate none of its rule\'s lines has (' . implode(', ', $case['rates']) . ')';
            }
            if ($rate == 0.0 && (!isset($line['tax_code']) || (string) $line['tax_code'] === '')) {
                $failures[] = 'discount line "' . $line['name'] . '" at 0% has no tax code';
            }
        }
        if ($discount_lines === array()) {
            $failures[] = 'no discount line in the order request';
        }
        if ($case['totals'] && ($discount_net !== -crdCents($core_net) || $discount_gross !== -crdCents($core_gross))) {
            $failures[] = 'discount lines total ' . ($discount_gross / 100) . ' incl / ' . ($discount_net / 100)
                . ' excl, core ONLY_DISCOUNTS -' . $core_gross . ' incl / -' . $core_net . ' excl';
        }
        if ($case['lines'] !== null) {
            $got = array();
            foreach ($discount_lines as $line) {
                $got[] = $line['tax_rate'] . ' ' . $line['gross_amount'];
            }
            $want = array();
            foreach ($case['lines'] as $expected) {
                $matched = false;
                foreach ($discount_lines as $line) {
                    $matched = $matched || (crdRateIn((float) $line['tax_rate'], array($expected[0])) && crdCents($line['gross_amount']) === crdCents($expected[1]));
                }
                $want[] = $expected[0] . ' ' . $expected[1];
                if (!$matched) {
                    $failures[] = 'no discount line at ' . $expected[0] . ' for ' . $expected[1];
                }
            }
            if (count($discount_lines) !== count($case['lines'])) {
                $failures[] = 'discount lines [' . implode('; ', $got) . '], expected [' . implode('; ', $want) . ']';
            }
        }
    }

    if ($failures === array()) {
        echo '  PASS ' . $name . ': ' . $description . PHP_EOL;

        return 0;
    }
    echo '  FAIL ' . $name . ': ' . $description . PHP_EOL;
    foreach ($failures as $failure) {
        echo '       - ' . $failure . ' (' . $description . ')' . PHP_EOL;
    }
    foreach (probeModuleLogs() as $entry) {
        if ($entry['severity'] >= 3) {
            echo '       log[' . $entry['severity'] . '] ' . $entry['message'] . PHP_EOL;
        }
    }

    return 1;
}

$argument = isset($argv[1]) ? (string) $argv[1] : '';
if ($argument !== '') {
    probeClearModuleLogs();
    exit(crdRunCase($argument));
}

echo 'Cart-rule discount lines - integration probe (PrestaShop ' . _PS_VERSION_ . ')' . PHP_EOL;
$armed_gross = Configuration::get('TWO_CARRIERLESS_TEST_GROSS');
$exit = 0;
foreach (array_keys(crdCases()) as $case_name) {
    echo '--- ' . $case_name . PHP_EOL;
    $command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M '
        . escapeshellarg(__FILE__) . ' ' . escapeshellarg($case_name);
    $status = 0;
    passthru($command, $status);
    if ($status !== 0) {
        $exit = 1;
    }
}
Configuration::updateValue('TWO_CARRIERLESS_TEST_GROSS', $armed_gross === false ? '' : (string) $armed_gross);
echo $exit === 0 ? 'cart-rule-discounts: all cases pass on PrestaShop ' . _PS_VERSION_ . PHP_EOL : 'cart-rule-discounts: FAILURES - see above' . PHP_EOL;
exit($exit);
