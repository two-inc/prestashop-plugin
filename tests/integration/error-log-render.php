<?php

/**
 * INTEGRATION PROBE - the Two error log page renders for SuperAdmin (TWO-26639), against a real PrestaShop engine.
 *
 * PrestaShop 9 removed ModuleAdminController::l(), so a controller that translated its strings with $this->l()
 * threw an UndefinedMethodError from initContent() and the page answered 500. Permission checks never reach that
 * code, so this probe renders the page: it logs one module line, runs initContent() as SuperAdmin and checks the
 * content holds both panels and the line it logged.
 *
 * TWO-27014: it also stores an order postprocessing trace as the module does, checks the page lists it, and that
 * the download's lookup gives back the document core stored, on 1.7's escaping, core's tag stripping and its checks.
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
$id_trace = 0;
try {
    PrestaShopLogger::addLog($marker, 1);
    $id_log = (int) Db::getInstance()->getValue(
        'SELECT MAX(id_log) FROM `' . _DB_PREFIX_ . "log` WHERE message = '" . pSQL($marker) . "'"
    );
    $check($id_log > 0, 'the probe logged a module line');

    // TWO-27014: a trace whose payload holds what core's log handling mangles: quotes, a backslash and tags.
    $payload = array('merchant_reference' => "O'Brien \\ <b>bold</b> & \"quoted\"", 'gross_amount' => '47.80');
    $subscriber = new stdClass();
    $subscriber->name = 'probesubscriber';
    $trace = new TwoOrderPostprocessingTrace('order_create', 'checkout', 987654, 0, '0.0.0-probe', array('probesubscriber'), $payload);
    $trace->starting($subscriber);
    $trace->finished($subscriber, array('gross_amount' => '47.80') + $payload);
    PrestaShopLogger::addLog($trace->encode(), 1, null, TwoOrderPostprocessingTrace::LOG_OBJECT_TYPE, 987654, true);
    $id_trace = (int) Db::getInstance()->getValue(
        'SELECT MAX(id_log) FROM `' . _DB_PREFIX_ . "log` WHERE object_type = '" . pSQL(TwoOrderPostprocessingTrace::LOG_OBJECT_TYPE) . "' AND object_id = 987654"
    );
    $check($id_trace > 0, 'the probe stored a trace under its object type');

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
        $check(strpos($content, 'Order postprocessing traces') !== false, 'the trace panel is rendered');
        $check(strpos($content, 'order_create (checkout)') !== false && strpos($content, 'probesubscriber') !== false, 'the trace is listed with its request and subscriber');
        $check(strpos($content, 'downloadSnapshot=' . $id_trace) !== false, 'the trace has a download link');
    }

    // The download's lookup, which the page itself only reaches through a response that exits. Core ties a module
    // admin controller to its tab by class name, so it is called on the page's own controller rather than a subclass.
    $lookup = new ReflectionMethod('AdminTwoErrorLogController', 'getTwoDocument');
    $lookup->setAccessible(true);
    $document = $lookup->invoke($controller, $id_trace);
    $decoded = is_array($document) ? json_decode($document['json'], true) : null;
    $check(is_array($decoded) && $decoded['before'] === $payload, 'the downloaded trace decodes to the payload as it was, quotes, backslash and tags included');
    $check(is_array($decoded) && $decoded['steps'][0]['payload']['gross_amount'] === '47.80', 'the downloaded trace holds the step');
    $check(is_array($document) && $document['filename'] === 'two-postprocessing-trace-cart-987654-' . $id_trace . '.json', 'the download is named for a trace');
    $check($lookup->invoke($controller, $id_log) === null, 'a plain log line is not downloadable');
} finally {
    if ($id_log > 0) {
        Db::getInstance()->delete('log', 'id_log = ' . $id_log);
    }
    if ($id_trace > 0) {
        Db::getInstance()->delete('log', 'id_log = ' . $id_trace);
    }
}

if ($failures !== array()) {
    echo count($failures) . " check(s) failed\n";
    exit(1);
}
echo "all checks passed\n";
