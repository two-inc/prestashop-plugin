/**
 * ABN-554: order create adds the buyer fee back regardless of what the browser
 * did, so the checkout may only withhold it from the summary where the tile has
 * told this buyer it is refusing - and it must say so to the server, since that
 * recorded refusal is the whole gate. Everything that is not an enumerated
 * refusal - a transport error, the merchant having the preview switched off -
 * leaves the fee the buyer is being shown alone.
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

/** Answer the save/clear the manager chains its fee sync onto, if it made one. */
function settleIntentRecordWrite() {
    const write = ajax.calls.find(
        (call) => call.settings.data
            && ['saveOrderIntentResult', 'clearOrderIntentResult'].includes(call.settings.data.action)
    );
    if (write) {
        write.succeed({ success: true });
    }
}

describe.each([
    [{ success: true, approved: true }, [1], 'an approved intent is the tile genuinely offering Two'],
    [{ success: true, approved: false, message: 'Not available' }, [0], 'a declined company gets no fee'],
    [{ success: false, status: 'no_company', error: 'no company' }, [0], 'no company entered at all'],
    [{ success: false, status: 'incomplete_company', error: 'incomplete' }, [0], 'a company the backend could not resolve'],
    [{ success: false, status: 'buyer_country_not_supported', error: 'not available' }, [0], 'a billing country Two does not serve'],
    [{ success: false, error: 'skipped_no_company' }, [0], 'a legacy skipped check'],
    [{ success: false, status: 'order_intent_disabled', error: 'disabled' }, [], 'the preview switched off is not this buyer being refused'],
    [{ success: false, error: 'Network error' }, [], 'an intent that died in transport says nothing']
])('order intent result %j', (result, expectedSyncs, why) => {
    test('reconciles the fee line to what the tile offers: ' + why, async () => {
        // Given: a checkout manager on the payment step with Two selected.
        const manager = makeManager();

        // When: the order intent answers.
        manager.handleOrderIntentResult(result);
        settleIntentRecordWrite();
        await flushPromises();

        // Then: the fee syncs only where the tile has a verdict to carry.
        expect(surchargeSyncs()).toEqual(expectedSyncs);
    });
});

test('a refused tile records the refusal, rather than forgetting the verdict', async () => {
    const manager = makeManager();

    manager.handleOrderIntentResult({ success: false, status: 'no_company', error: 'no company' });
    const write = ajax.calls.find((call) => call.settings.data.action === 'saveOrderIntentResult');
    settleIntentRecordWrite();
    await flushPromises();

    // A cleared record reads as 'no verdict', which admits the fee again.
    expect(actions()).not.toContain('clearOrderIntentResult');
    expect(write.settings.data.approved).toBe(0);
    expect(surchargeSyncs()).toEqual([0]);
});

test('an intent that errored is not a refusal: fee and approval both stand', async () => {
    // Given: an approved buyer, fee line in the summary.
    const manager = makeManager();
    manager.handleOrderIntentResult({ success: true, approved: true });
    settleIntentRecordWrite();
    await flushPromises();
    expect(surchargeSyncs()).toEqual([1]);
    ajax.calls.length = 0;

    // When: a re-check dies in transport.
    manager.handleOrderIntentResult({ success: false, error: 'Network error' });
    await flushPromises();

    // Then: nothing withdrew the fee the buyer is still looking at, and the
    // server keeps the approval that admits it at order create.
    expect(actions()).toEqual([]);
    expect(surchargeSyncs()).toEqual([]);
});

test('a clear issued behind an in-flight approval waits for it', async () => {
    const manager = makeManager();

    manager.saveOrderIntentResultToServer(true);
    manager.clearOrderIntentResultFromServer();
    await flushPromises();

    // Given: the approval has not answered yet.
    expect(actions()).toEqual(['saveOrderIntentResult']);

    ajax.calls[0].succeed({ success: true });
    await flushPromises();

    // Then: the clear lands after it, never before.
    expect(actions()).toEqual(['saveOrderIntentResult', 'clearOrderIntentResult']);
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
