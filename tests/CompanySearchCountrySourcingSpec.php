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
 *    reordering or re-indenting a method. They are textual, so what they catch
 *    is a country literal in the RETURN EXPRESSION itself, in any quoting
 *    style. A literal reached through a variable or a method call is NOT
 *    caught - `const FALLBACK = 'GB'; return FALLBACK;` walks through, and so
 *    does `return this.defaultCountry()`. The guard is narrower than the
 *    defect space on purpose: it pins the shape the defect actually had, and
 *    the behaviour itself is pinned by Jest. A gutted getCurrentCountry() is
 *    caught separately, by asserting that the two real resolution sources it
 *    reads are still read at all.
 */
final class CompanySearchCountrySourcingSpec
{
    public static function runAll(): void
    {
        self::testCountryIsoMapIsInjectedByTheMediaHook();
        self::testSearchCountryIsInjectedByTheMediaHook();
        self::testBillingCountryResolvesFromTheCartsInvoiceAddress();
        self::testBillingCountryIsNeverTheDeliveryAddress();
        self::testCheckoutSearchCountryFallsBackToTheDeliveryAddress();
        self::testJsReadsTheInjectedSearchCountry();
        self::testDropdownCopyKeysMatchTheKeysTheJsReads();
        self::testClearCompanyActionSeam();
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
     * A trailing `//` comment on a code line is NOT stripped. That only matters
     * for the `navigator.language` sweep, which is a plain substring match: a
     * `// ... navigator.language ...` tacked onto the end of real code would
     * false-fail there. It does NOT affect the GB check, which needs a quoted
     * country code inside a `return` with no intervening `;`, so both
     * `return ''; // no GB fallback` and `// used to be 'GB'` pass. None of
     * either exist in the guarded files today; if one is ever added, strip it
     * here rather than renaming the guess. Trailing `/* ... *\/` blocks and a
     * trailing `/*` opener ARE stripped, below.
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

    /**
     * The cart's billing-address country must be injected by the SAME hook,
     * behind the SAME early-return gate, as the search script that reads it -
     * TWO-25326 §7.1 follow-up.
     *
     * Same reasoning as the countries map above, and for a sharper reason:
     * this is the ONLY country source available to the company search once the
     * control has moved into the payment tile. PrestaShop renders the address
     * FORM - and therefore `select[name='id_country']`, everything
     * getCurrentCountry() could otherwise read - only while the buyer is
     * editing an address; on the payment step it renders an address SELECTOR
     * instead. Drop this key, or move it out from behind this gate, and the
     * tile-mounted search silently stops searching on every keystroke, which
     * is exactly the state Doug found live.
     */
    private static function testSearchCountryIsInjectedByTheMediaHook(): void
    {
        $hook = 'hookActionFrontControllerSetMedia';
        $body = self::functionBody(
            self::codeLines(self::moduleSource()),
            'public function ' . $hook . '()',
            'twopayment.php'
        );
        $body_text = implode("\n", $body);

        TinyAssert::true(
            strpos($body_text, "'company_search_country' => \$this->getCheckoutSearchCountryIso()") !== false,
            'The company-search country is no longer injected by ' . $hook
            . '() - the payment-tile company search has no country to search with'
        );
    }

    /**
     * And the resolver behind that key answers from the cart's own invoice
     * address, or not at all.
     *
     * "Or not at all" is the load-bearing half: a shop-country or geolocation
     * fallback here would search a register the buyer's company is not in,
     * with nothing on screen saying which one was used - the same defect the
     * removed `navigator.language` / `'GB'` guesses had, one layer down.
     */
    private static function testBillingCountryResolvesFromTheCartsInvoiceAddress(): void
    {
        StubStore::reset();
        Tools::resetTestValues();
        $module = new TwopaymentTestHarness();
        $method = new ReflectionMethod(Twopayment::class, 'getCheckoutBillingCountryIso');

        $cart = Context::getContext()->cart;

        // No billing address yet: resolves to empty, never to a fallback.
        $cart->id_address_invoice = 0;
        TinyAssert::same('', $method->invoke($module));

        // A billing address whose country the shop knows.
        StubStore::$addresses[8801] = ['id_country' => 44];
        StubStore::$countries[44] = 'gb';
        $cart->id_address_invoice = 8801;
        // Upper-cased: the API wants the ISO code upper, and PrestaShop's own
        // country table stores it lower.
        TinyAssert::same('GB', $method->invoke($module));

        // An address row that does not exist, and a country id the shop's
        // table does not carry: both resolve to empty rather than guessing.
        $cart->id_address_invoice = 9999;
        TinyAssert::same('', $method->invoke($module));

        StubStore::$addresses[8802] = ['id_country' => 4242];
        $cart->id_address_invoice = 8802;
        TinyAssert::same('', $method->invoke($module));
    }

    /**
     * The INVOICE address, and never the delivery one (TWO-40 #13).
     *
     * This resolver is the billing-only one, and its callers depend on that:
     * getTwoBrowserCompanySelection() invalidates a stored company selection by
     * comparing its country against this, and the sole-trader entry point asks
     * it whether enrolment is offered at all. Both are questions about the
     * address the order will be BILLED to, so a delivery-address fallback here
     * would validate a company against an address it is not billed to. The
     * wider chain the checkout JS gets is a separate method - see
     * testCheckoutSearchCountryFallsBackToTheDeliveryAddress().
     *
     * A shop where the buyer ships and bills to different countries is the only
     * one that can tell the two sourcings apart.
     *
     * Asserted with both addresses set to DIFFERENT countries, so the answer
     * identifies which field was read, and then with only a delivery address, so
     * a fallback to it cannot hide behind an unset invoice address.
     */
    private static function testBillingCountryIsNeverTheDeliveryAddress(): void
    {
        StubStore::reset();
        Tools::resetTestValues();
        $module = new TwopaymentTestHarness();
        $method = new ReflectionMethod(Twopayment::class, 'getCheckoutBillingCountryIso');

        StubStore::$countries[44] = 'gb';
        StubStore::$countries[45] = 'fr';
        StubStore::$addresses[8811] = ['id_country' => 44];
        StubStore::$addresses[8812] = ['id_country' => 45];

        $cart = Context::getContext()->cart;
        $cart->id_address_invoice = 8811;
        $cart->id_address_delivery = 8812;

        TinyAssert::same(
            'GB',
            $method->invoke($module),
            'the billing country handed to the JS came from the DELIVERY address'
        );

        // Only a delivery address on the cart: empty, not the delivery country.
        $cart->id_address_invoice = 0;
        TinyAssert::same(
            '',
            $method->invoke($module),
            'the delivery address answered as a fallback for the billing country'
        );
    }

    /**
     * The resolver actually injected for the checkout JS: the billing address,
     * then the shipping address, then nothing.
     *
     * The tile-mounted search has no other country source - the payment step
     * renders an address selector, not the address form - so a cart whose
     * billing address resolves to no ISO code would leave the search declining
     * on every keystroke. The shipping address is a country the buyer supplied,
     * which is what makes it an answer rather than the guess this whole chain
     * exists to refuse; when neither answers, the payment option is withheld
     * outright (BuyerCountryGateSpec pins that half).
     *
     * The unresolvable-billing fixture is an address carrying a country id the
     * shop's country table does not answer for - a deleted country row - which
     * is the state that reaches the fallback: an address with NO country id at
     * all is refused several gates earlier, by TWO-25387's module_country check.
     */
    private static function testCheckoutSearchCountryFallsBackToTheDeliveryAddress(): void
    {
        StubStore::reset();
        Tools::resetTestValues();
        $module = new TwopaymentTestHarness();
        $method = new ReflectionMethod(Twopayment::class, 'getCheckoutSearchCountryIso');

        StubStore::$countries[44] = 'gb';
        StubStore::$countries[45] = 'fr';
        StubStore::$addresses[8821] = ['id_country' => 44];
        StubStore::$addresses[8822] = ['id_country' => 45];
        // Exists, carries a country id, and that id resolves to no ISO code.
        StubStore::$addresses[8823] = ['id_country' => 4242];

        $cart = Context::getContext()->cart;

        $cart->id_address_invoice = 8821;
        $cart->id_address_delivery = 8822;
        TinyAssert::same(
            'GB',
            $method->invoke($module),
            'the shipping address answered while the billing address had a country'
        );

        $cart->id_address_invoice = 8823;
        TinyAssert::same(
            'FR',
            $method->invoke($module),
            'an unresolvable billing country did not fall back to the shipping address'
        );

        $cart->id_address_delivery = 8823;
        TinyAssert::same(
            '',
            $method->invoke($module),
            'a country was invented when neither address could answer'
        );
    }

    /**
     * And the browser side reads that exact key. A rename on either side is
     * invisible at runtime - the JS simply resolves no country and stops
     * searching, which reads as a broken search rather than as a broken
     * contract. Same class of silent failure as the i18n-key check below.
     *
     * The read is a snapshot, so all three links are pinned: payload off
     * `window.twopayment`, `company_search_country` off the payload, snapshot field
     * read by the country resolver.
     */
    private static function testJsReadsTheInjectedSearchCountry(): void
    {
        $js = implode("\n", self::codeLines(self::searchJsSource()));

        $binding = array();
        TinyAssert::true(
            preg_match('#searchCountry:\s*([A-Za-z_$][\w$]*).company_search_country\b#', $js, $binding) === 1,
            'TwoCompanySearch must read the server-injected company_search_country key - it is the '
            . 'payment-tile search\'s only country source'
        );

        $payload = $binding[1];
        TinyAssert::true(
            preg_match('#\b' . preg_quote($payload, '#') . '\s*=[^;]*window\.twopayment\b#', $js) === 1,
            'company_search_country must be read from `' . $payload . '`, the window.twopayment payload '
            . 'the media hook injects'
        );

        TinyAssert::true(
            strpos($js, 'this._page.searchCountry') !== false,
            'getCurrentCountry() must consult the snapshotted search country, or the '
            . 'payment-tile search resolves none and stops searching'
        );
    }

    /**
     * Every dropdown copy key must exist on BOTH sides under the same name.
     *
     * This is the only gate that can catch it. The Jest suite stubs
     * `window.twopayment.i18n` itself, so a PHP key renamed to a typo leaves it
     * perfectly green while the shipped row falls back to its English literal for
     * good - a row that looks entirely correct in English and is permanently
     * untranslated everywhere else, which is the exact failure this ticket exists
     * to remove.
     *
     * A loop rather than one case per key, deliberately: a new row added to that
     * dropdown must be added HERE, and a list is the shape that makes the
     * omission obvious. The manual-entry pair is TWO-25288 element 5.
     */
    private static function testDropdownCopyKeysMatchTheKeysTheJsReads(): void
    {
        $keys = [
            'company_search_select_country' => 'the "pick a country" row',
            'company_search_manual_entry' => 'the manual-entry row',
            'company_search_back_to_search' => 'the back-to-search link',
        ];

        $js = implode("\n", self::codeLines(self::searchJsSource()));

        // The JS asks for each key through one accessor rather than reaching for
        // the payload at each call site, so the seam is asserted once - that the
        // accessor resolves from the injected `i18n` payload - and then each key
        // is asserted to be a key it is actually asked for.
        $binding = array();
        TinyAssert::true(
            preg_match('#i18n:\s*([A-Za-z_$][\w$]*)\.i18n\b#', $js, $binding) === 1,
            'The search JS must take the injected i18n payload, or every dropdown row falls '
            . 'back to its English literal for good'
        );
        $payload = $binding[1];
        TinyAssert::true(
            preg_match('#\b' . preg_quote($payload, '#') . '\s*=[^;]*window\.twopayment\b#', $js) === 1,
            'The i18n copy must be read from `' . $payload . '`, the window.twopayment payload '
            . 'the media hook injects'
        );
        TinyAssert::true(
            preg_match('#\btext\s*\(\s*key\s*,[^)]*\)\s*\{[^}]*_page\.i18n\[\s*key\s*\]#s', $js) === 1
            || preg_match('#return\s+this\._page\.i18n\[\s*key\s*\]#', $js) === 1,
            'The copy accessor must resolve keys against the injected i18n payload'
        );

        foreach ($keys as $key => $description) {
            TinyAssert::true(
                strpos(self::moduleSource(), "'" . $key . "' => \$this->l(") !== false,
                'Missing translatable copy for ' . $description . ': ' . $key
            );
            TinyAssert::true(
                preg_match('#\.text\(\s*[\'"]' . preg_quote($key, '#') . '[\'"]#', $js) === 1,
                'The search JS no longer reads ' . $key . ' (' . $description
                . '); the PHP copy is dead and the row is permanently untranslated'
            );
        }
    }

    /**
     * The endpoint action the browser posts to forget the session company must
     * exist on the controller under exactly that name (TWO-25288).
     *
     * Same seam, same invisibility as the copy keys above: an action name that
     * agrees on neither side fails SILENTLY. The browser fires and forgets, the
     * controller falls through its switch, and the stale session company - which
     * the order payload consults ahead of the address - survives. The buyer then
     * has the company they explicitly disowned credit-checked at placement. No
     * Jest test can see this; the JS suite stubs the transport.
     *
     * SCOPE: the name agreement across the two languages, and nothing else. What
     * the action DOES - which keys it empties, that it refuses a bad token, that
     * the switch actually dispatches it - is driven in SessionCompanyClearSpec
     * and must stay there. This test used to assert those too, by grepping the
     * controller for `case 'clearCompany':` and for each `unset(...)` literal, and
     * that is precisely what a source grep cannot do: an early `return` above the
     * unsets left every grepped literal in place and the suite stayed green, and
     * inverting the token guard passed identically. A grep pins spelling; only
     * execution pins behaviour.
     */
    private static function testClearCompanyActionSeam(): void
    {
        $action = 'clearCompany';
        $controller = file_get_contents(dirname(__DIR__) . '/controllers/front/orderintent.php');
        TinyAssert::true(is_string($controller), 'Unreadable controllers/front/orderintent.php');
        $controller = (string) $controller;

        TinyAssert::true(
            strpos(self::searchJsSource(), "action: '" . $action . "'") !== false,
            'The search JS no longer posts the ' . $action
            . ' action; entering manual entry would leave the session company in place'
        );
        TinyAssert::true(
            strpos($controller, "case '" . $action . "':") !== false,
            'The order-intent controller no longer dispatches ' . $action
            . '; the clear falls through the switch and silently does nothing'
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
        // The map is snapshotted off the payload at construction; that seam is
        // asserted in testJsReadsTheInjectedSearchCountry(). What matters here
        // is that getCurrentCountry() still SUBSCRIPTS it by country id, which is
        // the resolution itself rather than a mention of the payload.
        $countries = array();
        TinyAssert::true(
            preg_match('#countries:\s*([A-Za-z_$][\w$]*)\.countries\b#', $code, $countries) === 1,
            'The id_country -> ISO map must be taken from the injected payload in ' . $path
            . ' - it is getCurrentCountry()\'s authoritative resolution source'
        );
        // Anchored on the resolution getCurrentCountry() names, not on a bare
        // subscript: two OTHER methods subscript the same map, so a bare one
        // stays satisfied while this resolver loses it.
        TinyAssert::true(
            preg_match('#isoFromConfig\s*=[^;]*_page\.countries\[#s', $code) === 1,
            'getCurrentCountry() must subscript the id_country -> ISO map by country id in '
            . $path . ' - it is its authoritative resolution source'
        );
    }
}
