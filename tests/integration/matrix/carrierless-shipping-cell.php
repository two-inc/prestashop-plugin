<?php

/**
 * One cell of the carrier-less shipping matrix (dev/ci/run-carrierless-matrix.sh):
 * a cart shipping MODE crossed with a plugin CONFIG, driven through
 * getTwoNewOrderData() - the create-order payload, with its reconciliation
 * gates - and never sent anywhere. Records outcomes; asserts only that the
 * cart shape itself was reproduced.
 *
 * Usage: php carrierless-shipping-cell.php <A|B|C> <1|2|3|4a|4b|5a|5b>
 * Configs 4/5 take the merchant override's rate config key from env MERCHANT_RATE_CONFIG_KEY.
 * Exit: 0 recorded, 2 shape not reproduced or merchant override not loaded.
 */

if (!defined('_PS_VERSION_')) {
    require '/var/www/html/config/config.inc.php';
}
require __DIR__ . '/../lib/probe-helpers.php';

const CELL_MODES = array(
    'A' => array('gross' => '29.00', 'net' => '29.00', 'mode' => ''),
    'B' => array('gross' => '29.00', 'net' => '23.97', 'mode' => ''),
    'C' => array('gross' => '29.00', 'net' => '29.00', 'mode' => 'external_only'),
);
// group: '' unset, 'TRG_21' the seeded 21% group, '0' core's "No tax".
const CELL_CONFIGS = array(
    '1' => array('group' => '', 'override' => false, 'merchant_rate' => null),
    '2' => array('group' => 'TRG_21', 'override' => false, 'merchant_rate' => null),
    '3' => array('group' => '0', 'override' => false, 'merchant_rate' => null),
    '4a' => array('group' => '', 'override' => true, 'merchant_rate' => null),
    '4b' => array('group' => '', 'override' => true, 'merchant_rate' => '21'),
    '5a' => array('group' => 'TRG_21', 'override' => true, 'merchant_rate' => null),
    '5b' => array('group' => 'TRG_21', 'override' => true, 'merchant_rate' => '21'),
);

$mode_name = isset($argv[1]) ? (string) $argv[1] : '';
$config_name = isset($argv[2]) ? (string) $argv[2] : '';
if (!isset(CELL_MODES[$mode_name]) || !isset(CELL_CONFIGS[$config_name])) {
    fwrite(STDERR, 'usage: carrierless-shipping-cell.php <A|B|C> <1|2|3|4a|4b|5a|5b>' . PHP_EOL);
    exit(2);
}
$mode = CELL_MODES[$mode_name];
$config = CELL_CONFIGS[$config_name];
$cell = $mode_name . $config_name;

probeBootKernel();
Configuration::updateValue('TWO_CARRIERLESS_TEST_GROSS', $mode['gross']);
Configuration::updateValue('TWO_CARRIERLESS_TEST_NET', $mode['net']);
Configuration::updateValue('TWO_CARRIERLESS_TEST_MODE', $mode['mode']);
Configuration::updateValue(
    'PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP',
    $config['group'] === 'TRG_21' ? (string) (int) Configuration::get('TWO_CARRIERLESS_TEST_TRG_21') : $config['group']
);
$rate_key = (string) getenv('MERCHANT_RATE_CONFIG_KEY');
if ($config['override'] && $rate_key === '') {
    fwrite(STDERR, 'config ' . $config_name . ' needs env MERCHANT_RATE_CONFIG_KEY' . PHP_EOL);
    exit(2);
}
if ($rate_key !== '') {
    if ($config['merchant_rate'] === null) {
        Configuration::deleteByName($rate_key);
    } else {
        Configuration::updateValue($rate_key, $config['merchant_rate']);
    }
}

$cart = new Cart((int) Configuration::get('TWO_CARRIERLESS_TEST_ID_CART'));
if (!Validate::isLoadedObject($cart)) {
    fwrite(STDERR, 'probe cart does not load - run dev/ci/seed-carrierless-cart.sh first' . PHP_EOL);
    exit(2);
}
$customer = new Customer((int) $cart->id_customer);
probeApplyContext($cart, $customer);

$module = Module::getInstanceByName('twopayment');
$module_class = is_object($module) ? get_class($module) : '(none)';

$totals = array(
    'ship_incl' => round((float) $cart->getOrderTotal(true, Cart::ONLY_SHIPPING), 2),
    'ship_excl' => round((float) $cart->getOrderTotal(false, Cart::ONLY_SHIPPING), 2),
    'both_incl' => round((float) $cart->getOrderTotal(true, Cart::BOTH), 2),
    'both_excl' => round((float) $cart->getOrderTotal(false, Cart::BOTH), 2),
    'noship_incl' => round((float) $cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING), 2),
    'external' => method_exists($cart, 'getExternalShippingCost') ? round((float) $cart->getExternalShippingCost(), 2) : null,
);

$invalid = array();
if ((int) $cart->id_carrier !== 0) {
    $invalid[] = 'id_carrier=' . (int) $cart->id_carrier;
}
if ($totals['external'] !== (float) $mode['gross']) {
    $invalid[] = 'getExternalShippingCost()=' . var_export($totals['external'], true);
}
$expect_ship = $mode['mode'] === 'external_only'
    ? array(0.0, 0.0)
    : array((float) $mode['gross'], (float) $mode['net']);
if (array($totals['ship_incl'], $totals['ship_excl']) !== $expect_ship) {
    $invalid[] = 'ONLY_SHIPPING=' . $totals['ship_incl'] . '/' . $totals['ship_excl'];
}
if (round($totals['both_incl'] - $totals['noship_incl'], 2) !== (float) $mode['gross']) {
    $invalid[] = 'BOTH does not include the ' . $mode['gross'] . ' shipping cost';
}
if ($config['override'] !== ($module_class === 'TwopaymentOverride')) {
    $invalid[] = 'module class is ' . $module_class;
}

probeClearModuleLogs();
$outcome = 'not run: cart shape not reproduced';
$payload = null;
if ($invalid === array()) {
    $outcome = 'payload OK';
    try {
        $payload = $module->getTwoNewOrderData('matrix-' . $cell, $cart);
    } catch (Throwable $e) {
        $outcome = get_class($e) . ': ' . $e->getMessage();
    }
}

$shipping = array();
foreach (probeShippingLines($payload !== null ? $payload['line_items'] : array()) as $line) {
    $shipping[] = $line['gross_amount'] . '/' . $line['net_amount'] . '/' . $line['tax_amount'] . '@' . $line['tax_rate'];
}

echo '### ' . $cell . ' (mode ' . $mode_name . ', config ' . $config_name . ', module ' . $module_class . ')' . PHP_EOL;
echo '  cart ONLY_SHIPPING incl/excl ' . $totals['ship_incl'] . '/' . $totals['ship_excl']
    . ', BOTH incl/excl ' . $totals['both_incl'] . '/' . $totals['both_excl']
    . ', getExternalShippingCost ' . var_export($totals['external'], true) . PHP_EOL;
if ($payload !== null) {
    echo '  order gross/net/tax ' . $payload['gross_amount'] . '/' . $payload['net_amount'] . '/' . $payload['tax_amount'] . PHP_EOL;
}
echo '  outcome: ' . $outcome . PHP_EOL;
foreach (probeModuleLogs() as $entry) {
    echo '  log[' . $entry['severity'] . '] ' . $entry['message'] . PHP_EOL;
}
if ($invalid !== array()) {
    echo '  CELL INVALID: ' . implode('; ', $invalid) . PHP_EOL;
}
echo "ROW\t" . implode("\t", array(
    $cell,
    $invalid === array() ? 'valid' : 'INVALID',
    $totals['both_incl'] . '/' . $totals['both_excl'],
    $shipping === array() ? '(none)' : implode(' ; ', $shipping),
    $payload !== null ? $payload['gross_amount'] . '/' . $payload['net_amount'] . '/' . $payload['tax_amount'] : '-',
    $outcome,
)) . PHP_EOL;

exit($invalid === array() ? 0 : 2);
