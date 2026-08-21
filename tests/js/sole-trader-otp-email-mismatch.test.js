/**
 * Live bug (Doug, 2026-08-12): sole-trader autofill's passive lookup finds no
 * matching Two session cookie for the checkout email, so it opens the hosted
 * signup popup. The buyer completes a REAL OTP verification there for a
 * DIFFERENT, genuinely-registered email (their sole-trader account may not
 * share an inbox with whatever they typed into PrestaShop's own personal-
 * information step for this order). The popup posts 'ACCEPTED' back and
 * closes - authentication succeeded - but the resulting buyer lookup used to
 * re-validate `buyer.email` against `checkoutEmail()` (still the FIRST,
 * unrelated email, since nothing in this flow ever writes the popup's email
 * back into the PS form) and rejected an otherwise-successful response,
 * reopening the very popup the buyer had just finished with. Every retry
 * hits the identical mismatch, forever - the bug is not a real validation
 * rule, it is `getCurrentBuyer()` reusing the SAME email-match heuristic for
 * two calls with different trust levels: an unauthenticated cookie probe
 * (where the heuristic is the only signal available) and a call that follows
 * a genuine OTP round trip (where the server has already told this browser
 * exactly who the buyer is).
 *
 * Fix: bindPopupMessageListener()'s 'ACCEPTED' handler calls
 * getCurrentBuyer(true) - `trustedIdentity` - which applies any buyer the
 * endpoint returns without re-checking it against checkoutEmail() at all.
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
    delete global.window.open;
    document.body.innerHTML = '';
    global.window.localStorage.clear();
});

test('a real OTP round trip applies the buyer even when their email differs from the checkout form', async () => {
    const publishes = stubManager();
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };

    // EMAIL_A: what the buyer typed into PrestaShop's own personal-info step
    // for this order. Deliberately different from the sole-trader account's
    // real, registered email below - the buyer legitimately has both.
    document.body.insertAdjacentHTML(
        'beforeend',
        "<input name='email' value='order-contact@example.test' />"
    );

    let popupOpenCalls = 0;
    global.window.open = function () {
        popupOpenCalls += 1;
        // A real popup handle - openPopup()'s blocked-popup branch must not
        // fire and confuse this test with an unrelated showError() path.
        return {};
    };

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
            // EMAIL_B: the sole trader's real, registered account email -
            // the one they just proved ownership of with a valid OTP inside
            // the hosted popup. Never equal to EMAIL_A above; nothing in
            // this flow makes it equal, by design - the two identify
            // different things (who authenticated vs. who the order is
            // addressed to).
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    email: 'sole-trader-real-account@example.test',
                    company_name: 'Sole Trader AS',
                    organization_number: '923456789'
                })
            });
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;

    const instance = build();
    // destroy() in a finally (not just at the happy-path end): an assertion
    // throwing above it would otherwise leave this instance's window
    // 'message' listener attached, live to react to a LATER test's own
    // dispatched events against the same mocked signup origin - exactly the
    // cross-test contamination this file's own bindPopupMessageListener()
    // comments warn a leaked listener causes on a real page.
    try {
        instance.startEnrollment();
        // Mint resolves; the first, PASSIVE getCurrentBuyer() call correctly
        // finds no cookie/email match (EMAIL_A has no Two session yet) and
        // hands off to the on-page prompt - no popup opened by this step
        // alone.
        await flushPromises();
        await flushPromises();
        expect(popupOpenCalls).toBe(0);
        expect(publishes).toEqual([]);

        const prompt = document.querySelector('.two-sole-trader__prompt');
        expect(prompt).not.toBeNull();
        prompt.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
        expect(popupOpenCalls).toBe(1);

        // The buyer authenticates as EMAIL_B and gets a valid OTP; the popup
        // posts back and (in production) closes itself.
        window.dispatchEvent(new window.MessageEvent('message', {
            data: 'ACCEPTED',
            origin: 'https://signup.example.test'
        }));
        await flushPromises();
        await flushPromises();
        await flushPromises();

        // The buyer must be applied - NOT rejected and NOT sent back through
        // another popup round trip.
        expect(publishes).toEqual([{ company: 'Sole Trader AS', companyid: '923456789' }]);
        expect(popupOpenCalls).toBe(1);
    } finally {
        instance.destroy();
    }
});

/**
 * TWO-40 round 8 adversarial review (Han + Vader, convergent): a 404 on
 * `/autofill/v1/buyer/current` right after the popup's real 'ACCEPTED' is
 * ordinary read-after-write lag, not "no registration" - the OTP round trip
 * just completed server-side and this GET can briefly not see it yet.
 * Falling straight into showPrompt()/openPopup() on that 404 reopens the
 * exact popup the buyer had just finished with - the reported bug's symptom,
 * reached via a timing race instead of a guaranteed email mismatch. One
 * retry, after a short delay, must be given before giving up.
 */
test('a 404 immediately after a real ACCEPTED is retried once, not treated as no registration', async () => {
    const publishes = stubManager();
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };
    document.body.insertAdjacentHTML('beforeend', "<input name='email' value='order-contact@example.test' />");

    let popupOpenCalls = 0;
    global.window.open = function () {
        popupOpenCalls += 1;
        return {};
    };

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
            if (buyerLookupCalls === 1) {
                // The FIRST lookup after the mint (the passive, untrusted
                // probe) - genuinely no registration yet, real 404.
                return Promise.resolve({ ok: false, status: 404 });
            }
            if (buyerLookupCalls === 2) {
                // The trusted lookup triggered by 'ACCEPTED' - the server
                // has not caught up yet. Still 404.
                return Promise.resolve({ ok: false, status: 404 });
            }
            // The retry: now visible.
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    email: 'sole-trader-real-account@example.test',
                    company_name: 'Sole Trader AS',
                    organization_number: '923456789'
                })
            });
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;

    // Nested try/finally (repo convention - see company-search-resize.test.js
    // for the fake-timers half): a thrown assertion must not leave fake
    // timers bleeding into whichever test jest runs next, NOR leave this
    // instance's window 'message' listener attached to react to a later
    // test's own dispatched events (see the identical reasoning in the
    // previous test).
    jest.useFakeTimers();
    try {
        const instance = build();
        try {
            instance.startEnrollment();
            await flushPromises();
            await flushPromises();
            expect(buyerLookupCalls).toBe(1);
            expect(popupOpenCalls).toBe(0);

            const prompt = document.querySelector('.two-sole-trader__prompt');
            prompt.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
            expect(popupOpenCalls).toBe(1);

            window.dispatchEvent(new window.MessageEvent('message', {
                data: 'ACCEPTED',
                origin: 'https://signup.example.test'
            }));
            await flushPromises();
            await flushPromises();
            expect(buyerLookupCalls).toBe(2);
            // Must NOT have reopened the popup yet - the retry is still pending.
            expect(popupOpenCalls).toBe(1);
            expect(publishes).toEqual([]);

            jest.advanceTimersByTime(800);
            await flushPromises();
            await flushPromises();
            await flushPromises();

            expect(buyerLookupCalls).toBe(3);
            expect(publishes).toEqual([{ company: 'Sole Trader AS', companyid: '923456789' }]);
            // The retry succeeding must not have opened a second popup.
            expect(popupOpenCalls).toBe(1);
        } finally {
            instance.destroy();
        }
    } finally {
        jest.useRealTimers();
    }
});

/**
 * TWO-40 round 8 adversarial review (Han): if a genuine 'ACCEPTED' arrives
 * while a DIFFERENT getCurrentBuyer() call is already out (isFetchingBuyer),
 * getCurrentBuyer(true) would previously just no-op on its own re-entrancy
 * guard - silently dropping the authentication event, leaving the busy
 * (untrusted) call to resolve on its own and potentially fail the
 * email-match heuristic, reproducing the loop via a race. The busy call's
 * own `.finally()` must re-issue a trusted lookup once it clears.
 */
test('an ACCEPTED that arrives while a lookup is already in flight is not dropped', async () => {
    const publishes = stubManager();
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };
    document.body.insertAdjacentHTML('beforeend', "<input name='email' value='order-contact@example.test' />");

    let resolveBusyLookup;
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
            if (buyerLookupCalls === 1) {
                // The mint's own immediate lookup - held open, simulating
                // a slow round trip still out when 'ACCEPTED' arrives.
                return new Promise((resolve) => { resolveBusyLookup = resolve; });
            }
            // The re-issued, trusted lookup from .finally().
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    email: 'sole-trader-real-account@example.test',
                    company_name: 'Sole Trader AS',
                    organization_number: '923456789'
                })
            });
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;

    const instance = build();
    // See the previous two tests' identical reasoning for destroy() living
    // in a finally.
    try {
        instance.startEnrollment();
        await flushPromises();
        expect(buyerLookupCalls).toBe(1); // held open

        // The buyer authenticates in the (still-open, from an earlier click)
        // popup while that first lookup is still out.
        window.dispatchEvent(new window.MessageEvent('message', {
            data: 'ACCEPTED',
            origin: 'https://signup.example.test'
        }));
        await flushPromises();
        // getCurrentBuyer(true) must not have fired a second request yet -
        // it was flagged, not issued, because the guard was held.
        expect(buyerLookupCalls).toBe(1);

        // The busy lookup now resolves (404 - it was the untrusted probe, no
        // registration visible to IT).
        resolveBusyLookup({ ok: false, status: 404 });
        await flushPromises();
        await flushPromises();
        await flushPromises();

        // The flagged trusted resume must have fired once the guard cleared.
        expect(buyerLookupCalls).toBe(2);
        expect(publishes).toEqual([{ company: 'Sole Trader AS', companyid: '923456789' }]);
    } finally {
        instance.destroy();
    }
});

/**
 * TWO-40 round 9 adversarial review (Han + Vader, convergent): the 404-retry
 * fix's own `setTimeout(..., 800)` is a bare side effect of the `.then()`
 * handler that scheduled it - returning from that handler settles the
 * promise immediately, so the chained `.finally()` released `isFetchingBuyer`
 * right away, roughly 800ms BEFORE the retry itself ran. For the whole wait,
 * the re-entrancy guard read `false` even though a retry was logically still
 * pending - reopening the exact concurrent-lookup window the round-5 guard
 * exists to close, for a second 'ACCEPTED' landing mid-wait. Must now stay
 * held for the entire wait, released only right before the retry decides
 * what to do.
 */
test('the guard stays held for the whole 404-retry wait, not just until the retry is scheduled', async () => {
    const publishes = stubManager();
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };
    document.body.insertAdjacentHTML('beforeend', "<input name='email' value='order-contact@example.test' />");

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
            if (buyerLookupCalls === 1) {
                // The passive probe right after the mint - genuinely no
                // registration yet.
                return Promise.resolve({ ok: false, status: 404 });
            }
            if (buyerLookupCalls === 2) {
                // The trusted lookup triggered by the FIRST 'ACCEPTED' - the
                // server has not caught up yet, so this schedules the
                // 800ms retry.
                return Promise.resolve({ ok: false, status: 404 });
            }
            // Whichever call the guard being held correctly routed here
            // (the retry itself, or a re-issued pending resume) - now
            // visible.
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    email: 'sole-trader-real-account@example.test',
                    company_name: 'Sole Trader AS',
                    organization_number: '923456789'
                })
            });
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;

    jest.useFakeTimers();
    try {
        const instance = build();
        try {
            instance.startEnrollment();
            await flushPromises();
            expect(buyerLookupCalls).toBe(1);

            // First 'ACCEPTED' - triggers the trusted lookup that 404s and
            // schedules the 800ms retry.
            window.dispatchEvent(new window.MessageEvent('message', {
                data: 'ACCEPTED',
                origin: 'https://signup.example.test'
            }));
            await flushPromises();
            await flushPromises();
            expect(buyerLookupCalls).toBe(2);

            // A SECOND 'ACCEPTED' lands mid-wait (popups can legitimately
            // fire more than once, e.g. on refocus). If the guard had
            // already been released (the bug), this would fire its own,
            // third, concurrent lookup right now. It must not: the guard is
            // still held, so this is flagged via `_pendingTrustedResume`
            // instead, exactly like the "already in flight" test above.
            window.dispatchEvent(new window.MessageEvent('message', {
                data: 'ACCEPTED',
                origin: 'https://signup.example.test'
            }));
            await flushPromises();
            expect(buyerLookupCalls).toBe(2);

            // The 800ms retry fires. It must find the guard held by nothing
            // of its own doing - and settle() releasing it must yield to the
            // flagged pending resume rather than firing a duplicate lookup
            // on top of it.
            jest.advanceTimersByTime(800);
            await flushPromises();
            await flushPromises();
            await flushPromises();

            expect(buyerLookupCalls).toBe(3);
            expect(publishes).toEqual([{ company: 'Sole Trader AS', companyid: '923456789' }]);
            // Exactly one apply - not two, even though two 'ACCEPTED'
            // messages arrived.
            expect(publishes.length).toBe(1);
        } finally {
            instance.destroy();
        }
    } finally {
        jest.useRealTimers();
    }
});
