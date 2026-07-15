<?php
/**
 * Sole trader checkout support — business logic (TWO-24755).
 *
 * All decisioning lives here; the address-form override and the JS layer
 * only render what these methods return.
 *
 * Sole Trader is a third option on the existing Personal/Business
 * account_type selector (PS_TWO_USE_ACCOUNT_TYPE mode). Two gates decide
 * whether it is offered for a billing country, and both must pass:
 *  - country-level legal truth from the registry endpoint
 *    GET /registry/v1/supported-company-types/<ISO> (TWO-24753), and
 *  - the merchant's PS_TWO_ENABLE_SOLE_TRADER admin toggle (default off,
 *    and dependent on account-type mode, since the option rides that
 *    selector).
 *
 * The flow mirrors the WooCommerce/Magento plugins: the buyer picks Sole
 * Trader, the module server-side mints two delegated-authority tokens
 * with the merchant API key, the buyer registers or logs in through
 * Two's hosted signup popup, and the checkout autofills the company data
 * from GET /autofill/v1/buyer/current. No sole-trader-specific fields
 * are collected and the order payload is unchanged — an enrolled sole
 * trader's organization number (TWO:ST…) carries the semantics and the
 * backend derives the company type from it (TWO-24749 spike).
 */
class TwoSoleTrader
{
    const SOLE_TRADER = 'SOLE_TRADER';

    /** Cookie key prefix; full key is prefix + ISO country code. */
    const COOKIE_KEY_PREFIX = 'two_company_types_';

    /** Matches the registry endpoint's Cache-Control max-age. */
    const CACHE_TTL_SECONDS = 3600;

    /** @var array<string, string[]> request-scoped cache, keyed by country */
    private static $types_cache = array();

    /**
     * Whether the merchant has opted into sole trader checkout. The option
     * rides the account_type selector, so account-type mode is a hard
     * prerequisite — without it the feature cannot surface no matter what
     * the toggle says.
     */
    public static function isEnabled()
    {
        return (int) Configuration::get('PS_TWO_ENABLE_SOLE_TRADER') === 1
            && (int) Configuration::get('PS_TWO_USE_ACCOUNT_TYPE') === 1;
    }

    /**
     * Whether the Sole Trader option should be offered for a billing
     * country: merchant toggle on AND the country legally supports it.
     *
     * @param Twopayment $module
     * @param string $countryIso
     *
     * @return bool
     */
    public static function isAvailable($module, $countryIso)
    {
        return self::isEnabled()
            && in_array(self::SOLE_TRADER, self::getSupportedCompanyTypes($module, $countryIso), true);
    }

    /**
     * Whether an account type is allowed to pay with Two. Business always
     * is; sole trader only when the feature is available for the country;
     * anything else (personal, empty) fails closed.
     *
     * @param Twopayment $module
     * @param string $accountType
     * @param string $countryIso
     *
     * @return bool
     */
    public static function isAccountTypeAllowed($module, $accountType, $countryIso)
    {
        if ($accountType === 'business') {
            return true;
        }
        if ($accountType === 'sole_trader') {
            return self::isAvailable($module, $countryIso);
        }
        return false;
    }

    /**
     * The buyer company types the Two registry supports for a country,
     * from GET /registry/v1/supported-company-types/<ISO> — only the
     * types that need registry enrollment before they can buy (sole
     * traders). Registered businesses need no enrollment and are always
     * supported, so the endpoint deliberately omits them: an empty list
     * means business-only checkout. Cached in the context cookie for the
     * endpoint's own max-age. Fail-soft: any error (network, non-200,
     * malformed body) also resolves to an empty list — checkout never
     * blocks, the option just doesn't show.
     *
     * @param Twopayment $module
     * @param string $countryIso
     *
     * @return string[]
     */
    public static function getSupportedCompanyTypes($module, $countryIso)
    {
        $countryIso = strtoupper(trim((string) $countryIso));
        if (!preg_match('/^[A-Z]{2}$/', $countryIso)) {
            return array();
        }

        if (array_key_exists($countryIso, self::$types_cache)) {
            return self::$types_cache[$countryIso];
        }

        $cookie = Context::getContext()->cookie;
        $cookieKey = self::COOKIE_KEY_PREFIX . $countryIso;
        if ($cookie && !empty($cookie->{$cookieKey})) {
            $cached = json_decode($cookie->{$cookieKey}, true);
            if (
                is_array($cached)
                && isset($cached['types'], $cached['fetched_at'])
                && is_array($cached['types'])
                && time() - (int) $cached['fetched_at'] < self::CACHE_TTL_SECONDS
            ) {
                return self::$types_cache[$countryIso] = $cached['types'];
            }
        }

        $types = self::fetchSupportedCompanyTypes($module, $countryIso);

        if ($cookie) {
            $cookie->{$cookieKey} = json_encode(array(
                'types' => $types,
                'fetched_at' => time(),
            ));
        }
        return self::$types_cache[$countryIso] = $types;
    }

    /**
     * Uncached registry call. @see getSupportedCompanyTypes()
     *
     * @return string[]
     */
    private static function fetchSupportedCompanyTypes($module, $countryIso)
    {
        $response = $module->setTwoPaymentRequest(
            '/registry/v1/supported-company-types/' . $countryIso,
            null,
            'GET'
        );
        if (
            !is_array($response)
            || (int) ($response['http_status'] ?? 0) !== 200
            || !isset($response['supported_company_types'])
            || !is_array($response['supported_company_types'])
        ) {
            return array();
        }
        return array_values(array_filter($response['supported_company_types'], 'is_string'));
    }

    /**
     * Mint the two delegated-authority tokens the sole-trader flow needs,
     * server-side with the merchant API key (the key never reaches the
     * browser). The Two API returns each token in the
     * `two-delegated-authority-token` response HEADER, not the body —
     * hence a dedicated request here rather than setTwoPaymentRequest,
     * which discards response headers. Fail-closed: if either mint fails
     * the pair is void — never hand the browser half a flow.
     *
     * @param Twopayment $module
     *
     * @return array{delegation_token: string, autofill_token: string}|null
     */
    public static function mintTokens($module)
    {
        $delegationToken = self::mintToken($module, '/registry/v1/delegation', array(
            'create_proposal' => true,
            'read_current_business' => true,
        ));
        $autofillToken = self::mintToken($module, '/autofill/v1/delegation', array(
            'read_current_buyer' => true,
            'write_current_buyer' => true,
        ));
        if ($delegationToken === null || $autofillToken === null) {
            return null;
        }
        return array(
            'delegation_token' => $delegationToken,
            'autofill_token' => $autofillToken,
        );
    }

    /**
     * @return string|null
     */
    private static function mintToken($module, $endpoint, array $payload)
    {
        $result = self::postCapturingHeaders($module, $endpoint, $payload);
        if ($result === null || $result['status'] >= 300) {
            return null;
        }
        $token = isset($result['headers']['two-delegated-authority-token'])
            ? trim($result['headers']['two-delegated-authority-token'])
            : '';
        return $token !== '' ? $token : null;
    }

    /**
     * POST to the Two API capturing response headers (lower-cased keys).
     * Seam for tests: overridable transport via the static $transport hook.
     *
     * @return array{status: int, headers: array<string, string>}|null
     */
    private static function postCapturingHeaders($module, $endpoint, array $payload)
    {
        if (self::$transport !== null) {
            return call_user_func(self::$transport, $endpoint, $payload);
        }

        $url = $module->getTwoCheckoutHostUrl() . $endpoint;
        $responseHeaders = array();

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'X-API-Key: ' . Configuration::get('PS_TWO_MERCHANT_API_KEY'),
            ),
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($header);
            },
        ));
        // Honour the merchant's SSL-verify setting (and its production
        // hardening) the same way every other Two API call does, rather
        // than leaving this curl on libcurl defaults.
        $module->configureSslVerification($ch);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error) {
            PrestaShopLogger::addLog('TwoPayment: sole trader token mint failed - ' . $error, 2);
            return null;
        }
        return array('status' => $status, 'headers' => $responseHeaders);
    }

    /**
     * Explicit environment -> checkout-page host map for Two's hosted
     * sole-trader signup page (the checkout-page app, not the API).
     * Mirrors Twopayment::ENVIRONMENT_HOSTS: anything not in the map
     * (legacy 'development', empty/unset) falls back to sandbox.
     */
    private static $signup_hosts = array(
        'production' => 'https://checkout.two.inc',
        'staging' => 'https://checkout.staging.two.inc',
    );

    /**
     * Full URL of Two's hosted sole-trader signup page for the configured
     * environment.
     *
     * @return string
     */
    public static function getSignupPageUrl()
    {
        $env = strtolower((string) Configuration::get('PS_TWO_ENVIRONMENT'));
        $host = isset(self::$signup_hosts[$env])
            ? self::$signup_hosts[$env]
            : 'https://checkout.sandbox.two.inc';
        return $host . '/soletrader/signup';
    }

    /** @var callable|null test seam for postCapturingHeaders */
    public static $transport = null;

    /**
     * Test seam: clear the request-scoped types cache and transport hook.
     */
    public static function resetCache()
    {
        self::$types_cache = array();
        self::$transport = null;
    }
}
