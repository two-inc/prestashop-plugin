/**
 * TWO-40 follow-up, Doug: delegated auth tokens can expire server-side if a
 * buyer sits on checkout too long, breaking autofill and the sole-trader
 * flow. Pins the fixed behaviour: a 30-minute background re-mint, armed by
 * startEagerTokenMint() as soon as an eligible billing country resolves, and
 * reusing fetchTokens()'s own `isFetchingTokens` guard rather than a second,
 * uncoordinated mint path.
 *
 * The eager mint that arms it is covered by
 * sole-trader-eager-token-mint.test.js.
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

function tokenPayload(suffix) {
    return {
        success: true,
        autofill_token: 'af-token-' + suffix,
        delegation_token: 'del-token-' + suffix,
        signup_url: 'https://signup.example.test/',
        country: 'GB'
    };
}

/**
 * Stubs fetch(), routing soleTraderTokens calls through `mintHandler` (so
 * tests can hand back a fresh payload, a pending Promise, or a failure per
 * call) and answering every other endpoint (availability, buyer lookup)
 * inertly so they cannot interfere with what each test is asserting.
 */
function stubFetch(mintHandler) {
    global.window.fetch = (url) => {
        if (String(url).includes('soleTraderAvailability')) {
            return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
        }
        if (String(url).includes('soleTraderTokens')) {
            return mintHandler();
        }
        if (String(url).includes('/autofill/v1/buyer/current')) {
            return Promise.resolve({ ok: false, status: 404 });
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;
}

beforeEach(() => {
    buildPaymentTile();
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

test('the refresh interval does not start while the billing country is unresolved', () => {
    jest.useFakeTimers();
    try {
        let mintCalls = 0;
        stubFetch(() => {
            mintCalls += 1;
            return Promise.resolve({ json: () => Promise.resolve(tokenPayload('never-called')) });
        });

        // No country at all resolves, so no eligible country ever does either:
        // there is nothing for startEagerTokenMint() to mint against.
        const instance = build({ billingCountry: '', shopCountry: '' });
        jest.advanceTimersByTime(60 * 60 * 1000);

        expect(mintCalls).toBe(0);
        expect(instance._tokenRefreshIntervalId).toBeNull();
        instance.destroy();
    } finally {
        jest.useRealTimers();
    }
});

test('a real mint starts the interval, which re-mints via the guarded fetchTokens() path at the 30-minute mark', async () => {
    jest.useFakeTimers();
    try {
        let mintCalls = 0;
        stubFetch(() => {
            mintCalls += 1;
            return Promise.resolve({ json: () => Promise.resolve(tokenPayload(String(mintCalls))) });
        });

        const instance = build();
        instance.startEnrollment();
        await flushPromises();

        expect(mintCalls).toBe(1);
        expect(instance.tokens.autofill_token).toBe('af-token-1');

        jest.advanceTimersByTime(30 * 60 * 1000);
        await flushPromises();

        expect(mintCalls).toBe(2);
        expect(instance.tokens.autofill_token).toBe('af-token-2');

        instance.destroy();
    } finally {
        jest.useRealTimers();
    }
});

test('a tick is skipped, not queued, while a mint is already in flight', async () => {
    jest.useFakeTimers();
    try {
        let mintCalls = 0;
        let resolveSecondMint;
        stubFetch(() => {
            mintCalls += 1;
            if (mintCalls === 1) {
                return Promise.resolve({ json: () => Promise.resolve(tokenPayload('1')) });
            }
            // The buyer clicks "select a different sole trader" right as the
            // 30-minute tick fires - a real mint is genuinely in flight.
            return new Promise((resolve) => { resolveSecondMint = resolve; });
        });

        const instance = build();
        instance.startEnrollment();
        await flushPromises();
        expect(mintCalls).toBe(1);

        instance.isFetchingTokens = true;
        instance.refreshTokens();
        // The guard skips the tick outright - no second request queued
        // behind the in-flight one.
        expect(mintCalls).toBe(1);

        instance.isFetchingTokens = false;
        resolveSecondMint = null;
        instance.destroy();
    } finally {
        jest.useRealTimers();
    }
});

test('a failed refresh tick leaves the existing tokens in place for the next scheduled retry', async () => {
    jest.useFakeTimers();
    try {
        let mintCalls = 0;
        stubFetch(() => {
            mintCalls += 1;
            if (mintCalls === 1) {
                return Promise.resolve({ json: () => Promise.resolve(tokenPayload('good')) });
            }
            return Promise.reject(new Error('network down'));
        });

        const instance = build();
        instance.startEnrollment();
        await flushPromises();
        const mintedTokens = instance.tokens;

        jest.advanceTimersByTime(30 * 60 * 1000);
        await flushPromises();

        expect(mintCalls).toBe(2);
        // Unlike fetchTokens()'s own failure handling (which nulls tokens
        // out), a failed background refresh must not break autofill for a
        // buyer who still has valid, not-yet-expired tokens.
        expect(instance.tokens).toBe(mintedTokens);
        expect(instance.isFetchingTokens).toBe(false);

        instance.destroy();
    } finally {
        jest.useRealTimers();
    }
});

test('a refresh tick after cancelEnrollment() still silently replaces the tokens, without re-entering the enrolment flow', async () => {
    jest.useFakeTimers();
    try {
        let mintCalls = 0;
        stubFetch(() => {
            mintCalls += 1;
            return Promise.resolve({ json: () => Promise.resolve(tokenPayload(String(mintCalls))) });
        });
        global.window.open = jest.fn();

        const instance = build();
        instance.startEnrollment();
        await flushPromises();
        expect(mintCalls).toBe(1);

        // Buyer abandons the flow - bumps `_enrollGeneration`, but does NOT
        // discard `tokens` (see cancelEnrollment()'s own comment).
        instance.cancelEnrollment();

        jest.advanceTimersByTime(30 * 60 * 1000);
        await flushPromises();

        // The tick still re-minted (tokens are kept alive regardless of
        // enrolment state), but did not act on them: no popup, no new
        // buyer lookup - refreshTokens() never calls afterTokensReady().
        expect(mintCalls).toBe(2);
        expect(instance.tokens.autofill_token).toBe('af-token-2');
        expect(global.window.open).not.toHaveBeenCalled();

        instance.destroy();
    } finally {
        jest.useRealTimers();
    }
});

test('destroy() clears the timer - no further mint after teardown', async () => {
    jest.useFakeTimers();
    try {
        let mintCalls = 0;
        stubFetch(() => {
            mintCalls += 1;
            return Promise.resolve({ json: () => Promise.resolve(tokenPayload(String(mintCalls))) });
        });

        const instance = build();
        instance.startEnrollment();
        await flushPromises();
        expect(mintCalls).toBe(1);

        instance.destroy();

        jest.advanceTimersByTime(60 * 60 * 1000);
        await flushPromises();

        expect(mintCalls).toBe(1);
    } finally {
        jest.useRealTimers();
    }
});

test('the interval fires repeatedly, not just once - two consecutive successful ticks re-mint twice', async () => {
    jest.useFakeTimers();
    try {
        let mintCalls = 0;
        stubFetch(() => {
            mintCalls += 1;
            return Promise.resolve({ json: () => Promise.resolve(tokenPayload(String(mintCalls))) });
        });

        const instance = build();
        instance.startEnrollment();
        await flushPromises();
        expect(mintCalls).toBe(1);

        jest.advanceTimersByTime(30 * 60 * 1000);
        await flushPromises();
        expect(mintCalls).toBe(2);
        expect(instance.tokens.autofill_token).toBe('af-token-2');

        jest.advanceTimersByTime(30 * 60 * 1000);
        await flushPromises();
        expect(mintCalls).toBe(3);
        expect(instance.tokens.autofill_token).toBe('af-token-3');

        instance.destroy();
    } finally {
        jest.useRealTimers();
    }
});

/**
 * Adversarial review round 1 BLOCKER (Han/Vader/Yoda, independently):
 * openPopup() bakes `this.tokens` into the popup's own URL at open time: a
 * background tick that swaps `this.tokens` while that popup is still open
 * would authenticate the popup's eventual 'ACCEPTED' completion against a
 * token pair the buyer's OTP flow never actually ran through.
 */
test('a tick is skipped while a signup popup opened against the current tokens is still open', async () => {
    jest.useFakeTimers();
    try {
        let mintCalls = 0;
        stubFetch(() => {
            mintCalls += 1;
            return Promise.resolve({ json: () => Promise.resolve(tokenPayload(String(mintCalls))) });
        });
        const popup = { closed: false };
        global.window.open = jest.fn(() => popup);

        const instance = build();
        instance.startEnrollment();
        // Mint succeeds; the immediate buyer lookup (404, no match) hands
        // off to the on-page prompt rather than opening the popup itself.
        await flushPromises();
        expect(mintCalls).toBe(1);

        const prompt = document.querySelector('.two-sole-trader__prompt');
        prompt.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
        expect(global.window.open).toHaveBeenCalledTimes(1);
        expect(instance._popup).toBe(popup);

        const mintedTokens = instance.tokens;
        jest.advanceTimersByTime(30 * 60 * 1000);
        await flushPromises();

        // Popup still open - the tick must have been skipped outright.
        expect(mintCalls).toBe(1);
        expect(instance.tokens).toBe(mintedTokens);

        // Buyer closes the popup; watchPopupUntilClosed()'s own poll clears
        // `this._popup` on its next 500ms tick.
        popup.closed = true;
        jest.advanceTimersByTime(500);
        await flushPromises();
        expect(instance._popup).toBeNull();

        jest.advanceTimersByTime(30 * 60 * 1000);
        await flushPromises();
        expect(mintCalls).toBe(2);
        expect(instance.tokens.autofill_token).toBe('af-token-2');

        instance.destroy();
    } finally {
        jest.useRealTimers();
    }
});

/**
 * Adversarial review round 1 BUG (Vader): fetchTokens()'s own mint keeps the
 * posted country and the eligibility-check country in agreement "by
 * construction" (see its own comment) - a background refresh minting for a
 * DIFFERENT country than the one `this.tokens` currently belongs to would
 * silently break that agreement.
 */
test('a tick is skipped if the billing country has diverged from the country the current tokens were minted for', async () => {
    jest.useFakeTimers();
    try {
        let mintCalls = 0;
        stubFetch(() => {
            mintCalls += 1;
            return Promise.resolve({ json: () => Promise.resolve(tokenPayload(String(mintCalls))) });
        });

        const instance = build({ billingCountry: 'GB' });
        instance.startEnrollment();
        await flushPromises();
        expect(mintCalls).toBe(1);
        expect(instance.tokens.country).toBe('GB');

        // The buyer changes billing country mid-enrolment WITHOUT it
        // becoming ineligible (config-time billingCountry() fallback can't
        // move on its own, so this pins the guard directly rather than
        // simulating a country-select change).
        instance.config.billingCountry = 'SE';

        jest.advanceTimersByTime(30 * 60 * 1000);
        await flushPromises();

        expect(mintCalls).toBe(1);
        expect(instance.tokens.autofill_token).toBe('af-token-1');

        instance.destroy();
    } finally {
        jest.useRealTimers();
    }
});

/**
 * Round 2 adversarial review (Han finding): the entry guard on `this._popup`
 * only proves no popup was open when the tick STARTED - it says nothing
 * about a popup opened WHILE that tick's mint POST is still out.
 * openPopup() (via the on-page prompt's click handler) has no
 * `isFetchingTokens` guard of its own, so this is a real, if narrow, window.
 */
test('a popup opened while a background mint is still in flight is not orphaned by that mint landing', async () => {
    let mintCalls = 0;
    let resolveSecondMint;
    stubFetch(() => {
        mintCalls += 1;
        if (mintCalls === 1) {
            return Promise.resolve({ json: () => Promise.resolve(tokenPayload('1')) });
        }
        return new Promise((resolve) => { resolveSecondMint = resolve; });
    });
    const popup = { closed: false };
    global.window.open = jest.fn(() => popup);

    const instance = build();
    instance.startEnrollment();
    // Mint succeeds; the buyer lookup (404, no match) hands off to the
    // on-page prompt.
    await flushPromises();
    expect(mintCalls).toBe(1);

    // A background tick starts - no popup open yet, so the entry guard lets
    // it through - and its mint is still in flight.
    instance.refreshTokens();
    expect(mintCalls).toBe(2);

    // The buyer clicks the prompt WHILE that mint is out, baking the
    // (still-old) tokens into a brand new popup.
    const prompt = document.querySelector('.two-sole-trader__prompt');
    prompt.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    expect(global.window.open).toHaveBeenCalledTimes(1);
    expect(instance._popup).toBe(popup);

    resolveSecondMint({ json: () => Promise.resolve(tokenPayload('2')) });
    await flushPromises();

    // Must not have overwritten the tokens the just-opened popup was baked
    // against - the re-check inside refreshTokens()'s own .then() catches
    // what the entry guard alone could not.
    expect(instance.tokens.autofill_token).toBe('af-token-1');

    instance.destroy();
});

/**
 * Round 2 adversarial review (Leia finding): a mint still outstanding when
 * destroy() runs (e.g. PrestaShop swaps in a fresh instance for a replaced
 * payment fragment) must not arm a NEW setInterval on the now-dead instance
 * when it eventually resolves - nothing will ever call destroy() on it
 * again to clear it.
 */
test('a mint that resolves after destroy() does not arm a background-refresh interval', async () => {
    // Round 3 adversarial review (Vader finding): `_tokenRefreshIntervalId`
    // is a proxy the code under test writes itself - a mutant that armed a
    // REAL setInterval() without recording its handle there would still
    // read null here. jest.getTimerCount() proves no timer, of any kind,
    // was actually scheduled.
    jest.useFakeTimers();
    try {
        let resolveMint;
        stubFetch(() => new Promise((resolve) => { resolveMint = resolve; }));

        const instance = build();
        instance.startEnrollment();
        instance.destroy();

        resolveMint({ json: () => Promise.resolve(tokenPayload('late')) });
        await flushPromises();

        expect(instance._tokenRefreshIntervalId).toBeNull();
        expect(jest.getTimerCount()).toBe(0);
    } finally {
        jest.useRealTimers();
    }
});

/**
 * Round 3 adversarial review (Leia/Yoda, convergent): fetchTokens()'s own
 * success branch checks `_destroyed` before touching `this.tokens` -
 * refreshTokens()'s should too, for the same reason (this instance is gone,
 * nothing is safe to act on), even though it arms nothing that could leak.
 */
test('a background mint that resolves after destroy() does not write to a torn-down instance', async () => {
    let mintCalls = 0;
    let resolveSecondMint;
    stubFetch(() => {
        mintCalls += 1;
        if (mintCalls === 1) {
            return Promise.resolve({ json: () => Promise.resolve(tokenPayload('1')) });
        }
        return new Promise((resolve) => { resolveSecondMint = resolve; });
    });

    const instance = build();
    instance.startEnrollment();
    await flushPromises();
    expect(mintCalls).toBe(1);

    instance.refreshTokens();
    expect(mintCalls).toBe(2);

    instance.destroy();
    resolveSecondMint({ json: () => Promise.resolve(tokenPayload('2')) });
    await flushPromises();

    expect(instance.tokens.autofill_token).toBe('af-token-1');
});
