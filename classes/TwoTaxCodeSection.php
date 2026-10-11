<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

/**
 * What the "Tax codes for 0% lines" section of the Order management form shows (TWO-26153). Admin only: nothing at
 * order time reads it. Twopayment::getTwoTaxCodeSectionState() gathers the facts; this decides, first match wins:
 *
 *   not a single shop             select_shop   the title and a prompt to pick a shop
 *   no API key                    hidden
 *   no merchant profile           profile_error why it could not be read (rejected when Two refused the key)
 *   no answer from the code list  list_error    why it could not be read
 *   codes mandatory at 0%         rows          the mapping table
 *   otherwise                     notice        the saved map's standing, else hidden
 *
 * On top of every view but select_shop and hidden: an unreadable saved map (N3, with Clear), and checkout holding
 * other merchant details than the profile (W1).
 */
class TwoTaxCodeSection
{
    const VIEW_SELECT_SHOP = 'select_shop';
    const VIEW_HIDDEN = 'hidden';
    const VIEW_PROFILE_ERROR = 'profile_error';
    const VIEW_LIST_ERROR = 'list_error';
    const VIEW_ROWS = 'rows';
    const VIEW_NOTICE = 'notice';

    /** The saved map cannot be read, so checkout withholds Two. */
    const NOTICE_UNREADABLE = 'N3';
    /** Checkout holds another merchant id or country than the profile. */
    const NOTICE_CHECKOUT_STALE = 'W1';
    /** Every saved code is listed at 0% for the country, which does not require them. */
    const NOTICE_SAVED_VALID = 'N1';
    /** A saved code is unlisted, or not at 0%, for the country. */
    const NOTICE_SAVED_INVALID = 'N2';

    /**
     * @param array $facts
     *   single_shop      bool
     *   has_key          bool
     *   readable         bool, Twopayment::isTwoTaxCodeMapReadable()
     *   entries          array<string,string>, the saved map ([] when unreadable or none)
     *   profile          array{merchant_id:string,country:string}|null
     *   profile_failure  array{stage:string,category:string,http:int}|null, why there is no profile
     *   list             array{codes:array,mandatory:bool}|null, null when there is no answer
     *   list_http        int, the list fetch's HTTP status when there is no answer (0 when none)
     *   checkout_id      string, PS_TWO_MERCHANT_ID
     *   checkout_country string, Twopayment::getTwoMerchantCountry()
     * @return array{view:string,notices:string[],clear:bool,country:string,codes:array,rejected:bool,http:int}
     */
    public static function state(array $facts)
    {
        $state = array('view' => self::VIEW_HIDDEN, 'notices' => array(), 'clear' => false, 'country' => '', 'codes' => array(), 'rejected' => false, 'http' => 0);
        if (empty($facts['single_shop'])) {
            $state['view'] = self::VIEW_SELECT_SHOP;

            return $state;
        }
        if (empty($facts['has_key'])) {
            return $state;
        }
        if (empty($facts['readable'])) {
            $state['notices'][] = self::NOTICE_UNREADABLE;
            $state['clear'] = true;
        }
        $profile = $facts['profile'];
        if ($profile === null) {
            $failure = (array) $facts['profile_failure'];
            $state['view'] = self::VIEW_PROFILE_ERROR;
            $state['http'] = isset($failure['http']) ? (int) $failure['http'] : 0;
            $state['rejected'] = in_array($state['http'], array(401, 403), true);

            return $state;
        }
        $state['country'] = $profile['country'];
        if ($profile['merchant_id'] !== (string) $facts['checkout_id'] || $profile['country'] !== (string) $facts['checkout_country']) {
            $state['notices'][] = self::NOTICE_CHECKOUT_STALE;
        }
        if ($facts['list'] === null) {
            $state['view'] = self::VIEW_LIST_ERROR;
            $state['http'] = (int) $facts['list_http'];

            return $state;
        }
        $state['codes'] = $facts['list']['codes'];
        if (!empty($facts['list']['mandatory'])) {
            $state['view'] = self::VIEW_ROWS;

            return $state;
        }
        if (!empty($facts['readable']) && $facts['entries'] !== array()) {
            $state['notices'][] = self::allZeroAndListed($facts['entries'], $state['codes']) ? self::NOTICE_SAVED_VALID : self::NOTICE_SAVED_INVALID;
            $state['clear'] = true;
        }
        $state['view'] = $state['notices'] === array() ? self::VIEW_HIDDEN : self::VIEW_NOTICE;

        return $state;
    }

    /**
     * Whether the metadata of GET /v1/tax_codes/<country> makes a code mandatory on a 0% line.
     *
     * @param mixed $metadata
     * @return bool
     */
    public static function isMandatoryAtZero($metadata)
    {
        if (!is_array($metadata) || empty($metadata['mandatory_tax_codes_enabled']) || !isset($metadata['mandatory_tax_rates']) || !is_array($metadata['mandatory_tax_rates'])) {
            return false;
        }
        foreach ($metadata['mandatory_tax_rates'] as $rate) {
            if (is_numeric($rate) && (float) $rate == 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The codes a 0% line may carry: the listed codes at rate 0. Two refuses any other code on a 0% line.
     *
     * @param array $codes Twopayment::getTwoTaxCodeOptions() entries
     * @return array
     */
    public static function zeroRated(array $codes)
    {
        return array_values(array_filter($codes, static function ($code) {
            return isset($code['rate']) && is_numeric($code['rate']) && (float) $code['rate'] == 0.0;
        }));
    }

    /**
     * @param array<string,string> $entries
     * @param array $codes
     * @return bool whether every saved code is listed at rate 0
     */
    private static function allZeroAndListed(array $entries, array $codes)
    {
        $zero = array_column(self::zeroRated($codes), 'code');
        foreach ($entries as $code) {
            if (!in_array($code, $zero, true)) {
                return false;
            }
        }

        return true;
    }
}
