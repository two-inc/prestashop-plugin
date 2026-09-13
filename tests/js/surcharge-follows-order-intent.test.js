/**
 * ABN-554: the per-term buyer fee belongs to the term chips, and the chips
 * only exist once an order intent comes back approved. A buyer whose company
 * was typed by hand, or declined, sees no chip - and used to be charged the
 * default term's fee anyway, because the fee sync fired on the payment radio
 * alone and never looked at what the tile was actually offering.
 */

'use strict';

const {
    loadCompanySearch,
    loadOrderIntent,
    loadScript,
    releaseWidgets,
    stubAjax,
    flushPromises
} = require('./ps-harness');

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
        checkout_host: CHECKOUT_HOST,
        surcharge_cart_line: true
    };
});

afterEach(async () => {
    // Settle anything still in flight before jsdom goes away: a continuation
    // that runs after teardown fails whichever suite jest is running by then.
    ajax.calls.forEach((call) => {
        if (!call.aborted) {
            try {
                call.fail('abort', 'abort');
            } catch (e) {
                // some call sites wire .done()/.fail() directly - see other suites
            }
        }
    });
    await flushPromises();
    ajax.restore();
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
    jest.restoreAllMocks();
});

function makeManager() {
    const manager = new TwoCheckoutManager({
        checkoutHost: CHECKOUT_HOST,
        orderIntentEnabled: true,
        orderIntentUrl: ORDER_INTENT_URL,
        ajaxToken: 'test-token'
    });
    manager.isTwoPaymentSelected = () => true;
    ajax.calls.length = 0;
    return manager;
}

/** Every syncSurchargeLine POST the manager made, newest last. */
function surchargeSyncs() {
    return ajax.calls
        .filter((call) => call.settings.data && call.settings.data.action === 'syncSurchargeLine')
        .map((call) => call.settings.data.selected);
}

function actions() {
    return ajax.calls.map((call) => (call.settings.data || {}).action);
}

describe.each([
    [{ success: true, approved: true }, 1, 'an approved intent is the tile genuinely offering Two'],
    [{ success: true, approved: false, message: 'Not available' }, 0, 'a declined company gets no fee'],
    [{ success: false, status: 'no_company', error: 'no company' }, 0, 'no company entered at all'],
    [{ success: false, status: 'incomplete_company', error: 'incomplete' }, 0, 'a company the backend could not resolve'],
    [{ success: false, error: 'skipped_no_company' }, 0, 'a legacy skipped check']
])('order intent result %j', (result, expectedSelected, why) => {
    test('reconciles the fee line to what the tile offers: ' + why, async () => {
        // Given: a checkout manager on the payment step with Two selected.
        const manager = makeManager();

        // When: the order intent answers.
        manager.handleOrderIntentResult(result);
        const save = ajax.calls.find(
            (call) => call.settings.data && call.settings.data.action === 'saveOrderIntentResult'
        );
        if (save) {
            save.succeed({ success: true });
        }
        await flushPromises();

        // Then: exactly one fee sync fired, carrying the tile's own verdict.
        expect(surchargeSyncs()).toEqual([expectedSelected]);
    });
});

test('a refused tile also withdraws any approval already recorded server-side', async () => {
    const manager = makeManager();

    manager.handleOrderIntentResult({ success: false, status: 'no_company', error: 'no company' });
    await flushPromises();

    expect(actions()).toContain('clearOrderIntentResult');
    expect(surchargeSyncs()).toEqual([0]);
});

test('the fee sync waits for the approval to reach the server, which gates it', async () => {
    const manager = makeManager();

    manager.handleOrderIntentResult({ success: true, approved: true });

    // Given: the approval POST is still in flight.
    expect(surchargeSyncs()).toEqual([]);

    ajax.calls
        .find((call) => call.settings.data.action === 'saveOrderIntentResult')
        .succeed({ success: true });
    await flushPromises();

    expect(surchargeSyncs()).toEqual([1]);
});
