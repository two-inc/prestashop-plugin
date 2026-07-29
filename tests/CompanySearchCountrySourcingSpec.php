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
 *  - the media hook still builds `countries`, the complete id_country -> ISO
 *    map from this shop's own country table, AND still injects it in the same
 *    method that registers the search script. That map is now the search's
 *    authoritative source, not a nice-to-have for the order-intent module, so
 *    moving it to a method nothing calls, or dropping it from the addJsDef
 *    payload, would silently return the search to guessing;
 *  - the i18n key the JS reads for the "pick a country" row exists in the
 *    payload under exactly that name. A rename on either side is invisible at
 *    runtime: the JS falls back to hardcoded English, so a merchant's
 *    translated checkout quietly stops being translated;
 *  - the two guesses are gone from getCurrentCountry() for good. Asserted
 *    against the source rather than through behaviour, because the
 *    reachable-in-jsdom behaviour is only a subset - a re-added GB literal on
 *    a path no test builds a DOM for would pass every Jest case and still ship
 *    the defect. The source check is textual, so it catches a re-added literal
 *    whatever quoting style it uses, but not a value assembled at runtime.
 */
final class CompanySearchCountrySourcingSpec
{
    public static function runAll(): void
    {
        self::testCountryIsoMapIsInjectedByTheMediaHook();
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
     * Strip comments so a prose explanation of a removed guess does not read as
     * the guess itself - the WHY comments deliberately name both. Handles `//`
     * lines, docblock continuations and multi-line `/* ... *\/` blocks whose
     * continuation lines start with an ordinary word.
     *
     * Keys are 1-based source line numbers and survive the stripping, so a
     * failure message can point at the real line.
     *
     * @return array<int, string>
     */
    private static function codeLines(string $source): array
    {
        $kept = array();
        $in_block_comment = false;

        foreach (explode("\n", $source) as $index => $line) {
            $number = $index + 1;

            if ($in_block_comment) {
                $close = strpos($line, '*/');
                if ($close === false) {
                    continue;
                }
                $in_block_comment = false;
                $line = substr($line, $close + 2);
            }

            $trimmed = ltrim($line);
            if ($trimmed === '' || strpos($trimmed, '//') === 0 || strpos($trimmed, '*') === 0) {
                continue;
            }

            $line = (string) preg_replace('#/\*.*?\*/#', '', $line);
            $open = strpos($line, '/*');
            if ($open !== false) {
                $in_block_comment = true;
                $line = substr($line, 0, $open);
            }

            if (trim($line) === '') {
                continue;
            }
            $kept[$number] = $line;
        }

        return $kept;
    }

    /**
     * Slice one function body out of already-comment-stripped lines.
     *
     * The body ends at the first following line that is nothing but the closing
     * brace at the declaration's own indentation. Anchoring on the declaration's
     * own brace rather than on whatever member happens to come next keeps the
     * slice stable when members are reordered - a no-op edit must not fail a
     * spec.
     *
     * @param array<int, string> $lines
     *
     * @return array<int, string> body lines, keyed by source line number
     */
    private static function functionBody(array $lines, string $signature, string $where): array
    {
        $indent = null;
        $body = array();

        foreach ($lines as $number => $line) {
            if ($indent === null) {
                if (strpos($line, $signature) === false) {
                    continue;
                }
                $indent = substr($line, 0, strlen($line) - strlen(ltrim($line)));
                continue;
            }
            if (rtrim($line) === $indent . '}') {
                return $body;
            }
            $body[$number] = $line;
        }

        TinyAssert::true(
            false,
            'Could not slice ' . $signature . ' out of ' . $where
            . ' - this test has drifted from the source it guards'
        );

        return $body;
    }

    private static function testCountryIsoMapIsInjectedByTheMediaHook(): void
    {
        $hook = 'hookActionFrontControllerSetMedia';
        $body = self::functionBody(
            self::codeLines(self::moduleSource()),
            'public function ' . $hook . '()',
            'twopayment.php'
        );
        $body_text = implode("\n", $body);

        // Built from Country::getCountries() for THIS install, so it covers
        // every country the shop has rather than a handful of ids guessed at
        // in JavaScript. PrestaShop country ids are table rows, not constants.
        // Matched loosely: a cast, a row-variable rename or a line wrap is not
        // a regression, so only the two column names are pinned.
        TinyAssert::true(
            preg_match('#\$param_countries\b.{0,160}id_country.{0,160}iso_code#s', $body_text) === 1,
            'The id_country -> ISO map is no longer built from the shop country table inside ' . $hook . '()'
        );

        // Presence anywhere in the file proves nothing: the map is only alive
        // if it is injected by the hook the front controller actually calls,
        // behind the same early-return gate as the script that consumes it.
        TinyAssert::true(
            strpos($body_text, 'Media::addJsDef(') !== false
            && strpos($body_text, "'countries' => \$param_countries") !== false,
            'The countries map is no longer injected by ' . $hook
            . '() - moved or dropped, the company search falls back to guessing'
        );
        TinyAssert::true(
            strpos($body_text, "registerJavascript('two-company-search'") !== false,
            'The company search script is no longer registered by ' . $hook
            . '(), so it no longer shares the gate that injects its country map'
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
        $path = 'views/js/modules/TwoCompanySearch.js';
        $lines = self::codeLines(self::searchJsSource());

        // The browser locale is never a legitimate source for this anywhere in
        // the file: it describes the buyer's laptop, not the shop's country or
        // the company's.
        foreach ($lines as $number => $line) {
            TinyAssert::true(
                strpos($line, 'navigator.language') === false
                && strpos($line, 'navigator.userLanguage') === false,
                'The company search guesses the country from the browser locale again, at '
                . $path . ':' . $number . ' - ' . trim($line)
            );
        }

        // A hardcoded GB is checked only inside getCurrentCountry(), because it
        // is legitimate below that: extractCountryFromText() maps the country
        // NAME "United Kingdom" to it, which is a resolution, not a default.
        // Quote style is not assumed - a backtick template literal is the same
        // defect as a single-quoted one.
        $body = self::functionBody($lines, 'getCurrentCountry() {', $path);
        foreach ($body as $number => $line) {
            TinyAssert::true(
                preg_match('#\bGB\b#', $line) !== 1,
                'A hardcoded GB default is back in getCurrentCountry(), at '
                . $path . ':' . $number . ' - ' . trim($line)
            );
        }
    }
}
