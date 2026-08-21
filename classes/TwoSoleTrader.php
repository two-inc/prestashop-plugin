<?php
/**
 * Sole trader checkout support — business logic (TWO-24755).
 *
 * All decisioning lives here; the payment-step JS toggle and the
 * order-intent controller only render/consume what these methods return
 * (the ps_checkout adaptation, TWO-24770, must be able to re-skin the
 * presentation layer without touching this class).
 *
 * Matches the Magento/WooCommerce plugins' model: there is NO account-type
 * selector on the address form (B2B checkout - the company field is
 * always present). Instead the payment step shows a Business / Sole
 * trader TOGGLE, gated on ONE thing only: country-level legal truth from
 * the registry endpoint GET /registry/v1/supported-company-types/<ISO>
 * (TWO-24753) - GB/US currently, NO/SE not. There is deliberately no
 * merchant admin toggle (the former PS_TWO_ENABLE_SOLE_TRADER was removed
 * in TWO-25166): whether a buyer in a country can be a sole trader is
 * Two's registry answer, not a merchant preference, and Magento's
 * toggle-less behaviour is the cross-plugin target state (TWO-25163).
 *
 * Picking Sole Trader mints two delegated-authority tokens server-side
 * with the merchant API key, the buyer registers or logs in through
 * Two's hosted signup popup, and the checkout autofills the company data
 * from GET /autofill/v1/buyer/current. An enrolled sole trader then
 * checks out as a regular business - no sole-trader-specific fields are
 * collected and the order payload is unchanged: the synthetic
 * organization number (TWO:ST…) their registration minted carries the
 * semantics, and the backend derives the company type from it
 * (TWO-24749 spike). There is no account-type security gate on the
 * order-intent path either - an org number is the business guard, and
 * both a registered business and an enrolled sole trader arrive with
 * one.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class TwoSoleTrader
{
    const SOLE_TRADER = 'SOLE_TRADER';

    /**
     * Single cookie key for the registry-answer cache. Deliberately one
     * slot, not one key per country: the checkout only ever cares about
     * the buyer's currently-selected billing country, and a per-country
     * key (keyed on unvalidated client input, since the availability
     * check runs live as the buyer edits the address form) would let a
     * caller with a valid ajax token grow the single PrestaShop session
     * cookie without bound by requesting many ISO codes. Overwriting on
     * every lookup caps growth at one entry, at the cost of a re-fetch
     * when the buyer switches country back and forth within the TTL - an
     * acceptable trade for a value that changes at most a few times per
     * checkout.
     */
    const COOKIE_KEY = 'two_sole_trader_types';

    /** Matches the registry endpoint's Cache-Control max-age. */
    const CACHE_TTL_SECONDS = 3600;

    /** @var array<string, string[]> request-scoped cache, keyed by country */
    private static $types_cache = array();

    /**
     * Countries whose lookup FAILED during this request, so it is attempted at
     * most once per request per country (TWO-25326 bug 9, round 3).
     *
     * Deliberately separate from $types_cache: a failure must never be stored
     * as an ANSWER (a registry blip would then read as a cached "business-only
     * country") and must not survive past the request (that's what the cookie
     * is for, and it never records a failure either).
     *
     * @var array<string, bool>
     */
    private static $failed_lookups = array();

    /** @var callable|null test seam for postCapturingHeaders */
    public static $transport = null;

    /**
     * Explicit environment -> checkout-page host map for Two's hosted
     * sole-trader signup page (the checkout-page app, not the API).
     * Mirrors Twopayment::ENVIRONMENT_HOSTS: anything not in the map
     * (legacy 'development', empty/unset) falls back to sandbox. In dev mode
     * only, TWO_CHECKOUT_BASE_URL takes precedence over this map — see
     * getSignupPageUrl().
     *
     * @var array<string, string>
     */
    private static $signup_hosts = array(
        'production' => 'https://checkout.two.inc',
        'staging' => 'https://checkout.staging.two.inc',
    );

    /**
     * Whether the Sole Trader toggle should be offered for a billing
     * country. The registry's answer for that country is the ONLY gate -
     * and it is also the security barrier the order-intent controller
     * relies on before minting delegated-authority tokens, so it must
     * stay the single source of truth here (TWO-25166).
     *
     * @param Twopayment $module
     * @param string $countryIso
     *
     * @return bool
     */
    public static function isAvailable($module, $countryIso)
    {
        return in_array(self::SOLE_TRADER, self::getSupportedCompanyTypes($module, $countryIso), true);
    }

    /**
     * The same three-state answer, but ONLY if it is already known - never a
     * network call (TWO-25326 bug 9, round 3 review, finding 2). This is what
     * the payment tile renders from: a render-path network call would pay a
     * registry timeout on every payment-step render for a shop that can't
     * reach the registry, which is buyer-visible latency a rendering nicety
     * doesn't justify.
     *
     * An unknown answer renders as no answer; the browser's own availability
     * request resolves it and writes the cookie, so the first payment-step
     * render of a session shows no toggle and every render after it shows the
     * real answer. Where the cookie can't be stored, or the registry keeps
     * failing, every render answers "unknown" and the toggle is client-rendered
     * as it was pre-ticket.
     *
     * @param string $countryIso
     *
     * @return bool|null null = not known yet (or known to have failed)
     */
    public static function resolveAvailabilityFromCache($countryIso)
    {
        $countryIso = strtoupper(trim((string) $countryIso));
        if (!preg_match('/^[A-Z]{2}$/', $countryIso)) {
            return null;
        }

        $types = self::readCachedTypes($countryIso);
        if ($types === null) {
            return null;
        }

        return in_array(self::SOLE_TRADER, $types, true);
    }

    /**
     * The already-known answer for a country, or null if there is none.
     *
     * ONE reader for both callers (round 4 review, finding 4): keeps
     * COOKIE_KEY, CACHE_TTL_SECONDS and the stored JSON shape defined in one
     * place instead of two independently-maintained copies.
     *
     * Request memo first, then the cookie: the memo is only ever populated from a
     * fresh answer, so it cannot be staler than the cookie it shadows.
     *
     * Deliberately does NOT consult $failed_lookups. A failure is not an answer,
     * and both this and "no cached answer" resolve to the same null for every
     * caller - reading it would add a branch that cannot change an outcome.
     *
     * @param string $countryIso already normalised and validated by the caller
     *
     * @return string[]|null
     */
    private static function readCachedTypes($countryIso)
    {
        if (array_key_exists($countryIso, self::$types_cache)) {
            return self::$types_cache[$countryIso];
        }

        $cookie = Context::getContext()->cookie;
        if ($cookie && !empty($cookie->{self::COOKIE_KEY})) {
            $cached = json_decode($cookie->{self::COOKIE_KEY}, true);
            if (
                is_array($cached)
                && isset($cached['country'], $cached['types'], $cached['fetched_at'])
                && $cached['country'] === $countryIso
                && is_array($cached['types'])
                && time() - (int) $cached['fetched_at'] < self::CACHE_TTL_SECONDS
            ) {
                return self::$types_cache[$countryIso] = $cached['types'];
            }
        }

        return null;
    }

    /**
     * The buyer company types the Two registry supports for a country,
     * from GET /registry/v1/supported-company-types/<ISO> — only the
     * types that need registry enrollment before they can buy (sole
     * traders). Registered businesses need no enrollment and are always
     * supported, so the endpoint deliberately omits them: an empty list
     * is a legitimate answer meaning business-only checkout for that
     * country. Cached in the context cookie for the endpoint's own
     * max-age (single slot - see COOKIE_KEY). A fetch ERROR (network,
     * non-200, malformed body) is fail-soft for the current lookup
     * (resolves to an empty list, checkout never blocks) and is never written to
     * the COOKIE, so a transient registry blip does not suppress the toggle for
     * the rest of the TTL window. It IS remembered for the rest of the REQUEST,
     * as a failure rather than as an answer - see $failed_lookups.
     *
     * @param Twopayment $module
     * @param string $countryIso
     *
     * @return string[]
     */
    public static function getSupportedCompanyTypes($module, $countryIso)
    {
        return self::getSupportedCompanyTypesOrNull($module, $countryIso) ?? array();
    }

    /**
     * @see getSupportedCompanyTypes() - identical, except that a registry FAILURE
     *   is reported as null instead of being flattened into the empty list that a
     *   business-only country legitimately returns. Split out for
     *   getSupportedCompanyTypes()'s callers, which must fail closed, from the
     *   payment tile, which must be able to say "unknown" rather than "no" -
     *   see resolveAvailabilityFromCache().
     *
     * @param Twopayment $module
     * @param string $countryIso
     *
     * @return string[]|null
     */
    public static function getSupportedCompanyTypesOrNull($module, $countryIso)
    {
        $countryIso = strtoupper(trim((string) $countryIso));
        if (!preg_match('/^[A-Z]{2}$/', $countryIso)) {
            return array();
        }

        $cached = self::readCachedTypes($countryIso);
        if ($cached !== null) {
            return $cached;
        }
        if (isset(self::$failed_lookups[$countryIso])) {
            return null;
        }

        $types = self::fetchSupportedCompanyTypes($module, $countryIso);
        if ($types === null) {
            // Recorded as a FAILURE, not as an answer (TWO-25326 bug 9, round 3):
            // caller is told "unresolved" rather than caching a blip as a
            // definite "business-only country". See $failed_lookups.
            self::$failed_lookups[$countryIso] = true;

            return null;
        }

        $cookie = Context::getContext()->cookie;
        if ($cookie) {
            $cookie->{self::COOKIE_KEY} = json_encode(array(
                'country' => $countryIso,
                'types' => $types,
                'fetched_at' => time(),
            ));
        }
        return self::$types_cache[$countryIso] = $types;
    }

    /**
     * Uncached registry call. @see getSupportedCompanyTypes()
     *
     * Null distinguishes a fetch ERROR (network, non-200, malformed body -
     * not cached by the caller) from a real empty answer (business-only
     * country - cached normally).
     *
     * @return string[]|null
     */
    private static function fetchSupportedCompanyTypes($module, $countryIso)
    {
        $response = $module->setTwoPaymentRequest(
            '/registry/v1/supported-company-types/' . $countryIso,
            null,
            'GET',
            array(),
            // Tight timeout rather than setTwoPaymentRequest()'s 60s default
            // (API_TIMEOUT_LONG), which is sized for file uploads. This lookup is
            // reached from the module's own AJAX controller while a buyer waits on
            // the checkout for the toggle to appear, so a minute is the wrong
            // bound for it whichever side of the page it runs on. The payment tile
            // deliberately does NOT reach this method - it reads
            // resolveAvailabilityFromCache() so the checkout render makes no call
            // at all (round 3 review, finding 2).
            Twopayment::API_TIMEOUT_STATE_CHECK
        );
        if (
            !is_array($response)
            || (int) ($response['http_status'] ?? 0) !== 200
            || !isset($response['supported_company_types'])
            || !is_array($response['supported_company_types'])
        ) {
            return null;
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
     * Full URL of Two's hosted sole-trader signup page for the configured
     * environment.
     *
     * TWO_CHECKOUT_BASE_URL overrides the host when the shop is in dev mode
     * (_PS_MODE_DEV_), independently of the API and portal overrides, so a dev
     * can mix a staging API with a locally-served checkout-page. The gate lives
     * in Twopayment::getDevModeEnvOverride() — one implementation shared with
     * TWO_API_BASE_URL and TWO_PORTAL_BASE_URL, never honoured off dev mode.
     *
     * @return string
     */
    public static function getSignupPageUrl()
    {
        $override = Twopayment::getDevModeEnvOverride('TWO_CHECKOUT_BASE_URL');
        if ($override !== null) {
            // The map values carry no trailing slash; an env var supplied by
            // hand may, and '<host>//soletrader/signup' is not the same path.
            return rtrim($override, '/') . '/soletrader/signup';
        }
        $env = strtolower((string) Configuration::get('PS_TWO_ENVIRONMENT'));
        $host = isset(self::$signup_hosts[$env])
            ? self::$signup_hosts[$env]
            : 'https://checkout.sandbox.two.inc';
        return $host . '/soletrader/signup';
    }

    /**
     * Test seam: clear the request-scoped types cache and transport hook.
     */
    public static function resetCache()
    {
        self::$types_cache = array();
        self::$failed_lookups = array();
        self::$transport = null;
    }
}
