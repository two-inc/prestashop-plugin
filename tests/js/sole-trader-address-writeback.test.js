/**
 * TWO-40: the two browser-side halves of "a sole trader can start, and finish,
 * enrolment from the address-editor page".
 *
 * 1. The mint request carries the country. The cart has no invoice address at
 *    that point in checkout, so the server has nothing else to gate on - and
 *    the country sent is `billingCountry()`, the very resolver the "I'm a sole
 *    trader" chip's own visibility is decided from, so the country the mint is
 *    authorised against cannot disagree with the country the chip was shown
 *    for.
 *
 * 2. A completed enrolment is adopted into the address form. Nothing listened
 *    to `two:sole-trader-ready` before this ticket, so the buyer finished the
 *    hosted signup and came back to an empty company field.
 *
 *    The adoption is placement-conditional, and that is the whole point of the
 *    `companySearchInAddressArea` flag being passed explicitly from both mounts:
 *    the payment-tile placement must never write into the address form. Its
 *    mount point is not part of that form, and the write goes to the form's own
 *    inputs by global selector, so an unconditional handler would rewrite an
 *    address the buyer is not even looking at.
 */

'use strict';

const {
    loadCompanySearch,
    loadScript,
    buildAddressForm,
    releaseWidgets,
    flushPromises
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';

let TwoCompanySearch;
let $;

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
});

afterEach(() => {
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
    delete global.fetch;
    delete global.window.fetch;
    delete global.window.TwoSoleTrader_Instance;
});

describe('the mint request carries the buyer\'s current country', () => {
    /**
     * Driven through the real fetchTokens(), with the country only reachable
     * from the DOM select - so a regression to a config-time value, or to no
     * country at all, fails here rather than passing on a hardcoded fixture.
     */
    test('fetchTokens POSTs the country billingCountry() resolves, urlencoded', async () => {
        buildAddressForm({ country: 'NO' });
        loadScript('views/js/modules/TwoSoleTrader.js');
        const TwoSoleTrader = window.TwoSoleTrader;

        const calls = [];
        global.window.fetch = (url, options) => {
            calls.push({ url: String(url), options: options });
            if (String(url).includes('soleTraderAvailability')) {
                return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
            }
            return Promise.resolve({ json: () => Promise.resolve({ success: false }) });
        };
        global.fetch = global.window.fetch;

        const soleTrader = new TwoSoleTrader({
            checkoutHost: CHECKOUT_HOST,
            orderIntentUrl: ORDER_INTENT_URL,
            ajaxToken: 'test-token'
        });
        soleTrader.fetchTokens();
        await flushPromises();

        const mint = calls.filter((call) => call.url.includes('soleTraderTokens'));
        expect(mint).toHaveLength(1);
        expect(mint[0].options.method).toBe('POST');
        expect(mint[0].options.headers['Content-Type']).toBe('application/x-www-form-urlencoded');
        expect(mint[0].options.body).toBe('country=NO');
        soleTrader.destroy();
    });

    /**
     * The agreement that makes the gate coherent, asserted as an equality
     * rather than against a second hardcoded ISO code: whatever the chip's
     * visibility resolver answers is what goes on the wire. A future change
     * that gives the mint its own country source has to break this.
     */
    test('the country sent is the same one the chip\'s visibility is decided from', async () => {
        buildAddressForm({ country: 'SE' });
        loadScript('views/js/modules/TwoSoleTrader.js');
        const TwoSoleTrader = window.TwoSoleTrader;

        const calls = [];
        global.window.fetch = (url, options) => {
            calls.push({ url: String(url), options: options });
            if (String(url).includes('soleTraderAvailability')) {
                return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
            }
            return Promise.resolve({ json: () => Promise.resolve({ success: false }) });
        };
        global.fetch = global.window.fetch;

        const soleTrader = new TwoSoleTrader({
            checkoutHost: CHECKOUT_HOST,
            orderIntentUrl: ORDER_INTENT_URL,
            ajaxToken: 'test-token'
        });
        soleTrader.fetchTokens();
        await flushPromises();

        const mint = calls.filter((call) => call.url.includes('soleTraderTokens'));
        expect(mint).toHaveLength(1);
        expect(mint[0].options.body).toBe('country=' + soleTrader.billingCountry());
        // Not vacuous: billingCountry() resolving to '' would satisfy the
        // equality above while sending no country at all.
        expect(soleTrader.billingCountry()).toBe('SE');
        soleTrader.destroy();
    });
});

describe('address-form placement adopts a completed enrolment', () => {
    test('the enrolled company name and organisation number land in the form', () => {
        buildAddressForm({ country: 'GB' });
        const search = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: true,
            companyFieldSelector: "input[name='company']"
        });

        dispatchReady({ company: 'Sole Trader AS', companyid: '923456789' });

        expect(companyValue()).toBe('Sole Trader AS');
        expect(dniValue()).toBe('923456789');
        expect(document.querySelector("input[name='companyid']").value).toBe('923456789');
        // Never the VAT field - an organisation number is not a VAT number,
        // and a non-empty vat_number on a foreign address zeroes the tax.
        expect(document.querySelector("input[name='vat_number']").value).toBe('');
        search.destroy();
    });

    /**
     * The write is MARKED as ours, using the same attribute the address fill
     * uses. Without the marker a later company selection reads the value as
     * something the buyer typed and refuses to replace it, so one identity
     * sticks to the next for the rest of checkout.
     */
    test('the company write is marked as autofilled, and announced to the theme', () => {
        buildAddressForm({ country: 'GB' });
        const events = [];
        $("input[name='company']").on('input change', (event) => {
            events.push(event.type);
        });
        const search = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: true,
            companyFieldSelector: "input[name='company']"
        });

        dispatchReady({ company: 'Sole Trader AS', companyid: '923456789' });

        expect(
            document.querySelector("input[name='company']")
                .getAttribute(TwoCompanySearch.AUTOFILL_MARKER_ATTR)
        ).toBe('Sole Trader AS');
        expect(events).toContain('input');
        expect(events).toContain('change');
        search.destroy();
    });

    /**
     * The merchant's address-lookup toggle governs what a capture WRITES into
     * the address step, and the organisation number goes through the existing
     * gated writer rather than around it. The company name is not gated - it is
     * PrestaShop's own field and the value the buyer just enrolled under.
     */
    test('the organisation-number mirror honours the address-lookup toggle', () => {
        buildAddressForm({ country: 'GB' });
        const search = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: true,
            addressLookupEnabled: false,
            companyFieldSelector: "input[name='company']"
        });

        dispatchReady({ company: 'Sole Trader AS', companyid: '923456789' });

        expect(dniValue()).toBe('');
        expect(companyValue()).toBe('Sole Trader AS');
        search.destroy();
    });

    /** A malformed or half-empty payload must not blank a field. */
    test('an event carrying no detail at all changes nothing and does not throw', () => {
        buildAddressForm({ country: 'GB' });
        $("input[name='company']").val('Typed By Buyer Ltd');
        const search = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: true,
            companyFieldSelector: "input[name='company']"
        });

        expect(() => {
            document.dispatchEvent(new window.CustomEvent('two:sole-trader-ready'));
        }).not.toThrow();

        expect(companyValue()).toBe('Typed By Buyer Ltd');
        expect(dniValue()).toBe('');
        search.destroy();
    });
});

describe('payment-tile placement never writes into the address form', () => {
    /**
     * The invariant, asserted with the address form present so the write has
     * somewhere to land: in tile mode the enrolment must reach none of it. The
     * tile field is not part of the address form, and the handler's write goes
     * to the form's own inputs by global selector.
     */
    function buildTileAlongsideAddressForm() {
        buildAddressForm({ country: 'GB' });
        document.body.insertAdjacentHTML(
            'beforeend',
            "<div class='two-payment-container'><input type='text' id='two_tile_company' name='two_tile_company' /></div>"
        );
    }

    test('nothing is written into the address form\'s company or identifier fields', () => {
        buildTileAlongsideAddressForm();
        const search = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: false,
            addressLookupEnabled: false,
            companyFieldSelector: '#two_tile_company'
        });

        dispatchReady({ company: 'Sole Trader AS', companyid: '923456789' });

        expect(companyValue()).toBe('');
        expect(dniValue()).toBe('');
        expect(document.querySelector("input[name='vat_number']").value).toBe('');
        expect(
            document.querySelector("input[name='company']")
                .hasAttribute(TwoCompanySearch.AUTOFILL_MARKER_ATTR)
        ).toBe(false);
        search.destroy();
    });

    /**
     * The placement flag on its own, with the address-lookup toggle left at its
     * default. The two defences overlap in production - the real tile mount
     * hardcodes `addressLookupEnabled: false` as well - and the case above
     * therefore cannot tell them apart: with the lookup off, removing the
     * placement guard changes nothing it can see. This one isolates the guard,
     * so a tile mount that ever reached here with the lookup toggle on (a
     * merchant default, a future mount) is still refused.
     */
    test('the placement flag alone is enough, with the address-lookup toggle on', () => {
        buildTileAlongsideAddressForm();
        const search = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: false,
            companyFieldSelector: '#two_tile_company'
        });

        dispatchReady({ company: 'Sole Trader AS', companyid: '923456789' });

        expect(dniValue()).toBe('');
        expect(companyValue()).toBe('');
        search.destroy();
    });

    /**
     * Same DOM, address-form placement: the mirror-image case, so the test
     * above cannot pass by the handler being dead everywhere.
     */
    test('the same event in address-form placement DOES write', () => {
        buildTileAlongsideAddressForm();
        const search = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: true,
            companyFieldSelector: "input[name='company']"
        });

        dispatchReady({ company: 'Sole Trader AS', companyid: '923456789' });

        expect(companyValue()).toBe('Sole Trader AS');
        expect(dniValue()).toBe('923456789');
        search.destroy();
    });
});

/**
 * End to end across the seam, because each side of it is stubbed in the cases
 * above: those dispatch the event by hand (so a dispatch that carried no payload
 * at all would keep them green) and the mint cases never reach a completion.
 * This drives the real enrolment - mint, buyer lookup, saveCompany - into a real
 * TwoCompanySearch mounted on a real address form.
 */
describe('a real enrolment reaches the real address form', () => {
    test('the enrolled identity ends up in the form, with nothing hand-dispatched', async () => {
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

        const search = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: true,
            companyFieldSelector: "input[name='company']"
        });
        const soleTrader = new window.TwoSoleTrader({
            checkoutHost: CHECKOUT_HOST,
            orderIntentUrl: ORDER_INTENT_URL,
            ajaxToken: 'test-token'
        });

        soleTrader.startEnrollment();
        await flushPromises();
        await flushPromises();
        await flushPromises();

        expect(companyValue()).toBe('Sole Trader AS');
        expect(dniValue()).toBe('923456789');
        soleTrader.destroy();
        search.destroy();
    });
});

/**
 * The flag has to arrive from the manager, not from a test's own literal. Every
 * case above constructs TwoCompanySearch directly, so a tile mount that simply
 * omitted `companySearchInAddressArea` would inherit the default (`true`, the
 * address-form value) and write into the address form in production with the
 * whole suite green.
 */
describe('the manager decides the placement flag, per mount', () => {
    let TwoCheckoutManager;

    beforeEach(() => {
        loadScript('views/js/modules/TwoCheckoutManager.js');
        TwoCheckoutManager = window.TwoCheckoutManager;
    });

    test('the tile mount is constructed with the address-form write DISABLED', () => {
        document.body.innerHTML =
            "<div class='two-payment-container'><input type='text' id='two_tile_company' name='two_tile_company' /></div>";
        const manager = new TwoCheckoutManager({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: false
        });
        manager.initializeCompanySearch();

        expect(manager.companySearch).toBeTruthy();
        expect(manager.companySearch.config.companyFieldSelector).toBe('#two_tile_company');
        expect(manager.companySearch.config.companySearchInAddressArea).toBe(false);
        // Destroyed explicitly: `document` is shared for the whole file, so an
        // instance left listening would answer a LATER test's dispatch.
        manager.companySearch.destroy();
    });

    test('the address-form mount is constructed with it ENABLED', () => {
        buildAddressForm({ country: 'GB' });
        const manager = new TwoCheckoutManager({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: true
        });
        manager.initializeCompanySearch();

        expect(manager.companySearch).toBeTruthy();
        expect(manager.companySearch.config.companyFieldSelector).toBe("input[name='company']");
        expect(manager.companySearch.config.companySearchInAddressArea).toBe(true);
        manager.companySearch.destroy();
    });
});

describe('a destroyed instance ignores the event', () => {
    /**
     * Both defences, because either alone would let this pass: the listener is
     * detached by destroy() (asserted through the outcome of a real dispatch),
     * AND the handler stands down on `_destroyed` if it is reached anyway -
     * `document` outlives every instance and TwoSoleTrader dispatches on it,
     * so nothing guarantees teardown ran first.
     */
    test('neither the dispatch nor a direct call reaches the form', () => {
        buildAddressForm({ country: 'GB' });
        const search = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: true,
            companyFieldSelector: "input[name='company']"
        });
        search.destroy();

        dispatchReady({ company: 'Sole Trader AS', companyid: '923456789' });
        expect(companyValue()).toBe('');
        expect(dniValue()).toBe('');

        search.onSoleTraderReady({ detail: { company: 'Sole Trader AS', companyid: '923456789' } });
        expect(companyValue()).toBe('');
        expect(dniValue()).toBe('');
    });
});
