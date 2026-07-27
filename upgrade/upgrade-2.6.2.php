<?php
/**
 * UPGRADE SCRIPT: Version 2.6.2
 *
 * Fulfilled-status mapping storage normalisation (TWO-24769).
 *
 * PS_TWO_OS_FULFILLED_MAP is meant to hold a JSON array of order-status
 * IDs. upgrade-2.6.2 exists because a runtime recovery path
 * (ensureCustomStatesExist(), which fires when Two's custom order states
 * are missing) wrote a bare status ID instead, so a store that lost its
 * custom states after the 2.1.2 migration could be sitting on the legacy
 * single-ID format again. The reader still tolerates that format, but
 * leaving it in the database keeps a trap open for any future refactor
 * that drops the compatibility branch.
 *
 * This rewrites whatever is stored into the canonical JSON int-array form.
 * The selection itself is preserved; a value that yields no usable IDs
 * falls back to the shop's Shipped status, which is the module default.
 *
 * Created: 2026-07-27
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_6_2($module)
{
    $stored = Configuration::get('PS_TWO_OS_FULFILLED_MAP');

    $ids = $stored ? json_decode($stored, true) : null;
    if (!is_array($ids)) {
        $ids = $stored ? array($stored) : array();
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (empty($ids)) {
        $ids = array((int) Configuration::get('PS_OS_SHIPPING'));
    }

    $normalised = json_encode($ids);
    if ($normalised !== $stored) {
        Configuration::updateValue('PS_TWO_OS_FULFILLED_MAP', $normalised);

        PrestaShopLogger::addLog(
            'Two Payment v2.6.2 upgrade: normalised PS_TWO_OS_FULFILLED_MAP to JSON array format (' . $normalised . ')',
            1,
            null,
            'Module',
            $module->id
        );
    }

    return true;
}
