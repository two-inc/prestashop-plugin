<?php

declare(strict_types=1);

// Not loaded by twopayment.php, so the spec pulls it in itself; the file's
// guard clause needs the _PS_VERSION_ that tests/bootstrap.php defines.
require_once dirname(__DIR__) . '/classes/TwoOverrideMigrator.php';

/**
 * The module `refresh()` is handed, of which it touches only `$version`,
 * `getLocalPath()` and `installOverrides()`.
 *
 * `installOverrides()` mirrors `Module::addOverride()`'s add-only contract: an
 * existing shop file is left exactly as found, and only a missing one is
 * written, stamped at the installed version.
 */
final class ReinstallModuleDouble
{
    /** @var string */
    public $version = OverrideReinstallSpec::MODULE_VERSION;

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
            return true;
        }

        @mkdir(dirname($destination), 0777, true);
        file_put_contents($destination, OverrideReinstallSpec::body(OverrideReinstallSpec::MODULE_VERSION));

        return true;
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
