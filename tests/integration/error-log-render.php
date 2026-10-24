<?php

/**
 * INTEGRATION PROBE - the Two error log page renders for SuperAdmin (TWO-26639), against a real PrestaShop engine.
 *
 * PrestaShop 9 removed ModuleAdminController::l(), so a controller that translated its strings with $this->l()
 * threw an UndefinedMethodError from initContent() and the page answered 500. Permission checks never reach that
 * code, so this probe renders the page: it logs one module line, runs initContent() as SuperAdmin and checks the
 * content holds both panels and the line it logged.
 *
 * Hermetic: no network and no Two credentials. Needs only the module installed. The log line is deleted on exit.
 *
 * Usage: php tests/integration/error-log-render.php
 */

if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', '/var/www/html/admin-dev');
}
if (!defined('_PS_VERSION_')) {
    require '/var/www/html/config/config.inc.php';
}
require_once __DIR__ . '/lib/probe-helpers.php';

probeBootKernel();
Module::getInstanceByName('twopayment');
require_once _PS_MODULE_DIR_ . 'twopayment/controllers/admin/AdminTwoErrorLogController.php';

$failures = array();
$check = static function ($ok, $description) use (&$failures) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $description . "\n";
    if (!$ok) {
        $failures[] = $description;
    }
};

$marker = 'TwoPayment: error log render probe ' . uniqid();
$id_log = 0;
try {
    PrestaShopLogger::addLog($marker, 1);
    $id_log = (int) Db::getInstance()->getValue(
        'SELECT MAX(id_log) FROM `' . _DB_PREFIX_ . "log` WHERE message = '" . pSQL($marker) . "'"
    );
    $check($id_log > 0, 'the probe logged a module line');

    $employee = new Employee(1);
    $employee->id_profile = (int) _PS_ADMIN_PROFILE_;
    Context::getContext()->employee = $employee;

    $controller = new AdminTwoErrorLogController();
    Context::getContext()->controller = $controller;
    $check($controller->viewAccess() === true, 'SuperAdmin may view the error log');

    $content = null;
    try {
        $controller->initContent();
        $content = (string) Context::getContext()->smarty->getTemplateVars('content');
    } catch (Throwable $e) {
        $check(false, 'initContent() renders without throwing (' . get_class($e) . ': ' . $e->getMessage() . ')');
    }

    if ($content !== null) {
        $check(true, 'initContent() renders without throwing');
        $check(strpos($content, 'last log records') !== false, 'the log panel heading is rendered');
        $check(strpos($content, htmlspecialchars($marker, ENT_QUOTES, 'UTF-8')) !== false, 'the logged line is listed');
        $check(strpos($content, 'Discrepancy snapshots') !== false, 'the discrepancy snapshot panel is rendered');
    }
} finally {
    if ($id_log > 0) {
        Db::getInstance()->delete('log', 'id_log = ' . $id_log);
    }
}

if ($failures !== array()) {
    echo count($failures) . " check(s) failed\n";
    exit(1);
}
echo "all checks passed\n";
