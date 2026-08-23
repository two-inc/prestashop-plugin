<?php

declare(strict_types=1);

/**
 * Coverage for the "Validate tax subtotals" toggle - TWO-25502.
 *
 * PS_TWO_ENABLE_TAX_SUBTOTALS gates whether the per-tax-rate `tax_subtotals`
 * breakdown reaches /v1/order and /v1/order_intent. The breakdown itself is
 * computed unconditionally and reconciled against the order lines before any
 * payload is built, so this key controls transmission only, never the internal
 * gate.
 *
 * The caption is asserted verbatim because it is the whole user-visible
 * deliverable of TWO-25502 - the setting is named identically across the Two
 * plugins, and nothing else in the build would notice it drifting back to the
 * pre-rename wording.
 */
final class ValidateTaxSubtotalsSpec
{
    private const CONFIG_KEY = 'PS_TWO_ENABLE_TAX_SUBTOTALS';

    private const LABEL = 'Validate tax subtotals';

    public static function runAll(): void
    {
        self::testFreshInstallSeedsEnabled();
        self::testAbsentKeyResolvesToEnabled();
        self::testAdminFieldCarriesRenamedCaption();
    }

    private static function reset(): void
    {
        StubStore::reset();
        PrestaShopLogger::reset();
        Tools::resetTestValues();
    }

    /** @return array<string,mixed> */
    private static function orderManagementField(TwopaymentTestHarness $module): array
    {
        $method = new ReflectionMethod(Twopayment::class, 'getTwoOrderManagementForm');
        $form = $method->invoke($module);

        foreach ($form['form']['input'] as $field) {
            if (isset($field['name']) && $field['name'] === self::CONFIG_KEY) {
                return $field;
            }
        }

        throw new RuntimeException('The tax-subtotals switch is not rendered in Order Management.');
    }

    private static function testFreshInstallSeedsEnabled(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        $method = new ReflectionMethod(Twopayment::class, 'installTwoSettings');
        TinyAssert::true($method->invoke($module));

        TinyAssert::true(Configuration::hasKey(self::CONFIG_KEY));
        TinyAssert::same(1, Configuration::get(self::CONFIG_KEY));
    }

    /**
     * A shop whose row is missing must still send the breakdown: the read
     * default and the install default have to agree, or an install that
     * predates the key behaves opposite to a fresh one.
     */
    private static function testAbsentKeyResolvesToEnabled(): void
    {
        self::reset();
        $module = new TwopaymentTestHarness();

        TinyAssert::false(Configuration::hasKey(self::CONFIG_KEY));

        $method = new ReflectionMethod(Twopayment::class, 'shouldIncludeTaxSubtotals');
        TinyAssert::true($method->invoke($module));
    }

    private static function testAdminFieldCarriesRenamedCaption(): void
    {
        self::reset();
        $field = self::orderManagementField(new TwopaymentTestHarness());

        TinyAssert::same(self::LABEL, $field['label']);
        TinyAssert::same('switch', $field['type']);
        TinyAssert::true($field['is_bool']);
    }
}
