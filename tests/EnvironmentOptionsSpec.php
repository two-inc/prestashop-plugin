<?php

declare(strict_types=1);

/**
 * Environment setting (TWO-26604), driven by the shared case table
 * tests/fixtures/environment-cases.json, which is byte-identical in the
 * WooCommerce and Magento plugins.
 */
final class EnvironmentOptionsSpec
{
    public static function runAll(): void
    {
        $cases = json_decode((string) file_get_contents(__DIR__ . '/fixtures/environment-cases.json'), true);

        try {
            foreach ($cases['resolve'] as $case) {
                self::assertResolveCase($case);
            }
            foreach ($cases['save'] as $case) {
                self::assertSaveCase($case);
            }
            self::testFormOffersTheOptionsInOrder();
            self::testHealthRowLabels();
        } finally {
            putenv('TWO_INTERNAL_ENVIRONMENTS');
        }
    }

    private static function setFlag(?string $flag): void
    {
        putenv($flag === null ? 'TWO_INTERNAL_ENVIRONMENTS' : 'TWO_INTERNAL_ENVIRONMENTS=' . $flag);
    }

    private static function assertResolveCase(array $case): void
    {
        StubStore::reset();
        self::setFlag($case['flag']);
        $stored = $case['stored'] ?? Twopayment::TWO_ENVIRONMENT_DEFAULT;
        StubStore::$configuration['PS_TWO_ENVIRONMENT'] = $stored;
        $module = new TwopaymentTestHarness();
        $description = $case['description'];

        TinyAssert::same($case['options'], Twopayment::getTwoEnvironmentOptions($stored), $description . ' (options)');
        TinyAssert::same($case['effective'], Twopayment::normaliseTwoEnvironment($stored), $description . ' (effective)');
        TinyAssert::same($case['api_host'], $module->getTwoCheckoutHostUrl(), $description . ' (API host)');
        TinyAssert::same($case['checkout_host'] . '/soletrader/signup', TwoSoleTrader::getSignupPageUrl(), $description . ' (checkout host)');
        TinyAssert::same($case['health_ok'], Twopayment::isTwoEnvironmentHealthy($stored), $description . ' (health)');
    }

    private static function assertSaveCase(array $case): void
    {
        self::setFlag($case['flag']);

        TinyAssert::same(
            $case['accepted'],
            Twopayment::isTwoEnvironmentSaveAllowed($case['submitted'], $case['stored']),
            $case['description']
        );
    }

    /** The dropdown rows come from getTwoEnvironmentOptions(), labelled, in order. */
    private static function testFormOffersTheOptionsInOrder(): void
    {
        $cases = [
            [null, 'sandbox', ['production' => 'Production', 'sandbox' => 'Sandbox'], 'outside Two infra: production then sandbox'],
            ['1', 'sandbox', ['production' => 'Production', 'sandbox' => 'Sandbox', 'staging' => 'Staging'], 'on Two infra: staging last'],
        ];

        foreach ($cases as list($flag, $stored, $expected, $description)) {
            StubStore::reset();
            self::setFlag($flag);
            StubStore::$configuration['PS_TWO_ENVIRONMENT'] = $stored;
            $rows = (new ReflectionMethod(Twopayment::class, 'getTwoEnvironmentOptionRows'))->invoke(new TwopaymentTestHarness());

            TinyAssert::same($expected, array_column($rows, 'name', 'id_option'), $description);
        }
    }

    /** The health row names the environment, or says what to do about it. */
    private static function testHealthRowLabels(): void
    {
        $cases = [
            ['production', null, 'Production', 'production is named'],
            ['sandbox', null, 'Sandbox', 'sandbox is named'],
            ['staging', '1', 'Staging', 'staging on Two infra is named'],
            ['staging', null, 'Staging (Two internal test environment). Select Sandbox or Production.', 'staging off Two infra says what to do'],
            ['garbage', null, 'Not recognised: Sandbox is in use. Select an environment and save.', 'an unknown value says sandbox is in use'],
        ];

        foreach ($cases as list($stored, $flag, $expected, $description)) {
            StubStore::reset();
            self::setFlag($flag);
            StubStore::$configuration['PS_TWO_ENVIRONMENT'] = $stored;
            $html = (string) (new ReflectionMethod(Twopayment::class, 'renderTwoPluginHealthChecklist'))->invoke(new TwopaymentTestHarness());

            TinyAssert::true(strpos($html, '</i> ' . $expected . '</span>') !== false, $description);
        }
    }
}
