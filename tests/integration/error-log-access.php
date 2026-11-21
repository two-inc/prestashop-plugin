<?php

/**
 * INTEGRATION PROBE - who may read the Two error log (TWO-26612), against a real PrestaShop engine's permissions.
 *
 * The error log tab is hidden, and core grants a new tab only to SuperAdmin and the installer's profile, so any
 * other profile got "Access denied". AdminTwoErrorLogController::viewAccess() also lets in a profile that may view
 * Orders. This probe makes throwaway profiles with real grants in core's access table and checks:
 *   - a profile granted only the Orders tab's view may view the log
 *   - a profile granted only the Orders tab's edit, or nothing, may not
 *   - the Orders viewer is refused every other action on the log tab: those still ask the tab's own permission
 *   - on PrestaShop 9, the Symfony wrapper for legacy controllers consults viewAccess() for a read, which it does
 *     only for a controller it sees declaring getTabSlug()
 *
 * Hermetic: no network and no Two credentials. Needs only the module installed. The profiles are deleted on exit.
 *
 * Usage: php tests/integration/error-log-access.php
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

/**
 * A throwaway profile holding exactly these roles in core's access table.
 *
 * @param string[] $roles
 * @return int
 */
function errorLogProbeProfile(array $roles)
{
    $profile = new Profile();
    foreach (Language::getLanguages(false) as $language) {
        $profile->name[(int) $language['id_lang']] = 'Two error log probe';
    }
    if (!$profile->add()) {
        throw new RuntimeException('could not add a probe profile');
    }
    $id = (int) $profile->id;
    Db::getInstance()->delete('access', 'id_profile = ' . $id);
    foreach ($roles as $role) {
        $id_role = (int) Db::getInstance()->getValue(
            'SELECT id_authorization_role FROM `' . _DB_PREFIX_ . "authorization_role` WHERE slug = '" . pSQL($role) . "'"
        );
        if ($id_role <= 0) {
            throw new RuntimeException('no authorization role ' . $role);
        }
        Db::getInstance()->insert('access', array('id_profile' => $id, 'id_authorization_role' => $id_role));
    }

    return $id;
}

/**
 * @param int $id_profile
 * @return AdminTwoErrorLogController
 */
function errorLogProbeController($id_profile)
{
    $employee = new Employee(1);
    $employee->id_profile = (int) $id_profile;
    Context::getContext()->employee = $employee;

    return new AdminTwoErrorLogController();
}

$profiles = array();
try {
    $check((int) Tab::getIdFromClassName('AdminTwoErrorLog') > 0, 'the error log tab is installed');

    $profiles['viewer'] = errorLogProbeProfile(array('ROLE_MOD_TAB_ADMINORDERS_READ'));
    $profiles['editor'] = errorLogProbeProfile(array('ROLE_MOD_TAB_ADMINORDERS_UPDATE'));
    $profiles['none'] = errorLogProbeProfile(array());

    $viewer = errorLogProbeController($profiles['viewer']);
    $check($viewer->viewAccess() === true, 'a profile that may view Orders may view the error log');
    foreach (array('add', 'edit', 'delete') as $action) {
        $check(!$viewer->access($action), 'the Orders viewer is refused ' . $action . ' on the error log tab');
    }
    $check(!$viewer->access('view'), 'access(view), which core\'s list actions ask, still asks the tab\'s own permission');
    $check(errorLogProbeController($profiles['editor'])->viewAccess() === false, 'Orders edit without view does not open the log');
    $check(errorLogProbeController($profiles['none'])->viewAccess() === false, 'a profile with nothing granted is refused');
    $check(errorLogProbeController(_PS_ADMIN_PROFILE_)->viewAccess() === true, 'SuperAdmin may still view the log');

    $legacy = 'PrestaShopBundle\Controller\Admin\LegacyController';
    if (class_exists($legacy) && method_exists($legacy, 'isMethodOverridden')) {
        $wrapper = (new ReflectionClass($legacy))->newInstanceWithoutConstructor();
        $overridden = new ReflectionMethod($legacy, 'isMethodOverridden');
        $overridden->setAccessible(true);
        $check($overridden->invoke($wrapper, $viewer) === true, 'PrestaShop 9 consults viewAccess() for a read of the error log');
    } else {
        echo "SKIP no Symfony wrapper for legacy controllers on PrestaShop " . _PS_VERSION_ . "\n";
    }
} finally {
    foreach ($profiles as $id) {
        (new Profile($id))->delete();
    }
}

if ($failures !== array()) {
    echo count($failures) . " check(s) failed\n";
    exit(1);
}
echo "all checks passed\n";
