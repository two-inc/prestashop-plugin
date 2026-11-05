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
 * It also clears a stored checkout title or subtitle that is still exactly
 * the English text earlier versions stored in every language at install
 * (TWO-27107). Nothing is stored at install any more: an empty title shows
 * the default title in the shopper's language, and an empty subtitle shows
 * none. A value the merchant edited, in any language, is left alone. Every
 * shop tier is read from the configuration table, as upgrade 2.7.8 does,
 * and only rows that exist are touched.
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

    $installDefaults = array(
        'PS_TWO_TITLE' => 'Business invoice',
        'PS_TWO_SUB_TITLE' => 'Buy now, pay later - instant credit',
    );
    $languages = Language::getLanguages(false);
    foreach ($installDefaults as $key => $english) {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_shop_group`, `id_shop`, `value` FROM `' . _DB_PREFIX_ . 'configuration`'
            . " WHERE `name` = '" . $key . "'"
        );
        // The global tier always; a group or shop tier only under multistore,
        // where updateValue() can address it.
        $scopes = array('0:0' => array(0, 0));
        if (Shop::isFeatureActive() && is_array($rows)) {
            foreach ($rows as $row) {
                $group = (int) $row['id_shop_group'];
                $shop = (int) $row['id_shop'];
                $scopes[$group . ':' . $shop] = array($group, $shop);
            }
        }
        foreach ($scopes as $scope) {
            list($group, $shop) = $scope;
            foreach ($languages as $language) {
                $idLang = (int) $language['id_lang'];
                if (!Configuration::hasKey($key, $idLang, $group, $shop)
                    || (string) Configuration::get($key, $idLang, $group, $shop) !== $english
                ) {
                    continue;
                }
                if (!Configuration::updateValue($key, array($idLang => ''), false, $group, $shop)) {
                    PrestaShopLogger::addLog(
                        'TwoPayment Upgrade 2.7.22: could not clear the install default of ' . $key
                        . ' (language ' . $idLang . ', group ' . $group . ', shop ' . $shop . ')',
                        2
                    );
                }
            }
        }
    }

    return true;
}
