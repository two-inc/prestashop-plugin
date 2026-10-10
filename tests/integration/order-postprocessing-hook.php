<?php

/**
 * INTEGRATION PROBE - the order postprocessing hook (TWO-26092), against a
 * real PrestaShop engine: core's own Hook::exec dispatching to a module that
 * registered on actionTwoOrderPostprocessing
 * (tests/integration/fixtures/twoorderpostprocessingtest).
 *
 * The cart is a 21% product at 100.00 net and 29.00 of shipping on a "No tax"
 * carrier, the worked example of the README. The probe drives every request
 * type and asserts the hook fired once with the contract's context, that the
 * sent payload is the post-hook one, as the subscriber returned it.
 *
 * The carrier-less scenarios (TWO-26117) use the carrier-less cart
 * (dev/ci/seed-carrierless-cart.sh) with the Default shipping tax code blank:
 * shipping no carrier provides a rate for goes out at 0% with the tax it was
 * charged, and a subscriber's re-split of it is sent as returned.
 *
 * TWO-26274: the module's shop-match checks are its default handler, which
 * stands down for an enabled merchant handler. The fixture is installed
 * disabled; this probe enables it for each scenario but `unhandled`. The
 * `outside_carrier_*` scenarios use the carrier-less cart's "external_only"
 * shape, a cost the shop adds to the cart total outside any carrier (29.00, on
 * every leg): refused with no handler, and sent as a handler that adds the line
 * returns it. A handler that leaves the payload inconsistent is sent as it
 * returns it too: Two's API judges that (TWO-26283).
 * The `surcharge_*` scenarios put the Default shipping tax code at 21% under the
 * "No tax" carrier's shipping with the buyer fee on: the fee's cart line is
 * written, and create and update send that fee, only when a handler owns the
 * shipping line's check.
 *
 * One process per scenario, for the same per-request caches reason as
 * default-shipping-tax-code.php. Requires dev/ci/install-order-postprocessing-fixture.sh.
 * Hermetic: sends are recorded by a subclass, never made.
 *
 * Usage: php tests/integration/order-postprocessing-hook.php [scenario]
 */

// Core 8 and 9 discard a subscriber's Exception only with debug off, so throws_prod forces it off.
if (isset($argv[1]) && $argv[1] === 'throws_prod' && !defined('_PS_MODE_DEV_')) {
    define('_PS_MODE_DEV_', false);
}
if (!defined('_PS_VERSION_')) {
    require '/var/www/html/config/config.inc.php';
}
require_once _PS_MODULE_DIR_ . 'twopayment/twopayment.php';

const OPP_LABEL = 'Two hook probe';
const OPP_TWO_ORDER = 'two-order-opp';

/**
 * The order's lines as Two's GET answers them (TWO-26282).
 *
 * @return array
 */
function oppTwoLines()
{
    return array(
        array('id' => 'line-1', 'type' => 'PHYSICAL', 'gross_amount' => '121.00', 'net_amount' => '100.00', 'tax_amount' => '21.00', 'tax_rate' => '0.21', 'tax_code' => null),
        array('id' => 'line-2', 'type' => 'SHIPPING_FEE', 'gross_amount' => '29.00', 'net_amount' => '29.00', 'tax_amount' => '0.00', 'tax_rate' => '0', 'tax_code' => null),
    );
}

/**
 * A refund Two already holds for the order, crediting part of line-1, as Two's GET answers it (TWO-26287).
 *
 * @return array
 */
function oppTwoRefunds()
{
    return array(
        array('id' => 'refund-0', 'total_amount' => '-12.10', 'line_items' => array(
            array('prototype_id' => 'line-1', 'gross_amount' => '-12.10', 'net_amount' => '-10.00', 'tax_amount' => '-2.10'),
        )),
    );
}

/**
 * Records sends instead of making them, and answers as a CONFIRMED Two order.
 */
class OppProbeTwopayment extends Twopayment
{
    /** @var array<int,array{endpoint:string,payload:mixed,method:string}> */
    public $sent = array();
    public $twoState = 'CONFIRMED';
    /** @var array the refunds Two holds for the order, on a read (TWO-26287) */
    public $twoRefunds = array();

    public function setTwoPaymentRequest($endpoint, $payload = array(), $method = 'POST', $additional_headers = array(), $timeout = null)
    {
        if ($endpoint === '/v1/pricing/order/fee') {
            // The buyer fee quote, for the surcharge scenarios: not an order request.
            return array('http_status' => 200, 'buyer_fee_share' => '5.00', 'currency' => 'EUR');
        }
        if ($method !== 'GET') {
            $this->sent[] = array('endpoint' => $endpoint, 'payload' => $payload, 'method' => $method);
        }
        if (strpos($endpoint, '/refund') !== false) {
            return array('http_status' => 201, 'id' => 'refund-1');
        }
        if (strpos($endpoint, '/fulfillments') !== false) {
            return array('http_status' => 201, 'fulfilled_order' => array('id' => 'fulfilled-1'));
        }
        if ($endpoint === '/v1/order_intent') {
            return array('http_status' => 200, 'approved' => true);
        }

        // The order's lines at Two, on a read only: the context's order_lines (TWO-26282).
        $lines = $method === 'GET' ? array('line_items' => oppTwoLines(), 'refunds' => $this->twoRefunds) : array();

        return $lines + array(
            'http_status' => 200, 'id' => OPP_TWO_ORDER, 'state' => $this->twoState, 'status' => 'APPROVED',
            'merchant_reference' => 'ref', 'gross_amount' => '150.00', 'currency' => 'EUR', 'invoice_url' => '', 'refunds' => array(),
        );
    }

    public function getTwoOrderPaymentData($id_order)
    {
        return array(
            'two_order_id' => OPP_TWO_ORDER, 'two_order_reference' => 'ref', 'two_order_state' => $this->twoState,
            'two_order_status' => 'APPROVED', 'two_day_on_invoice' => '30', 'two_payment_term_type' => 'STANDARD',
            'two_invoice_url' => '', 'two_invoice_id' => null,
        );
    }

    public function setTwoOrderPaymentData($id_order, $payment_data)
    {
        return true;
    }

    // The credit slip driver passes a slip core never stored: claim and record it in memory, and give it the one
    // 21% line it refunds, so the probe exercises the send rather than the refund table (TWO-26093).
    protected function claimTwoCreditSlip($id_order, $id_order_slip)
    {
        return true;
    }

    protected function recordTwoRefundOutcome($id_order, $id_order_slip, $status, $payload, $reason)
    {
    }

    public function getTwoCreditSlipTaxLines($slip)
    {
        return array(array('amount_tax_excl' => '24.79', 'amount_tax_incl' => '30.00', 'rate' => '21.000'));
    }
}

class OppResponded extends Error
{
}

if (class_exists('ModuleFrontController')) {
    require_once _PS_MODULE_DIR_ . 'twopayment/controllers/front/orderintent.php';

    /**
     * The checkout's controller, answering by exception rather than exit.
     */
    class OppProbeOrderintentController extends TwopaymentOrderintentModuleFrontController
    {
        public function sendJsonResponse($content)
        {
            throw new OppResponded((string) $content);
        }
    }
}

// PrestaShop 9 prices carts through the Symfony container.
function oppBootKernel()
{
    if (!class_exists('PrestaShop\PrestaShop\Adapter\SymfonyContainer')
        || PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance() !== null) {
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
}

function oppStoredObject($key, $class)
{
    $id = (int) Configuration::get($key);
    if ($id <= 0) {
        return null;
    }
    $object = new $class($id);

    return Validate::isLoadedObject($object) ? $object : null;
}

/**
 * The shop, customer, 21% product, "No tax" carrier at 29.00, cart and order. Idempotent.
 *
 * @return void
 */
function oppSeed()
{
    $id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
    $country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
    if (!$country->active) {
        $country->active = true;
        $country->update();
    }
    $context = Context::getContext();
    $context->language = new Language($id_lang);
    $context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
    $context->country = $country;

    $group = oppStoredObject('TWO_OPP_TEST_TRG', 'TaxRulesGroup');
    if ($group === null) {
        $tax = new Tax();
        $tax->rate = 21.0;
        $tax->active = true;
        $tax->name = array($id_lang => OPP_LABEL . ' 21%');
        $tax->add();
        $group = new TaxRulesGroup();
        $group->name = OPP_LABEL . ' 21%';
        $group->active = true;
        $group->add();
        $rule = new TaxRule();
        $rule->id_tax_rules_group = (int) $group->id;
        $rule->id_country = (int) $country->id;
        $rule->id_tax = (int) $tax->id;
        $rule->behavior = 0;
        $rule->add();
        Configuration::updateValue('TWO_OPP_TEST_TRG', (int) $group->id);
    }

    $customer = oppStoredObject('TWO_OPP_TEST_ID_CUSTOMER', 'Customer');
    if ($customer === null) {
        $customer = new Customer();
        $customer->firstname = 'Hook';
        $customer->lastname = 'Probe';
        $customer->email = 'order-postprocessing-probe@example.com';
        $customer->passwd = Tools::hash('probe-' . uniqid('', true));
        $customer->id_lang = $id_lang;
        $customer->active = true;
        $customer->add();
        Configuration::updateValue('TWO_OPP_TEST_ID_CUSTOMER', (int) $customer->id);
    }
    $context->customer = $customer;

    $address = oppStoredObject('TWO_OPP_TEST_ID_ADDRESS', 'Address');
    if ($address === null) {
        $address = new Address();
        $address->id_customer = (int) $customer->id;
        $address->id_country = (int) $country->id;
        $address->alias = OPP_LABEL;
        $address->firstname = 'Hook';
        $address->lastname = 'Probe';
        $address->company = 'Hook Probe AS';
        $address->address1 = 'Testveien 2';
        $address->postcode = '0150';
        $address->city = 'Oslo';
        $address->phone = '12345678';
        $address->add();
        Configuration::updateValue('TWO_OPP_TEST_ID_ADDRESS', (int) $address->id);
    }

    $product = oppStoredObject('TWO_OPP_TEST_ID_PRODUCT', 'Product');
    if ($product === null) {
        $product = new Product();
        $product->name = array($id_lang => OPP_LABEL . ' lamp');
        $product->link_rewrite = array($id_lang => 'two-hook-probe-lamp');
        $product->price = 100.0;
        $product->id_tax_rules_group = (int) $group->id;
        $product->active = true;
        $product->id_category_default = (int) Configuration::get('PS_HOME_CATEGORY');
        $product->minimal_quantity = 1;
        $product->add();
        Configuration::updateValue('TWO_OPP_TEST_ID_PRODUCT', (int) $product->id);
        // Orderable without stock: setting a quantity records a stock movement, which needs an employee.
        StockAvailable::setProductOutOfStock((int) $product->id, 1);
    }

    $carrier = oppStoredObject('TWO_OPP_TEST_ID_CARRIER', 'Carrier');
    if ($carrier === null) {
        $carrier = new Carrier();
        $carrier->name = OPP_LABEL;
        $carrier->delay = array($id_lang => '1 day');
        $carrier->active = true;
        $carrier->is_free = false;
        $carrier->shipping_handling = false;
        $carrier->shipping_method = Carrier::SHIPPING_METHOD_PRICE;
        $carrier->range_behavior = 0;
        $carrier->need_range = true;
        $carrier->add();
        $groups = array();
        foreach (Group::getGroups($id_lang) as $row) {
            $groups[] = (int) $row['id_group'];
        }
        $carrier->setGroups($groups);
        $carrier->addZone((int) $country->id_zone);
        $range = new RangePrice();
        $range->id_carrier = (int) $carrier->id;
        $range->delimiter1 = 0;
        $range->delimiter2 = 1000000;
        $range->add();
        $carrier->setTaxRulesGroup(0);
        Configuration::updateValue('TWO_OPP_TEST_ID_CARRIER', (int) $carrier->id);
    }
    // RangePrice::add() seeds a 0.00 price per zone, which core would read first.
    $range = Db::getInstance()->getValue('SELECT id_range_price FROM `' . _DB_PREFIX_ . 'range_price` WHERE id_carrier = ' . (int) $carrier->id);
    Db::getInstance()->delete('delivery', 'id_carrier = ' . (int) $carrier->id);
    $carrier->addDeliveryPrice(array(array(
        'id_shop' => null, 'id_shop_group' => null, 'id_carrier' => (int) $carrier->id,
        'id_range_price' => (int) $range, 'id_range_weight' => null,
        'id_zone' => (int) $country->id_zone, 'price' => 29.00,
    )));

    $cart = oppStoredObject('TWO_OPP_TEST_ID_CART', 'Cart');
    if ($cart === null) {
        $cart = new Cart();
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_delivery = (int) $address->id;
        $cart->id_address_invoice = (int) $address->id;
        $cart->id_lang = $id_lang;
        $cart->id_currency = (int) $context->currency->id;
        $cart->id_shop = (int) $context->shop->id;
        $cart->id_shop_group = (int) $context->shop->id_shop_group;
        $cart->add();
        $context->cart = $cart;
        $cart->updateQty(1, (int) $product->id);
        $cart->id_carrier = (int) $carrier->id;
        $cart->delivery_option = json_encode(array((int) $address->id => (int) $carrier->id . ','));
        $cart->update();
        Configuration::updateValue('TWO_OPP_TEST_ID_CART', (int) $cart->id);
    }

    if (oppStoredObject('TWO_OPP_TEST_ID_ORDER', 'Order') === null) {
        // A bare order row: the status and slip hooks load it by id; validateOrder() would fire the whole order pipeline.
        $order = new Order();
        $order->id_address_delivery = (int) $address->id;
        $order->id_address_invoice = (int) $address->id;
        $order->id_cart = (int) $cart->id;
        $order->id_currency = (int) $cart->id_currency;
        $order->id_lang = $id_lang;
        $order->id_customer = (int) $customer->id;
        $order->id_carrier = (int) $carrier->id;
        $order->id_shop = (int) $context->shop->id;
        $order->id_shop_group = (int) $context->shop->id_shop_group;
        $order->module = 'twopayment';
        $order->payment = OPP_LABEL;
        $order->secure_key = (string) $customer->secure_key;
        $order->conversion_rate = 1;
        $order->total_paid = 150.00;
        $order->total_paid_real = 150.00;
        $order->total_products = 100.00;
        $order->total_products_wt = 121.00;
        // An update replays the placed order (TWO-26085): 29.00 of shipping on the "No tax" carrier.
        $order->total_paid_tax_incl = 150.00;
        $order->total_paid_tax_excl = 129.00;
        $order->total_shipping = 29.00;
        $order->total_shipping_tax_incl = 29.00;
        $order->total_shipping_tax_excl = 29.00;
        $order->carrier_tax_rate = 0;
        $order->reference = 'OPPPROBE';
        if (!$order->add()) {
            throw new RuntimeException('could not create the probe order');
        }
        Configuration::updateValue('TWO_OPP_TEST_ID_ORDER', (int) $order->id);
    }

    $id_order = (int) Configuration::get('TWO_OPP_TEST_ID_ORDER');
    if (!(int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_detail` WHERE id_order = ' . $id_order)) {
        // The product line as core records it at placement, so the update has a placed order to replay.
        Db::getInstance()->insert('order_detail', array(
            'id_order' => $id_order,
            'id_shop' => (int) $context->shop->id,
            'id_warehouse' => 0,
            'product_id' => (int) $product->id,
            'product_attribute_id' => 0,
            'product_name' => pSQL(OPP_LABEL . ' lamp'),
            'product_quantity' => 1,
            'product_price' => 100.0,
            'unit_price_tax_excl' => 100.0,
            'unit_price_tax_incl' => 121.0,
            'total_price_tax_excl' => 100.0,
            'total_price_tax_incl' => 121.0,
            'id_tax_rules_group' => (int) $group->id,
            'tax_computation_method' => 0,
        ));
        // Any 21% tax will do: the update reads the rate its row records.
        $id_tax = (int) Db::getInstance()->getValue('SELECT id_tax FROM `' . _DB_PREFIX_ . 'tax` WHERE rate = 21 ORDER BY id_tax DESC');
        $ok = Db::getInstance()->insert('order_detail_tax', array(
            'id_order_detail' => (int) Db::getInstance()->getValue('SELECT MAX(id_order_detail) FROM `' . _DB_PREFIX_ . 'order_detail` WHERE id_order = ' . $id_order),
            'id_tax' => $id_tax,
            'unit_amount' => 21.0,
            'total_amount' => 21.0,
        ));
        if (!$ok || $id_tax <= 0) {
            throw new RuntimeException('could not record the probe order line\'s tax: ' . Db::getInstance()->getMsgError());
        }
    }
}

/**
 * @return array{0:Cart,1:Customer,2:Address,3:Order}
 */
function oppFixture()
{
    oppBootKernel();
    $cart = new Cart((int) Configuration::get('TWO_OPP_TEST_ID_CART'));
    $customer = new Customer((int) $cart->id_customer);
    $address = new Address((int) $cart->id_address_invoice);
    $context = Context::getContext();
    $context->cart = $cart;
    $context->customer = $customer;
    $context->language = new Language((int) $cart->id_lang);
    $context->currency = new Currency((int) $cart->id_currency);
    $context->country = new Country((int) $address->id_country);
    if (is_object($context->cookie)) {
        $context->cookie->id_lang = (int) $cart->id_lang;
        $context->cookie->id_customer = (int) $customer->id;
        $context->cookie->id_currency = (int) $cart->id_currency;
    }

    return array($cart, $customer, $address, new Order((int) Configuration::get('TWO_OPP_TEST_ID_ORDER')));
}

function oppMerchantUrls()
{
    return array(
        'merchant_confirmation_url' => 'https://shop.local/confirm', 'merchant_cancel_order_url' => 'https://shop.local/cancel',
        'merchant_edit_order_url' => '', 'merchant_order_verification_failed_url' => '', 'merchant_invoice_url' => '', 'merchant_shipping_document_url' => '',
    );
}

function oppLine(array $payload, $type)
{
    foreach ($payload['line_items'] as $line) {
        if ($line['type'] === $type) {
            return array($line['net_amount'], $line['tax_amount'], $line['gross_amount']);
        }
    }

    return null;
}

/**
 * @return array<int,array{0:mixed,1:mixed,2:string}> [got, expected, what]
 */
function oppRunScenario($name, &$detail)
{
    list($cart, $customer, $address, $order) = oppFixture();
    Configuration::updateValue('TWO_OPP_TEST_RATE', '0.21');
    Configuration::updateValue('PS_TWO_DEBUG_MODE', '0');
    Configuration::updateValue('PS_MAIL_METHOD', 3);
    $mode = in_array($name, array('unarmed', 'unhandled', 'paths', 'context_rate', 'relay'), true) ? (in_array($name, array('paths', 'relay'), true) ? 'record' : '') : ($name === 'throws_prod' ? 'throws' : $name);
    $mode = $name === 'carrierless' ? 'record' : (in_array($name, array('carrierless_resplit', 'refund_resplit'), true) ? 'resplit' : $mode);
    Configuration::updateValue('TWO_OPP_TEST_MODE', $mode);
    $module = new OppProbeTwopayment();
    $checks = array();
    $currency = new Currency((int) $cart->id_currency);

    if ($name === 'refund_resplit') {
        // A credit slip refunding the "No tax" carrier's 29.00: the README subscriber re-splits its untaxed share at 21%.
        $module->twoState = 'FULFILLED';
        $slip = new stdClass();
        $slip->id = 8;
        $slip->id_order = (int) $order->id;
        $slip->total_products_tax_incl = 0.0;
        $slip->total_shipping_tax_incl = 29.0;
        Module::getInstanceByName('twoorderpostprocessingtest');
        Twoorderpostprocessingtest::$calls = array();
        $module->hookActionOrderSlipAdd(array('order' => $order, 'order_slip' => $slip));
        $subtotals = function ($payload) {
            return is_array($payload) && isset($payload['tax_subtotals']) ? array_map(function ($s) {
                return array($s['tax_rate'], $s['taxable_amount'], $s['tax_amount']);
            }, $payload['tax_subtotals']) : $payload;
        };
        $calls = Twoorderpostprocessingtest::$calls;
        $refunds = array_values(array_filter($module->sent, function ($r) {
            return strpos($r['endpoint'], '/refund') !== false;
        }));
        $checks[] = array(count($calls) === 1 ? $subtotals($calls[0]['payload_in']) : count($calls), array(array('0.000000', '29.00', '0.00')), 'the slip reaches the hook at 0%');
        $checks[] = array(count($refunds) === 1 ? $subtotals($refunds[0]['payload']) : count($refunds), array(array('0.210000', '23.97', '5.03')), 'the re-split slip is sent as returned');
        $checks[] = array(count($refunds) === 1 ? $refunds[0]['payload']['amount'] : null, '29.00', 'the amount is unchanged');

        return $checks;
    }

    if ($name === 'carrierless' || $name === 'carrierless_resplit') {
        return oppCarrierlessChecks($module, $name === 'carrierless');
    }

    if ($name === 'unarmed') {
        $payload = $module->getTwoNewOrderData('opp-attempt', $cart, oppMerchantUrls());
        $checks[] = array(oppLine($payload, 'SHIPPING_FEE'), array('29.00', '0.00', '29.00'), 'shipping as the shop recorded it');
        $checks[] = array(array($payload['net_amount'], $payload['tax_amount'], $payload['gross_amount']), array('129.00', '21.00', '150.00'), 'order totals as the shop recorded them');
        $checks[] = array(class_exists('Twoorderpostprocessingtest', false) ? count(Twoorderpostprocessingtest::$calls) : 0, 0, 'an inert subscriber records nothing');
        $checks[] = array(oppLoggedNow('has an order postprocessing hook handler (twoorderpostprocessingtest): the shop-match checks are delegated to it'), true, 'an enabled handler owns the shop-match checks, armed or not');

        return $checks;
    }

    if ($name === 'context_rate') {
        $carrier = new Carrier((int) $cart->id_carrier);
        $checks[] = array($module->buildTwoOrderPostprocessingContext('order_create', 'probe', '/v1/order', $cart)['shipping_tax_rate'], 0.0, '"No tax" carrier: shipping_tax_rate 0');
        $carrier->setTaxRulesGroup((int) Configuration::get('TWO_OPP_TEST_TRG'));
        Cache::clean('*');
        $checks[] = array($module->buildTwoOrderPostprocessingContext('order_create', 'probe', '/v1/order', new Cart((int) $cart->id))['shipping_tax_rate'], 0.21, '21% carrier group: shipping_tax_rate 0.21, whatever the line was taxed');
        $carrier->setTaxRulesGroup(0);

        return $checks;
    }

    if ($name === 'paths') {
        $drivers = array(
            array('order_intent', 'precheck', 0, function () use ($module, $cart, $customer, $currency, $address) {
                $module->getTwoIntentOrderData($cart, $customer, $currency, $address);
            }),
            array('order_intent', 'strict_intent', 1, function () use ($module, $cart, $customer, $currency, $address) {
                $module->checkTwoOrderIntentApprovalAtPayment($cart, $customer, $currency, $address);
            }),
            array('order_create', 'checkout', 0, function () use ($module, $cart) {
                $module->getTwoNewOrderData('opp-attempt', $cart, oppMerchantUrls());
            }),
            array('order_create', 'snapshot_hash', 0, function () use ($module, $cart) {
                $module->getTwoNewOrderData('opp-attempt', $cart, oppMerchantUrls(), false, 'snapshot_hash');
            }),
            array('order_update', 'admin_edit', 1, function () use ($module, $order) {
                $module->hookActionOrderEdited(array('order' => $order));
            }),
            array('order_update', 'tracking_number', 1, function () use ($module, $order) {
                $module->hookActionAdminOrdersTrackingNumberUpdate(array('order' => $order));
            }),
            array('order_confirm', 'payment_return', 1, function () use ($module, $order) {
                $module->confirmTwoOrder(OPP_TWO_ORDER, 'payment_return', null, $order);
            }),
            array('capture', 'status_change', 1, function () use ($module, $order) {
                $module->twoState = 'CONFIRMED';
                Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode(array((int) Configuration::get('PS_OS_SHIPPING'))));
                $module->hookActionOrderStatusUpdate(array('id_order' => (int) $order->id, 'newOrderStatus' => new OrderState((int) Configuration::get('PS_OS_SHIPPING'), (int) $order->id_lang)));
            }),
            array('refund', 'status_change', 1, function () use ($module, $order) {
                $module->twoState = 'FULFILLED';
                $module->hookActionOrderStatusUpdate(array('id_order' => (int) $order->id, 'newOrderStatus' => new OrderState((int) Configuration::get('PS_TWO_OS_REFUNDED_MAP'), (int) $order->id_lang)));
            }),
            array('refund', 'credit_slip', 1, function () use ($module, $order) {
                $module->twoState = 'FULFILLED';
                $slip = new stdClass();
                $slip->id = 7;
                $slip->id_order = (int) $order->id;
                $slip->total_products_tax_incl = 30.0;
                $slip->total_shipping_tax_incl = 0.0;
                $module->hookActionOrderSlipAdd(array('order' => $order, 'order_slip' => $slip));
            }),
            array('cancel', 'status_change', 1, function () use ($module, $order) {
                $module->hookActionOrderStatusUpdate(array('id_order' => (int) $order->id, 'newOrderStatus' => new OrderState((int) Configuration::get('PS_TWO_OS_CANCELLED_MAP'), (int) $order->id_lang)));
            }),
            array('cancel', 'attempt_persist_failed', 1, function () use ($module) {
                $module->cancelTwoOrderBestEffort(OPP_TWO_ORDER, 'attempt_persist_failed');
            }),
        );
        $keys = array('request_type', 'trigger', 'endpoint', 'cart', 'order', 'shipping_tax_rate', 'fallback_shipping_tax_rate', 'contract_version', 'order_lines', 'order_refunds');
        // Loads the fixture's class, whose static records the calls.
        Module::getInstanceByName('twoorderpostprocessingtest');
        foreach ($drivers as $driver) {
            list($type, $trigger, $sends, $run) = $driver;
            $label = $type . '/' . $trigger;
            Twoorderpostprocessingtest::$calls = array();
            $module->sent = array();
            // An earlier refund at Two, except on the Refunded status, where it would make the refund a remainder.
            $module->twoRefunds = $label === 'refund/status_change' ? array() : oppTwoRefunds();
            $run();
            $calls = Twoorderpostprocessingtest::$calls;
            $checks[] = array(count($calls), 1, $label . ': fired exactly once');
            if (count($calls) !== 1) {
                continue;
            }
            $context = $calls[0]['context'];
            $checks[] = array(array_keys($context), $keys, $label . ': context keys');
            // A best-effort cancel knows only the Two order id.
            $cartClass = $trigger === 'attempt_persist_failed' ? null : 'Cart';
            $checks[] = array(array($context['request_type'], $context['trigger'], $context['contract_version'], $context['cart']), array($type, $trigger, 1, $cartClass), $label . ': context');
            // The refunds and the updates are given the order's lines at Two; the others read no order from Two, or are not given it.
            $placed = in_array($type, array('refund', 'order_update'), true) ? oppTwoLines() : null;
            $checks[] = array($context['order_lines'], $placed, $label . ': order_lines');
            // The refunds Two holds come from the same read, verbatim (TWO-26287).
            $checks[] = array($context['order_refunds'], $placed === null ? null : $module->twoRefunds, $label . ': order_refunds');
            if ($placed !== null && $module->twoRefunds !== array()) {
                $checks[] = array(Twoorderpostprocessingtest::leftToRefund($context, 'line-1'), 108.9, $label . ': the README left to refund on line-1');
            }
            $checks[] = array($calls[0]['payload_out'], $calls[0]['payload_in'], $label . ': a recording subscriber changes nothing');
            $sent = array_values(array_filter($module->sent, function ($r) {
                return strpos($r['endpoint'], '/v1/order') === 0;
            }));
            $checks[] = array(count($sent), $sends, $label . ': sends');
            if ($sends === 1 && count($sent) === 1) {
                $checks[] = array($sent[0]['payload'], $calls[0]['payload_out'], $label . ': sent exactly the post-hook payload');
            }
        }

        return $checks;
    }

    if ($name === 'relay') {
        return oppRelayChecks($module, $cart, $customer, $currency, $address);
    }

    if ($name === 'body_on_cancel') {
        $module->sendTwoOrderRequest('cancel', 'status_change', '/v1/order/' . OPP_TWO_ORDER . '/cancel', array(), 'POST', null, $order);
        $checks[] = array(count($module->sent) === 1 ? $module->sent[0]['payload'] : $module->sent, array('reason' => 'added'), 'a cancel given a body sends it as returned');

        return $checks;
    }

    if (strpos($name, 'outside_carrier_parity_') === 0) {
        return oppOutsideCarrierParityChecks($module, substr($name, strlen('outside_carrier_parity_')), $cart, $order);
    }
    if (in_array($name, array('unhandled', 'outside_carrier_line', 'outside_carrier_line_checked', 'outside_carrier_shop_match'), true)) {
        return oppOutsideCarrierChecks($module, $name, $cart, $order);
    }
    if (in_array($name, array('surcharge_unhandled', 'surcharge_resplit'), true)) {
        return oppSurchargeChecks($module, $name === 'surcharge_resplit', $cart, $order);
    }

    // Order create under one fixture behaviour: sent as returned, unless the subscriber has a code bug. A payload that
    // does not add up is sent as returned too: Two's API judges it (TWO-26283).
    $expected = array(
        'resplit' => null,
        'gross_change' => null,
        'off_by_cent' => null,
        'stale_totals' => null,
        'stale_subtotals' => null,
        'no_lines' => null,
        'throws' => 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED',
        'throws_prod' => 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED',
        'non_array' => 'TWO_ORDER_POSTPROCESSING_HOOK_FAILED',
    );
    if ($name === 'throws_prod') {
        $checks[] = array(_PS_MODE_DEV_, false, 'debug mode is off');
    }
    Configuration::updateValue('PS_TWO_DEBUG_MODE', '1');
    Module::getInstanceByName('twoorderpostprocessingtest');
    Twoorderpostprocessingtest::$calls = array();
    $payload = null;
    $code = null;
    try {
        $payload = $module->getTwoNewOrderData('opp-attempt', $cart, oppMerchantUrls());
    } catch (TwoOrderPostprocessingException $e) {
        $code = $e->getTwoCode();
    } catch (Exception $e) {
        $code = get_class($e) . ': ' . $e->getMessage();
    }
    Configuration::updateValue('PS_TWO_DEBUG_MODE', '0');
    $checks[] = array($code, $expected[$name], 'outcome');
    // Not scoped to this run: 1.7 drops a message identical to one already logged.
    $logged = function ($needle) {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . "log` WHERE message LIKE '%" . pSQL($needle) . "%'"
        ) > 0;
    };
    if ($expected[$name] !== null && strpos($expected[$name], 'TWO_') === 0) {
        $checks[] = array($logged($expected[$name] . ' - the order_create request (checkout) was not sent'), true, 'the failure is logged');

        return $checks;
    }
    if ($expected[$name] !== null) {
        $checks[] = array(count(Twoorderpostprocessingtest::$calls), 1, 'refused after the hook ran');

        return $checks;
    }
    $calls = Twoorderpostprocessingtest::$calls;
    $checks[] = array(count($calls) === 1 ? $calls[0]['payload_out'] : count($calls), $payload, 'returned exactly what the subscriber left');
    if ($name !== 'no_lines') {
        $checks[] = array(oppLine($payload, 'SHIPPING_FEE')[1], '5.03', 'shipping tax re-split');
    } else {
        $checks[] = array($payload['line_items'], array(), 'sent with no lines');
    }
    if (in_array($name, array('resplit', 'gross_change'), true)) {
        $totals = $name === 'gross_change' ? array('133.97', '28.13', '162.10') : array('123.97', '26.03', '150.00');
        $checks[] = array(array($payload['net_amount'], $payload['tax_amount'], $payload['gross_amount']), $totals, 'order totals');
    }
    $checks[] = array($logged('The order postprocessing hook changed the order_create request (checkout)'), true, 'the Debug Mode diff is logged');

    return $checks;
}

/**
 * Whether a module log line written by this process contains $needle.
 *
 * @param string $needle
 * @return bool
 */
function oppLoggedNow($needle)
{
    return (int) Db::getInstance()->getValue(
        'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . "log` WHERE id_log > " . (int) $GLOBALS['opp_first_log'] . " AND message LIKE '%" . pSQL($needle) . "%'"
    ) > 0;
}

/**
 * TWO-26274: a cost the shop adds to the cart total outside any carrier, the carrier-less cart's "external_only"
 * shape: 29.00 in Cart::BOTH and none in ONLY_SHIPPING. With no merchant handler the module's default handler
 * refuses it, as it always did: the order intent pre-check only warns, the strict intent and the create are
 * refused. A handler that adds the cost as a 21% shipping line owns the shop-match checks, and each request is
 * sent as it returns it, also when it opts back in to the checks on single lines. One that opts back in on the
 * payload it was given gets the module's refusal. A placed order's update carries the cost in its shipping line
 * already (TWO-26085) and is built either way; the handler cuts that line back to the carrier's shipping and adds
 * the cost as its own line, as on the create.
 *
 * @return array<int,array{0:mixed,1:mixed,2:string}>
 */
function oppOutsideCarrierChecks(OppProbeTwopayment $module, $name, Cart $oppCart, Order $order)
{
    $checks = array();
    if ($name === 'unhandled') {
        // The probe's own cart, which the module's checks pass: built as the shop recorded it, as with no hook at all.
        $payload = $module->getTwoNewOrderData('opp-attempt', $oppCart, oppMerchantUrls());
        $checks[] = array(array($payload['net_amount'], $payload['tax_amount'], $payload['gross_amount'], oppLine($payload, 'SHIPPING_FEE')), array('129.00', '21.00', '150.00', array('29.00', '0.00', '29.00')), 'a passing order with no handler: built as the shop recorded it');
    }
    $cart = new Cart((int) Configuration::get('TWO_CARRIERLESS_TEST_ID_CART'));
    if (!Validate::isLoadedObject($cart)) {
        return array(array('no carrier-less cart', 'the seeded carrier-less cart', 'dev/ci/seed-carrierless-cart.sh has run'));
    }
    $customer = new Customer((int) $cart->id_customer);
    $address = new Address((int) $cart->id_address_invoice);
    $context = Context::getContext();
    $context->cart = $cart;
    $context->customer = $customer;
    $context->language = new Language((int) $cart->id_lang);
    $context->currency = new Currency((int) $cart->id_currency);
    $context->country = new Country((int) (new Address((int) $cart->id_address_delivery))->id_country);
    $saved = array();
    foreach (array('TWO_CARRIERLESS_TEST_NET', 'TWO_CARRIERLESS_TEST_MODE') as $key) {
        $saved[$key] = Configuration::get($key);
    }
    Configuration::updateValue('TWO_CARRIERLESS_TEST_GROSS', '29.00');
    Configuration::updateValue('TWO_CARRIERLESS_TEST_NET', '29.00');
    Configuration::updateValue('TWO_CARRIERLESS_TEST_MODE', 'external_only');
    Configuration::updateValue('TWO_OPP_TEST_MODE', $name === 'outside_carrier_shop_match' ? 'shop_match' : ($name === 'unhandled' ? '' : $name));
    $paid = array((float) $order->total_paid_tax_incl, (float) $order->total_paid_tax_excl);
    $handled = in_array($name, array('outside_carrier_line', 'outside_carrier_line_checked'), true);
    $refusal = 'TwoCheckoutAmountException: Order totals do not reconcile with cart totals: ';
    $run = function ($build) {
        try {
            return array(null, $build());
        } catch (TwoOrderPostprocessingException $e) {
            return array($e->getTwoCode(), null);
        } catch (Exception $e) {
            return array(get_class($e) . ': ' . $e->getMessage(), null);
        }
    };
    $added = function ($payload) {
        foreach (is_array($payload) ? $payload['line_items'] : array() as $line) {
            if ($line['name'] === 'Delivery') {
                return array($line['type'], $line['tax_rate'], $line['net_amount'], $line['tax_amount'], $line['gross_amount']);
            }
        }

        return null;
    };
    $line = array('SHIPPING_FEE', '0.21', '23.97', '5.03', '29.00');
    try {
        $checks[] = array((float) $cart->getOrderTotal(true, Cart::BOTH) - (float) $cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), 29.0, 'the cart total carries 29.00 beyond its products');
        $checks[] = array((float) $cart->getOrderTotal(true, Cart::ONLY_SHIPPING), 0.0, 'none of it is carrier shipping');

        list($error, $payload) = $run(function () use ($module, $cart, $customer, $address, $context) {
            return $module->getTwoIntentOrderData($cart, $customer, $context->currency, $address);
        });
        $checks[] = array(array($error, $added($payload)), array(null, $handled ? $line : null), 'precheck: built, the added line as returned');
        if (!$handled) {
            // Not scoped to this run: 1.7 drops a message identical to one already logged.
            $checks[] = array((int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . "log` WHERE message LIKE '%order intent reconciliation drift logged as warning-only%'") > 0, true, 'precheck: the drift is only a warning');
        }

        $module->sent = array();
        $result = $module->checkTwoOrderIntentApprovalAtPayment($cart, $customer, $context->currency, $address);
        $intents = array_values(array_filter($module->sent, function ($r) {
            return $r['endpoint'] === '/v1/order_intent';
        }));
        $checks[] = array(count($intents) === 1 ? $added($intents[0]['payload']) : count($intents), $handled ? $line : 0, 'strict intent: sent with the added line, or refused unsent');
        if (!$handled) {
            $checks[] = array(strpos((string) $result['message'], 'Order totals do not reconcile with cart totals') !== false, true, 'strict intent: the module\'s refusal');
        }

        list($error, $payload) = $run(function () use ($module, $cart) {
            return $module->getTwoNewOrderData('opp-outside', $cart, oppMerchantUrls());
        });
        $checks[] = array(array(substr((string) $error, 0, strlen($refusal)), $added($payload)), $handled ? array('', $line) : array($refusal, null), 'create: sent with the added line, or refused');
        if ($handled) {
            $checks[] = array($payload['gross_amount'], number_format((float) $cart->getOrderTotal(true, Cart::BOTH), 2, '.', ''), 'create: the order gross is now the cart\'s');
            $calls = Twoorderpostprocessingtest::$calls;
            $checks[] = array(count($calls) > 0 ? end($calls)['payload_out'] : null, $payload, 'create: exactly what the handler returned');
        }

        if ($name !== 'outside_carrier_shop_match') {
            // The placed order, its total carrying the same 29.00 beyond what its lines describe.
            Db::getInstance()->update('orders', array('total_paid_tax_incl' => $paid[0] + 29.0, 'total_paid_tax_excl' => $paid[1] + 29.0), 'id_order = ' . (int) $order->id);
            Cache::clean('*');
            $placed = new Order((int) $order->id);
            list($error, $payload) = $run(function () use ($module, $placed) {
                return $module->getTwoUpdateOrderData($placed, $module->getTwoOrderPaymentData((int) $placed->id), 'admin_edit');
            });
            // The module's update line holds the opp order's own 29.00 of untaxed carrier shipping and the 29.00 beyond
            // it; a handler cuts it back to the carrier's and adds the cost as on the create.
            $checks[] = array(array($error, $added($payload), is_array($payload) ? $payload['gross_amount'] : null, is_array($payload) ? oppLine($payload, 'SHIPPING_FEE') : null), array(null, $handled ? $line : null, number_format($paid[0] + 29.0, 2, '.', ''), $handled ? array('29.00', '0.00', '29.00') : array('58.00', '0.00', '58.00')), 'update: built, the cost as its own line beside the carrier\'s shipping, as on the create');
        }
    } finally {
        Db::getInstance()->update('orders', array('total_paid_tax_incl' => $paid[0], 'total_paid_tax_excl' => $paid[1]), 'id_order = ' . (int) $order->id);
        foreach ($saved as $key => $value) {
            Configuration::updateValue($key, $value === false ? '' : (string) $value);
        }
    }
    $checks[] = array(oppLoggedNow('the shop-match checks are delegated to it'), $name !== 'unhandled', 'the delegation line, only with a handler');

    return $checks;
}

/**
 * TWO-26274: the buyer fee's cart line is priced on the module's own lines, before the hook. With the Default shipping
 * tax code at 21%, the "No tax" carrier's untaxed shipping fails its declared-rate check. With no handler the cart gets
 * no fee line and the create is refused, as before. With a handler that re-splits that shipping, the cart carries the
 * fee, the create sends that fee, and the update of the order placed with it sends the same fee.
 *
 * @param bool $handled
 * @return array<int,array{0:mixed,1:mixed,2:string}>
 */
function oppSurchargeChecks(OppProbeTwopayment $module, $handled, Cart $cart, Order $order)
{
    $checks = array();
    $group = (int) Configuration::get('TWO_OPP_TEST_TRG');
    $config = array(
        'PS_TWO_SURCHARGE_TYPE' => 'percentage', 'PS_TWO_SURCHARGE_PCT_30' => '5', Twopayment::CONFIG_SURCHARGE_TAX_RULES_GROUP => (string) $group,
        Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS => '[30]', 'PS_TWO_PAYMENT_TERMS_30' => '1',
    );
    $saved = array();
    foreach ($config as $key => $value) {
        $saved[$key] = Configuration::get($key);
        Configuration::updateValue($key, $value);
    }
    // oppRun restores it.
    Configuration::updateValue('PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP', (string) $group);
    Configuration::updateValue('TWO_OPP_TEST_MODE', $handled ? 'resplit' : '');
    Context::getContext()->cookie->two_payment_term = 30;
    $fields = array('total_paid_tax_incl', 'total_paid_tax_excl', 'total_products', 'total_products_wt');
    $paid = array();
    foreach ($fields as $field) {
        $paid[$field] = (float) $order->$field;
    }
    $detail = 0;
    try {
        $error = null;
        $payload = null;
        try {
            $payload = $module->getTwoNewOrderData('opp-surcharge', $cart, oppMerchantUrls());
        } catch (Exception $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
        }
        $row = $module->getTwoSurchargeCartLine(new Cart((int) $cart->id));
        if (!$handled) {
            $refusal = 'TwoCheckoutAmountException: Declared tax rate diverges from applied tax amounts for shipping';
            $checks[] = array(substr((string) $error, 0, strlen($refusal)), $refusal, 'no handler: the create is refused on the shipping line, as before');
            $checks[] = array($row, null, 'no handler: the cart gets no fee line, as before');
            // Not scoped to this run: core drops a message identical to one already logged.
            $checks[] = array((int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . "log` WHERE message LIKE '%Surcharge cart line sync failed for cart " . (int) $cart->id . " - Declared tax rate diverges%'") > 0, true, 'no handler: the sync failure is logged, as before');

            return $checks;
        }
        $fee = is_array($payload) ? oppLine($payload, 'SERVICE') : null;
        $checks[] = array($error, null, 'a handler: the create is sent');
        $checks[] = array($fee, array('5.00', '1.05', '6.05'), 'a handler: the create sends the quoted fee');
        $checks[] = array($row === null ? null : array(number_format((float) $row['net'], 2, '.', ''), number_format((float) $row['gross'], 2, '.', '')), is_array($fee) ? array($fee[0], $fee[2]) : $fee, 'a handler: the cart carries the fee the create sends');
        if ($row === null) {
            return $checks;
        }
        // The order placed from that cart: its fee row as core records it, and its totals carrying it.
        $id_order = (int) $order->id;
        Db::getInstance()->insert('order_detail', array(
            'id_order' => $id_order, 'id_shop' => (int) Context::getContext()->shop->id, 'id_warehouse' => 0,
            'product_id' => (int) $module->getTwoSurchargeCartProductId(false), 'product_attribute_id' => 0,
            'product_name' => 'Payment terms fee', 'product_reference' => Twopayment::TWO_SURCHARGE_PRODUCT_REFERENCE,
            'product_quantity' => 1, 'product_price' => (float) $row['net'], 'unit_price_tax_excl' => (float) $row['net'],
            'unit_price_tax_incl' => (float) $row['gross'], 'total_price_tax_excl' => (float) $row['net'],
            'total_price_tax_incl' => (float) $row['gross'], 'id_tax_rules_group' => $group, 'tax_computation_method' => 0,
        ));
        $detail = (int) Db::getInstance()->Insert_ID();
        $tax = round((float) $row['gross'] - (float) $row['net'], 2);
        Db::getInstance()->insert('order_detail_tax', array(
            'id_order_detail' => $detail,
            'id_tax' => (int) Db::getInstance()->getValue('SELECT id_tax FROM `' . _DB_PREFIX_ . 'tax` WHERE rate = 21 ORDER BY id_tax DESC'),
            'unit_amount' => $tax, 'total_amount' => $tax,
        ));
        $add = array('total_paid_tax_incl' => (float) $row['gross'], 'total_paid_tax_excl' => (float) $row['net'], 'total_products' => (float) $row['net'], 'total_products_wt' => (float) $row['gross']);
        $placedTotals = array();
        foreach ($fields as $field) {
            $placedTotals[$field] = $paid[$field] + $add[$field];
        }
        Db::getInstance()->update('orders', $placedTotals, 'id_order = ' . $id_order);
        Cache::clean('*');
        $placed = new Order($id_order);
        $error = null;
        $updated = null;
        try {
            $updated = $module->getTwoUpdateOrderData($placed, $module->getTwoOrderPaymentData($id_order), 'admin_edit');
        } catch (Exception $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
        }
        $checks[] = array(array($error, is_array($updated) ? oppLine($updated, 'SERVICE') : null), array(null, $fee), 'a handler: the update sends the same fee');
    } finally {
        if ($detail > 0) {
            Db::getInstance()->delete('order_detail_tax', 'id_order_detail = ' . $detail);
            Db::getInstance()->delete('order_detail', 'id_order_detail = ' . $detail);
        }
        Db::getInstance()->update('orders', $paid, 'id_order = ' . (int) $order->id);
        $feeId = (int) $module->getTwoSurchargeCartProductId(false);
        if ($feeId > 0) {
            $cart->deleteProduct($feeId);
            SpecificPrice::deleteByIdCart((int) $cart->id, $feeId);
        }
        foreach ($saved as $key => $value) {
            Configuration::updateValue($key, $value === false ? '' : (string) $value);
        }
        unset(Context::getContext()->cookie->two_payment_term);
    }
    $checks[] = array(oppLoggedNow('the shop-match checks are delegated to it'), $handled, 'the delegation line, only with a handler');

    return $checks;
}

/**
 * TWO-26274: the README's outside-carrier handler sends the same lines on an order create and on the update of the
 * order placed from that cart. The probe's own cart gets 29.00 outside any carrier on top of its carrier shipping,
 * which is untaxed (the "No tax" carrier), taxed (the carrier at the 21% group) or none (free shipping), and its
 * placed order is set to the totals the cart charges. Create and update must carry the same lines, amounts, rates
 * and tax.
 *
 * @param string $shape untaxed, taxed or none
 * @return array<int,array{0:mixed,1:mixed,2:string}>
 */
function oppOutsideCarrierParityChecks(OppProbeTwopayment $module, $shape, Cart $cart, Order $order)
{
    $carrier = new Carrier((int) $cart->id_carrier);
    $fields = array('total_paid', 'total_paid_tax_incl', 'total_paid_tax_excl', 'total_shipping', 'total_shipping_tax_incl', 'total_shipping_tax_excl', 'carrier_tax_rate');
    $savedOrder = Db::getInstance()->getRow('SELECT `' . implode('`, `', $fields) . '` FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order = ' . (int) $order->id);
    $savedConfig = array();
    foreach (array('TWO_CARRIERLESS_TEST_MODE', 'PS_SHIPPING_FREE_PRICE') as $key) {
        $savedConfig[$key] = Configuration::get($key);
    }
    $project = function (array $payload) {
        return array_map(function ($line) {
            return array($line['type'], $line['name'], $line['net_amount'], $line['tax_amount'], $line['gross_amount'], (string) $line['tax_rate'], (int) $line['quantity']);
        }, $payload['line_items']);
    };
    $checks = array();
    // Armed, the carrier-less fixture replaces every cart's delivery options (PS 8+); here only its Cart override's
    // cost outside any carrier is wanted, on this cart's own carrier.
    $carrierless = Module::getInstanceByName('twocarrierlesstest');
    $rehook = $carrierless && $carrierless->isRegisteredInHook('actionFilterDeliveryOptionList') && $carrierless->unregisterHook('actionFilterDeliveryOptionList');
    try {
        Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'two_test_external_shipping` (id_cart, id_product, id_carrier_reference) VALUES (' . (int) $cart->id . ', 0, ' . (int) $carrier->id_reference . ')');
        Configuration::updateValue('TWO_CARRIERLESS_TEST_GROSS', '29.00');
        Configuration::updateValue('TWO_CARRIERLESS_TEST_MODE', 'external_only');
        Configuration::updateValue('TWO_OPP_TEST_MODE', 'outside_carrier_line');
        if ($shape === 'taxed') {
            $carrier->setTaxRulesGroup((int) Configuration::get('TWO_OPP_TEST_TRG'));
        }
        if ($shape === 'none') {
            Configuration::updateValue('PS_SHIPPING_FREE_PRICE', '1');
        }
        Cache::clean('*');
        $cart = new Cart((int) $cart->id);
        $ship = array(round((float) $cart->getOrderTotal(true, Cart::ONLY_SHIPPING), 2), round((float) $cart->getOrderTotal(false, Cart::ONLY_SHIPPING), 2));
        $both = array(round((float) $cart->getOrderTotal(true, Cart::BOTH), 2), round((float) $cart->getOrderTotal(false, Cart::BOTH), 2));
        $checks[] = array(array($ship[0] > 0, $ship[0] > $ship[1]), array($shape !== 'none', $shape === 'taxed'), 'the cart\'s carrier shipping is ' . $shape);
        $checks[] = array(round($both[0] - $ship[0], 2), 150.0, 'the cart total carries 29.00 beyond its products and carrier shipping');
        // The order as placed from this cart.
        Db::getInstance()->update('orders', array(
            'total_paid' => $both[0], 'total_paid_tax_incl' => $both[0], 'total_paid_tax_excl' => $both[1],
            'total_shipping' => $ship[0], 'total_shipping_tax_incl' => $ship[0], 'total_shipping_tax_excl' => $ship[1],
            'carrier_tax_rate' => $shape === 'taxed' ? 21 : 0,
        ), 'id_order = ' . (int) $order->id);

        $created = $module->getTwoNewOrderData('opp-parity', $cart, oppMerchantUrls());
        Cache::clean('*');
        $placed = new Order((int) $order->id);
        $updated = $module->getTwoUpdateOrderData($placed, $module->getTwoOrderPaymentData((int) $placed->id), 'admin_edit');
        $checks[] = array($project($updated), $project($created), 'update: the same lines as the create');
        $checks[] = array(array($updated['net_amount'], $updated['tax_amount'], $updated['gross_amount']), array($created['net_amount'], $created['tax_amount'], $created['gross_amount']), 'update: the same totals as the create');
        $lines = $project($created);
        $checks[] = array(end($lines), array('SHIPPING_FEE', 'Delivery', '23.97', '5.03', '29.00', '0.21', 1), 'create: the cost as its own 21% line');
        $checks[] = array(count($lines), $shape === 'none' ? 2 : 3, 'create: the product, the carrier shipping if any, and the cost');
    } finally {
        Db::getInstance()->update('orders', $savedOrder, 'id_order = ' . (int) $order->id);
        Db::getInstance()->delete('two_test_external_shipping', 'id_cart = ' . (int) $cart->id);
        if ($shape === 'taxed') {
            $carrier->setTaxRulesGroup(0);
        }
        if ($rehook) {
            $carrierless->registerHook('actionFilterDeliveryOptionList');
        }
        foreach ($savedConfig as $key => $value) {
            Configuration::updateValue($key, $value === false ? '' : (string) $value);
        }
    }

    return $checks;
}

/**
 * TWO-26117: the carrier-less cart, the Default shipping tax code blank, 29.00 of shipping taxed (23.20 + 5.80) or
 * untaxed. Taxed, the line goes out at 0% with the tax charged, never refused; untaxed, the subscriber re-splits it
 * at 21% and the module sends what it returned.
 *
 * @return array<int,array{0:mixed,1:mixed,2:string}>
 */
function oppCarrierlessChecks(OppProbeTwopayment $module, $taxed)
{
    $cart = new Cart((int) Configuration::get('TWO_CARRIERLESS_TEST_ID_CART'));
    if (!Validate::isLoadedObject($cart)) {
        return array(array('no carrier-less cart', 'the seeded carrier-less cart', 'dev/ci/seed-carrierless-cart.sh has run'));
    }
    $customer = new Customer((int) $cart->id_customer);
    $address = new Address((int) $cart->id_address_invoice);
    $context = Context::getContext();
    $context->cart = $cart;
    $context->customer = $customer;
    $context->language = new Language((int) $cart->id_lang);
    $context->currency = new Currency((int) $cart->id_currency);
    $context->country = new Country((int) (new Address((int) $cart->id_address_delivery))->id_country);
    $saved = array();
    foreach (array('TWO_CARRIERLESS_TEST_NET', 'TWO_CARRIERLESS_TEST_MODE', 'PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP') as $key) {
        $saved[$key] = Configuration::get($key);
    }
    Configuration::updateValue('TWO_CARRIERLESS_TEST_GROSS', '29.00');
    Configuration::updateValue('TWO_CARRIERLESS_TEST_NET', $taxed ? '23.20' : '29.00');
    Configuration::updateValue('TWO_CARRIERLESS_TEST_MODE', '');
    Configuration::updateValue('PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP', '');
    Module::getInstanceByName('twoorderpostprocessingtest');
    Twoorderpostprocessingtest::$calls = array();
    $checks = array();
    try {
        $payload = $module->getTwoIntentOrderData($cart, $customer, $context->currency, $address);
        $shipping = function (array $payload) {
            foreach ($payload['line_items'] as $line) {
                if ($line['type'] === 'SHIPPING_FEE') {
                    return array((string) $line['tax_rate'], $line['net_amount'], $line['tax_amount'], $line['gross_amount']);
                }
            }

            return null;
        };
        $calls = Twoorderpostprocessingtest::$calls;
        $checks[] = array((int) $cart->id_carrier, 0, 'the cart has no carrier');
        $checks[] = array(count($calls) === 1 ? $shipping($calls[0]['payload_in']) : count($calls), $taxed ? array('0', '23.20', '5.80', '29.00') : array('0', '29.00', '0.00', '29.00'), 'the builder sends the line at 0% with the tax charged');
        $checks[] = array(count($calls) === 1 ? $calls[0]['payload_out'] : count($calls), $payload, 'the payload is what the subscriber returned');
        $checks[] = array($shipping($payload), $taxed ? array('0', '23.20', '5.80', '29.00') : array('0.21', '23.97', '5.03', '29.00'), $taxed ? 'sent at 0%, not refused' : 'the re-split is sent as returned');
    } finally {
        foreach ($saved as $key => $value) {
            Configuration::updateValue($key, $value === false ? '' : (string) $value);
        }
    }

    return $checks;
}

/**
 * Drive the checkout's order-intent controller as the browser does: the
 * pre-check, then the relay with a tampered payload posted beside the buyer
 * fields, then payment submit.
 *
 * @return array<int,array{0:mixed,1:mixed,2:string}>
 */
function oppRelayChecks(OppProbeTwopayment $module, Cart $cart, Customer $customer, Currency $currency, Address $address)
{
    require_once _PS_MODULE_DIR_ . 'twopayment/controllers/front/orderintent.php';
    $_GET['module'] = 'twopayment';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    Configuration::updateValue('PS_TWO_ENABLE_ORDER_INTENT', 1);
    Context::getContext()->customer = $customer;
    $buyer = array('token' => Tools::getToken(false), 'company' => 'Hook Probe AS', 'companyid' => '123456789', 'id_address_invoice' => (string) $address->id);
    $tampered = json_encode(array(
        'gross_amount' => '1.00', 'net_amount' => '1.00', 'tax_amount' => '0.00', 'currency' => 'EUR',
        'buyer' => array('company' => array('company_name' => 'Someone Else AS', 'organization_number' => '999999999', 'country_prefix' => 'NO')),
        'line_items' => array(array('name' => 'x', 'gross_amount' => '1.00')),
    ));
    $foreign = (int) Db::getInstance()->getValue('SELECT id_address FROM `' . _DB_PREFIX_ . 'address` WHERE deleted = 0 AND id_customer NOT IN (0, ' . (int) $customer->id . ')');
    $checks = array();

    $checked = oppDriveController($module, 'ajaxProcessCheckOrderIntent', $buyer);
    $checks[] = array(isset($checked['success']) ? $checked['success'] : $checked, true, 'the pre-check builds a payload');
    Twoorderpostprocessingtest::$calls = array();
    $module->sent = array();
    oppDriveController($module, 'ajaxProcessOrderIntent', $buyer + array('payload' => $tampered));
    $checks[] = array(count($module->sent), 1, 'the relay sends once');
    $relayed = isset($module->sent[0]) ? $module->sent[0]['payload'] : null;
    $checks[] = array(is_array($relayed) ? array($relayed['gross_amount'], count($relayed['line_items']), $relayed['buyer']['company']['organization_number']) : $relayed, array('150.00', 2, '123456789'), 'the relay sends the cart and the buyer fields, not the posted payload');
    $checks[] = array($relayed, isset($checked['payload']) ? $checked['payload'] : null, 'the relay sends what the pre-check built');
    $calls = Twoorderpostprocessingtest::$calls;
    $checks[] = array(count($calls) === 1 ? array($calls[0]['context']['request_type'], $calls[0]['context']['trigger']) : count($calls), array('order_intent', 'precheck'), 'the relay fires the hook once');
    $module->sent = array();
    $module->checkTwoOrderIntentApprovalAtPayment($cart, $customer, $currency, $address);
    $checks[] = array(isset($module->sent[0]) ? $module->sent[0]['payload'] : null, $relayed, 'the preview equals the payment-time intent');

    $module->sent = array();
    $refused = oppDriveController($module, 'ajaxProcessOrderIntent', array('id_address_invoice' => (string) $foreign) + $buyer);
    $checks[] = array(array($foreign > 0, isset($refused['error_code']) ? $refused['error_code'] : $refused, $module->sent), array(true, 'INVALID_REQUEST', array()), 'another customer\'s address is refused and nothing sent');
    $refused = oppDriveController($module, 'ajaxProcessOrderIntent', array('company' => "Evil\nCo") + $buyer);
    $checks[] = array(array(isset($refused['error_code']) ? $refused['error_code'] : $refused, $module->sent), array('invalid_request', array()), 'a malformed company field is refused and nothing sent');

    return $checks;
}

/**
 * @return mixed the JSON the controller answered with
 */
function oppDriveController(OppProbeTwopayment $module, $action, array $post)
{
    $_POST = $post;
    $_GET = array('module' => 'twopayment');
    $controller = new OppProbeOrderintentController();
    $controller->module = $module;
    try {
        $controller->{$action}();
    } catch (OppResponded $e) {
        return json_decode($e->getMessage(), true);
    }

    return 'no response';
}

function oppRun($name)
{
    $detail = '';
    $GLOBALS['opp_first_log'] = (int) Db::getInstance()->getValue('SELECT MAX(id_log) FROM `' . _DB_PREFIX_ . 'log`');
    // The carrier-less fixture, once armed by another probe, replaces every cart's delivery options.
    $carrierless = Configuration::get('TWO_CARRIERLESS_TEST_GROSS');
    Configuration::updateValue('TWO_CARRIERLESS_TEST_GROSS', '0');
    // Blank, as another probe may leave it: populated, it would check the "No tax" carrier's line before the hook (TWO-26117).
    $defaultGroup = Configuration::get('PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP');
    Configuration::updateValue('PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP', '');
    try {
        $checks = oppRunScenario($name, $detail);
    } catch (Throwable $e) {
        $checks = array(array(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 'no exception', 'scenario ran'));
    }
    Configuration::updateValue('TWO_CARRIERLESS_TEST_GROSS', $carrierless === false ? '0' : (string) $carrierless);
    Configuration::updateValue('PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP', $defaultGroup === false ? '' : (string) $defaultGroup);
    Configuration::updateValue('TWO_OPP_TEST_MODE', '');
    $failures = array();
    foreach ($checks as $check) {
        if ($check[0] !== $check[1]) {
            $failures[] = $check[2] . ': got ' . json_encode($check[0]) . ', expected ' . json_encode($check[1]);
        }
    }
    echo ($failures === array() ? '  PASS ' : '  FAIL ') . $name . ' (' . count($checks) . ' checks)' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '       - ' . $failure . PHP_EOL;
    }
    if ($failures !== array() && $detail !== '') {
        echo '       block: ' . $detail . PHP_EOL;
    }

    return $failures === array() ? 0 : 1;
}

$argument = isset($argv[1]) ? (string) $argv[1] : '';
if ($argument !== '') {
    exit(oppRun($argument));
}

echo 'Order postprocessing hook - integration probe (PrestaShop ' . _PS_VERSION_ . ')' . PHP_EOL;
if (!Module::isInstalled('twoorderpostprocessingtest')) {
    fwrite(STDERR, 'the fixture module is not installed - run dev/ci/install-order-postprocessing-fixture.sh first' . PHP_EOL);
    exit(2);
}
oppBootKernel();
oppSeed();
// The hidden buyer fee product, made here once: the surcharge scenarios price a shop that already has it, and making
// it records a stock movement, which needs an employee on the command line.
Context::getContext()->employee = new Employee((int) Db::getInstance()->getValue('SELECT MIN(id_employee) FROM `' . _DB_PREFIX_ . 'employee`'));
Module::getInstanceByName('twopayment')->getTwoSurchargeCartProductId(true);
$exit = 0;
$scenario_names = array('unhandled', 'unarmed', 'context_rate', 'paths', 'resplit', 'gross_change', 'off_by_cent', 'stale_totals', 'stale_subtotals', 'no_lines', 'outside_carrier_line', 'outside_carrier_line_checked', 'outside_carrier_shop_match', 'outside_carrier_parity_untaxed', 'outside_carrier_parity_taxed', 'outside_carrier_parity_none', 'throws', 'throws_prod', 'non_array', 'body_on_cancel', 'relay', 'refund_resplit', 'surcharge_unhandled', 'surcharge_resplit');
// The carrier-less fixture injects through actionFilterDeliveryOptionList, which core only fires from 8.0.
if (version_compare(_PS_VERSION_, '8.0.0', '>=')) {
    $scenario_names[] = 'carrierless';
    $scenario_names[] = 'carrierless_resplit';
}
// An enabled handler makes the module's default handler stand down (TWO-26274), so the fixture is enabled only for
// the scenarios that want a handler, and left disabled, as installed, for every other probe.
$fixture = Module::getInstanceByName('twoorderpostprocessingtest');
try {
    foreach ($scenario_names as $scenario_name) {
        in_array($scenario_name, array('unhandled', 'surcharge_unhandled'), true) ? $fixture->disable() : $fixture->enable();
        $status = 0;
        passthru(escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($scenario_name), $status);
        $exit = $status !== 0 ? 1 : $exit;
    }
} finally {
    $fixture->disable();
}
exit($exit);
