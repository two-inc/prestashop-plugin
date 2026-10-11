<?php

/**
 * INTEGRATION PROBE - "Clear saved tax codes" on one shop of a multistore install (TWO-26153), against a real
 * PrestaShop engine.
 *
 * Clear writes {} to the tax code mapping at the context shop's tier, with explicit ids, rather than '', which core can
 * store as NULL and so fall back to the global value. This probe checks that on core's real Configuration:
 *   - with multistore on, shop B inheriting an unreadable global mapping, Clear posted in shop B's context
 *   - shop B then reads a readable, empty mapping in its own shop context
 *   - shop A still reads the global mapping, and the global row is untouched
 *
 * Multistore is switched on, and shop B added, before the checks run in child processes: core caches whether the
 * feature is active for the life of a process. Everything is put back at the end.
 *
 * Hermetic: Clear makes no request. Needs only the module installed.
 *
 * Usage: php tests/integration/tax-code-clear-multistore.php
 */

if (!defined('_PS_VERSION_')) {
    require '/var/www/html/config/config.inc.php';
}
require_once __DIR__ . '/lib/probe-helpers.php';

probeBootKernel();
Module::getInstanceByName('twopayment');

const CLEAR_PROBE_UNREADABLE = '{"not-a-row":"ES_IVA_EXPORT"}';

/** The installed module, with the Order management save callable as the configuration page runs it. */
class ClearProbeTwopayment extends Twopayment
{
    /** @return array the validation errors; the save runs only without any */
    public function saveOrderManagement()
    {
        $this->errors = array();
        $this->validTwoOrderManagementFormValues();
        if ($this->errors === array()) {
            $this->saveTwoOrderManagementFormValues();
        }

        return $this->errors;
    }
}

/**
 * @param int $idShop
 * @return void
 */
function clearProbeUseShop($idShop)
{
    Shop::setContext(Shop::CONTEXT_SHOP, (int) $idShop);
    Context::getContext()->shop = new Shop((int) $idShop);
}

$phase = isset($argv[1]) ? $argv[1] : 'main';
$failures = array();
$check = static function ($ok, $description) use (&$failures) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $description . "\n";
    if (!$ok) {
        $failures[] = $description;
    }
};

if ($phase === 'clear') {
    $idShop = (int) $argv[2];
    clearProbeUseShop($idShop);
    $check(Shop::isFeatureActive(), 'multistore is active in the child process');
    $_POST[Twopayment::ORDER_MANAGEMENT_FORM_END_FIELD] = '1';
    $_POST[Twopayment::TAX_CODES_CLEAR_FIELD] = '1';
    $errors = (new ClearProbeTwopayment())->saveOrderManagement();
    $check($errors === array(), 'Clear saves in shop B\'s context (errors: ' . json_encode($errors) . ')');
} elseif ($phase === 'check') {
    list(, , $idShopA, $idShopB) = $argv;
    clearProbeUseShop($idShopB);
    $module = Module::getInstanceByName('twopayment');
    $check($module->isTwoTaxCodeMapReadable(), 'shop B reads a readable mapping');
    $check($module->isTwoTaxCodeMapReadable() && $module->getTwoTaxCodeMap() === array(), 'shop B\'s mapping is empty');
    $check(Configuration::get(Twopayment::CONFIG_TAX_CODE_MAP, null, null, (int) $idShopB) === '{}', 'shop B holds {} at its own tier');
    clearProbeUseShop($idShopA);
    $module = Module::getInstanceByName('twopayment');
    $check(Configuration::get(Twopayment::CONFIG_TAX_CODE_MAP) === CLEAR_PROBE_UNREADABLE, 'shop A still reads the global mapping');
    $check(!$module->isTwoTaxCodeMapReadable(), 'so shop A is still withheld until it is cleared there too');
    $global = Db::getInstance()->getValue('SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL(Twopayment::CONFIG_TAX_CODE_MAP) . '\' AND `id_shop` IS NULL AND `id_shop_group` IS NULL');
    $check($global === CLEAR_PROBE_UNREADABLE, 'the global row is untouched');
} else {
    $idShopA = (int) Configuration::get('PS_SHOP_DEFAULT');
    $shopA = new Shop($idShopA);
    $hadGlobal = Configuration::hasKey(Twopayment::CONFIG_TAX_CODE_MAP);
    $savedGlobal = Configuration::get(Twopayment::CONFIG_TAX_CODE_MAP, null, 0, 0);
    $savedFeature = Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE', null, 0, 0);

    $shopB = new Shop();
    $shopB->name = 'Probe shop B';
    $shopB->id_shop_group = (int) $shopA->id_shop_group;
    $shopB->id_category = (int) $shopA->id_category;
    $shopB->theme_name = $shopA->theme_name;
    $shopB->active = 0;
    $added = $shopB->add();
    $check($added, 'shop B is added');
    $idShopB = (int) $shopB->id;
    register_shutdown_function(static function () use ($shopB, $idShopB, $hadGlobal, $savedGlobal, $savedFeature) {
        Db::getInstance()->delete('configuration', '`id_shop` = ' . (int) $idShopB);
        if ($idShopB > 0) {
            $shopB->delete();
        }
        Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', $savedFeature);
        if ($hadGlobal) {
            Configuration::updateGlobalValue(Twopayment::CONFIG_TAX_CODE_MAP, $savedGlobal);
        } else {
            Configuration::deleteByName(Twopayment::CONFIG_TAX_CODE_MAP);
        }
    });
    if ($added) {
        Configuration::updateGlobalValue('PS_MULTISHOP_FEATURE_ACTIVE', 1);
        Configuration::updateGlobalValue(Twopayment::CONFIG_TAX_CODE_MAP, CLEAR_PROBE_UNREADABLE);
        // Neither shop holds a row of its own, so both inherit the global mapping.
        Db::getInstance()->delete('configuration', '`name` = \'' . pSQL(Twopayment::CONFIG_TAX_CODE_MAP) . '\' AND (`id_shop` IS NOT NULL OR `id_shop_group` IS NOT NULL)');
        foreach (array(array('clear', $idShopB), array('check', $idShopA, $idShopB)) as $args) {
            $command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M ' . escapeshellarg(__FILE__) . ' ' . implode(' ', array_map('escapeshellarg', array_map('strval', $args)));
            passthru($command, $code);
            $check($code === 0, 'the ' . $args[0] . ' phase passes');
        }
    }
}

if ($failures !== array()) {
    echo count($failures) . " check(s) failed\n";
    exit(1);
}
if ($phase === 'main') {
    echo "all checks passed\n";
}
