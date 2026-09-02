/**
 * Every call the module makes to Two's API identifies the client that made it,
 * with `client` (the platform) and `client_v` (the module version). The PHP
 * side has always done this - getTwoClientParams() in twopayment.php, attached
 * by setTwoPaymentRequest() - and so does the one call the BROWSER still makes
 * directly to Two (sole-trader autofill). Company search, company detail and
 * order-intent are relayed through this module's own controller instead
 * (TWO-25386 follow-up: the firewall token those calls used to need in the
 * browser now stays server-side, alongside the client-identification pair
 * that setTwoPaymentRequest() already attaches on that side).
 *
 * These tests pin:
 *
 *  - The one remaining direct call (sole-trader autofill) still carries the
 *    pair, from the server-published config
 *    (`window.twopayment.client` / `.client_version`), never a literal in the
 *    JS. A version bump must be a PHP-only change; a hardcoded 'PS' or
 *    '2.7.8' here is the defect these tests exist to catch.
 *
 *  - The three relayed calls (company search, company detail, order intent)
 *    go to the module's own controller with the right action/token/params,
 *    and do NOT carry the client-identification pair themselves - that would
 *    be redundant with (and could drift from) what the server-side call
 *    already attaches.
 *
 *  - The sole-trader autofill call attaches exactly the custom headers the
 *    merchant ticked for browser traffic, and nothing when none were - the
 *    one place a merchant-configured header still reaches the browser.
 *
 * The version deliberately carries a `+<sha7>` suffix throughout, because that
 * is what getTwoClientVersion() produces on a deployed build and it is the part
 * most likely to break: `+` must reach the wire percent-encoded (`%2B`), the
 * way PHP's http_build_query() encodes it. A literal `+` in a query string
 * decodes to a SPACE, which would silently report a different version than the
 * one running.
 */

'use strict';

const {
    loadCompanySearch,
    loadOrderIntent,
    loadSoleTrader,
    buildAddressForm,
    buildPaymentTile,
    stubAjax,
    callbackRecorder,
    releaseWidgets,
    flushPromises
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

/** What twopayment.php publishes on a deployed build: version + commit sha. */
const CLIENT = 'PS';
const CLIENT_VERSION = '2.7.8+abc1234';
/** The same value as it must appear on the wire. */
const CLIENT_VERSION_ENCODED = '2.7.8%2Babc1234';

/**
 * Assert a URL carries the identification pair, decoded AND as raw text.
 *
 * Both halves matter and neither implies the other: searchParams.get() proves
 * the value survives a round trip, and the raw-text check proves the encoding
 * used to get there is the percent-encoding PHP produces rather than a literal
 * `+` that happens to decode to something.
 *
 * @param {string} url the fully constructed request URL
 */
function expectClientParams(url) {
    const parsed = new URL(url);
    expect(parsed.searchParams.get('client')).toBe(CLIENT);
    expect(parsed.searchParams.get('client_v')).toBe(CLIENT_VERSION);
    expect(String(url)).toContain('client=' + CLIENT);
    expect(String(url)).toContain('client_v=' + CLIENT_VERSION_ENCODED);
}

/** The config the server publishes, as the real page has it before any module runs. */
function publishConfig(extra) {
    global.window.twopayment = Object.assign({
        client: CLIENT,
        client_version: CLIENT_VERSION,
        checkout_host: CHECKOUT_HOST,
        company_search_country: 'GB',
        i18n: {}
    }, extra || {});
}

// ---------------------------------------------------------------------------
// withTwoClientParams: only TwoSoleTrader still owns a copy - the other two
// classes no longer build a direct-to-Two URL at all.
// ---------------------------------------------------------------------------

describe('withTwoClientParams (TwoSoleTrader)', () => {
    let TwoSoleTrader;

    beforeEach(() => {
        buildAddressForm({ country: 'GB' });
        TwoSoleTrader = loadSoleTrader();
        publishConfig();
    });

    afterEach(() => {
        delete global.window.twopayment;
        document.body.innerHTML = '';
    });

    test('appends the pair to a URL with no query string', () => {
        const url = TwoSoleTrader.withTwoClientParams('https://api.example.test/v1/thing');

        expect(url).toBe(
            'https://api.example.test/v1/thing?client=' + CLIENT + '&client_v=' + CLIENT_VERSION_ENCODED
        );
    });

    test('appends with & to a URL that already has a query string', () => {
        const url = TwoSoleTrader.withTwoClientParams('https://api.example.test/v1/thing?q=exa');

        expect(url).toBe(
            'https://api.example.test/v1/thing?q=exa&client=' + CLIENT + '&client_v=' + CLIENT_VERSION_ENCODED
        );
        // The existing query must survive intact, not be replaced.
        expect(new URL(url).searchParams.get('q')).toBe('exa');
    });

    test('percent-encodes the sha suffix rather than emitting a literal +', () => {
        const url = TwoSoleTrader.withTwoClientParams('https://api.example.test/v1/thing');

        // A literal `+` here would decode to a space and report the version
        // as "2.7.8 abc1234".
        expect(url).not.toContain('2.7.8+abc1234');
        expect(new URL(url).searchParams.get('client_v')).toBe(CLIENT_VERSION);
    });

    test('reads the version from config rather than a literal', () => {
        // The whole point of the config indirection: a version bump is a
        // PHP-only change. If the version were baked into the JS, this
        // would still report the old one.
        global.window.twopayment.client_version = '9.9.9+deadbee';

        expect(TwoSoleTrader.withTwoClientParams('https://api.example.test/v1/thing'))
            .toBe('https://api.example.test/v1/thing?client=PS&client_v=9.9.9%2Bdeadbee');
    });

    // A page without the config is not a page that should send
    // `client=undefined` - an unparseable version is worse to anything
    // aggregating these than an absent one. Each case states the exact URL
    // it expects, so a helper that emitted a stray `?`, dropped the param it
    // DOES have, or passed `undefined` through fails on the value rather
    // than on a "contains no undefined" check that a broken helper could
    // also satisfy.
    const partialConfigCases = [
        ['no config at all', undefined, 'https://api.example.test/v1/thing'],
        ['config carrying neither key', {}, 'https://api.example.test/v1/thing'],
        ['client only, no version', { client: CLIENT }, 'https://api.example.test/v1/thing?client=PS'],
        [
            'version only, no client',
            { client_version: CLIENT_VERSION },
            'https://api.example.test/v1/thing?client_v=' + CLIENT_VERSION_ENCODED
        ]
    ];

    test.each(partialConfigCases)(
        'sends only what config carries, never a literal undefined (%s)',
        (caseLabel, config, expectedUrl) => {
            if (config === undefined) {
                delete global.window.twopayment;
            } else {
                global.window.twopayment = config;
            }

            const url = TwoSoleTrader.withTwoClientParams('https://api.example.test/v1/thing');

            expect(url).toBe(expectedUrl);
            expect(url).not.toContain('undefined');
        }
    );
});

// ---------------------------------------------------------------------------
// Call sites 1 + 2: company search and company detail, relayed through the
// module's own controller (TwoCompanySearch).
// ---------------------------------------------------------------------------

describe('company search calls are relayed through the module controller', () => {
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
        publishConfig({ order_intent_url: ORDER_INTENT_URL, ajax_token: 'test-token' });
    });

    afterEach(() => {
        releaseWidgets($);
        ajax.restore();
        delete global.window.twopayment;
        document.body.innerHTML = '';
    });

    function makeInstance() {
        return new TwoCompanySearch({ checkoutHost: CHECKOUT_HOST });
    }

    test('search relays action=companySearch with the ajax token and query, never to Two directly', () => {
        const search = makeInstance();
        search.searchCompanies('exa', callbackRecorder().fn);

        const call = ajax.last();
        expect(call.url).toBe(ORDER_INTENT_URL);
        expect(call.settings.data).toMatchObject({
            action: 'companySearch',
            token: 'test-token',
            q: 'exa',
            country: 'GB'
        });
        // Never the client-identification pair here - that is attached
        // server-side, on the request setTwoPaymentRequest() makes to Two.
        expect(call.url).not.toContain('client=');
    });

    test('detail lookup relays action=companyDetails with the lookup id', async () => {
        const search = makeInstance();
        search.fetchCompanyDetails('lookup-abc-123');

        const call = ajax.last();
        expect(call.url).toBe(ORDER_INTENT_URL);
        expect(call.settings.data).toMatchObject({
            action: 'companyDetails',
            token: 'test-token',
            lookup_id: 'lookup-abc-123'
        });
        expect(call.url).not.toContain('client=');
        await flushPromises();
    });
});

// ---------------------------------------------------------------------------
// Call site 3: order intent, relayed through the module's own controller
// (TwoOrderIntent).
// ---------------------------------------------------------------------------

describe('order intent is relayed through the module controller', () => {
    let TwoOrderIntent;
    let $;
    let ajax;

    const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';

    beforeEach(() => {
        buildAddressForm({ country: 'GB' });
        const loaded = loadCompanySearch();
        $ = loaded.$;
        TwoOrderIntent = loadOrderIntent();
        ajax = stubAjax($);
        publishConfig({
            order_intent_url: ORDER_INTENT_URL,
            ajax_token: 'test-token'
        });
    });

    afterEach(() => {
        releaseWidgets($);
        ajax.restore();
        delete global.window.twopayment;
        document.body.innerHTML = '';
    });

    test('relays action=orderIntent with the JSON payload as a string field, never straight to Two', () => {
        const intent = new TwoOrderIntent({ checkoutHost: CHECKOUT_HOST });
        intent.callTwoOrderIntent({ gross_amount: '100.00' });

        const call = ajax.last();
        expect(call.url).toBe(ORDER_INTENT_URL);
        expect(call.settings.type).toBe('POST');
        expect(call.settings.data.action).toBe('orderIntent');
        expect(call.settings.data.token).toBe('test-token');
        expect(JSON.parse(call.settings.data.payload)).toEqual({ gross_amount: '100.00' });
        // Never the client-identification pair here - see the company-search
        // describe block above for why.
        expect(call.url).not.toContain('client=');
    });
});

// ---------------------------------------------------------------------------
// Call site 4: sole-trader autofill (fetch, TwoSoleTrader) - the one call
// still made directly to Two, and the one place a merchant-configured header
// still reaches the browser.
// ---------------------------------------------------------------------------

describe('sole-trader autofill carries the client identification and the browser-flagged headers', () => {
    let TwoSoleTrader;

    beforeEach(() => {
        buildPaymentTile();
        TwoSoleTrader = loadSoleTrader();
        publishConfig();
        global.window.TwoCompanyNumber = { forDisplay: (v) => v };
    });

    afterEach(() => {
        delete global.window.TwoCheckoutManager_Instance;
        delete global.fetch;
        delete global.window.fetch;
        delete global.window.TwoCompanyNumber;
        delete global.window.twopayment;
        document.body.innerHTML = '';
        global.window.localStorage.clear();
    });

    function stubFetchCapturing(calls) {
        global.window.fetch = (url, options) => {
            calls.push({ url: String(url), options: options || {} });
            if (String(url).includes('soleTraderAvailability')) {
                return Promise.resolve({
                    json: () => Promise.resolve({ success: true, available: true })
                });
            }
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve(null)
            });
        };
        global.fetch = global.window.fetch;
    }

    function makeInstance() {
        return new TwoSoleTrader({
            checkoutHost: CHECKOUT_HOST,
            orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
            ajaxToken: 'test-token',
            billingCountry: 'GB'
        });
    }

    test('GET /autofill/v1/buyer/current carries client and client_v', async () => {
        const calls = [];
        stubFetchCapturing(calls);

        const instance = makeInstance();
        // getCurrentBuyer() reads this.tokens.autofill_token, which a real
        // enrolment mints first; seeding it goes straight to the call under
        // test without replaying the whole popup flow.
        instance.tokens = { autofill_token: 'af-token' };
        instance.getCurrentBuyer();
        await flushPromises();

        const autofillCall = calls.find((call) => call.url.includes('/autofill/v1/buyer/current'));
        expect(autofillCall).toBeDefined();
        expectClientParams(autofillCall.url);
        expect(new URL(autofillCall.url).pathname).toBe('/autofill/v1/buyer/current');
    });

    async function headersFromAutofillCall() {
        const calls = [];
        stubFetchCapturing(calls);

        const instance = makeInstance();
        instance.tokens = { autofill_token: 'af-token' };
        instance.getCurrentBuyer();
        await flushPromises();

        return calls.find((call) => call.url.includes('/autofill/v1/buyer/current')).options.headers;
    }

    test('attaches every header the module published for the browser', async () => {
        global.window.twopayment.custom_headers = { 'X-WAF-TOKEN': 'waf-token-1', 'X-Corp-Gate': 'gate-2' };

        const headers = await headersFromAutofillCall();

        expect(headers['X-WAF-TOKEN']).toBe('waf-token-1');
        expect(headers['X-Corp-Gate']).toBe('gate-2');
        // The delegated-authority token must still travel alongside them.
        expect(headers['two-delegated-authority-token']).toBe('af-token');
    });

    test('attaches nothing extra when no row was ticked for the browser', async () => {
        global.window.twopayment.custom_headers = {};

        expect(Object.keys(await headersFromAutofillCall())).toEqual(['two-delegated-authority-token']);
    });

    test('attaches nothing extra when the module published no header map at all', async () => {
        delete global.window.twopayment.custom_headers;

        expect(Object.keys(await headersFromAutofillCall())).toEqual(['two-delegated-authority-token']);
    });

    test('the shop-internal module URLs do NOT carry the pair', () => {
        // The constructor's own refreshAvailability() fires a request that has
        // nothing to do with this assertion; answer it so the instance builds.
        global.window.fetch = () => Promise.resolve({
            json: () => Promise.resolve({ success: true, available: true })
        });
        global.fetch = global.window.fetch;

        const instance = makeInstance();

        // These go to the shop's own front controller, not to Two. Sending
        // Two's identification pair there is noise at best, and it would read
        // as evidence the helper is being applied by reflex rather than to the
        // calls that are actually Two's.
        const moduleUrl = instance.moduleUrl('soleTraderTokens');
        expect(moduleUrl).not.toContain('client=');
        expect(moduleUrl).not.toContain('client_v=');
    });
});
