<?php
/**
 * UPGRADE SCRIPT: Version 2.7.22
 *
 * Removes the retired skip-confirm-token switch's configuration key,
 * `PS_TWO_SKIP_CONFIRM_TOKEN_CHECK` (TWO-27107). The order-intent token check
 * now always runs and nothing reads the key, so this only clears the row.
 * `Configuration::deleteByName()` removes it on every shop tier.
 *
 * Returns true whatever the delete answers: a row left behind is read by
 * nothing, so it cannot weaken the check, and failing the upgrade over it
 * would only stop the module upgrading.
 *
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_7_22($object)
{
    Configuration::deleteByName('PS_TWO_SKIP_CONFIRM_TOKEN_CHECK');

    return true;
}
