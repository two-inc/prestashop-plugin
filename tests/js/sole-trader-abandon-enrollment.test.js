/**
 * TWO-40 follow-up: closing the hosted signup popup and cancelling the
 * enrolment behind it are ONE operation, abandonEnrollment() - two separate
 * calls let each get done in the wrong order.
 *
 * Tests run against the REAL TwoSoleTrader, not a stub. Which gesture picks
 * which operation is TwoCompanySearch's, tested in
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

/** Records every publish, like TwoCompanySearch's real manager. */
function stubManager() {
    const publishes = [];
    global.window.TwoCheckoutManager_Instance = {
        setConfirmedCompanySelection(selection) {
            publishes.push(selection);
        }
    };
    return publishes;
}

/** `close()` flips `closed`, so a second close is observably a no-op. */
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
 * Answers availability "available" so refreshAvailability() doesn't read
 * "country ineligible" and abandon the enrolment under test. Same stub as
 * sole-trader-generation-guard.test.js.
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
 * Uses the ADDRESS-FORM page deliberately: with no `.two-sole-trader`
 * container a no-match lookup opens the popup straight away, where the
 * payment tile would stop at a prompt first
 * (sole-trader-containerless-popup.test.js).
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

/** Pinned on the EFFECT, not call order: cancelEnrollment() discards the
 * popup handle, so a close attempted after it can't reach the window. */
test('abandonEnrollment() closes the popup while a handle still exists, then cancels', async () => {
    const { instance, popup } = await enrolWithPopupOpen();
    const generationBefore = instance._enrollGeneration;

    instance.abandonEnrollment();

    expect(popup.closeCalls).toBe(1);
    expect(popup.closed).toBe(true);
    expect(instance._popup).toBeNull();
    expect(instance._enrollGeneration).toBe(generationBefore + 1);
    expect(instance.enrolling).toBe(false);

    instance.destroy();
});

/** TWO-40 follow-up: an address-form re-render restores a panel without
 * abandoning, so the popup must still be THIS instance's handle. */
test('an enrolment nobody abandoned keeps its popup handle, closable and raisable', async () => {
    const { instance, popup } = await enrolWithPopupOpen();

    expect(instance._popup).toBe(popup);
    expect(instance.focusSignupPopup()).toBe(true);
    expect(popup.focus).toHaveBeenCalledTimes(1);
    expect(popup.closeCalls).toBe(0);

    instance.abandonEnrollment();
    expect(popup.closeCalls).toBe(1);

    instance.destroy();
});

/** The mutation this catches: swap the two lines inside abandonEnrollment()
 * and the window never closes, because the handle is gone by then. */
test('cancelling first would leave the window open - which is why the pair is one call', async () => {
    const { instance, popup } = await enrolWithPopupOpen();

    instance.cancelEnrollment();
    instance.closeSignupPopup();

    // Live window owned by nobody: Sole trader would open a SECOND one (guide §14).
    expect(popup.closeCalls).toBe(0);
    expect(popup.closed).toBe(false);
    expect(instance._popup).toBeNull();

    instance.destroy();
});

/**
 * Guide §14: a caller that is NOT a buyer gesture and does NOT close the
 * window - TwoCompanySearch.destroy(), fired on every address-form re-render -
 * disowns the write and keeps the popup, since the buyer may be filling it
 * in right now. Mutation caught: dropping the argument nulls the handle.
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

        // Write disowned, window not.
        expect(instance._enrollGeneration).toBe(generationBefore + 1);
        expect(instance.enrolling).toBe(false);
        expect(instance._popup).toBe(popup);
        expect(instance._popupPollInterval).not.toBeNull();
        expect(popup.closeCalls).toBe(0);
        // Live-popup guard raises this window instead of opening a second one.
        expect(instance.openPopup()).toBe(popup);
        expect(global.window.open).toHaveBeenCalledTimes(1);
        // Held back by notifyEnrollmentSettled()'s popup-open guard: the
        // buyer's own close is what settles the flight.
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
 * cancelEnrollment(true) bumps `_enrollGeneration`, so the popup left behind
 * is one the message listener would drop on its generation check. Raising it
 * is an explicit resume, so the raise must re-stamp too - or a buyer who
 * completes signup after a shipping recalculation gets nothing written and
 * no error, with a spinner saying otherwise.
 */
test('a popup kept across a teardown still completes once the buyer raises it', async () => {
    const publishes = stubManager();
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };
    document.body.insertAdjacentHTML('beforeend', "<input name='email' value='buyer@example.test' />");
    const { instance, popup } = await enrolWithPopupOpen();

    // Address form re-renders under the buyer (shipping recalc, not a gesture).
    instance.cancelEnrollment(true);

    expect(instance.focusSignupPopup()).toBe(true);
    expect(instance._tokensGeneration).toBe(instance._enrollGeneration);

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
 * TWO-40 follow-up: "Enter manually" used to close the popup and leave the
 * enrolment running, so a lookup already in flight still resolved and
 * published the sole-trader identity over the company name typed by hand.
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
    instance.startEnrollment();
    await flushPromises();
    await flushPromises();
    expect(typeof resolveBuyerLookup).toBe('function');

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
 * Idempotent and safe with nothing open - every caller fires it
 * unconditionally rather than testing for a popup first.
 */
test('abandonEnrollment() with no popup and no enrolment is a no-op that still disowns', () => {
    stubFetch({});
    const instance = build();
    const generationBefore = instance._enrollGeneration;

    instance.abandonEnrollment();
    instance.abandonEnrollment();

    // Generation bump is unconditional: a lookup can be in flight with `enrolling` already false.
    expect(instance._enrollGeneration).toBe(generationBefore + 2);
    expect(instance._popup).toBeNull();

    instance.destroy();
});
