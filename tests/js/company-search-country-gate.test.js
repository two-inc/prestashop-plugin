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
