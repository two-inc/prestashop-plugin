<?php

declare(strict_types=1);

/**
 * Guards the module's upgrade-script contract (TWO-25230).
 *
 * PrestaShop considers `upgrade/upgrade-<version>.php` only for versions
 * STRICTLY ABOVE the version currently installed and AT OR BELOW the version
 * being installed, and it derives the function to call from the filename. Two
 * things therefore fail silently:
 *
 *   - a script whose filename and `upgrade_module_X_Y_Z` function disagree is
 *     loaded and then nothing is called;
 *   - a script numbered ABOVE the declared module version is never in range for
 *     any upgrade, so it never runs at all.
 *
 * Neither produces an error, a warning, a log line or a failing check. The
 * first symptom is a merchant whose data was quietly never migrated. Now that
 * the version bump is automated (patch on staging, minor on main), the declared
 * version moves without a human looking at it, so this needs to be a gate
 * rather than a convention.
 *
 * NOTE ON THE BOUNDARY: declared == highest upgrade script is the NORMAL,
 * CORRECT case, not an error. An upgrade script is named for the version it
 * upgrades *to*, so shipping 2.7.0 with `upgrade-2.7.0.php` is exactly the
 * intended pattern — that script is what migrates a 2.6.x shop onto 2.7.0.
 * The repository's own history confirms it: at tags 2.0.0 and 2.2.2 the
 * declared version equalled the highest upgrade script. So the assertion is
 * `declared >= highest`, NOT `>`. A `>` gate would be red on legitimate
 * released content and would block the mechanism it is meant to protect.
 *
 * NOTE ON CONTIGUITY: the version sequence is legitimately NOT contiguous —
 * 2.6.7 was deliberately skipped, and most versions ship no upgrade script at
 * all because most releases need no data migration. A gate demanding a script
 * per consecutive version would be wrong and would block every future bump.
 * Do not add one.
 */
final class UpgradeScriptVersionSpec
{
    public static function runAll(): void
    {
        self::testEveryUpgradeScriptHasMatchingFunction();
        self::testDeclaredVersionsAgree();
        self::testNoUpgradeScriptAboveDeclaredVersion();
    }

    private static function moduleRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Absolute paths of the real upgrade scripts, keyed by their dotted version.
     *
     * `index.php` is PrestaShop's directory-listing stub, not an upgrade
     * script, and is excluded.
     *
     * @return array<string, string>
     */
    private static function upgradeScripts(): array
    {
        $dir = self::moduleRoot() . '/upgrade';
        $found = [];

        foreach ((array) scandir($dir) as $entry) {
            if (!is_string($entry) || !preg_match('/^upgrade-(\d+\.\d+\.\d+)\.php$/', $entry, $m)) {
                continue;
            }
            $found[$m[1]] = $dir . '/' . $entry;
        }

        TinyAssert::true(
            count($found) > 0,
            'Found no upgrade/upgrade-X.Y.Z.php scripts at all — the glob or the directory layout has changed.'
        );

        return $found;
    }

    private static function versionFromConfigXml(): string
    {
        $xml = (string) file_get_contents(self::moduleRoot() . '/config.xml');

        TinyAssert::true(
            (bool) preg_match('/<version>\s*<!\[CDATA\[(\d+\.\d+\.\d+)\]\]>\s*<\/version>/', $xml, $m),
            'Could not read <version> from config.xml.'
        );

        return $m[1];
    }

    private static function versionFromModuleFile(): string
    {
        $php = (string) file_get_contents(self::moduleRoot() . '/twopayment.php');

        TinyAssert::true(
            (bool) preg_match('/\$this->version\s*=\s*\'(\d+\.\d+\.\d+)\'\s*;/', $php, $m),
            'Could not read $this->version from twopayment.php.'
        );

        return $m[1];
    }

    /**
     * Compare two dotted versions the way PrestaShop does. Returns -1, 0 or 1.
     */
    private static function compareVersions(string $a, string $b): int
    {
        return version_compare($a, $b);
    }

    private static function testEveryUpgradeScriptHasMatchingFunction(): void
    {
        foreach (self::upgradeScripts() as $version => $path) {
            $expected = 'upgrade_module_' . str_replace('.', '_', $version);
            $source = (string) file_get_contents($path);

            TinyAssert::true(
                (bool) preg_match('/\bfunction\s+' . preg_quote($expected, '/') . '\s*\(/i', $source),
                sprintf(
                    'upgrade/upgrade-%s.php must declare function %s() — PrestaShop derives the '
                    . 'function name from the filename, and a mismatch means the script is loaded '
                    . 'and then silently never called. Found no such function.',
                    $version,
                    $expected
                )
            );
        }
    }

    private static function testDeclaredVersionsAgree(): void
    {
        $xml = self::versionFromConfigXml();
        $php = self::versionFromModuleFile();

        TinyAssert::same(
            $xml,
            $php,
            sprintf(
                'config.xml declares version %s but twopayment.php declares %s. PrestaShop reads '
                . 'the module version from both at different moments, so a disagreement makes the '
                . 'upgrade decision depend on which one is consulted. Both are bumpver targets in '
                . 'bumpver.toml, so a mismatch means a bump only half-applied.',
                $xml,
                $php
            )
        );
    }

    private static function testNoUpgradeScriptAboveDeclaredVersion(): void
    {
        $declared = self::versionFromConfigXml();

        $highest = null;
        foreach (array_keys(self::upgradeScripts()) as $version) {
            if ($highest === null || self::compareVersions((string) $version, $highest) > 0) {
                $highest = (string) $version;
            }
        }

        // `>=`, deliberately — see the boundary note in the class docblock.
        // Equal is the normal case: a script is named for the version it
        // upgrades TO. Only a script numbered ABOVE the declared version is
        // unreachable, and that is what this catches.
        TinyAssert::true(
            self::compareVersions($declared, (string) $highest) >= 0,
            sprintf(
                'upgrade-%s.php is numbered ABOVE the declared module version %s, so it can '
                . 'never run: PrestaShop only considers upgrade scripts at or below the version '
                . 'being installed. It would be skipped silently, with no error and no log line. '
                . 'Either bump the declared version to at least %s (config.xml AND '
                . 'twopayment.php), or rename the script to the version it actually belongs to.',
                (string) $highest,
                $declared,
                (string) $highest
            )
        );
    }
}
