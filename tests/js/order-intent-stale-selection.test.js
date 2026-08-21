/**
 * TWO-25326: selecting company B while A's intent call is in flight showed A's
 * data.
 *
 * A monotonic `requestSeq`, bumped by every checkOrderIntent() call and by
 * reset(), gates every write a request's promise chain makes
 * (publishPayloadCompany/processResult/handleError) and whether it may call
 * callTwoOrderIntent() at all. Without it, whichever round trip resolves LAST
 * wins regardless of which was started last.
 */

'use strict';

const { loadCompanySearch, loadOrderIntent, flushPromises } = require('./ps-harness');

let TwoOrderIntent;
let intent;

beforeEach(() => {
    jest.resetModules();
    delete global.window.TwoOrderIntent;
    // jQuery on the window for updateUI()'s `$('.payment-option')`; no
    // payment-option markup is built, so it early-returns.
    loadCompanySearch();
    global.window.twopayment = { i18n: {} };
    TwoOrderIntent = loadOrderIntent();
    intent = new TwoOrderIntent({
        enabled: true,
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token'
    });
    jest.spyOn(intent, 'collectFormData').mockImplementation(() => Promise.resolve({}));
});

afterEach(() => {
    delete global.window.twopayment;
    document.body.innerHTML = '';
});

function deferred() {
    let resolve;
    const promise = new Promise((r) => { resolve = r; });
    return { promise, resolve };
}

function buildPayload(companyName, organizationNumber) {
    return {
        payload: {
            buyer: { company: { company_name: companyName, organization_number: organizationNumber } }
        }
    };
}

describe('a superseded selection cannot overwrite a newer one', () => {
    test('company A resolving AFTER company B is discarded, not applied', async () => {
        const forA = deferred();
        const forB = deferred();
        let fetchCall = 0;
        jest.spyOn(intent, 'fetchOrderIntentPayload').mockImplementation(() => {
            fetchCall += 1;
            return fetchCall === 1 ? forA.promise : forB.promise;
        });
        const callTwo = jest.spyOn(intent, 'callTwoOrderIntent')
            .mockImplementation(() => Promise.resolve({ success: true, approved: true, rawResponse: { approved: true } }));

        intent.checkOrderIntent();
        // What TwoCompanySearch.onCompanySelected() does on picking company B:
        // reset() then a fresh call, without waiting for A's to finish.
        intent.reset();
        intent.checkOrderIntent();

        // B's round trip is the faster one and resolves first.
        forB.resolve(buildPayload('Company B', 'BBB'));
        await flushPromises();

        expect(intent.lastCompany).toBe('Company B');
        expect(intent.lastCompanyNumber).toBe('BBB');
        expect(callTwo).toHaveBeenCalledTimes(1);

        forA.resolve(buildPayload('Company A', 'AAA'));
        await flushPromises();

        expect(intent.lastCompany).toBe('Company B');
        expect(intent.lastCompanyNumber).toBe('BBB');
        expect(callTwo).toHaveBeenCalledTimes(1);
    });

    test('isProcessing is released by the CURRENT request, not by a stale one finishing late', async () => {
        const forA = deferred();
        const forB = deferred();
        let fetchCall = 0;
        jest.spyOn(intent, 'fetchOrderIntentPayload').mockImplementation(() => {
            fetchCall += 1;
            return fetchCall === 1 ? forA.promise : forB.promise;
        });
        jest.spyOn(intent, 'callTwoOrderIntent')
            .mockImplementation(() => Promise.resolve({ success: true, approved: true, rawResponse: { approved: true } }));

        intent.checkOrderIntent();
        intent.reset();
        intent.checkOrderIntent();

        // A (stale) resolves first this time.
        forA.resolve(buildPayload('Company A', 'AAA'));
        await flushPromises();
        expect(intent.isProcessing).toBe(true);

        forB.resolve(buildPayload('Company B', 'BBB'));
        await flushPromises();
        expect(intent.isProcessing).toBe(false);
        expect(intent.lastCompany).toBe('Company B');
    });

    test('reset() alone (no immediate re-check) still invalidates the in-flight request', async () => {
        const forA = deferred();
        jest.spyOn(intent, 'fetchOrderIntentPayload').mockImplementation(() => forA.promise);
        const callTwo = jest.spyOn(intent, 'callTwoOrderIntent')
            .mockImplementation(() => Promise.resolve({ success: true, approved: true, rawResponse: { approved: true } }));

        intent.checkOrderIntent();
        intent.reset();

        forA.resolve(buildPayload('Company A', 'AAA'));
        await flushPromises();

        expect(intent.lastCompany).toBeNull();
        expect(callTwo).not.toHaveBeenCalled();
    });
});
