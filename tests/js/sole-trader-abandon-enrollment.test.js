/**
 * TWO-40 follow-up: closing the hosted signup popup and cancelling the
 * enrolment behind it are ONE operation, abandonEnrollment().
 *
 * Doug's architectural call, after finding both ways of getting the pair wrong
 * already shipped: "the fix also requires that we make closure and enrolment
 * cancelation a single atomic operation, not two separate functions as now.
 * It's just begging to fail in some way." The "Enter manually" chip closed and
 * never cancelled, so a lookup still in flight resolved into a company name the
 * buyer had since typed by hand; openDropdown() cancelled and never closed,
 * leaving a live popup on screen tracked by nothing.
 *
 * These tests are on the REAL TwoSoleTrader, not a stub: the whole point of the
 * merge is that the ordering and the effects are the module's own contract
 * rather than something each caller re-derives. Which gesture picks which
 * operation is TwoCompanySearch's, and lives in
 * company-search-sole-trader-entry.test.js.
 */

'use strict';

const {
    loadSoleTrader,
    buildPaymentTile,
    buildAddressForm,
    flushPromises
} = require('./ps-harness');

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

/**
 * A popup window that behaves like the real one in the one way that matters
 * here: `close()` flips `closed`, so a second close is observably a no-op and
 * an attempt to close a handle that was already discarded never reaches it.
 */
function fakePopup() {
    const popup = {
        closed: false,
        closeCalls: 0,
        close() {
            this.closeCalls += 1;
            this.closed = true;
        },
        focus: jest.fn()
    };
    return popup;
}

/**
 * Answer the availability request "available", so the constructor's own
 * refreshAvailability() does not read as "country ineligible" and abandon the
 * very enrolment under test. Same reasoning as
 * sole-trader-generation-guard.test.js's identical stub.
 */
function stubFetch(handlers) {
    global.window.fetch = (url) => {
        const target = String(url);
        if (target.includes('soleTraderAvailability')) {
            return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
        }
        const matched = Object.keys(handlers).find((key) => target.includes(key));
        if (matched) {
            return handlers[matched](target);
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;
}

const TOKENS = {
    success: true,
    autofill_token: 'af-token',
    delegation_token: 'del-token',
    signup_url: 'https://signup.example.test/',
    country: 'GB'
};

/**
 * Stand the flow up with a popup actually open.
 *
 * On the ADDRESS-FORM page, deliberately: with no `.two-sole-trader` container
 * a no-match lookup opens the popup straight away, where the payment tile would
 * stop at a prompt the buyer has to click first
 * (sole-trader-containerless-popup.test.js). Fewer moving parts between the
 * enrolment starting and a real handle existing to abandon.
 */
async function enrolWithPopupOpen() {
    document.body.innerHTML = '';
    buildAddressForm();
    const popup = fakePopup();
    global.window.open = jest.fn(() => popup);
    stubFetch({
        soleTraderTokens: () => Promise.resolve({ json: () => Promise.resolve(TOKENS) }),
        '/autofill/v1/buyer/current': () => Promise.resolve({ ok: false, status: 404 })
    });

    const instance = build();
    instance.startEnrollment();
    await flushPromises();
    await flushPromises();
    await flushPromises();

    expect(global.window.open).toHaveBeenCalledTimes(1);
    expect(instance._popup).toBe(popup);
    return { instance: instance, popup: popup };
}

beforeEach(() => {
    buildPaymentTile();
    TwoSoleTrader = loadSoleTrader();
});

afterEach(() => {
    delete global.window.TwoCheckoutManager_Instance;
    delete global.window.open;
    delete global.fetch;
    delete global.window.fetch;
    delete global.window.TwoCompanyNumber;
    document.body.innerHTML = '';
    global.window.localStorage.clear();
});

/**
 * The ordering, pinned on the EFFECT rather than on call order: cancelEnrollment()
 * discards the popup handle, so a close attempted after it cannot reach the
 * window at all. `closeCalls` is therefore 1 only if the close really did run
 * while a handle still existed - which is the whole reason the pair cannot be
 * left to callers to sequence.
 */
test('abandonEnrollment() closes the popup while a handle still exists, then cancels', async () => {
    const { instance, popup } = await enrolWithPopupOpen();
    const generationBefore = instance._enrollGeneration;

    instance.abandonEnrollment();

    // The close reached the real window...
    expect(popup.closeCalls).toBe(1);
    expect(popup.closed).toBe(true);
    // ...and the cancel really ran after it, not instead of it.
    expect(instance._popup).toBeNull();
    expect(instance._enrollGeneration).toBe(generationBefore + 1);
    expect(instance.enrolling).toBe(false);

    instance.destroy();
});

/**
 * The re-render half of the same bug (Doug, TWO-40 follow-up), from this side:
 * an address-form re-render restores a panel without abandoning, so the popup
 * must still be THIS instance's to close and raise afterwards. Pinned on the
 * handle rather than on who called what - a nulled handle is exactly what left
 * a live window owned by nobody.
 */
test('an enrolment nobody abandoned keeps its popup handle, closable and raisable', async () => {
    const { instance, popup } = await enrolWithPopupOpen();

    expect(instance._popup).toBe(popup);
    expect(instance.focusSignupPopup()).toBe(true);
    expect(popup.focus).toHaveBeenCalledTimes(1);
    expect(popup.closeCalls).toBe(0);

    // And it is still the handle a later abandon closes.
    instance.abandonEnrollment();
    expect(popup.closeCalls).toBe(1);

    instance.destroy();
});

/**
 * The mutation this file exists to catch: swap the two lines inside
 * abandonEnrollment() and the window is never closed, because the handle is
 * gone by the time the close is attempted. Asserted as the difference between
 * the pair and the halves-in-the-wrong-order, on the same instance shape.
 */
test('cancelling first would leave the window open - which is why the pair is one call', async () => {
    const { instance, popup } = await enrolWithPopupOpen();

    instance.cancelEnrollment();
    instance.closeSignupPopup();

    // Nothing closed it, and nothing is tracking it any more: a live window on
    // screen owned by nobody, from where the Sole trader chip would open a
    // SECOND one (guide §14).
    expect(popup.closeCalls).toBe(0);
    expect(popup.closed).toBe(false);
    expect(instance._popup).toBeNull();

    instance.destroy();
});

/**
 * The other half of the same rule (guide §14): a caller that is NOT a buyer
 * gesture and does NOT close the window - TwoCompanySearch.destroy(), which the
 * platform fires on every address-form re-render - disowns the write and keeps
 * the popup. The buyer may be filling that popup in right now because their
 * shipping total recalculated behind it.
 *
 * The mutation this catches is dropping the argument: every assertion below
 * fails against a cancel that nulls the handle, and the pair of them is what
 * "owned by nobody" actually meant - nothing polling it, and nothing able to
 * close or raise it afterwards.
 */
test('cancelEnrollment(true) disowns the write but keeps the popup tracked', async () => {
    jest.useFakeTimers();
    const settles = [];
    const onSettled = () => settles.push(true);
    document.addEventListener('two:sole-trader-flight-settled', onSettled);
    try {
        const { instance, popup } = await enrolWithPopupOpen();
        const generationBefore = instance._enrollGeneration;

        instance.cancelEnrollment(true);

        // The write is disowned...
        expect(instance._enrollGeneration).toBe(generationBefore + 1);
        expect(instance.enrolling).toBe(false);
        // ...and the window is not.
        expect(instance._popup).toBe(popup);
        expect(instance._popupPollInterval).not.toBeNull();
        expect(popup.closeCalls).toBe(0);
        // The live-popup guard still sees it, so the next Sole trader click
        // raises this window instead of opening a SECOND one over it.
        expect(instance.openPopup()).toBe(popup);
        expect(global.window.open).toHaveBeenCalledTimes(1);
        // Held back by notifyEnrollmentSettled()'s popup-open guard, which is
        // the point: nothing has been taken away from the buyer, so their own
        // close is what settles the flight.
        expect(settles.length).toBe(0);

        popup.closed = true;
        jest.advanceTimersByTime(500);
        expect(settles.length).toBe(1);

        instance.destroy();
    } finally {
        document.removeEventListener('two:sole-trader-flight-settled', onSettled);
        jest.useRealTimers();
    }
});

/**
 * The consequence of that survival, and the round-2 adversarial review finding
 * it produced: the cancel bumps `_enrollGeneration`, so the popup it leaves
 * behind is one the message listener would drop on its generation check. The
 * buyer raising it is an explicit resume - the same statement
 * startEnrollment()/startReplacement() re-stamp for - so the raise re-stamps
 * too, or a buyer who completes signup after a shipping recalculation gets
 * nothing written and no error, with a spinner over it saying otherwise.
 *
 * Asserted on the publish rather than on the counters: the stamp is the
 * mechanism, the silently dropped completion is the bug.
 */
test('a popup kept across a teardown still completes once the buyer raises it', async () => {
    const publishes = stubManager();
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };
    document.body.insertAdjacentHTML('beforeend', "<input name='email' value='buyer@example.test' />");
    const { instance, popup } = await enrolWithPopupOpen();

    // The address form re-renders under the buyer - a shipping recalculation,
    // not a gesture - so the search instance is torn down and rebuilt.
    instance.cancelEnrollment(true);

    // The buyer clicks Sole trader again: "give me that popup back".
    expect(instance.focusSignupPopup()).toBe(true);
    expect(instance._tokensGeneration).toBe(instance._enrollGeneration);

    // ...and finishes signing up in it.
    stubFetch({
        '/autofill/v1/buyer/current': () => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
                email: 'buyer@example.test',
                company_name: 'Sole Trader AS',
                organization_number: '923456789'
            })
        })
    });
    window.dispatchEvent(new window.MessageEvent('message', {
        data: 'ACCEPTED',
        origin: 'https://signup.example.test'
    }));
    await flushPromises();
    await flushPromises();
    await flushPromises();

    expect(publishes.length).toBe(1);
    expect(publishes[0].company).toBe('Sole Trader AS');
    expect(popup.closeCalls).toBe(0);

    instance.destroy();
});

/**
 * The buyer-facing half of bug 2 (Doug, TWO-40 follow-up), end to end on the
 * real module: "Enter manually" used to close the popup and leave the enrolment
 * running, so a buyer lookup already in flight still resolved and published the
 * sole-trader identity - over the company name the buyer had typed by hand, with
 * the order-intent credit check then running against the identity they walked
 * away from.
 *
 * Asserted on the publish, not on the generation counter: the counter is the
 * mechanism, the silent overwrite is the bug.
 */
test('a lookup in flight when the buyer abandons publishes nothing afterwards', async () => {
    const publishes = stubManager();
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };
    document.body.insertAdjacentHTML('beforeend', "<input name='email' value='buyer@example.test' />");

    let resolveBuyerLookup;
    stubFetch({
        soleTraderTokens: () => Promise.resolve({ json: () => Promise.resolve(TOKENS) }),
        '/autofill/v1/buyer/current': () => new Promise((resolve) => { resolveBuyerLookup = resolve; })
    });

    const instance = build();
    // The lookup this starts never resolves until this test says so - it is
    // what is still in the air when the buyer gives up on the flow.
    instance.startEnrollment();
    await flushPromises();
    await flushPromises();
    expect(typeof resolveBuyerLookup).toBe('function');

    // The buyer clicks "Enter manually" and types their own company name.
    instance.abandonEnrollment();
    publishes.length = 0;

    resolveBuyerLookup({
        ok: true,
        json: () => Promise.resolve({
            email: 'buyer@example.test',
            company_name: 'Sole Trader AS',
            organization_number: '923456789'
        })
    });
    await flushPromises();
    await flushPromises();

    expect(publishes).toEqual([]);

    instance.destroy();
});

/**
 * The pair is idempotent and safe with nothing open - every caller fires it
 * unconditionally rather than testing for a popup first (openDropdown() on
 * every buyer-initiated open, both chips on every click).
 */
test('abandonEnrollment() with no popup and no enrolment is a no-op that still disowns', () => {
    stubFetch({});
    const instance = build();
    const generationBefore = instance._enrollGeneration;

    instance.abandonEnrollment();
    instance.abandonEnrollment();

    // The generation bump is unconditional by design (cancelEnrollment()'s own
    // comment): a lookup can be in flight with `enrolling` already false.
    expect(instance._enrollGeneration).toBe(generationBefore + 2);
    expect(instance._popup).toBeNull();

    instance.destroy();
});
