/**
 * TWO-25326 bug 9, round 3. Doug live-tested the round-2 fix and the chips this
 * module used to render STILL "render, then disappear and reappear again".
 * TWO-40 later removed those chips entirely - there is no upfront Business /
 * Sole trader toggle any more, and the entry point lives inside
 * TwoCompanySearch.js's dropdown instead. What survives from this file, and is
 * still exactly as load-bearing, is the AVAILABILITY ANSWER handover this
 * module adopts from the server: TwoCompanySearch.js's "I'm a sole trader" row
 * reads `isAvailableForCurrentCountry()`, and that has to be correct at first
 * paint, across country changes, and across the payment-fragment replacements
 * PrestaShop performs constantly while a checkout step settles - with no
 * request at all when the server has already answered.
 *
 * WHY ROUND 2 COULD NOT HAVE FIXED THE ORIGINAL BUG. Round 2 keyed the
 * settled-check on the container node and put an in-flight guard on the
 * availability request. Both were real defects, and neither is this one: they
 * are about not redoing work AFTER an answer arrives, and the flicker lived
 * entirely in the window BEFORE it arrives. Measured on the staging shop with
 * a rAF-rate sampler: the toggle was `display:none` at first paint and only
 * became `display:block` ~280ms later, on EVERY load - because the answer was
 * resolved only by this module, only after a round trip. Selecting a payment
 * option reloads the whole checkout page, so the buyer saw chips, then a
 * document without chips, then chips again. Nothing that runs after the
 * answer can close that window; the answer has to already be in the markup.
 *
 * So paymentinfo.tpl renders `.two-sole-trader`'s data- attributes from the
 * server-side registry answer and this module ADOPTS them. These tests are
 * about that handover: the adopted state must be correct, must cost no
 * request, and must not be trusted further than it goes - a different country
 * still re-resolves, and markup with no answer in it still falls back to the
 * fetch.
 */

'use strict';

const {
    loadSoleTrader,
    buildPaymentTile,
    buildPaymentTileWithSoleTraderAnswer,
    flushPromises
} = require('./ps-harness');

let TwoSoleTrader;
let fetchCalls;
let answer;

function container() {
    return document.querySelector('.two-sole-trader');
}

function buildCountry(iso) {
    const holder = document.createElement('div');
    holder.innerHTML = "<select name='id_country'>"
        + '<option value="17" data-iso-code="' + iso + '" selected>Country</option>'
        + '</select>';
    document.body.appendChild(holder);
    return holder.querySelector('select');
}

/**
 * A country <select> with NONE of the `data-iso-code`/`data-iso`/
 * `data-country-iso` attributes - the exact shape that exposed the missing
 * `window.twopayment.countries` id-to-ISO fallback in billingCountry() (TWO-40
 * follow-up). Doug's own repro (>10 min open, France -> GB -> reopen, chip
 * still missing) happens on any theme/PS version whose options look like
 * this - that fallback is the only thing that can resolve them at all.
 */
function buildCountryNoIsoAttr(id, text) {
    const holder = document.createElement('div');
    holder.innerHTML = "<select name='id_country'>"
        + '<option value="' + id + '" selected>' + (text || 'Country') + '</option>'
        + '</select>';
    document.body.appendChild(holder);
    return holder.querySelector('select');
}

/** Let the debounced observer callback and any promise chain run. */
async function drain() {
    jest.advanceTimersByTime(150);
    await flushPromises();
    jest.advanceTimersByTime(150);
    await flushPromises();
}

function build(overrides) {
    return new TwoSoleTrader(Object.assign({
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        // Deliberately DIFFERENT from the fixtures' country, so any test that
        // resolves the country from this rather than from the cart's billing
        // country or the live select shows up as a wrong-country request.
        shopCountry: 'ZZ',
        billingCountry: 'GB'
    }, overrides || {}));
}

beforeEach(() => {
    jest.useFakeTimers();
    delete global.window.TwoSoleTrader;
    fetchCalls = [];
    answer = { available: true };
    global.window.fetch = (url) => {
        fetchCalls.push(url);
        return Promise.resolve({ json: () => Promise.resolve({ success: true, available: answer.available }) });
    };
    global.fetch = global.window.fetch;
});

afterEach(() => {
    if (global.window.TwoSoleTrader_Instance) {
        delete global.window.TwoSoleTrader_Instance;
    }
    jest.useRealTimers();
    document.body.innerHTML = '';
    delete global.window.fetch;
    delete global.fetch;
    // TWO-40 follow-up: see the identical comment in
    // sole-trader-toggle-flicker.test.js - the persistent cache is real
    // cross-test state now and must not leak between cases.
    global.window.localStorage.clear();
});

describe('a server-rendered answer is adopted, not re-fetched', () => {
    test('available is answered correctly with no request at all', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();

        // Before construction, i.e. what the buyer's browser knows at FIRST
        // PAINT. This is the assertion round 2 could not make: previously the
        // template shipped no answer at all, and TwoCompanySearch.js's "I'm a
        // sole trader" row could not have known whether to show itself.
        expect(container().getAttribute('data-two-available')).toBe('1');
        expect(container().getAttribute('data-two-country')).toBe('GB');

        const instance = build();
        await drain();

        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        expect(fetchCalls).toEqual([]);
        instance.destroy();
    });

    test('startEnrollment() works off the adopted state with no request', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await drain();

        // The whole risk of moving the answer server-side: an availability
        // cache that looks right and does not actually gate enrolment.
        const calls = [];
        instance.fetchTokens = () => { calls.push('fetchTokens'); };
        instance.startEnrollment();

        expect(instance.enrolling).toBe(true);
        expect(calls).toEqual(['fetchTokens']);
        instance.destroy();
    });

    test('a server answer of "not available" is adopted, with no request', async () => {
        buildPaymentTileWithSoleTraderAnswer('0', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();

        expect(container().getAttribute('data-two-available')).toBe('0');

        const instance = build();
        await drain();

        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        expect(fetchCalls).toEqual([]);
        instance.destroy();
    });
});

describe('the adopted answer is trusted exactly as far as it goes', () => {
    test('a different billing country still resolves over the network', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        // The buyer's billing country is NOT the one the server rendered for -
        // e.g. the address was changed in a tab the payment step outlived.
        buildCountry('NO');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await drain();

        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=NO');
        instance.destroy();
    });

    test('a country change after adoption is resolved for the new country', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        const select = buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await drain();
        expect(fetchCalls).toEqual([]);

        select.querySelector('option').setAttribute('data-iso-code', 'SE');
        select.dispatchEvent(new window.Event('change', { bubbles: true }));
        await drain();

        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=SE');
        instance.destroy();
    });

    test('an UNRESOLVED answer falls back to the request rather than caching a "no"', async () => {
        // The registry did not answer. isAvailableForCurrentCountry() answers
        // false until it resolves - fail-soft is unchanged - but the browser
        // must NOT record that as "business-only country", or one blip
        // becomes permanent for the page: adoption never re-asks.
        buildPaymentTileWithSoleTraderAnswer('', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        expect(instance.renderedForCountry).toBeNull();
        expect(instance.availabilityByCountry).toEqual({});

        await drain();

        expect(fetchCalls).toHaveLength(1);
        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        instance.destroy();
    });

    test('an answer that is neither "1" nor "0" is not adopted', async () => {
        // A theme or a future template emitting e.g. "true". Adopting it would
        // read as `false` - sole trader silently unavailable, no request. The
        // country here is VALID on purpose: with a bad country too, the
        // country guard rejects first and this guard is never the deciding
        // branch.
        buildPaymentTileWithSoleTraderAnswer('yes', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        expect(instance.renderedForCountry).toBeNull();
        expect(instance.availabilityByCountry).toEqual({});

        await drain();

        expect(fetchCalls).toHaveLength(1);
        instance.destroy();
    });

    test('markup with no server answer at all falls back to the request', async () => {
        // buildPaymentTile() leaves the sole-trader blocks unevaluated, which is
        // what an older cached template or a theme-rebuilt tile looks like.
        buildPaymentTile();
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await drain();

        expect(fetchCalls).toHaveLength(1);
        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        instance.destroy();
    });

    test('a malformed country attribute is not adopted', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'not-an-iso');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();

        // Asserted on the adoption state directly, not on "a request happened":
        // the answer would be filed under the bogus key, which the DOM country
        // never matches, so a request happens under BOTH branches and that
        // assertion alone proves nothing.
        expect(instance.renderedForCountry).toBeNull();
        expect(instance.availabilityByCountry).toEqual({});

        await drain();
        expect(fetchCalls).toHaveLength(1);
        instance.destroy();
    });
});

describe('which country the answer is resolved for', () => {
    test('with no country select on the page, the CART billing country is used', async () => {
        // The real payment step: PrestaShop renders no address form there, so
        // there is no `select[name=id_country]` to read. Falling back to the
        // shop/visitor country resolved availability for the wrong country and
        // applied it over the server-rendered answer.
        buildPaymentTileWithSoleTraderAnswer('', 'NO');
        TwoSoleTrader = loadSoleTrader();
        const instance = build({ billingCountry: 'NO', shopCountry: 'ZZ' });
        await drain();

        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=NO');
        instance.destroy();
    });

    test('the shop country is only a last resort', async () => {
        buildPaymentTileWithSoleTraderAnswer('', 'GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build({ billingCountry: '', shopCountry: 'SE' });
        await drain();

        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=SE');
        instance.destroy();
    });

    test('a live country select still outranks both', async () => {
        buildPaymentTileWithSoleTraderAnswer('', 'GB');
        buildCountry('DK');
        TwoSoleTrader = loadSoleTrader();
        const instance = build({ billingCountry: 'NO', shopCountry: 'SE' });
        await drain();

        // A buyer mid-edit may have picked a country that is not saved yet.
        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=DK');
        instance.destroy();
    });
});

describe('a replaced container is re-adopted from its own markup', () => {
    /** Replace the container with a fresh SERVER-rendered copy, as PS does. */
    function replaceWithServerRendered(answer, countryIso) {
        const old = container();
        const holder = document.createElement('div');
        holder.innerHTML = '<div class="two-sole-trader" data-two-country="' + countryIso + '"'
            + ' data-two-available="' + answer + '">'
            + '<a href="#" class="two-sole-trader__prompt" style="display: none;"></a>'
            + '<span class="two-sole-trader__status" style="display: none;"></span>'
            + '<span class="two-sole-trader__error" style="display: none;"></span>'
            + '</div>';
        const fresh = holder.querySelector('.two-sole-trader');
        old.parentNode.replaceChild(fresh, old);
        return fresh;
    }

    test("the replacement's answer is adopted without waiting out the refresh debounce", async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await drain();

        replaceWithServerRendered('0', 'GB');
        // NO timer advance: the container-identity check is deliberately not
        // debounced, because until it runs the cache is answering for a
        // container that no longer exists.
        await flushPromises();

        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        instance.destroy();
    });

    test("a fresher server answer in the replacement is not overwritten by the stale cache", async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await drain();
        expect(instance.isAvailableForCurrentCountry()).toBe(true);

        // Same country, but the server now says business-only. The cached `true`
        // used to win and re-render the toggle as available.
        replaceWithServerRendered('0', 'GB');
        await drain();

        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        expect(fetchCalls).toEqual([]);
        instance.destroy();
    });

    test('an in-flight request cannot clobber an answer adopted while it was out', async () => {
        // Round 3 review, finding 1 - and the worst failure mode in this design.
        // The load starts with NO server answer, so the module fetches. Prestashop
        // then replaces the payment fragment with one that DOES carry an answer,
        // which is adopted. The already-outstanding request then answers: the
        // server has spoken more recently than that request was even issued, so its
        // result is stale however in-order it looked.
        let settle;
        global.window.fetch = (url) => {
            fetchCalls.push(url);
            return new Promise((resolve) => { settle = resolve; });
        };
        global.fetch = global.window.fetch;

        buildPaymentTile();
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        jest.advanceTimersByTime(150);
        await flushPromises();
        expect(fetchCalls).toHaveLength(1);

        replaceWithServerRendered('1', 'GB');
        await flushPromises();
        expect(instance.isAvailableForCurrentCountry()).toBe(true);

        // The in-flight request now FAILS the way a blip does. Before the fix this
        // applied "not available" while availabilityByCountry still held the
        // adopted `true`, so the settled-check then agreed the cache was correct -
        // and isAvailableForCurrentCountry() stayed wrong for the rest of the
        // page's life, unrecoverably.
        settle({ json: () => Promise.resolve({ success: false }) });
        await drain();

        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        expect(instance.availabilityByCountry.GB).toBe(true);
        instance.destroy();
    });

    test('a superseded request leaves the CURRENT country resolved, not stuck', async () => {
        // Round 4 review, finding 1. The generation counter is per instance, not
        // per country, so an adoption for country A supersedes an outstanding
        // request for country B - and dropping that result must not leave B
        // unresolved with nothing scheduled to resolve it. The debounced refresh
        // that would have re-asked already ran and bailed while the request was
        // still out (pendingCountry was set), so the bail has to re-arm it.
        let settle;
        global.window.fetch = (url) => {
            fetchCalls.push(url);
            return new Promise((resolve) => { settle = resolve; });
        };
        global.fetch = global.window.fetch;

        buildPaymentTile();
        buildCountry('DK');
        TwoSoleTrader = loadSoleTrader();
        const instance = build({ billingCountry: 'DK' });
        jest.advanceTimersByTime(150);
        await flushPromises();
        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=DK');

        // A replacement carrying an answer for a DIFFERENT country.
        replaceWithServerRendered('1', 'GB');
        await flushPromises();

        global.window.fetch = (url) => {
            fetchCalls.push(url);
            return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
        };
        global.fetch = global.window.fetch;
        settle({ json: () => Promise.resolve({ success: true, available: true }) });
        await drain();

        // The buyer's country is still DK, so DK is what has to end up resolved.
        expect(fetchCalls.filter((u) => u.includes('country=DK'))).toHaveLength(2);
        expect(instance.renderedForCountry).toBe('DK');
        instance.destroy();
    });

    test('a successful in-flight request is dropped too if an answer was adopted', async () => {
        let settle;
        global.window.fetch = (url) => {
            fetchCalls.push(url);
            return new Promise((resolve) => { settle = resolve; });
        };
        global.fetch = global.window.fetch;

        buildPaymentTile();
        buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        jest.advanceTimersByTime(150);
        await flushPromises();

        // Server now says business-only for the same country.
        replaceWithServerRendered('0', 'GB');
        await flushPromises();

        // The older request says available. It must not win: it was issued before
        // the server's answer existed.
        settle({ json: () => Promise.resolve({ success: true, available: true }) });
        await drain();

        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        expect(instance.availabilityByCountry.GB).toBe(false);
        instance.destroy();
    });

    test('the country-change path re-adopts too, not only the observer', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        const select = buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await drain();

        // Observer off, so the ONLY route into refreshAvailability() is the
        // country-change listener. That listener can fire after a fragment
        // replacement whose mutations the observer never saw or has not drained,
        // so it has to re-adopt as well - otherwise the stale cache overwrites
        // the replacement's own, fresher answer.
        instance.stopObserving();
        replaceWithServerRendered('0', 'GB');
        select.dispatchEvent(new window.Event('change', { bubbles: true }));
        await flushPromises();

        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        expect(fetchCalls).toEqual([]);
        instance.destroy();
    });
});

describe('lifecycle', () => {
    test('stopObserving() still lets a country change resolve a now-ineligible country', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        const select = buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await drain();

        // stopObserving() means "this flow is resolved", not "this instance is
        // gone". An enrolled buyer who then switches to a business-only country
        // must still have that reflected in the availability cache.
        instance.stopObserving();
        answer.available = false;
        select.querySelector('option').setAttribute('data-iso-code', 'SE');
        select.dispatchEvent(new window.Event('change', { bubbles: true }));
        await drain();

        expect(fetchCalls).toHaveLength(1);
        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        instance.destroy();
    });

    test('destroy() detaches the country-change listener too', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        const select = buildCountry('GB');
        TwoSoleTrader = loadSoleTrader();
        const instance = build();
        await drain();

        instance.destroy();
        select.querySelector('option').setAttribute('data-iso-code', 'SE');
        select.dispatchEvent(new window.Event('change', { bubbles: true }));
        await drain();

        // The handler used to be an anonymous closure with no reference kept, so
        // there was no way to detach it at all - a disposed instance stayed a
        // live second writer to the availability cache.
        expect(fetchCalls).toEqual([]);
    });
});

describe('billingCountry() id-to-ISO map fallback (TWO-40 follow-up)', () => {
    afterEach(() => {
        delete window.twopayment;
    });

    test('resolves via window.twopayment.countries when no data- attribute exists on the option', async () => {
        buildPaymentTile();
        buildCountryNoIsoAttr('44', 'Nederland');
        window.twopayment = { countries: { 44: 'nl' } };
        TwoSoleTrader = loadSoleTrader();
        // billingCountry config deliberately blank: a fallback straight to
        // `this.config` (the bug) would surface here as '', not silently
        // agree with a live value.
        const instance = build({ billingCountry: '', shopCountry: '' });

        expect(instance.billingCountry()).toBe('NL');

        await drain();

        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=NL');
        instance.destroy();
    });

    test('a later country change through the map fallback is picked up - not pinned to the page-load value', async () => {
        // This is Doug's exact repro shape: no ISO attribute anywhere, a
        // country change, then a re-check with no reopen forced in between.
        buildPaymentTile();
        const select = buildCountryNoIsoAttr('44', 'Nederland');
        window.twopayment = { countries: { 44: 'nl', 8: 'gb' } };
        TwoSoleTrader = loadSoleTrader();
        const instance = build({ billingCountry: '', shopCountry: '' });
        await drain();
        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=NL');

        // Swap to a different id, still with no data-iso-code anywhere - the
        // buyer picking a new country in a theme that never renders it.
        select.innerHTML = '<option value="8" selected>United Kingdom</option>';
        select.dispatchEvent(new window.Event('change', { bubbles: true }));
        await drain();

        // Before this fix: billingCountry() stayed on the page-load 'GB'/''
        // fallback and never asked about NL let alone GB again - the buyer's
        // OWN change to the selector had no effect at all.
        expect(instance.billingCountry()).toBe('GB');
        expect(fetchCalls).toHaveLength(2);
        expect(fetchCalls[1]).toContain('country=GB');
        instance.destroy();
    });

    test('isAvailableForCurrentCountry() follows the map-resolved country, not a stale page-load one', async () => {
        buildPaymentTile();
        const select = buildCountryNoIsoAttr('44', 'Nederland');
        window.twopayment = { countries: { 44: 'nl', 8: 'gb' } };
        answer.available = false;
        TwoSoleTrader = loadSoleTrader();
        const instance = build({ billingCountry: '', shopCountry: '' });
        await drain();
        expect(instance.isAvailableForCurrentCountry()).toBe(false);

        answer.available = true;
        select.innerHTML = '<option value="8" selected>United Kingdom</option>';
        select.dispatchEvent(new window.Event('change', { bubbles: true }));
        await drain();

        // The chip-visibility gate TwoCompanySearch.js reads. If billingCountry()
        // stayed pinned to the wrong country, this would still read false
        // (or the NL answer) despite GB now being available.
        expect(instance.isAvailableForCurrentCountry()).toBe(true);
        instance.destroy();
    });

    test('falls through to the option-text map when neither an attribute nor the config covers it', async () => {
        buildPaymentTile();
        buildCountryNoIsoAttr('999', 'France');
        window.twopayment = { countries: { 44: 'nl' } };
        TwoSoleTrader = loadSoleTrader();
        const instance = build({ billingCountry: '', shopCountry: '' });

        expect(instance.billingCountry()).toBe('FR');
        instance.destroy();
    });
});
