/**
 * TWO-40 follow-up: PR #151 fixed the Sole Trader chip's VISIBILITY on the
 * address-editor page but not its click behaviour. `.two-sole-trader` (and
 * everything inside it - `.two-sole-trader__prompt`, `.two-sole-trader__status`,
 * `.two-sole-trader__error`) is only ever rendered by
 * views/templates/hook/paymentinfo.tpl, i.e. only on the payment step. On the
 * address-editor page, getCurrentBuyer()'s no-match branch used to always call
 * showPrompt(), which no-ops when `.two-sole-trader__prompt` cannot be found -
 * so the buyer's click minted tokens, ran a buyer lookup, and then silently
 * dead-ended with no popup and no error.
 *
 * Empirically verified in real Chrome against staging that a `window.open()`
 * chained off two async hops (fetchTokens() then the buyer lookup) after a
 * real click survives Chrome's transient-activation window under normal
 * latency, so the fix is: when there is no `.two-sole-trader__prompt` to
 * show, call openPopup() directly instead of falling through to a no-op.
 *
 * Payment-step behaviour, where the container and prompt element genuinely
 * exist, must stay on the original two-click showPrompt()->openPopup() flow -
 * see the "unchanged on payment step" test below.
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

/** No checkout email in the DOM - always a 404-shaped "no buyer" answer. */
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
