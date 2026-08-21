/**
 * TWO-40 round 4: pins TwoSoleTrader.js's notifyEnrollmentSettled(), which
 * dispatches 'two:sole-trader-flight-settled' from every terminal branch of
 * startEnrollment()'s call graph. Each test drives one branch and asserts
 * the event fires - a fixed timeout in TwoCompanySearch.js would pass all
 * of these without ever wiring the real signal.
 */

'use strict';

const { loadSoleTrader, buildPaymentTile, buildAddressForm, flushPromises } = require('./ps-harness');

let TwoSoleTrader;

function build(overrides) {
    return new TwoSoleTrader(Object.assign({
        checkoutHost: 'https://api.example.test',
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        billingCountry: 'GB'
    }, overrides || {}));
}

function stubFetch(handlers) {
    global.window.fetch = (url) => {
        if (String(url).includes('soleTraderAvailability')) {
            return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
        }
        if (String(url).includes('soleTraderTokens')) {
            return handlers.tokens
                ? handlers.tokens()
                : Promise.resolve({
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
            return handlers.buyer ? handlers.buyer() : Promise.resolve({ ok: false, status: 404 });
        }
        if (String(url).includes('saveCompany')) {
            return handlers.save ? handlers.save() : Promise.resolve({ json: () => Promise.resolve({ success: true }) });
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;
}

/** Records every `two:sole-trader-flight-settled` document dispatch. */
function recordSettled() {
    const calls = [];
    const handler = () => calls.push(true);
    document.addEventListener('two:sole-trader-flight-settled', handler);
    return { calls, handler };
}

beforeEach(() => {
    TwoSoleTrader = loadSoleTrader();
});

afterEach(() => {
    delete global.fetch;
    delete global.window.fetch;
    delete global.window.TwoCompanyNumber;
    delete global.window.open;
    delete global.window.TwoCheckoutManager_Instance;
    document.body.innerHTML = '';
    global.window.localStorage.clear();
});

test('a successful autofill (buyer match) fires the settle event', async () => {
    buildPaymentTile();
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };
    global.window.TwoCheckoutManager_Instance = { setConfirmedCompanySelection: () => {} };
    document.body.insertAdjacentHTML('beforeend', "<input name='email' value='buyer@example.test' />");
    stubFetch({
        buyer: () => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ email: 'buyer@example.test', company_name: 'Sole Trader AS', organization_number: '923456789' })
        })
    });
    const { calls, handler } = recordSettled();

    const instance = build();
    instance.startEnrollment();
    await flushPromises();
    await flushPromises();
    await flushPromises();

    expect(calls.length).toBe(1);
    document.removeEventListener('two:sole-trader-flight-settled', handler);
    instance.destroy();
});

/**
 * Adversarial review finding (Yoda): other tests don't exercise the settle
 * guard while a popup is still open. This one does - a real OTP round trip
 * resolves while the popup the buyer authenticated in is still open.
 */
test('a genuine OTP completion settles the buyer lookup but withholds the spinner until the still-open popup actually closes', async () => {
    buildPaymentTile();
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };
    global.window.TwoCheckoutManager_Instance = { setConfirmedCompanySelection: () => {} };
    document.body.insertAdjacentHTML('beforeend', "<input name='email' value='order-contact@example.test' />");

    const popup = { closed: false };
    global.window.open = jest.fn(() => popup);

    let buyerLookupCalls = 0;
    stubFetch({
        buyer: () => {
            buyerLookupCalls += 1;
            if (buyerLookupCalls === 1) {
                // Passive cookie-match check - no match, falls through to on-page prompt.
                return Promise.resolve({ ok: false, status: 404 });
            }
            // Trusted lookup after genuine OTP completion.
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    email: 'sole-trader-real-account@example.test',
                    company_name: 'Sole Trader AS',
                    organization_number: '923456789'
                })
            });
        }
    });
    const { calls, handler } = recordSettled();

    jest.useFakeTimers();
    try {
        const instance = build();
        try {
            instance.startEnrollment();
            await flushPromises();
            await flushPromises();
            await flushPromises();

            // No-match on passive check - handed off to on-page prompt.
            expect(calls.length).toBe(1);

            const prompt = document.querySelector('.two-sole-trader__prompt');
            prompt.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
            expect(global.window.open).toHaveBeenCalledTimes(1);
            expect(instance._popup).toBe(popup);
            // Handed off to popup - not settled again yet.
            expect(calls.length).toBe(1);

            window.dispatchEvent(new window.MessageEvent('message', {
                data: 'ACCEPTED',
                origin: 'https://signup.example.test'
            }));
            await flushPromises();
            await flushPromises();
            await flushPromises();

            // applyBuyer() succeeded but popup still open - guard must withhold settle.
            expect(instance.enrolling).toBe(false);
            expect(calls.length).toBe(1);

            popup.closed = true;
            jest.advanceTimersByTime(500);

            expect(calls.length).toBe(2);
        } finally {
            instance.destroy();
        }
    } finally {
        jest.useRealTimers();
    }
    document.removeEventListener('two:sole-trader-flight-settled', handler);
});

/**
 * TWO-40 follow-up: focus returning to checkout must take the popup down
 * with panel/spinner. The settle must come from watchPopupUntilClosed()'s
 * poll, not a second dispatch on close - an abandon could settle a flight
 * whose write-back is still in flight.
 */
test('closeSignupPopup() closes a still-open popup, and leaves the settle to the poll that owns it', async () => {
    buildPaymentTile();
    document.body.insertAdjacentHTML('beforeend', "<input name='email' value='order-contact@example.test' />");

    const popup = {
        closed: false,
        close: jest.fn(() => { popup.closed = true; })
    };
    global.window.open = jest.fn(() => popup);
    stubFetch({ buyer: () => Promise.resolve({ ok: false, status: 404 }) });
    const { calls, handler } = recordSettled();

    jest.useFakeTimers();
    try {
        const instance = build();
        try {
            instance.startEnrollment();
            await flushPromises();
            await flushPromises();
            await flushPromises();

            document.querySelector('.two-sole-trader__prompt')
                .dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
            expect(instance._popup).toBe(popup);
            const settlesBefore = calls.length;

            instance.closeSignupPopup();

            expect(popup.close).toHaveBeenCalledTimes(1);
            // Not settled yet - poll hasn't run; method doesn't dispatch itself.
            expect(calls.length).toBe(settlesBefore);

            jest.advanceTimersByTime(500);

            expect(calls.length).toBe(settlesBefore + 1);
            expect(instance._popup).toBe(null);
        } finally {
            instance.destroy();
        }
    } finally {
        jest.useRealTimers();
    }
    document.removeEventListener('two:sole-trader-flight-settled', handler);
});

/**
 * focusSignupPopup() re-raises the popup for the "give me that popup back"
 * gesture (re-clicking the chip). The throw case is real, not defensive
 * padding: the hosted flow can close its own window between the check and
 * the call, so it must report false rather than propagate.
 */
test.each([
    [() => null, false, false, 'no popup was ever opened'],
    [() => ({ closed: true, focus: jest.fn() }), false, false, 'the popup has already gone'],
    [() => ({ closed: false, focus: jest.fn() }), true, true, 'a live popup is raised'],
    [() => ({ closed: false, focus: jest.fn(() => { throw new Error('gone'); }) }), false, true,
        'the window went away between the check and the raise'],
])('focusSignupPopup(): raised=%s called=%s when %s', (makePopup, raised, called) => {
    buildPaymentTile();
    stubFetch({});

    const instance = build();
    try {
        const popup = makePopup();
        instance._popup = popup;

        expect(instance.focusSignupPopup()).toBe(raised);
        if (popup) {
            expect(popup.focus.mock.calls.length > 0).toBe(called);
            // Doesn't clear the handle - the poll owns that and the settle.
            expect(instance._popup).toBe(popup);
        }
    } finally {
        instance.destroy();
    }
});

/**
 * openPopup()'s never-open-over-a-live-window guard gates on the window
 * being live, not on the raise succeeding (round 2 finding). A throwing
 * focus() must still return the existing handle, or a second popup opens
 * and orphans the first untracked (guide §14).
 */
test('openPopup() returns the live popup even when raising it throws, rather than opening a second', () => {
    buildPaymentTile();
    stubFetch({});

    const instance = build();
    try {
        const popup = {
            closed: false,
            focus: jest.fn(() => { throw new Error('gone'); })
        };
        instance._popup = popup;
        instance.tokens = { signup_url: 'https://signup.example.test/', delegation_token: 'd', autofill_token: 'a' };
        global.window.open = jest.fn();

        expect(instance.openPopup()).toBe(popup);
        expect(global.window.open).not.toHaveBeenCalled();
    } finally {
        instance.destroy();
    }
});

/**
 * An abandon can land on a window already gone (buyer closed it, or the
 * hosted flow closed it on 'ACCEPTED'). Both must be no-ops.
 */
test('closeSignupPopup() is a safe no-op with no popup, or one that has already gone', async () => {
    buildPaymentTile();
    stubFetch({});

    const instance = build();
    try {
        expect(instance._popup).toBe(null);
        expect(() => instance.closeSignupPopup()).not.toThrow();

        const alreadyClosed = { closed: true, close: jest.fn() };
        instance._popup = alreadyClosed;
        instance.closeSignupPopup();

        expect(alreadyClosed.close).not.toHaveBeenCalled();
    } finally {
        instance.destroy();
    }
    await flushPromises();
});

/**
 * The opposite, common-in-browser ordering: the hosted flow closes its own
 * window on 'ACCEPTED', so the 500ms poll often observes `closed` while the
 * write-back chain it started is still in flight.
 *
 * Doug's definition of complete (TWO-40 follow-up): popup closed AND lookup
 * resolved AND company written - all three. This test is written to FAIL
 * against a popup-close-only settle, and checks the ordering too.
 */
test('the popup closing does NOT settle the flight while the write-back is still out - the write does', async () => {
    buildPaymentTile();
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };

    const order = [];
    global.window.TwoCheckoutManager_Instance = {
        setConfirmedCompanySelection: () => {},
        companySearch: {
            adoptSoleTraderBuyer: () => {
                order.push('write');
                return true;
            }
        }
    };
    document.body.insertAdjacentHTML('beforeend', "<input name='email' value='order-contact@example.test' />");

    const popup = { closed: false };
    global.window.open = jest.fn(() => popup);

    // Held open so the popup can close first, then released by hand.
    let releaseSave;
    const savePending = new Promise((resolve) => { releaseSave = resolve; });

    let buyerLookupCalls = 0;
    stubFetch({
        buyer: () => {
            buyerLookupCalls += 1;
            if (buyerLookupCalls === 1) {
                return Promise.resolve({ ok: false, status: 404 });
            }
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    email: 'sole-trader-real-account@example.test',
                    company_name: 'Sole Trader AS',
                    organization_number: '923456789'
                })
            });
        },
        save: () => savePending.then(() => ({ json: () => Promise.resolve({ success: true }) }))
    });

    const calls = [];
    const handler = () => { calls.push(true); order.push('settle'); };
    document.addEventListener('two:sole-trader-flight-settled', handler);

    jest.useFakeTimers();
    try {
        const instance = build();
        try {
            instance.startEnrollment();
            await flushPromises();
            await flushPromises();
            await flushPromises();
            expect(calls.length).toBe(1);

            document.querySelector('.two-sole-trader__prompt')
                .dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
            expect(instance._popup).toBe(popup);

            window.dispatchEvent(new window.MessageEvent('message', {
                data: 'ACCEPTED',
                origin: 'https://signup.example.test'
            }));
            await flushPromises();
            await flushPromises();

            // Lookup back, applyBuyer() waiting on saveCompany - nothing written yet.
            expect(order).not.toContain('write');

            // Hosted flow closes window here, before the write lands.
            popup.closed = true;
            jest.advanceTimersByTime(500);
            await flushPromises();

            // The assertion this test exists for: popup gone, poll fired, not settled.
            expect(calls.length).toBe(1);

            releaseSave();
            await flushPromises();
            await flushPromises();
            await flushPromises();

            expect(calls.length).toBe(2);
            // And in this order, not merely both eventually true.
            expect(order).toEqual(['settle', 'write', 'settle']);
        } finally {
            instance.destroy();
        }
    } finally {
        jest.useRealTimers();
    }
    document.removeEventListener('two:sole-trader-flight-settled', handler);
});

test('a failed token mint fires the settle event', async () => {
    buildPaymentTile();
    stubFetch({ tokens: () => Promise.resolve({ json: () => Promise.resolve({ success: false }) }) });
    const { calls, handler } = recordSettled();

    const instance = build();
    instance.startEnrollment();
    await flushPromises();
    await flushPromises();

    expect(calls.length).toBe(1);
    document.removeEventListener('two:sole-trader-flight-settled', handler);
    instance.destroy();
});

test('a network failure on the buyer lookup fires the settle event', async () => {
    buildPaymentTile();
    stubFetch({ buyer: () => Promise.reject(new Error('network down')) });
    const { calls, handler } = recordSettled();

    const instance = build();
    instance.startEnrollment();
    await flushPromises();
    await flushPromises();
    await flushPromises();

    expect(calls.length).toBe(1);
    document.removeEventListener('two:sole-trader-flight-settled', handler);
    instance.destroy();
});

/**
 * TWO-40 follow-up, Doug: spinner was clearing as soon as window.open()
 * returned, not when the popup closed. Pins the fix: settle stays held
 * until watchPopupUntilClosed()'s poll observes `popup.closed`.
 */
test('a no-match buyer lookup handed off directly to the popup (address-editor page) keeps the spinner up until the popup actually closes', async () => {
    buildAddressForm();
    const popup = { closed: false };
    global.window.open = jest.fn(() => popup);
    stubFetch({ buyer: () => Promise.resolve({ ok: false, status: 404 }) });
    const { calls, handler } = recordSettled();

    jest.useFakeTimers();
    try {
        const instance = build();
        try {
            instance.startEnrollment();
            await flushPromises();
            await flushPromises();
            await flushPromises();

            // Handed off to popup - must not settle yet despite window.open() returning.
            expect(calls.length).toBe(0);

            // Buyer closes popup - completes/cancels/closes all read as `.closed`.
            popup.closed = true;
            jest.advanceTimersByTime(500);

            expect(calls.length).toBe(1);
        } finally {
            instance.destroy();
        }
    } finally {
        jest.useRealTimers();
    }
    document.removeEventListener('two:sole-trader-flight-settled', handler);
});

test('the spinner stays held if the popup takes a few poll ticks to actually close', async () => {
    buildAddressForm();
    const popup = { closed: false };
    global.window.open = jest.fn(() => popup);
    stubFetch({ buyer: () => Promise.resolve({ ok: false, status: 404 }) });
    const { calls, handler } = recordSettled();

    jest.useFakeTimers();
    try {
        const instance = build();
        try {
            instance.startEnrollment();
            await flushPromises();
            await flushPromises();
            await flushPromises();

            jest.advanceTimersByTime(500);
            expect(calls.length).toBe(0);
            jest.advanceTimersByTime(500);
            expect(calls.length).toBe(0);

            popup.closed = true;
            jest.advanceTimersByTime(500);
            expect(calls.length).toBe(1);
        } finally {
            instance.destroy();
        }
    } finally {
        jest.useRealTimers();
    }
    document.removeEventListener('two:sole-trader-flight-settled', handler);
});

test('no popup poll interval survives a normal popup-close settle', async () => {
    buildAddressForm();
    const popup = { closed: false };
    global.window.open = jest.fn(() => popup);
    stubFetch({ buyer: () => Promise.resolve({ ok: false, status: 404 }) });

    jest.useFakeTimers();
    try {
        const instance = build();
        try {
            instance.startEnrollment();
            await flushPromises();
            await flushPromises();
            await flushPromises();

            expect(jest.getTimerCount()).toBeGreaterThan(0);
            popup.closed = true;
            jest.advanceTimersByTime(500);
            // 1 not 0: poll gone, but TWO-40's background refresh timer
            // outlives the settle - only destroy() clears it.
            expect(jest.getTimerCount()).toBe(1);
        } finally {
            instance.destroy();
        }
    } finally {
        jest.useRealTimers();
    }
});

test('a popup blocked outright (window.open() returns null) never starts a poll and settles immediately', async () => {
    buildAddressForm();
    global.window.open = jest.fn(() => null);
    const errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
    stubFetch({ buyer: () => Promise.resolve({ ok: false, status: 404 }) });
    const { calls, handler } = recordSettled();

    jest.useFakeTimers();
    try {
        const instance = build();
        try {
            instance.startEnrollment();
            await flushPromises();
            await flushPromises();
            await flushPromises();

            expect(calls.length).toBe(1);
            // 1 not 0: mint succeeded (openPopup() is what failed) - TWO-40
            // refresh timer legitimately running.
            expect(jest.getTimerCount()).toBe(1);
        } finally {
            instance.destroy();
        }
    } finally {
        jest.useRealTimers();
    }
    errorSpy.mockRestore();
    document.removeEventListener('two:sole-trader-flight-settled', handler);
});

/**
 * Adversarial review finding (Han): a second openPopup() while the first
 * popup from the same attempt is still open used to open a second window
 * and silently retarget `_popup`, orphaning the first - closing the
 * original left the spinner stuck forever. Must refuse and refocus instead.
 */
test('calling openPopup() again while a popup is already open refocuses it instead of opening a second window', () => {
    buildAddressForm();
    const popup = { closed: false, focus: jest.fn() };
    const openSpy = jest.fn(() => popup);
    global.window.open = openSpy;
    stubFetch({});

    const instance = build();
    try {
        instance.tokens = {
            autofill_token: 'af-token',
            delegation_token: 'del-token',
            signup_url: 'https://signup.example.test/',
            country: 'GB'
        };
        const first = instance.openPopup();
        const second = instance.openPopup();

        expect(openSpy).toHaveBeenCalledTimes(1);
        expect(popup.focus).toHaveBeenCalledTimes(1);
        expect(second).toBe(first);
        expect(instance._popup).toBe(popup);
    } finally {
        instance.destroy();
    }
});

/**
 * cancelEnrollment() means the buyer moved to a different interaction, not
 * the popup closing - it must stop the poll rather than leave it running.
 */
test('cancelEnrollment() while a popup is open stops the poll instead of leaking it', async () => {
    buildAddressForm();
    const popup = { closed: false };
    global.window.open = jest.fn(() => popup);
    stubFetch({ buyer: () => Promise.resolve({ ok: false, status: 404 }) });

    jest.useFakeTimers();
    try {
        const instance = build();
        try {
            instance.startEnrollment();
            await flushPromises();
            await flushPromises();
            await flushPromises();

            expect(jest.getTimerCount()).toBeGreaterThan(0);
            instance.cancelEnrollment();
            // 1 not 0: cancelEnrollment() keeps `tokens` (resumable by design)
            // so TWO-40's refresh interval stays alive too - only destroy() clears it.
            expect(jest.getTimerCount()).toBe(1);
        } finally {
            instance.destroy();
        }
    } finally {
        jest.useRealTimers();
    }
});

test('destroy() while a popup is open stops the poll instead of leaking it', async () => {
    buildAddressForm();
    const popup = { closed: false };
    global.window.open = jest.fn(() => popup);
    stubFetch({ buyer: () => Promise.resolve({ ok: false, status: 404 }) });

    jest.useFakeTimers();
    try {
        const instance = build();
        instance.startEnrollment();
        await flushPromises();
        await flushPromises();
        await flushPromises();

        expect(jest.getTimerCount()).toBeGreaterThan(0);
        instance.destroy();
        expect(jest.getTimerCount()).toBe(0);
    } finally {
        jest.useRealTimers();
    }
});

test('a no-match buyer lookup handed off to the on-page prompt (payment step) fires the settle event', async () => {
    buildPaymentTile();
    stubFetch({ buyer: () => Promise.resolve({ ok: false, status: 404 }) });
    const { calls, handler } = recordSettled();

    const instance = build();
    instance.startEnrollment();
    await flushPromises();
    await flushPromises();
    await flushPromises();

    expect(calls.length).toBe(1);
    document.removeEventListener('two:sole-trader-flight-settled', handler);
    instance.destroy();
});

test('a blocked popup still fires the settle event exactly once (not twice, despite openPopup() also reaching showError())', async () => {
    buildAddressForm();
    global.window.open = jest.fn(() => null); // blocked
    const errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
    stubFetch({ buyer: () => Promise.resolve({ ok: false, status: 404 }) });
    const { calls, handler } = recordSettled();

    const instance = build();
    instance.startEnrollment();
    await flushPromises();
    await flushPromises();
    await flushPromises();

    expect(calls.length).toBe(1);
    errorSpy.mockRestore();
    document.removeEventListener('two:sole-trader-flight-settled', handler);
    instance.destroy();
});

/**
 * TWO-40 round 5, adversarial review finding (Leia): cancelEnrollment()
 * only bumped the generation counter and hid the prompt - it never told
 * TwoCompanySearch.js's spinner/listener the flight was over. apply() calls
 * it directly off a billing-country change while enrolling, a real path.
 */
test('cancelEnrollment() fires the settle event when it actually cancels an in-progress enrolment', () => {
    buildPaymentTile();
    stubFetch({});
    const instance = build();
    const { calls, handler } = recordSettled();

    instance.enrolling = true; // as startEnrollment() would have set it
    instance.cancelEnrollment();

    expect(calls.length).toBe(1);
    document.removeEventListener('two:sole-trader-flight-settled', handler);
    instance.destroy();
});

test('cancelEnrollment() does NOT fire the settle event when there was nothing to cancel', () => {
    buildPaymentTile();
    stubFetch({});
    const instance = build();
    const { calls, handler } = recordSettled();

    expect(instance.enrolling).toBe(false);
    instance.cancelEnrollment();

    expect(calls.length).toBe(0);
    document.removeEventListener('two:sole-trader-flight-settled', handler);
    instance.destroy();
});

/**
 * TWO-40 round 5, adversarial review finding (Han + Yoda): abandon-then-
 * retry while the FIRST mint is still outstanding used to leave the second
 * attempt's spinner running forever. fetchTokens()'s single in-flight guard
 * means the second click rides the first mint's request; when it resolves,
 * the fix must resume the lookup for whichever generation is CURRENT, not
 * drop tokens because they no longer match the stale generation.
 */
test('a mint that resolves after abandon-then-retry still resumes the buyer lookup for the current attempt', async () => {
    buildAddressForm();
    const popup = { closed: false };
    global.window.open = jest.fn(() => popup);
    let resolveTokens;
    stubFetch({
        tokens: () => new Promise((resolve) => { resolveTokens = resolve; }),
        buyer: () => Promise.resolve({ ok: false, status: 404 })
    });
    const { calls, handler } = recordSettled();

    jest.useFakeTimers();
    try {
        const instance = build();
        try {
            instance.startEnrollment(); // click 1: mint 1 starts, isFetchingTokens = true
            const generationAtFirstClick = instance._enrollGeneration;

            instance.cancelEnrollment(); // buyer abandons - settles click 1
            expect(calls.length).toBe(1);

            instance.startEnrollment(); // click 2: fetchTokens() no-ops (still in flight)
            expect(instance._enrollGeneration).not.toBe(generationAtFirstClick);
            expect(instance.tokens).toBeNull();

            // Mint 1 (only request that went out) resolves.
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
            await flushPromises();
            await flushPromises();

            // Click 2 handed off to popup - tokens landed but popup still
            // open, so its settle must not have fired (TWO-40 follow-up).
            expect(instance.tokens).not.toBeNull();
            expect(calls.length).toBe(1);

            popup.closed = true;
            jest.advanceTimersByTime(500);

            // Click 2 must settle once popup closes - not hang because
            // tokens landed under click 1's stale generation.
            expect(calls.length).toBe(2);
        } finally {
            instance.destroy();
        }
    } finally {
        jest.useRealTimers();
    }
    document.removeEventListener('two:sole-trader-flight-settled', handler);
});

/**
 * TWO-40 round 5, adversarial review finding (Han + Vader): getCurrentBuyer()
 * had no in-flight guard of its own (unlike fetchTokens()'s isFetchingTokens)
 * - a second concurrent lookup opened a second signup popup from one gesture.
 */
test('two concurrent getCurrentBuyer() calls only open one popup', async () => {
    buildAddressForm();
    const openSpy = jest.fn(() => ({ closed: false }));
    global.window.open = openSpy;
    stubFetch({ buyer: () => Promise.resolve({ ok: false, status: 404 }) });

    const instance = build();
    instance.tokens = {
        autofill_token: 'af-token',
        delegation_token: 'del-token',
        signup_url: 'https://signup.example.test/',
        country: 'GB'
    };
    instance.getCurrentBuyer();
    instance.getCurrentBuyer();
    await flushPromises();
    await flushPromises();
    await flushPromises();

    expect(openSpy).toHaveBeenCalledTimes(1);
    instance.destroy();
});

/**
 * TWO-40 round 5 follow-up, adversarial review round 2 (Han): the abandon-
 * then-retry resume fixed for the MINT stage had no equivalent for the
 * BUYER-LOOKUP stage - getCurrentBuyer()'s isFetchingBuyer guard's
 * superseded() branches just bare-returned. When click 1's lookup resolves,
 * the fix must resume for whichever generation is CURRENT rather than
 * dropping the result with nothing left to settle click 2.
 */
test('a buyer lookup that resolves after abandon-then-retry during the lookup stage still resumes for the current attempt', async () => {
    buildAddressForm();
    const popup = { closed: false };
    global.window.open = jest.fn(() => popup);
    let resolveBuyer;
    stubFetch({
        buyer: () => new Promise((resolve) => { resolveBuyer = resolve; })
    });
    const { calls, handler } = recordSettled();

    jest.useFakeTimers();
    try {
        const instance = build();
        try {
            instance.tokens = {
                autofill_token: 'af-token',
                delegation_token: 'del-token',
                signup_url: 'https://signup.example.test/',
                country: 'GB'
            };
            instance.enrolling = true;
            instance.getCurrentBuyer(); // click 1: lookup 1 starts, isFetchingBuyer = true

            instance.cancelEnrollment(); // buyer abandons - settles click 1
            expect(calls.length).toBe(1);

            instance.enrolling = true; // as startEnrollment()'s "resume" branch would set it
            instance.getCurrentBuyer(); // click 2: no-ops, lookup 1 still in flight

            // Lookup 1 resolves 404 - superseded() true (generation moved on),
            // settles into resumeIfStillEnrolling().
            resolveBuyer({ ok: false, status: 404 });
            await flushPromises();
            await flushPromises();
            await flushPromises();
            // resumeIfStillEnrolling() defers via setTimeout(0) - advance
            // macrotasks too so the resumed call issues its own fetch
            // (rebinds `resolveBuyer`).
            jest.advanceTimersByTime(0);
            await flushPromises();

            // Resumed lookup's own separate request resolves the same way -
            // no match, hands off to popup.
            resolveBuyer({ ok: false, status: 404 });
            await flushPromises();
            await flushPromises();
            await flushPromises();

            // Click 2 handed off to popup - still open, so its settle must
            // not have fired (TWO-40 follow-up).
            expect(global.window.open).toHaveBeenCalledTimes(1);
            expect(calls.length).toBe(1);

            popup.closed = true;
            jest.advanceTimersByTime(500);

            // Click 2 must settle once popup closes - not hang on click 1's
            // stale generation.
            expect(calls.length).toBe(2);
        } finally {
            instance.destroy();
        }
    } finally {
        jest.useRealTimers();
    }
    document.removeEventListener('two:sole-trader-flight-settled', handler);
});

/**
 * TWO-40 round 7, adversarial review round 3 (Han): resumeIfStillEnrolling()
 * checked `enrolling` once at SCHEDULE time then deferred via setTimeout(0)
 * - a second abandonment landing in that gap ran an unwanted lookup, popping
 * a signup window nobody asked for on the no-match path.
 */
test('a second abandonment landing during the deferred resume window does not fire an unwanted lookup', async () => {
    buildAddressForm();
    const openSpy = jest.fn(() => ({ closed: false }));
    global.window.open = openSpy;
    let resolveBuyer;
    stubFetch({
        buyer: () => new Promise((resolve) => { resolveBuyer = resolve; })
    });
    const { calls, handler } = recordSettled();

    const instance = build();
    instance.tokens = {
        autofill_token: 'af-token',
        delegation_token: 'del-token',
        signup_url: 'https://signup.example.test/',
        country: 'GB'
    };
    instance.enrolling = true;
    instance.getCurrentBuyer(); // click 1: lookup 1 starts

    instance.cancelEnrollment(); // abandon #1
    instance.enrolling = true; // resumed, as startEnrollment() would set it
    instance.getCurrentBuyer(); // click 2: no-ops, lookup 1 still in flight

    resolveBuyer({ ok: false, status: 404 }); // lookup 1 settles, superseded
    await flushPromises();
    await flushPromises();
    await flushPromises();

    // Second abandonment lands before the deferred setTimeout(0) fires -
    // `enrolling` false by then.
    instance.cancelEnrollment();
    expect(instance.enrolling).toBe(false);

    await new Promise((resolve) => setTimeout(resolve, 0));
    await flushPromises();
    await flushPromises();

    // No lookup should run for the abandoned attempt - no fetch, no popup.
    expect(openSpy).not.toHaveBeenCalled();
    document.removeEventListener('two:sole-trader-flight-settled', handler);
    instance.destroy();
});

/**
 * TWO-40 round 5 follow-up, adversarial review round 2 (Vader): the
 * synchronous stretch between setting isFetchingTokens/isFetchingBuyer true
 * and the fetch() call starting was unprotected. A throw there left the
 * guard stuck true FOREVER, with no recovery and no settle to ever close
 * an already-open panel/spinner.
 */
test('a synchronous throw building the token-mint request does not permanently wedge fetchTokens()', () => {
    buildPaymentTile();
    stubFetch({});
    const instance = build();
    const { handler } = recordSettled();
    const errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});

    // Force a synchronous throw the way a malformed billingCountry() config could.
    const originalBillingCountry = instance.billingCountry;
    instance.billingCountry = () => { throw new Error('boom'); };

    expect(() => instance.startEnrollment()).not.toThrow();
    expect(instance.isFetchingTokens).toBe(false);

    // The guard must not be stuck - a later, working call proceeds.
    instance.billingCountry = originalBillingCountry;
    global.window.fetch = () => Promise.resolve({ json: () => Promise.resolve({ success: false }) });
    global.fetch = global.window.fetch;
    instance.nextRetryAt = 0; // skip the retry cooldown the failed attempt armed
    instance.startEnrollment();
    expect(instance.isFetchingTokens).toBe(true);

    errorSpy.mockRestore();
    document.removeEventListener('two:sole-trader-flight-settled', handler);
    instance.destroy();
});

/**
 * TWO-40 round 7, adversarial review round 3 (Vader): the retry cooldown
 * (`nextRetryAt`) predates the round-4 "keep panel open until settle"
 * redesign and was never wired into it. Unlike isFetchingTokens (where a
 * request is out and will eventually resume), a click inside the cooldown
 * has nothing in flight to ever settle it - the panel used to stick open
 * indefinitely.
 */
test('a click landing inside the retry cooldown still settles its own flight, rather than dead-ending open', () => {
    buildPaymentTile();
    stubFetch({ tokens: () => Promise.resolve({ json: () => Promise.resolve({ success: false }) }) });
    const instance = build();
    const { calls, handler } = recordSettled();

    instance.startEnrollment(); // click 1: mint fails, arms the cooldown
    return flushPromises().then(() => flushPromises()).then(() => {
        expect(instance.nextRetryAt).toBeGreaterThan(Date.now());
        expect(calls.length).toBe(1);

        instance.startEnrollment(); // click 2: lands inside the cooldown

        expect(calls.length).toBe(2);
        document.removeEventListener('two:sole-trader-flight-settled', handler);
        instance.destroy();
    });
});

test('a synchronous throw building the buyer-lookup request does not permanently wedge getCurrentBuyer()', () => {
    buildAddressForm();
    stubFetch({});
    const instance = build();

    // this.tokens is null - the exact throw shape Vader flagged.
    expect(instance.tokens).toBeNull();

    expect(() => instance.getCurrentBuyer()).not.toThrow();
    expect(instance.isFetchingBuyer).toBe(false);

    // The guard must not be stuck - a later, working call proceeds.
    instance.tokens = { autofill_token: 'af-token' };
    global.window.fetch = () => new Promise(() => {}); // never resolves; just prove it starts
    global.fetch = global.window.fetch;
    instance.getCurrentBuyer();
    expect(instance.isFetchingBuyer).toBe(true);

    instance.destroy();
});
