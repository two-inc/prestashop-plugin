/**
 * TWO-25326. Doug found this running a real checkout: select company A ->
 * a correct intent call fires for A. Search again and select a DIFFERENT
 * company B before A's call has returned -> the SECOND intent call ends up
 * showing A's data, not B's.
 *
 * TwoCompanySearch.onCompanySelected() reacts to a fresh selection by
 * calling TwoOrderIntent.reset() (clears lastResult/lastCompany and forces
 * isProcessing back to false so the new selection is never blocked by the
 * old request's mutex) and then immediately starting a new
 * checkOrderIntent() call. Before this fix that reset-and-restart had no way
 * to stop the ORIGINAL request's promise chain from still running - so
 * whichever of the two network round trips happened to resolve LAST won,
 * regardless of which one was actually started last. A slow decision for A
 * arriving after a fast (or deduped) decision for B silently overwrote B's
 * already-rendered result with A's.
 *
 * These tests pin the fix: a monotonic `requestSeq`, bumped by every
 * checkOrderIntent() call and by reset() itself, gates every write a
 * request's promise chain makes (publishPayloadCompany/processResult/
 * handleError) and whether it is even allowed to call callTwoOrderIntent()
 * at all. `collectFormData` and the two intent legs are mocked directly so
 * these tests exercise exactly the sequencing logic, not $.ajax plumbing
 * already covered elsewhere.
 */

'use strict';

const { loadCompanySearch, loadOrderIntent, flushPromises } = require('./ps-harness');

let TwoOrderIntent;
let intent;

beforeEach(() => {
    jest.resetModules();
    delete global.window.TwoOrderIntent;
    // jQuery on the window, for updateUI()'s `$('.payment-option')` - no
    // payment-option markup is built, so it early-returns without touching
    // anything.
    loadCompanySearch();
    global.window.twopayment = { i18n: {} };
    TwoOrderIntent = loadOrderIntent();
    intent = new TwoOrderIntent({
        enabled: true,
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token'
    });
    // Isolates the sequencing logic under test from collectFormData()'s own
    // DOM/ajax plumbing (covered by other tests) - every call resolves
    // immediately with an empty payload placeholder.
    jest.spyOn(intent, 'collectFormData').mockImplementation(() => Promise.resolve({}));
});

afterEach(() => {
    delete global.window.twopayment;
    document.body.innerHTML = '';
});

/** A promise plus its resolver, so a test can decide exactly when each of two overlapping calls settles. */
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

        // Select company A.
        intent.checkOrderIntent();
        // Buyer searches again and picks company B - exactly what
        // TwoCompanySearch.onCompanySelected() does: reset() then a fresh
        // checkOrderIntent() call, without waiting for A's call to finish.
        intent.reset();
        intent.checkOrderIntent();

        // B's network round trip is the FASTER one here (e.g. a decision-cache
        // hit) and resolves first.
        forB.resolve(buildPayload('Company B', 'BBB'));
        await flushPromises();

        expect(intent.lastCompany).toBe('Company B');
        expect(intent.lastCompanyNumber).toBe('BBB');
        expect(callTwo).toHaveBeenCalledTimes(1);

        // A's slower round trip finally lands - it must be dropped silently,
        // never re-publishing A over B's already-rendered result.
        forA.resolve(buildPayload('Company A', 'AAA'));
        await flushPromises();

        expect(intent.lastCompany).toBe('Company B');
        expect(intent.lastCompanyNumber).toBe('BBB');
        // The stale request must never reach the real Two API call at all.
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

        // A (stale) resolves first this time - it must not flip isProcessing
        // to false while B is still genuinely in flight.
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

        // reset() cleared lastCompany; the stale request landing afterwards
        // must not resurrect it.
        expect(intent.lastCompany).toBeNull();
        expect(callTwo).not.toHaveBeenCalled();
    });
});
