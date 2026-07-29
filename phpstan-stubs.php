<?php

/**
 * Analysis-only stubs for install-generated PrestaShop constants.
 *
 * This file is never loaded at runtime. Real PrestaShop core classes are
 * resolved by scanning a pinned core checkout (see the phpstan job in
 * .github/workflows/tests.yml and scanDirectories in phpstan.neon), so no
 * class stubs live here — only the constants PrestaShop generates at
 * install time (app/config/parameters.php etc.), which therefore exist in
 * no source tree phpstan can scan.
 */

declare(strict_types=1);

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}

// Defined in config/defines.inc.php at runtime, but conditionally and from
// a computed value, which phpstan's symbol scanner does not register.
if (!defined('_PS_CACHE_DIR_')) {
    define('_PS_CACHE_DIR_', '/tmp/ps-cache/');
}

if (!defined('_PS_ROOT_DIR_')) {
    define('_PS_ROOT_DIR_', '/var/www/html');
}

// `_PS_ROOT_DIR_ . '/override/'` at runtime. The shop's own override tree, which
// is a different thing from the module's `override/` template directory — see
// classes/TwoOverrideMigrator.php.
if (!defined('_PS_OVERRIDE_DIR_')) {
    define('_PS_OVERRIDE_DIR_', '/var/www/html/override/');
}
