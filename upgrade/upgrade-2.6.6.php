<?php
/**
 * UPGRADE SCRIPT: Version 2.6.6
 *
 * PS_TWO_ADDRESS_LOOKUP is a new merchant toggle (TWO-25203, umbrella
 * TWO-24739) for the company address lookup on the checkout address step -
 * whether picking a company overwrites the address fields and the
 * DNI / VAT number fields. The behaviour is unchanged; it was previously
 * ungated, with no way for a merchant to turn it off.
 *
 * Existing installs are therefore behaving as "on". Seed the key to 1 so no
 * shop's effective behaviour flips on upgrade. Only when the key is absent -
 * a merchant who has already saved a deliberate 0 must keep it, and this
 * script may run again on a shop that has one.
 *
 * Created: 2026-07-28
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_6_6($module)
{
    if (!Configuration::hasKey('PS_TWO_ADDRESS_LOOKUP')) {
        Configuration::updateValue('PS_TWO_ADDRESS_LOOKUP', 1);

        PrestaShopLogger::addLog(
            'Two Payment v2.6.6 upgrade: seeded PS_TWO_ADDRESS_LOOKUP to 1 - the company address lookup was previously ungated, so enabled preserves this shop\'s existing behaviour (TWO-25203)',
            1,
            null,
            'Module',
            $module->id
        );
    }

    return true;
}
