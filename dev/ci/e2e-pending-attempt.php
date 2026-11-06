<?php
/**
 * CLI helper for tests/e2e/tests/pending-recovery.spec.ts (TWO-26300), run inside the
 * PrestaShop container as www-data. Prints one JSON document per call.
 *
 * The hermetic harness cannot place an order through the storefront: the checkout refuses a
 * company that was not verified through Two's live search, which the stub does not serve. So
 * `seed` does what the payment step does once the buyer submits: it builds the order payload
 * with the module's own builder, creates the order at the stub, and stores the checkout attempt
 * as REDIRECTED, which is where a buyer who never comes back from Two leaves it. Everything after
 * that (the cron URL, the resolver, validateOrder outside a buyer session, the admin button) is
 * the real module.
 *
 *   seed [minutes-old]          a waiting attempt, created that many minutes ago (default 31)
 *   state <attempt-token>       the attempt, the cart's order and the status ids to compare
 *   checked-ago <token> <min>   pretend the last check ran that many minutes ago
 *   two-state <order> <state>   set the stub order's state
 *   requests <order>            the order requests the stub saw
 *   cron-token                  the cron URL's token
 *   admin-url <employee-email>  the module configuration URL, with that employee's token
 */

require '/var/www/html/config/config.inc.php';

$stub = 'http://127.0.0.1:' . (getenv('TWO_API_STUB_PORT') ?: '8099');
$module = Module::getInstanceByName('twopayment');
$context = Context::getContext();
$id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
$context->language = new Language($id_lang);
$context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
$context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
if (!isset($context->link) || !$context->link) {
    $context->link = new Link();
}

function out($data)
{
    echo json_encode($data), "\n";
    exit(0);
}

function stub_call($url, $method = 'GET', $body = null)
{
    $options = array('http' => array('method' => $method, 'ignore_errors' => true, 'header' => "Content-Type: application/json\r\n"));
    if ($body !== null) {
        $options['http']['content'] = json_encode($body);
    }

    return json_decode((string) file_get_contents($url, false, stream_context_create($options)), true);
}

$command = isset($argv[1]) ? $argv[1] : '';

if ($command === 'seed') {
    $minutes = isset($argv[2]) ? (int) $argv[2] : 31;
    $customer = new Customer();
    $customer->firstname = 'Test';
    $customer->lastname = 'Buyer';
    $customer->email = 'two-e2e-pending-' . time() . '-' . mt_rand(1000, 9999) . '@two.inc';
    $customer->passwd = md5(_COOKIE_KEY_ . Tools::passwdGen(12));
    $customer->is_guest = 1;
    $customer->id_shop = (int) $context->shop->id;
    $customer->id_lang = $id_lang;
    $customer->add();

    $address = new Address();
    $address->id_customer = (int) $customer->id;
    $address->alias = 'E2E';
    $address->firstname = 'Test';
    $address->lastname = 'Buyer';
    $address->company = 'RESTAURANT 53 LTD';
    $address->address1 = '123 Test Street';
    $address->city = 'London';
    $address->postcode = 'SW1A 1AA';
    $address->id_country = (int) Country::getByIso('GB');
    $address->phone = '+447777777777';
    $address->add();

    $products = Product::getProducts($id_lang, 0, 1, 'id_product', 'ASC', false, true);
    $carriers = Carrier::getCarriers($id_lang, true, false, false, null, Carrier::ALL_CARRIERS);

    $cart = new Cart();
    $cart->id_customer = (int) $customer->id;
    $cart->id_address_delivery = (int) $address->id;
    $cart->id_address_invoice = (int) $address->id;
    $cart->id_lang = $id_lang;
    $cart->id_currency = (int) $context->currency->id;
    $cart->id_shop = (int) $context->shop->id;
    $cart->id_shop_group = (int) $context->shop->id_shop_group;
    $cart->secure_key = $customer->secure_key;
    $cart->id_carrier = (int) $carriers[0]['id_carrier'];
    $cart->add();
    $cart->updateQty(1, (int) $products[0]['id_product']);
    $cart->setDeliveryOption(array((int) $address->id => (int) $carriers[0]['id_carrier'] . ','));
    $cart->update();

    $context->cart = $cart;
    $context->customer = $customer;
    $token = 'e2e-' . bin2hex(random_bytes(12));
    $merchant_order_id = 'e2e-' . (int) $cart->id;
    $urls = array(
        'merchant_confirmation_url' => $context->link->getModuleLink('twopayment', 'confirmation', array('attempt_token' => $token, 'key' => $customer->secure_key), true),
        'merchant_cancel_order_url' => $context->link->getModuleLink('twopayment', 'cancel', array('attempt_token' => $token, 'key' => $customer->secure_key), true),
        'merchant_edit_order_url' => '',
        'merchant_order_verification_failed_url' => '',
        'merchant_invoice_url' => '',
        'merchant_shipping_document_url' => '',
    );
    $payload = $module->getTwoNewOrderData($merchant_order_id, $cart, $urls, true, 'checkout');
    $created = stub_call($stub . '/v1/order', 'POST', $payload);

    $module->setTwoCheckoutAttempt($token, array(
        'id_cart' => (int) $cart->id,
        'id_customer' => (int) $customer->id,
        'customer_secure_key' => $customer->secure_key,
        'merchant_order_id' => $merchant_order_id,
        'two_order_id' => $created['id'],
        'two_order_reference' => '',
        'two_order_state' => 'UNVERIFIED',
        'two_order_status' => 'APPROVED',
        'two_day_on_invoice' => '30',
        'two_payment_term_type' => 'STANDARD',
        'status' => 'REDIRECTED',
    ));
    $created_at = date('Y-m-d H:i:s', time() - $minutes * 60);
    Db::getInstance()->update('twopayment_attempt', array('created_at' => pSQL($created_at)), 'attempt_token = "' . pSQL($token) . '"');
    $id_attempt = (int) Db::getInstance()->getValue('SELECT `id_attempt` FROM `' . _DB_PREFIX_ . 'twopayment_attempt` WHERE `attempt_token` = "' . pSQL($token) . '"');

    out(array('token' => $token, 'id_attempt' => $id_attempt, 'id_cart' => (int) $cart->id, 'two_order_id' => $created['id'], 'gross_amount' => $payload['gross_amount']));
}

if ($command === 'state') {
    $attempt = $module->getTwoCheckoutAttempt((string) $argv[2]);
    $id_order = is_array($attempt) ? (int) $module->getTwoOrderIdByCart((int) $attempt['id_cart']) : 0;
    $order = new Order($id_order);
    out(array(
        'attempt' => $attempt,
        'id_order' => $id_order,
        'orders_for_cart' => is_array($attempt) ? (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = ' . (int) $attempt['id_cart']) : 0,
        'current_state' => $id_order > 0 ? (int) $order->current_state : 0,
        'verified_status' => (int) $module->getTwoVerifiedStatusIdForConfirmation(),
        'payment' => $id_order > 0 ? $module->getTwoOrderPaymentData($id_order) : null,
    ));
}

if ($command === 'checked-ago') {
    $at = date('Y-m-d H:i:s', time() - (int) $argv[3] * 60);
    Db::getInstance()->update('twopayment_attempt', array('pending_checked_at' => pSQL($at)), 'attempt_token = "' . pSQL((string) $argv[2]) . '"');
    out(array('pending_checked_at' => $at));
}

if ($command === 'two-state') {
    out(stub_call($stub . '/_stub/order/' . rawurlencode((string) $argv[2]) . '/state', 'POST', array('state' => (string) $argv[3])));
}

if ($command === 'requests') {
    out(stub_call($stub . '/_stub/requests?order=' . rawurlencode((string) $argv[2])));
}

if ($command === 'cron-token') {
    out(array('token' => $module->getTwoCronToken()));
}

if ($command === 'admin-url') {
    $employee = new Employee();
    $employee = $employee->getByEmail((string) $argv[2]);
    $token = Tools::getAdminToken('AdminModules' . (int) Tab::getIdFromClassName('AdminModules') . (int) $employee->id);
    out(array('url' => 'index.php?controller=AdminModules&configure=twopayment&token=' . $token));
}

fwrite(STDERR, "unknown command\n");
exit(2);
