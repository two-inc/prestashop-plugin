<?php

/**
 * Render baseline for the "Tax codes for 0% lines" section (TWO-26153).
 *
 * Renders the Order management form for every case in tests/fixtures/tax-admin-cases.php against the module tree at
 * <root> and prints, per case, the Configuration rows the render wrote as [key, shop group, shop, value]. Run once on
 * the base commit and once on the branch (dev/tax-admin-baseline.sh does both), the two outputs may differ only in the
 * admin-only slots the section owns: anything else the render writes reaches order time.
 *
 * Usage: php dev/tax-admin-baseline.php <module root>
 */

if (!isset($argv[1]) || !is_file($argv[1] . '/twopayment.php')) {
    fwrite(STDERR, "usage: php dev/tax-admin-baseline.php <module root>\n");
    exit(2);
}
require $argv[1] . '/tests/bootstrap.php';
require __DIR__ . '/../tests/fixtures/tax-admin-cases.php';

/** @return array<string,mixed> every Configuration row, keyed "key|group|shop" */
function taxAdminBaselineRows()
{
    $rows = array();
    foreach (StubStore::$configuration as $key => $value) {
        $rows[$key . '|0|0'] = $value;
    }
    foreach (StubStore::$configurationGroup as $group => $values) {
        foreach ($values as $key => $value) {
            $rows[$key . '|' . $group . '|0'] = $value;
        }
    }
    foreach (StubStore::$configurationShop as $shop => $values) {
        foreach ($values as $key => $value) {
            $rows[$key . '|0|' . $shop] = $value;
        }
    }

    return $rows;
}

/** A clock reading (seconds within two days of now) differs between runs, so it is compared as "<ts>". */
function taxAdminBaselineValue($value)
{
    if (is_numeric($value) && abs((int) $value - time()) < 2 * 86400 && (int) $value > 1000000000) {
        return '<ts>';
    }

    return is_scalar($value) || $value === null ? (string) $value : json_encode($value);
}

$out = array();
foreach (TaxAdminCases::all() as $case) {
    list($name, $setup, $responses) = $case;
    $setup();
    $module = new TaxAdminFixtureModule();
    $module->responses = $responses;
    $before = taxAdminBaselineRows();
    $values = new ReflectionMethod(Twopayment::class, 'getTwoOrderManagementFormValues');
    $form = new ReflectionMethod(Twopayment::class, 'getTwoOrderManagementForm');
    $values->invoke($module);
    $form->invoke($module);
    $writes = array();
    foreach (taxAdminBaselineRows() as $id => $value) {
        if (!array_key_exists($id, $before) || $before[$id] !== $value) {
            list($key, $group, $shop) = explode('|', $id);
            $writes[] = array($key, (int) $group, (int) $shop, taxAdminBaselineValue($value));
        }
    }
    foreach (array_diff_key($before, taxAdminBaselineRows()) as $id => $value) {
        list($key, $group, $shop) = explode('|', $id);
        $writes[] = array($key, (int) $group, (int) $shop, '<deleted>');
    }
    sort($writes);
    $out[$name] = $writes;
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
