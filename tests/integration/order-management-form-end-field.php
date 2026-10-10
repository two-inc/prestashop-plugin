<?php

/**
 * INTEGRATION PROBE - the Order management form's end field (TWO-26153), rendered by a real PrestaShop engine.
 *
 * PHP keeps the first max_input_vars values of a post and drops the rest, and the form posts multipart, where it
 * keeps exactly that many, so a cut post cannot be told from a whole one by counting. The module renders a hidden
 * field, Twopayment::ORDER_MANAGEMENT_FORM_END_FIELD, and refuses a save without it. That only holds if the field is
 * the form's last named control, after every mapping row and the save button, in the HTML core's HelperForm and
 * the module's form.tpl override actually produce. This probe renders the form and checks it:
 *   - the form posts multipart/form-data
 *   - the end field is its last named control, after the save button and every mapping row
 *   - another of the module's forms, rendered on the same request, has no end field
 *
 * Hermetic: the tax code list is stubbed, so no network and no Two credentials. Needs only the module installed.
 *
 * Usage: php tests/integration/order-management-form-end-field.php
 */

if (!defined('_PS_ADMIN_DIR_')) {
    // Admin Smarty setup, as a back-office request has it; the folder name is the harness's PS_FOLDER_ADMIN.
    define('_PS_ADMIN_DIR_', '/var/www/html/admin-dev');
}
if (!defined('_PS_VERSION_')) {
    require '/var/www/html/config/config.inc.php';
}
require_once __DIR__ . '/lib/probe-helpers.php';

probeBootKernel();
Module::getInstanceByName('twopayment');

/** The installed module with a fixed tax code list, so the mapping rows render without calling Two. */
class EndFieldProbeTwopayment extends Twopayment
{
    public function getTwoTaxCodeOptions(&$error = '')
    {
        $error = '';

        return array(array('code' => 'ES_IVA_EXPORT', 'display_name' => 'Export', 'rate' => '0', 'requires_exemption_reason' => true, 'exemption_reason_code' => 'VATEX-EU-G'));
    }

    public function render($method)
    {
        return $this->$method();
    }
}

$context = Context::getContext();
$context->employee = new Employee(1);
// A legacy back-office controller present on 1.7.6 to 9 (PS 9 has no legacy AdminModulesController): admin Smarty
// template dirs and getLanguages(), as the module's configuration page has them.
$context->controller = new AdminNotFoundController();
$module = new EndFieldProbeTwopayment();

// A tax rules group with a 0% rule, so the form has an exempt row, a rule row and a no-rule row; removed at the end.
$group = new TaxRulesGroup();
$group->name = 'Probe end field 0%';
$group->active = 1;
$group->add();
$rule = new TaxRule();
$rule->id_tax_rules_group = (int) $group->id;
$rule->id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT');
$rule->id_tax = 0;
$rule->add();
register_shutdown_function(static function () use ($group, $rule) {
    $rule->delete();
    $group->delete();
});

/**
 * @param string $html
 * @return array{enctype: string, controls: array<int, array{tag: string, type: string, name: string}>} the first form's
 *     enctype and its named controls in document order
 */
function endFieldProbeControls($html)
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $form = $dom->getElementsByTagName('form')->item(0);
    if ($form === null) {
        return array('enctype' => '', 'controls' => array());
    }
    $controls = array();
    foreach ((new DOMXPath($dom))->query('.//input|.//select|.//textarea|.//button', $form) as $node) {
        if ($node->getAttribute('name') !== '') {
            $controls[] = array('tag' => $node->nodeName, 'type' => $node->getAttribute('type'), 'name' => $node->getAttribute('name'));
        }
    }

    return array('enctype' => $form->getAttribute('enctype'), 'controls' => $controls);
}

$failures = array();
$check = static function ($ok, $description) use (&$failures) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $description . "\n";
    if (!$ok) {
        $failures[] = $description;
    }
};

$form = endFieldProbeControls($module->render('renderTwoOrderManagementForm'));
$names = array_column($form['controls'], 'name');
$last = end($form['controls']);
$end = Twopayment::ORDER_MANAGEMENT_FORM_END_FIELD;
$rows = array_keys(array_filter($names, static function ($name) {
    return strpos($name, Twopayment::TAX_CODE_MAP_FIELD_PREFIX) === 0;
}));
$buttons = array_keys(array_filter($form['controls'], static function ($control) {
    return $control['tag'] === 'button';
}));
echo 'controls: ' . implode(', ', $names) . "\n";
$check($form['enctype'] === 'multipart/form-data', 'the Order management form posts multipart/form-data (got: ' . $form['enctype'] . ')');
$check($last !== false && $last['name'] === $end && $last['type'] === 'hidden', 'the end field is the form\'s last named control');
$check(count(array_keys($names, $end)) === 1, 'the end field renders once');
$check($rows !== array(), 'the mapping rows render');
$check($rows !== array() && $buttons !== array() && max($rows) < count($names) - 1 && max($buttons) < count($names) - 1, 'every mapping row and the save button come before the end field');

$other = endFieldProbeControls($module->render('renderTwoOrderStatusForm'));
$check($other['controls'] !== array() && !in_array($end, array_column($other['controls'], 'name'), true), 'another form rendered on the same request has no end field');

if ($failures !== array()) {
    echo count($failures) . " check(s) failed\n";
    exit(1);
}
echo "all checks passed\n";
