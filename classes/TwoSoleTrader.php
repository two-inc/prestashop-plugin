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

    /** @var callable|null test seam for postCapturingHeaders */
    public static $transport = null;

    /**
     * Explicit environment -> checkout-page host map for Two's hosted
     * sole-trader signup page (the checkout-page app, not the API).
     * Mirrors Twopayment::ENVIRONMENT_HOSTS: anything not in the map
     * (legacy 'development', empty/unset) falls back to sandbox.
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
     * The same answer, but able to say "I do not know" (TWO-25326 bug 9, round 3
     * adversarial review). Returns true, false, or NULL when the registry did not
     * answer at all.
     *
     * isAvailable() cannot express that, and must not: every existing caller gates
     * a capability on it and has to fail soft to "no sole trader" rather than
     * block checkout. But the payment tile now RENDERS the answer into markup the
     * browser adopts as settled and never re-asks (paymentinfo.tpl ->
     * TwoSoleTrader.adoptServerRenderedToggle), so collapsing a transport failure
     * into "no" there would launder one blip into a cached "no" for the rest of
     * the page's life. Both layers already refuse to do that on their own - see
     * getSupportedCompanyTypesOrNull() below and the client fetch's own catch -
     * and this is what lets the handover refuse to as well: an unresolved answer
     * is rendered as no answer, and the client's retrying path stays live.
     *
     * @param Twopayment $module
     * @param string $countryIso
     *
     * @return bool|null
     */
    public static function resolveAvailability($module, $countryIso)
    {
        $types = self::getSupportedCompanyTypesOrNull($module, $countryIso);
        if ($types === null) {
            return null;
        }

        return in_array(self::SOLE_TRADER, $types, true);
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
     * (resolves to an empty list, checkout never blocks) but is
     * deliberately NOT cached, so a transient registry blip does not
     * suppress the toggle for the rest of the TTL window.
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
     *   resolveAvailability(); see its comment for why the distinction has to
     *   survive as far as the payment tile.
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

        $types = self::fetchSupportedCompanyTypes($module, $countryIso);
        if ($types === null) {
            // NOT memoised either (TWO-25326 bug 9, round 3 adversarial review).
            // The class comment above has always said a fetch error is not cached,
            // and the COOKIE was already exempt - but the request-scoped cache
            // below used to store the flattened empty list, so within one request
            // a blip WAS cached, as a definite "business-only country". That was
            // invisible while every caller re-asked over AJAX; it is not once the
            // answer is rendered into markup the browser adopts and never re-asks.
            return null;
        }

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
            // Tight timeout, not the 30s default (TWO-25326 bug 9, round 3
            // adversarial review). This call used to be reachable only from the
            // module's own AJAX controller, i.e. always after the buyer's page had
            // painted. The payment tile now resolves it INLINE in the checkout
            // render, so on a cold cache it is on the critical path of a shopper's
            // page - which is exactly the set of calls this constant exists for.
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

    /**
     * Test seam: clear the request-scoped types cache and transport hook.
     */
    public static function resetCache()
    {
        self::$types_cache = array();
        self::$transport = null;
    }
}
