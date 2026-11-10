<?php

/**
 * INTEGRATION PROBE - a back-office status change acts in the order's shop (TWO-27200), against a real PrestaShop
 * engine.
 *
 * On a multistore install the back office runs in the shop picked in the shop selector. This probe checks, on core's
 * real Shop context and Configuration, that a status change on an order in shop B made with "All shops" selected:
 *   - fulfils when the status is in shop B's own fulfil map, and sends shop B's own API key;
 *   - sends nothing when the status is only in the all-shops fulfil map;
 *   - hands the all-shops context back afterwards.
 *
 * Multistore is switched on, shop B added and an existing order copied into shop B before the checks run in a child
 * process: core caches whether the feature is active for the life of a process. Everything is put back at the end.
 *
 * Hermetic: the probe's module answers the API calls itself, and keeps its order notes in memory.
 *
 * Usage: php tests/integration/order-shop-context-multistore.php
 */

if (!defined('_PS_VERSION_')) {
    require '/var/www/html/config/config.inc.php';
}
require_once __DIR__ . '/lib/probe-helpers.php';

probeBootKernel();
Module::getInstanceByName('twopayment');

const SHOP_CONTEXT_PROBE_ALL_KEY = 'probe-all-shops-key';
const SHOP_CONTEXT_PROBE_SHOP_B_KEY = 'probe-shop-b-key';

/** The installed module, answering its API calls and recording each with the API key it carried. */
class ShopContextProbeTwopayment extends Twopayment
{
    /** @var array[] endpoint, method, key */
    public $sends = array();

    public function getTwoOrderPaymentData($id_order)
    {
        return array(
            'two_order_id' => 'probe-two-order',
            'two_order_reference' => 'probe',
            'two_order_state' => 'CONFIRMED',
            'two_order_status' => 'APPROVED',
            'two_day_on_invoice' => '30',
            'two_payment_term_type' => 'STANDARD',
            'two_invoice_url' => '',
            'two_invoice_id' => null,
        );
    }

    public function setTwoOrderPaymentData($id_order, $payment_data)
    {
        return true;
    }

    protected function addTwoOrderPrivateNote($idOrder, $text)
    {
    }

    public function setTwoPaymentRequest($endpoint, $payload = array(), $method = 'POST', $additional_headers = array(), $timeout = null)
    {
        $key = array_values(preg_grep('/^X-API-Key:/', $this->getTwoRequestHeaders($endpoint, $additional_headers)));
        $this->sends[] = array('endpoint' => $endpoint, 'method' => $method, 'key' => $key ? substr($key[0], strlen('X-API-Key:')) : '');

        return array(
            'http_status' => $method === 'GET' ? 200 : 201,
            'id' => 'probe-two-order',
            'state' => 'CONFIRMED',
            'status' => 'APPROVED',
            'merchant_reference' => 'probe',
            'invoice_url' => '',
            'fulfilled_order' => array('id' => 'probe-fulfilled'),
        );
    }
}

$phase = isset($argv[1]) ? $argv[1] : 'main';
$failures = array();
$check = static function ($ok, $description) use (&$failures) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $description . "\n";
    if (!$ok) {
        $failures[] = $description;
    }
};

if ($phase === 'check') {
    list(, , $idShopA, $idOrder, $shopBStatus, $allShopsStatus) = $argv;
    $check(Shop::isFeatureActive(), 'multistore is active in the child process');
    // The back office on "All shops": core's context shop is the default shop.
    Shop::setContext(Shop::CONTEXT_ALL);
    Context::getContext()->shop = new Shop((int) $idShopA);

    foreach (array(array((int) $shopBStatus, true), array((int) $allShopsStatus, false)) as $case) {
        list($status, $fulfils) = $case;
        $module = new ShopContextProbeTwopayment();
        $order = new Order((int) $idOrder);
        $module->hookActionOrderStatusUpdate(array('id_order' => (int) $idOrder, 'newOrderStatus' => new OrderState($status, (int) $order->id_lang)));
        $fulfilments = array_values(array_filter($module->sends, static function ($send) {
            return $send['method'] === 'POST' && substr($send['endpoint'], -strlen('/fulfillments')) === '/fulfillments';
        }));
        $label = 'status ' . $status . ($fulfils ? ' (shop B\'s fulfil map)' : ' (the all-shops fulfil map only)');
        $check(count($fulfilments) === ($fulfils ? 1 : 0), $label . ': fulfilments sent ' . count($fulfilments));
        foreach ($module->sends as $send) {
            $check($send['key'] === SHOP_CONTEXT_PROBE_SHOP_B_KEY, $label . ': ' . $send['method'] . ' ' . $send['endpoint'] . ' carries shop B\'s API key');
        }
        $check(Shop::getContext() === Shop::CONTEXT_ALL && (int) Context::getContext()->shop->id === (int) $idShopA, $label . ': the all-shops context is handed back');
    }
} else {
    $idShopA = (int) Configuration::get('PS_SHOP_DEFAULT');
    $shopA = new Shop($idShopA);
    $saved = array();
    foreach (array('PS_MULTISHOP_FEATURE_ACTIVE', 'PS_TWO_OS_FULFILLED_MAP', 'PS_TWO_MERCHANT_API_KEY') as $key) {
        $saved[$key] = array(Configuration::hasKey($key), Configuration::get($key, null, 0, 0));
    }
    $shopBStatus = (int) Configuration::get('PS_OS_SHIPPING');
    $allShopsStatus = (int) Configuration::get('PS_OS_DELIVERED');
    $source = (int) Db::getInstance()->getValue('SELECT MIN(`id_order`) FROM `' . _DB_PREFIX_ . 'orders`');
    $check($source > 0, 'the shop has an order to copy');

    $shopB = new Shop();
    $shopB->name = 'Probe shop B';
    $shopB->id_shop_group = (int) $shopA->id_shop_group;
    $shopB->id_category = (int) $shopA->id_category;
    $shopB->theme_name = $shopA->theme_name;
    $shopB->active = 0;
    $added = $source > 0 && $shopB->add();
    $check($added, 'shop B is added');
    $idShopB = (int) $shopB->id;
    $copy = $added ? (new Order($source))->duplicateObject() : false;
    $idOrder = is_object($copy) ? (int) $copy->id : 0;
    $check($idOrder > 0, 'the order is copied');
    register_shutdown_function(static function () use ($shopB, $idShopB, $idOrder, $saved) {
        if ($idOrder > 0) {
            Db::getInstance()->delete('orders', '`id_order` = ' . (int) $idOrder);
        }
        Db::getInstance()->delete('configuration', '`id_shop` = ' . (int) $idShopB);
        if ($idShopB > 0) {
            $shopB->delete();
        }
        foreach ($saved as $key => $was) {
            list($had, $value) = $was;
            if ($had) {
                Configuration::updateGlobalValue($key, $value);
            } else {
                Configuration::deleteByName($key);
            }
        }
    });
    if ($idOrder > 0) {
        Db::getInstance()->update('orders', array('id_shop' => $idShopB, 'module' => 'twopayment'), '`id_order` = ' . $idOrder);
        Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', 1);
        Configuration::updateGlobalValue('PS_TWO_OS_FULFILLED_MAP', json_encode(array($allShopsStatus)));
        Configuration::updateGlobalValue('PS_TWO_MERCHANT_API_KEY', SHOP_CONTEXT_PROBE_ALL_KEY);
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', json_encode(array($shopBStatus)), false, (int) $shopB->id_shop_group, $idShopB);
        Configuration::updateValue('PS_TWO_MERCHANT_API_KEY', SHOP_CONTEXT_PROBE_SHOP_B_KEY, false, (int) $shopB->id_shop_group, $idShopB);
        $args = array('check', $idShopA, $idOrder, $shopBStatus, $allShopsStatus);
        $command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M ' . escapeshellarg(__FILE__) . ' ' . implode(' ', array_map('escapeshellarg', array_map('strval', $args)));
        passthru($command, $code);
        $check($code === 0, 'the check phase passes');
    }
}

if ($failures !== array()) {
    echo count($failures) . " check(s) failed\n";
    exit(1);
}
if ($phase === 'main') {
    echo "all checks passed\n";
}
