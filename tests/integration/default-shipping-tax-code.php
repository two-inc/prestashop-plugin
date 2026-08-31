<?php

/**
 * INTEGRATION PROBE - "Default shipping tax code" (TWO-25200), against a real
 * PrestaShop engine.
 *
 * WHAT THIS ADDS OVER tests/DefaultShippingTaxCodeSpec.php, which drives a
 * hand-rolled core stub: it asks a real engine for the order-intent payload of
 * a real carrier-less cart and checks the SHIPPING_FEE line and the log
 * severity that come back.
 *
 * Hermetic: no browser, no network, no Two credentials. It calls
 * getTwoIntentOrderData() directly - the same public entry point the checkout
 * controller uses to build an order intent - and never sends it anywhere.
 *
 * ONE PROCESS PER SCENARIO. Called with no argument this file is the runner
 * and re-executes itself once per scenario; called with a scenario name it
 * runs that one. Scenarios differ in configuration that core and the module
 * both cache per request (delivery option list, tax managers, Configuration),
 * and a shared process would test the caches rather than the behaviour. A
 * fresh process per scenario is also what a real checkout request is.
 *
 * Requires dev/ci/seed-carrierless-cart.sh to have run first.
 *
 * Usage:
 *   php tests/integration/default-shipping-tax-code.php            # all scenarios
 *   php tests/integration/default-shipping-tax-code.php declared   # just one
 */

if (!defined('_PS_VERSION_')) {
    require '/var/www/html/config/config.inc.php';
}

const PROBE_CONFIG_GROUP = 'PS_TWO_DEFAULT_SHIPPING_TAX_RULES_GROUP';
const PROBE_CONFIG_GROSS = 'TWO_CARRIERLESS_TEST_GROSS';
const PROBE_CONFIG_NET = 'TWO_CARRIERLESS_TEST_NET';
// Deliberately far above anything a test shop creates, and never created here:
// the "merchant selected a group and then deleted it" state.
const PROBE_DELETED_GROUP_ID = 999001;

/**
 * @return array<string,array<string,mixed>>
 */
function probeScenarios()
{
    return array(
        // The shipped state. No declaration anywhere, so the order must be
        // refused rather than shipped with a guessed shipping rate.
        'unset' => array(
            'group' => '',
            'gross' => '29.00',
            'net' => '23.20',
            'expect' => 'refusal',
            'log_present' => array(
                array(3, 'No deliverable carrier for the cart shipping cost'),
                array(3, 'Configure a carrier that covers this delivery address'),
            ),
            'log_absent' => array('assuming the configured Default shipping tax code'),
        ),
        // The case the feature exists for: the merchant declared the group on
        // the module instead of on a carrier row, and it is relayed.
        'declared' => array(
            'group' => 'PROBE_TRG',
            'gross' => '29.00',
            'net' => '23.20',
            'expect' => array(
                'gross_amount' => '29.00',
                'net_amount' => '23.20',
                'tax_amount' => '5.80',
                'tax_rate' => '0.25',
                'tax_class_name' => 'VAT 25.00%',
            ),
            'log_present' => array(
                array(2, 'assuming the configured Default shipping tax code'),
                array(2, 'Falling back to the configured Default shipping tax code'),
            ),
            // The fallback is designed behaviour once declared: it must not
            // leave a permanent error line in the merchant's log.
            'log_absent' => array('Configure a carrier that covers this delivery address'),
        ),
        // Core's first-class "No tax" sentinel is a tax treatment the merchant
        // can declare, not the absence of a declaration. Shipping is priced
        // with no tax component here, because a declared rate is asserted
        // against the applied amounts.
        'notax' => array(
            'group' => '0',
            'gross' => '29.00',
            'net' => '29.00',
            'expect' => array(
                'gross_amount' => '29.00',
                'net_amount' => '29.00',
                'tax_amount' => '0.00',
                'tax_rate' => '0',
                'tax_class_name' => 'VAT 0.00%',
            ),
            'log_present' => array(
                array(2, 'assuming the configured Default shipping tax code "No tax"'),
            ),
            'log_absent' => array('Configure a carrier that covers this delivery address'),
        ),
        // A selection whose group has since been deleted is not a declaration
        // any more. It must refuse, NOT silently relay 0%.
        'missing' => array(
            'group' => (string) PROBE_DELETED_GROUP_ID,
            'gross' => '29.00',
            'net' => '23.20',
            'expect' => 'refusal',
            'log_present' => array(
                array(3, 'which no longer exists'),
                array(3, 'No deliverable carrier for the cart shipping cost'),
            ),
            'log_absent' => array('assuming the configured Default shipping tax code'),
        ),
    );
}

/**
 * Boot the Symfony kernel so core can resolve services out of the container.
 *
 * Required on PrestaShop 9, and harmless on 8. `config/config.inc.php` alone
 * does not create a container, and on 9 Cart::getOrderTotal() routes through
 * Cart::newCalculator(), which asks ContainerFinder for one and throws
 * ContainerNotFoundException without it. In a web request the front controller
 * has already booted the kernel; in a CLI request nothing has.
 *
 * The kernel class differs by version and lives in the global namespace both
 * times: `AppKernel` is concrete on 8, abstract on 9, where the front-office
 * kernel is `FrontKernel` (see bin/console). Resolved by probing rather than
 * branching on _PS_VERSION_, so a future rename fails with a clear message
 * instead of a version comparison that quietly stops matching.
 *
 * @return void
 */
function probeBootKernel()
{
    if (PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance() !== null) {
        return;
    }

    foreach (array('FrontKernel', 'AppKernel') as $class) {
        if (!class_exists($class)) {
            continue;
        }
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract()) {
            continue;
        }

        $env = defined('_PS_MODE_DEV_') && _PS_MODE_DEV_ ? 'dev' : 'prod';
        /** @var Symfony\Component\HttpKernel\KernelInterface $kernel */
        $kernel = new $class($env, false);
        $kernel->boot();
        // SymfonyContainer::getInstance() reads $GLOBALS['kernel'], the same
        // handle bin/console and the front controller populate.
        $GLOBALS['kernel'] = $kernel;
        PrestaShop\PrestaShop\Adapter\SymfonyContainer::resetStaticCache();
        Context::getContext()->container = $kernel->getContainer();

        return;
    }

    throw new RuntimeException(
        'no bootable PrestaShop kernel class found (tried FrontKernel, AppKernel) - '
        . 'cart totals cannot be computed without a container on PrestaShop 9'
    );
}

/**
 * A CLI request has no employee and no shopper cookie, and core reaches into
 * the context from inside ordinary cart operations -
 * Cart::getDeliveryOptionList() reads the cookie's
 * id_lang/id_customer, and product pricing reads the currency's precision
 * (Context::getComputingPrecision), which fatals on a null currency.
 *
 * @param Cart $cart
 * @param Customer $customer
 * @return void
 */
function probeApplyContext($cart, $customer)
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
 * Drop the module's own log rows so each scenario's severity assertions read
 * only its own output.
 *
 * Necessary, not tidiness: PrestaShopLogger::addLog suppresses a write when an
 * identical message already exists (PrestaShopLogger::isPresent queries the
 * log table), so without this a re-run would assert against rows the previous
 * run wrote, and an "absent" assertion could never fail. Scoped to this
 * module's messages, and this harness only ever runs against a CI or dev shop.
 *
 * @return void
 */
function probeClearModuleLogs()
{
    $logs = new PrestaShopCollection('PrestaShopLogger');
    $logs->where('message', 'like', '%TwoPayment%');
    foreach ($logs as $log) {
        $log->delete();
    }
}

/**
 * @return array<int,array{severity:int,message:string}>
 */
function probeModuleLogs()
{
    $entries = array();
    $logs = new PrestaShopCollection('PrestaShopLogger');
    $logs->where('message', 'like', '%TwoPayment%');
    foreach ($logs as $log) {
        $entries[] = array('severity' => (int) $log->severity, 'message' => (string) $log->message);
    }

    return $entries;
}

/**
 * @param array<int,array{severity:int,message:string}> $logs
 * @param int $severity
 * @param string $needle
 * @return bool
 */
function probeLogHas($logs, $severity, $needle)
{
    foreach ($logs as $entry) {
        if ($entry['severity'] === $severity && strpos($entry['message'], $needle) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int,array{severity:int,message:string}> $logs
 * @param string $needle
 * @return bool
 */
function probeLogMentions($logs, $needle)
{
    foreach ($logs as $entry) {
        if (strpos($entry['message'], $needle) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int,array<string,mixed>> $line_items
 * @return array<int,array<string,mixed>>
 */
function probeShippingLines($line_items)
{
    $lines = array();
    foreach ($line_items as $item) {
        if (isset($item['type']) && $item['type'] === 'SHIPPING_FEE') {
            $lines[] = $item;
        }
    }

    return $lines;
}

/**
 * @param string $name
 * @return int Process exit code
 */
function probeRunScenario($name)
{
    $scenarios = probeScenarios();
    if (!isset($scenarios[$name])) {
        fwrite(STDERR, 'unknown scenario "' . $name . '"' . PHP_EOL);

        return 2;
    }
    $scenario = $scenarios[$name];
    probeBootKernel();

    $id_cart = (int) Configuration::get('TWO_CARRIERLESS_TEST_ID_CART');
    $cart = new Cart($id_cart);
    if (!Validate::isLoadedObject($cart)) {
        fwrite(STDERR, 'probe cart ' . $id_cart . ' does not load - run dev/ci/seed-carrierless-cart.sh first' . PHP_EOL);

        return 2;
    }
    $customer = new Customer((int) $cart->id_customer);
    $address = new Address((int) $cart->id_address_invoice);
    $currency = new Currency((int) $cart->id_currency);
    probeApplyContext($cart, $customer);

    $group = $scenario['group'] === 'PROBE_TRG'
        ? (string) (int) Configuration::get('TWO_CARRIERLESS_TEST_TRG')
        : $scenario['group'];
    Configuration::updateValue(PROBE_CONFIG_GROUP, $group);
    Configuration::updateValue(PROBE_CONFIG_GROSS, $scenario['gross']);
    Configuration::updateValue(PROBE_CONFIG_NET, $scenario['net']);
    probeClearModuleLogs();

    // The shape itself, re-asserted every scenario: shipping IS priced and
    // NOTHING declares a carrier. If either half stops holding, the payload
    // assertions below would pass for the wrong reason - a 0.00 shipping cost
    // emits no SHIPPING_FEE line at all, and a loadable carrier would supply
    // its own declared group and never reach the fallback.
    $shipping_gross = round((float) $cart->getOrderTotal(true, Cart::ONLY_SHIPPING), 2);
    $shipping_net = round((float) $cart->getOrderTotal(false, Cart::ONLY_SHIPPING), 2);
    $failures = array();
    if ($shipping_gross !== (float) $scenario['gross'] || $shipping_net !== (float) $scenario['net']) {
        $failures[] = 'cart shipping totals are ' . $shipping_gross . '/' . $shipping_net
            . ', expected ' . $scenario['gross'] . '/' . $scenario['net']
            . ' - the carrier-less delivery option is not being injected';
    }
    if ((int) $cart->id_carrier !== 0) {
        $failures[] = 'cart id_carrier is ' . (int) $cart->id_carrier . ', expected 0';
    }

    $module = Module::getInstanceByName('twopayment');
    if (!is_object($module)) {
        fwrite(STDERR, 'twopayment module does not instantiate' . PHP_EOL);

        return 2;
    }

    $payload = null;
    $refusal = null;
    try {
        $payload = $module->getTwoIntentOrderData($cart, $customer, $currency, $address);
    } catch (Exception $e) {
        $refusal = $e;
    }
    $logs = probeModuleLogs();

    if ($scenario['expect'] === 'refusal') {
        if ($refusal === null) {
            $failures[] = 'expected the order to be refused, but a payload was built';
        } elseif (!($refusal instanceof TwoCheckoutAmountException)) {
            $failures[] = 'expected a TwoCheckoutAmountException, got ' . get_class($refusal)
                . ': ' . $refusal->getMessage();
        }
    } else {
        if ($refusal !== null) {
            $failures[] = 'expected a payload, got ' . get_class($refusal) . ': ' . $refusal->getMessage();
        } else {
            $lines = probeShippingLines(isset($payload['line_items']) ? $payload['line_items'] : array());
            if (count($lines) !== 1) {
                $failures[] = 'expected exactly 1 SHIPPING_FEE line, got ' . count($lines);
            } else {
                foreach ($scenario['expect'] as $field => $expected) {
                    $actual = isset($lines[0][$field]) ? (string) $lines[0][$field] : '(missing)';
                    if ($actual !== (string) $expected) {
                        $failures[] = 'SHIPPING_FEE ' . $field . ' is "' . $actual
                            . '", expected "' . $expected . '"';
                    }
                }
            }
        }
    }

    foreach ($scenario['log_present'] as $expected) {
        if (!probeLogHas($logs, $expected[0], $expected[1])) {
            $failures[] = 'no severity-' . $expected[0] . ' log containing "' . $expected[1] . '"';
        }
    }
    foreach ($scenario['log_absent'] as $needle) {
        if (probeLogMentions($logs, $needle)) {
            $failures[] = 'unexpected log containing "' . $needle . '"';
        }
    }

    if ($failures === array()) {
        echo '  PASS ' . $name . PHP_EOL;

        return 0;
    }

    echo '  FAIL ' . $name . PHP_EOL;
    foreach ($failures as $failure) {
        echo '       - ' . $failure . PHP_EOL;
    }
    foreach ($logs as $entry) {
        echo '       log[' . $entry['severity'] . '] ' . $entry['message'] . PHP_EOL;
    }

    return 1;
}

$argument = isset($argv[1]) ? (string) $argv[1] : '';
if ($argument !== '') {
    exit(probeRunScenario($argument));
}

echo 'Default shipping tax code - integration probe (PrestaShop ' . _PS_VERSION_ . ')' . PHP_EOL;
$exit = 0;
foreach (array_keys(probeScenarios()) as $scenario_name) {
    $command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M '
        . escapeshellarg(__FILE__) . ' ' . escapeshellarg($scenario_name);
    $status = 0;
    passthru($command, $status);
    if ($status !== 0) {
        $exit = 1;
    }
}
echo $exit === 0 ? 'all scenarios passed' . PHP_EOL : 'FAILURES - see above' . PHP_EOL;
exit($exit);
