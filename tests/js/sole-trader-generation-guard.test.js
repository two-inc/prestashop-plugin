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
    // TWO-40 follow-up: see the identical comment in
    // sole-trader-toggle-flicker.test.js.
    global.window.localStorage.clear();
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
        // The constructor's own refreshAvailability() call fires a
        // soleTraderAvailability request independent of anything this test
        // is exercising - answer it "available" so apply()'s existing
        // "country stopped being eligible -> cancelEnrollment()" behaviour
        // (unrelated to this test, real and correct) does not fire as an
        // accidental side effect of the generic fallback below.
        if (String(url).includes('soleTraderAvailability')) {
            return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
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
        // See the other test's identical comment: the constructor's own
        // refreshAvailability() call must not read as "country ineligible"
        // and cancel the very enrolment this test is exercising.
        if (String(url).includes('soleTraderAvailability')) {
            return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
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

/**
 * Round 3 adversarial review finding: round 2's fix only closed the race
 * where a getCurrentBuyer()/applyBuyer() call was ALREADY IN FLIGHT when
 * cancelEnrollment() fired. It missed the case where the tokens/popup from
 * a CANCELLED attempt produce a BRAND NEW getCurrentBuyer() call afterward -
 * a stale hosted-signup popup finishing on its own, well after the buyer
 * has moved on - because that new call re-captures whatever generation is
 * CURRENT at the moment it fires, which looks legitimate on its own. The fix
 * is `_tokensGeneration`: stamped onto the tokens at mint (or explicit
 * resume) time, and checked by the popup-completion listener BEFORE it acts
 * on a message at all - not inside getCurrentBuyer()/applyBuyer(), which by
 * then have already re-captured a fresh, misleading generation.
 */
test('a stale popup finishing AFTER a cancel does not react at all, even via a brand new lookup call', async () => {
    const publishes = stubManager();
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };
    // No email typed yet when the popup first opens - the buyer has not
    // finished (or even started) the hosted signup at this point, so the
    // FIRST lookup must come back unmatched (showPrompt(), not applyBuyer()).
    // It is only typed in below, simulating the buyer completing signup
    // inside the popup itself LATER, after they have already cancelled here.

    let buyerLookupCalls = 0;
    global.window.fetch = (url) => {
        if (String(url).includes('soleTraderAvailability')) {
            return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
        }
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
            buyerLookupCalls += 1;
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
    // Mint resolves; the immediate getCurrentBuyer() it triggers comes back
    // unmatched (no checkout email on the page yet) and shows the prompt.
    await flushPromises();
    await flushPromises();
    const buyerLookupCallsAfterMint = buyerLookupCalls;
    expect(buyerLookupCallsAfterMint).toBe(1);

    // The buyer goes back to search and picks a real company.
    instance.cancelEnrollment();
    publishes.length = 0;

    // The buyer NOW finishes signup inside the still-open popup - the
    // checkout email becomes matchable, exactly as it would once the real
    // signup completes server-side.
    document.body.insertAdjacentHTML('beforeend', "<input name='email' value='buyer@example.test' />");

    // The hosted signup popup - never closed by cancelEnrollment(), by
    // design - finishes on its own, well after the cancel, and posts back.
    window.dispatchEvent(new window.MessageEvent('message', {
        data: 'ACCEPTED',
        origin: 'https://signup.example.test'
    }));
    await flushPromises();
    await flushPromises();

    // The listener must not even ISSUE a new lookup for this stale
    // completion, let alone publish anything from one.
    expect(buyerLookupCalls).toBe(buyerLookupCallsAfterMint);
    expect(publishes).toEqual([]);
    instance.destroy();
});
