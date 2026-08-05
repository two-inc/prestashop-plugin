/**
 * TWO-25326. Doug's repro on a real checkout: the Two payment tile rendered
 * and was immediately removed once before any selection, 3-5 times in a
 * rapid cycle on first selecting Two, twice on switching to a different
 * method, and once on re-selecting Two. Varying counts, never zero - the
 * signature of multiple redundant listeners each independently reacting to
 * the same underlying change, not of one broken listener.
 *
 * Root cause: handleDynamicContentChange() - run by setupMutationObserver()'s
 * debounced callback every time PrestaShop replaces the `.payment-options`
 * fragment while the checkout step settles - used to force
 * `_paymentListenersAttached = false` and call
 * setupPaymentOptionSelectionListener() again. That method binds its
 * change/click/submit listeners to `document` (event delegation), so they
 * already keep matching elements inside any number of DOM replacements
 * without ever needing to be re-attached; re-running it anyway added a
 * brand new, permanent set of duplicate document-level listeners on every
 * firing; nothing in cleanup() can remove them afterwards (they are
 * anonymous closures - no reference is ever kept to un-register).
 * handlePaymentOptionChange() calls syncSurchargeCartLine(), which can
 * trigger a full payment-step reload (triggerNativeCartRefresh()) - so a
 * single radio change ends up invoking it once per accumulated duplicate,
 * each one independently racing a reload. That reload is what Doug saw as
 * the tile being removed and re-rendered several times in a row.
 *
 * These tests pin: (1) a single user action still triggers the handler
 * exactly once no matter how many times the DOM-settle path has already run,
 * and (2) the listener-count/isProcessing/interval-leak invariants that
 * regression would reintroduce.
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
    // handlePaymentOptionChange() fires syncSurchargeCartLine()/
    // clearOrderIntentResultFromServer() AJAX side-effects this suite is not
    // about - resolve whatever is still outstanding so nothing logs after
    // the test that started it has already finished.
    ajax.calls.forEach((call) => {
        if (call.aborted) {
            return;
        }
        try {
            call.fail('abort', 'abort');
        } catch (e) {
            // This suite is about listener/render-loop counts, not about
            // syncSurchargeCartLine()'s own AJAX plumbing (covered
            // elsewhere) - some of its call sites wire up `.done()/.fail()`
            // on the jqXHR promise rather than passing settings.error, which
            // this minimal stub does not simulate. Either way the point
            // here is just to unstick anything left pending before the test
            // ends.
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

    // Simulates PrestaShop replacing the `.payment-options` fragment several
    // times in a row while the checkout step settles - each one debounced
    // through the MutationObserver into exactly this call, before the buyer
    // has clicked anything.
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

    // Same interval handle - handleDynamicContentChange() must not call
    // setupPaymentOptionSelectionListener() again, which is the only place a
    // new one is ever created.
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
