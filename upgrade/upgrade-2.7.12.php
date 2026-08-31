<?php
/**
 * UPGRADE SCRIPT: Version 2.7.12
 *
 * Carries the skip-confirm-token debug toggle onto its renamed configuration
 * key, `PS_TWO_SKIP_CONFIRM_TOKEN_CHECK` (TWO-25386 #4). Naming only - the
 * toggle gates the same CSRF-style token check on the order-intent controller
 * as before, and the default is still OFF.
 *
 * WHAT IT DOES
 *
 * Reads the old key, writes its value to the new key if there was one AND the
 * new key does not already hold one, then deletes the old key. A shop that
 * never enabled the toggle has no old row, keeps no new row, and the reader
 * (`isTwoSkipConfirmTokenCheckEnabled()`) answers false either way - so a
 * merchant who never touched it and one migrated from the old key both land
 * with the check enforced.
 *
 * The delete is last and conditional on the copy: `Configuration::updateValue()`
 * returns an accumulated Db result and can answer falsy without throwing, and
 * on that path the old row is the only surviving record of the merchant's
 * choice. Everything else about the shape of this script - why the new key wins
 * when it already holds a value, why a new version rather than an edit to an
 * existing script, why it returns true on every path instead of becoming
 * re-runnable, and why it carries the global tier only - is the same as
 * upgrade-2.7.6.php, whose header sets out each of those decisions in full.
 *
 * A shop that skipped the copy stays on the default, which is the SECURE
 * position (the token check enforced), so the failure mode here is a debug
 * escape hatch a developer has to re-enable, not an exposed shop.
 *
 * Created: 2026-08-31
 *
 * @param Twopayment $module
 * @return bool
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_12($module)
{
    // Every fact the log message is built from lives out here, so the throw path
    // reports the same state the success path does.
    $old = false;
    $new = false;
    $oldRead = false;
    $newRead = false;
    $oldUsable = false;
    $newAlreadySet = false;
    $copyAttempted = false;
    $carried = false;
    $deleteAttempted = false;
    $deleted = false;
    $threw = null;

    // The one literal use of the retired name in this module: the row it has to
    // read and remove.
    $oldKey = 'PS_TWO_SKIP_CONFIRM_NONCE_CHECK';

    try {
        $old = Configuration::get($oldKey);
        $oldRead = true;
        // '' counts as absent: the reader casts to int, so an empty row means
        // the same thing as no row and carrying it would carry nothing.
        $oldUsable = ($old !== false && $old !== null && $old !== '');

        $new = Configuration::get('PS_TWO_SKIP_CONFIRM_TOKEN_CHECK');
        $newRead = true;
        $newAlreadySet = ($new !== false && $new !== null && $new !== '');

        if ($oldUsable && !$newAlreadySet) {
            $copyAttempted = true;
            $carried = (bool) Configuration::updateValue('PS_TWO_SKIP_CONFIRM_TOKEN_CHECK', $old);
        }

        // Kept only when a copy was attempted and did not land - there the old
        // row holds the sole copy of the value.
        if (!($copyAttempted && !$carried)) {
            $deleteAttempted = true;
            $deleted = (bool) Configuration::deleteByName($oldKey);
        }
    } catch (Throwable $e) {
        // Deliberately broad, same reasoning as upgrade-2.7.6.php: the message
        // is built from the recorded state below, not from where the throw came
        // from.
        $threw = $e->getMessage();
    }

    // A throw from updateValue() leaves the same state a falsy updateValue()
    // does, so both paths pass through here.
    $keptOldRow = ($copyAttempted && !$carried);

    $severity = 1;

    if (!$oldRead) {
        $outcome = 'nothing was migrated - the old key could not be read';
    } elseif (!$newRead) {
        $outcome = 'nothing was copied - the new key could not be read';
    } elseif ($carried) {
        $outcome = 'carried the stored value "' . $old . '" across (global tier only, see'
            . ' upgrade-2.7.6.php)';
    } elseif ($copyAttempted) {
        $outcome = 'copying the stored value "' . $old . '" to the new key FAILED';
    } elseif ($newAlreadySet) {
        $outcome = 'the new key already held "' . $new . '", so the copy was skipped and that value kept'
            . ($oldUsable
                ? ' in preference to the old key\'s "' . $old . '"'
                : ' (the old key held no usable value either)');
    } else {
        $outcome = 'no usable value on the old key, the token check stays enforced';
    }

    if ($deleted) {
        $outcome .= '; the old key ' . $oldKey . ' was removed';
    } elseif ($keptOldRow) {
        $outcome .= '; ' . $oldKey . ' was deliberately KEPT because it now holds the only'
            . ' copy of the value, and this upgrade will NOT run again - the shop is on the secure'
            . ' default, so re-set the toggle on the Two Payment Diagnostics tab if it is still wanted';
        $severity = 3;
    } elseif ($deleteAttempted) {
        $outcome .= '; deleting the old key FAILED, so ' . $oldKey . ' may still be present'
            . ' in ps_configuration - harmless, nothing reads it, but this upgrade will not run again to'
            . ' remove it';
        $severity = max($severity, 2);
    } else {
        $outcome .= '; the delete never ran, so ' . $oldKey . ' may still be present in'
            . ' ps_configuration - harmless, nothing reads it, but this upgrade will not run again to'
            . ' remove it';
        $severity = max($severity, 2);
    }

    if ($threw !== null) {
        PrestaShopLogger::addLog(
            'Two Payment v2.7.12 upgrade: ' . $oldKey . ' -> PS_TWO_SKIP_CONFIRM_TOKEN_CHECK'
            . ' raised "' . $threw . '" - ' . $outcome . ' (TWO-25386 #4)',
            max($severity, 2),
            null,
            'Module',
            $module->id
        );

        return true;
    }

    PrestaShopLogger::addLog(
        'Two Payment v2.7.12 upgrade: ' . $oldKey . ' -> PS_TWO_SKIP_CONFIRM_TOKEN_CHECK - '
        . $outcome . ' (TWO-25386 #4)',
        $severity,
        null,
        'Module',
        $module->id
    );

    return true;
}
