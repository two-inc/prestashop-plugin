<?php

declare(strict_types=1);

/**
 * The "no company selected" refusal at payment submit is worded identically
 * across the Two plugin fleet; the wording is a product decision, not a
 * per-platform one, so drift here is a regression rather than a rephrasing.
 *
 * TranslationCatalogueSpec already gates presence, emptiness and sprintf-token
 * parity for every string. What it cannot see is a catalogue row that simply
 * repeats the English source: perfectly well-formed, and a silent regression to
 * English for that locale.
 */
final class CompanyRefusalMessageSpec
{
    private const MESSAGE = 'Please select your company before paying with %s.';

    private const PREFIX = '<{twopayment}prestashop>';

    /** @var array<int, array{0: string, 1: string}> iso code, description for the assertion message */
    private const GATED_LOCALES = [
        ['nl', 'Dutch'],
        ['no', 'Norwegian'],
        ['sv', 'Swedish'],
    ];

    public static function runAll(): void
    {
        self::testSourceStringIsTheFleetWording();

        foreach (self::GATED_LOCALES as [$iso, $description]) {
            self::testLocaleTranslatesTheRefusal($iso, $description);
        }
    }

    private static function testSourceStringIsTheFleetWording(): void
    {
        $path = dirname(__DIR__) . '/controllers/front/payment.php';
        $contents = (string) file_get_contents($path);
        $needle = "->l('" . self::MESSAGE . "')";

        $occurrences = substr_count($contents, $needle);
        if ($occurrences !== 1) {
            throw new RuntimeException(sprintf(
                'controllers/front/payment.php has %d call site(s) for the company refusal message, expected '
                . 'exactly 1 reading: %s',
                $occurrences,
                $needle
            ));
        }
    }

    private static function testLocaleTranslatesTheRefusal(string $iso, string $description): void
    {
        $key = self::PREFIX . 'twopayment_' . md5(self::MESSAGE);

        $_MODULE = [];
        require dirname(__DIR__) . '/translations/' . $iso . '.php';

        $value = isset($_MODULE[$key]) ? stripslashes((string) $_MODULE[$key]) : '';

        if ($value === '' || $value === self::MESSAGE) {
            throw new RuntimeException(sprintf(
                'translations/%s.php (%s) does not translate the company refusal message — row %s is %s, so '
                . '%s buyers see English.',
                $iso,
                $description,
                $key,
                $value === '' ? 'absent or empty' : 'a copy of the English source',
                $description
            ));
        }
    }
}
