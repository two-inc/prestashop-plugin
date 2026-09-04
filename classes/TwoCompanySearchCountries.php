<?php
/**
 * Country gate for the "Registered Company" search chip (TWO-25288 follow-up).
 *
 * Relays GET /companies/v2/supported-countries - the full ISO alpha-2 list
 * company search can serve, published by bifrost (PR #832). Unlike
 * TwoSoleTrader's per-country registry lookup, this is ONE global list, so
 * the cache is a single cookie slot with no per-country keying.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoCompanySearchCountries
{
    const COOKIE_KEY = 'two_company_search_countries';

    /** Matches the endpoint's Cache-Control max-age. */
    const CACHE_TTL_SECONDS = 3600;

    /** @var string[]|null request-scoped cache */
    private static $countries_cache = null;

    /** Whether the lookup has already failed once this request. */
    private static $failed = false;

    /**
     * The supported-countries list, or null if it could not be resolved
     * (network/non-200/malformed - never cached as an answer). Callers must
     * treat null as UNKNOWN and fail open (offer search), matching bifrost's
     * own guidance to treat a failed response as unknown rather than
     * unsupported.
     *
     * @param Twopayment $module
     *
     * @return string[]|null
     */
    public static function getSupportedCountriesOrNull($module)
    {
        if (self::$countries_cache !== null) {
            return self::$countries_cache;
        }
        if (self::$failed) {
            return null;
        }

        $cached = self::readCachedCountries();
        if ($cached !== null) {
            return self::$countries_cache = $cached;
        }

        $countries = self::fetchSupportedCountries($module);
        if ($countries === null) {
            self::$failed = true;
            return null;
        }

        $cookie = Context::getContext()->cookie;
        if ($cookie) {
            $cookie->{self::COOKIE_KEY} = json_encode(array(
                'countries' => $countries,
                'fetched_at' => time(),
            ));
        }

        return self::$countries_cache = $countries;
    }

    /**
     * @return string[]|null
     */
    private static function readCachedCountries()
    {
        $cookie = Context::getContext()->cookie;
        if (!$cookie || empty($cookie->{self::COOKIE_KEY})) {
            return null;
        }
        $cached = json_decode($cookie->{self::COOKIE_KEY}, true);
        if (
            !is_array($cached)
            || !isset($cached['countries'], $cached['fetched_at'])
            || !is_array($cached['countries'])
            || time() - (int) $cached['fetched_at'] >= self::CACHE_TTL_SECONDS
        ) {
            return null;
        }
        return array_values(array_filter($cached['countries'], 'is_string'));
    }

    /**
     * Uncached call to GET /companies/v2/supported-countries.
     *
     * @param Twopayment $module
     *
     * @return string[]|null
     */
    private static function fetchSupportedCountries($module)
    {
        $response = $module->setTwoPaymentRequest(
            '/companies/v2/supported-countries',
            null,
            'GET',
            array(),
            Twopayment::API_TIMEOUT_STATE_CHECK
        );
        if (
            !is_array($response)
            || (int) ($response['http_status'] ?? 0) !== 200
            || !isset($response['supported_countries'])
            || !is_array($response['supported_countries'])
        ) {
            return null;
        }
        return array_values(array_filter($response['supported_countries'], 'is_string'));
    }
}
