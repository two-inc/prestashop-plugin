/**
 * TWO-40 follow-up, Doug live-test finding (2026-08-19): with the billing
 * country set to United Kingdom - a country the registry DOES support sole
 * traders for - the "Sole trader" mode chip did not render in the
 * company-search dropdown at all.
 *
 * Unlike company-search-sole-trader-entry.test.js, which stubs
 * `TwoSoleTrader_Instance` to test TwoCompanySearch's own wiring, these run the
 * REAL TwoSoleTrader beside the real search control: the defects below are in
 * the seam between the two - who resolves the availability answer, when, and
 * who is told once it lands - and a stub on either side hides all three.
 *
 *  1. `{success: false}` (this endpoint's answer for a stale ajax token) was
 *     flattened into `available: false` and cached as a real answer: in memory
 *     for the page, and in localStorage for 24h on EVERY later load, with
 *     nothing that re-asks inside the TTL. One expired token removed the chip
 *     for a day.
 *  2. A negative answer was persisted, so a country becoming eligible - or a
 *     merchant environment being fixed - stayed invisible for up to 24h. It is
 *     now REMOVED from the cache instead, which also stops an earlier "yes"
 *     outliving the country's eligibility.
 *  3. Availability resolves asynchronously and nothing pushed the answer to the
 *     search control, which only reads it when IT re-evaluates - so a panel
 *     opened before the round trip landed had no chip in it and nothing to add
 *     one while it stayed open. TwoSoleTrader.apply() now re-syncs the chip,
 *     the same direction WooCommerce already pushes it.
 */

'use strict';

const {
    loadCompanySearch,
    loadSoleTrader,
    buildAddressForm,
    buildPaymentTileWithSoleTraderAnswer,
    installStylesheet,
    stubAjax,
    releaseWidgets,
    flushPromises,
    panelParts,
    openPanel,
    shown
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://checkout.staging.two.inc';

let TwoCompanySearch;
let TwoSoleTrader;
let $;
let ajax;
let fetchCalls;
let respond;

/** The localStorage key TwoSoleTrader caches an availability answer under. */
function storageKey(country) {
    return 'two_sole_trader_availability::' + CHECKOUT_HOST + '::' + country;
}

/**
 * Both modules, wired to each other exactly as twopayment.js wires them: the
 * search instance is reachable only through the manager (which is what
 * TwoSoleTrader resolves lazily, since the manager rebuilds it on every
 * `updatedAddressForm`), and TwoSoleTrader is reachable through its own global.
 *
 * Search FIRST: TwoSoleTrader resolves availability from its own constructor,
 * so the manager has to be in place before it can push the answer anywhere.
 */
function mount() {
    const search = new TwoCompanySearch({ checkoutHost: CHECKOUT_HOST });
    global.window.TwoCheckoutManager_Instance = { companySearch: search };
    const soleTrader = new TwoSoleTrader({
        checkoutHost: CHECKOUT_HOST,
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        shopCountry: 'ZZ',
        billingCountry: 'GB'
    });
    global.window.TwoSoleTrader_Instance = soleTrader;
    return { search: search, soleTrader: soleTrader };
}

/** Let the debounced observer callback and any promise chain run. */
async function drain() {
    jest.advanceTimersByTime(150);
    await flushPromises();
    jest.advanceTimersByTime(150);
    await flushPromises();
}

/** A DOM mutation, i.e. what re-triggers a refresh on a real checkout. */
function mutateBody() {
    global.document.body.appendChild(global.document.createElement('span'));
}

beforeEach(() => {
    jest.useFakeTimers();
    document.body.innerHTML = '';
    document.head.innerHTML = '';
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    // Defaults to GB, `data-iso-code` and all - the country under test.
    buildAddressForm();
    installStylesheet('views/css/two.css');
    ajax = stubAjax($);
    window.twopayment = { checkout_host: CHECKOUT_HOST, countries: { 17: 'gb' } };

    delete global.window.TwoSoleTrader;
    TwoSoleTrader = loadSoleTrader();

    fetchCalls = [];
    respond = () => Promise.resolve({ success: true, available: true });
    global.window.fetch = (url) => {
        fetchCalls.push(url);
        return Promise.resolve({ json: () => respond() });
    };
    global.fetch = global.window.fetch;
});

afterEach(() => {
    if (global.window.TwoSoleTrader_Instance) {
        global.window.TwoSoleTrader_Instance.destroy();
        delete global.window.TwoSoleTrader_Instance;
    }
    delete global.window.TwoCheckoutManager_Instance;
    releaseWidgets($);
    ajax.restore();
    jest.useRealTimers();
    document.body.innerHTML = '';
    delete window.twopayment;
    delete global.window.fetch;
    delete global.fetch;
    global.window.localStorage.clear();
});

describe('the "Sole trader" chip renders for a supported billing country (GB)', () => {
    test('shown once the availability answer has landed', async () => {
        // Given a GB address step and a registry answer of "supported"
        mount();
        await drain();

        // When the buyer opens the company-search panel
        openPanel();

        // Then the chip is there
        expect(fetchCalls).toHaveLength(1);
        expect(fetchCalls[0]).toContain('country=GB');
        expect(shown(panelParts().soleTrader)).toBe(true);
        expect(panelParts().soleTrader.text()).toBe('Sole trader');
    });

    test('appears without a reopen when the answer lands while the panel is already open', async () => {
        // Given a round trip still in flight (the whole address step, on a
        // first visit: no server-rendered answer to adopt, nothing cached)
        let settle;
        respond = () => new Promise((resolve) => { settle = resolve; });
        mount();
        jest.advanceTimersByTime(150);
        await flushPromises();

        // When the buyer opens the panel BEFORE it lands...
        openPanel();
        expect(shown(panelParts().soleTrader)).toBe(false);

        // ...and it then lands, with the panel still open
        settle({ success: true, available: true });
        await drain();

        // Then the chip appears in the open panel - no close/reopen needed
        expect(shown(panelParts().soleTrader)).toBe(true);
    });

    test('a country that genuinely does not support sole traders still shows no chip', async () => {
        respond = () => Promise.resolve({ success: true, available: false });
        mount();
        await drain();

        openPanel();

        expect(shown(panelParts().soleTrader)).toBe(false);
    });
});

describe('an error response is not an availability answer', () => {
    // Every shape this endpoint answers with when it declines to answer at
    // all - all of them HTTP 200 with a JSON body, so none reaches the
    // transport-failure catch().
    const declined = [
        [{ success: false, error: 'Invalid token' }, 'stale/absent ajax token'],
        [{ success: false, error: 'Unknown action requested.' }, 'unknown action'],
        [{}, 'no success field at all'],
        [null, 'empty body']
    ];

    test.each(declined)('%j is never cached, in memory or in localStorage (%s)', async (body) => {
        respond = () => Promise.resolve(body);
        const { soleTrader } = mount();

        await drain();

        expect(fetchCalls).toHaveLength(1);
        // Fail-soft on screen, as a transport failure already was...
        expect(soleTrader.isAvailableForCurrentCountry()).toBe(false);
        // ...but nothing recorded as an answer about GB, either cache.
        expect(soleTrader.availabilityByCountry.GB).toBeUndefined();
        expect(window.localStorage.getItem(storageKey('GB'))).toBeNull();
    });

    test('a later trigger re-asks, and the chip appears once a real answer arrives', async () => {
        // Given the first request was declined
        respond = () => Promise.resolve({ success: false, error: 'Invalid token' });
        mount();
        await drain();
        openPanel();
        expect(shown(panelParts().soleTrader)).toBe(false);

        // When anything triggers another refresh (a fragment re-render, a
        // country change) and the endpoint answers properly this time
        respond = () => Promise.resolve({ success: true, available: true });
        mutateBody();
        await drain();

        // Then the chip is back - the decline did not poison the country
        expect(fetchCalls).toHaveLength(2);
        expect(shown(panelParts().soleTrader)).toBe(true);
    });
});

describe('the persisted cache never holds a negative answer', () => {
    test('a real "not available" answer writes nothing', async () => {
        respond = () => Promise.resolve({ success: true, available: false });
        mount();

        await drain();

        expect(window.localStorage.getItem(storageKey('GB'))).toBeNull();
    });

    test('a server-rendered "not available" REMOVES a stale cached "available"', () => {
        // The shape that made this sticky both ways: yesterday's cached "yes"
        // for GB, and a payment step that has just rendered "no". Skipping the
        // write would leave the "yes" standing for the rest of its 24h.
        window.localStorage.setItem(
            storageKey('GB'),
            JSON.stringify({ available: true, ts: Date.now() })
        );
        buildPaymentTileWithSoleTraderAnswer('0', 'GB');

        const { soleTrader } = mount();

        expect(soleTrader.isAvailableForCurrentCountry()).toBe(false);
        expect(window.localStorage.getItem(storageKey('GB'))).toBeNull();
    });

    test('a positive answer is still persisted, so a later load paints with no round trip', async () => {
        mount();
        await drain();

        const cached = JSON.parse(window.localStorage.getItem(storageKey('GB')));
        expect(cached.available).toBe(true);
    });
});
