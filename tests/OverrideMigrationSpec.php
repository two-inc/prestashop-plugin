<?php

declare(strict_types=1);

// Not loaded by twopayment.php, so the spec pulls it in itself; the file's
// guard clause needs the _PS_VERSION_ that tests/bootstrap.php defines.
require_once dirname(__DIR__) . '/classes/TwoOverrideMigrator.php';

/**
 * TWO-25265: ownership and staleness in TwoOverrideMigrator. `classify()`
 * returning STALE for a file that is not exclusively ours deletes another
 * module's override, or a merchant's hand-written one, so the cases below are
 * weighted towards "refuse to touch it".
 *
 * The stamp format is PrestaShop's own, written by `Module::addOverride()`, so
 * these fixtures mirror core's exact output — comment block, leading asterisk,
 * `module:` / `date:` / `version:` in that order.
 */
final class OverrideMigrationSpec
{
    public static function runAll(): void
    {
        self::testUnstampedFileIsNotOurs();
        self::testForeignStampIsCoOwned();
        self::testAnyForeignStampWinsOverOurs();
        self::testOurStampAtCurrentVersionIsCurrent();
        self::testOurStampAtOldVersionIsStale();
        self::testMixedOurVersionsIsStale();
        self::testOurStampWithNoVersionIsStale();
        self::testStampParsingIgnoresProse();
        self::testStampInsideAStringLiteralIsNotAStamp();
        self::testUntokenisableSourceIsLeftAlone();
        self::testUpgradeScriptNeverFailsTheUpgradeOnAnError();
    }

    /**
     * TWO-25326: the override refresh is housekeeping on top of an upgrade that
     * already succeeded, so a throw here would leave the module version
     * un-bumped. Errors included - a `catch (Exception)` misses a TypeError or
     * a missing class on an odd install.
     */
    private static function testUpgradeScriptNeverFailsTheUpgradeOnAnError(): void
    {
        require_once dirname(__DIR__) . '/upgrade/upgrade-2.7.3.php';
        PrestaShopLogger::reset();

        $module = new class extends TwopaymentTestHarness {
            public function getLocalPath()
            {
                throw new TypeError('anything at all, from anywhere in the migrator');
            }
        };

        TinyAssert::true(
            upgrade_module_2_7_3($module),
            'an upgrade script must finish the upgrade even when its housekeeping raises an Error'
        );

        $logged = false;
        foreach (PrestaShopLogger::$logs as $entry) {
            if (strpos($entry['message'], 'override refresh raised') !== false) {
                $logged = true;
            }
        }
        TinyAssert::true($logged, 'and it must say so, or the shop is silently stale');
    }

    /** One stamped member, as core writes it. */
    private static function stamp(string $module, string $version): string
    {
        return "    /*\n"
            . '    * module: ' . $module . "\n"
            . "    * date: 2026-07-08 09:12:44\n"
            . '    * version: ' . $version . "\n"
            . "    */\n";
    }

    private static function file(string ...$stamps): string
    {
        $body = "<?php\n\nclass CustomerAddressFormatter extends CustomerAddressFormatterCore\n{\n";
        foreach ($stamps as $i => $stamp) {
            $body .= $stamp . '    public function m' . $i . "()\n    {\n    }\n\n";
        }

        return $body . "}\n";
    }

    private static function testUnstampedFileIsNotOurs(): void
    {
        $source = "<?php\n\nclass CustomerAddressFormatter extends CustomerAddressFormatterCore\n{\n"
            . "    public function getFormat()\n    {\n    }\n}\n";

        TinyAssert::same(
            TwoOverrideMigrator::UNSTAMPED,
            TwoOverrideMigrator::classify($source, '2.7.1'),
            'A shop override with no module: stamp is PrestaShop core\'s own or a merchant '
            . 'hand-edit. Deleting it would destroy work this module never did. It must '
            . 'classify UNSTAMPED so refresh() leaves it alone.'
        );
    }

    private static function testForeignStampIsCoOwned(): void
    {
        TinyAssert::same(
            TwoOverrideMigrator::CO_OWNED,
            TwoOverrideMigrator::classify(self::file(self::stamp('someothermodule', '1.0.0')), '2.7.1'),
            'An override stamped by another module belongs to that module.'
        );
    }

    private static function testAnyForeignStampWinsOverOurs(): void
    {
        // PrestaShop's override/ tree is a SHARED merge target: addOverride()
        // splices each module's methods into ONE file, so a file can carry our
        // stamp and someone else's at once.
        $source = self::file(
            self::stamp(TwoOverrideMigrator::MODULE_NAME, '2.4.0'),
            self::stamp('someothermodule', '1.0.0')
        );

        TinyAssert::same(
            TwoOverrideMigrator::CO_OWNED,
            TwoOverrideMigrator::classify($source, '2.7.1'),
            'A file carrying our stamp AND another module\'s must classify CO_OWNED, not '
            . 'STALE. Our stamp being stale is not permission to delete a file we share.'
        );
    }

    private static function testOurStampAtCurrentVersionIsCurrent(): void
    {
        $source = self::file(
            self::stamp(TwoOverrideMigrator::MODULE_NAME, '2.7.1'),
            self::stamp(TwoOverrideMigrator::MODULE_NAME, '2.7.1')
        );

        TinyAssert::same(
            TwoOverrideMigrator::CURRENT,
            TwoOverrideMigrator::classify($source, '2.7.1'),
            'Exclusively ours and already at the installed version — nothing to do. This is '
            . 'what makes a second run of the same upgrade a real no-op instead of a '
            . 'delete-and-rewrite.'
        );
    }

    private static function testOurStampAtOldVersionIsStale(): void
    {
        TinyAssert::same(
            TwoOverrideMigrator::STALE,
            TwoOverrideMigrator::classify(
                self::file(self::stamp(TwoOverrideMigrator::MODULE_NAME, '2.4.0')),
                '2.7.1'
            ),
            'Exclusively ours, stamped below the installed version — the exact condition '
            . 'observed on the staging shop on 2026-07-29.'
        );
    }

    private static function testMixedOurVersionsIsStale(): void
    {
        // addOverride() only ever ADDS, so members can be spliced in at
        // different releases.
        $source = self::file(
            self::stamp(TwoOverrideMigrator::MODULE_NAME, '2.7.1'),
            self::stamp(TwoOverrideMigrator::MODULE_NAME, '2.4.0')
        );

        TinyAssert::same(
            TwoOverrideMigrator::STALE,
            TwoOverrideMigrator::classify($source, '2.7.1'),
            'One off-version stamp is enough — a partially current file is still not the '
            . 'current override.'
        );
    }

    private static function testOurStampWithNoVersionIsStale(): void
    {
        $source = "<?php\n\nclass CustomerAddressFormatter extends CustomerAddressFormatterCore\n{\n"
            . "    /*\n    * module: " . TwoOverrideMigrator::MODULE_NAME . "\n    */\n"
            . "    public function getFormat()\n    {\n    }\n}\n";

        TinyAssert::same(
            TwoOverrideMigrator::STALE,
            TwoOverrideMigrator::classify($source, '2.7.1'),
            'Ours but unversioned: we cannot show it is up to date, and rewriting a file we '
            . 'demonstrably own is the safe answer. Fail towards refreshing, not towards '
            . 'leaving a possibly-stale override in place.'
        );
    }

    private static function testStampParsingIgnoresProse(): void
    {
        // The regex is anchored to a comment-continuation line so that a
        // "module:" in prose cannot invent a foreign owner.
        $source = "<?php\n\n// This file relates to module: somethingelse in passing.\n"
            . 'class Foo extends FooCore {}' . "\n";

        TinyAssert::count(
            0,
            TwoOverrideMigrator::stampedModules($source),
            'A bare "module:" in prose is not a PrestaShop ownership stamp.'
        );

        TinyAssert::same(
            TwoOverrideMigrator::UNSTAMPED,
            TwoOverrideMigrator::classify($source, '2.7.1'),
            'No real stamps means UNSTAMPED, which means leave it alone.'
        );
    }

    private static function testStampInsideAStringLiteralIsNotAStamp(): void
    {
        // A multiline string literal can hold text byte-identical to a stamp,
        // which line-by-line matching on raw source cannot tell apart - so
        // stamps are extracted from comment TOKENS only.
        $source = "<?php\n\nclass CustomerAddressFormatter extends CustomerAddressFormatterCore\n{\n"
            . self::stamp(TwoOverrideMigrator::MODULE_NAME, '2.7.1')
            . "    public function getFormat()\n    {\n"
            . '        $doc = "' . "\\n    * module: " . TwoOverrideMigrator::MODULE_NAME
            . "\\n    * version: 2.4.0\\n\";\n"
            . "        return \$doc;\n    }\n}\n";

        TinyAssert::count(
            1,
            TwoOverrideMigrator::stampedModules($source),
            'The module stamp inside the string literal must not be counted — only the '
            . 'real comment stamp is.'
        );

        TinyAssert::same(
            TwoOverrideMigrator::CURRENT,
            TwoOverrideMigrator::classify($source, '2.7.1'),
            'A current override whose body happens to contain stamp-shaped text in a string '
            . 'literal must classify CURRENT. Reading the literal would report the fake '
            . '2.4.0 and delete a file that was never stale.'
        );
    }

    private static function testUntokenisableSourceIsLeftAlone(): void
    {
        // No `<?php` open tag: token_get_all() yields inline HTML and no
        // comment tokens, so nothing is ours and nothing is touched.
        TinyAssert::same(
            TwoOverrideMigrator::UNSTAMPED,
            TwoOverrideMigrator::classify("* module: twopayment\n* version: 2.4.0\n", '2.7.1'),
            'Source that yields no PHP comment tokens must classify UNSTAMPED, even when the '
            . 'raw bytes look exactly like a stamp.'
        );
    }
}
