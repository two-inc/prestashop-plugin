/**
 * TWO-25239. Regression tests for TwoCompanySearch.searchCompanies() and the
 * class-static result cache.
 *
 * These pin the invariant behind the stuck-spinner defect found while
 * reviewing the company-search rewrite: searchCompanies() must invoke its
 * `responseCallback` EXACTLY ONCE per search, on every path. Zero calls leaks
 * the spinner forever, because jQuery UI decrements its `pending` counter (and
 * therefore drops `ui-autocomplete-loading`) only when a search's response
 * callback runs. Two calls lets a superseded result overwrite a live one.
 *
 * They also pin the failure-vs-empty distinction — a failed search must never
 * render as an empty dropdown, which a buyer reads as "my company is not
 * registered" — and the `degraded` flag's deliberate `=== true` strictness.
 */

'use strict';

const {
    loadCompanySearch,
    buildAddressForm,
    stubAjax,
    callbackRecorder,
    releaseWidgets,
    panelParts
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

const SEARCH_RESPONSE = {
    items: [
        {
            name: 'Example Trading Ltd',
            national_identifier: { id: '12345678' },
            lookup_id: 'lookup-abc-123'
        }
    ]
};

let TwoCompanySearch;
let $;
let ajax;

const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';

beforeEach(() => {
    buildAddressForm({ country: 'GB' });
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    ajax = stubAjax($);
    // Search is relayed through the module's own controller (TWO-25386
    // follow-up); every real page publishes both keys.
    window.twopayment = { order_intent_url: ORDER_INTENT_URL, ajax_token: 'test-token' };
});

afterEach(() => {
    releaseWidgets($);
    ajax.restore();
    document.body.innerHTML = '';
    delete window.twopayment;
});

/** A live, initialised instance bound to the form built in beforeEach. */
function makeInstance(extraConfig) {
    return new TwoCompanySearch(
        Object.assign({ checkoutHost: CHECKOUT_HOST }, extraConfig || {})
    );
}

/**
 * The autocomplete widget, wherever it currently lives.
 *
 * TWO-25326 §1 moved it off `input[name='company']` and onto the anchored
 * panel's own query field: the company-name field stopped being the search box.
 * The panel is built by setupAutocomplete(), so this resolves from the moment
 * an instance exists - it does NOT need the panel to have been opened. Resolved
 * from the live DOM on each call rather than cached, for the same reason
 * panelParts() is: PrestaShop replaces the address form wholesale.
 *
 * @returns {Object} the jQuery UI autocomplete instance on the query field
 */
function widget() {
    return panelParts().query.autocomplete('instance');
}

describe('exactly one callback per search', () => {
    test('a term shorter than the minimum answers once, empty, non-silently', () => {
        const search = makeInstance();
        const rec = callbackRecorder();

        search.searchCompanies('ex', rec.fn);

        // No request, but the callback still has to fire or jQuery UI never
        // clears the spinner it armed before calling source().
        expect(ajax.calls).toHaveLength(0);
        expect(rec.calls).toHaveLength(1);
        expect(rec.calls[0].results).toEqual([]);
        expect(rec.calls[0].meta).toBeNull();
    });

    test('a successful search answers once with formatted results', () => {
        const search = makeInstance();
        const rec = callbackRecorder();

        search.searchCompanies('exa', rec.fn);
        ajax.last().succeed(SEARCH_RESPONSE);

        expect(rec.calls).toHaveLength(1);
        expect(rec.calls[0].meta).toBeNull();
        expect(rec.calls[0].results).toEqual([
            {
                label: 'Example Trading Ltd (12345678)',
                value: 'Example Trading Ltd',
                lookup_id: 'lookup-abc-123',
                organization_number: '12345678'
            }
        ]);
    });

    test('a timeout answers once, flagged unavailable rather than empty', () => {
        const search = makeInstance();
        const rec = callbackRecorder();

        search.searchCompanies('exa', rec.fn);
        ajax.last().fail('timeout');

        expect(rec.calls).toHaveLength(1);
        expect(rec.calls[0].meta).toEqual({ unavailable: true });
    });

    test.each([['error'], ['parsererror'], [null]])(
        'a %s failure answers once, flagged unavailable',
        (status) => {
            const search = makeInstance();
            const rec = callbackRecorder();

            search.searchCompanies('exa', rec.fn);
            ajax.last().fail(status, 'boom');

            expect(rec.calls).toHaveLength(1);
            expect(rec.calls[0].meta).toEqual({ unavailable: true });
        }
    );

    test('an abort answers once, silently', () => {
        const search = makeInstance();
        const rec = callbackRecorder();

        search.searchCompanies('exa', rec.fn);
        ajax.last().fail('abort');

        // Routine: the buyer typed another character, or the form re-rendered.
        // Reporting it as a failure would flash an error on every keystroke.
        expect(rec.calls).toHaveLength(1);
        expect(rec.calls[0].meta).toEqual({ silent: true });
        expect(rec.calls[0].results).toEqual([]);
    });

    test('falling back under the minimum settles the in-flight search once', () => {
        const search = makeInstance();
        const first = callbackRecorder();
        const second = callbackRecorder();

        search.searchCompanies('exa', first.fn);
        // Backspacing under the minimum has to cancel the live request, not
        // merely stop issuing new ones — otherwise it resolves later and
        // repopulates a dropdown the buyer has already emptied.
        search.searchCompanies('ex', second.fn);

        expect(ajax.calls).toHaveLength(1);
        expect(ajax.calls[0].aborted).toBe(true);
        expect(first.calls).toHaveLength(1);
        expect(first.calls[0].meta).toEqual({ silent: true });
        expect(second.calls).toHaveLength(1);
        expect(second.calls[0].meta).toBeNull();
    });

    test('a superseding search settles the one it replaced exactly once', () => {
        const search = makeInstance();
        const first = callbackRecorder();
        const second = callbackRecorder();

        search.searchCompanies('exa', first.fn);
        // The sequence is bumped BEFORE the abort, so the abort's synchronous
        // re-entry into the error handler already sees a stale sequence.
        search.searchCompanies('exam', second.fn);

        expect(first.calls).toHaveLength(1);
        expect(first.calls[0].meta).toEqual({ silent: true });
        expect(ajax.calls[0].aborted).toBe(true);

        ajax.calls[1].succeed(SEARCH_RESPONSE);
        expect(second.calls).toHaveLength(1);
        expect(second.calls[0].meta).toBeNull();
    });

    test('a stale success that outruns its abort answers once, silently', () => {
        const search = makeInstance();
        const first = callbackRecorder();
        const second = callbackRecorder();

        search.searchCompanies('exa', first.fn);
        // Abort does not always win the race — the response can already be on
        // the wire. Neuter it so the only guard left is the sequence check.
        ajax.calls[0].xhr.abort = function () {};
        search.searchCompanies('exam', second.fn);
        expect(first.calls).toHaveLength(0);

        ajax.calls[0].succeed(SEARCH_RESPONSE);

        expect(first.calls).toHaveLength(1);
        expect(first.calls[0].meta).toEqual({ silent: true });
        // Content dropped: jQuery UI discards a superseded requestIndex itself.
        expect(first.calls[0].results).toEqual([]);
    });

    test('a stale failure answers once, silently, not as unavailable', () => {
        const search = makeInstance();
        const first = callbackRecorder();
        const second = callbackRecorder();

        search.searchCompanies('exa', first.fn);
        ajax.calls[0].xhr.abort = function () {};
        search.searchCompanies('exam', second.fn);

        ajax.calls[0].fail('timeout');

        // A superseded request's failure is the newer request's problem to
        // report, not this one's — otherwise typing on through a flaky search
        // paints an error over a dropdown that is about to fill correctly.
        expect(first.calls).toHaveLength(1);
        expect(first.calls[0].meta).toEqual({ silent: true });
    });

    test('teardown mid-search settles the abandoned request exactly once', () => {
        const search = makeInstance();
        const rec = callbackRecorder();

        search.searchCompanies('exa', rec.fn);
        search.destroy();

        expect(ajax.calls[0].aborted).toBe(true);
        expect(rec.calls).toHaveLength(1);
        expect(rec.calls[0].meta).toEqual({ silent: true });
    });
});

describe('request envelope', () => {
    test('the search carries a 30s timeout, clear of the server retry window', () => {
        const search = makeInstance();
        search.searchCompanies('exa', callbackRecorder().fn);

        // The API's own retry envelope is stop_after_delay(10). A 10s client
        // timeout gave up at the exact moment a successful response would have
        // arrived.
        expect(ajax.last().settings.timeout).toBe(30000);
    });

    test('the search is bounded to one page, carries the country, and is relayed through the module controller', () => {
        const search = makeInstance();
        search.searchCompanies('exa', callbackRecorder().fn);

        // Relayed through the module's own controller (TWO-25386 follow-up),
        // never straight to Two - the firewall token that call may require
        // stays server-side.
        expect(ajax.last().url).toBe(window.twopayment.order_intent_url);
        expect(ajax.last().settings.data).toMatchObject({
            action: 'companySearch',
            q: 'exa',
            limit: 50,
            offset: 0,
            country: 'GB'
        });
    });

    test('a configured search limit overrides the default', () => {
        const search = makeInstance({ companySearchLimit: 10 });
        search.searchCompanies('exa', callbackRecorder().fn);

        expect(ajax.last().settings.data.limit).toBe(10);
    });

    test('a junk search limit falls back to the default rather than to none', () => {
        const search = makeInstance({ companySearchLimit: 'not-a-number' });
        search.searchCompanies('exa', callbackRecorder().fn);

        // An unbounded response is the failure mode the limit exists to stop.
        expect(ajax.last().settings.data.limit).toBe(50);
    });

    test('the country code is normalised to upper case', () => {
        document.body.innerHTML = '';
        buildAddressForm({ country: 'gb' });
        const search = makeInstance();
        search.searchCompanies('exa', callbackRecorder().fn);

        // Themes do emit lower-case iso codes. Un-normalised, that puts
        // `country=gb` on the wire and forks the cache key from `GB`.
        expect(ajax.last().settings.data.country).toBe('GB');
        expect(search.buildCacheKey('exa')).toBe('exa|GB');
    });

    test('a settled request releases its handle', () => {
        const search = makeInstance();
        search.searchCompanies('exa', callbackRecorder().fn);
        expect(search._companySearchXhr).not.toBeNull();

        ajax.last().succeed(SEARCH_RESPONSE);

        // Holding a settled handle means the next search aborts something
        // already finished — harmless with real jQuery, but it is the half of
        // the race fix that is easiest to drop by accident.
        expect(search._companySearchXhr).toBeNull();
    });
});

describe('degraded responses', () => {
    test('degraded with no results reads as unavailable, never as empty', () => {
        const search = makeInstance();
        const rec = callbackRecorder();

        search.searchCompanies('exa', rec.fn);
        ajax.last().succeed({ items: [], degraded: true });

        // An empty dropdown is what the buyer misreads as "my company is not
        // registered" — the whole reason the flag is honoured at all.
        expect(rec.calls).toHaveLength(1);
        expect(rec.calls[0].meta).toEqual({ unavailable: true });
    });

    test('degraded with results still shows them, flagged degraded', () => {
        const search = makeInstance();
        const rec = callbackRecorder();

        search.searchCompanies('exa', rec.fn);
        ajax.last().succeed(Object.assign({ degraded: true }, SEARCH_RESPONSE));

        expect(rec.calls).toHaveLength(1);
        expect(rec.calls[0].results).toHaveLength(1);
        // Partial data beats an error message, but the caller has to know not
        // to cache it for five minutes.
        expect(rec.calls[0].meta).toEqual({ degraded: true });
    });

    test('an absent degraded field means not degraded', () => {
        const search = makeInstance();
        const rec = callbackRecorder();

        // Every response predating the flag lacks it; today's payload shape
        // must keep working untouched.
        search.searchCompanies('exa', rec.fn);
        ajax.last().succeed({ items: [] });

        expect(rec.calls[0].meta).toBeNull();
        expect(rec.calls[0].results).toEqual([]);
    });

    test.each([
        ['false', 'a truthy string'],
        [1, 'a truthy number'],
        [{}, 'a truthy object'],
        [false, 'boolean false'],
        [null, 'null'],
        [undefined, 'undefined']
    ])('degraded: %p (%s) is not degraded', (value) => {
        const search = makeInstance();
        const rec = callbackRecorder();

        // `=== true`, not truthiness. A stray non-boolean must not silently
        // switch every search over to the unavailable message.
        search.searchCompanies('exa', rec.fn);
        ajax.last().succeed({ items: [], degraded: value });

        expect(rec.calls[0].meta).toBeNull();
    });
});

describe('organisation number extraction', () => {
    test.each([
        [{ national_identifier: { id: '1' } }, '1'],
        [{ national_identifier: { value: '2' } }, '2'],
        [{ national_identifier: { organisationNumber: '3' } }, '3'],
        [{ national_identifier: { organizationNumber: '4' } }, '4'],
        [{ national_identifier: { registration_number: '5' } }, '5'],
        [{ national_identifier: { company_number: '6' } }, '6'],
        [{ registration_number: '7' }, '7'],
        [{ company_number: '8' }, '8'],
        [{}, '']
    ])('reads the number out of %p', (extra, expected) => {
        const search = makeInstance();
        const rec = callbackRecorder();

        search.searchCompanies('exa', rec.fn);
        ajax.last().succeed({ items: [Object.assign({ name: 'Example Ltd' }, extra)] });

        expect(rec.calls[0].results[0].organization_number).toBe(expected);
        expect(rec.calls[0].results[0].label).toBe(
            expected ? 'Example Ltd (' + expected + ')' : 'Example Ltd'
        );
    });

    test('a response with no items array yields no results instead of throwing', () => {
        const search = makeInstance();
        const rec = callbackRecorder();

        search.searchCompanies('exa', rec.fn);
        ajax.last().succeed({});

        expect(rec.calls[0].results).toEqual([]);
    });
});

describe('class-static result cache', () => {
    test('the cache outlives the instance that filled it', () => {
        // The point of holding it on the class: the manager destroys and
        // rebuilds this widget on every `updatedAddressForm`, which PrestaShop
        // fires for something as ordinary as a country change. A per-instance
        // cache started cold every time and the buyer re-paid the round trip.
        TwoCompanySearch.cacheSet('exa|GB', [{ value: 'Example Ltd' }]);
        const first = makeInstance();
        first.destroy();
        const second = makeInstance();

        expect(TwoCompanySearch.cacheGet(second.buildCacheKey('exa'))).toEqual([
            { value: 'Example Ltd' }
        ]);
    });

    test('the key carries the country, so two countries do not share results', () => {
        const gb = makeInstance();
        expect(gb.buildCacheKey('exa')).toBe('exa|GB');

        document.body.innerHTML = '';
        buildAddressForm({ country: 'NO' });
        const no = makeInstance();

        // The country half must be exactly what searchCompanies() puts on the
        // wire, or one country's results get served for another's search.
        expect(no.buildCacheKey('exa')).toBe('exa|NO');
        no.searchCompanies('exa', callbackRecorder().fn);
        expect(ajax.last().settings.data.country).toBe('NO');
    });

    test('with nothing selected there is no key to answer from, and no search', () => {
        const search = makeInstance();
        document.querySelector("select[name='id_country']").innerHTML = '';
        const rec = callbackRecorder();

        // getCurrentCountry() used to guess here — navigator.language, then a
        // literal 'GB' — so a shop whose select the resolution chain could not
        // read searched the GB register for every buyer, silently. It now
        // resolves or returns empty, and an empty country means no request.
        expect(search.getCurrentCountry()).toBe('');
        expect(search.buildCacheKey('exa')).toBe('exa|');

        search.searchCompanies('exa', rec.fn);

        expect(ajax.calls).toHaveLength(0);
        expect(rec.calls).toHaveLength(1);
        expect(rec.calls[0].results).toEqual([]);
        expect(rec.calls[0].meta).toEqual({ countryUnresolved: true });
    });

    test('the browser locale is not consulted, whatever it says', () => {
        // The worst of the removed guesses: the buyer's browser locale has no
        // relationship to the shop's country or the company's, so a laptop set
        // to en-US searched the US register for a Dutch company.
        const language = jest.spyOn(window.navigator, 'language', 'get').mockReturnValue('en-no');
        try {
            const search = makeInstance();
            document.querySelector("select[name='id_country']").innerHTML = '';
            expect(search.getCurrentCountry()).toBe('');
        } finally {
            language.mockRestore();
        }
    });

    test('the authoritative id-to-ISO map resolves a select with no ISO attribute', () => {
        // The real fix, not a fallback: window.twopayment.countries is built
        // server-side from THIS shop's country table (Country::getCountries() in
        // twopayment.php) and injected via Media::addJsDef, so it covers every
        // country the shop has rather than the ten ids that used to be hardcoded
        // here. Lower-cased there, upper-cased on the wire.
        document.body.innerHTML = '';
        buildAddressForm({ country: null, countryId: '44', countryText: 'Nederland' });
        window.twopayment = { countries: { 44: 'nl' }, order_intent_url: ORDER_INTENT_URL, ajax_token: 'test-token' };
        try {
            const search = makeInstance();

            expect(search.getCurrentCountry()).toBe('NL');
            expect(search.buildCacheKey('exa')).toBe('exa|NL');
            search.searchCompanies('exa', callbackRecorder().fn);
            expect(ajax.last().settings.data.country).toBe('NL');
        } finally {
            delete window.twopayment;
        }
    });

    test('an id the map does not cover resolves to nothing rather than to GB', () => {
        // PrestaShop country ids are per-installation table rows, not constants.
        // The hardcoded map this replaces was therefore wrong on any shop whose
        // country table had been edited — and wrong SILENTLY, because a miss fell
        // straight through to the 'GB' default.
        document.body.innerHTML = '';
        buildAddressForm({ country: null, countryId: '999', countryText: 'Somewhere' });
        window.twopayment = { countries: { 44: 'nl' } };
        try {
            const search = makeInstance();
            const rec = callbackRecorder();

            expect(search.getCurrentCountry()).toBe('');
            search.searchCompanies('exa', rec.fn);

            expect(ajax.calls).toHaveLength(0);
            expect(rec.calls[0].meta).toEqual({ countryUnresolved: true });
        } finally {
            delete window.twopayment;
        }
    });

    test('the option text still resolves when there is no map to read', () => {
        // Kept as the last strategy for a theme that renders its own select and
        // loads the search without the module's JS defs. It is an exact
        // full-name match, so it fails closed rather than guessing.
        document.body.innerHTML = '';
        buildAddressForm({ country: null, countryId: '44', countryText: 'Netherlands' });
        const search = makeInstance();

        expect(search.getCurrentCountry()).toBe('NL');
    });

    describe('the option-text map covers this shop\'s own locales (TWO-40 follow-up, adversarial review)', () => {
        // This shop ships nl/no/sv translations. A map with only English/
        // Spanish/French country names was blind for exactly the locales this
        // shop actually serves, on a theme with no ISO attribute and no id in
        // window.twopayment.countries.
        test.each([
            ['Nederland', 'NL'],
            ['Storbritannia', 'GB'],
            ['Storbritannien', 'GB'],
            ['Frankrike', 'FR'],
            ['Tyskland', 'DE'],
            ['Spanien', 'ES'],
            ['Belgien', 'BE'],
            ['Australien', 'AU']
        ])('%s resolves to %s', (countryText, expectedIso) => {
            document.body.innerHTML = '';
            buildAddressForm({ country: null, countryId: '999', countryText });
            const search = makeInstance();

            expect(search.getCurrentCountry()).toBe(expectedIso);
        });
    });

    test('a select named "country" (no "id_" prefix) is resolved too, not just "id_country" (adversarial review finding)', () => {
        // TwoSoleTrader.js's billingCountry() and TwoOrderIntent.js's
        // getCurrentAddressCountryISO() both already fall back to
        // `select[name='country']`. This method used to check `id_country`
        // only, so a theme rendering the field under that name made this
        // method fall straight through to the page-load-time
        // `window.twopayment.company_search_country` while TwoSoleTrader.js resolved
        // the live value off the real select - the two silently disagreeing
        // on country.
        document.body.innerHTML = '';
        document.body.innerHTML = "<select name='country'>"
            + '<option value="17" data-iso-code="SE" selected>Sverige</option>'
            + '</select>'
            + "<input type='text' name='company' value='' />";
        const search = makeInstance();

        expect(search.getCurrentCountry()).toBe('SE');
    });

    test('nothing is cached for an unresolved country', () => {
        // The cache is class-static and outlives the widget, so an entry filed
        // under the empty key would be served to every later search for the same
        // term. There must be no entry to serve.
        document.body.innerHTML = '';
        buildAddressForm({ country: null, countryId: '999' });
        makeInstance();
        widget().search('exa');

        expect(ajax.calls).toHaveLength(0);
        expect(TwoCompanySearch.cacheGet('exa|')).toBeNull();
    });

    test('the buyer is told to pick a country rather than shown an empty list', () => {
        // An empty dropdown reads as "your company is not registered", which is a
        // reason to abandon checkout. It is also not the `unavailable` copy:
        // nothing is broken and retrying changes nothing.
        document.body.innerHTML = '';
        buildAddressForm({ country: null, countryId: '999' });
        window.twopayment = {
            i18n: { company_search_select_country: 'Pick a country first.' }
        };
        try {
            const search = makeInstance();
            const rendered = [];
            panelParts().query.autocomplete('option', 'response', (event, ui) => {
                ui.content.forEach((item) => rendered.push(item));
            });
            widget().search('exa');

            // `value` is the MESSAGE, not the '' the item was built with:
            // jQuery UI's _normalize() rewrites it as `value || label`. So the
            // `two_unavailable` flag is the only thing keeping this row out of
            // the company field — same trap as buildUnavailableItem() documents.
            expect(rendered).toEqual([
                {
                    label: 'Pick a country first.',
                    value: 'Pick a country first.',
                    two_unavailable: true,
                    // Its own row class: nothing is broken, so the row must not
                    // be identified in the DOM as the failure row.
                    two_row_class: 'two-autocomplete-select-country'
                }
                // ...and NOTHING else. The manual-entry footer that used to be
                // appended here as a second pseudo-row is gone: TWO-25326 §2
                // made "My company is not on the list" a real <button> outside
                // the scroll container, so it is no longer part of any rendered
                // item set. Asserted immediately below rather than left implied,
                // because "the route out is still offered" is the property that
                // mattered about the old row.
            ]);
            expect(rendered[0].label).not.toBe(search.getSearchUnavailableText());
            expect(panelParts().notListed.length).toBe(1);
            expect(panelParts().notListed.get(0).tagName).toBe('BUTTON');
        } finally {
            delete window.twopayment;
        }
    });

    test('the key and the wire never disagree about the country', () => {
        // The invariant both halves of the fix rest on, asserted directly rather
        // than inferred from the individual fallback cases: whatever
        // getCurrentCountry() resolves, the request carries it and the key
        // records it. Give either side its own fallback and the cache starts
        // answering one country's search with another's results.
        [
            ['GB', 'gb'],
            ['NO', 'NO'],
            ['DE', 'de']
        ].forEach(([expected, markup]) => {
            document.body.innerHTML = '';
            buildAddressForm({ country: markup });
            const search = makeInstance();
            search.searchCompanies('exa', callbackRecorder().fn);

            const onTheWire = ajax.last().settings.data.country;
            expect(onTheWire).toBe(expected);
            expect(search.buildCacheKey('exa')).toBe('exa|' + onTheWire);
        });
    });

    test('a country change mid-request does not re-file the response', () => {
        // The one way key and wire could still fork: the key is built before the
        // request and closed over, so if the buyer changes country while the
        // response is in flight, the entry must stay filed under the country the
        // REQUEST carried, not the one now selected. It does, because both are
        // resolved in the same synchronous tick before the request goes out.
        const search = makeInstance();
        widget().search('exa');
        expect(ajax.last().settings.data.country).toBe('GB');

        const inFlight = ajax.last();
        document.querySelector("select[name='id_country']").innerHTML =
            '<option value="44" data-iso-code="NO" selected>Norge</option>';
        expect(search.getCurrentCountry()).toBe('NO');

        inFlight.succeed(SEARCH_RESPONSE);

        expect(TwoCompanySearch.cacheGet('exa|GB')).not.toBeNull();
        expect(TwoCompanySearch.cacheGet('exa|NO')).toBeNull();
    });

    test('an entry expires after five minutes, and drops on read', () => {
        // Hard-coded rather than read off the class: the boundary logic and the
        // VALUE are separate decisions, and a five-minute window is the one that
        // was reasoned about (long enough to cover a buyer retyping a name,
        // short enough that a registry correction is not pinned for a session).
        const TTL_MS = 5 * 60 * 1000;
        expect(TwoCompanySearch._CACHE_TTL_MS).toBe(TTL_MS);

        const now = Date.now();
        const spy = jest.spyOn(Date, 'now').mockReturnValue(now);
        try {
            TwoCompanySearch.cacheSet('k', ['v']);
            spy.mockReturnValue(now + TTL_MS - 1);
            expect(TwoCompanySearch.cacheGet('k')).toEqual(['v']);

            spy.mockReturnValue(now + TTL_MS);
            expect(TwoCompanySearch.cacheGet('k')).toBeNull();
            expect(TwoCompanySearch._resultCache.has('k')).toBe(false);
        } finally {
            spy.mockRestore();
        }
    });

    test('the cache holds at most 50 entries, evicting oldest first', () => {
        // Also hard-coded. The cache lives for the whole page session now rather
        // than until the next address-form re-render, so the bound is what stops
        // a long checkout accumulating without limit.
        const max = 50;
        expect(TwoCompanySearch._CACHE_MAX_ENTRIES).toBe(max);

        for (let i = 0; i < max + 5; i += 1) {
            TwoCompanySearch.cacheSet('key-' + i, [i]);
        }

        expect(TwoCompanySearch._resultCache.size).toBeLessThanOrEqual(max);
        expect(TwoCompanySearch.cacheGet('key-0')).toBeNull();
        expect(TwoCompanySearch.cacheGet('key-' + (max + 4))).toEqual([max + 4]);
    });
});
