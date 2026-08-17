/**
 * Every call the module makes to Two's API identifies the client that made it,
 * with `client` (the platform) and `client_v` (the module version). The PHP
 * side has always done this - getTwoClientParams() in twopayment.php, attached
 * by setTwoPaymentRequest() - but the four calls the BROWSER makes directly to
 * Two sent neither, so anything counting clients or versions saw a PrestaShop
 * shop's traffic as partly unattributed.
 *
 * These tests pin all four browser call sites, plus the two properties that
 * make the pair trustworthy:
 *
 *  - The values come from the server-published config
 *    (`window.twopayment.client` / `.client_version`), never from a literal in
 *    the JS. A version bump must be a PHP-only change; a hardcoded 'PS' or
 *    '2.7.8' here is the defect these tests exist to catch.
 *
 *  - They are QUERY params, not body fields, on the POST as well as the GETs.
 *    That is not a choice made for the browser: setTwoPaymentRequest()'s
 *    POST/PUT branch appends them to the URL too, and the two clients reporting
 *    the same fact in different places is what this pins against.
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
 * @param {string} label which call site, for the assertion message
 */
function expectClientParams(url, label) {
    const parsed = new URL(url);
    expect(parsed.searchParams.get('client')).toBe(CLIENT);
    expect(parsed.searchParams.get('client_v')).toBe(CLIENT_VERSION);
    expect(String(url)).toContain('client=' + CLIENT);
    expect(String(url)).toContain('client_v=' + CLIENT_VERSION_ENCODED);
    // Guards the assertion itself: a helper that silently matched nothing would
    // pass every check above on a URL that happened to contain the substrings.
    expect(label).toBeTruthy();
}

/** The config the server publishes, as the real page has it before any module runs. */
function publishConfig(extra) {
    global.window.twopayment = Object.assign({
        client: CLIENT,
        client_version: CLIENT_VERSION,
        checkout_host: CHECKOUT_HOST,
        billing_country: 'GB',
        i18n: {}
    }, extra || {});
}

// ---------------------------------------------------------------------------
// The shared helper, once per class that owns a copy of it.
// ---------------------------------------------------------------------------

describe('withTwoClientParams', () => {
    let classes;

    beforeEach(() => {
        buildAddressForm({ country: 'GB' });
        const loaded = loadCompanySearch();
        classes = {
            TwoCompanySearch: loaded.TwoCompanySearch,
            TwoOrderIntent: loadOrderIntent(),
            TwoSoleTrader: loadSoleTrader()
        };
        publishConfig();
    });

    afterEach(() => {
        delete global.window.twopayment;
        document.body.innerHTML = '';
    });

    // Each module is registered as its own standalone script with no shared
    // util file, so each carries its own copy of the helper. Parameterised so
    // one copy drifting from the others fails here by name rather than showing
    // up as a missing param on whichever call site happens to be tested.
    const owners = [
        ['TwoCompanySearch', 'company search + company detail'],
        ['TwoOrderIntent', 'order intent'],
        ['TwoSoleTrader', 'sole-trader autofill']
    ];

    describe.each(owners)('%s', (className, description) => {
        test(`appends the pair to a URL with no query string (${description})`, () => {
            const url = classes[className].withTwoClientParams('https://api.example.test/v1/thing');

            expect(url).toBe(
                'https://api.example.test/v1/thing?client=' + CLIENT + '&client_v=' + CLIENT_VERSION_ENCODED
            );
        });

        test(`appends with & to a URL that already has a query string (${description})`, () => {
            const url = classes[className].withTwoClientParams('https://api.example.test/v1/thing?q=exa');

            expect(url).toBe(
                'https://api.example.test/v1/thing?q=exa&client=' + CLIENT + '&client_v=' + CLIENT_VERSION_ENCODED
            );
            // The existing query must survive intact, not be replaced.
            expect(new URL(url).searchParams.get('q')).toBe('exa');
        });

        test(`percent-encodes the sha suffix rather than emitting a literal + (${description})`, () => {
            const url = classes[className].withTwoClientParams('https://api.example.test/v1/thing');

            // A literal `+` here would decode to a space and report the version
            // as "2.7.8 abc1234".
            expect(url).not.toContain('2.7.8+abc1234');
            expect(new URL(url).searchParams.get('client_v')).toBe(CLIENT_VERSION);
        });

        test(`reads the version from config rather than a literal (${description})`, () => {
            // The whole point of the config indirection: a version bump is a
            // PHP-only change. If the version were baked into the JS, this
            // would still report the old one.
            global.window.twopayment.client_version = '9.9.9+deadbee';

            expect(classes[className].withTwoClientParams('https://api.example.test/v1/thing'))
                .toBe('https://api.example.test/v1/thing?client=PS&client_v=9.9.9%2Bdeadbee');
        });

        // A page without the config is not a page that should send
        // `client=undefined` - an unparseable version is worse than a missing
        // one for anything aggregating these.
        const absentConfigCases = [
            [undefined, 'no config at all'],
            [{}, 'config present but carrying neither key'],
            [{ client: 'PS' }, 'client only, no version'],
            [{ client_version: CLIENT_VERSION }, 'version only, no client']
        ];

        test.each(absentConfigCases)(
            `omits what config does not carry, never a literal undefined (%#: %s)`,
            (config, caseLabel) => {
                if (config === undefined) {
                    delete global.window.twopayment;
                } else {
                    global.window.twopayment = config;
                }

                const url = classes[className].withTwoClientParams('https://api.example.test/v1/thing');

                expect(url).not.toContain('undefined');
                if (config && config.client && config.client_version) {
                    throw new Error('case ' + caseLabel + ' is not an absent-config case');
                }
                // A URL with nothing to add comes back untouched - no stray `?`.
                if (!config || (!config.client && !config.client_version)) {
                    expect(url).toBe('https://api.example.test/v1/thing');
                }
            }
        );
    });
});

// ---------------------------------------------------------------------------
// Call site 1 + 2: company search and company detail (GET, TwoCompanySearch).
// ---------------------------------------------------------------------------

describe('company search calls carry the client identification', () => {
    let TwoCompanySearch;
    let $;
    let ajax;

    beforeEach(() => {
        buildAddressForm({ country: 'GB' });
        const loaded = loadCompanySearch();
        TwoCompanySearch = loaded.TwoCompanySearch;
        $ = loaded.$;
        ajax = stubAjax($);
        publishConfig();
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

    test('GET /companies/v2/company (search) carries client and client_v', () => {
        const search = makeInstance();
        search.searchCompanies('exa', callbackRecorder().fn);

        const url = ajax.last().url;
        expectClientParams(url, 'company search');
        // The search's own params are untouched by the addition.
        const parsed = new URL(url);
        expect(parsed.pathname).toBe('/companies/v2/company');
        expect(parsed.searchParams.get('q')).toBe('exa');
        expect(parsed.searchParams.get('country')).toBe('GB');
    });

    test('GET /companies/v2/company/{lookupId} (detail) carries client and client_v', async () => {
        const search = makeInstance();

        // fetchCompanyDetails() is the boundary under test; driving it directly
        // keeps this independent of the dropdown-selection plumbing that
        // company-search-rerender.test.js already covers.
        search.fetchCompanyDetails('lookup-abc-123');

        const url = ajax.last().url;
        expectClientParams(url, 'company detail');
        // The detail endpoint had NO query string before this change, so the
        // pair has to arrive behind a `?` rather than an `&`.
        const parsed = new URL(url);
        expect(parsed.pathname).toBe('/companies/v2/company/lookup-abc-123');
        expect(url).toContain('/companies/v2/company/lookup-abc-123?client=');
        await flushPromises();
    });
});

// ---------------------------------------------------------------------------
// Call site 3: order intent (POST with a JSON body, TwoOrderIntent).
// ---------------------------------------------------------------------------

describe('order intent POST carries the client identification', () => {
    let TwoOrderIntent;
    let $;
    let ajax;

    beforeEach(() => {
        buildAddressForm({ country: 'GB' });
        const loaded = loadCompanySearch();
        $ = loaded.$;
        TwoOrderIntent = loadOrderIntent();
        ajax = stubAjax($);
        publishConfig({
            order_intent_url: 'https://shop.example.test/module/twopayment/orderintent',
            ajax_token: 'test-token'
        });
    });

    afterEach(() => {
        releaseWidgets($);
        ajax.restore();
        delete global.window.twopayment;
        document.body.innerHTML = '';
    });

    test('POST /v1/order_intent carries client and client_v as QUERY params', () => {
        const intent = new TwoOrderIntent({ checkoutHost: CHECKOUT_HOST });
        intent.callTwoOrderIntent({ gross_amount: '100.00' });

        const call = ajax.last();
        expectClientParams(call.url, 'order intent');
        expect(new URL(call.url).pathname).toBe('/v1/order_intent');
        expect(call.settings.type).toBe('POST');
    });

    test('the JSON body is NOT given client fields, matching the PHP convention', () => {
        const intent = new TwoOrderIntent({ checkoutHost: CHECKOUT_HOST });
        intent.callTwoOrderIntent({ gross_amount: '100.00' });

        // setTwoPaymentRequest()'s POST/PUT branch puts these on the URL and
        // leaves the payload alone. Two clients disagreeing about where the
        // pair lives is the thing this asserts against - and the payload here
        // is one the server built and Two validates, so adding to it is a
        // contract change rather than a metadata addition.
        const body = JSON.parse(ajax.last().settings.data);
        expect(body).toEqual({ gross_amount: '100.00' });
        expect(body.client).toBeUndefined();
        expect(body.client_v).toBeUndefined();
        expect(body.client_version).toBeUndefined();
    });
});

// ---------------------------------------------------------------------------
// Call site 4: sole-trader autofill (fetch, TwoSoleTrader).
// ---------------------------------------------------------------------------

describe('sole-trader autofill carries the client identification', () => {
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

    test('GET /autofill/v1/buyer/current carries client and client_v', async () => {
        const urls = [];
        global.window.fetch = (url) => {
            urls.push(String(url));
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

        const instance = new TwoSoleTrader({
            checkoutHost: CHECKOUT_HOST,
            orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
            ajaxToken: 'test-token',
            billingCountry: 'GB'
        });
        // getCurrentBuyer() reads this.tokens.autofill_token, which a real
        // enrolment mints first; seeding it goes straight to the call under
        // test without replaying the whole popup flow.
        instance.tokens = { autofill_token: 'af-token' };
        instance.getCurrentBuyer();
        await flushPromises();

        const autofillUrl = urls.find((url) => url.includes('/autofill/v1/buyer/current'));
        expect(autofillUrl).toBeDefined();
        expectClientParams(autofillUrl, 'sole-trader autofill');
        expect(new URL(autofillUrl).pathname).toBe('/autofill/v1/buyer/current');
    });

    test('the shop-internal module URLs do NOT carry the pair', () => {
        // The constructor's own refreshAvailability() fires a request that has
        // nothing to do with this assertion; answer it so the instance builds.
        global.window.fetch = () => Promise.resolve({
            json: () => Promise.resolve({ success: true, available: true })
        });
        global.fetch = global.window.fetch;

        const instance = new TwoSoleTrader({
            checkoutHost: CHECKOUT_HOST,
            orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
            ajaxToken: 'test-token',
            billingCountry: 'GB'
        });

        // These go to the shop's own front controller, not to Two. Sending
        // Two's identification pair there is noise at best, and it would read
        // as evidence the helper is being applied by reflex rather than to the
        // calls that are actually Two's.
        const moduleUrl = instance.moduleUrl('soleTraderTokens');
        expect(moduleUrl).not.toContain('client=');
        expect(moduleUrl).not.toContain('client_v=');
    });
});
