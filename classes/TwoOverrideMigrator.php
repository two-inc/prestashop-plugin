<?php
/**
 * Refreshes the SHOP-LEVEL override tree so it matches the version of the
 * module that is actually installed (TWO-25265).
 *
 * PrestaShop copies a module's `override/` directory into the shop's own
 * `override/` tree once, at install/reset (`Module::installOverrides()`), and
 * that copy is what actually runs — an upgrade or a deploy never touches it.
 * `Module::addOverride()` also can't rewrite an existing shop-level method: it
 * throws if the method is already there and only ever splices in missing
 * methods. So changing or retiring an override in a release leaves every
 * existing shop silently running the old behaviour forever. A module reset (or
 * a disable/enable, which also re-runs `installOverrides()`) does not help
 * either while the shop copy is STALE, because add-only means the existing
 * members stay. It DOES rebuild a copy that is absent - nothing is there to
 * collide with, so `addOverride()` takes its fresh-copy branch.
 *
 * Observed in production-shaped staging on 2026-07-29: a shop on the 2.4.0
 * `CustomerAddressFormatter` override kept injecting fields into the address
 * form long after 2.7.0 moved them elsewhere, with module version, files on
 * disk, and git-sync all reporting current — only the shop-level override was
 * stale.
 *
 * Convention: changing or retiring an override is a MIGRATION. The version
 * that does it calls `TwoOverrideMigrator::refresh()` from its
 * `upgrade/upgrade-<version>.php`; `.github/scripts/check-override-migration.sh`
 * fails the PR if it doesn't.
 *
 * `refresh()` rebuilds the class index and re-runs `installOverrides()` when a
 * shop-level file is stale AND exclusively ours (deleting it first), and when a
 * file the module still ships is ABSENT from the shop tree — a shop whose copy
 * was deleted and never rebuilt runs no override at all, which is not the same
 * as running a current one. It refuses to touch:
 *
 *   1. A file stamped by any OTHER module too — PrestaShop's `override/` tree
 *      is a shared merge target; deleting a co-owned file would silently
 *      uninstall that module's override. Logged and left for a human.
 *   2. A file with no `module:` stamp at all — PrestaShop core's or a
 *      merchant's hand-written override, not ours.
 *   3. A file whose every stamp already matches the current module version —
 *      a no-op, so a second run of the same upgrade doesn't churn.
 *
 * Ownership/staleness is read from the `module:`/`version:` stamp PrestaShop's
 * own `addOverride()` writes above each spliced member, never from anything
 * this module has to remember to record itself.
 *
 * @author Plugin Developer from Two <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoOverrideMigrator
{
    /** Our own name as PrestaShop stamps it into an override file. */
    const MODULE_NAME = 'twopayment';

    /** Classification: no `module:` stamp at all - core's or a merchant's. */
    const UNSTAMPED = 'unstamped';

    /** Classification: at least one stamp belongs to another module. */
    const CO_OWNED = 'co_owned';

    /** Classification: exclusively ours, every stamp at the current version. */
    const CURRENT = 'current';

    /** Classification: exclusively ours, at least one stamp on another version. */
    const STALE = 'stale';

    /** Note an upgrade script matches on to log the refresh as a failure rather than as info. */
    const INSTALL_FAILED_NOTE = 'installOverrides() REPORTED FAILURE - the shop override was not written';

    /**
     * The comment text of a PHP source file, and nothing else.
     *
     * Stamps are read only out of real comment tokens, never raw source: a
     * multiline string literal could contain text indistinguishable from a
     * stamp (e.g. `"\n * module: twopayment\n"`), which matched on raw source
     * would misclassify a current override as stale and rewrite it.
     *
     * `token_get_all()` needs a `<?php` open tag to produce tokens; a file
     * without one (or that fails to tokenise) yields no comments, landing on
     * UNSTAMPED — "leave it alone", the safe direction.
     *
     * @param string $source
     *
     * @return string
     */
    private static function commentText($source)
    {
        $tokens = @token_get_all((string) $source);
        if (!is_array($tokens)) {
            return '';
        }

        $text = '';
        foreach ($tokens as $token) {
            if (!is_array($token) || !isset($token[0], $token[1])) {
                continue;
            }
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $text .= $token[1] . "\n";
            }
        }

        return $text;
    }

    /**
     * Every `module:` name stamped into an override file's COMMENTS, in order of
     * appearance and with duplicates kept.
     *
     * @param string $source
     *
     * @return array<int, string>
     */
    public static function stampedModules($source)
    {
        $matches = array();
        preg_match_all('/^\s*\*\s*module:\s*(\S+)\s*$/m', self::commentText($source), $matches);

        return isset($matches[1]) ? $matches[1] : array();
    }

    /**
     * Every `version:` stamped into an override file's COMMENTS.
     *
     * @param string $source
     *
     * @return array<int, string>
     */
    public static function stampedVersions($source)
    {
        $matches = array();
        preg_match_all('/^\s*\*\s*version:\s*([0-9][0-9.]*)\s*$/m', self::commentText($source), $matches);

        return isset($matches[1]) ? $matches[1] : array();
    }

    /**
     * Decide what a shop-level override file is, from its content alone.
     *
     * Pure - no filesystem, no PrestaShop. This is the whole ownership and
     * staleness decision, so it is the part that is unit-tested
     * (tests/OverrideMigrationSpec.php).
     *
     * @param string $source         Contents of the shop-level override file
     * @param string $currentVersion The module version now installed
     *
     * @return string One of the UNSTAMPED / CO_OWNED / CURRENT / STALE constants
     */
    public static function classify($source, $currentVersion)
    {
        $modules = self::stampedModules($source);

        if (empty($modules)) {
            return self::UNSTAMPED;
        }

        foreach ($modules as $module) {
            if ($module !== self::MODULE_NAME) {
                return self::CO_OWNED;
            }
        }

        // Exclusively ours from here on. A missing version stamp on a file that
        // does carry a module stamp is treated as stale rather than current: we
        // cannot show it is up to date, and rewriting it is the safe answer.
        $versions = self::stampedVersions($source);
        if (empty($versions)) {
            return self::STALE;
        }

        foreach ($versions as $version) {
            if (version_compare($version, (string) $currentVersion, '!=')) {
                return self::STALE;
            }
        }

        return self::CURRENT;
    }

    /**
     * Bring the shop's override tree into line with the installed module.
     *
     * Idempotent: a second call finds every file present and CURRENT and does
     * nothing.
     *
     * @param Module            $module        The module being upgraded
     * @param array<int,string> $retiredPaths  Override paths this version RETIRED,
     *                                         relative to the override root
     *                                         (e.g. 'classes/form/Foo.php').
     *                                         Retired files are gone from the
     *                                         module tree, so they cannot be
     *                                         discovered - the upgrade script has
     *                                         to name them.
     *
     * @return array<int, string> Human-readable log lines, one per decision
     */
    public static function refresh($module, array $retiredPaths = array())
    {
        $version = isset($module->version) ? (string) $module->version : '';
        $notes = array();
        $removed = 0;
        $missing = 0;
        $shipped = self::shippedPaths($module);
        $isShipped = array_flip($shipped);

        foreach (self::candidatePaths($shipped, $retiredPaths) as $relative) {
            $absolute = _PS_OVERRIDE_DIR_ . $relative;

            if (!is_file($absolute)) {
                if (!isset($isShipped[$relative])) {
                    // Retired, and already gone from the shop tree.
                    continue;
                }

                // Nothing on disk means the override's behaviour is absent, not current.
                ++$missing;
                $notes[] = sprintf('%s: MISSING from the shop tree - reinstalling', $relative);
                continue;
            }

            $source = @file_get_contents($absolute);
            if ($source === false) {
                $notes[] = sprintf('%s: UNREADABLE, left alone', $relative);
                continue;
            }

            $verdict = self::classify($source, $version);

            if ($verdict === self::CURRENT) {
                $notes[] = sprintf('%s: already at %s, left alone', $relative, $version);
                continue;
            }

            if ($verdict === self::UNSTAMPED) {
                $notes[] = sprintf(
                    '%s: carries no module stamp, so it is not ours (PrestaShop core or a '
                    . 'merchant hand-edit) - left alone',
                    $relative
                );
                continue;
            }

            if ($verdict === self::CO_OWNED) {
                $notes[] = sprintf(
                    '%s: SHARED with another module (%s) - left alone, because deleting it '
                    . 'would silently uninstall that module\'s override. Needs a human',
                    $relative,
                    implode(', ', array_unique(self::stampedModules($source)))
                );
                continue;
            }

            // STALE and exclusively ours.
            if (!is_writable($absolute) || !@unlink($absolute)) {
                $notes[] = sprintf('%s: STALE but could not be deleted (permissions?)', $relative);
                continue;
            }

            ++$removed;
            $notes[] = sprintf(
                '%s: STALE (stamped %s, module is %s) - deleted',
                $relative,
                implode('/', array_unique(self::stampedVersions($source))) ?: 'no version',
                $version
            );
        }

        if ($removed === 0 && $missing === 0) {
            return $notes;
        }

        // Order matters. The autoloader resolves a class through
        // var/cache/<env>/class_index.php, so until that is rebuilt the deleted
        // file is still the answer for this class - which means
        // `addOverride()` would take its "a shop override already exists"
        // branch and read a file that is no longer there. Rebuild first, then
        // reinstall.
        $indexed = self::rebuildClassIndex();
        $installed = $module->installOverrides();

        // `addOverride()` only regenerates the index on the branch that COPIES
        // a fresh file. A retired override leaves nothing to copy, so that
        // branch never runs and the index would keep the deleted path.
        self::rebuildClassIndex();

        $notes[] = sprintf(
            'rebuilt the class index and re-ran installOverrides() after deleting %d stale '
            . 'override(s) and finding %d missing',
            $removed,
            $missing
        );

        // Core returns false rather than throwing when an override could not be written.
        if (!$installed) {
            $notes[] = self::INSTALL_FAILED_NOTE;
        }

        if (!$indexed) {
            $notes[] = 'no class-index generator on this PrestaShop - the override was written, '
                . 'but the autoloader may resolve the old path until the cache is cleared';
        }

        return $notes;
    }

    /**
     * Override paths to consider, relative to the override root: everything the
     * module still ships, plus everything the caller says it retired.
     *
     * @param array<int,string> $shippedPaths Already walked by the caller, which needs the set anyway
     * @param array<int,string> $retiredPaths
     *
     * @return array<int, string>
     */
    private static function candidatePaths(array $shippedPaths, array $retiredPaths)
    {
        $paths = array();

        foreach ($retiredPaths as $relative) {
            $relative = ltrim((string) $relative, '/');
            if ($relative !== '') {
                $paths[$relative] = true;
            }
        }

        foreach ($shippedPaths as $relative) {
            $paths[$relative] = true;
        }

        return array_keys($paths);
    }

    /**
     * Override paths the module still SHIPS, relative to the override root.
     *
     * Kept apart from the retired paths because absence means opposite things
     * for the two: a shipped override missing from the shop tree has to be
     * reinstalled, a retired one missing is the finished state.
     *
     * @param Module $module
     *
     * @return array<int, string>
     */
    private static function shippedPaths($module)
    {
        $paths = array();
        $root = rtrim($module->getLocalPath(), '/') . '/override';

        foreach (self::phpFilesUnder($root) as $absolute) {
            $relative = ltrim(substr($absolute, strlen($root)), '/');
            // `index.php` is PrestaShop's directory-listing stub, present in
            // every directory of every module. It is not an override.
            if ($relative === '' || basename($relative) === 'index.php') {
                continue;
            }
            $paths[$relative] = true;
        }

        return array_keys($paths);
    }

    /**
     * Absolute paths of every `.php` file under a directory, recursively.
     *
     * Hand-rolled rather than `Tools::scandir()`: that helper flattens to
     * basenames in some PrestaShop versions, and the relative path is exactly
     * what is needed here.
     *
     * @param string $dir
     *
     * @return array<int, string>
     */
    private static function phpFilesUnder($dir)
    {
        if (!is_dir($dir)) {
            return array();
        }

        $found = array();
        foreach ((array) scandir($dir) as $entry) {
            if (!is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $found = array_merge($found, self::phpFilesUnder($path));
                continue;
            }
            if (substr($entry, -4) === '.php') {
                $found[] = $path;
            }
        }

        return $found;
    }

    /**
     * Rebuild the class index, in every cached environment rather than only the
     * one this process happens to be running in.
     *
     * Generating it rewrites `class_index.php` for the CURRENT environment's
     * cache directory only. An upgrade driven from the CLI can easily be running
     * in a different environment from the storefront, and then the storefront
     * keeps resolving the class it was told about last - the deleted override.
     * Unlinking the other environments' copies makes PrestaShop rebuild them
     * lazily on their next request.
     *
     * @return bool Whether an index generator was found at all
     */
    private static function rebuildClassIndex()
    {
        $generated = self::generateClassIndex();

        $indexes = glob(_PS_ROOT_DIR_ . '/var/cache/*/class_index.php');
        foreach ((array) $indexes as $index) {
            if (is_file($index) && is_writable($index)) {
                @unlink($index);
            }
        }

        // ...and put the current environment's back, so this request keeps a
        // working autoloader.
        return self::generateClassIndex() && $generated;
    }

    /**
     * Regenerate the current environment's class index the way the running
     * PrestaShop does: 8.1 moved it onto the `prestashop/autoload` package and 9
     * deleted `Tools::generateIndex()`, so either spelling alone fatals on one
     * major. Core's own `Module::addOverride()` switched with it.
     *
     * @return bool Whether an index generator was found at all
     */
    private static function generateClassIndex()
    {
        $autoload = 'PrestaShop\Autoload\PrestashopAutoload';

        if (class_exists($autoload)) {
            $autoload::getInstance()->generateIndex();

            return true;
        }

        if (method_exists('Tools', 'generateIndex')) {
            Tools::generateIndex();

            return true;
        }

        return false;
    }
}
