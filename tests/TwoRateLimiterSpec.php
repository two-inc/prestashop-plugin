<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/TwoRateLimiter.php';

/**
 * TWO-25386 - checkout rate limiting, on by default. Covers threshold
 * behaviour, the 0-width CIDR bypass, and IPv6 /64 bucketing - the same
 * shape as the equivalent coverage on the sibling plugins.
 */
final class TwoRateLimiterSpec
{
    public static function runAll(): void
    {
        self::testUnlistedActionIsNeverLimited();
        self::testDisableToggleAdmitsEveryRequest();
        self::testAllowsUpToTheConfiguredMaxThenRefuses();
        self::testWindowResetAdmitsAgain();
        self::testDistinctCallersGetSeparateBuckets();

        self::testZeroWidthCidrIsRejected();
        self::testValidCidrWidthsAreAccepted();
        self::testLeadingZeroWidthReadsAsDecimal();
        self::testNonNumericWidthIsRejected();

        self::testTrustedProxyIsExemptedByForwardedForHop();
        self::testUntrustedPeerIgnoresForwardedFor();

        self::testIpv6CallersShareA64BucketButNotAcross();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    // ---- threshold behaviour -----------------------------------------------

    private static function testUnlistedActionIsNeverLimited(): void
    {
        self::reset();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        for ($i = 0; $i < 1000; $i++) {
            TinyAssert::true(
                TwoRateLimiter::check('notARealAction'),
                'an action with no configured ceiling is never limited'
            );
        }
    }

    private static function testDisableToggleAdmitsEveryRequest(): void
    {
        self::reset();
        StubStore::$configuration['PS_TWO_DISABLE_RATE_LIMIT'] = 1;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.2';
        for ($i = 0; $i < 100; $i++) {
            TinyAssert::true(
                TwoRateLimiter::check('checkOrderIntent'),
                'the Diagnostics toggle set to on must stop metering entirely'
            );
        }
    }

    private static function testAllowsUpToTheConfiguredMaxThenRefuses(): void
    {
        self::reset();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.3';
        // checkOrderIntent: [30, 60] - see TwoRateLimiter::LIMITS.
        for ($i = 0; $i < 30; $i++) {
            TinyAssert::true(TwoRateLimiter::check('checkOrderIntent'), 'request ' . ($i + 1) . ' of 30 is admitted');
        }
        TinyAssert::false(TwoRateLimiter::check('checkOrderIntent'), 'request 31 within the window is refused');
        TinyAssert::false(TwoRateLimiter::check('checkOrderIntent'), 'refusal persists for further requests in the same window');
    }

    private static function testWindowResetAdmitsAgain(): void
    {
        self::reset();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.4';
        for ($i = 0; $i < 31; $i++) {
            TwoRateLimiter::check('checkOrderIntent');
        }
        TinyAssert::false(TwoRateLimiter::check('checkOrderIntent'), 'still refused within the window');

        // Simulate the window elapsing: back-date the stored row past the 60s window.
        foreach (StubStore::$rateLimitRows as $key => $row) {
            StubStore::$rateLimitRows[$key]['window_start'] = $row['window_start'] - 61;
        }

        TinyAssert::true(TwoRateLimiter::check('checkOrderIntent'), 'a new window admits the caller again');
    }

    private static function testDistinctCallersGetSeparateBuckets(): void
    {
        self::reset();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        for ($i = 0; $i < 30; $i++) {
            TwoRateLimiter::check('checkOrderIntent');
        }
        TinyAssert::false(TwoRateLimiter::check('checkOrderIntent'), 'first caller is at its ceiling');

        $_SERVER['REMOTE_ADDR'] = '203.0.113.6';
        TinyAssert::true(TwoRateLimiter::check('checkOrderIntent'), 'a distinct caller has its own, untouched bucket');
    }

    // ---- CIDR parsing (0-width bypass + malformed width) -------------------

    private static function testZeroWidthCidrIsRejected(): void
    {
        // A /0 (or /00) cast via (int) becomes 0, which a naive range check
        // treats as "match everything of this family" - a bug class already
        // fixed on the sibling plugins' rate limiters. Rejecting it outright
        // means a trusted-proxy entry of
        // "0.0.0.0/0" can never silently exempt every caller.
        $cases = [
            ['0.0.0.0/0', '0.0.0.0', false, 'IPv4 /0 must not match'],
            ['0.0.0.0/00', '0.0.0.0', false, 'IPv4 /00 must not match'],
            ['::/0', '::1', false, 'IPv6 /0 must not match'],
        ];
        foreach ($cases as [$rule, $address, $expected, $description]) {
            self::reset();
            StubStore::$configuration['PS_TWO_TRUSTED_PROXIES'] = $rule;
            $_SERVER['REMOTE_ADDR'] = $address;
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
            // If the /0 rule wrongly matched, the peer would be treated as a
            // trusted proxy and the caller identity would resolve from
            // X-Forwarded-For instead of REMOTE_ADDR - assert the peer's own
            // bucket is still what gets metered by checking isValidProxyEntry
            // directly, which is the save-time and runtime-shared gate.
            TinyAssert::same($expected, TwoRateLimiter::isValidProxyEntry($rule), $description);
        }
    }

    private static function testValidCidrWidthsAreAccepted(): void
    {
        $cases = [
            ['10.0.0.0/8', true, 'a normal IPv4 CIDR is valid'],
            ['10.0.0.0/32', true, 'a full-width IPv4 CIDR (single host) is valid'],
            ['2001:db8::/32', true, 'a normal IPv6 CIDR is valid'],
            ['2001:db8::1/128', true, 'a full-width IPv6 CIDR (single host) is valid'],
            ['10.0.0.0/33', false, 'a width past the address size is invalid'],
            ['2001:db8::/129', false, 'a width past the address size is invalid (v6)'],
        ];
        foreach ($cases as [$rule, $expected, $description]) {
            TinyAssert::same($expected, TwoRateLimiter::isValidProxyEntry($rule), $description);
        }
    }

    private static function testLeadingZeroWidthReadsAsDecimal(): void
    {
        TinyAssert::true(TwoRateLimiter::isValidProxyEntry('10.0.0.0/008'), '/008 is the genuine /8, not rejected as malformed');
    }

    private static function testNonNumericWidthIsRejected(): void
    {
        // Before the numeric cast, "(int) 'abc'" becomes 0 and would pass a
        // naive range guard, matching every address of that family.
        TinyAssert::false(TwoRateLimiter::isValidProxyEntry('10.0.0.0/abc'), 'a non-numeric width is rejected outright');
        TinyAssert::false(TwoRateLimiter::isValidProxyEntry('not-an-address'), 'an unparseable address is rejected');
    }

    // ---- trusted-proxy resolution -------------------------------------------

    private static function testTrustedProxyIsExemptedByForwardedForHop(): void
    {
        self::reset();
        StubStore::$configuration['PS_TWO_TRUSTED_PROXIES'] = '10.0.0.0/8';
        $_SERVER['REMOTE_ADDR'] = '10.1.2.3'; // the merchant's own reverse proxy
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
        for ($i = 0; $i < 30; $i++) {
            TwoRateLimiter::check('checkOrderIntent');
        }
        TinyAssert::false(TwoRateLimiter::check('checkOrderIntent'), 'the forwarded buyer address hits its own ceiling');

        // A second buyer behind the SAME trusted proxy must get its own bucket.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.10';
        TinyAssert::true(TwoRateLimiter::check('checkOrderIntent'), 'a distinct forwarded buyer is not collapsed into the proxy bucket');
    }

    private static function testUntrustedPeerIgnoresForwardedFor(): void
    {
        self::reset();
        StubStore::$configuration['PS_TWO_TRUSTED_PROXIES'] = '10.0.0.0/8';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.50'; // not a trusted proxy
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4'; // buyer-supplied, must be ignored
        for ($i = 0; $i < 30; $i++) {
            TwoRateLimiter::check('checkOrderIntent');
        }
        TinyAssert::false(TwoRateLimiter::check('checkOrderIntent'), 'the untrusted peer itself is metered');

        // Spoofing X-Forwarded-For from an untrusted peer must not free up a new bucket.
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '5.6.7.8';
        TinyAssert::false(TwoRateLimiter::check('checkOrderIntent'), 'an untrusted peer cannot escape its bucket via a spoofed header');
    }

    // ---- IPv6 /64 bucketing -------------------------------------------------

    private static function testIpv6CallersShareA64BucketButNotAcross(): void
    {
        self::reset();
        // Same /64, different host bits - must share one bucket (routed
        // allocation, not the raw address).
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678::1';
        for ($i = 0; $i < 30; $i++) {
            TwoRateLimiter::check('checkOrderIntent');
        }
        TinyAssert::false(TwoRateLimiter::check('checkOrderIntent'), 'first address in the /64 is at its ceiling');

        $_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678:ffff:ffff:ffff:ffff';
        TinyAssert::false(
            TwoRateLimiter::check('checkOrderIntent'),
            'a different address within the SAME /64 shares the ceiling rather than getting a free bucket'
        );

        // A different /64 must be a distinct bucket.
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5679::1';
        TinyAssert::true(TwoRateLimiter::check('checkOrderIntent'), 'a different /64 gets its own bucket');
    }
}
