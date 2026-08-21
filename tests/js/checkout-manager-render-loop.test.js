/**
 * TWO-25326: duplicate payment-option listeners multiplying tile re-renders.
 *
 * setupPaymentOptionSelectionListener() binds change/click/submit to `document`
 * by delegation, so those listeners survive any number of `.payment-options`
 * fragment replacements and must never be re-attached. Re-running it from
 * handleDynamicContentChange() adds a permanent duplicate set on every
 * MutationObserver firing that cleanup() cannot remove - they are anonymous
 * closures with no retained reference to un-register.
 */

'use strict';

const { loadCompanySearch, loadOrderIntent, loadScript, releaseWidgets, stubAjax, flushPromises } = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';

let TwoCheckoutManager;
let $;
let ajax;

function buildAddressModePaymentStep() {
    document.body.innerHTML = [
        '<div class="payment-options">',
        '  <div class="payment-option" data-module-name="twopayment">',
        "    <input type='radio' name='payment-option' value='twopayment' id='two-radio' />",
        '  </div>',
        '  <div class="payment-option" data-module-name="othermethod">',
        "    <input type='radio' name='payment-option' value='othermethod' id='other-radio' checked />",
        '  </div>',
        '</div>'
    ].join('\n');
}

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

    buildAddressModePaymentStep();
});

afterEach(async () => {
    // Resolve outstanding AJAX so nothing logs after its test has finished.
    ajax.calls.forEach((call) => {
        if (call.aborted) {
            return;
        }
        try {
            call.fail('abort', 'abort');
        } catch (e) {
            // Some call sites wire `.done()/.fail()` on the jqXHR promise rather
            // than settings.error, which this minimal stub does not simulate.
        }
    });
    await flushPromises();
    ajax.restore();
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
    delete window.TwoCheckoutManager_Instance;
});

function makeManager() {
    const manager = new TwoCheckoutManager({
        checkoutHost: CHECKOUT_HOST,
        orderIntentEnabled: true,
        orderIntentUrl: ORDER_INTENT_URL,
        ajaxToken: 'test-token',
        companySearchInAddressArea: true
    });
    window.TwoCheckoutManager_Instance = manager;
    return manager;
}

test('one radio change fires handlePaymentOptionChange exactly once, however many times the DOM-settle path already ran', () => {
    const manager = makeManager();
    const spy = jest.spyOn(manager, 'handlePaymentOptionChange');

    // PrestaShop replacing `.payment-options` repeatedly while the step settles.
    manager.handleDynamicContentChange();
    manager.handleDynamicContentChange();
    manager.handleDynamicContentChange();

    document.getElementById('two-radio').checked = true;
    document.getElementById('two-radio').dispatchEvent(new Event('change', { bubbles: true }));

    expect(spy).toHaveBeenCalledTimes(1);
});

test('handleDynamicContentChange() never flips _paymentListenersAttached back off', () => {
    const manager = makeManager();
    expect(manager._paymentListenersAttached).toBe(true);

    manager.handleDynamicContentChange();

    expect(manager._paymentListenersAttached).toBe(true);
});

test('handleDynamicContentChange() does not leak an extra Method-5 selection-check interval', () => {
    const manager = makeManager();
    const intervalAfterInit = manager._selectionCheckInterval;
    expect(intervalAfterInit).not.toBeNull();

    manager.handleDynamicContentChange();
    manager.handleDynamicContentChange();

    // setupPaymentOptionSelectionListener() is the only place a new one is made.
    expect(manager._selectionCheckInterval).toBe(intervalAfterInit);
});

test('switching away from Two after repeated DOM-settle firings still only reacts once', () => {
    const manager = makeManager();
    const spy = jest.spyOn(manager, 'handlePaymentOptionChange');

    document.getElementById('two-radio').checked = true;
    document.getElementById('two-radio').dispatchEvent(new Event('change', { bubbles: true }));
    spy.mockClear();

    manager.handleDynamicContentChange();
    manager.handleDynamicContentChange();

    document.getElementById('other-radio').checked = true;
    document.getElementById('two-radio').checked = false;
    document.getElementById('other-radio').dispatchEvent(new Event('change', { bubbles: true }));

    expect(spy).toHaveBeenCalledTimes(1);
});
