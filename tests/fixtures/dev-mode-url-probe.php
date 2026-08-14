<?php

declare(strict_types=1);

/**
 * Subprocess probe for the dev-mode env-var URL overrides.
 *
 * _PS_MODE_DEV_ is a CONSTANT, so a single PHP process cannot exercise both
 * sides of the gate that guards TWO_API_BASE_URL / TWO_PORTAL_BASE_URL /
 * TWO_CHECKOUT_BASE_URL. Tests therefore run this file in a child process with
 * the constant pinned, and read the three resolved URLs back as JSON.
 *
 * Usage:
 *   PROBE_PS_MODE_DEV=1|0|unset [PROBE_EMPTY_VARS=A,B] \
 *     php tests/fixtures/dev-mode-url-probe.php <environment>
 *
 * PROBE_EMPTY_VARS exists because proc_open() DROPS empty-valued entries from
 * the env array it is handed, so a caller cannot deliver `FOO=` to the child at
 * all - the child would see FOO as absent and exercise the wrong branch of the
 * gate. Each name listed here is re-created empty with putenv(). That is the
 * shape docker-compose.yml ships (`TWO_CHECKOUT_BASE_URL: ${TWO_CHECKOUT_BASE_URL:-}`),
 * so it is the empty case worth testing.
 *
 * PROBE_PS_MODE_DEV=unset - or the variable being absent altogether - leaves the
 * constant undefined (the shape the offline suite itself runs in); 1 / 0 define
 * it true / false, which is what a real PrestaShop always does.
 *
 * Prints {"signup":"...","api":"...","portal":"..."} on stdout.
 */

$mode = getenv('PROBE_PS_MODE_DEV');
if ($mode === '1' || $mode === '0') {
    define('_PS_MODE_DEV_', $mode === '1');
}

$emptyVars = (string) getenv('PROBE_EMPTY_VARS');
if ($emptyVars !== '') {
    foreach (explode(',', $emptyVars) as $name) {
        $name = trim($name);
        if ($name !== '') {
            putenv($name . '=');
        }
    }
}

require dirname(__DIR__) . '/bootstrap.php';

Configuration::updateValue('PS_TWO_ENVIRONMENT', $argv[1] ?? 'sandbox');

$module = new TwopaymentTestHarness();

echo json_encode(array(
    'signup' => TwoSoleTrader::getSignupPageUrl(),
    'api' => $module->getTwoCheckoutHostUrl(),
    'portal' => $module->getTwoPortalUrl(),
));
