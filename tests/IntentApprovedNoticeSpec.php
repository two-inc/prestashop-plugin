<?php

declare(strict_types=1);

/**
 * Coverage for the per-brand order-intent APPROVED notice - TWO-25218,
 * superseding the single three-state key shipped under TWO-25213.
 *
 * TWO KEYS, deliberately not one:
 *
 *   'intent_approved_notice_enabled' - the ON/OFF switch, explicit bool ONLY.
 *       true => ON, false => suppressed entirely, absent/null => documented
 *       default TRUE, anything else => a logged error and then TRUE.
 *
 *   'intent_approved_notice'         - the COPY OVERRIDE only.
 *       non-empty string => verbatim company-variant template (%s = company),
 *       absent/null/empty/whitespace-only => platform default copy. Empty is
 *       INERT: it is no longer an off switch, which is the one thing a reader
 *       who remembers TWO-25213 will get wrong.
 *
 * The two properties worth stating: absent can never mean off (so a
 * third-party overlay declaring nothing keeps the notice ON, and an older
 * cached JS payload cannot silently hide it), and a malformed switch is
 * reported rather than silently becoming a third behaviour. The DOM side of
 * the switch (both render sites) is not reachable from this PHP-only harness -
 * see the PR body.
 */
final class IntentApprovedNoticeSpec
{
    public static function runAll(): void
    {
        self::testExplicitBooleansAreHonoured();
        self::testAbsentSwitchMeansEnabled();
        self::testNonBooleanSwitchReportsAnErrorAndStaysEnabled();
        self::testCopyOverrideEmptyIsInert();
        self::testCopyOverrideNonEmptyIsVerbatim();
        self::testShippedTwoBrandIsEnabledWithDefaultCopy();
    }

    /**
     * @param mixed $configured
     * @return bool
     */
    private static function switchOf($configured, ?string &$error = null, string $brandCode = 'two'): bool
    {
        return Twopayment::normalizeIntentApprovedNoticeEnabled($configured, $error, $brandCode);
    }

    /**
     * @param mixed $configured
     * @return string|null
     */
    private static function copyOf($configured)
    {
        return Twopayment::normalizeIntentApprovedNotice($configured);
    }

    private static function testExplicitBooleansAreHonoured(): void
    {
        $error = null;
        TinyAssert::same(true, self::switchOf(true, $error), 'true must enable the notice');
        TinyAssert::same(null, $error, 'a valid true must not report an error');

        $error = null;
        TinyAssert::same(false, self::switchOf(false, $error), 'false must suppress the notice');
        TinyAssert::same(null, $error, 'a valid false must not report an error');
    }

    private static function testAbsentSwitchMeansEnabled(): void
    {
        // getTwoBrandConfig() returns null both for an absent key and for an
        // explicit null, so these are one input to the normalizer. Both are the
        // documented default: ON. This is what keeps a third-party overlay that
        // declares nothing from silently losing the notice.
        $error = null;
        TinyAssert::same(true, self::switchOf(null, $error), 'an absent switch must default to enabled');
        TinyAssert::same(null, $error, 'an absent switch is the documented default, not an error');
    }

    private static function testNonBooleanSwitchReportsAnErrorAndStaysEnabled(): void
    {
        // Every one of these was a plausible "off" under a truthiness check.
        // None of them may be honoured, and none may pass silently.
        $bad = array(
            'empty string' => '',
            'zero int' => 0,
            'one int' => 1,
            'string yes' => 'yes',
            'string false' => 'false',
            'array' => array(),
            'float' => 0.0,
        );

        foreach ($bad as $label => $value) {
            $error = null;
            TinyAssert::same(
                true,
                self::switchOf($value, $error),
                $label . ' must fall back to enabled, never to off'
            );
            TinyAssert::notSame(
                null,
                $error,
                $label . ' must report a clear error rather than pass silently'
            );
            TinyAssert::true(
                strpos((string) $error, 'intent_approved_notice_enabled') !== false,
                $label . ' error must name the offending key'
            );
            TinyAssert::true(
                strpos((string) $error, gettype($value)) !== false,
                $label . ' error must name the offending value type'
            );
            TinyAssert::true(
                strpos((string) $error, 'two') !== false,
                $label . ' error must name the brand code'
            );
        }

        // Deliberately a report-and-default, NOT a throw: this resolves on a
        // buyer-facing checkout render, where a white screen is worse than a
        // notice that stays on, and ON is the fail-safe direction.
        $error = null;
        TinyAssert::same(true, self::switchOf('nonsense', $error), 'a malformed switch must not throw');

        // The brand code is reported from the caller, not hardcoded.
        $error = null;
        self::switchOf('nonsense', $error, 'someoverlay');
        TinyAssert::true(
            strpos((string) $error, 'someoverlay') !== false,
            'the error must name the brand it came from'
        );
    }

    private static function testCopyOverrideEmptyIsInert(): void
    {
        // The TWO-25213 regression guard: '' used to suppress the notice. It is
        // now inert and resolves to the platform default copy. Nothing about
        // this key can turn the notice off.
        TinyAssert::same(null, self::copyOf(''), 'an empty copy override must be inert, not an off switch');
        TinyAssert::same(null, self::copyOf('   '), 'a whitespace-only copy override must be inert');
        TinyAssert::same(null, self::copyOf("\t\n "), 'any blank copy override must be inert');
        TinyAssert::same(null, self::copyOf(null), 'a null copy override must mean default copy');
        // A malformed overlay value must not become a copy template.
        TinyAssert::same(null, self::copyOf(false), 'false must mean default copy');
        TinyAssert::same(null, self::copyOf(0), '0 must mean default copy');
        TinyAssert::same(null, self::copyOf(array()), 'an array must mean default copy');
    }

    private static function testCopyOverrideNonEmptyIsVerbatim(): void
    {
        $template = 'Approved for %s pending final checks.';
        TinyAssert::same($template, self::copyOf($template), 'a non-empty override must pass through verbatim');
        // Verbatim means untouched, including surrounding whitespace inside a
        // string that is not blank.
        TinyAssert::same(' %s ', self::copyOf(' %s '), 'a non-blank override must not be trimmed');
    }

    private static function testShippedTwoBrandIsEnabledWithDefaultCopy(): void
    {
        $brand = (array) (require dirname(__DIR__) . '/brands/two.php');

        // Declared explicitly true rather than left to the absent-default, so
        // the brand file states the decision instead of implying it.
        TinyAssert::true(
            array_key_exists('intent_approved_notice_enabled', $brand),
            'brands/two.php must declare intent_approved_notice_enabled explicitly'
        );
        TinyAssert::same(
            true,
            $brand['intent_approved_notice_enabled'],
            'the Two default must be an explicit boolean true'
        );

        TinyAssert::true(
            array_key_exists('intent_approved_notice', $brand),
            'brands/two.php must declare intent_approved_notice'
        );
        TinyAssert::same(
            null,
            $brand['intent_approved_notice'],
            'the Two default copy override must be null - platform default copy'
        );

        $module = new TwopaymentTestHarness();
        PrestaShopLogger::reset();
        TinyAssert::same(
            true,
            $module->isIntentApprovedNoticeEnabled(),
            'the resolver must report the notice enabled for the shipped Two brand'
        );
        TinyAssert::count(
            0,
            PrestaShopLogger::$logs,
            'a valid shipped brand must not log anything'
        );
        TinyAssert::same(
            null,
            $module->getIntentApprovedNotice(),
            'the resolver must report the platform default copy for the shipped Two brand'
        );
    }
}
