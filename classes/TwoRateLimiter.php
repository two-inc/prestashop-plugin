<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

/**
 * Fixed-window per-caller ceiling for the buyer-facing order-intent AJAX
 * endpoints, each of which spends the merchant's own API key server-side.
 * On by default (TWO-25386), matching the equivalent checkout rate
 * limiting shipped for the woocommerce-plugin and magento-plugin gateways.
 *
 * One row per (route, caller) in `twopayment_rate_limit`, holding the
 * window's start and count - overwritten in place each window rather than
 * keyed by window index, so the table stays bounded by distinct callers
 * rather than growing per window.
 */
class TwoRateLimiter
{
    /** [max requests, window seconds] per action, sized so several concurrent checkouts behind one office NAT fit. */
    private const LIMITS = [
        'buildPayload' => [60, 60],
        'saveCompany' => [60, 60],
        'clearCompany' => [60, 60],
        'saveMirrorWrites' => [60, 60],
        'getCompany' => [60, 60],
        'savePaymentTerm' => [60, 60],
        'fetchTermSurcharges' => [300, 60],
        'syncSurchargeLine' => [300, 60],
        'checkOrderIntent' => [30, 60],
        'saveOrderIntentResult' => [30, 60],
        'clearOrderIntentResult' => [60, 60],
        'soleTraderAvailability' => [60, 60],
        'soleTraderTokens' => [60, 60],
    ];

    /** IPv6 callers are bucketed by their /64 - the smallest allocation routed to a single real-world holder. */
    private const IPV6_BUCKET_MASK_BYTES = 8;

    /**
     * @param string $action one of self::LIMITS' keys; an unlisted action is never limited
     * @return bool false when the caller is over the ceiling for this action
     */
    public static function check($action)
    {
        if (!isset(self::LIMITS[$action]) || (bool) Configuration::get('PS_TWO_DISABLE_RATE_LIMIT')) {
            return true;
        }

        list($max, $windowSeconds) = self::LIMITS[$action];
        $now = time();
        $key = substr(hash('sha256', $action . "\0" . self::callerIdentity()), 0, 60);

        $row = Db::getInstance()->getRow(
            'SELECT `window_start`, `hit_count` FROM `' . _DB_PREFIX_ . 'twopayment_rate_limit`'
            . ' WHERE `rate_key` = "' . pSQL($key) . '"'
        );
        $windowStart = $now;
        $count = 0;
        if (is_array($row) && ($now - (int) $row['window_start']) < $windowSeconds) {
            $windowStart = (int) $row['window_start'];
            $count = (int) $row['hit_count'];
        }
        $count++;

        Db::getInstance()->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . 'twopayment_rate_limit`'
            . ' (`rate_key`, `window_start`, `hit_count`) VALUES'
            . ' ("' . pSQL($key) . '", ' . (int) $windowStart . ', ' . (int) $count . ')'
        );

        if ($count > $max) {
            PrestaShopLogger::addLog(
                sprintf(
                    'TwoPayment: rate limit exceeded on %s (more than %d requests in %ds from one caller)',
                    $action,
                    $max,
                    $windowSeconds
                ),
                2
            );
            return false;
        }

        return true;
    }

    /**
     * The connecting peer, unless it is one of the merchant's own trusted
     * proxies - then the rightmost untrusted hop in X-Forwarded-For. Every
     * unresolvable peer shares one bucket rather than escaping the ceiling.
     */
    private static function callerIdentity()
    {
        $peer = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        if ($peer === '') {
            return 'unknown';
        }

        $rules = self::trustedProxies();
        if ($rules === [] || !self::matchesAny($peer, $rules)) {
            return self::bucketIdentity($peer);
        }

        $forwarded = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
        foreach (array_reverse(explode(',', $forwarded)) as $hop) {
            $hop = trim($hop);
            // Rightmost hop that is not itself a trusted proxy: everything
            // further left was written by a proxy we do not trust, so the
            // buyer could have set it themselves.
            if ($hop !== '' && !self::matchesAny($hop, $rules)) {
                return self::bucketIdentity($hop);
            }
        }

        return self::bucketIdentity($peer);
    }

    /**
     * @return string[] Trusted proxy addresses or CIDR blocks, as configured.
     */
    private static function trustedProxies()
    {
        $raw = (string) Configuration::get('PS_TWO_TRUSTED_PROXIES');
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry !== '') {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /** @param string[] $rules */
    private static function matchesAny($address, array $rules)
    {
        foreach ($rules as $rule) {
            if (self::matches($address, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one chokepoint where a resolved address becomes a bucket-cache
     * key: an IPv6 address is masked to its /64 so a routed allocation
     * (the smallest real-world one) can't spin up one bucket per address.
     * IPv4 is used verbatim.
     */
    private static function bucketIdentity($address)
    {
        $packed = @inet_pton($address);
        if ($packed === false || strlen($packed) !== 16) {
            return $address;
        }

        $masked = substr($packed, 0, self::IPV6_BUCKET_MASK_BYTES)
            . str_repeat("\0", 16 - self::IPV6_BUCKET_MASK_BYTES);

        return inet_ntop($masked) . '/64';
    }

    /**
     * True when the entry is a usable IP or CIDR range; the admin save
     * check (Twopayment::validTwoDiagnosticsFormValues) and this class'
     * runtime match share this, so a rule that would silently never match
     * is refused at save time rather than retired invisibly.
     */
    public static function isValidProxyEntry($entry)
    {
        return self::parseRange($entry) !== null;
    }

    /**
     * @return array{0: string, 1: int}|null [packed network, prefix bits], or null when unusable
     */
    private static function parseRange($range)
    {
        $bits = null;
        if (strpos($range, '/') !== false) {
            list($range, $bits) = explode('/', $range, 2);
            if (preg_match('/^\d+$/', trim($bits)) !== 1) {
                return null;
            }
        }

        $packed = @inet_pton(trim($range));
        if ($packed === false) {
            return null;
        }

        $width = strlen($packed) * 8;
        $bits = $bits === null ? $width : (int) trim($bits);
        // A width of 0 matches its whole address family, so it can only be a
        // typo. Leading zeros read as decimal, so /008 is /8.
        if ($bits < 1 || $bits > $width) {
            return null;
        }

        return [$packed, $bits];
    }

    private static function matches($address, $rule)
    {
        $parsed = self::parseRange($rule);
        if ($parsed === null) {
            return false;
        }
        list($packedSubnet, $bits) = $parsed;

        $packedAddress = @inet_pton($address);
        if ($packedAddress === false || strlen($packedAddress) !== strlen($packedSubnet)) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0 && strncmp($packedAddress, $packedSubnet, $wholeBytes) !== 0) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($packedAddress[$wholeBytes]) & $mask) === (ord($packedSubnet[$wholeBytes]) & $mask);
    }
}
