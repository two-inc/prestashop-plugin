/**
 * TWO-40 follow-up, Doug: the autofill lookup runs at mount, together with the
 * token mint, and its ANSWER is held client-side. "I'm a sole trader" is then a
 * synchronous branch on state already known - autofill, prompt, or the signup
 * popup opened inside the click's own call stack, which is what keeps that
 * popup out of Safari's popup blocker.
 *
 * The mint half is covered by sole-trader-eager-token-mint.test.js.
 */

'use strict';

const {
    loadSoleTrader,
    buildPaymentTileWithSoleTraderAnswer,
    buildAddressForm,
    flushPromises
} = require('./ps-harness');

const BUYER_EMAIL = 'sole@trader.test';

let TwoSoleTrader;
let instances;
let settleListeners;

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

/** The checkout email applyOrPrompt() matches an untrusted answer against. */
function buildEmailField(value) {
    const holder = document.createElement('div');
    holder.innerHTML = "<input name='email' value='" + value + "'>";
    document.body.appendChild(holder);
}

function registeredBuyer() {
    return {
        email: BUYER_EMAIL,
        company_name: 'Sole Trader Ltd',
        organization_number: 'TWO:123456'
    };
}

/**
 * @param {Function} buyerAnswer called per autofill lookup, returning the
 *        response (or a pending Promise) that lookup should see
 * @param {string} [country] the country the mint answers for
 */
function stubFetch(buyerAnswer, country) {
    const calls = { mints: 0, buyerLookups: 0, saves: [] };
    global.window.fetch = (url, options) => {
        const target = String(url);
        if (target.includes('soleTraderAvailability')) {
            return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
        }
        if (target.includes('soleTraderTokens')) {
            calls.mints += 1;
            return Promise.resolve({
                json: () => Promise.resolve({
                    success: true,
                    autofill_token: 'af-token-' + calls.mints,
                    delegation_token: 'del-token-' + calls.mints,
                    signup_url: 'https://signup.example.test/',
                    country: country || 'GB'
                })
            });
        }
        if (target.includes('/autofill/v1/buyer/current')) {
            calls.buyerLookups += 1;
            return buyerAnswer();
        }
        if (target.includes('saveCompany')) {
            calls.saves.push(String((options && options.body) || ''));
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;

    return calls;
}

function buyerFound() {
    return Promise.resolve({ ok: true, json: () => Promise.resolve(registeredBuyer()) });
}

function noRegistration() {
    return Promise.resolve({ ok: false, status: 404 });
}

/**
 * The checkout's real billing-country control, which is what the module reads
 * the country from - `config.billingCountry` is only its fallback.
 */
function selectCountry(iso) {
    let select = document.querySelector("select[name='id_country']");
    if (!select) {
        const holder = document.createElement('div');
        holder.innerHTML = "<select name='id_country'></select>";
        document.body.appendChild(holder);
        select = holder.querySelector('select');
    }
    select.innerHTML = '<option value="18" data-iso-code="' + iso + '" selected>Other</option>';
}

function promptShown() {
    const prompt = document.querySelector('.two-sole-trader__prompt');

    return !!prompt && prompt.style.display !== 'none';
}

beforeEach(() => {
    TwoSoleTrader = null;
    instances = [];
    settleListeners = [];
});

afterEach(() => {
    instances.forEach((instance) => instance.destroy());
    instances = [];
    settleListeners.forEach((handler) => document.removeEventListener('two:sole-trader-flight-settled', handler));
    settleListeners = [];
    delete global.fetch;
    delete global.window.fetch;
    delete global.window.open;
    delete global.window.TwoCheckoutManager_Instance;
    document.body.innerHTML = '';
    global.window.localStorage.clear();
});

describe('on the payment step, where the on-page prompt exists', () => {
    beforeEach(() => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        buildEmailField(BUYER_EMAIL);
        TwoSoleTrader = loadSoleTrader();
    });

    test('the answer is fetched at mount, alongside the mint, and held', async () => {
        const calls = stubFetch(buyerFound);

        const instance = build();
        await flushPromises();

        expect(calls.mints).toBe(1);
        expect(calls.buyerLookups).toBe(1);
        expect(instance.heldBuyerResult()).toEqual({ buyer: registeredBuyer() });
    });

    test('a held matching buyer is applied inside the click, with no popup', async () => {
        const calls = stubFetch(buyerFound);
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();

        instance.startEnrollment();

        // Asserted with no flush in between: the write-back is issued from the
        // click's own call stack, off the answer already held.
        expect(calls.saves).toHaveLength(1);
        expect(calls.saves[0]).toContain('companyid=TWO%3A123456');
        expect(calls.buyerLookups).toBe(1);
        expect(openSpy).not.toHaveBeenCalled();

        await flushPromises();
        expect(openSpy).not.toHaveBeenCalled();
    });

    test('a held "none" shows the prompt inside the click, and runs no lookup', async () => {
        const calls = stubFetch(noRegistration);
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();
        expect(instance.heldBuyerResult()).toEqual({ buyer: null });

        instance.startEnrollment();

        expect(promptShown()).toBe(true);
        expect(calls.buyerLookups).toBe(1);
        expect(calls.saves).toHaveLength(0);

        await flushPromises();
        expect(openSpy).not.toHaveBeenCalled();
    });

    test('a held answer persists across a country change and is still applied', async () => {
        const calls = stubFetch(buyerFound, 'GB');

        const instance = build();
        await flushPromises();
        expect(instance.heldBuyerResult()).not.toBeNull();

        // The buyer identity behind the session cookie has nothing to do
        // with which country the checkout form now shows.
        instance.config.billingCountry = 'SE';
        instance.availabilityByCountry.SE = true;
        expect(instance.heldBuyerResult()).toEqual({ buyer: registeredBuyer() });

        instance.startEnrollment();

        expect(calls.saves).toHaveLength(1);
    });

    test('an answer landing after a country change is still held and usable', async () => {
        let resolveLookup;
        stubFetch(() => new Promise((resolve) => { resolveLookup = resolve; }));

        const instance = build();
        await flushPromises();
        expect(instance.isPrefetchingBuyer).toBe(true);

        instance.config.billingCountry = 'SE';
        instance.availabilityByCountry.SE = true;
        instance.apply('SE', true);
        await flushPromises();

        resolveLookup({ ok: true, json: () => Promise.resolve(registeredBuyer()) });
        await flushPromises();

        expect(instance._heldBuyer).toEqual({ buyer: registeredBuyer() });
        expect(instance.heldBuyerResult()).toEqual({ buyer: registeredBuyer() });
    });

    test('a repeated availability resolution for the same country does not look up again', async () => {
        const calls = stubFetch(noRegistration);

        const instance = build();
        await flushPromises();
        expect(calls.buyerLookups).toBe(1);

        instance.apply('GB', true);
        instance.apply('GB', true);
        await flushPromises();

        expect(calls.buyerLookups).toBe(1);
    });

    test('a failed prefetch is retried after a cooldown, not on every resolution', async () => {
        jest.useFakeTimers();
        try {
            let lookups = 0;
            const calls = stubFetch(() => {
                lookups += 1;
                return lookups === 1 ? Promise.reject(new Error('autofill unreachable')) : noRegistration();
            });

            const instance = build();
            await flushPromises();
            expect(calls.buyerLookups).toBe(1);
            expect(instance.heldBuyerResult()).toBeNull();

            // A page with no enrolment container re-applies availability on
            // every DOM mutation burst, so an immediate retry is unbounded.
            instance.apply('GB', true);
            instance.apply('GB', true);
            await flushPromises();
            expect(calls.buyerLookups).toBe(1);

            jest.advanceTimersByTime(instance.retryCooldownMs + 1);
            instance.apply('GB', true);
            await flushPromises();

            expect(calls.buyerLookups).toBe(2);
            expect(instance.heldBuyerResult()).toEqual({ buyer: null });
        } finally {
            jest.useRealTimers();
        }
    });

    test('the prompt click autofills an answer that landed since the chip click', async () => {
        let resolveLookup;
        const calls = stubFetch(() => new Promise((resolve) => { resolveLookup = resolve; }));
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();

        // The answer is still out, so the chip click can only offer the
        // prompt - and then it lands, with a registration.
        instance.startEnrollment();
        expect(promptShown()).toBe(true);
        resolveLookup({ ok: true, json: () => Promise.resolve(registeredBuyer()) });
        await flushPromises();

        document.querySelector('.two-sole-trader__prompt').click();

        // Autofilled from the click's own call stack: a buyer who IS registered
        // must not be pushed into hosted signup.
        expect(calls.saves).toHaveLength(1);
        expect(openSpy).not.toHaveBeenCalled();
    });

    test('two prompt clicks write the enrolment back once', async () => {
        let resolveLookup;
        const calls = stubFetch(() => new Promise((resolve) => { resolveLookup = resolve; }));
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();

        instance.startEnrollment();
        resolveLookup({ ok: true, json: () => Promise.resolve(registeredBuyer()) });
        await flushPromises();

        const prompt = document.querySelector('.two-sole-trader__prompt');
        prompt.click();
        prompt.click();

        expect(calls.saves).toHaveLength(1);
        expect(openSpy).not.toHaveBeenCalled();
    });

    test('a click whose lookup was declined by a country change still gets no re-mint once it settles', async () => {
        let lookups = 0;
        let resolveLookup;
        const calls = stubFetch(() => {
            lookups += 1;
            // The mount's prefetch fails, so the click runs its own lookup and
            // holds isFetchingBuyer while the country changes under it.
            if (lookups === 1) {
                return Promise.reject(new Error('autofill unreachable'));
            }
            return new Promise((resolve) => { resolveLookup = resolve; });
        });

        const instance = build();
        await flushPromises();

        instance.startEnrollment();
        await flushPromises();
        expect(instance.isFetchingBuyer).toBe(true);
        expect(calls.mints).toBe(1);

        selectCountry('SE');
        instance.availabilityByCountry.SE = true;
        instance.apply('SE', true);
        await flushPromises();
        expect(calls.mints).toBe(1);

        resolveLookup({ ok: false, status: 404 });
        await flushPromises();
        await flushPromises();

        // The token is not country-specific - held tokens are never
        // re-minted just because the buyer moved country.
        expect(calls.mints).toBe(1);
    });

    test('walking away from an open popup re-fetches the answer it dropped', async () => {
        const calls = stubFetch(noRegistration);
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();

        instance.startEnrollment();
        document.querySelector('.two-sole-trader__prompt').click();
        expect(openSpy).toHaveBeenCalledTimes(1);
        expect(instance.heldBuyerResult()).toBeNull();

        // Reopening company search abandons the enrolment and disowns the
        // popup - taking its close poll, the only other re-fetch trigger.
        instance.cancelEnrollment();
        await flushPromises();

        expect(calls.buyerLookups).toBe(2);
        expect(instance.heldBuyerResult()).toEqual({ buyer: null });

        // So the next click is synchronous again.
        instance.startEnrollment();
        expect(promptShown()).toBe(true);
        expect(calls.buyerLookups).toBe(2);
    });

    test('the next click after a popup closes is synchronous too', async () => {
        // Fake timers from the start: the popup-close poll is a real
        // setInterval, so it has to be armed under them to be advanceable.
        jest.useFakeTimers();
        try {
            const popup = { closed: false };
            const calls = stubFetch(noRegistration);
            const openSpy = jest.fn(() => popup);
            global.window.open = openSpy;

            const instance = build();
            await flushPromises();

            instance.startEnrollment();
            document.querySelector('.two-sole-trader__prompt').click();
            expect(openSpy).toHaveBeenCalledTimes(1);
            // Opening the popup drops the answer it falsified.
            expect(instance.heldBuyerResult()).toBeNull();

            // The buyer closes it without registering.
            popup.closed = true;
            jest.advanceTimersByTime(600);
            await flushPromises();

            // A fresh answer is fetched off the close, so the SECOND click
            // decides synchronously rather than chaining a lookup into
            // window.open().
            expect(calls.buyerLookups).toBe(2);
            expect(instance.heldBuyerResult()).toEqual({ buyer: null });

            instance.startEnrollment();
            expect(promptShown()).toBe(true);
            expect(calls.buyerLookups).toBe(2);
        } finally {
            jest.useRealTimers();
        }
    });

    test('the authenticated answer outranks a pre-click one still in flight', async () => {
        let lookups = 0;
        let resolvePrefetch;
        let resolveTrusted;
        const calls = stubFetch(() => {
            lookups += 1;
            if (lookups === 1) {
                return noRegistration();
            }
            if (lookups === 2) {
                return new Promise((resolve) => { resolvePrefetch = resolve; });
            }
            return new Promise((resolve) => { resolveTrusted = resolve; });
        });
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();

        instance.startEnrollment();
        document.querySelector('.two-sole-trader__prompt').click();
        expect(openSpy).toHaveBeenCalledTimes(1);

        // Reopening search disowns that still-visible popup and re-arms the
        // pre-click lookup; the buyer then comes back to the chip and
        // completes the signup in the window that is still open.
        instance.cancelEnrollment();
        await flushPromises();
        expect(calls.buyerLookups).toBe(2);
        instance.startEnrollment();
        window.dispatchEvent(new window.MessageEvent('message', {
            data: 'ACCEPTED',
            origin: 'https://signup.example.test'
        }));
        await flushPromises();
        expect(calls.buyerLookups).toBe(3);

        // The pre-click lookup answers first, from before the signup.
        resolvePrefetch({ ok: false, status: 404 });
        await flushPromises();
        resolveTrusted({ ok: true, json: () => Promise.resolve(registeredBuyer()) });
        await flushPromises();

        // The authenticated answer is the one held, so the next click
        // autofills instead of reopening signup for a registered buyer.
        expect(instance.heldBuyerResult().buyer).toEqual(registeredBuyer());
        instance.cancelEnrollment();
        instance.startEnrollment();
        expect(openSpy).toHaveBeenCalledTimes(1);
    });

    test('a held answer stays readable even if the tokens later echo a different country', async () => {
        stubFetch(buyerFound);

        const instance = build();
        await flushPromises();
        expect(instance.heldBuyerResult()).not.toBeNull();

        // The server resolves the mint against the cart, so it can echo a
        // country the posted one did not name - the held answer is not
        // scoped by country at all, so this has no bearing on it.
        instance.tokens = Object.assign({}, instance.tokens, { country: 'SE' });

        expect(instance.heldBuyerResult()).toEqual({ buyer: registeredBuyer() });
    });

    test('the read-after-write retry is not shadowed by a second lookup', async () => {
        jest.useFakeTimers();
        try {
            let lookups = 0;
            const calls = stubFetch(() => {
                lookups += 1;
                // The mount's prefetch and the authenticated lookup both see
                // the registration as not yet visible; the retry finds it.
                return lookups < 3 ? noRegistration() : buyerFound();
            });
            const popup = { closed: false };
            global.window.open = jest.fn(() => popup);

            const instance = build();
            await flushPromises();

            instance.startEnrollment();
            document.querySelector('.two-sole-trader__prompt').click();
            window.dispatchEvent(new window.MessageEvent('message', {
                data: 'ACCEPTED',
                origin: 'https://signup.example.test'
            }));
            await flushPromises();
            expect(calls.buyerLookups).toBe(2);

            // The hosted flow closes its own window the moment it has posted,
            // so the popup handle is gone before the retry fires - and with it
            // the guard that would otherwise decline a pre-click lookup.
            popup.closed = true;
            jest.advanceTimersByTime(500);
            await flushPromises();
            expect(calls.buyerLookups).toBe(2);

            jest.advanceTimersByTime(400);
            await flushPromises();

            // The retry, and nothing alongside it.
            expect(calls.buyerLookups).toBe(3);
            expect(calls.saves).toHaveLength(1);
        } finally {
            jest.useRealTimers();
        }
    });

    test('abandoning mid-lookup still re-fetches the answer the popup dropped', async () => {
        let lookups = 0;
        let resolveTrusted;
        const calls = stubFetch(() => {
            lookups += 1;
            if (lookups === 2) {
                return new Promise((resolve) => { resolveTrusted = resolve; });
            }
            return noRegistration();
        });
        global.window.open = jest.fn(() => ({ closed: false }));

        const instance = build();
        await flushPromises();

        instance.startEnrollment();
        document.querySelector('.two-sole-trader__prompt').click();
        window.dispatchEvent(new window.MessageEvent('message', {
            data: 'ACCEPTED',
            origin: 'https://signup.example.test'
        }));
        await flushPromises();
        expect(calls.buyerLookups).toBe(2);

        // Reopening company search while the authenticated lookup is out: its
        // own re-fetch is declined by that lookup's guard, and the lookup then
        // lands superseded with nothing to resume.
        instance.cancelEnrollment();
        resolveTrusted({ ok: false, status: 404 });
        await flushPromises();
        await flushPromises();

        expect(calls.buyerLookups).toBe(3);
        expect(instance.heldBuyerResult()).toEqual({ buyer: null });
    });

    test('abandoning inside the read-after-write wait still re-fetches the answer', async () => {
        jest.useFakeTimers();
        try {
            const calls = stubFetch(noRegistration);
            global.window.open = jest.fn(() => ({ closed: false }));

            const instance = build();
            await flushPromises();

            instance.startEnrollment();
            document.querySelector('.two-sole-trader__prompt').click();
            window.dispatchEvent(new window.MessageEvent('message', {
                data: 'ACCEPTED',
                origin: 'https://signup.example.test'
            }));
            await flushPromises();
            expect(calls.buyerLookups).toBe(2);

            // The buyer walks away during the 800ms read-after-write wait, so
            // the retry that settle() stood aside for never happens.
            instance.cancelEnrollment();
            jest.advanceTimersByTime(900);
            await flushPromises();

            expect(calls.buyerLookups).toBe(3);
            expect(instance.heldBuyerResult()).toEqual({ buyer: null });
        } finally {
            jest.useRealTimers();
        }
    });

    test('a resume that no longer has a flow to resume re-fetches the answer', async () => {
        jest.useFakeTimers();
        try {
            let lookups = 0;
            let resolveClickLookup;
            const calls = stubFetch(() => {
                lookups += 1;
                if (lookups === 1) {
                    return Promise.reject(new Error('autofill unreachable'));
                }
                if (lookups === 2) {
                    return new Promise((resolve) => { resolveClickLookup = resolve; });
                }
                return noRegistration();
            });
            global.window.open = jest.fn(() => ({ closed: false }));

            const instance = build();
            await flushPromises();
            expect(calls.buyerLookups).toBe(1);
            jest.advanceTimersByTime(instance.retryCooldownMs + 1);

            // Nothing held, so this click runs its own lookup, and is then
            // abandoned and resumed while it is still out - so the lookup
            // lands superseded with a resume queued behind it.
            instance.startEnrollment();
            await flushPromises();
            expect(calls.buyerLookups).toBe(2);
            instance.cancelEnrollment();
            instance.startEnrollment();

            resolveClickLookup({ ok: false, status: 404 });
            await flushPromises();
            expect(calls.buyerLookups).toBe(2);

            // Another lookup settles the flow before that resume runs - what
            // applyBuyer() does on a successful adoption - so the resume finds
            // nothing to resume and has to recover the eager work itself.
            instance.enrolling = false;
            jest.advanceTimersByTime(1);
            await flushPromises();

            expect(calls.buyerLookups).toBe(3);
            expect(instance.heldBuyerResult()).toEqual({ buyer: null });
        } finally {
            jest.useRealTimers();
        }
    });

    test('a lookup outstanding at destroy() acts on nothing', async () => {
        let resolveLookup;
        let lookups = 0;
        const calls = stubFetch(() => {
            lookups += 1;
            // The mount's prefetch fails, so the click runs its own lookup.
            if (lookups === 1) {
                return Promise.reject(new Error('autofill unreachable'));
            }
            return new Promise((resolve) => { resolveLookup = resolve; });
        });
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();

        instance.startEnrollment();
        await flushPromises();
        expect(instance.isFetchingBuyer).toBe(true);

        instance.destroy();
        resolveLookup({ ok: false, status: 404 });
        await flushPromises();
        await flushPromises();

        expect(openSpy).not.toHaveBeenCalled();
        expect(calls.saves).toHaveLength(0);
        expect(instance.heldBuyerResult()).toBeNull();
    });

    test('a popup closing while the prefetch it falsified is out still re-arms', async () => {
        jest.useFakeTimers();
        try {
            const popup = { closed: false };
            let lookups = 0;
            let resolveFirstLookup;
            const calls = stubFetch(() => {
                lookups += 1;
                if (lookups === 1) {
                    return new Promise((resolve) => { resolveFirstLookup = resolve; });
                }
                return noRegistration();
            });
            const openSpy = jest.fn(() => popup);
            global.window.open = openSpy;

            const instance = build();
            await flushPromises();
            expect(instance.isPrefetchingBuyer).toBe(true);

            // The click rides "none" while that first lookup is still out, and
            // the popup the prompt opens disowns it.
            instance.startEnrollment();
            document.querySelector('.two-sole-trader__prompt').click();
            expect(openSpy).toHaveBeenCalledTimes(1);

            // The popup closes BEFORE the disowned lookup lands, so the close
            // trigger is pre-empted by the prefetch's own guard.
            popup.closed = true;
            jest.advanceTimersByTime(600);
            await flushPromises();
            expect(calls.buyerLookups).toBe(1);

            resolveFirstLookup({ ok: true, json: () => Promise.resolve(registeredBuyer()) });
            await flushPromises();

            // Releasing that guard is the last chance to re-arm, so it has to.
            expect(calls.buyerLookups).toBe(2);
            expect(instance.heldBuyerResult()).toEqual({ buyer: null });
        } finally {
            jest.useRealTimers();
        }
    });

    test('a disowned prefetch failure does not put the live state in a cooldown', async () => {
        jest.useFakeTimers();
        try {
            const popup = { closed: false };
            let lookups = 0;
            let failFirstLookup;
            const calls = stubFetch(() => {
                lookups += 1;
                if (lookups === 1) {
                    return new Promise((resolve, reject) => {
                        failFirstLookup = () => reject(new Error('autofill unreachable'));
                    });
                }
                return noRegistration();
            });
            global.window.open = jest.fn(() => popup);

            const instance = build();
            await flushPromises();

            instance.startEnrollment();
            document.querySelector('.two-sole-trader__prompt').click();
            failFirstLookup();
            await flushPromises();

            // The failure belongs to the answer the popup already disowned, so
            // the fresh state it left behind is not in a cooldown.
            expect(instance._buyerPrefetchRetryAt).toBe(0);

            popup.closed = true;
            jest.advanceTimersByTime(600);
            await flushPromises();

            expect(calls.buyerLookups).toBe(2);
            expect(instance.heldBuyerResult()).toEqual({ buyer: null });
        } finally {
            jest.useRealTimers();
        }
    });

    test('walking away twice still re-fetches the answer', async () => {
        const calls = stubFetch(noRegistration);
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();

        // The buyer abandons the flow, then takes the prompt anyway - so the
        // popup opens with the enrolment already abandoned.
        instance.startEnrollment();
        instance.cancelEnrollment();
        await flushPromises();
        const lookupsBeforePopup = calls.buyerLookups;
        document.querySelector('.two-sole-trader__prompt').click();
        expect(openSpy).toHaveBeenCalledTimes(1);
        expect(instance.heldBuyerResult()).toBeNull();

        // Reopening search disowns that popup - and its close poll - from the
        // already-abandoned path.
        instance.cancelEnrollment();
        await flushPromises();

        expect(calls.buyerLookups).toBe(lookupsBeforePopup + 1);
        expect(instance.heldBuyerResult()).toEqual({ buyer: null });
    });

    test('a popup the buyer completed costs no second lookup when it closes', async () => {
        jest.useFakeTimers();
        try {
            const popup = { closed: false };
            let lookups = 0;
            const calls = stubFetch(() => {
                lookups += 1;
                return lookups === 1 ? noRegistration() : buyerFound();
            });
            global.window.open = jest.fn(() => popup);

            const instance = build();
            await flushPromises();

            instance.startEnrollment();
            document.querySelector('.two-sole-trader__prompt').click();

            // The buyer registers; the popup's completion message drives the
            // authenticated lookup, which answers before the window closes.
            window.dispatchEvent(new window.MessageEvent('message', {
                data: 'ACCEPTED',
                origin: 'https://signup.example.test'
            }));
            await flushPromises();
            expect(calls.buyerLookups).toBe(2);
            expect(instance.heldBuyerResult().buyer).toEqual(registeredBuyer());

            popup.closed = true;
            jest.advanceTimersByTime(600);
            await flushPromises();

            // Nothing left to ask: that answer is already held.
            expect(calls.buyerLookups).toBe(2);
        } finally {
            jest.useRealTimers();
        }
    });

    test('an authenticated lookup lands a held answer usable regardless of a later country change', async () => {
        let lookups = 0;
        const calls = stubFetch(() => {
            lookups += 1;
            return lookups === 1 ? noRegistration() : buyerFound();
        });
        global.window.open = jest.fn(() => ({ closed: false }));

        const instance = build();
        await flushPromises();

        instance.startEnrollment();
        document.querySelector('.two-sole-trader__prompt').click();

        // The buyer edits the country while the popup is up - the tokens
        // authorising the popup, and the lookup its completion drives, do
        // not change with it.
        selectCountry('SE');
        expect(instance.billingCountry()).toBe('SE');
        expect(instance.tokens.country).toBe('GB');

        window.dispatchEvent(new window.MessageEvent('message', {
            data: 'ACCEPTED',
            origin: 'https://signup.example.test'
        }));
        await flushPromises();

        expect(calls.buyerLookups).toBe(2);
        expect(instance.heldBuyerResult()).toEqual({ buyer: registeredBuyer() });
    });

    test('a lookup answering after a country change is still held and usable', async () => {
        let lookups = 0;
        let resolveLookup;
        stubFetch(() => {
            lookups += 1;
            // The mount's prefetch fails, so the click runs its own lookup -
            // the one that is still out when the country changes.
            if (lookups === 1) {
                return Promise.reject(new Error('autofill unreachable'));
            }
            return new Promise((resolve) => { resolveLookup = resolve; });
        });

        const instance = build();
        await flushPromises();

        instance.startEnrollment();
        await flushPromises();
        expect(instance.isFetchingBuyer).toBe(true);

        // Another eligible country: a real change, which bumps no generation
        // of its own until the flow notices it.
        instance.config.billingCountry = 'SE';
        instance.availabilityByCountry.SE = true;

        resolveLookup({ ok: true, json: () => Promise.resolve(registeredBuyer()) });
        await flushPromises();

        expect(instance._heldBuyer).toEqual({ buyer: registeredBuyer() });
        expect(instance.heldBuyerResult()).toEqual({ buyer: registeredBuyer() });
    });

    test('a held "none" does not outlive a signup popup the buyer completed', async () => {
        let lookups = 0;
        const calls = stubFetch(() => {
            lookups += 1;
            return lookups === 1 ? noRegistration() : buyerFound();
        });
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();
        expect(instance.heldBuyerResult()).toEqual({ buyer: null });

        // The click shows the prompt off that answer; the buyer takes it, and
        // the popup opens.
        instance.startEnrollment();
        expect(promptShown()).toBe(true);
        document.querySelector('.two-sole-trader__prompt').click();
        expect(openSpy).toHaveBeenCalledTimes(1);

        // The buyer registers, and the flow is abandoned while the popup is
        // still open - the resumable case, where the completion message is
        // dropped on the generation check.
        instance.cancelEnrollment();
        window.dispatchEvent(new window.MessageEvent('message', {
            data: 'ACCEPTED',
            origin: 'https://signup.example.test'
        }));
        await flushPromises();
        expect(calls.saves).toHaveLength(0);

        await flushPromises();
        // Two re-fetches, one per event that falsified the answer: walking
        // away from the popup, and the completion this attempt did not act on.
        expect(calls.buyerLookups).toBe(3);
        expect(instance.heldBuyerResult().buyer).toEqual(registeredBuyer());

        // A repeated message is not a second completion, so it cannot re-ask
        // on loop.
        window.dispatchEvent(new window.MessageEvent('message', {
            data: 'ACCEPTED',
            origin: 'https://signup.example.test'
        }));
        await flushPromises();
        expect(calls.buyerLookups).toBe(3);

        // Resuming must not re-prompt a buyer who is now registered - the
        // answer the click decides from is the post-signup one.
        instance.startEnrollment();

        expect(calls.saves).toHaveLength(1);
        expect(calls.buyerLookups).toBe(3);
    });

    test('"select a different sole trader" pops up regardless of the held answer', async () => {
        const calls = stubFetch(buyerFound);
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();
        expect(instance.heldBuyerResult().buyer).toEqual(registeredBuyer());

        instance.startReplacement();

        expect(openSpy).toHaveBeenCalledTimes(1);
        expect(String(openSpy.mock.calls[0][0])).toContain('autoselect=false');
        expect(calls.saves).toHaveLength(0);
        expect(calls.buyerLookups).toBe(1);
    });
});

describe('on the address-editor page, where there is no prompt to show', () => {
    beforeEach(() => {
        buildAddressForm();
        buildEmailField(BUYER_EMAIL);
        TwoSoleTrader = loadSoleTrader();
    });

    test('a held "none" opens the signup popup inside the click', async () => {
        const calls = stubFetch(noRegistration);
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();
        await flushPromises();
        expect(instance.heldBuyerResult()).toEqual({ buyer: null });

        instance.startEnrollment();

        // The whole point: no promise tick between the gesture and the popup.
        expect(openSpy).toHaveBeenCalledTimes(1);
        expect(calls.buyerLookups).toBe(1);
    });

    test('an answer still in flight is treated as "none" rather than blocking the click', async () => {
        let resolveLookup;
        const calls = stubFetch(() => new Promise((resolve) => { resolveLookup = resolve; }));
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();
        expect(instance.isPrefetchingBuyer).toBe(true);
        expect(instance.heldBuyerResult()).toBeNull();

        instance.startEnrollment();

        expect(openSpy).toHaveBeenCalledTimes(1);
        expect(calls.buyerLookups).toBe(1);

        // The answer landing afterwards describes a registration the buyer is
        // in the middle of changing, so it is dropped rather than held - and
        // it neither autofills over nor re-pops on top of the open popup.
        resolveLookup({ ok: true, json: () => Promise.resolve(registeredBuyer()) });
        await flushPromises();

        expect(instance.heldBuyerResult()).toBeNull();
        expect(openSpy).toHaveBeenCalledTimes(1);
        expect(calls.saves).toHaveLength(0);
    });

    test('an answer a real click already acted on is not overwritten by a late prefetch', async () => {
        let lookups = 0;
        let resolvePrefetch;
        stubFetch(() => {
            lookups += 1;
            if (lookups === 1) {
                return new Promise((resolve) => { resolvePrefetch = resolve; });
            }
            return buyerFound();
        });
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();
        await flushPromises();

        // The click rides out on "none" and pops the signup window; the buyer
        // completes it, and THAT lookup is the authoritative answer.
        instance.startEnrollment();
        expect(openSpy).toHaveBeenCalledTimes(1);
        window.dispatchEvent(new window.MessageEvent('message', {
            data: 'ACCEPTED',
            origin: 'https://signup.example.test'
        }));
        await flushPromises();
        await flushPromises();
        expect(instance.heldBuyerResult().buyer).toEqual(registeredBuyer());

        resolvePrefetch({ ok: false, status: 404 });
        await flushPromises();

        expect(instance.heldBuyerResult().buyer).toEqual(registeredBuyer());
    });

    test('a country change mid-mint holds the tokens once they land - no second mint for the new country', async () => {
        let mintCalls = 0;
        let resolveFirstMint;
        const calls = { mints: [], buyerLookups: 0 };
        global.window.fetch = (url, options) => {
            const target = String(url);
            if (target.includes('soleTraderAvailability')) {
                return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
            }
            if (target.includes('soleTraderTokens')) {
                mintCalls += 1;
                calls.mints.push(String((options && options.body) || ''));
                const payload = {
                    success: true,
                    autofill_token: 'af-token-' + mintCalls,
                    delegation_token: 'del-token-' + mintCalls,
                    signup_url: 'https://signup.example.test/',
                    country: 'GB'
                };
                return new Promise((resolve) => {
                    resolveFirstMint = () => resolve({ json: () => Promise.resolve(payload) });
                });
            }
            if (target.includes('/autofill/v1/buyer/current')) {
                calls.buyerLookups += 1;
                return buyerFound();
            }
            return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
        };
        global.fetch = global.window.fetch;

        const instance = build();
        await flushPromises();
        expect(mintCalls).toBe(1);

        // The country changes while that first mint is still out.
        selectCountry('SE');
        instance.availabilityByCountry.SE = true;
        instance.apply('SE', true);
        await flushPromises();
        expect(mintCalls).toBe(1);

        resolveFirstMint();
        await flushPromises();
        await flushPromises();

        // The token is not country-specific - once held, no second mint
        // follows just because the buyer is now in a different country.
        expect(mintCalls).toBe(1);
        expect(instance.tokens.country).toBe('GB');
    });

    test('a failed prefetch leaves the click to run its own lookup', async () => {
        let lookups = 0;
        const calls = stubFetch(() => {
            lookups += 1;
            return lookups === 1
                ? Promise.reject(new Error('autofill unreachable'))
                : buyerFound();
        });
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();
        await flushPromises();
        expect(instance.heldBuyerResult()).toBeNull();
        expect(calls.buyerLookups).toBe(1);

        instance.startEnrollment();
        await flushPromises();

        expect(calls.buyerLookups).toBe(2);
        expect(calls.saves).toHaveLength(1);
        expect(openSpy).not.toHaveBeenCalled();
    });
});
