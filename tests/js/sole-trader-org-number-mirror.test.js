/**
 * TWO-40: a completed sole-trader enrolment mirrors its organisation number
 * into the address identification field, and does nothing else.
 *
 * Nothing listened to `two:sole-trader-ready` before this ticket. A wider
 * adoption - the trading name into the visible `company` field, plus a
 * publish/cookie backstop - was built and withdrawn (see `.ai/decisions.md`),
 * so what these cases pin is deliberately narrow:
 *
 *  - the gate is the merchant's "Autofill company address" setting
 *    (PS_TWO_ADDRESS_LOOKUP, surfaced as `addressLookupEnabled`), reached
 *    through writeOrganizationToAddressIdentifiers(). NOT where company search
 *    happens to be mounted: the setting being forced off in the payment tile
 *    today is a coincidence and must not be relied on.
 *  - a synthetic internal identifier never lands in `dni`, because PrestaShop
 *    saves that field onto the address and can print it.
 *  - no `input`/`change` cascade, so clearStaleOrganizationSelection() - and
 *    its `clearCompany` request - never fires off the back of this.
 */

'use strict';

const {
    loadCompanySearch,
    loadScript,
    buildAddressForm,
    releaseWidgets,
    stubAjax,
    flushPromises
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';

let TwoCompanySearch;
let $;

/**
 * Every instance built here is registered so afterEach can destroy it whatever
 * happened. `document` is shared for the whole file, so an instance left
 * listening because an assertion threw first answers a LATER test's dispatch -
 * which is exactly how a detached form got written to during review.
 */
let instances;

function track(search) {
    instances.push(search);
    return search;
}

/** Fire the event exactly as TwoSoleTrader.applyBuyer() does on completion. */
function dispatchReady(detail) {
    document.dispatchEvent(new window.CustomEvent('two:sole-trader-ready', { detail: detail }));
}

function companyValue() {
    return document.querySelector("input[name='company']").value;
}

function dniValue() {
    return document.querySelector("input[name='dni']").value;
}

beforeEach(() => {
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    instances = [];
});

afterEach(() => {
    instances.forEach((search) => {
        try {
            search.destroy();
        } catch (e) {
            // A test that already tore it down must not fail here.
        }
    });
    instances = [];
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
    delete global.fetch;
    delete global.window.fetch;
    delete global.window.TwoSoleTrader_Instance;
    delete global.window.TwoCheckoutManager_Instance;
});

describe('the organisation number reaches the address identification field', () => {
    test('with the autofill setting ON, the number lands in dni and nowhere else', () => {
        buildAddressForm({ country: 'GB' });
        track(new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            addressLookupEnabled: true,
            companyFieldSelector: "input[name='company']"
        }));

        dispatchReady({ company: 'Sole Trader AS', companyid: '923456789' });

        expect(dniValue()).toBe('923456789');
        // Never the VAT field - an organisation number is not a VAT number, and
        // a non-empty vat_number on a foreign address zeroes the tax.
        expect(document.querySelector("input[name='vat_number']").value).toBe('');
        // And deliberately NOT the visible company field: writing it is what
        // the withdrawn adoption did, and every defect traced back to it.
        expect(companyValue()).toBe('');
    });

    /**
     * THE load-bearing gate assertion. Deleting the
     * `isAddressLookupEnabled()` check at the top of
     * writeOrganizationToAddressIdentifiers(), or routing this handler around
     * that writer, has to fail here.
     */
    test('with the autofill setting OFF, dni is left completely alone', () => {
        buildAddressForm({ country: 'GB' });
        track(new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            addressLookupEnabled: false,
            companyFieldSelector: "input[name='company']"
        }));

        dispatchReady({ company: 'Sole Trader AS', companyid: '923456789' });

        expect(dniValue()).toBe('');
        expect(companyValue()).toBe('');
    });

    /**
     * A sole trader with no registered number enrols under a SYNTHETIC internal
     * identifier, and the event's `company` label falls back to it. PrestaShop
     * saves `dni` onto the address and can print it, so that value must not
     * land there any more than in a field the buyer sees.
     */
    test('a synthetic internal identifier never reaches dni', () => {
        buildAddressForm({ country: 'GB' });
        track(new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            addressLookupEnabled: true,
            companyFieldSelector: "input[name='company']"
        }));

        // Exactly what applyBuyer() dispatches for a buyer with no registered
        // number: the label IS the internal identifier.
        const internal = window.TwoCompanyNumber.INTERNAL_PREFIX + 'ST123456';
        dispatchReady({ company: internal, companyid: internal });

        expect(dniValue()).toBe('');
        expect(companyValue()).toBe('');
    });

    /**
     * The regression guard for the defect that killed the wider adoption: the
     * visible company write announced `input`, whose handler ran the
     * stale-selection check, which - finding the PREVIOUS company's number
     * still tagged with the PREVIOUS company's name - cleared the whole
     * selection, `clearCompany` request included, undoing the save the
     * enrolment had just made. This handler makes no announced write at all,
     * so there is no cascade to guard against; the case pins that.
     */
    test('no clearCompany request is fired, even over an earlier confirmed selection', () => {
        buildAddressForm({ country: 'GB' });
        window.twopayment = {
            order_intent_url: ORDER_INTENT_URL,
            ajax_token: 'test-token'
        };
        track(new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            addressLookupEnabled: true,
            companyFieldSelector: "input[name='company']"
        }));

        // The state a completed search selection leaves behind: name, number,
        // and the number tagged with the name it belongs to.
        $("input[name='company']").val('Earlier Registered Ltd');
        $("input[name='companyid']")
            .val('999888777')
            .attr('data-two-company-name', 'Earlier Registered Ltd');

        const publishes = [];
        global.window.TwoCheckoutManager_Instance = {
            setConfirmedCompanySelection(selection) {
                publishes.push(selection);
            }
        };
        const ajax = stubAjax($);

        try {
            dispatchReady({ company: 'Sole Trader AS', companyid: '923456789' });

            const actions = ajax.calls.map((call) => (call.settings.data || {}).action);
            expect(actions).not.toContain('clearCompany');
            // And it publishes nothing of its own: the enrolment's own
            // saveCompany plus the selection TwoSoleTrader publishes are what
            // carry the company to the order.
            expect(publishes).toEqual([]);
            expect(dniValue()).toBe('923456789');
        } finally {
            ajax.restore();
        }
    });

    /**
     * Manual entry is the buyer typing their own details because search could
     * not find them. Enrolment is asynchronous, so it can complete mid-field -
     * and every other write path in the module stands down on this flag.
     */
    test('an enrolment completing during manual entry writes nothing', () => {
        buildAddressForm({ country: 'GB' });
        const search = track(new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            addressLookupEnabled: true,
            companyFieldSelector: "input[name='company']"
        }));
        search.enterManualEntryMode();
        $("input[name='dni']").val('');

        dispatchReady({ company: 'Sole Trader AS', companyid: '923456789' });

        expect(dniValue()).toBe('');
    });

    /** A malformed or half-empty payload must not blank a field or throw. */
    test('an event carrying no detail at all changes nothing and does not throw', () => {
        buildAddressForm({ country: 'GB' });
        $("input[name='dni']").val('11223344');
        track(new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            addressLookupEnabled: true,
            companyFieldSelector: "input[name='company']"
        }));

        expect(() => {
            document.dispatchEvent(new window.CustomEvent('two:sole-trader-ready'));
        }).not.toThrow();

        expect(dniValue()).toBe('11223344');
    });
});

/**
 * End to end across the seam, because the cases above dispatch the event by
 * hand - a dispatch that carried no payload at all would keep them all green.
 * This drives the real enrolment (mint, buyer lookup, saveCompany) into a real
 * TwoCompanySearch on a real address form.
 */
describe('a real enrolment reaches the real address form', () => {
    test('the enrolled number ends up in dni, with nothing hand-dispatched', async () => {
        buildAddressForm({ country: 'GB' });
        document.body.insertAdjacentHTML('beforeend', "<input name='email' value='buyer@example.test' />");
        loadScript('views/js/modules/TwoSoleTrader.js');

        global.window.fetch = (url) => {
            const target = String(url);
            if (target.includes('soleTraderAvailability')) {
                return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
            }
            if (target.includes('soleTraderTokens')) {
                return Promise.resolve({
                    json: () => Promise.resolve({
                        success: true,
                        autofill_token: 'af-token',
                        delegation_token: 'del-token',
                        signup_url: 'https://signup.example.test/',
                        country: 'GB'
                    })
                });
            }
            if (target.includes('/autofill/v1/buyer/current')) {
                return Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve({
                        email: 'buyer@example.test',
                        company_name: 'Sole Trader AS',
                        organization_number: '923456789'
                    })
                });
            }
            // saveCompany
            return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
        };
        global.fetch = global.window.fetch;

        track(new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            addressLookupEnabled: true,
            companyFieldSelector: "input[name='company']"
        }));
        const soleTrader = new window.TwoSoleTrader({
            checkoutHost: CHECKOUT_HOST,
            orderIntentUrl: ORDER_INTENT_URL,
            ajaxToken: 'test-token'
        });

        try {
            soleTrader.startEnrollment();
            await flushPromises();
            await flushPromises();
            await flushPromises();

            expect(dniValue()).toBe('923456789');
        } finally {
            soleTrader.destroy();
        }
    });
});

describe('a destroyed instance ignores the event', () => {
    /**
     * Both defences, because either alone would let this pass: the listener is
     * detached by destroy() (asserted through the outcome of a real dispatch),
     * AND the handler stands down on `_destroyed` if it is reached anyway -
     * `document` outlives every instance and TwoSoleTrader dispatches on it, so
     * nothing guarantees teardown ran first.
     */
    test('neither the dispatch nor a direct call reaches the form', () => {
        buildAddressForm({ country: 'GB' });
        const search = track(new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            addressLookupEnabled: true,
            companyFieldSelector: "input[name='company']"
        }));
        search.destroy();

        dispatchReady({ company: 'Sole Trader AS', companyid: '923456789' });
        expect(dniValue()).toBe('');

        search.onSoleTraderReady({
            detail: { company: 'Sole Trader AS', companyid: '923456789' }
        });
        expect(dniValue()).toBe('');
    });
});
