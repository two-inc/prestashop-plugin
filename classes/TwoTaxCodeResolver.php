<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

/**
 * The tax code a 0% line carries (TWO-24877, TWO-26151). Two requires one on every 0% line of a Spanish merchant's order.
 *
 * 1. A code the merchant mapped to the line's tax rules group wins, for any merchant country.
 * 2. Otherwise, for a Spanish merchant only, a code derived from the order: goods follow the delivery address,
 *    services follow the country of the buyer company. The Canaries, Ceuta and Melilla are outside the EU VAT
 *    area, told by the delivery postcode for goods and by the invoice postcode for a Spanish buyer of services.
 *    Both intra-community codes also need a buyer VAT number whose prefix names an EU member state other than the
 *    merchant's country (TWO-26153); without one the line gets no code and Two's API refuses it.
 * 3. Otherwise no code. The plugin never refuses and never coerces a rate: Two's API validates what is sent.
 *
 * Lines at any other rate never carry a code, so their payloads are unchanged.
 */
class TwoTaxCodeResolver
{
    /** The 27 member states, plus Monaco, which is inside the EU VAT area as part of France. */
    const EU_VAT_AREA = array(
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'IE', 'IT', 'LV',
        'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'HU', 'MC',
    );

    /** Spanish postcode prefixes outside the EU VAT area: Canarias (35, 38), Ceuta (51), Melilla (52). */
    const ES_OUTSIDE_VAT_AREA_POSTCODES = array('35', '38', '51', '52');

    /**
     * Derivation, first matching row wins. Each row names the facts that must all hold.
     * Anything unmatched (a mainland or Balearic destination, an EU destination with a Spanish buyer,
     * a service to a mainland or Balearic Spanish buyer, an EU buyer with no qualifying VAT number) derives nothing.
     */
    const DERIVATION = array(
        'goods' => array(
            array(array('dest_outside_eu'), 'ES_IVA_EXPORT'),
            array(array('dest_es_outside_vat_area'), 'ES_IVA_EXPORT'),
            array(array('dest_other_eu', 'buyer_other_eu', 'vat_other_eu'), 'ES_IVA_INTRA_COMMUNITY_GOODS'),
        ),
        'services' => array(
            array(array('buyer_other_eu', 'vat_other_eu'), 'ES_IVA_INTRA_COMMUNITY_SERVICES'),
            array(array('buyer_outside_eu'), 'ES_IVA_NON_EU_SERVICES'),
            array(array('buyer_es_outside_vat_area'), 'ES_IVA_NON_EU_SERVICES'),
        ),
    );

    /**
     * @param string|float $rate the line's tax_rate as sent
     * @param string|null $mapped the code the merchant mapped to the line's tax rules group
     * @param bool $goods a goods line (see the class doc of the caller); false for a service line
     * @param array $order ['merchant_country', 'dest_country', 'dest_postcode', 'buyer_country', 'buyer_postcode',
     *                     'buyer_vat_number' (normalised, see normaliseVatNumber())]
     * @return string|null
     */
    public static function resolve($rate, $mapped, $goods, array $order)
    {
        if (!self::isZeroRate($rate)) {
            return null;
        }
        if (is_string($mapped) && $mapped !== '') {
            return $mapped;
        }
        if (self::iso(isset($order['merchant_country']) ? $order['merchant_country'] : '') !== 'ES') {
            return null;
        }

        return self::derive($goods, $order);
    }

    /**
     * @param bool $goods
     * @param array $order
     * @return string|null
     */
    public static function derive($goods, array $order)
    {
        $facts = self::facts($order);
        foreach (self::DERIVATION[$goods ? 'goods' : 'services'] as $row) {
            list($needs, $code) = $row;
            $holds = true;
            foreach ($needs as $fact) {
                $holds = $holds && $facts[$fact];
            }
            if ($holds) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param string|float $rate
     * @return bool
     */
    public static function isZeroRate($rate)
    {
        return is_numeric($rate) && (float) $rate == 0.0;
    }

    /**
     * @param array $order
     * @return array<string,bool>
     */
    private static function facts(array $order)
    {
        $dest = self::iso(isset($order['dest_country']) ? $order['dest_country'] : '');
        $buyer = self::iso(isset($order['buyer_country']) ? $order['buyer_country'] : '');
        $postcode = trim((string) (isset($order['dest_postcode']) ? $order['dest_postcode'] : ''));
        $buyerPostcode = trim((string) (isset($order['buyer_postcode']) ? $order['buyer_postcode'] : ''));
        $destInEu = in_array($dest, self::EU_VAT_AREA, true);
        $merchant = self::iso(isset($order['merchant_country']) ? $order['merchant_country'] : '');
        $vatCountry = self::vatCountry(isset($order['buyer_vat_number']) ? $order['buyer_vat_number'] : '');

        return array(
            // An unknown destination is no evidence the goods left the EU.
            'dest_outside_eu' => $dest !== '' && !$destInEu,
            'dest_es_outside_vat_area' => $dest === 'ES' && in_array(substr($postcode, 0, 2), self::ES_OUTSIDE_VAT_AREA_POSTCODES, true),
            'dest_other_eu' => $destInEu && $dest !== 'ES',
            'buyer_other_eu' => in_array($buyer, self::EU_VAT_AREA, true) && $buyer !== 'ES',
            'buyer_outside_eu' => $buyer !== '' && !in_array($buyer, self::EU_VAT_AREA, true),
            'buyer_es_outside_vat_area' => $buyer === 'ES' && in_array(substr($buyerPostcode, 0, 2), self::ES_OUTSIDE_VAT_AREA_POSTCODES, true),
            // The buyer's VAT number names an EU member state other than the merchant's country. MC is in the EU VAT
            // area as a destination but is no VAT prefix.
            'vat_other_eu' => in_array($vatCountry, self::EU_VAT_AREA, true) && $vatCountry !== 'MC' && $vatCountry !== $merchant,
        );
    }

    /**
     * A buyer VAT number as the resolver and Two read it (TWO-26153): spaces, dots and hyphens stripped, upper-cased,
     * and the address country prepended when it does not start with two letters (Greece as EL, Monaco as FR).
     * Without an address country an unprefixed number stays unprefixed, and so names no country.
     *
     * @param mixed $raw
     * @param mixed $addressCountry alpha-2
     * @return string '' for no number
     */
    public static function normaliseVatNumber($raw, $addressCountry)
    {
        $vat = strtoupper(str_replace(array(' ', '.', '-'), '', (string) $raw));
        if ($vat === '' || preg_match('/^[A-Z]{2}/', $vat) === 1) {
            return $vat;
        }
        $country = self::iso($addressCountry);
        // VAT prefixes: Greece is EL, and Monaco businesses hold French numbers.
        $prefixes = array('GR' => 'EL', 'MC' => 'FR');

        return (isset($prefixes[$country]) ? $prefixes[$country] : $country) . $vat;
    }

    /**
     * The country a normalised VAT number's prefix names, EL read as GR; '' when it starts with no two letters.
     *
     * @param mixed $vat
     * @return string
     */
    private static function vatCountry($vat)
    {
        $prefix = substr((string) $vat, 0, 2);
        if (preg_match('/^[A-Z]{2}$/', $prefix) !== 1) {
            return '';
        }

        return $prefix === 'EL' ? 'GR' : $prefix;
    }

    /**
     * @param mixed $country
     * @return string
     */
    private static function iso($country)
    {
        return strtoupper(trim((string) $country));
    }
}
