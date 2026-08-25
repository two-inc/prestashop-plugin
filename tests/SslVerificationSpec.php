<?php

declare(strict_types=1);

/**
 * Coverage for configureSslVerification(): the PS_TWO_DISABLE_SSL_VERIFY
 * toggle is the sole determinant of SSL bypass in every PS_TWO_ENVIRONMENT
 * value, including 'production' (corporate proxies that terminate TLS with
 * their own cert need this to work in production too).
 */
final class SslVerificationSpec
{
    public static function runAll(): void
    {
        self::testToggleDeterminesBypassAcrossEnvironments();
    }

    private static function testToggleDeterminesBypassAcrossEnvironments(): void
    {
        $cases = [
            ['production', true, 'toggle ON in production applies the bypass'],
            ['production', false, 'toggle OFF in production never bypasses'],
            ['development', true, 'toggle ON in development applies the bypass'],
            ['development', false, 'toggle OFF in development never bypasses'],
        ];

        foreach ($cases as [$environment, $toggleOn, $description]) {
            StubStore::$configuration['PS_TWO_ENVIRONMENT'] = $environment;
            StubStore::$configuration['PS_TWO_DISABLE_SSL_VERIFY'] = $toggleOn ? 1 : 0;
            PrestaShopLogger::reset();

            $module = new TwopaymentTestHarness();
            $ch = curl_init();
            $module->configureSslVerification($ch);

            if ($toggleOn) {
                TinyAssert::count(1, PrestaShopLogger::$logs, $description);
                TinyAssert::same(
                    'TwoPayment: SSL verification disabled by configuration (security risk - corporate networks only)',
                    PrestaShopLogger::$logs[0]['message'],
                    $description
                );
                TinyAssert::same(2, PrestaShopLogger::$logs[0]['severity'], $description);
            } else {
                foreach (PrestaShopLogger::$logs as $log) {
                    TinyAssert::false(
                        strpos($log['message'], 'SSL verification disabled by configuration') !== false,
                        $description
                    );
                }
            }

            foreach (PrestaShopLogger::$logs as $log) {
                TinyAssert::false(
                    strpos($log['message'], 'ignored in production') !== false,
                    'SSL bypass must never be reported as ignored in production'
                );
            }
        }
    }
}
