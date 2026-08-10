/**
 * TWO-40: the mint request carries the country.
 *
 * A sole trader has to be able to START enrolment from the address-editor page.
 * The cart has no invoice address at that point in checkout, so the server has
 * nothing else to gate on - and the country sent is `billingCountry()`, the very
 * resolver the "I'm a sole trader" chip's own visibility is decided from, so the
 * country the mint is authorised against cannot disagree with the country the
 * chip was shown for.
 *
 * The server half is in `tests/SoleTraderTokenPreconditionSpec.php`.
 */

'use strict';

const {
    loadCompanySearch,
    loadScript,
    buildAddressForm,
    releaseWidgets,
    flushPromises
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';

let $;

beforeEach(() => {
    // Installs jQuery and TwoCompanyNumber, both of which TwoSoleTrader.js
    // expects to be in place before it loads (see the harness).
    const loaded = loadCompanySearch();
    $ = loaded.$;
});

afterEach(() => {
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
    delete global.fetch;
    delete global.window.fetch;
    delete global.window.TwoSoleTrader_Instance;
});

describe('the mint request carries the buyer\'s current country', () => {
    /**
     * Driven through the real fetchTokens(), with the country only reachable
     * from the DOM select - so a regression to a config-time value, or to no
     * country at all, fails here rather than passing on a hardcoded fixture.
     */
    test('fetchTokens POSTs the country billingCountry() resolves, urlencoded', async () => {
        buildAddressForm({ country: 'NO' });
        loadScript('views/js/modules/TwoSoleTrader.js');
        const TwoSoleTrader = window.TwoSoleTrader;

        const calls = [];
        global.window.fetch = (url, options) => {
            calls.push({ url: String(url), options: options });
            if (String(url).includes('soleTraderAvailability')) {
                return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
            }
            return Promise.resolve({ json: () => Promise.resolve({ success: false }) });
        };
        global.fetch = global.window.fetch;

        const soleTrader = new TwoSoleTrader({
            checkoutHost: CHECKOUT_HOST,
            orderIntentUrl: ORDER_INTENT_URL,
            ajaxToken: 'test-token'
        });
        soleTrader.fetchTokens();
        await flushPromises();

        const mint = calls.filter((call) => call.url.includes('soleTraderTokens'));
        expect(mint).toHaveLength(1);
        expect(mint[0].options.method).toBe('POST');
        expect(mint[0].options.headers['Content-Type']).toBe('application/x-www-form-urlencoded');
        expect(mint[0].options.body).toBe('country=NO');
        soleTrader.destroy();
    });

    /**
     * The agreement that makes the gate coherent, asserted as an equality
     * rather than against a second hardcoded ISO code: whatever the chip's
     * visibility resolver answers is what goes on the wire. A future change
     * that gives the mint its own country source has to break this.
     */
    test('the country sent is the same one the chip\'s visibility is decided from', async () => {
        buildAddressForm({ country: 'SE' });
        loadScript('views/js/modules/TwoSoleTrader.js');
        const TwoSoleTrader = window.TwoSoleTrader;

        const calls = [];
        global.window.fetch = (url, options) => {
            calls.push({ url: String(url), options: options });
            if (String(url).includes('soleTraderAvailability')) {
                return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
            }
            return Promise.resolve({ json: () => Promise.resolve({ success: false }) });
        };
        global.fetch = global.window.fetch;

        const soleTrader = new TwoSoleTrader({
            checkoutHost: CHECKOUT_HOST,
            orderIntentUrl: ORDER_INTENT_URL,
            ajaxToken: 'test-token'
        });
        soleTrader.fetchTokens();
        await flushPromises();

        const mint = calls.filter((call) => call.url.includes('soleTraderTokens'));
        expect(mint).toHaveLength(1);
        expect(mint[0].options.body).toBe('country=' + soleTrader.billingCountry());
        // Not vacuous: billingCountry() resolving to '' would satisfy the
        // equality above while sending no country at all.
        expect(soleTrader.billingCountry()).toBe('SE');
        soleTrader.destroy();
    });
});
