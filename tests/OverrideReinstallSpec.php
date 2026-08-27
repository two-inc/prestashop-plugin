<?php

declare(strict_types=1);

// Not loaded by twopayment.php, so the spec pulls it in itself; the file's
// guard clause needs the _PS_VERSION_ that tests/bootstrap.php defines.
require_once dirname(__DIR__) . '/classes/TwoOverrideMigrator.php';

/**
 * The module `refresh()` is handed, of which it touches only `$version`,
 * `getLocalPath()` and `installOverrides()`.
 *
 * `installOverrides()` mirrors what `Module::addOverride()` really does, not a
 * convenient version of it: only a MISSING shop file is written fresh. Core's
 * existing-file branch reflects both classes and THROWS on a member the shop
 * copy already declares, so the double throws there too - `refresh()` must
 * never reach `installOverrides()` while a file it decided to leave alone is
 * still on disk.
 */
class ReinstallModuleDouble
{
    /** @var string */
    public $version = OverrideReinstallSpec::MODULE_VERSION;

    /** @var int PrestaShopLogger's object id, which the upgrade scripts pass through. */
    public $id = 1;

    /** @var int */
    public $installOverridesCalls = 0;

    /** @var string */
    private $localPath;

    public function __construct(string $localPath)
    {
        $this->localPath = $localPath;
    }

    public function getLocalPath()
    {
        return $this->localPath;
    }

    public function installOverrides()
    {
        ++$this->installOverridesCalls;

        $destination = _PS_OVERRIDE_DIR_ . OverrideReinstallSpec::SHIPPED;
        if (is_file($destination)) {
            throw new Exception('Method getFormat in class CustomerAddressFormatter is already overridden.');
        }

        @mkdir(dirname($destination), 0777, true);
        file_put_contents($destination, OverrideReinstallSpec::body(OverrideReinstallSpec::MODULE_VERSION));

        return true;
    }
}

/** A module whose overrides cannot be written: core's false return, not a throw. */
final class RefusingModuleDouble extends ReinstallModuleDouble
{
    public function installOverrides()
    {
        ++$this->installOverridesCalls;

        return false;
    }
}

/**
 * TWO-25265: what `refresh()` does to the shop-level override TREE, as opposed
 * to `classify()`'s verdict on one file's bytes (OverrideMigrationSpec).
 *
 * The case that matters most here is the degenerate one: a shop whose copy of
 * an override the module still ships was deleted and never rebuilt. Observed on
 * a staging shop, whose `override/classes/form/` held nothing but PrestaShop's
 * `index.php` stub while the module reported 2.7.9 — so the address form ran
 * with no override at all and laid its fields out in core's order.
 */
final class OverrideReinstallSpec
{
    const MODULE_VERSION = '9.9.9';

    /** An override path the module still ships. */
    const SHIPPED = 'classes/form/CustomerAddressFormatter.php';

    /** An override path a release retired: gone from the module tree. */
    const RETIRED = 'classes/form/RetiredFormatter.php';

    public static function runAll(): void
    {
        foreach (self::cases() as $case) {
            self::assertCase($case);
        }

        self::testReinstallingAMissingOverrideIsIdempotent();
        self::testAnInstallOverridesFailureIsLoggedAsAFailure();
        // Last: it loads a class that permanently changes which branch the
        // generator resolution takes.
        self::testTheClassIndexGoesThroughWhicheverGeneratorPrestaShopHas();
    }

    /**
     * @return array<int, array{0: ?string, 1: array<int, string>, 2: bool, 3: ?string, 4: string}>
     *         shop file contents (null = absent) | retired paths passed in |
     *         installOverrides() expected to run | expected version stamp
     *         afterwards (null = still absent) | assertion message
     */
    private static function cases(): array
    {
        return [
            [
                null,
                [],
                true,
                self::MODULE_VERSION,
                'A shipped override missing from the shop tree must be reinstalled. Nothing on '
                . 'disk is not a current copy - it is the override not running at all, which is '
                . 'exactly the staging shop whose address form lost its field order.',
            ],
            [
                self::body('2.4.0'),
                [],
                true,
                self::MODULE_VERSION,
                'Exclusively ours and stamped below the installed version: deleted, then '
                . 'reinstalled at the installed version.',
            ],
            [
                self::body(self::MODULE_VERSION),
                [],
                false,
                self::MODULE_VERSION,
                'Already at the installed version - refresh() must not churn the tree.',
            ],
            [
                self::body('2.4.0', 'someothermodule'),
                [],
                false,
                '2.4.0',
                'Co-owned with another module: left exactly as found, because deleting it would '
                . 'silently uninstall that module\'s override.',
            ],
            [
                self::unstampedBody(),
                [],
                false,
                null,
                'No module stamp, so it is core\'s or a merchant hand-edit: left alone, and the '
                . 'expected "stamp afterwards" is that it still carries none.',
            ],
            // This row's own call-count assertion is SHADOWED and unproven: drop
            // the shipped/retired guard and the double throws before it is
            // reached, so the mutant dies on the throw, not on the count. Soften
            // the double and this row stops discriminating anything.
            [
                self::body(self::MODULE_VERSION),
                [self::RETIRED],
                false,
                self::MODULE_VERSION,
                'A RETIRED path absent from the shop tree is the finished state, not a gap. '
                . 'Reinstalling on it would put a deliberately removed override back.',
            ],
        ];
    }

    /**
     * @param array{0: ?string, 1: array<int, string>, 2: bool, 3: ?string, 4: string} $case
     */
    private static function assertCase(array $case): void
    {
        list($shopSource, $retiredPaths, $expectReinstall, $expectedStamp, $message) = $case;

        $module = self::freshShop($shopSource);
        TwoOverrideMigrator::refresh($module, $retiredPaths);

        TinyAssert::same(
            $expectReinstall ? 1 : 0,
            $module->installOverridesCalls,
            $message
        );

        $absolute = _PS_OVERRIDE_DIR_ . self::SHIPPED;

        TinyAssert::true(is_file($absolute), $message);

        $stamps = is_file($absolute)
            ? array_values(array_unique(TwoOverrideMigrator::stampedVersions((string) file_get_contents($absolute))))
            : [];

        TinyAssert::same(
            $expectedStamp === null ? [] : [$expectedStamp],
            $stamps,
            $message
        );

        TinyAssert::false(
            is_file(_PS_OVERRIDE_DIR_ . self::RETIRED),
            $message
        );
    }

    /**
     * A reinstall must not re-run on the next upgrade: the file it wrote is
     * stamped at the installed version, so the second pass classifies it CURRENT.
     */
    private static function testReinstallingAMissingOverrideIsIdempotent(): void
    {
        $module = self::freshShop(null);

        TwoOverrideMigrator::refresh($module);
        TwoOverrideMigrator::refresh($module);

        TinyAssert::same(
            1,
            $module->installOverridesCalls,
            'A second refresh() after a reinstall must do nothing - otherwise every later '
            . 'upgrade rewrites the tree.'
        );
    }

    /**
     * Core returns false rather than throwing when an override could not be
     * written, so a swallowed false would report the same "all done" as a real
     * repair - the exact shape of the bug this release exists to fix.
     */
    private static function testAnInstallOverridesFailureIsLoggedAsAFailure(): void
    {
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.10.php';

        self::freshShop(null);
        // The REAL module root, so the upgrade script's own require_once of the
        // migrator resolves and a thrown error cannot be mistaken for the
        // false-return path under test.
        $module = new RefusingModuleDouble(dirname(__DIR__) . '/');

        TinyAssert::true(
            in_array(TwoOverrideMigrator::INSTALL_FAILED_NOTE, TwoOverrideMigrator::refresh($module), true),
            'A false return from installOverrides() must be reported, not discarded.'
        );

        PrestaShopLogger::reset();
        self::freshShop(null);
        TinyAssert::true(upgrade_module_2_7_10($module), 'and the upgrade itself must still finish.');

        $severities = array();
        foreach (PrestaShopLogger::$logs as $entry) {
            $severities[] = $entry['severity'];
        }

        TinyAssert::same(
            array(2),
            $severities,
            'A shop whose override was not written must be logged as a failure. At severity 1 it '
            . 'reads as a successful repair, which is how the original defect stayed invisible.'
        );
    }

    /**
     * PrestaShop 8.1 moved the class index onto the `prestashop/autoload`
     * package and 9 deleted `Tools::generateIndex()`, so a `refresh()` hard-wired
     * to either spelling fatals on one major - swallowed by the upgrade script's
     * `catch`, leaving the shop broken while the upgrade reports success.
     */
    private static function testTheClassIndexGoesThroughWhicheverGeneratorPrestaShopHas(): void
    {
        Tools::$generateIndexCalls = 0;
        TwoOverrideMigrator::refresh(self::freshShop(null));

        TinyAssert::true(
            Tools::$generateIndexCalls > 0,
            'On a PrestaShop with no `prestashop/autoload` package (1.7.6 through 8.0), the '
            . 'index must be rebuilt through Tools::generateIndex().'
        );

        require_once __DIR__ . '/fixtures/ps9-autoload-double.php';

        Tools::$generateIndexCalls = 0;
        PrestaShop\Autoload\PrestashopAutoload::$generateIndexCalls = 0;
        TwoOverrideMigrator::refresh(self::freshShop(null));

        TinyAssert::true(
            PrestaShop\Autoload\PrestashopAutoload::$generateIndexCalls > 0,
            'On 8.1 and later it must go through the autoload package, exactly as core\'s own '
            . 'Module::addOverride() does.'
        );

        TinyAssert::same(
            0,
            Tools::$generateIndexCalls,
            'and it must NOT call Tools::generateIndex() there - PrestaShop 9 deleted that '
            . 'method, so calling it is a fatal, not a fallback.'
        );
    }

    /**
     * A throwaway module tree (shipping SHIPPED only) plus a shop override tree
     * holding $shopSource at SHIPPED, or nothing at all when it is null.
     */
    private static function freshShop(?string $shopSource): ReinstallModuleDouble
    {
        $moduleRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'two-ps-spec-module';
        self::removeTree($moduleRoot);
        self::removeTree(rtrim(_PS_OVERRIDE_DIR_, DIRECTORY_SEPARATOR));

        $moduleOverride = $moduleRoot . '/override/' . dirname(self::SHIPPED);
        @mkdir($moduleOverride, 0777, true);
        file_put_contents($moduleOverride . '/' . basename(self::SHIPPED), self::body(self::MODULE_VERSION));
        // PrestaShop's directory-listing stub, present in every override
        // directory and never itself an override.
        file_put_contents($moduleOverride . '/index.php', "<?php\n");

        @mkdir(_PS_OVERRIDE_DIR_ . dirname(self::SHIPPED), 0777, true);
        if ($shopSource !== null) {
            file_put_contents(_PS_OVERRIDE_DIR_ . self::SHIPPED, $shopSource);
        }

        return new ReinstallModuleDouble($moduleRoot . '/');
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    /** One stamped member, in PrestaShop's own `addOverride()` comment format. */
    public static function body(string $version, string $module = 'twopayment'): string
    {
        return "<?php\n\nclass CustomerAddressFormatter extends CustomerAddressFormatterCore\n{\n"
            . "    /*\n    * module: " . $module . "\n    * date: 2026-08-27 09:12:44\n"
            . '    * version: ' . $version . "\n    */\n"
            . "    public function getFormat()\n    {\n    }\n}\n";
    }

    private static function unstampedBody(): string
    {
        return "<?php\n\nclass CustomerAddressFormatter extends CustomerAddressFormatterCore\n{\n"
            . "    public function getFormat()\n    {\n    }\n}\n";
    }
}
