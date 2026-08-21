/**
 * TWO-40 follow-up: `.two-sole-trader` and everything inside it is only ever
 * rendered by views/templates/hook/paymentinfo.tpl, i.e. only on the payment
 * step. So on the address-editor page showPrompt() has no element to show and
 * silently no-ops; the no-match branch must call openPopup() directly there.
 *
 * Verified in real Chrome against staging that a `window.open()` chained off
 * two async hops (fetchTokens() then the buyer lookup) after a real click still
 * survives Chrome's transient-activation window under normal latency.
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

function stubFetch(buyerAnswer) {
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
                    signup_url: 'https://signup.example.test/',
                    country: 'GB'
                })
            });
        }
        if (String(url).includes('/autofill/v1/buyer/current')) {
            return buyerAnswer();
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;
}

function noMatchAnswer() {
    return Promise.resolve({ ok: false, status: 404 });
}

beforeEach(() => {
    TwoSoleTrader = loadSoleTrader();
});

afterEach(() => {
    delete global.fetch;
    delete global.window.fetch;
    delete global.window.TwoCompanyNumber;
    delete global.window.open;
    document.body.innerHTML = '';
    global.window.localStorage.clear();
});

describe('address-editor page (no .two-sole-trader container at all)', () => {
    test('a no-match buyer lookup opens the popup directly instead of dead-ending on showPrompt()', async () => {
        buildAddressForm();
        expect(document.querySelector('.two-sole-trader')).toBeNull();

        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;
        stubFetch(noMatchAnswer);

        const instance = build();
        instance.startEnrollment();
        await flushPromises();
        await flushPromises();
        await flushPromises();

        expect(openSpy).toHaveBeenCalledTimes(1);
        expect(String(openSpy.mock.calls[0][0])).toContain('businessToken=');
        instance.destroy();
    });

    test('a blocked popup with no container logs to console.error instead of failing completely silently', async () => {
        buildAddressForm();
        global.window.open = jest.fn(() => null); // blocked
        stubFetch(noMatchAnswer);
        const errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});

        const instance = build();
        instance.startEnrollment();
        await flushPromises();
        await flushPromises();
        await flushPromises();

        expect(errorSpy).toHaveBeenCalled();
        errorSpy.mockRestore();
        instance.destroy();
    });
});

describe('payment step (.two-sole-trader container present) - unchanged', () => {
    test('a no-match buyer lookup shows the prompt and does NOT open the popup until the prompt is clicked', async () => {
        buildPaymentTile();
        expect(document.querySelector('.two-sole-trader__prompt')).not.toBeNull();

        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;
        stubFetch(noMatchAnswer);

        const instance = build();
        instance.startEnrollment();
        await flushPromises();
        await flushPromises();
        await flushPromises();

        expect(openSpy).not.toHaveBeenCalled();
        const prompt = document.querySelector('.two-sole-trader__prompt');
        expect(prompt.style.display).toBe('inline');

        prompt.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
        expect(openSpy).toHaveBeenCalledTimes(1);
        instance.destroy();
    });
});
