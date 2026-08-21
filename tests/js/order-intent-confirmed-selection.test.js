/**
 * TWO-25326 bug 8, second attempt. Doug re-tested the shipped fix live and the
 * defect was still there: search a company -> intent fires for it (correct);
 * search again, select a DIFFERENT company -> the intent fires for the FIRST
 * one.
 *
 * order-intent-stale-selection.test.js pins a `requestSeq` gate against a slow
 * response overwriting a fast one, but mocks `collectFormData` out entirely -
 * it can't see WHICH COMPANY the request was built for, which is where the
 * staleness lives.
 *
 * Root cause: in tile mode collectFormData() reads nothing from the
 * address-area DOM (by design, §7.1) and falls through to a `getCompany`
 * round trip answered from the SESSION COOKIE. That cookie is written by
 * TwoCompanySearch.persistCompanyToCookie()'s fire-and-forget `saveCompany`
 * request, fired the SAME TICK as the intent check - so the cookie the
 * request carries is whatever the browser had BEFORE this selection: empty
 * on the first search, the PREVIOUS company on every one after.
 *
 * So these tests run the opposite way round from the existing ones:
 * collectFormData is REAL, `$.ajax` stubs `getCompany` with a stale cookie
 * exactly as the server would, and assertions are on the company the request
 * carries.
 */

'use strict';

const {
    loadCompanySearch,
    loadOrderIntent,
    loadSoleTrader,
    loadScript,
    stubAjax,
    flushPromises
} = require('./ps-harness');

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
    /**
     * Every test in this block asserts a POSITIVE control alongside the
     * invalidated case (review round 1 finding: these were vacuous). Expecting
     * only the cookie's value cannot distinguish "correctly invalidated" from
     * "the shortcut was never consulted at all" - both produce it. Each test
     * therefore runs the same scenario twice, once with the invalidating
     * condition and once without, and requires the two to DIFFER.
     */
    test('a pending country change discards it - and without the flag it is used', async () => {
        const store = managerStore();
        store.set('Company B', 'BBB');

        sessionStorage.setItem('two_country_changed', '1');
        const invalidated = await collect(buildIntent({ getConfirmedCompany: () => store.get() }), null);
        // Country change invalidates a captured company outright: neither the
        // selection nor the cookie may supply one.
        expect(invalidated.company).toBe('');
        expect(invalidated.companyid).toBe('');

        // Positive control: same setup, no flag.
        sessionStorage.removeItem('two_country_changed');
        const used = await collect(buildIntent({ getConfirmedCompany: () => store.get() }), null);
        expect(used.company).toBe('Company B');
    });

    test('a selection captured against a DIFFERENT address is discarded - matching one is used', async () => {
        document.body.innerHTML = "<input type='radio' name='id_address_invoice' value='9' checked />";
        const store = managerStore();

        store.set('Company B', 'BBB', 4);
        const invalidated = await collect(buildIntent({ getConfirmedCompany: () => store.get() }), null);
        // Address 4's company must never be credit-checked against address 9.
        expect(invalidated.company).toBe('');

        // Positive control: the same selection captured against address 9.
        store.set('Company B', 'BBB', 9);
        const used = await collect(buildIntent({ getConfirmedCompany: () => store.get() }), null);
        expect(used.company).toBe('Company B');
    });

    test('a selection captured under a DIFFERENT country is discarded - matching one is used', async () => {
        document.body.innerHTML = "<select name='id_country'>"
            + '<option value="17" data-iso-code="NO" selected>Norway</option>'
            + '</select>';
        const store = managerStore();

        store.selection = { company: 'Company B', companyid: 'BBB', addressId: 0, countryIso: 'SE' };
        const invalidated = await collect(buildIntent({ getConfirmedCompany: () => store.get() }), null);
        // The cookie path drops a stored company whose country disagrees with
        // the address; this shortcut must not be a weaker guard than the path
        // it replaces.
        expect(invalidated.company).toBe('');

        store.selection = { company: 'Company B', companyid: 'BBB', addressId: 0, countryIso: 'NO' };
        const used = await collect(buildIntent({ getConfirmedCompany: () => store.get() }), null);
        expect(used.company).toBe('Company B');
    });

    test('a half-written pair (name but no number) is not usable - a complete one is', async () => {
        const store = managerStore();

        store.selection = { company: 'Company B', companyid: '', addressId: 0 };
        const invalidated = await collect(buildIntent({ getConfirmedCompany: () => store.get() }), { company: 'Company A', companyid: 'AAA' });
        // Falls through to the cookie rather than shipping a name with no
        // number, which the server would treat as an incomplete company.
        expect(invalidated.company).toBe('Company A');

        store.set('Company B', 'BBB');
        const used = await collect(buildIntent({ getConfirmedCompany: () => store.get() }), { company: 'Company A', companyid: 'AAA' });
        expect(used.company).toBe('Company B');
    });

    test('a getter that throws cannot break the intent check', async () => {
        const intent = buildIntent({ getConfirmedCompany: () => { throw new Error('boom'); } });
        const data = await collect(intent, { company: 'Company A', companyid: 'AAA' });
        expect(data.company).toBe('Company A');
    });
});

describe('the REAL TwoCheckoutManager store, not a stand-in', () => {
    /**
     * Review round 1 found the manager half of this fix entirely unverified:
     * gutting setConfirmedCompanySelection() and deleting the getter injection
     * left all 401 tests passing, because every other test in this file
     * substitutes a hand-written store. These tests run the real methods, on a
     * real instance, and read the result through the intent module's own
     * config - so the injection, the capture of the address/country context and
     * the clear paths are all executed.
     */
    let TwoCheckoutManager;

    function buildManager(config) {
        loadScript('views/js/modules/TwoCheckoutManager.js');
        TwoCheckoutManager = global.window.TwoCheckoutManager;
        const manager = new TwoCheckoutManager(Object.assign({
            orderIntentEnabled: true,
            orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
            ajaxToken: 'test-token',
            companySearchInAddressArea: false
        }, config || {}));
        global.window.TwoCheckoutManager_Instance = manager;
        return manager;
    }

    afterEach(() => {
        if (global.window.TwoCheckoutManager_Instance
            && typeof global.window.TwoCheckoutManager_Instance.cleanup === 'function') {
            global.window.TwoCheckoutManager_Instance.cleanup();
        }
    });

    test('the getter injected into TwoOrderIntent reads the real store', async () => {
        document.body.innerHTML = "<input type='radio' name='id_address_invoice' value='5' checked />"
            + "<select name='id_country'><option value='17' data-iso-code='NO' selected>Norway</option></select>";
        const manager = buildManager();
        manager.initializeOrderIntent();
        expect(manager.orderIntent).toBeTruthy();

        manager.setConfirmedCompanySelection({ company: 'Company B', companyid: 'BBB' });

        // Through the module's own config getter - the wiring under test.
        expect(manager.orderIntent.getConfirmedCompanySelection())
            .toEqual({ company: 'Company B', companyid: 'BBB' });
        // And the context really was captured, not defaulted.
        expect(manager.getConfirmedCompanySelection())
            .toEqual({ company: 'Company B', companyid: 'BBB', addressId: 5, countryIso: 'NO' });

        const data = await collect(manager.orderIntent, { company: 'Company A', companyid: 'AAA' });
        expect(data.company).toBe('Company B');
    });

    test('an empty pair clears the real store rather than half-writing it', () => {
        const manager = buildManager();
        manager.setConfirmedCompanySelection({ company: 'Company B', companyid: 'BBB' });
        manager.setConfirmedCompanySelection({ company: '', companyid: '' });
        expect(manager.getConfirmedCompanySelection()).toBeNull();
    });

    test('the manager and the intent module resolve the SAME address id', () => {
        // A page with no checked radio, an open edit form, and hidden inputs -
        // the shape on which two independently-written resolutions disagree and
        // silently discard a valid selection.
        document.body.innerHTML = "<div class='js-address-form'><form data-id-address='7'></form></div>"
            + "<input type='hidden' name='id_address_invoice' value='3' />";
        const manager = buildManager();
        manager.initializeOrderIntent();
        expect(manager.getSelectedAddressId()).toBe(manager.orderIntent.getCurrentAddressId());
    });

    test('an address-form update in tile mode drops the selection', () => {
        document.body.innerHTML = "<select name='id_country'>"
            + "<option value='17' data-iso-code='NO' selected>Norway</option></select>";
        const manager = buildManager();
        manager.setConfirmedCompanySelection({ company: 'Company B', companyid: 'BBB' });

        manager.handleAddressFormUpdate();

        // Neither invalidation guard can see a country change made at the
        // address step in tile mode (both context values are unresolvable at
        // the payment step where the selection was captured), so the buyer
        // being in the address form at all has to drop it.
        expect(manager.getConfirmedCompanySelection()).toBeNull();
    });

    test('an address-form event with NO address form present keeps the selection', () => {
        document.body.innerHTML = '<div class="payment-options"></div>';
        const manager = buildManager();
        manager.setConfirmedCompanySelection({ company: 'Company B', companyid: 'BBB' });

        manager.handleAddressFormUpdate();

        // PrestaShop emits this event for ordinary things, and it can land after
        // an unrelated click on the payment step - an unconditional clear there
        // would put the original bug straight back.
        expect(manager.getConfirmedCompanySelection()).toEqual(
            expect.objectContaining({ company: 'Company B', companyid: 'BBB' })
        );
    });
});

describe('the sole-trader flow publishes to the same store', () => {
    test('enrolling republishes the selection so an earlier company cannot be posted', async () => {
        const published = [];
        global.window.TwoCheckoutManager_Instance = {
            setConfirmedCompanySelection: (selection) => published.push(selection)
        };
        global.window.fetch = () => Promise.resolve({ json: () => Promise.resolve({ success: true }) });
        global.fetch = global.window.fetch;

        const TwoSoleTrader = loadSoleTrader();
        const soleTrader = Object.create(TwoSoleTrader.prototype);
        soleTrader.config = {
            orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
            ajaxToken: 'test-token',
            i18n: {}
        };
        soleTrader.tokens = { country: 'NO' };
        soleTrader.showStatus = () => {};
        soleTrader.hidePrompt = () => {};
        soleTrader.stopObserving = () => {};

        soleTrader.applyBuyer({ company_name: 'Sole Trader AS', organization_number: 'TWO:ST777' });
        await flushPromises();

        // Without this, a buyer who picked a registered company first and then
        // enrolled would have the intent check keep posting that earlier
        // company - and the endpoint re-stores what it is posted, overwriting
        // the sole-trader record the ORDER payload reads.
        expect(published[published.length - 1])
            .toEqual({ company: 'Sole Trader AS', companyid: 'TWO:ST777' });

        delete global.window.fetch;
        delete global.fetch;
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
