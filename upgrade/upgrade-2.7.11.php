<?php
/**
 * UPGRADE SCRIPT: Version 2.7.11
 *
 * Pushes the edited `CustomerAddressFormatter` override into the shop's own
 * override tree, which PrestaShop writes once at install and never rewrites.
 * Without this a shop keeps rendering the removed `Enter company name to
 * search` placeholder on its address form for good.
 *
 * It cannot fail the upgrade: every filesystem operation inside the migrator is
 * guarded and this function returns true unconditionally, because a shop that
 * cannot be tidied must still finish upgrading.
 *
 * @param Twopayment $module
 * @return bool
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_11($module)
{
    try {
        require_once rtrim($module->getLocalPath(), '/') . '/classes/TwoOverrideMigrator.php';

        $notes = TwoOverrideMigrator::refresh($module);
    } catch (Throwable $e) {
        // Deliberately broad, same reasoning as the 2.7.1-2.7.10 scripts: this is
        // housekeeping on top of an upgrade that has already succeeded, and
        // anything thrown here leaves the module version un-bumped and the shop in
        // a state no later script can reason about.
        PrestaShopLogger::addLog(
            'Two Payment v2.7.11 upgrade: shop-level override refresh raised "' . $e->getMessage()
            . '" and was skipped, so CustomerAddressFormatter may still carry the removed placeholder',
            2,
            null,
            'Module',
            $module->id
        );

        return true;
    }

    // At severity 1 a shop that stayed stale reads as a successful refresh.
    $failed = preg_grep('/' . preg_quote(TwoOverrideMigrator::INSTALL_FAILED_NOTE, '/') . '/', $notes);

    PrestaShopLogger::addLog(
        'Two Payment v2.7.11 upgrade: shop-level override refresh (CustomerAddressFormatter) - '
        . (empty($notes) ? 'no override files present, nothing to do' : implode('; ', $notes)),
        empty($failed) ? 1 : 2,
        null,
        'Module',
        $module->id
    );

    return true;
}
