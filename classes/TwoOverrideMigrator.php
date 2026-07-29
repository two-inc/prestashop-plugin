<?php
/**
 * Refreshes the SHOP-LEVEL override tree so it matches the version of the
 * module that is actually installed (TWO-25265).
 *
 * WHY THIS HAS TO EXIST AT ALL
 *
 * A module's `override/` directory is not deployed content. PrestaShop copies
 * it, once, into the shop's own `override/` tree - `_PS_OVERRIDE_DIR_` - and
 * from then on the shop's copy is the file that runs. That copy is written
 * exclusively by `Module::installOverrides()`, which runs at install and at
 * reset and nowhere else. It is NOT rewritten by an upgrade, and it is NOT
 * rewritten by a deploy that replaces the module directory.
 *
 * Worse, `Module::addOverride()` cannot rewrite it even when it does run. Read
 * its body in PrestaShop core: when a shop-level override for the class already
 * exists, it reflects over both copies and, for every method the shop copy
 * already declares, THROWS - "The method %s in the class %s is already
 * overridden by the module %s version %s". It only ever splices in methods that
 * are missing. There is no path in core that replaces a method body and no path
 * that removes one. So:
 *
 *   - CHANGE an override's behaviour in a release and every existing shop keeps
 *     running the old behaviour, forever, silently;
 *   - RETIRE an override in a release and every existing shop keeps running it,
 *     forever, silently. A module reset does not help - it re-runs
 *     `installOverrides()`, which adds and never strips.
 *
 * Both were observed in production-shaped staging on 2026-07-29: a shop
 * carrying the 2.4.0 `CustomerAddressFormatter` override kept injecting the
 * department and project fields into the address form long after 2.7.0 moved
 * them into the payment tile. Module version reported 2.7.0, files on disk were
 * 2.7.0, git-sync healthy, deploy Synced. The shop-level override was the only
 * stale thing, and nothing in the module or in core was ever going to fix it.
 *
 * THE CONVENTION THIS ESTABLISHES
 *
 * Changing or retiring an override is a MIGRATION, not an edit. The version
 * that does it calls `TwoOverrideMigrator::refresh()` from its
 * `upgrade/upgrade-<version>.php`, and `.github/scripts/check-override-migration.sh`
 * fails the pull request if it does not.
 *
 * WHAT `refresh()` DOES, AND THE THREE THINGS IT REFUSES TO DO
 *
 * For each candidate shop-level override file it deletes the file when - and
 * only when - the file is stale AND exclusively ours, then regenerates the
 * class index and re-runs `installOverrides()` so the current version's copy is
 * written fresh. A retired override is simply never re-written, because the
 * module no longer ships one; that is the same code path, not a second one.
 *
 * It refuses to touch:
 *
 *   1. A file carrying ANY other module's `module:` stamp. PrestaShop's
 *      `override/` tree is a SHARED merge target - several modules splice
 *      methods into one file. Deleting a co-owned file would silently
 *      uninstall another module's override, which is a far worse failure than
 *      the one being fixed. Co-owned files are logged and left alone; a human
 *      has to unpick them.
 *   2. A file carrying no `module:` stamp at all. That is either PrestaShop's
 *      own or a merchant's hand-written override. Not ours, not our business.
 *   3. A file whose every stamp already reads the current module version.
 *      Nothing to do, so nothing is done - which is what makes a second run of
 *      the same upgrade a genuine no-op rather than a delete-and-rewrite churn.
 *
 * The stamp is PrestaShop's own, written by `addOverride()` immediately above
 * each spliced member:
 *
 *     \/*
 *      * module: twopayment
 *      * date: 2026-07-08 09:12:44
 *      * version: 2.4.0
 *      *\/
 *
 * so ownership and staleness are read from the artifact core itself produced,
 * not from anything this module has to remember to write.
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

    /**
     * Every `module:` name stamped into an override file, in order of
     * appearance and with duplicates kept.
     *
     * @param string $source
     *
     * @return array<int, string>
     */
    public static function stampedModules($source)
    {
        $matches = array();
        preg_match_all('/^\s*\*\s*module:\s*(\S+)\s*$/m', (string) $source, $matches);

        return isset($matches[1]) ? $matches[1] : array();
    }

    /**
     * Every `version:` stamped into an override file.
     *
     * @param string $source
     *
     * @return array<int, string>
     */
    public static function stampedVersions($source)
    {
        $matches = array();
        preg_match_all('/^\s*\*\s*version:\s*([0-9][0-9.]*)\s*$/m', (string) $source, $matches);

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
     * Idempotent: a second call finds every file CURRENT and does nothing.
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

        foreach (self::candidatePaths($module, $retiredPaths) as $relative) {
            $absolute = _PS_OVERRIDE_DIR_ . $relative;

            if (!is_file($absolute)) {
                // Never installed here, or already cleaned up. Both fine.
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

        if ($removed === 0) {
            return $notes;
        }

        // Order matters. The autoloader resolves a class through
        // var/cache/<env>/class_index.php, so until that is rebuilt the deleted
        // file is still the answer for this class - which means
        // `addOverride()` would take its "a shop override already exists"
        // branch and read a file that is no longer there. Rebuild first, then
        // reinstall.
        self::rebuildClassIndex();
        $module->installOverrides();

        // `addOverride()` only regenerates the index on the branch that COPIES
        // a fresh file. A retired override leaves nothing to copy, so that
        // branch never runs and the index would keep the deleted path.
        self::rebuildClassIndex();

        $notes[] = sprintf(
            'rebuilt the class index and re-ran installOverrides() after deleting %d stale override(s)',
            $removed
        );

        return $notes;
    }

    /**
     * Override paths to consider, relative to the override root: everything the
     * module still ships, plus everything the caller says it retired.
     *
     * @param Module            $module
     * @param array<int,string> $retiredPaths
     *
     * @return array<int, string>
     */
    private static function candidatePaths($module, array $retiredPaths)
    {
        $paths = array();

        foreach ($retiredPaths as $relative) {
            $relative = ltrim((string) $relative, '/');
            if ($relative !== '') {
                $paths[$relative] = true;
            }
        }

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
     * `Tools::generateIndex()` rewrites `class_index.php` for the CURRENT
     * environment's cache directory only. An upgrade driven from the CLI can
     * easily be running in a different environment from the storefront, and
     * then the storefront keeps resolving the class it was told about last -
     * the deleted override. Unlinking the other environments' copies makes
     * PrestaShop rebuild them lazily on their next request.
     *
     * @return void
     */
    private static function rebuildClassIndex()
    {
        Tools::generateIndex();

        $indexes = glob(_PS_ROOT_DIR_ . '/var/cache/*/class_index.php');
        foreach ((array) $indexes as $index) {
            if (is_file($index) && is_writable($index)) {
                @unlink($index);
            }
        }

        // ...and put the current environment's back, so this request keeps a
        // working autoloader.
        Tools::generateIndex();
    }
}
