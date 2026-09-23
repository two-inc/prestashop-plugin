<?php

/**
 * Shared by the tests/integration probes and the carrier-less shipping matrix
 * (tests/integration/matrix/). Not a probe itself: run-integration-probes.sh
 * only discovers *.php directly under tests/integration/.
 */

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
