/**
 * TWO-40 round 4, Doug's explicit request: "keep the company search control
 * open, show spinner in query field" for the duration of a Sole Trader
 * click's real autofill round trip.
 *
 * TwoCompanySearch.js needs to know when that round trip is DONE - whatever
 * the outcome - so it can stop the spinner and close the panel. This pins
 * the signal it listens for: TwoSoleTrader.js's notifyEnrollmentSettled(),
 * which fires `document.dispatchEvent(new CustomEvent('two:sole-trader-flight-settled'))`
 * from every terminal branch of startEnrollment()'s call graph. Each test
 * below drives one branch and asserts the event actually fires from it -
 * a fixed timeout in TwoCompanySearch.js would pass every one of these
 * trivially without ever wiring the real signal, which is exactly the
 * class of bug this file exists to rule out.
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
 * Adversarial review finding (Yoda): every OTHER test drives
 * notifyEnrollmentSettled()'s popup-open guard through a path where
 * `this._popup` is either never set or already nulled by the poll itself -
 * none of them actually exercises the guard suppressing a call from a
 * DIFFERENT terminal branch while a popup is still open. This does: a real
 * OTP round trip (postMessage 'ACCEPTED') resolves through applyBuyer()'s
 * success branch while the popup the buyer authenticated in is still open on
 * screen - the spinner must not clear until they actually close it.
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
                // Passive cookie-match check on the order's own checkout
                // email - no match, so this falls through to the on-page
                // prompt.
                return Promise.resolve({ ok: false, status: 404 });
            }
            // The trusted lookup issued after a genuine OTP completion.
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

            // No-match on the passive check - handed off to the on-page
            // prompt (nothing left to wait on until the buyer clicks it).
            expect(calls.length).toBe(1);

            const prompt = document.querySelector('.two-sole-trader__prompt');
            prompt.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
            expect(global.window.open).toHaveBeenCalledTimes(1);
            expect(instance._popup).toBe(popup);
            // Popup handed off to - must not have settled again yet.
            expect(calls.length).toBe(1);

            window.dispatchEvent(new window.MessageEvent('message', {
                data: 'ACCEPTED',
                origin: 'https://signup.example.test'
            }));
            await flushPromises();
            await flushPromises();
            await flushPromises();

            // applyBuyer() succeeded - the buyer is enrolled - but the popup
            // they just authenticated in is STILL open, so the guard must
            // withhold the settle event.
            expect(instance.enrolling).toBe(false);
            expect(calls.length).toBe(1);

            // Buyer now closes the popup.
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
 * TWO-40 follow-up, Doug: the spinner was clearing as soon as window.open()
 * returned, not when the popup itself closed. Pins the fixed behavior: the
 * settle event stays held while the popup is open, and only fires once
 * watchPopupUntilClosed()'s poll observes `popup.closed`.
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

            // Popup handed off to - the spinner must NOT settle yet, even
            // though window.open() has already returned.
            expect(calls.length).toBe(0);

            // Buyer closes the popup (completes, cancels inside it, or just
            // closes the window - all three read identically as `.closed`).
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
            // 1, not 0: the poll interval is gone, but TWO-40's 30-minute
            // background token-refresh interval is a real timer that
            // legitimately outlives the settle - only destroy() clears it.
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
            // 1, not 0: the mint still succeeded (openPopup() itself is what
            // fails here), so TWO-40's background token-refresh interval is
            // legitimately running.
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
 * Adversarial review finding (Han): calling openPopup() a second time while
 * the first popup from the SAME attempt is still open (e.g. the buyer
 * double-clicks the on-page "sign up" prompt) used to open a SECOND browser
 * window and silently retarget `this._popup` to it - orphaning the first
 * window untracked, so a buyer closing the ORIGINAL window instead of the
 * new one left the spinner stuck forever. openPopup() must refuse to open a
 * second window while one is still tracked open and just refocus it.
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
 * cancelEnrollment() means the buyer moved on to a different search
 * interaction, not the popup closing - it must stop the poll (no leaked
 * interval) rather than leave it running against a popup nobody is tracking
 * for this attempt any more.
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
            // 1, not 0: cancelEnrollment() deliberately does not discard
            // `tokens` (resumable by design, see its own comment), so
            // TWO-40's background refresh interval keeps them alive too -
            // only destroy() clears it.
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
 * TWO-40 round 5, adversarial review finding (Leia): cancelEnrollment() only
 * bumped the generation counter and hid the prompt - it never told
 * TwoCompanySearch.js's spinner/listener that the flight it was watching is
 * over. apply() calls cancelEnrollment() directly off a live billing-country
 * change while enrolling, not just from a click handler, so this is a real
 * path, not a hypothetical.
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
 * TWO-40 round 5, adversarial review finding (Han + Yoda, independently):
 * abandon-then-retry while the FIRST mint is still outstanding used to leave
 * the second attempt's spinner running forever. Sequence: click Sole Trader
 * (mint 1 in flight) -> buyer abandons (cancelEnrollment() bumps the
 * generation) -> buyer clicks Sole Trader again. Because fetchTokens() has a
 * single in-flight guard, the SECOND click's startEnrollment() rides mint
 * 1's request rather than issuing its own - there is only ever one mint in
 * flight. When that shared mint resolves successfully, the fix must resume
 * the buyer lookup for whichever generation is CURRENT, not silently drop
 * newly-minted tokens because they no longer match the STALE generation
 * that originally requested them.
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

            // Mint 1 (the only request that ever went out) now resolves.
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

            // Click 2 resumed and handed off to the popup - tokens landed,
            // but the popup is still open, so click 2's own settle must not
            // have fired yet (TWO-40 follow-up).
            expect(instance.tokens).not.toBeNull();
            expect(calls.length).toBe(1);

            popup.closed = true;
            jest.advanceTimersByTime(500);

            // Click 2 must have been resumed and settled on its own once the
            // popup closed - not left hanging because the tokens landed
            // under click 1's stale generation.
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
 * TWO-40 round 5, adversarial review finding (Han + Vader, independently):
 * getCurrentBuyer() had no in-flight guard of its own (unlike fetchTokens()'s
 * isFetchingTokens) - a second concurrent lookup on the no-match path opened
 * a second signup popup from one buyer gesture.
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
 * TWO-40 round 5 follow-up, adversarial review round 2 finding (Han): the
 * abandon-then-retry resume fixed above for the MINT stage had no
 * equivalent for the BUYER-LOOKUP stage - getCurrentBuyer() got the exact
 * same isFetchingBuyer single-flight guard in the same commit, but its own
 * superseded() branches just bare-returned, one call deeper than the bug
 * this whole PR chain exists to fix. Sequence: click 1 mints fast and its
 * buyer lookup is outstanding -> buyer abandons (cancelEnrollment() bumps
 * the generation) -> buyer clicks Sole Trader again; tokens already exist,
 * so click 2 goes straight to getCurrentBuyer(), which no-ops on
 * isFetchingBuyer (click 1's lookup still out). When click 1's lookup
 * resolves, the fix must resume for whichever generation is CURRENT rather
 * than dropping the result with nothing left to ever settle click 2.
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

            // Lookup 1 (the only request that ever went out) now resolves with a
            // 404 - no match. superseded() is true (generation moved on from the
            // abandon), so this settles into resumeIfStillEnrolling() rather than
            // acting on it directly.
            resolveBuyer({ ok: false, status: 404 });
            await flushPromises();
            await flushPromises();
            await flushPromises();
            // resumeIfStillEnrolling() defers via setTimeout(0) - advance the fake
            // macrotask queue too, not just the microtask queue flushPromises()
            // covers, so the resumed getCurrentBuyer() call actually runs and
            // issues ITS OWN fetch (`buyer` factory is called again, rebinding
            // `resolveBuyer` to that new promise's resolver below).
            jest.advanceTimersByTime(0);
            await flushPromises();

            // The resumed lookup's own, SEPARATE request now resolves the same way
            // - still no match, hands off to the popup on this containerless page.
            resolveBuyer({ ok: false, status: 404 });
            await flushPromises();
            await flushPromises();
            await flushPromises();

            // Click 2 was resumed and handed off to the popup - but the popup
            // is still open, so its own settle must not have fired yet
            // (TWO-40 follow-up).
            expect(global.window.open).toHaveBeenCalledTimes(1);
            expect(calls.length).toBe(1);

            popup.closed = true;
            jest.advanceTimersByTime(500);

            // Click 2 must have been settled on its own once the popup
            // closed - not left hanging because the response landed under
            // click 1's stale generation.
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
 * TWO-40 round 7, adversarial review round 3 finding (Han): resumeIfStillEnrolling()
 * checked `enrolling` once, at SCHEDULE time, then deferred via setTimeout(0)
 * - the real time gap between scheduling and firing is exactly wide enough
 * for a SECOND abandonment to land in it. Without a re-check at fire time,
 * the deferred callback ran a full, unwanted buyer lookup for someone who
 * had already walked away from the flow a second time - on the no-match
 * path, popping a signup window nobody asked for any more.
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

    // A SECOND abandonment, landing in the gap before the deferred resume's
    // setTimeout(0) has fired - `enrolling` is false again by the time it does.
    instance.cancelEnrollment();
    expect(instance.enrolling).toBe(false);

    // Now let the deferred macrotask actually fire.
    await new Promise((resolve) => setTimeout(resolve, 0));
    await flushPromises();
    await flushPromises();

    // No lookup should have run for the abandoned second attempt - no fetch
    // issued, no popup opened.
    expect(openSpy).not.toHaveBeenCalled();
    document.removeEventListener('two:sole-trader-flight-settled', handler);
    instance.destroy();
});

/**
 * TWO-40 round 5 follow-up, adversarial review round 2 finding (Vader): the
 * synchronous stretch between setting isFetchingTokens/isFetchingBuyer true
 * and the fetch() call actually starting was unprotected. A throw there
 * left the guard stuck true FOREVER - unlike a throw from
 * TwoSoleTrader_Instance.startEnrollment() itself (which
 * TwoCompanySearch.js's own try/catch recovers), this one has no recovery
 * at all: every future click silently no-ops on the guard for the rest of
 * the page's life, with nothing ever in flight and no settle event to ever
 * close a THEN-open panel/spinner.
 */
test('a synchronous throw building the token-mint request does not permanently wedge fetchTokens()', () => {
    buildPaymentTile();
    stubFetch({});
    const instance = build();
    const { handler } = recordSettled();
    const errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});

    // Force a synchronous throw from inside fetchTokens()'s own pre-fetch
    // setup, the same way a malformed billingCountry() config could.
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
 * TWO-40 round 7, adversarial review round 3 finding (Vader): the retry
 * cooldown (`nextRetryAt`) predates the round-4 "keep panel open until
 * settle" redesign and was never wired into it. Unlike the isFetchingTokens
 * branch (where a request really is out and will eventually resume this
 * click), a click landing inside the cooldown window has NOTHING in flight
 * to ever settle it - the panel/spinner used to be stuck open indefinitely,
 * recoverable only by an unrelated action (Escape, reopening, switching
 * chips). A buyer clicking "I'm a sole trader" again within 5 seconds of a
 * failed attempt - "did that work? let me retry" - is an entirely ordinary
 * gesture, not an edge case.
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

    // this.tokens is null - the exact shape of throw Vader flagged
    // (`this.tokens.autofill_token` reads off null).
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
