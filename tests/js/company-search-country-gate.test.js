/**
 * TWO-25288 follow-up: the "Registered Company" chip's country gate.
 *
 * `syncRegisteredEntryVisibility()` used to show the chip unconditionally
 * whenever the panel was open. This pins its new gate against
 * GET /companies/v2/supported-countries, fetched once via
 * ensureSupportedSearchCountriesFetched() and shared across instances - see
 * ps-harness.js's loadCompanySearch() for why the static answer is reset
 * between tests. Uses native `fetch()`, not `$.ajax` (same choice
 * TwoSoleTrader.js's own availability lookup makes - see sole-trader-
 * availability-cache.test.js), so it is stubbed the same way here.
 */

'use strict';

const {
    loadCompanySearch,
    buildAddressForm,
    installStylesheet,
    releaseWidgets,
    panelParts,
    openPanel,
    shown
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';

let TwoCompanySearch;
let $;
let fetchCalls;
let resolvers;

function makeInstance(config) {
    return new TwoCompanySearch(Object.assign({ checkoutHost: CHECKOUT_HOST }, config || {}));
}

/** Resolve the (single) supported-countries fetch with a JSON body. */
async function resolveFetch(body) {
    const resolve = resolvers[resolvers.length - 1];
    resolve({ json: () => Promise.resolve(body) });
    // Let the fetch/then chain drain.
    await Promise.resolve().then().then();
}

/** Reject the (single) supported-countries fetch, simulating a transport error. */
async function rejectFetch() {
    const reject = global.window.__two_test_fetch_rejects[global.window.__two_test_fetch_rejects.length - 1];
    reject(new Error('network down'));
    await Promise.resolve().then().then();
}

beforeEach(() => {
    document.body.innerHTML = '';
    document.head.innerHTML = '';
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    buildAddressForm({ country: 'GB' });
    installStylesheet('views/css/two.css');
    window.twopayment = { order_intent_url: ORDER_INTENT_URL, ajax_token: 'test-token' };

    fetchCalls = [];
    resolvers = [];
    global.window.__two_test_fetch_rejects = [];
    global.window.fetch = (url) => {
        fetchCalls.push(url);
        return new Promise((resolve, reject) => {
            resolvers.push(resolve);
            global.window.__two_test_fetch_rejects.push(reject);
        });
    };
    global.fetch = global.window.fetch;
});

afterEach(() => {
    releaseWidgets($);
    delete window.twopayment;
    delete global.window.fetch;
    delete global.fetch;
    delete global.window.__two_test_fetch_rejects;
});

describe('fetch on load', () => {
    test('requests the supported-countries list exactly once, via the module controller URL', () => {
        makeInstance();

        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain(ORDER_INTENT_URL);
        expect(fetchCalls[0]).toContain('action=companySearchSupportedCountries');
        expect(fetchCalls[0]).toContain('token=test-token');
    });

    test('a second instance (address-form re-render) does not repeat the request', () => {
        makeInstance();
        makeInstance();

        expect(fetchCalls).toHaveLength(1);
    });
});

describe('country gate', () => {
    test.each([
        ['GB', true, 'billing country is in the fetched list'],
        ['NO', false, 'billing country is absent from the fetched list']
    ])('country=%s -> chip shown=%s (%s)', async (country, expectedShown) => {
        buildAddressForm({ country: country });
        makeInstance();
        await resolveFetch({ success: true, countries: ['GB', 'US'] });
        openPanel();

        expect(shown(panelParts().registered)).toBe(expectedShown);
    });

    test('fails open while the fetch is still in flight - chip stays visible', () => {
        makeInstance();
        openPanel();

        expect(fetchCalls).toHaveLength(1);
        expect(shown(panelParts().registered)).toBe(true);
    });

    test('fails open on a transient fetch error for the supported-countries call itself', async () => {
        makeInstance();
        await rejectFetch();
        openPanel();

        expect(shown(panelParts().registered)).toBe(true);
    });

    test('fails open when the server-side lookup itself is unresolved (countries: null)', async () => {
        makeInstance();
        await resolveFetch({ success: true, countries: null });
        openPanel();

        expect(shown(panelParts().registered)).toBe(true);
    });

    test('a country switch after the list resolves re-evaluates the gate', async () => {
        makeInstance();
        await resolveFetch({ success: true, countries: ['GB'] });
        openPanel();
        expect(shown(panelParts().registered)).toBe(true);

        $("select[name='id_country'] option").get(0).setAttribute('data-iso-code', 'NO');
        $("select[name='id_country']").trigger('change');
        openPanel();

        expect(shown(panelParts().registered)).toBe(false);
    });

    test('"Enter Manually" and "Sole Trader" are unaffected by the country gate', async () => {
        global.window.TwoSoleTrader_Instance = { isAvailableForCurrentCountry: () => true };
        buildAddressForm({ country: 'NO' });
        makeInstance();
        await resolveFetch({ success: true, countries: ['GB'] });
        openPanel();

        expect(shown(panelParts().registered)).toBe(false);
        expect(shown(panelParts().notListed)).toBe(true);
        expect(shown(panelParts().soleTrader)).toBe(true);
        delete global.window.TwoSoleTrader_Instance;
    });
});

/**
 * ABN-525: an uncovered country withdraws the SEARCH and nothing else. The
 * chip row is what carries manual entry, so a country that leaves one chip
 * standing must still render it.
 */
describe('an uncovered country still leaves a route to naming a company', () => {
    beforeEach(() => {
        // No sole-trader route either, so "Enter Manually" is the lone chip
        // and the one the buyer is NOT already in.
        global.window.TwoSoleTrader_Instance = { isAvailableForCurrentCountry: () => false };
    });

    afterEach(() => {
        delete global.window.TwoSoleTrader_Instance;
    });

    test.each([
        ['NO', false, true, 'uncovered: search gone, manual entry standing alone'],
        ['GB', true, true, 'covered: both routes offered']
    ])('country=%s -> registered shown=%s, manual shown=%s (%s)',
        async (country, registeredShown, manualShown) => {
            buildAddressForm({ country: country });
            makeInstance();
            await resolveFetch({ success: true, countries: ['GB', 'US'] });

            openPanel();

            expect(shown(panelParts().registered)).toBe(registeredShown);
            expect(shown(panelParts().notListed)).toBe(manualShown);
        });

    test.each([
        ['NO', false, 'uncovered: no query row to type a doomed search into'],
        ['GB', true, 'covered: the query row is there']
    ])('country=%s -> search row shown=%s (%s)', async (country, rowShown) => {
        buildAddressForm({ country: country });
        makeInstance();
        await resolveFetch({ success: true, countries: ['GB', 'US'] });

        openPanel();

        expect(shown(panelParts().searchRow)).toBe(rowShown);
    });

    test('opening with no query row puts focus on a chip, not outside the panel', async () => {
        buildAddressForm({ country: 'NO' });
        makeInstance();
        await resolveFetch({ success: true, countries: ['GB'] });

        openPanel();

        expect(global.document.activeElement).toBe(panelParts().notListed.get(0));
    });

    test('the panel body is repainted for the term the gate just dropped', async () => {
        // Given: an open panel in a covered country, with a term typed.
        buildAddressForm({ country: 'GB' });
        const instance = makeInstance();
        await resolveFetch({ success: true, countries: ['GB'] });
        openPanel();
        panelParts().query.val('Alp');
        // Blanking the field fires no event, so the rows the old term produced
        // outlive it unless the gate re-renders explicitly.
        let repaints = 0;
        instance.openSearchForCurrentTerm = () => { repaints += 1; };

        // When: the country becomes one company search does not cover.
        $("select[name='id_country'] option").get(0).setAttribute('data-iso-code', 'NO');
        instance.syncModeChipVisibility();

        expect(repaints).toBe(1);
        expect(panelParts().query.val()).toBe('');

        // And a later sync, with nothing left to drop, does not re-render.
        instance.syncModeChipVisibility();
        expect(repaints).toBe(1);
    });

    test('the row comes back when the country becomes covered under an OPEN panel', async () => {
        // Given: an open panel in an uncovered country, so the row is gone.
        buildAddressForm({ country: 'NO' });
        const instance = makeInstance();
        await resolveFetch({ success: true, countries: ['GB'] });
        openPanel();
        expect(shown(panelParts().searchRow)).toBe(false);

        // When: the country becomes one company search covers, with no reopen.
        $("select[name='id_country'] option").get(0).setAttribute('data-iso-code', 'GB');
        instance.syncModeChipVisibility();

        expect(shown(panelParts().searchRow)).toBe(true);
    });

    test('a term typed before the country changed does not survive the gate', async () => {
        buildAddressForm({ country: 'GB' });
        makeInstance();
        await resolveFetch({ success: true, countries: ['GB'] });
        openPanel();
        panelParts().query.val('Alp');

        $("select[name='id_country'] option").get(0).setAttribute('data-iso-code', 'NO');
        $("select[name='id_country']").trigger('change');
        openPanel();

        expect(panelParts().query.val()).toBe('');
    });
});
