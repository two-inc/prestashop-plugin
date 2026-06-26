<?php
define('_PS_ADMIN_DIR_', '/var/www/html/admin-dev');
require '/var/www/html/config/config.inc.php';

$cartId = (int)($argv[1] ?? 6);
$ctx = Context::getContext();
$cart = new Cart($cartId);
if (!Validate::isLoadedObject($cart)) {
    echo "cart $cartId not found\n";
    exit(1);
}
$ctx->cart = $cart;
$ctx->customer = new Customer($cart->id_customer);
$ctx->currency = new Currency($cart->id_currency ?: 1);
$ctx->language = new Language($cart->id_lang ?: 1);
$ctx->shop = new Shop((int)$cart->id_shop ?: 1);

$mod = Module::getInstanceByName('twopayment');
echo "module loaded: " . ($mod ? 'yes' : 'NO') . "\n";
echo "active=" . ($mod->active ? '1' : '0') . "\n";
echo "merchant_short_name='" . $mod->merchant_short_name . "'\n";
echo "api_key_empty=" . (empty($mod->api_key) ? 'YES' : 'no') . "\n";
echo "cart id_address_invoice=" . $cart->id_address_invoice . "\n";
echo "cart id_carrier=" . $cart->id_carrier . "\n";
echo "PS_TWO_USE_ACCOUNT_TYPE=" . Configuration::get('PS_TWO_USE_ACCOUNT_TYPE') . "\n";
if ($cart->id_address_invoice) {
    $addr = new Address($cart->id_address_invoice);
    echo "billing address loaded: " . (Validate::isLoadedObject($addr) ? 'yes' : 'NO') . "\n";
    echo "billing address country iso: ";
    $country = new Country($addr->id_country);
    echo $country->iso_code . "\n";
    echo "billing address account_type: '" . ($addr->account_type ?? '(null)') . "'\n";
}

$result = $mod->hookPaymentOptions(['cart' => $cart]);
echo "hookPaymentOptions returned: " . var_export($result, true) . "\n";
