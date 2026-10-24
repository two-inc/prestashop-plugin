<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/admin/AdminTwoErrorLogController.php';

/**
 * TWO-26612: the error log tab is hidden, so core grants it only to SuperAdmin and the profile of
 * the employee who installed the module. Viewing it is also open to any profile that may view Orders; every
 * other action still asks the tab's own permission.
 */
final class ErrorLogAccessSpec
{
    private const PROFILE = 4;

    private const OWN_READ = 'ROLE_MOD_TAB_ADMINTWOERRORLOG_READ';

    private const ORDERS_READ = 'ROLE_MOD_TAB_ADMINORDERS_READ';

    public static function runAll(): void
    {
        self::testViewFollowsTheOrdersTab();
        self::testOnlyViewingIsWidened();
        self::testPrestaShopNineAsksViewAccess();
    }

    /** @param string[] $roles */
    private static function controllerFor(array $roles, int $idProfile = self::PROFILE): AdminTwoErrorLogController
    {
        Access::$granted = [$idProfile => array_fill_keys($roles, true)];
        Context::getContext()->employee = (object) ['id_profile' => $idProfile];
        $controller = new AdminTwoErrorLogController();
        $controller->controller_name = 'AdminTwoErrorLog';

        return $controller;
    }

    private static function testViewFollowsTheOrdersTab(): void
    {
        // [roles granted to the employee's profile, profile id, may view, description].
        $cases = [
            [[self::ORDERS_READ], self::PROFILE, true, 'a profile that may view Orders may view the log'],
            [[self::OWN_READ], self::PROFILE, true, 'the tab\'s own permission still opens it'],
            [['ROLE_MOD_TAB_ADMINORDERS_UPDATE', 'ROLE_MOD_TAB_ADMINCUSTOMERS_READ'], self::PROFILE, false, 'no view of Orders, no log'],
            [[], self::PROFILE, false, 'a profile with nothing granted is refused'],
            [[self::ORDERS_READ], 0, false, 'no signed-in profile is refused'],
        ];
        foreach ($cases as [$roles, $idProfile, $mayView, $description]) {
            TinyAssert::same($mayView, self::controllerFor($roles, $idProfile)->viewAccess(), $description);
        }
    }

    private static function testOnlyViewingIsWidened(): void
    {
        $controller = self::controllerFor([self::ORDERS_READ]);
        foreach (['add', 'edit', 'delete'] as $action) {
            TinyAssert::false($controller->access($action), $action . ' still asks the tab\'s own permission');
        }
        TinyAssert::false($controller->access('view'), 'access(view), which core\'s list actions ask, still asks the tab\'s own permission');
    }

    /**
     * PrestaShop 9's wrapper for legacy controllers asks viewAccess() about a read only when the
     * controller's class declares getTabSlug(); otherwise it asks the tab's own permission.
     */
    private static function testPrestaShopNineAsksViewAccess(): void
    {
        $declaring = (new ReflectionMethod(AdminTwoErrorLogController::class, 'getTabSlug'))->getDeclaringClass()->getName();
        TinyAssert::same(AdminTwoErrorLogController::class, $declaring, 'the controller declares getTabSlug()');
        TinyAssert::same(
            'ROLE_MOD_TAB_ADMINTWOERRORLOG_',
            self::controllerFor([])->getTabSlug(),
            'the slug is the tab\'s own, so every other action keeps its permission'
        );
    }
}
