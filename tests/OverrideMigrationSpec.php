<?php

declare(strict_types=1);

// Not loaded by twopayment.php (only the upgrade script that needs it requires
// it), so the spec pulls it in itself. tests/bootstrap.php has already defined
// _PS_VERSION_, which the file's guard clause requires.
require_once dirname(__DIR__) . '/classes/TwoOverrideMigrator.php';

/**
 * Pins the ownership and staleness decision in TwoOverrideMigrator (TWO-25265).
 *
 * This is the only part of the shop-level override refresh that can be tested
 * offline, and it is also the only part where a wrong answer is destructive:
 * `classify()` returning STALE for a file that is not exclusively ours means
 * deleting another module's override, or a merchant's hand-written one. So the
 * cases below are weighted towards "refuse to touch it", not towards the happy
 * path.
 *
 * The stamp format is PrestaShop's own, written by `Module::addOverride()` above
 * every member it splices into the shop's copy. It is not something this module
 * emits, so these fixtures mirror core's exact output — comment block, leading
 * asterisk, `module:` / `date:` / `version:` in that order.
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
     * An upgrade script's override refresh is housekeeping ON TOP of an upgrade
     * that has already succeeded, so nothing it can hit may propagate: a throw
     * here leaves the module version un-bumped and the shop in a state no later
     * script can reason about. Errors included, not just exceptions - a TypeError
     * or a missing class on an odd install is exactly the shape that gets past a
     * `catch (Exception)` (TWO-25326, review round 3).
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

    /**
     * One stamped member, as core writes it.
     */
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
        // The case that matters: PrestaShop's override/ tree is a SHARED merge
        // target. `addOverride()` splices each module's methods into ONE file, so
        // a file can carry our stamp and someone else's at the same time.
        // Deleting it would silently uninstall their override — a worse failure
        // than the stale-override bug this whole mechanism exists to fix.
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
        // The actual production case: a 2.4.0-stamped CustomerAddressFormatter
        // still injecting department and project into the address form on a shop
        // reporting 2.7.0.
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
        // Possible in practice: methods spliced in at different releases, since
        // addOverride() only ever ADDS. If any member is off-version the file is
        // not a faithful copy of the current override.
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
        // The word "module:" inside ordinary prose must not read as a stamp — the
        // regex is anchored to a comment-continuation line for exactly this
        // reason. Getting this wrong in the permissive direction turns an
        // unrelated docblock into a foreign owner and quietly disables the
        // migration; in the other direction it invents ownership.
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
        // Raised in adversarial review. A multiline string literal can contain
        // text that is byte-identical to a stamp, and matching line-by-line on
        // raw source cannot tell the two apart. Here the file is genuinely
        // CURRENT; reading the literal would classify it STALE and rewrite it.
        // Stamps are therefore extracted from comment TOKENS only.
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
        // No `<?php` open tag: token_get_all() yields inline HTML and no comment
        // tokens, so nothing is ours and nothing is touched. Failing towards
        // "leave it alone" is the whole point — the alternative is deleting a
        // file we could not read.
        TinyAssert::same(
            TwoOverrideMigrator::UNSTAMPED,
            TwoOverrideMigrator::classify("* module: twopayment\n* version: 2.4.0\n", '2.7.1'),
            'Source that yields no PHP comment tokens must classify UNSTAMPED, even when the '
            . 'raw bytes look exactly like a stamp.'
        );
    }
}
