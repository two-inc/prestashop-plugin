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
