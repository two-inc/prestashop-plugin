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
});

afterEach(() => {
    instances.forEach((instance) => instance.destroy());
    instances = [];
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
        expect(instance.heldBuyerResult()).toEqual({ country: 'GB', buyer: registeredBuyer() });
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
        expect(instance.heldBuyerResult()).toEqual({ country: 'GB', buyer: null });

        instance.startEnrollment();

        expect(promptShown()).toBe(true);
        expect(calls.buyerLookups).toBe(1);
        expect(calls.saves).toHaveLength(0);

        await flushPromises();
        expect(openSpy).not.toHaveBeenCalled();
    });

    test('a held answer for a country the buyer has left is not applied', async () => {
        const calls = stubFetch(buyerFound, 'GB');

        const instance = build();
        await flushPromises();
        expect(instance.heldBuyerResult()).not.toBeNull();

        // The mint for the new country is still out when the click lands, so
        // there is no held answer and no tokens to decide from either.
        instance.config.billingCountry = 'SE';
        instance.availabilityByCountry.SE = true;
        expect(instance.heldBuyerResult()).toBeNull();

        instance.startEnrollment();

        expect(calls.saves).toHaveLength(0);
    });

    test('an answer landing after a country change is held for the country it was authorised for', async () => {
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

        expect(instance._heldBuyer).toEqual({ country: 'GB', buyer: registeredBuyer() });
        expect(instance.heldBuyerResult()).toBeNull();
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

    test('a failed prefetch is retried on a later availability resolution', async () => {
        let lookups = 0;
        const calls = stubFetch(() => {
            lookups += 1;
            return lookups === 1 ? Promise.reject(new Error('autofill unreachable')) : noRegistration();
        });

        const instance = build();
        await flushPromises();
        expect(calls.buyerLookups).toBe(1);
        expect(instance.heldBuyerResult()).toBeNull();

        instance.apply('GB', true);
        await flushPromises();

        expect(calls.buyerLookups).toBe(2);
        expect(instance.heldBuyerResult()).toEqual({ country: 'GB', buyer: null });
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
            expect(instance.heldBuyerResult()).toEqual({ country: 'GB', buyer: null });

            instance.startEnrollment();
            expect(promptShown()).toBe(true);
            expect(calls.buyerLookups).toBe(2);
        } finally {
            jest.useRealTimers();
        }
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

    test('an authenticated lookup files its answer under the tokens it was authorised by', async () => {
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

        // The buyer edits the country while the popup is up. The tokens the
        // popup - and the lookup its completion drives - are authorised by
        // are still the GB pair.
        selectCountry('SE');
        expect(instance.billingCountry()).toBe('SE');
        expect(instance.tokens.country).toBe('GB');

        window.dispatchEvent(new window.MessageEvent('message', {
            data: 'ACCEPTED',
            origin: 'https://signup.example.test'
        }));
        await flushPromises();

        expect(calls.buyerLookups).toBe(2);
        expect(instance._heldBuyer.country).toBe('GB');
        expect(instance.heldBuyerResult()).toBeNull();
    });

    test('a lookup answering after a country change is not filed under the new country', async () => {
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

        expect(instance._heldBuyer.country).toBe('GB');
        expect(instance.heldBuyerResult()).toBeNull();
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
        expect(instance.heldBuyerResult()).toEqual({ country: 'GB', buyer: null });

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

        // Resuming must not re-prompt a buyer who is now registered: the held
        // "none" the popup falsified is gone, so this click looks again.
        instance.startEnrollment();
        await flushPromises();
        await flushPromises();

        expect(calls.buyerLookups).toBe(2);
        expect(calls.saves).toHaveLength(1);
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
        expect(instance.heldBuyerResult()).toEqual({ country: 'GB', buyer: null });

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

    test('a country change mid-mint still gets its own eager mint and answer', async () => {
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
                    country: mintCalls === 1 ? 'GB' : 'SE'
                };
                if (mintCalls === 1) {
                    return new Promise((resolve) => {
                        resolveFirstMint = () => resolve({ json: () => Promise.resolve(payload) });
                    });
                }
                return Promise.resolve({ json: () => Promise.resolve(payload) });
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

        // The country changes while that first mint is still out, so its own
        // eager mint is declined by the single-mint guard.
        selectCountry('SE');
        instance.availabilityByCountry.SE = true;
        instance.apply('SE', true);
        await flushPromises();
        expect(mintCalls).toBe(1);

        resolveFirstMint();
        await flushPromises();
        await flushPromises();
        // Nothing else would ever trigger it - the availability answer for SE
        // is already settled - so the mint that landed has to re-try it.
        expect(mintCalls).toBe(2);
        expect(calls.mints[1]).toContain('country=SE');
        expect(instance.heldBuyerResult()).toEqual({ country: 'SE', buyer: registeredBuyer() });
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
