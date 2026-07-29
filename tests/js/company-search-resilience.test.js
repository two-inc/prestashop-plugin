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
    releaseWidgets
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

beforeEach(() => {
    buildAddressForm({ country: 'GB' });
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    ajax = stubAjax($);
});

afterEach(() => {
    releaseWidgets($);
    ajax.restore();
    document.body.innerHTML = '';
});

/** A live, initialised instance bound to the form built in beforeEach. */
function makeInstance(extraConfig) {
    return new TwoCompanySearch(
        Object.assign({ checkoutHost: CHECKOUT_HOST }, extraConfig || {})
    );
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

    test('the search is bounded to one page and carries the country', () => {
        const search = makeInstance();
        search.searchCompanies('exa', callbackRecorder().fn);

        const url = new URL(ajax.last().url);
        expect(url.pathname).toBe('/companies/v2/company');
        expect(url.searchParams.get('q')).toBe('exa');
        expect(url.searchParams.get('limit')).toBe('50');
        expect(url.searchParams.get('offset')).toBe('0');
        expect(url.searchParams.get('country')).toBe('GB');
    });

    test('a configured search limit overrides the default', () => {
        const search = makeInstance({ companySearchLimit: 10 });
        search.searchCompanies('exa', callbackRecorder().fn);

        expect(new URL(ajax.last().url).searchParams.get('limit')).toBe('10');
    });

    test('a junk search limit falls back to the default rather than to none', () => {
        const search = makeInstance({ companySearchLimit: 'not-a-number' });
        search.searchCompanies('exa', callbackRecorder().fn);

        // An unbounded response is the failure mode the limit exists to stop.
        expect(new URL(ajax.last().url).searchParams.get('limit')).toBe('50');
    });

    test('the request carries no credentials', () => {
        const search = makeInstance();
        search.searchCompanies('exa', callbackRecorder().fn);

        // This is a cross-origin call to the public company API from a shop
        // page. `withCredentials: true` would attach the buyer's cookies for
        // that origin to every keystroke's search.
        expect(ajax.last().settings.crossDomain).toBe(true);
        expect(ajax.last().settings.xhrFields).toEqual({ withCredentials: false });
    });

    test('the country code is normalised to upper case', () => {
        document.body.innerHTML = '';
        buildAddressForm({ country: 'gb' });
        const search = makeInstance();
        search.searchCompanies('exa', callbackRecorder().fn);

        // Themes do emit lower-case iso codes. Un-normalised, that puts
        // `country=gb` on the wire and forks the cache key from `GB`.
        expect(new URL(ajax.last().url).searchParams.get('country')).toBe('GB');
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

    test('credential headers cannot be attached to the public API call', () => {
        const search = makeInstance();
        search.searchCompanies('exa', callbackRecorder().fn);

        const set = [];
        const xhr = {
            setRequestHeader: function (name, value) {
                set.push([name, value]);
            }
        };
        ajax.last().settings.beforeSend(xhr);
        xhr.setRequestHeader('Authorization', 'Bearer nope');
        xhr.setRequestHeader('X-API-Key', 'nope');
        xhr.setRequestHeader('Accept', 'application/json');

        expect(set).toEqual([['Accept', 'application/json']]);
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
        expect(new URL(ajax.last().url).searchParams.get('country')).toBe('NO');
    });

    test('with nothing selected the key falls back to the browser locale', () => {
        const search = makeInstance();
        document.querySelector("select[name='id_country']").innerHTML = '';

        // NOTE: getCurrentCountry() never returns empty, so buildCacheKey()'s
        // docblock is wrong about an empty segment for the unselected case — that
        // path is unreachable. Strategy 4 is a navigator.language guess, which
        // under jsdom is en-US.
        expect(search.getCurrentCountry()).toBe('US');
        expect(search.buildCacheKey('exa')).toBe('exa|US');
        search.searchCompanies('exa', callbackRecorder().fn);
        expect(new URL(ajax.last().url).searchParams.get('country')).toBe('US');
    });

    test('with no locale to guess from either, the key falls back to GB', () => {
        const language = jest
            .spyOn(window.navigator, 'language', 'get')
            .mockReturnValue('xx');
        try {
            const search = makeInstance();
            document.querySelector("select[name='id_country']").innerHTML = '';

            // The last resort is a literal 'GB'. Pinned explicitly because the
            // jsdom default locale hides it, and because a test that merely
            // reads getCurrentCountry() back cannot fail if it changes.
            expect(search.getCurrentCountry()).toBe('GB');
            expect(search.buildCacheKey('exa')).toBe('exa|GB');
        } finally {
            language.mockRestore();
        }
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
