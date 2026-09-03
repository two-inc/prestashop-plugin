/**
 * TWO-40 follow-up, Doug: the delegated auth tokens the sole-trader flow signs
 * against are minted when the component mounts and an eligible billing country
 * resolves, not on the buyer's first "I'm a sole trader" click. By the time
 * that click happens a token pair is already minted and refreshing.
 *
 * The autofill answer those tokens fetch, and the click that decides from it,
 * are covered by sole-trader-eager-autofill.test.js; the 30-minute refresh
 * cadence by sole-trader-token-refresh.test.js.
 */

'use strict';

const {
    loadSoleTrader,
    buildPaymentTile,
    buildPaymentTileWithSoleTraderAnswer,
    flushPromises
} = require('./ps-harness');

let TwoSoleTrader;
let instances;

/**
 * Every instance is torn down in afterEach, not only on the happy path: an
 * instance left alive keeps a `message` listener and a refresh interval bound,
 * and would answer a later test's dispatched events using its own state.
 */
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

function tokenPayload(suffix, country) {
    return {
        success: true,
        autofill_token: 'af-token-' + suffix,
        delegation_token: 'del-token-' + suffix,
        signup_url: 'https://signup.example.test/',
        country: country || 'GB'
    };
}

/**
 * Records every mint and buyer lookup, routing mints through `mintHandler` so
 * a test can hand back a fresh payload, a pending Promise or a failure per
 * call. `availability.value` is what the network availability answer says, for
 * the tests that resolve it that way rather than from server-rendered markup.
 */
function stubFetch(mintHandler, availability) {
    const calls = { mints: [], buyerLookups: 0, availability: 0 };
    global.window.fetch = (url, options) => {
        const target = String(url);
        if (target.includes('soleTraderAvailability')) {
            calls.availability += 1;
            if (availability && availability.fail) {
                return Promise.reject(new Error('availability unreachable'));
            }
            return Promise.resolve({
                json: () => Promise.resolve({
                    success: true,
                    available: !availability || availability.value !== false
                })
            });
        }
        if (target.includes('soleTraderTokens')) {
            calls.mints.push(String((options && options.body) || ''));
            return mintHandler();
        }
        if (target.includes('/autofill/v1/buyer/current')) {
            calls.buyerLookups += 1;
            if (availability && availability.lookup) {
                return availability.lookup();
            }
            return Promise.resolve({ ok: false, status: 404 });
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;

    return calls;
}

/** A mint handler that always succeeds, numbering each payload in call order. */
function mintsSucceed(state) {
    return () => {
        state.calls += 1;
        return Promise.resolve({ json: () => Promise.resolve(tokenPayload(String(state.calls))) });
    };
}

/**
 * A real billing-country select, the way the checkout renders one: the module
 * reads the country from the selected option and re-resolves off a `change`
 * event on it, so a test that reassigns `config.billingCountry` proves the
 * branch it calls into but not the wiring that reaches it.
 */
function buildCountrySelect(iso) {
    const holder = document.createElement('div');
    holder.innerHTML = "<select name='id_country'>"
        + '<option value="17" data-iso-code="' + iso + '" selected>Country</option>'
        + '</select>';
    document.body.appendChild(holder);

    return holder.querySelector('select');
}

function selectCountry(select, iso) {
    select.innerHTML = '<option value="18" data-iso-code="' + iso + '" selected>Other</option>';
    select.dispatchEvent(new window.Event('change', { bubbles: true }));
}

function errorShown() {
    const error = document.querySelector('.two-sole-trader__error');

    return !!error && error.style.display === 'inline';
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

describe('a server-rendered eligible answer mints on mount', () => {
    beforeEach(() => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        TwoSoleTrader = loadSoleTrader();
    });

    test('the mint fires with no click, for the country the answer is about', async () => {
        const state = { calls: 0 };
        const calls = stubFetch(mintsSucceed(state));

        const instance = build();
        await flushPromises();

        expect(calls.mints).toHaveLength(1);
        expect(calls.mints[0]).toContain('country=GB');
        expect(instance.tokens.autofill_token).toBe('af-token-1');

        instance.destroy();
    });

    test('the refresh interval is armed by the same mount, not by a later click', async () => {
        jest.useFakeTimers();
        try {
            const state = { calls: 0 };
            const calls = stubFetch(mintsSucceed(state));

            const instance = build();
            await flushPromises();
            expect(calls.mints).toHaveLength(1);
            expect(instance._tokenRefreshIntervalId).not.toBeNull();

            // Still no click anywhere in this test - the cadence runs on its
            // own once mounted.
            jest.advanceTimersByTime(30 * 60 * 1000);
            await flushPromises();

            expect(calls.mints).toHaveLength(2);
            expect(instance.tokens.autofill_token).toBe('af-token-2');

            instance.destroy();
        } finally {
            jest.useRealTimers();
        }
    });

    test('the tokens are minted and the autofill answer prefetched, but neither acted on', async () => {
        const state = { calls: 0 };
        const calls = stubFetch(mintsSucceed(state));
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();
        await flushPromises();

        expect(calls.mints).toHaveLength(1);
        expect(calls.buyerLookups).toBe(1);
        expect(instance.heldBuyerResult()).toEqual({ country: 'GB', buyer: null });
        expect(openSpy).not.toHaveBeenCalled();
        expect(errorShown()).toBe(false);
        expect(instance.enrolling).toBe(false);

        instance.destroy();
    });

    test("the buyer's first click spends no mint of its own", async () => {
        const state = { calls: 0 };
        const calls = stubFetch(mintsSucceed(state));

        const instance = build();
        await flushPromises();
        expect(calls.mints).toHaveLength(1);

        instance.startEnrollment();
        await flushPromises();

        // The click rode the tokens the mount already minted.
        expect(calls.mints).toHaveLength(1);
        expect(calls.buyerLookups).toBe(1);

        instance.destroy();
    });

    test('a click landing while the eager mint is still in flight is served by that mint', async () => {
        let mintCalls = 0;
        let resolveMint;
        const calls = stubFetch(() => {
            mintCalls += 1;
            return new Promise((resolve) => { resolveMint = resolve; });
        });
        const settled = jest.fn();
        document.addEventListener('two:sole-trader-flight-settled', settled);

        const instance = build();
        expect(mintCalls).toBe(1);

        // The buyer clicks before the mount's own mint has come back.
        instance.startEnrollment();
        // No second mint - the click rides the one already out.
        expect(mintCalls).toBe(1);

        resolveMint({ json: () => Promise.resolve(tokenPayload('1')) });
        await flushPromises();
        await flushPromises();

        // Served: the lookup this click was waiting on ran, and the click's
        // spinner was told its round trip reached a terminal state.
        expect(calls.buyerLookups).toBe(1);
        expect(settled).toHaveBeenCalled();

        document.removeEventListener('two:sole-trader-flight-settled', settled);
        instance.destroy();
    });

    test('a failed eager mint stays silent and leaves the first click free to mint immediately', async () => {
        let mintCalls = 0;
        stubFetch(() => {
            mintCalls += 1;
            if (mintCalls === 1) {
                return Promise.reject(new Error('network down'));
            }
            return Promise.resolve({ json: () => Promise.resolve(tokenPayload('2')) });
        });

        const instance = build();
        await flushPromises();

        expect(mintCalls).toBe(1);
        expect(instance.tokens).toBeNull();
        // Nothing the buyer asked for failed, so nothing is on screen and the
        // retry cooldown a real click would respect was never started.
        expect(errorShown()).toBe(false);
        expect(instance.nextRetryAt).toBe(0);

        instance.startEnrollment();
        await flushPromises();

        expect(mintCalls).toBe(2);
        expect(instance.tokens.autofill_token).toBe('af-token-2');

        instance.destroy();
    });

    test('a click abandoned while its mint is in flight is not resumed when that mint lands', async () => {
        let mintCalls = 0;
        let resolveMint;
        const calls = stubFetch(() => {
            mintCalls += 1;
            return new Promise((resolve) => { resolveMint = resolve; });
        });
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        expect(mintCalls).toBe(1);

        instance.startEnrollment();
        // The buyer goes back to ordinary company search before the mint lands.
        instance.cancelEnrollment();

        resolveMint({ json: () => Promise.resolve(tokenPayload('1')) });
        await flushPromises();
        await flushPromises();

        // The tokens are kept for a later resume, and the autofill answer
        // prefetched off them, but neither acted on: the click that was
        // waiting has already been settled by the cancel.
        expect(instance.tokens.autofill_token).toBe('af-token-1');
        expect(calls.buyerLookups).toBe(1);
        expect(openSpy).not.toHaveBeenCalled();
        expect(errorShown()).toBe(false);

        instance.destroy();
    });

    test('a country change after a failed click does not resume that click', async () => {
        let mintCalls = 0;
        const calls = stubFetch(() => {
            mintCalls += 1;
            // The eager mint and the click both fail; the mint the country
            // change starts SUCCEEDS, so anything treating that click as still
            // waiting would act on it.
            if (mintCalls <= 2) {
                return Promise.reject(new Error('network down'));
            }
            return Promise.resolve({ json: () => Promise.resolve(tokenPayload(String(mintCalls), 'SE')) });
        });
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();
        expect(mintCalls).toBe(1);

        // The buyer's click fails too, and is settled by the error it shows.
        instance.startEnrollment();
        await flushPromises();
        expect(mintCalls).toBe(2);
        expect(errorShown()).toBe(true);

        // `enrolling` is still true here, so a country change must not be read
        // as that click still waiting: it never re-enters the enrolment flow.
        instance.nextRetryAt = 0;
        instance.config.billingCountry = 'SE';
        instance.availabilityByCountry.SE = true;
        instance.apply('SE', true);
        await flushPromises();

        expect(mintCalls).toBe(3);
        // The prefetch off the new country's tokens, and nothing else - that
        // click is not resumed, so no popup and no lookup of its own.
        expect(calls.buyerLookups).toBe(1);
        expect(openSpy).not.toHaveBeenCalled();

        instance.destroy();
    });

    test('an availability answer landing after destroy() mints nothing and arms nothing', async () => {
        jest.useFakeTimers();
        try {
            const state = { calls: 0 };
            const calls = stubFetch(mintsSucceed(state));

            // A container-less page, so the answer comes over the network and
            // can still be in flight when the instance is torn down.
            document.body.innerHTML = '';
            const instance = build();
            instance.destroy();
            await flushPromises();

            expect(calls.mints).toHaveLength(0);
            expect(instance._tokenRefreshIntervalId).toBeNull();
            expect(jest.getTimerCount()).toBe(0);
        } finally {
            jest.useRealTimers();
        }
    });
});

describe('a payment fragment that arrives after this instance was constructed', () => {
    test('mints and arms the refresh, rather than settling availability silently', async () => {
        jest.useFakeTimers();
        try {
            // No container at construction - the address-editor page - and the
            // availability request fails, so nothing is resolved or cached.
            TwoSoleTrader = loadSoleTrader();
            let mintCalls = 0;
            const calls = stubFetch(() => {
                mintCalls += 1;
                return Promise.resolve({ json: () => Promise.resolve(tokenPayload(String(mintCalls))) });
            }, { fail: true });

            const instance = build();
            await flushPromises();
            expect(calls.mints).toHaveLength(0);

            // PrestaShop now renders the payment fragment, carrying the
            // server's own "available" answer.
            buildPaymentTileWithSoleTraderAnswer('1', 'GB');
            jest.advanceTimersByTime(150);
            await flushPromises();
            await flushPromises();

            expect(instance.isAvailableForCurrentCountry()).toBe(true);
            expect(calls.mints).toHaveLength(1);
            expect(instance._tokenRefreshIntervalId).not.toBeNull();

            jest.advanceTimersByTime(30 * 60 * 1000);
            await flushPromises();
            expect(calls.mints).toHaveLength(2);
        } finally {
            jest.useRealTimers();
        }
    });

    test('a click-driven mint arms the refresh even with no eager mint before it', async () => {
        jest.useFakeTimers();
        try {
            buildPaymentTileWithSoleTraderAnswer('0', 'GB');
            TwoSoleTrader = loadSoleTrader();
            let mintCalls = 0;
            const calls = stubFetch(() => {
                mintCalls += 1;
                return Promise.resolve({ json: () => Promise.resolve(tokenPayload(String(mintCalls))) });
            });

            // Server says business-only, so nothing is minted eagerly.
            const instance = build();
            await flushPromises();
            expect(calls.mints).toHaveLength(0);
            expect(instance._tokenRefreshIntervalId).toBeNull();

            instance.startEnrollment();
            await flushPromises();

            expect(calls.mints).toHaveLength(1);
            expect(instance._tokenRefreshIntervalId).not.toBeNull();

            jest.advanceTimersByTime(30 * 60 * 1000);
            await flushPromises();
            expect(calls.mints).toHaveLength(2);
        } finally {
            jest.useRealTimers();
        }
    });
});

describe('a buyer who cannot use the flow costs no mint', () => {
    test('a server-rendered business-only answer mints nothing', async () => {
        buildPaymentTileWithSoleTraderAnswer('0', 'GB');
        TwoSoleTrader = loadSoleTrader();
        const state = { calls: 0 };
        const calls = stubFetch(mintsSucceed(state));

        const instance = build();
        await flushPromises();

        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        expect(calls.mints).toHaveLength(0);
        expect(instance._tokenRefreshIntervalId).toBeNull();

        instance.destroy();
    });

    test('a network answer of not-available mints nothing either', async () => {
        buildPaymentTile();
        TwoSoleTrader = loadSoleTrader();
        const state = { calls: 0 };
        const calls = stubFetch(mintsSucceed(state), { value: false });

        const instance = build();
        await flushPromises();
        await flushPromises();

        expect(calls.availability).toBe(1);
        expect(instance.isAvailableForCurrentCountry()).toBe(false);
        expect(calls.mints).toHaveLength(0);

        instance.destroy();
    });
});

describe('the minted tokens track the country the chip is shown for', () => {
    test('a change to a different eligible country mints again, for the new country', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        TwoSoleTrader = loadSoleTrader();
        let mintCalls = 0;
        const calls = stubFetch(() => {
            mintCalls += 1;
            return Promise.resolve({
                json: () => Promise.resolve(tokenPayload(String(mintCalls), mintCalls === 1 ? 'GB' : 'SE'))
            });
        });

        const instance = build();
        await flushPromises();
        expect(calls.mints).toHaveLength(1);
        expect(calls.mints[0]).toContain('country=GB');

        // The config-time fallback cannot move on its own, so the country
        // change is applied the way a live select would leave it.
        instance.config.billingCountry = 'SE';
        instance.availabilityByCountry.SE = true;
        instance.apply('SE', true);
        await flushPromises();

        expect(calls.mints).toHaveLength(2);
        expect(calls.mints[1]).toContain('country=SE');
        expect(instance.tokens.country).toBe('SE');

        instance.destroy();
    });

    test('a country whose eager mint was declined by the retry cooldown is still minted later', async () => {
        jest.useFakeTimers();
        try {
            buildPaymentTileWithSoleTraderAnswer('1', 'GB');
            TwoSoleTrader = loadSoleTrader();
            let mintCalls = 0;
            const calls = stubFetch(() => {
                mintCalls += 1;
                // The eager GB mint and the click that follows it both fail;
                // only the later SE mint succeeds.
                if (mintCalls <= 2) {
                    return Promise.reject(new Error('network down'));
                }
                return Promise.resolve({ json: () => Promise.resolve(tokenPayload(String(mintCalls), 'SE')) });
            });

            const instance = build();
            await flushPromises();
            expect(calls.mints).toHaveLength(1);
            // A failed eager mint starts no cooldown, so the click can mint.
            expect(instance.nextRetryAt).toBe(0);

            // The click fails too, and its failure DOES start the cooldown.
            instance.startEnrollment();
            await flushPromises();
            expect(calls.mints).toHaveLength(2);
            expect(instance.nextRetryAt).toBeGreaterThan(Date.now());

            // The buyer switches country while that cooldown is still running,
            // so the eager mint for SE is declined rather than issued.
            instance.config.billingCountry = 'SE';
            instance.availabilityByCountry.SE = true;
            instance.apply('SE', true);
            await flushPromises();
            expect(calls.mints).toHaveLength(2);

            // SE must not have been recorded as already attempted: once the
            // cooldown lapses it still gets tokens of its own.
            jest.advanceTimersByTime(instance.retryCooldownMs + 1);
            instance.apply('SE', true);
            await flushPromises();

            expect(calls.mints).toHaveLength(3);
            expect(calls.mints[2]).toContain('country=SE');
            expect(instance.tokens.country).toBe('SE');

            instance.destroy();
        } finally {
            jest.useRealTimers();
        }
    });

    test('an unsolicited signup message cannot act on tokens no click has asked for', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        TwoSoleTrader = loadSoleTrader();
        const state = { calls: 0 };
        const calls = stubFetch(mintsSucceed(state));

        const instance = build();
        await flushPromises();
        expect(instance.tokens.autofill_token).toBe('af-token-1');
        // The mount's own prefetch, which the message must not add to.
        expect(calls.buyerLookups).toBe(1);

        // A message from the signup origin, with no click ever made and no
        // popup ever opened - so not even the re-fetch a completed signup of
        // this instance's own would earn.
        window.dispatchEvent(new window.MessageEvent('message', {
            data: 'ACCEPTED',
            origin: 'https://signup.example.test'
        }));
        await flushPromises();
        await flushPromises();

        expect(calls.buyerLookups).toBe(1);
        expect(instance.enrolling).toBe(false);

        window.dispatchEvent(new window.MessageEvent('message', {
            data: 'FAILED',
            origin: 'https://signup.example.test'
        }));
        await flushPromises();

        expect(errorShown()).toBe(false);

        instance.destroy();
    });

    test('a click uses no tokens minted for a country the buyer has left', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        TwoSoleTrader = loadSoleTrader();
        let mintCalls = 0;
        const calls = stubFetch(() => {
            mintCalls += 1;
            return Promise.resolve({
                json: () => Promise.resolve(tokenPayload(String(mintCalls), mintCalls === 1 ? 'GB' : 'SE'))
            });
        });

        const instance = build();
        await flushPromises();
        expect(instance.tokens.country).toBe('GB');

        // The buyer changes country and clicks the chip before availability
        // has re-resolved, so no eager mint has run for the new country yet.
        instance.config.billingCountry = 'SE';
        instance.availabilityByCountry.SE = true;
        instance.startEnrollment();
        await flushPromises();

        // The click must have minted fresh SE authority, not enrolled against
        // the GB tokens still sitting on the instance.
        expect(calls.mints).toHaveLength(2);
        expect(calls.mints[1]).toContain('country=SE');
        expect(instance.tokens.country).toBe('SE');
    });

    test('an availability answer for a country a click already minted for does not re-mint', async () => {
        // Server-rendered "business only", so no eager mint runs at mount and
        // the click's own mint is the only one, fully settled, before the
        // availability answer below turns eligible.
        buildPaymentTileWithSoleTraderAnswer('0', 'GB');
        TwoSoleTrader = loadSoleTrader();
        const state = { calls: 0 };
        const calls = stubFetch(mintsSucceed(state));

        const instance = build();
        await flushPromises();
        expect(calls.mints).toHaveLength(0);

        instance.startEnrollment();
        await flushPromises();
        expect(calls.mints).toHaveLength(1);
        expect(instance.tokens.country).toBe('GB');

        // GB now resolves eligible and reaches the eager path, which must
        // recognise the click's tokens as already covering this country.
        instance.availabilityByCountry.GB = true;
        instance.apply('GB', true);
        await flushPromises();

        expect(calls.mints).toHaveLength(1);
    });

    test('a stale popup cannot act on tokens replaced by a later background mint', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        TwoSoleTrader = loadSoleTrader();
        let mintCalls = 0;
        const calls = stubFetch(() => {
            mintCalls += 1;
            return Promise.resolve({
                json: () => Promise.resolve(tokenPayload(String(mintCalls), mintCalls === 1 ? 'GB' : 'SE'))
            });
        });

        const instance = build();
        await flushPromises();

        // A real click stamps the tokens it is served as the current attempt;
        // it is served by the mount's tokens AND the mount's held autofill
        // answer, so it costs neither a mint nor a lookup of its own.
        instance.startEnrollment();
        await flushPromises();
        expect(calls.buyerLookups).toBe(1);
        expect(calls.mints).toHaveLength(1);

        // A country change re-mints with nobody waiting, replacing those
        // tokens - so the click's stamp must not carry over to them.
        instance.config.billingCountry = 'SE';
        instance.availabilityByCountry.SE = true;
        instance.apply('SE', true);
        await flushPromises();
        expect(instance.tokens.country).toBe('SE');
        const lookupsBeforeMessage = calls.buyerLookups;

        window.dispatchEvent(new window.MessageEvent('message', {
            data: 'ACCEPTED',
            origin: 'https://signup.example.test'
        }));
        await flushPromises();
        await flushPromises();

        expect(calls.buyerLookups).toBe(lookupsBeforeMessage);
    });

    test('a country change does not re-mint under a signup popup opened against the current tokens', async () => {
        jest.useFakeTimers();
        try {
            buildPaymentTileWithSoleTraderAnswer('1', 'GB');
            TwoSoleTrader = loadSoleTrader();
            let mintCalls = 0;
            const calls = stubFetch(() => {
                mintCalls += 1;
                return Promise.resolve({
                    json: () => Promise.resolve(tokenPayload(String(mintCalls), mintCalls === 1 ? 'GB' : 'SE'))
                });
            });
            const popup = { closed: false };
            global.window.open = jest.fn(() => popup);

            const instance = build();
            await flushPromises();
            expect(calls.mints).toHaveLength(1);

            // Click, no autofill match, then the buyer opens the signup popup -
            // which bakes the current GB tokens into its URL.
            instance.startEnrollment();
            await flushPromises();
            document.querySelector('.two-sole-trader__prompt')
                .dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
            expect(instance._popup).toBe(popup);

            // A country change now must not swap the tokens that popup is
            // signing against.
            instance.config.billingCountry = 'SE';
            instance.availabilityByCountry.SE = true;
            instance.apply('SE', true);
            await flushPromises();

            expect(calls.mints).toHaveLength(1);
            expect(instance.tokens.country).toBe('GB');

            // Once it closes, the new country does get its own tokens.
            popup.closed = true;
            jest.advanceTimersByTime(500);
            await flushPromises();
            expect(instance._popup).toBeNull();

            instance.apply('SE', true);
            await flushPromises();

            expect(calls.mints).toHaveLength(2);
            expect(instance.tokens.country).toBe('SE');
        } finally {
            jest.useRealTimers();
        }
    });

    test('a real billing-country change drives the eager mint, not just apply()', async () => {
        jest.useFakeTimers();
        try {
            buildPaymentTileWithSoleTraderAnswer('1', 'GB');
            TwoSoleTrader = loadSoleTrader();
            let mintCalls = 0;
            const calls = stubFetch(() => {
                mintCalls += 1;
                return Promise.resolve({
                    json: () => Promise.resolve(tokenPayload(String(mintCalls), mintCalls === 1 ? 'GB' : 'SE'))
                });
            });
            const select = buildCountrySelect('GB');

            const instance = build();
            await flushPromises();
            expect(calls.mints).toHaveLength(1);
            expect(calls.mints[0]).toContain('country=GB');

            // The buyer picks a different country in the real select; the
            // module's own change listener and debounce do the rest.
            selectCountry(select, 'SE');
            jest.advanceTimersByTime(150);
            await flushPromises();
            await flushPromises();

            expect(calls.mints).toHaveLength(2);
            expect(calls.mints[1]).toContain('country=SE');
            expect(instance.tokens.country).toBe('SE');
        } finally {
            jest.useRealTimers();
        }
    });

    test('a click riding an in-flight background refresh tick is still served', async () => {
        jest.useFakeTimers();
        try {
            buildPaymentTileWithSoleTraderAnswer('1', 'GB');
            TwoSoleTrader = loadSoleTrader();
            let mintCalls = 0;
            let resolveTick;
            const calls = stubFetch(() => {
                mintCalls += 1;
                if (mintCalls === 2) {
                    return new Promise((resolve) => { resolveTick = resolve; });
                }
                return Promise.resolve({
                    json: () => Promise.resolve(tokenPayload(String(mintCalls), mintCalls === 1 ? 'GB' : 'SE'))
                });
            });
            const settled = jest.fn();
            document.addEventListener('two:sole-trader-flight-settled', settled);

            const instance = build();
            await flushPromises();
            expect(calls.mints).toHaveLength(1);

            // The 30-minute tick fires and its POST is still out.
            jest.advanceTimersByTime(30 * 60 * 1000);
            expect(mintCalls).toBe(2);
            expect(instance.isFetchingTokens).toBe(true);

            // The buyer changes country and clicks while that tick is in
            // flight, so the click rides a request with no resume branches.
            instance.config.billingCountry = 'SE';
            instance.availabilityByCountry.SE = true;
            instance.startEnrollment();
            expect(mintCalls).toBe(2);
            // Nothing has resolved yet, so the click is genuinely still open.
            expect(settled).not.toHaveBeenCalled();

            resolveTick({ json: () => Promise.resolve(tokenPayload('2', 'GB')) });
            await flushPromises();
            await flushPromises();

            // The click must not dead-end: a mint for the country it actually
            // needs was issued, and its flight reached a terminal state.
            expect(mintCalls).toBe(3);
            expect(calls.mints[2]).toContain('country=SE');
            expect(instance.tokens.country).toBe('SE');
            expect(settled).toHaveBeenCalledTimes(1);
            expect(instance._mintHasWaiter).toBe(false);

            document.removeEventListener('two:sole-trader-flight-settled', settled);
        } finally {
            jest.useRealTimers();
        }
    });

    test('a mint landing after its enrolment already completed is not acted on', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        TwoSoleTrader = loadSoleTrader();
        let mintCalls = 0;
        let resolveClickMint;
        const calls = stubFetch(() => {
            mintCalls += 1;
            if (mintCalls === 2) {
                return new Promise((resolve) => { resolveClickMint = resolve; });
            }
            return Promise.resolve({ json: () => Promise.resolve(tokenPayload(String(mintCalls), 'GB')) });
        });
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();
        expect(calls.mints).toHaveLength(1);

        // A click on a country the mount's tokens do not cover mints afresh.
        instance.config.billingCountry = 'SE';
        instance.availabilityByCountry.SE = true;
        instance.startEnrollment();
        expect(mintCalls).toBe(2);

        // An enrolment COMPLETES while that mint is still out - what
        // applyBuyer() does on a successful adoption, leaving the waiting
        // click's flag set but the flow no longer enrolling.
        instance.enrolling = false;
        const lookupsBeforeMint = calls.buyerLookups;

        resolveClickMint({ json: () => Promise.resolve(tokenPayload('2', 'SE')) });
        await flushPromises();
        await flushPromises();

        // The prefetch off the tokens that just landed, and nothing else: the
        // completed enrolment's click is not resumed into a lookup or a popup.
        expect(calls.buyerLookups).toBe(lookupsBeforeMint + 1);
        expect(instance.heldBuyerResult()).toEqual({ country: 'SE', buyer: null });
        expect(openSpy).not.toHaveBeenCalled();
    });

    test('no eager mint runs while a buyer lookup is still out against the current tokens', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        TwoSoleTrader = loadSoleTrader();
        const state = { calls: 0 };
        let resolveLookup;
        let lookupCalls = 0;
        const calls = stubFetch(mintsSucceed(state), {
            lookup: () => {
                lookupCalls += 1;
                // The mount's prefetch fails, so nothing is held and the click
                // has to run its own lookup - which then hangs.
                if (lookupCalls === 1) {
                    return Promise.reject(new Error('autofill unreachable'));
                }
                return new Promise((resolve) => { resolveLookup = resolve; });
            }
        });

        const instance = build();
        await flushPromises();
        expect(calls.mints).toHaveLength(1);
        expect(instance.heldBuyerResult()).toBeNull();

        // The click's lookup is authorised by the GB tokens and is still out.
        instance.startEnrollment();
        await flushPromises();
        expect(calls.buyerLookups).toBe(2);
        expect(instance.isFetchingBuyer).toBe(true);

        // A country change must not swap the tokens that lookup is running
        // against - applyBuyer() reads their country when it lands.
        instance.config.billingCountry = 'SE';
        instance.availabilityByCountry.SE = true;
        instance.apply('SE', true);
        await flushPromises();

        expect(calls.mints).toHaveLength(1);
        expect(instance.tokens.country).toBe('GB');

        resolveLookup({ ok: false, status: 404 });
        await flushPromises();
    });

    test('a click abandoned while riding a refresh tick is not rescued into a wasted mint', async () => {
        jest.useFakeTimers();
        try {
            buildPaymentTileWithSoleTraderAnswer('1', 'GB');
            TwoSoleTrader = loadSoleTrader();
            let mintCalls = 0;
            let resolveTick;
            const calls = stubFetch(() => {
                mintCalls += 1;
                if (mintCalls === 2) {
                    return new Promise((resolve) => { resolveTick = resolve; });
                }
                return Promise.resolve({ json: () => Promise.resolve(tokenPayload(String(mintCalls), 'GB')) });
            });

            const instance = build();
            await flushPromises();
            expect(calls.mints).toHaveLength(1);

            jest.advanceTimersByTime(30 * 60 * 1000);
            expect(mintCalls).toBe(2);

            // The buyer changes country and clicks, riding the tick, then goes
            // back to ordinary company search before it lands.
            instance.config.billingCountry = 'SE';
            instance.availabilityByCountry.SE = true;
            instance.startEnrollment();
            instance.cancelEnrollment();

            resolveTick({ json: () => Promise.resolve(tokenPayload('2', 'GB')) });
            await flushPromises();
            await flushPromises();

            // Nobody is waiting any more, so the tick's rescue must not mint
            // for the abandoned click. The mint that DOES follow is the eager
            // one the buyer's new country needs - it takes no waiter, and is
            // acted on by nothing.
            expect(mintCalls).toBe(3);
            expect(calls.mints[calls.mints.length - 1]).toContain('country=SE');
            expect(instance._mintHasWaiter).toBe(false);
            expect(instance.enrolling).toBe(false);
            expect(calls.buyerLookups).toBe(1);
        } finally {
            jest.useRealTimers();
        }
    });

    test('"select a different sole trader" also re-mints for a country the buyer has moved to', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        TwoSoleTrader = loadSoleTrader();
        let mintCalls = 0;
        const calls = stubFetch(() => {
            mintCalls += 1;
            return Promise.resolve({
                json: () => Promise.resolve(tokenPayload(String(mintCalls), mintCalls === 1 ? 'GB' : 'SE'))
            });
        });
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = build();
        await flushPromises();
        expect(instance.tokens.country).toBe('GB');

        instance.config.billingCountry = 'SE';
        instance.availabilityByCountry.SE = true;
        instance.startReplacement();
        await flushPromises();

        // The replacement popup must be signed against SE authority, not the
        // GB pair the mount happened to leave on the instance.
        expect(calls.mints).toHaveLength(2);
        expect(calls.mints[1]).toContain('country=SE');
        expect(openSpy).toHaveBeenCalledTimes(1);
        expect(String(openSpy.mock.calls[0][0])).toContain('af-token-2');
    });

    test('the rescue mint is declined while a signup popup is open against the current tokens', async () => {
        jest.useFakeTimers();
        try {
            buildPaymentTileWithSoleTraderAnswer('1', 'GB');
            TwoSoleTrader = loadSoleTrader();
            let mintCalls = 0;
            let resolveTick;
            const calls = stubFetch(() => {
                mintCalls += 1;
                if (mintCalls === 2) {
                    return new Promise((resolve) => { resolveTick = resolve; });
                }
                return Promise.resolve({ json: () => Promise.resolve(tokenPayload(String(mintCalls), 'GB')) });
            });
            const popup = { closed: false };
            global.window.open = jest.fn(() => popup);

            const instance = build();
            await flushPromises();
            expect(calls.mints).toHaveLength(1);
            const mintedTokens = instance.tokens;

            jest.advanceTimersByTime(30 * 60 * 1000);
            expect(mintCalls).toBe(2);

            // A click rides the tick, and the buyer opens the signup popup
            // against the tokens currently on the instance before it lands.
            instance.config.billingCountry = 'SE';
            instance.availabilityByCountry.SE = true;
            instance.startEnrollment();
            instance._popup = popup;

            resolveTick({ json: () => Promise.resolve(tokenPayload('2', 'GB')) });
            await flushPromises();
            await flushPromises();

            // No rescue mint, and the popup's pair is untouched.
            expect(mintCalls).toBe(2);
            expect(instance.tokens).toBe(mintedTokens);
            // Declining the mint still ends that click's wait - the popup
            // closing settles it. The behaviour that stands on: the next eager
            // mint, once the popup is gone, must land silently rather than
            // opening a second popup or running a lookup for that dead click.
            expect(instance._mintHasWaiter).toBe(false);

            popup.closed = true;
            instance._popup = null;
            const openSpy = jest.fn(() => ({ closed: false }));
            global.window.open = openSpy;
            instance.apply('SE', true);
            await flushPromises();
            await flushPromises();

            expect(mintCalls).toBe(3);
            expect(openSpy).not.toHaveBeenCalled();
            // The one lookup is that mint's own prefetch, with the enrolment
            // prompt left down - nothing resumed the dead click.
            expect(calls.buyerLookups).toBe(1);
            expect(document.querySelector('.two-sole-trader__prompt').style.display).not.toBe('inline');
        } finally {
            jest.useRealTimers();
        }
    });

    test('a repeated availability resolution for the same country does not re-mint', async () => {
        buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        TwoSoleTrader = loadSoleTrader();
        const state = { calls: 0 };
        const calls = stubFetch(mintsSucceed(state));

        const instance = build();
        await flushPromises();
        expect(calls.mints).toHaveLength(1);

        instance.apply('GB', true);
        instance.apply('GB', true);
        await flushPromises();

        expect(calls.mints).toHaveLength(1);

        instance.destroy();
    });
});
