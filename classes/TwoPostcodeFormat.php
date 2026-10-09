<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

/**
 * Fits a postcode to a country's PrestaShop `zip_code_format` (TWO-26257).
 *
 * Company details carry the registry's postcode, which can lack the separator
 * core's format requires ("1234AB" where NL is "NNNN LL"), and core's
 * Country::checkZipCode() then refuses the address. In a format, N is a digit,
 * L is a letter and C is the country's iso code, matched case-insensitively as
 * core does; any other character is literal.
 */
class TwoPostcodeFormat
{
    /**
     * Spaces and hyphens are dropped and the format's literals re-inserted
     * when what remains fills the format's slots exactly. Anything else, and
     * any country with no format, is returned unchanged. Letter case is kept:
     * core matches case-insensitively.
     *
     * @param string $postcode
     * @param string $zipFormat the country's zip_code_format, '' when none
     * @param string $isoCode the country's iso code, what C stands for
     *
     * @return string
     */
    public static function format($postcode, $zipFormat, $isoCode)
    {
        $postcode = (string) $postcode;
        $zipFormat = (string) $zipFormat;
        if ($zipFormat === '' || $postcode === '') {
            return $postcode;
        }

        $chars = str_replace(array(' ', '-'), '', $postcode);
        $iso = (string) $isoCode;
        $out = '';
        $pos = 0;
        $len = strlen($chars);
        for ($i = 0, $n = strlen($zipFormat); $i < $n; ++$i) {
            $slot = $zipFormat[$i];
            if ($slot === 'N' || $slot === 'L') {
                $c = $pos < $len ? $chars[$pos] : '';
                if ($c === '' || !preg_match($slot === 'N' ? '/^[0-9]$/' : '/^[a-zA-Z]$/', $c)) {
                    return $postcode;
                }
                $out .= $c;
                ++$pos;
            } elseif ($slot === 'C') {
                $part = (string) substr($chars, $pos, strlen($iso));
                if ($iso === '' || strcasecmp($part, $iso) !== 0) {
                    return $postcode;
                }
                $out .= $part;
                $pos += strlen($iso);
            } elseif ($slot === ' ' || $slot === '-') {
                $out .= $slot;
            } else {
                // Any other literal (core has "980NN") must already be there.
                $c = $pos < $len ? $chars[$pos] : '';
                if (strcasecmp($c, $slot) !== 0) {
                    return $postcode;
                }
                $out .= $c;
                ++$pos;
            }
        }

        return $pos === $len ? $out : $postcode;
    }

    /**
     * Applies format() to every addresses[].postal_code of a company-details
     * body, each against its own country, falling back to the company's.
     *
     * @param array $body the company-details response
     * @param callable $zipFormatForIso fn(string $iso): string, '' when none
     *
     * @return array
     */
    public static function formatCompanyAddresses(array $body, callable $zipFormatForIso)
    {
        if (!isset($body['addresses']) || !is_array($body['addresses'])) {
            return $body;
        }
        $companyIso = isset($body['country']) && is_string($body['country']) ? $body['country'] : '';
        foreach ($body['addresses'] as $key => $address) {
            if (!is_array($address) || !isset($address['postal_code']) || !is_string($address['postal_code'])) {
                continue;
            }
            $iso = isset($address['country']) && is_string($address['country']) && $address['country'] !== ''
                ? $address['country']
                : $companyIso;
            if ($iso === '') {
                continue;
            }
            $iso = strtoupper($iso);
            $body['addresses'][$key]['postal_code'] = self::format(
                $address['postal_code'],
                (string) call_user_func($zipFormatForIso, $iso),
                $iso
            );
        }

        return $body;
    }
}
