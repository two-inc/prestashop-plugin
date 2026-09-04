/**
 * TWO-40 follow-up: the eager mint/autofill trigger must intersect the
 * registry's per-country answer with the merchant's OWN buyer-country gate
 * (`merchantBuyerCountriesState`/`merchantBuyerCountries`, mirroring
 * Twopayment::BUYER_COUNTRIES_*), not just the registry answer alone.
 *
 * Three states, not two - see resolveMerchantCountryGate() in
 * TwoSoleTrader.js: an ABSENT field must never be read as an empty
 * allowlist, or an unrestricted merchant would mint for nobody.
 */

'use strict';

const {
    loadSoleTrader,
    buildPaymentTileWithSoleTraderAnswer,
    flushPromises
} = require('./ps-harness');

let TwoSoleTrader;
let instances;

function build(overrides) {
    const instance = new TwoSoleTrader(Object.assign({
        checkoutHost: 'https://api.example.test',
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        billingCountry: 'GB'
    }, overrides || {}));
    instances.push(instance);

    return instance;
}

function stubFetch() {
    const calls = { mints: 0 };
    global.window.fetch = (url) => {
        const target = String(url);
        if (target.includes('soleTraderTokens')) {
            calls.mints += 1;
            return Promise.resolve({
                json: () => Promise.resolve({
                    success: true,
                    autofill_token: 'af-token',
                    delegation_token: 'del-token',
                    signup_url: 'https://signup.example.test/',
                    country: 'GB'
                })
            });
        }
        if (target.includes('/autofill/v1/buyer/current')) {
            return Promise.resolve({ ok: false, status: 404 });
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;

    return calls;
}

beforeEach(() => {
    TwoSoleTrader = null;
    instances = [];
});

afterEach(() => {
    instances.forEach((instance) => instance.destroy());
    instances = [];
    delete global.fetch;
    delete global.window.fetch;
    document.body.innerHTML = '';
    global.window.localStorage.clear();
});

describe.each([
    [undefined, undefined, true, 'an absent state (config predates this key) is read as unrestricted'],
    ['absent', undefined, true, 'the explicit ABSENT state is unrestricted'],
    ['empty', [], false, 'a PRESENT, EMPTY allowlist is a deliberate deny-all'],
    ['malformed', [], false, 'a malformed merchant record fails closed, not open'],
    ['allowlist', ['GB'], true, 'an allowlist containing the registry-eligible country authorises it'],
    ['allowlist', ['NO', 'SE'], false, 'an allowlist NOT containing the country refuses it'],
    // Not a real PHP-side state (decodeMerchantBuyerCountries() reports
    // 'empty', never 'allowlist', for zero codes) - pinned defensively in
    // case a future caller ever sends this combination anyway.
    ['allowlist', [], false, 'an allowlist state with no codes at all fails safe rather than mints']
])(
    'merchantBuyerCountriesState=%p merchantBuyerCountries=%p',
    (state, allowed, expectMint, description) => {
        test(description, async () => {
            buildPaymentTileWithSoleTraderAnswer('1', 'GB');
            TwoSoleTrader = loadSoleTrader();
            const calls = stubFetch();

            const instance = build({
                merchantBuyerCountriesState: state,
                merchantBuyerCountries: allowed
            });
            await flushPromises();

            expect(calls.mints).toBe(expectMint ? 1 : 0);
        });
    }
);

describe('the merchant gate never widens a registry refusal', () => {
    test('an unrestricted merchant still mints nothing for a business-only country', async () => {
        buildPaymentTileWithSoleTraderAnswer('0', 'GB');
        TwoSoleTrader = loadSoleTrader();
        const calls = stubFetch();

        const instance = build({ merchantBuyerCountriesState: 'absent' });
        await flushPromises();

        expect(calls.mints).toBe(0);
    });
});

describe('the merchant gate is resolved once, not re-derived per country change', () => {
    test('an allowlist excluding the new country still blocks the mint after a live country change', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        TwoSoleTrader = loadSoleTrader();
        const calls = stubFetch();

        const instance = build({
            merchantBuyerCountriesState: 'allowlist',
            merchantBuyerCountries: ['GB']
        });
        await flushPromises();
        expect(calls.mints).toBe(1);

        // A later country change to one the SAME (unchanged) merchant
        // allowlist excludes - resolveMerchantCountryGate() was computed
        // once at construction and is never re-derived, so this is the
        // registry-per-country check refusing SE, not a stale merchant
        // read.
        instance.config.billingCountry = 'SE';
        instance.availabilityByCountry.SE = true;
        instance.tokens = null;
        instance._eagerMintCountry = null;
        instance.apply('SE', true);
        await flushPromises();

        expect(calls.mints).toBe(1);
    });
});
