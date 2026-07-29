<?php

declare(strict_types=1);

/**
 * Coverage for how the company search establishes which country to search.
 *
 * The register searched is decided entirely by one value, and until now
 * TwoCompanySearch.getCurrentCountry() would invent it: a chain of hardcoded
 * maps ending in navigator.language and then a literal 'GB'. Any shop that
 * missed every map searched GB companies for every buyer, on every keystroke,
 * with nothing on screen saying so.
 *
 * The fix has a PHP half and a JS half, and these tests pin the seam between
 * them - the part no Jest test can see:
 *
 *  - the module still injects `countries`, the complete id_country -> ISO map
 *    built from this shop's own country table. That map is now the search's
 *    authoritative source, not a nice-to-have for the order-intent module, so
 *    dropping it from the addJsDef payload would silently return the search to
 *    guessing;
 *  - the i18n key the JS reads for the "pick a country" row exists in the
 *    payload under exactly that name. A rename on either side is invisible at
 *    runtime: the JS falls back to hardcoded English, so a merchant's
 *    translated checkout quietly stops being translated;
 *  - the two guesses are gone from the JS for good. Asserted against the
 *    source rather than through behaviour, because the reachable-in-jsdom
 *    behaviour is only a subset - a re-added `'GB'` on a path no test builds a
 *    DOM for would pass every Jest case and still ship the defect.
 */
final class CompanySearchCountrySourcingSpec
{
    public static function runAll(): void
    {
        self::testCountryIsoMapIsInjected();
        self::testSelectCountryCopyKeyMatchesTheKeyTheJsReads();
        self::testJsNoLongerGuessesTheCountry();
    }

    private static function moduleSource(): string
    {
        $source = file_get_contents(dirname(__DIR__) . '/twopayment.php');
        TinyAssert::true(is_string($source), 'Unreadable twopayment.php');

        return (string) $source;
    }

    private static function searchJsSource(): string
    {
        $path = dirname(__DIR__) . '/views/js/modules/TwoCompanySearch.js';
        $source = file_get_contents($path);
        TinyAssert::true(is_string($source), 'Unreadable ' . $path);

        return (string) $source;
    }

    /**
     * Strip comment lines so a prose explanation of a removed guess does not
     * read as the guess itself - the WHY comments deliberately name both.
     */
    private static function codeLines(string $source): array
    {
        $kept = array();
        foreach (explode("\n", $source) as $number => $line) {
            if (preg_match('#^\s*(//|\*|/\*)#', $line) === 1) {
                continue;
            }
            $kept[$number + 1] = $line;
        }

        return $kept;
    }

    private static function testCountryIsoMapIsInjected(): void
    {
        $source = self::moduleSource();

        // Built from Country::getCountries() for THIS install, so it covers
        // every country the shop has rather than a handful of ids guessed at
        // in JavaScript. PrestaShop country ids are table rows, not constants.
        TinyAssert::true(
            strpos($source, "\$param_countries[\$country['id_country']] = Tools::strtolower(\$country['iso_code']);") !== false,
            'The id_country -> ISO map is no longer built from the shop country table'
        );
        TinyAssert::true(
            strpos($source, "'countries' => \$param_countries,") !== false,
            'The countries map is no longer injected; the company search would fall back to guessing'
        );
    }

    private static function testSelectCountryCopyKeyMatchesTheKeyTheJsReads(): void
    {
        $key = 'company_search_select_country';

        TinyAssert::true(
            strpos(self::moduleSource(), "'" . $key . "' => \$this->l(") !== false,
            'Missing translatable copy for the "pick a country" row: ' . $key
        );
        TinyAssert::true(
            strpos(self::searchJsSource(), 'window.twopayment.i18n.' . $key) !== false,
            'The search JS no longer reads ' . $key . '; the PHP copy is dead and the row is untranslated'
        );
    }

    private static function testJsNoLongerGuessesTheCountry(): void
    {
        $lines = self::codeLines(self::searchJsSource());

        // The browser locale is never a legitimate source for this anywhere in
        // the file: it describes the buyer's laptop, not the shop's country or
        // the company's.
        foreach ($lines as $number => $line) {
            TinyAssert::true(
                strpos($line, 'navigator.language') === false
                && strpos($line, 'navigator.userLanguage') === false,
                'The company search guesses the country from the browser locale again, at '
                . 'views/js/modules/TwoCompanySearch.js:' . $number . ' - ' . trim($line)
            );
        }

        // A hardcoded 'GB' is checked only inside getCurrentCountry(), because
        // it is legitimate below that: extractCountryFromText() maps the country
        // NAME "United Kingdom" to it, which is a resolution, not a default.
        $start = null;
        $end = null;
        foreach ($lines as $number => $line) {
            if ($start === null && strpos($line, 'getCurrentCountry() {') !== false) {
                $start = $number;
                continue;
            }
            if ($start !== null && strpos($line, 'extractCountryFromText(text) {') !== false) {
                $end = $number;
                break;
            }
        }
        TinyAssert::true(
            $start !== null && $end !== null,
            'Could not locate getCurrentCountry() - this test has drifted from the source it guards'
        );

        foreach ($lines as $number => $line) {
            if ($number <= $start || $number >= $end) {
                continue;
            }
            TinyAssert::true(
                strpos($line, "'GB'") === false && strpos($line, '"GB"') === false,
                'A hardcoded GB default is back in getCurrentCountry(), at '
                . 'views/js/modules/TwoCompanySearch.js:' . $number . ' - ' . trim($line)
            );
        }
    }
}
