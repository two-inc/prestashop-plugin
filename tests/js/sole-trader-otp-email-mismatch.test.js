/**
 * Live bug (Doug, 2026-08-12): sole-trader autofill's passive lookup finds no
 * matching Two session cookie for the checkout email, so it opens the hosted
 * signup popup. The buyer completes a REAL OTP verification there for a
 * DIFFERENT, genuinely-registered email (their sole-trader account may not
 * share an inbox with whatever they typed into PrestaShop's own personal-
 * information step for this order). The popup posts 'ACCEPTED' back and
 * closes - authentication succeeded - but the resulting buyer lookup used to
 * re-validate `buyer.email` against `checkoutEmail()` (still the FIRST,
 * unrelated email, since nothing in this flow ever writes the popup's email
 * back into the PS form) and rejected an otherwise-successful response,
 * reopening the very popup the buyer had just finished with. Every retry
 * hits the identical mismatch, forever - the bug is not a real validation
 * rule, it is `getCurrentBuyer()` reusing the SAME email-match heuristic for
 * two calls with different trust levels: an unauthenticated cookie probe
 * (where the heuristic is the only signal available) and a call that follows
 * a genuine OTP round trip (where the server has already told this browser
 * exactly who the buyer is).
 *
 * Fix: bindPopupMessageListener()'s 'ACCEPTED' handler calls
 * getCurrentBuyer(true) - `trustedIdentity` - which applies any buyer the
 * endpoint returns without re-checking it against checkoutEmail() at all.
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

function stubManager() {
    const publishes = [];
    global.window.TwoCheckoutManager_Instance = {
        setConfirmedCompanySelection(selection) {
            publishes.push(selection);
        }
    };
    return publishes;
}

beforeEach(() => {
    buildPaymentTile();
    TwoSoleTrader = loadSoleTrader();
});

afterEach(() => {
    delete global.window.TwoCheckoutManager_Instance;
    delete global.fetch;
    delete global.window.fetch;
    delete global.window.TwoCompanyNumber;
    delete global.window.open;
    document.body.innerHTML = '';
    global.window.localStorage.clear();
});

test('a real OTP round trip applies the buyer even when their email differs from the checkout form', async () => {
    const publishes = stubManager();
    global.window.TwoCompanyNumber = { forDisplay: (v) => v };

    // EMAIL_A: what the buyer typed into PrestaShop's own personal-info step
    // for this order. Deliberately different from the sole-trader account's
    // real, registered email below - the buyer legitimately has both.
    document.body.insertAdjacentHTML(
        'beforeend',
        "<input name='email' value='order-contact@example.test' />"
    );

    let popupOpenCalls = 0;
    global.window.open = function () {
        popupOpenCalls += 1;
        // A real popup handle - openPopup()'s blocked-popup branch must not
        // fire and confuse this test with an unrelated showError() path.
        return {};
    };

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
            // EMAIL_B: the sole trader's real, registered account email -
            // the one they just proved ownership of with a valid OTP inside
            // the hosted popup. Never equal to EMAIL_A above; nothing in
            // this flow makes it equal, by design - the two identify
            // different things (who authenticated vs. who the order is
            // addressed to).
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({
                    email: 'sole-trader-real-account@example.test',
                    company_name: 'Sole Trader AS',
                    organization_number: '923456789'
                })
            });
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
    };
    global.fetch = global.window.fetch;

    const instance = build();
    instance.startEnrollment();
    // Mint resolves; the first, PASSIVE getCurrentBuyer() call correctly
    // finds no cookie/email match (EMAIL_A has no Two session yet) and hands
    // off to the on-page prompt - no popup opened by this step alone.
    await flushPromises();
    await flushPromises();
    expect(popupOpenCalls).toBe(0);
    expect(publishes).toEqual([]);

    // The buyer clicks the on-page prompt, opening the hosted signup popup.
    const prompt = document.querySelector('.two-sole-trader__prompt');
    expect(prompt).not.toBeNull();
    prompt.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
    expect(popupOpenCalls).toBe(1);

    // The buyer authenticates in the popup as EMAIL_B and gets a valid OTP;
    // the popup posts back and (in production) closes itself.
    window.dispatchEvent(new window.MessageEvent('message', {
        data: 'ACCEPTED',
        origin: 'https://signup.example.test'
    }));
    await flushPromises();
    await flushPromises();
    await flushPromises();

    // The buyer must be applied - NOT rejected and NOT sent back through
    // another popup round trip.
    expect(publishes).toEqual([{ company: 'Sole Trader AS', companyid: '923456789' }]);
    expect(popupOpenCalls).toBe(1);

    instance.destroy();
});
