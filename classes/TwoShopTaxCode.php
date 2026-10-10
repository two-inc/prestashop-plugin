<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

/**
 * The tax code a 0% line takes from the merchant's mapping of the shop's own tax setup (TWO-26153). The merchant
 * defines the receivable; the module only reads which of the merchant's rows a line falls on.
 *
 * Per tax rules group the mapping holds three kinds of row, stored as one JSON object:
 *   "<id_tax_rules_group>|exempt"  a buyer in another EU country with a VAT number (step 1)
 *   "rule:<id_tax_rule>"           one of the group's 0% tax rules, the one core picks for the tax address (step 2)
 *   "<id_tax_rules_group>|none"    no tax rule of the group matches the tax address (step 3)
 *
 * A line without a tax rules group (a discount, shipping with no one group, wrapping under PS_ATCP_SHIPWRAP) takes
 * the one code the order's coded 0% lines share, and none when they disagree or there are none (step 4).
 *
 * A matched row left on (none) gives no code: it never falls through to a later step.
 */
class TwoShopTaxCode
{
    /** The 27 member states plus Monaco. Northern Ireland (GB with a BT postcode) is added in inEuVatArea(). */
    const EU_VAT_AREA = array(
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'IE', 'IT', 'LV',
        'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'HU', 'MC',
    );

    /**
     * Whether an address is in the EU VAT area: the 27 member states, Monaco, and Northern Ireland, which shops hold
     * as GB with a postcode starting BT.
     *
     * @param string $country alpha-2
     * @param string $postcode
     * @return bool
     */
    public static function inEuVatArea($country, $postcode)
    {
        $country = strtoupper(trim((string) $country));
        if ($country === 'GB') {
            return strpos(strtoupper(trim((string) $postcode)), 'BT') === 0;
        }

        return in_array($country, self::EU_VAT_AREA, true);
    }

    /**
     * Step 1: the billing address and the tax address are both in the EU VAT area and neither is in the merchant's
     * country, and the VAT number field is not empty after trim(). A tax address outside the area is an export and
     * never exempt here. An unknown merchant country tells no "other" country, so nothing is exempt.
     *
     * @param array $billing ['country' => alpha-2, 'postcode' => string]
     * @param array $taxAddress ['country' => alpha-2, 'postcode' => string]
     * @param string $vatNumber as entered
     * @param string $merchantCountry alpha-2, '' when unknown
     * @return bool
     */
    public static function isExemptBuyer(array $billing, array $taxAddress, $vatNumber, $merchantCountry)
    {
        $merchant = strtoupper(trim((string) $merchantCountry));
        if ($merchant === '' || trim((string) $vatNumber) === '') {
            return false;
        }
        foreach (array($billing, $taxAddress) as $address) {
            $country = strtoupper(trim((string) $address['country']));
            if ($country === $merchant || !self::inEuVatArea($country, $address['postcode'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Steps 1 to 3 for a line taxed by a tax rules group.
     *
     * @param array<string,string> $map the stored mapping
     * @param int $group the line's tax rules group, > 0
     * @param bool $exempt isExemptBuyer()
     * @param array|null $rule the tax rule core picks for the tax address, ['id_tax_rule', 'zero' => bool]; null for none
     * @return string|null
     */
    public static function resolve(array $map, $group, $exempt, $rule)
    {
        if ($exempt) {
            return self::row($map, self::exemptKey($group));
        }
        if ($rule === null) {
            return self::row($map, self::noRuleKey($group));
        }
        if (empty($rule['zero'])) {
            // The line is 0% but the rule core picked is not: taxes off shop-wide or another module's tax manager.
            return null;
        }

        return self::row($map, self::ruleKey($rule['id_tax_rule']));
    }

    /**
     * Step 4: the one code the order's coded 0% lines share; null when they carry different codes or none.
     *
     * @param array $codes the codes of the order's 0% lines that steps 1 to 3 coded
     * @return string|null
     */
    public static function shared(array $codes)
    {
        $distinct = array_values(array_unique(array_filter(array_map('strval', $codes), 'strlen')));

        return count($distinct) === 1 ? $distinct[0] : null;
    }

    /**
     * Whether the merchant mapped any row of a tax rules group.
     *
     * @param array<string,string> $map
     * @param int $group
     * @param int[] $ruleIds the group's tax rule ids
     * @return bool
     */
    public static function isGroupMapped(array $map, $group, array $ruleIds)
    {
        if (isset($map[self::exemptKey($group)]) || isset($map[self::noRuleKey($group)])) {
            return true;
        }
        foreach ($ruleIds as $id) {
            if (isset($map[self::ruleKey($id)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one-time migration of a mapping by tax rules group (`{"<group>": "<code>"}`) to the rows: each group's code
     * goes to its exempt row, its no-rule row and every 0% rule it has now. A row already held keeps its code.
     *
     * @param array $legacy group id => code
     * @param array<int,int[]> $zeroRules group id => its 0% tax rule ids
     * @param array<string,string> $current the rows already stored
     * @return array<string,string>
     */
    public static function fanOut(array $legacy, array $zeroRules, array $current = array())
    {
        $map = $current;
        foreach ($legacy as $group => $code) {
            $keys = array(self::exemptKey($group), self::noRuleKey($group));
            foreach (isset($zeroRules[(int) $group]) ? $zeroRules[(int) $group] : array() as $id) {
                $keys[] = self::ruleKey($id);
            }
            foreach ($keys as $key) {
                if (!isset($map[$key])) {
                    $map[$key] = (string) $code;
                }
            }
        }
        ksort($map);

        return $map;
    }

    /**
     * @param mixed $raw the stored JSON
     * @return array<string,string>|null null when it is not a map of rows to codes
     */
    public static function parse($raw)
    {
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $map = array();
        foreach ($decoded as $key => $code) {
            if (!self::isKey((string) $key) || !self::isCode($code)) {
                return null;
            }
            $map[(string) $key] = $code;
        }

        return $map;
    }

    /**
     * A mapping stored before the rows (TWO-24877): tax rules group id => code. Null when the value is not one.
     *
     * @param mixed $raw
     * @return array<int,string>|null
     */
    public static function parseLegacy($raw)
    {
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || $decoded === array()) {
            return null;
        }
        $map = array();
        foreach ($decoded as $group => $code) {
            if (!ctype_digit((string) $group) || (int) $group <= 0 || !self::isCode($code)) {
                return null;
            }
            $map[(int) $group] = $code;
        }

        return $map;
    }

    /**
     * @param int $group
     * @return string
     */
    public static function exemptKey($group)
    {
        return (int) $group . '|exempt';
    }

    /**
     * @param int $group
     * @return string
     */
    public static function noRuleKey($group)
    {
        return (int) $group . '|none';
    }

    /**
     * @param int $idTaxRule
     * @return string
     */
    public static function ruleKey($idTaxRule)
    {
        return 'rule:' . (int) $idTaxRule;
    }

    /**
     * @param string $key
     * @return bool
     */
    public static function isKey($key)
    {
        return preg_match('/^(?:[1-9]\d*\|(?:exempt|none)|rule:[1-9]\d*)$/', (string) $key) === 1;
    }

    /**
     * @param mixed $code
     * @return bool
     */
    private static function isCode($code)
    {
        return is_string($code) && preg_match('/^[A-Z0-9_]+$/', $code) === 1;
    }

    /**
     * @param array<string,string> $map
     * @param string $key
     * @return string|null
     */
    private static function row(array $map, $key)
    {
        return isset($map[$key]) && $map[$key] !== '' ? $map[$key] : null;
    }
}
