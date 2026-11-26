/**
 * TWO-25503. The hosted signup popup posts back 'ACCEPTED' on success and
 * something else on failure. A failure that belongs to THIS flow's live
 * attempt must reach the buyer as an error - the popup is gone or stuck and
 * nothing else on the page ever explains why the enrolment stopped.
 *
 * The narrowing matters as much as the surfacing: a message for an attempt
 * the buyer has already walked away from (`_enrollGeneration` moved on), from
 * another origin, or positively attributable to some other window must stay
 * silent, or the error lands on a flow that is no longer on screen.
 */

'use strict';

const {
    loadSoleTrader,
    buildPaymentTileWithSoleTraderAnswer,
    flushPromises
} = require('./ps-harness');

const SIGNUP_ORIGIN = 'https://signup.example.test';

let TwoSoleTrader;
let buyerLookupCalls;
let popup;

function build() {
    return new TwoSoleTrader({
        checkoutHost: 'https://api.example.test',
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        billingCountry: 'GB'
    });
}

/**
 * `MessageEvent`'s constructor only accepts a real WindowProxy/MessagePort as
 * `source`, so the stub handle is attached to the built event instead.
 */
function post(data, origin, source) {
    const event = new window.MessageEvent('message', { data: data, origin: origin });
    Object.defineProperty(event, 'source', { value: source });
    window.dispatchEvent(event);
}

function errorShown() {
    const error = document.querySelector('.two-sole-trader__error');
    return !!error && error.style.display === 'inline';
}

beforeEach(() => {
    buyerLookupCalls = 0;
    popup = { closed: false, focus() {}, close() { this.closed = true; } };
    buildPaymentTileWithSoleTraderAnswer('1', 'GB');
    global.window.open = () => popup;
    global.window.fetch = (url) => {
        if (String(url).includes('soleTraderAvailability')) {
            return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
        }
        if (String(url).includes('soleTraderTokens')) {
            return Promise.resolve({
                json: () => Promise.resolve({
                    success: true,
                    autofill_token: 'af-token',
                    delegation_token: 'del-token',
                    signup_url: SIGNUP_ORIGIN + '/',
                    country: 'GB'
                })
            });
        }
        if (String(url).includes('/autofill/v1/buyer/current')) {
            buyerLookupCalls += 1;
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    email: 'buyer@example.test',
                    company_name: 'Sole Trader AS',
                    organization_number: '923456789'
                })
            });
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;
    TwoSoleTrader = loadSoleTrader();
});

afterEach(() => {
    delete global.window.TwoCheckoutManager_Instance;
    delete global.window.TwoCompanyNumber;
    delete global.fetch;
    delete global.window.fetch;
    document.body.innerHTML = '';
    // TWO-40 follow-up: see the identical comment in
    // sole-trader-toggle-flicker.test.js.
    global.window.localStorage.clear();
});

/**
 * Enrol, mint, and hand off to a popup - the state every case below starts
 * from. No checkout email is on the page, so the mint's own buyer lookup
 * comes back unmatched and shows the prompt rather than adopting anything.
 */
async function enrolWithOpenPopup() {
    const instance = build();
    instance.startEnrollment();
    await flushPromises();
    await flushPromises();
    instance.openPopup();
    return instance;
}

describe.each([
    [
        'a failure from the live popup',
        (instance) => post('FAILED', SIGNUP_ORIGIN, popup),
        true,
        0
    ],
    [
        'a failure whose sender window has already closed',
        (instance) => post('FAILED', SIGNUP_ORIGIN, null),
        true,
        0
    ],
    [
        'a failure attributable to a different window',
        (instance) => post('FAILED', SIGNUP_ORIGIN, { closed: false }),
        false,
        0
    ],
    [
        'a failure from an attempt the buyer has walked away from',
        (instance) => {
            instance.cancelEnrollment();
            post('FAILED', SIGNUP_ORIGIN, popup);
        },
        false,
        // Walking away re-fetches the pre-click autofill answer the popup
        // dropped, so this row's own cancel costs one lookup - the FAILED
        // message that follows it still costs none.
        1
    ],
    [
        'a failure from an unrelated origin',
        (instance) => post('FAILED', 'https://evil.example.test', popup),
        false,
        0
    ]
])('%s', (_label, act, expectError, extraLookups) => {
    test(expectError ? 'surfaces an error' : 'is ignored', async () => {
        const instance = await enrolWithOpenPopup();
        const lookupsBefore = buyerLookupCalls;

        act(instance);
        await flushPromises();
        await flushPromises();

        expect(errorShown()).toBe(expectError);
        // A failure never starts a buyer lookup, however it is classified.
        expect(buyerLookupCalls).toBe(lookupsBefore + extraLookups);
        instance.destroy();
    });
});

test("'ACCEPTED' from the live popup still adopts the enrolled identity", async () => {
    const publishes = [];
    global.window.TwoCheckoutManager_Instance = {
        setConfirmedCompanySelection(selection) {
            publishes.push(selection);
        }
    };
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };

    const instance = await enrolWithOpenPopup();
    const lookupsBefore = buyerLookupCalls;
    // The buyer completes signup inside the popup, so the checkout email now
    // matches what the lookup answers.
    document.body.insertAdjacentHTML('beforeend', "<input name='email' value='buyer@example.test' />");

    post('ACCEPTED', SIGNUP_ORIGIN, popup);
    await flushPromises();
    await flushPromises();
    await flushPromises();

    expect(errorShown()).toBe(false);
    expect(buyerLookupCalls).toBe(lookupsBefore + 1);
    expect(publishes).toEqual([{ company: 'Sole Trader AS', companyid: '923456789' }]);
    instance.destroy();
});
