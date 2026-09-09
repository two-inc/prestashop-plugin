/**
 * ABN-533: with no offered payment term the term block never opens.
 *
 * The template ships the block hidden, with a placeholder day count inside it.
 * showPaymentTerms() used to reveal it before initializePaymentTerms() had
 * decided there were no chips to draw, so a shop whose merchant record could
 * not be resolved showed the header and that placeholder as though they were
 * an offer.
 */

'use strict';

const { loadCompanySearch, loadOrderIntent, loadScript, releaseWidgets, stubAjax } = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

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

    window.twopayment = { checkout_host: CHECKOUT_HOST };
    // The template's own markup: hidden, with the placeholder day count.
    document.body.innerHTML = `
        <div class="payment-options"></div>
        <div class="two-payment-terms" id="two-payment-terms" style="display: none;">
            <div class="two-term-chips">
                <div class="two-term-chips__container" id="two-terms-chips"></div>
                <div class="two-terms-selected"><span id="two-selected-days">30</span></div>
            </div>
        </div>`;
});

afterEach(() => {
    ajax.restore();
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
});

function manager(terms) {
    return new TwoCheckoutManager({
        checkoutHost: CHECKOUT_HOST,
        orderIntentEnabled: false,
        available_payment_terms: terms,
        default_payment_term: 30
    });
}

describe('the config the checkout manager is built with', () => {
    // The manager reads its offered set from here, so a default substituted at
    // this seam is a term offered to a buyer just as surely as one from PHP.
    test.each([
        [undefined, [], 'an absent list stays absent'],
        [[], [], 'an empty list stays empty'],
        [[45], [45], 'a real list is carried through'],
    ])('%p', (terms, expected, description) => {
        loadScript('views/js/twopayment.js');

        const config = window.twoBuildCheckoutManagerConfig({ available_payment_terms: terms });

        expect(config.available_payment_terms).toEqual(expected);
        expect(description).toBeTruthy();
    });
});

describe('the payment-term block against the offered set', () => {
    // [offered set, block revealed, chips drawn, description]
    const cases = [
        [[], false, 0, 'no offered term reveals nothing'],
        [undefined, false, 0, 'an absent list is not an offer either'],
        [[30, 60], true, 2, 'a real offered set draws a chip per term'],
    ];

    test.each(cases)('%p', (terms, revealed, chips, description) => {
        manager(terms).showPaymentTerms();

        const block = document.getElementById('two-payment-terms');
        expect(block.style.display === 'block').toBe(revealed);
        expect(document.querySelectorAll('.two-term-chip').length).toBe(chips);
        expect(description).toBeTruthy();
    });
});
