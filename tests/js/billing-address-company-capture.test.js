/**
 * TWO-25503. Doug found this on a real checkout with company search in the
 * address area: with a billing address that differs from the shipping one, a
 * company picked on the BILLING form never reached the order-intent call. The
 * payment tile then spun forever with nothing said to the buyer.
 *
 * Two independent causes, both pinned here.
 *
 * 1. Address stamping. Every consumer of the stored company selection drops it
 *    when the address it was captured against is not the one now being billed.
 *    On the invoice pass the delivery side is a saved-address RADIO selector,
 *    so a radio-first read answered with the shipping address while the buyer
 *    was filling in their billing one - stamping the billing company with the
 *    shipping address id, which every one of those guards then read as a
 *    switch.
 *
 * 2. A lost update on PrestaShop's session cookie. Every front request
 *    rewrites that cookie WHOLE from the snapshot it loaded, so two module
 *    requests in flight together lose one of the writes. On the invoice form a
 *    company selection fires `saveCompany` and the address fill's
 *    `saveMirrorWrites` off the same click, and the mirror write landed last
 *    and reverted the company the buyer had just picked. The shipping pass
 *    records no mirror writes, which is why the same selection stuck there.
 */

'use strict';

const {
    loadCompanySearch,
    loadOrderIntent,
    loadScript,
    releaseWidgets,
    buildAddressesStep
} = require('./ps-harness');

const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';

let TwoCompanySearch;
let TwoOrderIntent;
let TwoCheckoutManager;
let $;

beforeEach(() => {
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    TwoOrderIntent = loadOrderIntent();
    loadScript('views/js/modules/TwoCheckoutManager.js');
    TwoCheckoutManager = window.TwoCheckoutManager;
    TwoCompanySearch._companyCookieWrite = null;

    window.twopayment = {
        order_intent_url: ORDER_INTENT_URL,
        ajax_token: 'test-token'
    };
});

afterEach(() => {
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
    delete window.TwoCheckoutManager_Instance;
});

/** The payment step: no editable address form, only the checked hidden ids. */
function buildPaymentStepAddressIds(deliveryId, invoiceId) {
    document.body.innerHTML = [
        '<input type="radio" name="id_address_delivery" value="' + deliveryId + '" checked>',
        '<input type="radio" name="id_address_invoice" value="' + invoiceId + '" checked>'
    ].join('\n');
}

describe('the address a company selection is stamped with', () => {
    const cases = [
        [
            () => buildAddressesStep({ editing: 'invoice' }),
            0,
            'invoice form for a NEW billing address, delivery radio checked: unknown, not the shipping address'
        ],
        [
            () => {
                buildAddressesStep({ editing: 'invoice' });
                document.querySelector("input[name='saveAddress']")
                    .closest('form').setAttribute('data-id-address', '9');
            },
            9,
            'invoice form EDITING a saved billing address: that address, not the shipping one'
        ],
        [
            () => buildAddressesStep({ editing: 'delivery' }),
            0,
            'delivery form for a new shipping address: unknown'
        ],
        [
            () => buildPaymentStepAddressIds('7', '9'),
            9,
            'payment step, no editable form: the billing address the radios name'
        ]
    ];

    test.each(cases)('%#', (build, expected, description) => {
        build();

        const intent = new TwoOrderIntent({});
        const search = new TwoCompanySearch({ companyFieldSelector: "input[name='company']" });

        expect(intent.getCurrentAddressId()).toBe(expected);
        expect(search.getCurrentAddressId()).toBe(expected);

        search.destroy();
    });

    /**
     * The two mirrors have to answer identically or the stamp is discarded on
     * comparison. A document-wide read in TwoOrderIntent answers with whichever
     * block comes first in the markup, so it disagrees with a search mounted in
     * the other one - which every address-switch guard reads as a switch and
     * throws the buyer's valid selection away.
     */
    test('the intent answers with the block its company search is mounted in', () => {
        const block = function (id, companyId, addressId) {
            return [
                '<div id="' + id + '">',
                '  <form method="POST" data-id-address="' + addressId + '">',
                '    <input type="text" name="company" id="' + companyId + '" value="" />',
                '    <input type="hidden" name="saveAddress" value="delivery">',
                '  </form>',
                '</div>'
            ].join('\n');
        };
        document.body.innerHTML = [
            block('delivery-address', 'company-a', '7'),
            block('invoice-address', 'company-b', '9')
        ].join('\n');
        const search = new TwoCompanySearch({ companyFieldSelector: '#company-b' });
        const intent = new TwoOrderIntent({ getCompanySearch: () => search });

        expect(search.getCurrentAddressId()).toBe(9);
        expect(intent.getCurrentAddressId()).toBe(9);

        search.destroy();
    });

    test('the manager stamps a billing selection with the same answer', () => {
        buildAddressesStep({ editing: 'invoice' });
        const manager = new TwoCheckoutManager({
            orderIntentEnabled: false,
            companySearchInAddressArea: true
        });

        manager.setConfirmedCompanySelection({ company: 'Example Ltd', companyid: '11111111' });

        expect(manager.getConfirmedCompanySelection().addressId).toBe(0);
    });
});

describe('module writes to the PrestaShop session cookie never overlap', () => {
    /**
     * A thenable stub, unlike the shared harness's: the real jqXHR is one, and
     * the whole point of the queue is that the NEXT write waits for it.
     */
    function stubDeferredAjax() {
        const calls = [];
        $.ajax = function (settings) {
            let settle;
            const promise = new Promise(resolve => { settle = resolve; });
            calls.push({ action: settings.data.action, settle: settle });
            return promise;
        };
        return calls;
    }

    test('a mirror write waits for the company write it was fired beside', async () => {
        buildAddressesStep({ editing: 'invoice' });
        const original = $.ajax;
        const calls = stubDeferredAjax();
        const search = new TwoCompanySearch({ companyFieldSelector: "input[name='company']" });

        search.persistCompanyToCookie({ company: 'Example Ltd', companyid: '11111111' });
        search.recordMirrorWrites({ city: 'London' });
        await Promise.resolve();

        expect(calls.map(call => call.action)).toEqual(['saveCompany']);

        calls[0].settle();
        await new Promise(resolve => setTimeout(resolve, 0));

        expect(calls.map(call => call.action)).toEqual(['saveCompany', 'saveMirrorWrites']);

        search.destroy();
        $.ajax = original;
    });
});
