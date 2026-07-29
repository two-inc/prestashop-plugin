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
 *    moving it OUT OF THIS METHOD, or dropping it from the addJsDef payload,
 *    would silently return the search to guessing. The assertion is that
 *    tight on purpose: it fails even for a behaviour-preserving extraction
 *    into a helper the hook still calls, because whether the map survives the
 *    hook's early-return gate is exactly what it guards;
 *  - the i18n key the JS reads for the "pick a country" row exists in the
 *    payload under exactly that name. A rename on either side is invisible at
 *    runtime: the JS falls back to hardcoded English, so a merchant's
 *    translated checkout quietly stops being translated;
 *  - the two guesses are gone for good. Asserted against the source rather
 *    than through behaviour, because the reachable-in-jsdom behaviour is only a
 *    subset - a re-added GB literal on a path no test builds a DOM for would
 *    pass every Jest case and still ship the defect. Both defects are asserted
 *    as SHAPES over the whole comment-stripped file rather than over a sliced
 *    method body: the defect is a RETURN of a hardcoded country, and no
 *    legitimate `return '<literal>'` of a country code exists anywhere in the
 *    file (extractCountryFromText() maps country NAMES to 'GB' in a plain map
 *    entry, which is a resolution and not a return). Whole-file shape checks
 *    cost nothing in precision here and cannot be defeated by gutting,
 *    reordering or re-indenting a method. They are textual, so they catch a
 *    re-added literal in any quoting style but not a value assembled at
 *    runtime; a gutted getCurrentCountry() is caught separately, by asserting
 *    that the two real resolution sources it reads are still read at all.
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
     * Strip LEADING comment lines so a prose explanation of a removed guess
     * does not read as the guess itself - the WHY comments deliberately name
     * both. Handles lines that START with `//`, docblock continuation lines
     * that start with `*`, and multi-line `/* ... *\/` blocks whose
     * continuation lines start with an ordinary word.
     *
     * A trailing `//` comment on a code line is NOT stripped, so a
     * `// ... GB ...` tacked onto the end of real code would false-fail. None
     * exist in either guarded file; if one is ever added, strip it here rather
     * than renaming the guess. Trailing `/* ... *\/` blocks and a trailing
     * `/*` opener ARE stripped, below.
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
     * Slice one PHP method body out of already-comment-stripped lines.
     *
     * PHP-only by design: the JS assertions deliberately do not slice, so this
     * only ever has to cope with PSR-12 brace-on-its-own-next-line style.
     *
     * The body ends at the first following line that is nothing but the closing
     * brace at the declaration's own indentation. Anchoring on the declaration's
     * own brace rather than on whatever member happens to come next keeps the
     * slice stable when members are reordered - a no-op edit must not fail a
     * spec.
     *
     * There is deliberately NO empty-slice guard. Brace-on-next-line style puts
     * the opening `{` into the collected body, so the slice is never empty and
     * such a guard could never fire. A gutted hook is caught instead by the
     * caller's own map and injection assertions, which fail on a body that no
     * longer contains them - those two assertions are the only thing protecting
     * this slice, and that is enough for what it guards.
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
        // Anchored on the SUBSCRIPT - `$param_countries[<...id_country...>] =
        // <...iso_code...>` - so that building the map into some other
        // variable, leaving $param_countries an empty array, fails here. A
        // co-occurrence check would not: the dead-map build still mentions
        // both column names. Still tolerant of a cast, a row-variable rename
        // or a line wrap, which are not regressions - the line-wrap tolerance
        // comes from the negated character classes, which match newlines.
        TinyAssert::true(
            preg_match('#\$param_countries\[[^\]]*id_country[^\]]*\][^;]{0,120}iso_code#', $body_text) === 1,
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

        // The defect being guarded is a RETURN of a hardcoded country, so that
        // is the shape asserted, over the whole file. No method slicing: the
        // one legitimate GB in this file is a map entry in
        // extractCountryFromText() ('united kingdom' => 'GB'), which resolves a
        // country NAME and is not a return, so it needs no carved-out range.
        // Checking the shape instead of a line range makes this immune to the
        // whole class of slicing bug - a gutted, re-indented, one-line-brace or
        // reordered method cannot make it pass by accident. Quote style is not
        // assumed: a backtick template literal is the same defect as a
        // single-quoted one. Line wraps between `return` and the literal are
        // tolerated because the negated class matches newlines.
        $code = implode("\n", $lines);
        $found = array();
        TinyAssert::true(
            preg_match('#\breturn\b[^;]*([\'"`])GB\1#', $code, $found) !== 1,
            'A hardcoded GB default is returned again in ' . $path
            . ' - ' . trim((string) (isset($found[0]) ? $found[0] : ''))
        );

        // Gutting getCurrentCountry() would satisfy every assertion above by
        // containing nothing at all, so assert that the two authoritative
        // sources it resolves from are still read. Both strings appear only
        // inside that method, so losing either means the resolution chain is
        // gone - which is the same defect as guessing, arrived at differently.
        TinyAssert::true(
            strpos($code, "getAttribute('data-iso-code')") !== false,
            'The country select\'s own ISO code is no longer read in ' . $path
            . ' - getCurrentCountry() has lost its first-choice resolution source'
        );
        TinyAssert::true(
            strpos($code, 'window.twopayment.countries[') !== false,
            'The server-injected id_country -> ISO map is no longer read in ' . $path
            . ' - getCurrentCountry() has lost its authoritative resolution source'
        );
    }
}
