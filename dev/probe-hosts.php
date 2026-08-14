<?php
define('_PS_ADMIN_DIR_', '/var/www/html/admin-dev');
require '/var/www/html/config/config.inc.php';
$mod = Module::getInstanceByName('twopayment');
echo "_PS_MODE_DEV_: " . (_PS_MODE_DEV_ ? 'true' : 'false') . "\n";
echo "getenv TWO_API_BASE_URL: '" . getenv('TWO_API_BASE_URL') . "'\n";
echo "getenv TWO_PORTAL_BASE_URL: '" . getenv('TWO_PORTAL_BASE_URL') . "'\n";
echo "getenv TWO_CHECKOUT_BASE_URL: '" . getenv('TWO_CHECKOUT_BASE_URL') . "'\n";
echo "PS_TWO_ENVIRONMENT (config): '" . Configuration::get('PS_TWO_ENVIRONMENT') . "'\n";
echo "getTwoCheckoutHostUrl(): " . $mod->getTwoCheckoutHostUrl() . "\n";
echo "getTwoPortalUrl(): " . $mod->getTwoPortalUrl() . "\n";
echo "TwoSoleTrader::getSignupPageUrl(): " . TwoSoleTrader::getSignupPageUrl() . "\n";
echo "merchant_short_name: '" . $mod->merchant_short_name . "'\n";
echo "api_key_verified: " . Configuration::get('PS_TWO_API_KEY_VERIFIED') . "\n";
