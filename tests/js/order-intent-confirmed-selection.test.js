/**
 * TWO-25326 bug 8, second attempt. Doug re-tested the shipped fix live and the
 * defect was still there: search a company -> intent fires for it (correct);
 * search again, select a DIFFERENT company -> the intent fires for the FIRST
 * one.
 *
 * WHY THE EXISTING TESTS DID NOT CATCH IT
 * order-intent-stale-selection.test.js pins a monotonic `requestSeq` gate that
 * stops a slow response for company A overwriting a fast one for company B.
 * That gate is correct and stays. It is also the wrong layer: it can only
 * choose between two answers that are already in flight. Those tests mock
 * `collectFormData` out entirely (`() => Promise.resolve({})`), so the one
 * thing they cannot see is WHICH COMPANY THE REQUEST WAS BUILT FOR - and that
 * is where the staleness actually lives.
 *
 * THE ROOT CAUSE
 * In tile mode collectFormData() reads nothing from the address-area DOM (by
 * design, §7.1) and therefore always falls through to the `getCompany` round
 * trip, which answers from the SESSION COOKIE. That cookie is written by
 * TwoCompanySearch.persistCompanyToCookie()'s fire-and-forget `saveCompany`
 * request, and the intent check is fired in the SAME TICK - so the cookie the
 * `getCompany` request carries is the one the browser had before this
 * selection: empty on the first search (the server's own fallbacks repair
 * that, which is why cycle one looked fine) and the PREVIOUS company on every
 * search after it. The request that fires for company A is, at that layer,
 * entirely in-order and current. No response-sequencing gate can see it.
 *
 * So these tests are deliberately built the opposite way round from the
 * existing ones: collectFormData is REAL, `$.ajax` is stubbed to answer
 * `getCompany` with a stale cookie exactly as the server would, and the
 * assertions are on the company the request carries.
 */

'use strict';

const { loadCompanySearch, loadOrderIntent, stubAjax, flushPromises } = require('./ps-harness');

let TwoOrderIntent;
let $;
let ajax;

/** A stand-in for TwoCheckoutManager's confirmed-selection store. */
function managerStore() {
    const store = { selection: null };
    store.set = (company, companyid, addressId) => {
        store.selection = { company: company, companyid: companyid, addressId: addressId || 0 };
    };
    store.clear = () => { store.selection = null; };
    store.get = () => store.selection;
    return store;
}

function buildIntent(options) {
    const opts = options || {};
    return new TwoOrderIntent({
        enabled: true,
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        // Tile mode: the address-area DOM fields are not a trustworthy source,
        // so collectFormData() must resolve the company some other way. This is
        // the mode the bug is in.
        companySearchInAddressArea: false,
        getConfirmedCompany: opts.getConfirmedCompany
    });
}

/**
 * Run collectFormData() with `$.ajax` answering any `getCompany` call with
 * `cookie` - i.e. with whatever the browser's cookie held when the request was
 * SENT, which is the crux of the bug.
 *
 * @param {Object} intent
 * @param {?Object} cookie {company, companyid, country, address_id} or null for
 *        "no company in the cookie yet"
 * @returns {Promise<Object>} the form data the intent request would carry
 */
async function collect(intent, cookie) {
    const promise = intent.collectFormData(intent.requestSeq);
    await flushPromises();
    const call = ajax.calls.find((record) => record.settings.data
        && record.settings.data.action === 'getCompany'
        && !record.answered);
    if (call) {
        call.answered = true;
        call.succeed(cookie
            ? {
                success: true,
                company: cookie.company,
                companyid: cookie.companyid,
                country: cookie.country || '',
                address_id: cookie.address_id || 0
            }
            : { success: true, company: '', companyid: '', country: '', address_id: 0 });
    }
    await flushPromises();
    return promise;
}

beforeEach(() => {
    jest.resetModules();
    delete global.window.TwoOrderIntent;
    const loaded = loadCompanySearch();
    $ = loaded.$;
    global.window.twopayment = {
        i18n: {},
        order_intent_url: 'https://shop.example.test/module/twopayment/orderintent',
        ajax_token: 'test-token'
    };
    TwoOrderIntent = loadOrderIntent();
    ajax = stubAjax($);
});

afterEach(() => {
    ajax.restore();
    delete global.window.twopayment;
    delete global.window.TwoCheckoutManager_Instance;
    document.body.innerHTML = '';
    try { sessionStorage.clear(); } catch (e) { /* jsdom always has it */ }
});

describe('the SECOND search-and-select cycle', () => {
    test('the intent request carries company B, not the cookie\'s stale company A', async () => {
        const store = managerStore();
        const intent = buildIntent({ getConfirmedCompany: () => store.get() });

        // ---- cycle 1: the buyer picks company A ----
        store.set('Company A', 'AAA');
        // The cookie is still EMPTY here: `saveCompany` for A has been sent but
        // its response has not come back.
        const first = await collect(intent, null);
        expect(first.company).toBe('Company A');
        expect(first.companyid).toBe('AAA');

        // ---- cycle 2: the buyer searches again and picks company B ----
        // The cookie has by now caught up with cycle 1 and holds company A -
        // which is precisely what the `getCompany` request issued alongside B's
        // selection reads back.
        store.set('Company B', 'BBB');
        const second = await collect(intent, { company: 'Company A', companyid: 'AAA' });

        expect(second.company).toBe('Company B');
        expect(second.companyid).toBe('BBB');
        // And the tile's own sentence must name B too, not A.
        expect(intent.lastCompany).toBe('Company B');
        expect(intent.lastCompanyNumber).toBe('BBB');
    });

    test('WITHOUT the confirmed selection the stale cookie is what the request carries', async () => {
        // The pre-fix code path, kept as an executable record of the defect:
        // with nothing but the cookie to go on, cycle two builds its request
        // for company A. If this ever starts returning 'Company B', the cookie
        // read has stopped being one selection behind and the fix above is no
        // longer load-bearing - which is a finding, not a pass.
        const intent = buildIntent({});
        const second = await collect(intent, { company: 'Company A', companyid: 'AAA' });
        expect(second.company).toBe('Company A');
    });

    test('no getCompany round trip is made at all once a selection is confirmed', async () => {
        const store = managerStore();
        store.set('Company B', 'BBB');
        const intent = buildIntent({ getConfirmedCompany: () => store.get() });

        await collect(intent, null);

        const cookieReads = ajax.calls.filter((record) => record.settings.data
            && record.settings.data.action === 'getCompany');
        // Not merely an optimisation: the round trip is the thing that can
        // answer with a stale company, so the fix is only complete if it is not
        // consulted.
        expect(cookieReads).toHaveLength(0);
    });
});

describe('the confirmed selection is still subject to every existing invalidation', () => {
    test('a pending country change discards it and falls back to the cookie path', async () => {
        const store = managerStore();
        store.set('Company B', 'BBB');
        const intent = buildIntent({ getConfirmedCompany: () => store.get() });
        sessionStorage.setItem('two_country_changed', '1');

        const data = await collect(intent, null);

        // Country change invalidates a captured company outright: neither the
        // selection nor the cookie may supply one.
        expect(data.company).toBe('');
        expect(data.companyid).toBe('');
    });

    test('a selection captured against a DIFFERENT address is discarded', async () => {
        document.body.innerHTML = "<input type='radio' name='id_address_invoice' value='9' checked />";
        const store = managerStore();
        store.set('Company B', 'BBB', 4);
        const intent = buildIntent({ getConfirmedCompany: () => store.get() });

        const data = await collect(intent, null);

        // Address 4's company must never be credit-checked against address 9.
        expect(data.company).toBe('');
    });

    test('a half-written pair (name but no number) is not usable', async () => {
        const store = managerStore();
        store.selection = { company: 'Company B', companyid: '', addressId: 0 };
        const intent = buildIntent({ getConfirmedCompany: () => store.get() });

        const data = await collect(intent, { company: 'Company A', companyid: 'AAA' });

        // Falls through to the cookie rather than shipping a name with no
        // number, which the server would treat as an incomplete company.
        expect(data.company).toBe('Company A');
    });

    test('a getter that throws cannot break the intent check', async () => {
        const intent = buildIntent({ getConfirmedCompany: () => { throw new Error('boom'); } });
        const data = await collect(intent, { company: 'Company A', companyid: 'AAA' });
        expect(data.company).toBe('Company A');
    });
});

describe('TwoCompanySearch publishes the selection the intent check reads', () => {
    test('picking a result records it on the manager BEFORE the recheck is triggered', () => {
        const calls = [];
        const store = managerStore();
        global.window.TwoCheckoutManager_Instance = {
            setConfirmedCompanySelection: (selection) => {
                calls.push(selection);
                store.set(selection.company, selection.companyid);
            },
            isTwoPaymentSelected: () => true,
            triggerOrderIntentForSelection: () => {
                // Ordering is the whole point: by the time anything can build a
                // payload, the selection must already be published.
                calls.push({ triggered: store.get() });
            },
            orderIntent: { reset: () => {} }
        };

        document.body.innerHTML = "<input type='text' name='company' value='' />"
            + "<input type='hidden' name='companyid' value='' />";
        const TwoCompanySearch = global.window.TwoCompanySearch;
        const search = new TwoCompanySearch({ companyFieldSelector: "input[name='company']" });

        search.onCompanySelected(null, { item: { value: 'Company B', organization_number: 'BBB' } });

        expect(calls[0]).toEqual({ company: 'Company B', companyid: 'BBB' });
        expect(calls[calls.length - 1].triggered).toEqual(
            expect.objectContaining({ company: 'Company B', companyid: 'BBB' })
        );
    });

    test('clearing the selection clears the published copy too', () => {
        const published = [];
        global.window.TwoCheckoutManager_Instance = {
            setConfirmedCompanySelection: (selection) => published.push(selection)
        };

        document.body.innerHTML = "<input type='text' name='company' value='Company B' />"
            + "<input type='hidden' name='companyid' value='BBB' />";
        const TwoCompanySearch = global.window.TwoCompanySearch;
        const search = new TwoCompanySearch({ companyFieldSelector: "input[name='company']" });

        search.clearSelectedCompany();

        // An empty pair, which setConfirmedCompanySelection() treats as a clear.
        // Without this, clearing the cookie while leaving the in-memory copy
        // would keep credit-checking a company the buyer has moved off - and
        // would now do it in PREFERENCE to the cleared cookie.
        expect(published[published.length - 1]).toEqual({ company: '', companyid: '' });
    });
});
