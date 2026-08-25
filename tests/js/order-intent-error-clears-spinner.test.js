/**
 * TWO-25503. An order-intent failure rendered its message UNDER a loading
 * overlay it never took down: showOrderIntentError() cleared only the in-flight
 * flag, so the buyer was left watching an endless spinner with no explanation.
 *
 * Defensive rather than tied to one trigger - "no company id" can arrive from
 * more than one cause, and none of them may end this way.
 */

'use strict';

const {
    loadCompanySearch,
    loadOrderIntent,
    loadScript,
    releaseWidgets
} = require('./ps-harness');

let TwoCheckoutManager;
let $;

function buildPaymentTileWithTwoSelected() {
    document.body.innerHTML = [
        '<div class="payment-options">',
        '  <div class="payment-option" data-module-name="twopayment">',
        "    <input type='radio' name='payment-option' value='twopayment' checked />",
        '    <div class="payment-option-content">',
        '      <div class="two-payment-container">',
        '        <section class="two-payment-info" style="display: none;">',
        '          <p class="two-subtitle"></p>',
        '          <p class="two-payment-message"></p>',
        '        </section>',
        '      </div>',
        '    </div>',
        '  </div>',
        '</div>'
    ].join('\n');
}

beforeEach(() => {
    const loaded = loadCompanySearch();
    $ = loaded.$;
    loadOrderIntent();
    loadScript('views/js/modules/TwoCheckoutManager.js');
    TwoCheckoutManager = window.TwoCheckoutManager;
    window.twopayment = { ajax_token: 'test-token' };
    buildPaymentTileWithTwoSelected();
});

afterEach(() => {
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
    delete window.TwoCheckoutManager_Instance;
});

function overlayIsShowing() {
    const overlay = document.querySelector('#two-loading-overlay');

    return !!overlay && overlay.classList.contains('show');
}

describe('an order-intent failure never leaves the spinner running', () => {
    const cases = [
        ['showOrderIntentError', 'Payload build failed', 'a failed check'],
        ['showOrderIntentDecline', 'Not available', 'a decline'],
        ['showOrderIntentApproval', 'Approved', 'an approval']
    ];

    test.each(cases)('%s takes the overlay down (%s)', (method, message) => {
        const manager = new TwoCheckoutManager({
            orderIntentEnabled: true,
            companySearchInAddressArea: true
        });
        manager.showOrderIntentLoading();
        expect(overlayIsShowing()).toBe(true);

        manager[method](message);

        expect(overlayIsShowing()).toBe(false);
        expect(manager.isLoadingUIShown).toBe(false);
    });

    test('the buyer is told what happened, not left with an empty tile', () => {
        const manager = new TwoCheckoutManager({
            orderIntentEnabled: true,
            companySearchInAddressArea: true
        });
        manager.showOrderIntentLoading();

        manager.showOrderIntentError('Payload build failed');

        const message = document.querySelector('.two-payment-message');
        expect(message.textContent.trim().length).toBeGreaterThan(0);
    });
});
