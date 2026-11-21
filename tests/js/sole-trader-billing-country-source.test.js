/**
 * TWO-27354: billingCountry() reads the INVOICE address's country, never the
 * delivery address's.
 *
 * It used to read the first country select on the page. A buyer editing their
 * delivery address while a separate invoice address is on the cart was then
 * offered sole-trader enrolment, and sent to the signup popup, for the delivery
 * country. The select is now taken only from the core form that saves the
 * invoice address (the invoice form, or the delivery form while the two are
 * one address); otherwise the server-resolved invoice country answers. Only
 * when the cart has no invoice address yet does the delivery select stand in,
 * as it does on the server, and the shop's country comes last.
 */

'use strict';

const { loadSoleTrader, buildAddressesStep, flushPromises } = require('./ps-harness');

let TwoSoleTrader;
let instance;
let availabilityCountries;

/** Core's classic theme puts no ISO on the options; the module reads this map. */
const COUNTRY_MAP = { 17: 'GB', 8: 'FR', 1: 'DE' };

function build(config) {
    instance = new TwoSoleTrader(Object.assign({
        checkoutHost: 'https://api.example.test',
        orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
        ajaxToken: 'test-token',
        shopCountry: 'ZZ',
        billingCountry: 'SE'
    }, config || {}));

    return instance;
}

/**
 * @param {Object} page
 * @param {?Object} page.step buildAddressesStep() options, or null for a page with no address form
 * @param {boolean} [page.dropSharedAddressControl] remove the `use_same_address`
 *        checkbox from the delivery form, as a theme that drops it would; core
 *        renders it on every delivery form
 * @param {?string} [page.straySelectIso] a country select placed BEFORE the step
 * @param {?string} [page.customFormIso] a theme's own form with no `saveAddress` marker
 * @param {?string} [page.outerFormIso] wrap the step in an outer form whose own
 *        country select comes first; the parser drops the nested form, so the
 *        marker's `form` is the outer one
 */
function renderPage(page) {
    document.body.innerHTML = '';
    if (page.step) {
        buildAddressesStep(page.step);
    }
    if (page.outerFormIso) {
        document.body.innerHTML = "<form><select name='id_country'><option value='9' data-iso-code='"
            + page.outerFormIso + "' selected>X</option></select>" + document.body.innerHTML + '</form>';
    }
    if (page.dropSharedAddressControl) {
        document.querySelector("input[name='use_same_address']").remove();
    }
    if (page.straySelectIso) {
        document.body.insertAdjacentHTML('afterbegin',
            "<select name='id_country'><option value='9' data-iso-code='" + page.straySelectIso + "' selected>X</option></select>");
    }
    if (page.customFormIso) {
        document.body.insertAdjacentHTML('beforeend',
            "<form><select name='id_country'><option value='9' data-iso-code='" + page.customFormIso + "' selected>X</option></select></form>");
    }
}

beforeEach(() => {
    delete global.window.TwoSoleTrader;
    delete global.window.TwoSoleTrader_Instance;
    window.localStorage.clear();
    global.window.twopayment = { countries: COUNTRY_MAP };
    availabilityCountries = [];
    global.window.fetch = (url) => {
        const match = /soleTraderAvailability.*[?&]country=([A-Z]{2})/.exec(String(url));
        if (match) {
            availabilityCountries.push(match[1]);
            return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
        }
        return Promise.resolve({ json: () => Promise.resolve({ success: false }) });
    };
    TwoSoleTrader = loadSoleTrader();
});

afterEach(() => {
    if (instance) {
        instance.destroy();
        instance = null;
    }
    document.body.innerHTML = '';
    delete global.window.twopayment;
});

describe('billingCountry() answers for the invoice address (TWO-27354)', () => {
    test.each([
        {
            page: { step: { editing: 'delivery', sameAddress: false, countryId: '1' } },
            config: {},
            expected: 'SE',
            description: 'delivery form with a separate invoice address answers with the server-resolved invoice country'
        },
        {
            page: { step: { editing: 'delivery', sameAddress: true, countryId: '1' } },
            config: {},
            expected: 'DE',
            description: 'delivery form that is also the invoice address answers with its live select'
        },
        {
            page: { step: { editing: 'invoice', countryId: '8' } },
            config: {},
            expected: 'FR',
            description: 'invoice form answers with its live select over the saved invoice country'
        },
        {
            page: { step: { editing: 'invoice', countryId: '8' }, straySelectIso: 'GB' },
            config: {},
            expected: 'FR',
            description: 'invoice form is read even when another country select comes first on the page'
        },
        {
            page: { step: { editing: 'invoice', countryId: '8' }, outerFormIso: 'GB' },
            config: {},
            expected: 'FR',
            description: 'invoice form is read from its address block, not the outer form a nested form resolves to'
        },
        {
            page: { step: { editing: 'delivery', sameAddress: true, countryId: '1' }, straySelectIso: 'GB' },
            config: {},
            expected: 'DE',
            description: 'shared delivery form is read even when another country select comes first on the page'
        },
        {
            page: { step: { editing: 'delivery', sameAddress: false, countryId: '1' }, dropSharedAddressControl: true },
            config: {},
            expected: 'SE',
            description: 'delivery form with no checkbox and an invoice block skips the delivery form'
        },
        {
            page: { step: { editing: 'delivery', sameAddress: true, invoiceBlock: false, countryId: '1' }, dropSharedAddressControl: true },
            config: {},
            expected: 'DE',
            description: 'delivery form with no checkbox and no invoice block reads the shared delivery form'
        },
        {
            page: { step: { editing: 'delivery', sameAddress: false, countryId: '1' } },
            config: { billingCountry: '' },
            expected: 'DE',
            description: 'no invoice address on the cart yet: the delivery select stands in before the shop country'
        },
        {
            page: { step: { editing: 'delivery', sameAddress: false, countryId: '17' } },
            config: { shopCountry: 'NO', billingCountry: '' },
            expected: 'GB',
            description: 'guest cross-border: shop in NO, delivery select GB, no invoice address answers GB'
        },
        {
            page: { step: null },
            config: { shopCountry: 'NO', billingCountry: '' },
            expected: 'NO',
            description: 'no address form and no invoice address falls back to the shop country last'
        },
        {
            page: { step: null, customFormIso: 'GB' },
            config: {},
            expected: 'GB',
            description: 'a form with no saveAddress marker keeps the live select, as before'
        },
        {
            page: { step: null },
            config: {},
            expected: 'SE',
            description: 'payment step with no address form answers with the server-resolved invoice country'
        }
    ])('$description', ({ page, config, expected, description }) => {
        renderPage(page);
        expect([build(config).billingCountry(), description]).toEqual([expected, description]);
    });
});

test('toggling use_same_address re-asks availability for the newly resolved country', async () => {
    renderPage({ step: { editing: 'delivery', sameAddress: true, countryId: '1' } });
    build();
    await flushPromises();
    expect(availabilityCountries).toEqual(['DE']);

    const sharedAddressControl = document.querySelector("input[name='use_same_address']");
    sharedAddressControl.checked = false;
    sharedAddressControl.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();

    expect(availabilityCountries).toEqual(['DE', 'SE']);
});
