/**
 * TWO-40: the mint request carries the country.
 *
 * Enrolment can start from the address-editor page, where the cart has no
 * invoice address yet, so the server has nothing else to gate on. The country
 * sent is `billingCountry()` - the same resolver the chip's visibility is
 * decided from - so the mint cannot be authorised against a different country
 * than the chip was shown for.
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
    // TwoSoleTrader.js expects jQuery and TwoCompanyNumber already in place.
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
