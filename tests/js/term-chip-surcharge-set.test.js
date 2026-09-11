/**
 * ABN-528: the term chips' fee amount is decided over the whole offered set.
 *
 * Any priced term puts an amount on every chip — a zero-fee chip then shows a
 * zero amount, not a blank — and every term ~zero shows none anywhere. Deciding
 * per chip is silent when it regresses: the chips still render, they just
 * disagree about whether the buyer is being charged.
 *
 * Every platform's checkout applies the same rule and the same threshold.
 */

'use strict';

const { loadCompanySearch, loadOrderIntent, loadScript, releaseWidgets, stubAjax, flushPromises } = require('./ps-harness');

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
        order_intent_url: ORDER_INTENT_URL,
        ajax_token: 'test-token',
        checkout_host: CHECKOUT_HOST
    };

    document.body.innerHTML = '<div class="payment-options"></div>';
});

afterEach(async () => {
    await flushPromises();
    ajax.restore();
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
    delete window.TwoCheckoutManager_Instance;
});

/** The chip markup initializePaymentTerms() builds, one chip per term. */
function mountChips(terms) {
    const container = document.createElement('div');
    container.className = 'two-term-chips__container';
    terms.forEach((days) => {
        const chip = document.createElement('button');
        chip.className = 'two-term-chip';
        chip.dataset.days = String(days);
        const surcharge = document.createElement('span');
        surcharge.className = 'two-term-chip__surcharge';
        surcharge.innerHTML = '<span class="two-term-chip__loading">...</span>';
        chip.appendChild(surcharge);
        container.appendChild(chip);
    });
    document.body.appendChild(container);
    return container;
}

function makeManager() {
    const manager = new TwoCheckoutManager({
        checkoutHost: CHECKOUT_HOST,
        orderIntentEnabled: true,
        orderIntentUrl: ORDER_INTENT_URL,
        ajaxToken: 'test-token'
    });
    window.TwoCheckoutManager_Instance = manager;
    return manager;
}

/** @returns {string[]} the surcharge text of each chip, in order */
function chipSurcharges(container) {
    return Array.from(container.querySelectorAll('.two-term-chip__surcharge')).map((node) => node.textContent);
}

test.each([
    { terms: [30, 60], amounts: { 30: 0, 60: 0 }, expected: ['', ''], description: 'every term zero shows nothing anywhere' },
    { terms: [30, 60], amounts: { 30: 12.5, 60: 0 }, expected: ['+12.50 EUR', '+0.00 EUR'], description: 'one priced term puts a zero amount on the zero-fee chip' },
    { terms: [30, 60], amounts: { 30: 12.5, 60: 18 }, expected: ['+12.50 EUR', '+18.00 EUR'], description: 'every priced term shows its own amount' },
    { terms: [30], amounts: { 30: 0 }, expected: [''], description: 'a lone zero-fee chip shows nothing' },
    { terms: [30], amounts: { 30: 9 }, expected: ['+9.00 EUR'], description: 'a lone priced chip shows its amount' },
    { terms: [30, 60], amounts: { 30: 12.5 }, expected: ['+12.50 EUR', '+0.00 EUR'], description: 'a term missing from the quote shows a zero amount beside a priced sibling' },
    { terms: [30, 60], amounts: { 30: 12.5, 60: 'not-a-number' }, expected: ['+12.50 EUR', '+0.00 EUR'], description: 'an unquotable term shows a zero amount beside a priced sibling' },
    { terms: [30, 60], amounts: {}, expected: ['', ''], description: 'no term quoted shows nothing anywhere' }
])('$description', ({ terms, amounts, expected }) => {
    const manager = makeManager();
    const container = mountChips(terms);

    manager.refreshTermSurchargeAmounts(container);
    ajax.last().succeed({ success: true, currency: 'eur', amounts: amounts });

    expect(chipSurcharges(container)).toEqual(expected);
});

test('a failed quote clears every chip rather than leaving the loader running', () => {
    const manager = makeManager();
    const container = mountChips([30, 60]);

    manager.refreshTermSurchargeAmounts(container);
    ajax.last().fail('error');

    expect(chipSurcharges(container)).toEqual(['', '']);
    expect(container.querySelectorAll('.two-term-chip__loading')).toHaveLength(0);
});
