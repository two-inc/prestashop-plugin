<?php

/**
 * INTEGRATION PROBE - discrepancy snapshot (TWO-26064), against a real
 * PrestaShop engine: a refused carrier-less cart (the Default shipping tax code
 * set and not reconciling, the one shipping refusal since TWO-26117) must leave one snapshot row
 * that classifies its own shape, carries no buyer PII and fits ps_log.message;
 * debug mode must also leave a baseline row for a cart that passes.
 *
 * One process per scenario, for the same per-request caches reason as
 * default-shipping-tax-code.php. Requires dev/ci/seed-carrierless-cart.sh.
 *
 * Usage: php tests/integration/discrepancy-snapshot.php [scenario]
 */

if (!defined('_PS_VERSION_')) {
    require '/var/www/html/config/config.inc.php';
}

/**
 * @return array<string,array<string,mixed>>
 */
function snapshotScenarios()
{
    // group: '' unset, 'TRG' the seeded 25% group, 'TRG_21' the seeded 21% one; gross/net: the injected carrier-less option.
    return array(
        'A' => array('gross' => '29.00', 'net' => '29.00', 'group' => 'TRG', 'debug' => '0', 'severity' => 3, 'gate' => 'declared_rate', 'shape' => 'A', 'desc' => 'untaxed carrier-less shipping at a 25% Default shipping tax code, refused'),
        'B' => array('gross' => '29.00', 'net' => '23.20', 'group' => 'TRG_21', 'debug' => '0', 'severity' => 3, 'gate' => 'declared_rate', 'shape' => 'B', 'desc' => 'shipping taxed at 25% with a 21% Default shipping tax code, refused'),
        'blank' => array('gross' => '29.00', 'net' => '23.20', 'group' => '', 'debug' => '0', 'severity' => null, 'gate' => null, 'shape' => null, 'desc' => 'taxed carrier-less shipping with no Default shipping tax code goes out at 0%, not a refusal'),
        'baseline' => array('gross' => '29.00', 'net' => '23.20', 'group' => 'TRG', 'debug' => '1', 'severity' => 1, 'gate' => null, 'shape' => 'B', 'desc' => 'debug-mode baseline of a cart that passes'),
        'quiet' => array('gross' => '29.00', 'net' => '23.20', 'group' => 'TRG', 'debug' => '0', 'severity' => null, 'gate' => null, 'shape' => null, 'desc' => 'a cart that passes writes nothing outside debug mode'),
    );
}

// Same as default-shipping-tax-code.php: PrestaShop 9 prices carts through the Symfony container.
function snapshotBootKernel()
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

function snapshotApplyContext($cart, $customer)
{
    $context = Context::getContext();
    $context->cart = $cart;
    $context->customer = $customer;
    $context->language = new Language((int) $cart->id_lang);
    $context->currency = new Currency((int) $cart->id_currency);
    $context->country = new Country((int) (new Address((int) $cart->id_address_delivery))->id_country);
    if (is_object($context->cookie)) {
        $context->cookie->id_lang = (int) $cart->id_lang;
        $context->cookie->id_customer = (int) $customer->id;
        $context->cookie->id_currency = (int) $cart->id_currency;
    }
}

/**
 * @return array<int,array{severity:int,message:string}>
 */
function snapshotRows($id_cart)
{
    $rows = Db::getInstance()->executeS(
        'SELECT severity, message FROM `' . _DB_PREFIX_ . 'log`'
        . " WHERE object_type = 'TwoDiscrepancySnapshot' AND object_id = " . (int) $id_cart
    );

    return is_array($rows) ? $rows : array();
}

function snapshotRun($name)
{
    $scenario = snapshotScenarios()[$name];
    snapshotBootKernel();
    $cart = new Cart((int) Configuration::get('TWO_CARRIERLESS_TEST_ID_CART'));
    if (!Validate::isLoadedObject($cart)) {
        fwrite(STDERR, 'probe cart does not load - run dev/ci/seed-carrierless-cart.sh first' . PHP_EOL);

        return 2;
    }
    $customer = new Customer((int) $cart->id_customer);
    $address = new Address((int) $cart->id_address_invoice);
    snapshotApplyContext($cart, $customer);

    Configuration::updateValue('TWO_CARRIERLESS_TEST_GROSS', $scenario['gross']);
    Configuration::updateValue('TWO_CARRIERLESS_TEST_NET', $scenario['net']);
    Configuration::updateValue(
        'PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP',
        $scenario['group'] === '' ? '' : (string) (int) Configuration::get('TWO_CARRIERLESS_TEST_' . $scenario['group'])
    );
    Configuration::updateValue('PS_TWO_DEBUG_MODE', $scenario['debug']);
    Db::getInstance()->delete('log', "object_type = 'TwoDiscrepancySnapshot' AND object_id = " . (int) $cart->id);

    $module = Module::getInstanceByName('twopayment');
    try {
        $module->getTwoIntentOrderData($cart, $customer, new Currency((int) $cart->id_currency), $address);
    } catch (Exception $e) {
        // Refusal is the expected outcome for A and B (TWO-26117); the snapshot row is what is asserted.
    }
    Configuration::updateValue('PS_TWO_DEBUG_MODE', '0');

    $failures = array();
    $rows = snapshotRows((int) $cart->id);
    if ($scenario['severity'] === null) {
        if ($rows !== array()) {
            $failures[] = 'expected no snapshot row, got ' . count($rows);
        }
    } elseif (count($rows) !== 1) {
        $failures[] = 'expected 1 snapshot row, got ' . count($rows);
    } else {
        $message = (string) TwoDiscrepancySnapshot::decodeStored($rows[0]['message']);
        $snapshot = json_decode($message, true);
        $gate = is_array($snapshot) && is_array($snapshot['gate']) ? $snapshot['gate']['name'] : null;
        $checks = array(
            array((int) $rows[0]['severity'], $scenario['severity'], 'severity'),
            array(is_array($snapshot), true, 'message decodes as JSON'),
            array(strlen($message) <= TwoDiscrepancySnapshot::MAX_BYTES, true, 'message fits MAX_BYTES'),
            array($gate, $scenario['gate'], 'gate name'),
            array(is_array($snapshot) ? $snapshot['shape'] : null, $scenario['shape'], 'classified shape'),
            array(is_array($snapshot) ? array_keys($snapshot['address']['invoice']) : null, array('country_iso', 'id_state', 'vat_number_present'), 'address keys'),
        );
        foreach (array($customer->email, $customer->lastname, $address->address1, $address->postcode) as $pii) {
            $checks[] = array(strpos($message, (string) $pii), false, 'no buyer PII "' . $pii . '"');
        }
        foreach ($checks as $check) {
            if ($check[0] !== $check[1]) {
                $failures[] = $check[2] . ': got ' . var_export($check[0], true) . ', expected ' . var_export($check[1], true);
            }
        }
    }

    echo ($failures === array() ? '  PASS ' : '  FAIL ') . $name . ' (' . $scenario['desc'] . ')' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '       - ' . $failure . PHP_EOL;
    }
    if ($failures !== array() && isset($message)) {
        echo '       snapshot: ' . $message . PHP_EOL;
    }

    return $failures === array() ? 0 : 1;
}

if (version_compare(_PS_VERSION_, '8.0.0', '<')) {
    echo 'SKIP discrepancy-snapshot on PrestaShop ' . _PS_VERSION_
        . ': the fixture injects through actionFilterDeliveryOptionList, which core only fires from 8.0' . PHP_EOL;
    exit(0);
}

$argument = isset($argv[1]) ? (string) $argv[1] : '';
if ($argument !== '') {
    exit(snapshotRun($argument));
}

echo 'Discrepancy snapshot - integration probe (PrestaShop ' . _PS_VERSION_ . ')' . PHP_EOL;
$exit = 0;
foreach (array_keys(snapshotScenarios()) as $scenario_name) {
    $status = 0;
    passthru(escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($scenario_name), $status);
    $exit = $status !== 0 ? 1 : $exit;
}
exit($exit);
