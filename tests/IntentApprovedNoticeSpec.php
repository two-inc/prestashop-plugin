<?php

declare(strict_types=1);

/**
 * Coverage for the per-brand order-intent APPROVED notice switch - TWO-25213.
 *
 * brands/two.php 'intent_approved_notice' has three states, and the checkout
 * JS distinguishes them by the value it is handed:
 *
 *   null (or key absent) - platform default translated copy, notice ON
 *   ''                   - notice suppressed entirely
 *   non-empty string     - verbatim company-variant template (%s = company)
 *
 * The important property is that absent can never mean off, which is why
 * anything non-string normalizes to null rather than to ''. The DOM side of
 * the switch (both render sites) is not reachable from this PHP-only harness -
 * see the PR body.
 */
final class IntentApprovedNoticeSpec
{
    public static function runAll(): void
    {
        self::testAbsentOrNullMeansPlatformDefault();
        self::testEmptyStringMeansSuppressed();
        self::testNonEmptyStringIsVerbatimTemplate();
        self::testShippedTwoBrandDefaultsToNotice();
    }

    private static function normalize($configured)
    {
        return Twopayment::normalizeIntentApprovedNotice($configured);
    }

    private static function testAbsentOrNullMeansPlatformDefault(): void
    {
        // getTwoBrandConfig() returns null both for an absent key and for an
        // explicit null, so these are the same input to the normalizer.
        TinyAssert::same(null, self::normalize(null), 'null must mean platform default copy');
        // A malformed overlay value must not be able to switch the notice off.
        TinyAssert::same(null, self::normalize(false), 'false must mean platform default copy');
        TinyAssert::same(null, self::normalize(0), '0 must mean platform default copy');
        TinyAssert::same(null, self::normalize(array()), 'array must mean platform default copy');
    }

    private static function testEmptyStringMeansSuppressed(): void
    {
        TinyAssert::same('', self::normalize(''), 'empty string must suppress the notice');
        TinyAssert::same('', self::normalize('   '), 'whitespace-only must suppress the notice');
    }

    private static function testNonEmptyStringIsVerbatimTemplate(): void
    {
        $template = 'Approved for %s pending final checks.';
        TinyAssert::same($template, self::normalize($template), 'a non-empty override must pass through verbatim');
        // Verbatim means untouched, including surrounding whitespace inside a
        // string that is not blank.
        TinyAssert::same(' %s ', self::normalize(' %s '), 'a non-blank override must not be trimmed');
    }

    private static function testShippedTwoBrandDefaultsToNotice(): void
    {
        $brand = require dirname(__DIR__) . '/brands/two.php';
        TinyAssert::true(
            array_key_exists('intent_approved_notice', (array) $brand),
            'brands/two.php must declare intent_approved_notice'
        );
        TinyAssert::same(
            null,
            $brand['intent_approved_notice'],
            'the Two default must be null - notice ON with the platform copy'
        );

        $module = new TwopaymentTestHarness();
        TinyAssert::same(
            null,
            $module->getIntentApprovedNotice(),
            'the resolver must report the platform default for the shipped Two brand'
        );
    }
}
