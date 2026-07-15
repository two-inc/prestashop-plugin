<?php
/**
 * UPGRADE SCRIPT: Version 2.5.1
 *
 * Sole trader checkout (TWO-24755): new PS_TWO_ENABLE_SOLE_TRADER admin
 * toggle. install() sets an explicit default of 0 for fresh installs; this
 * script does the same for shops that already ran install() before this
 * feature existed, so the config key is an explicit stored 0 rather than
 * an unset/false read, matching this module's convention for new config
 * keys (see upgrade-2.3.0.php, upgrade-2.5.0.php). Guarded by hasKey() so
 * it never clobbers a value a later release may have already set.
 *
 * Created: 2026-07-15
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_5_1($module)
{
    if (!Configuration::hasKey('PS_TWO_ENABLE_SOLE_TRADER')) {
        Configuration::updateValue('PS_TWO_ENABLE_SOLE_TRADER', 0);
    }

    return true;
}
