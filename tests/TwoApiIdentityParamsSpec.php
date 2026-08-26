<?php

declare(strict_types=1);

/**
 * Unit spec for getTwoApiIdentityParams() - the single client/client_v/merchant
 * builder every Two API call site (server-side curl and the JsDef the JS
 * modules read) is required to route through.
 */
final class TwoApiIdentityParamsSpec
{
    public static function runAll(): void
    {
        self::testGetTwoApiIdentityParamsCases();
        self::testGetTwoPdfUrlCarriesIdentityParams();
        self::testGetTwoApiIdentityParamsIsPublicForCrossClassCallers();
    }

    private static function testGetTwoApiIdentityParamsCases(): void
    {
        $cases = [
            ['merchant', [], ['client' => 'PS', 'client_v' => '2.4.0', 'merchant' => 'merchant'], 'no extras: base identity only'],
            ['merchant', ['q' => 'foo'], ['client' => 'PS', 'client_v' => '2.4.0', 'merchant' => 'merchant', 'q' => 'foo'], 'extras merged alongside identity'],
            ['', [], ['client' => 'PS', 'client_v' => '2.4.0', 'merchant' => ''], 'unset merchant short name yields empty string, not null'],
        ];

        foreach ($cases as [$merchantShortName, $extra, $expected, $description]) {
            self::reset();
            $module = new TwopaymentTestHarness();
            $module->merchant_short_name = $merchantShortName;

            TinyAssert::same($expected, $module->getTwoApiIdentityParams($extra), $description);
        }
    }

    private static function testGetTwoPdfUrlCarriesIdentityParams(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $url = $module->getTwoPdfUrl('two-order-123');

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        TinyAssert::same('PS', $query['client'] ?? null, 'PDF URL must carry client=PS');
        TinyAssert::same('2.4.0', $query['client_v'] ?? null, 'PDF URL must carry client_v');
        TinyAssert::same('merchant', $query['merchant'] ?? null, 'PDF URL must carry merchant');
    }

    /**
     * classes/TwoSoleTrader.php calls $module->getTwoApiIdentityParams() from
     * outside the Twopayment class - same cross-class-callable requirement as
     * configureSslVerification() (see TwoSoleTraderSpec's SSL tripwire).
     */
    private static function testGetTwoApiIdentityParamsIsPublicForCrossClassCallers(): void
    {
        $method = new ReflectionMethod('Twopayment', 'getTwoApiIdentityParams');
        TinyAssert::true($method->isPublic(), 'getTwoApiIdentityParams must be public - TwoSoleTrader calls it from outside the Twopayment class');
    }

    private static function reset(): void
    {
        StubStore::reset();
    }
}
