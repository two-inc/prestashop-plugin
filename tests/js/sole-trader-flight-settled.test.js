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

test('a no-match buyer lookup handed off directly to the popup (address-editor page) fires the settle event', async () => {
    buildAddressForm();
    global.window.open = jest.fn(() => ({ closed: false }));
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
    global.window.open = jest.fn(() => ({ closed: false }));
    let resolveTokens;
    stubFetch({
        tokens: () => new Promise((resolve) => { resolveTokens = resolve; }),
        buyer: () => Promise.resolve({ ok: false, status: 404 })
    });
    const { calls, handler } = recordSettled();

    const instance = build();
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

    // Click 2 must have been resumed and settled on its own - not left
    // hanging because the tokens landed under click 1's stale generation.
    expect(instance.tokens).not.toBeNull();
    expect(calls.length).toBe(2);
    document.removeEventListener('two:sole-trader-flight-settled', handler);
    instance.destroy();
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
