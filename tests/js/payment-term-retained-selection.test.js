/**
 * TWO-25709: the term chip the picker shows must be the term the order is
 * booked on.
 *
 * The retained selection survives a checkout reload server-side, so a picker
 * that seeds itself from the configured default alone shows one term while the
 * submission uses another - and a buyer who does not re-click the chip is
 * charged for the term they cannot see.
 */

'use strict';

const { loadCompanySearch, loadOrderIntent, loadScript, releaseWidgets, stubAjax } = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';

let TwoCheckoutManager;
let $;
let ajax;

beforeEach(() => {
    const loaded = loadCompanySearch();
    $ = loaded.$;
    ajax = stubAjax($);
    loadOrderIntent();
    loadScript('views/js/modules/TwoCheckoutManager.js');
    TwoCheckoutManager = window.TwoCheckoutManager;

    window.twopayment = {
        checkout_host: CHECKOUT_HOST,
        order_intent_url: ORDER_INTENT_URL,
        ajax_token: 'token'
    };
    document.body.innerHTML = `
        <div class="payment-options"></div>
        <div class="two-payment-terms" id="two-payment-terms" style="display: none;">
            <div class="two-term-chips">
                <div class="two-term-chips__container" id="two-terms-chips"></div>
                <div class="two-terms-selected"><span id="two-selected-days"></span></div>
            </div>
        </div>`;
});

afterEach(() => {
    ajax.restore();
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
});

function render(terms, defaultTerm, retainedTerm) {
    const manager = new TwoCheckoutManager({
        checkoutHost: CHECKOUT_HOST,
        orderIntentEnabled: false,
        available_payment_terms: terms,
        default_payment_term: defaultTerm,
        selected_payment_term: retainedTerm
    });
    manager.showPaymentTerms();
    return manager;
}

function selectedDays() {
    const chip = document.querySelector('.two-term-chip--selected');
    return chip ? Number(chip.dataset.days) : null;
}

function ariaCheckedDays() {
    return Array.from(document.querySelectorAll('.two-term-chip[aria-checked="true"]'))
        .map((chip) => Number(chip.dataset.days));
}

function savePaymentTermCalls() {
    return ajax.calls.filter((call) => call.settings.data && call.settings.data.action === 'savePaymentTerm');
}

describe('the term the picker seeds itself with', () => {
    // [description, offered terms, configured default, retained selection, term shown as selected]
    test.each([
        ['a retained term wins over the configured default', [30, 60, 90], 30, 60, 60],
        ['no retained selection falls back to the configured default', [30, 60, 90], 30, 0, 30],
        ['a retained term that is not offered falls back to the default', [30, 60, 90], 30, 45, 30],
        ['a retained term equal to the default is still shown', [30, 60, 90], 90, 90, 90],
        ['a retained term is shown even when the default is not offered', [30, 60, 90], 45, 60, 60],
        ['neither offered falls back to the first offered term', [30, 60, 90], 45, 0, 30],
    ])('%s', (description, terms, defaultTerm, retainedTerm, expected) => {
        render(terms, defaultTerm, retainedTerm);

        expect(selectedDays()).toBe(expected);
        expect(ariaCheckedDays()).toEqual([expected]);
        expect(document.getElementById('two-selected-days').textContent).toContain(String(expected));
    });
});

describe('a term the server did not accept', () => {
    test('the chip returns to the term the server still holds', () => {
        render([30, 60, 90], 30, 60);

        document.querySelector('.two-term-chip[data-days="90"]').click();
        expect(selectedDays()).toBe(90);

        savePaymentTermCalls().pop().fail('error');

        expect(selectedDays()).toBe(60);
        expect(ariaCheckedDays()).toEqual([60]);
        expect(document.getElementById('two-selected-days').textContent).toContain('60');
    });

    test('the revert goes back to the last term the server accepted', () => {
        render([30, 60, 90], 30, 60);

        document.querySelector('.two-term-chip[data-days="90"]').click();
        savePaymentTermCalls().pop().succeed({ success: true });

        document.querySelector('.two-term-chip[data-days="30"]').click();
        savePaymentTermCalls().pop().fail('error');

        expect(selectedDays()).toBe(90);
    });

    test('an aborted persist leaves the newer selection alone', () => {
        render([30, 60, 90], 30, 60);

        document.querySelector('.two-term-chip[data-days="90"]').click();
        savePaymentTermCalls().pop().fail('abort');

        expect(selectedDays()).toBe(90);
    });
});

describe('the config the checkout manager is built with', () => {
    // [description, published value, value the manager reads]
    test.each([
        ['an absent retained term reads as none', undefined, 0],
        ['a zero retained term reads as none', 0, 0],
        ['a real retained term is carried through', 60, 60],
    ])('%s', (description, published, expected) => {
        loadScript('views/js/twopayment.js');

        const config = window.twoBuildCheckoutManagerConfig({ selected_payment_term: published });

        expect(config.selected_payment_term).toBe(expected);
    });
});
