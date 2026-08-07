/**
 * TWO-40 round 2 adversarial review BLOCKER: a sole-trader enrolment that is
 * still resolving asynchronously (token mint, then the buyer-lookup round
 * trip, then the saveCompany round trip) must not publish over a REAL
 * company the buyer explicitly searched for and selected in the meantime.
 *
 * The message listener for the hosted signup popup is deliberately NOT
 * gated on `enrolling` (round 1's own fix for a dropped genuine completion -
 * see sole-trader-server-rendered-toggle.test.js), so gating on `enrolling`
 * here would just reintroduce that bug from the other side. The actual fix
 * is a generation counter (`_enrollGeneration`), bumped unconditionally by
 * cancelEnrollment() - which TwoCompanySearch.js calls on every dropdown
 * open, i.e. on every route back into ordinary search - and checked before
 * ANY buyer-lookup response is allowed to touch the DOM or publish a
 * selection.
 */

'use strict';

const { loadSoleTrader, buildPaymentTile, flushPromises } = require('./ps-harness');

let TwoSoleTrader;

function build(overrides) {
    return new TwoSoleTrader(Object.assign({
        checkoutHost: 'https://api.example.test',
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        billingCountry: 'GB'
    }, overrides || {}));
}

/** A manager stub recording every publish, the way TwoCompanySearch's real one does. */
function stubManager() {
    const publishes = [];
    global.window.TwoCheckoutManager_Instance = {
        setConfirmedCompanySelection(selection) {
            publishes.push(selection);
        }
    };
    return publishes;
}

beforeEach(() => {
    buildPaymentTile();
    TwoSoleTrader = loadSoleTrader();
});

afterEach(() => {
    delete global.window.TwoCheckoutManager_Instance;
    delete global.fetch;
    delete global.window.fetch;
    delete global.window.TwoCompanyNumber;
    document.body.innerHTML = '';
});

test('a buyer lookup resolving AFTER the buyer picked a real company does not overwrite that selection', async () => {
    const publishes = stubManager();
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };

    let resolveTokens;
    let resolveBuyerLookup;
    global.window.fetch = (url) => {
        if (String(url).includes('soleTraderTokens')) {
            return new Promise((resolve) => { resolveTokens = resolve; });
        }
        if (String(url).includes('/autofill/v1/buyer/current')) {
            return new Promise((resolve) => { resolveBuyerLookup = resolve; });
        }
        // saveCompany, or anything else this test does not care about.
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;

    const instance = build();
    instance.startEnrollment();

    resolveTokens({
        json: () => Promise.resolve({
            success: true,
            autofill_token: 'af-token',
            delegation_token: 'del-token',
            signup_url: 'https://signup.example.test/',
            country: 'GB'
        })
    });
    await flushPromises();

    // The buyer, mid-lookup, goes back to ordinary search and picks a REAL
    // company - exactly what TwoCompanySearch.openDropdown() triggers on
    // every reopen.
    instance.cancelEnrollment();
    publishes.length = 0; // ignore anything cancelEnrollment() itself did

    // The abandoned lookup now resolves, matching the checkout email, as if
    // the buyer really is a registered sole trader.
    document.querySelector("input[name='email'], #email") || document.body.insertAdjacentHTML(
        'beforeend',
        "<input name='email' value='buyer@example.test' />"
    );
    resolveBuyerLookup({
        ok: true,
        json: () => Promise.resolve({ email: 'buyer@example.test', company_name: 'Sole Trader AS', organization_number: '923456789' })
    });
    await flushPromises();
    await flushPromises();

    expect(publishes).toEqual([]);
    instance.destroy();
});

test('a buyer lookup resolving BEFORE any cancellation still publishes normally', async () => {
    const publishes = stubManager();
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };
    document.body.insertAdjacentHTML('beforeend', "<input name='email' value='buyer@example.test' />");

    global.window.fetch = (url) => {
        if (String(url).includes('soleTraderTokens')) {
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
        if (String(url).includes('/autofill/v1/buyer/current')) {
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ email: 'buyer@example.test', company_name: 'Sole Trader AS', organization_number: '923456789' })
            });
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;

    const instance = build();
    instance.startEnrollment();
    await flushPromises();
    await flushPromises();
    await flushPromises();

    expect(publishes).toEqual([{ company: 'Sole Trader AS', companyid: '923456789' }]);
    instance.destroy();
});
