/**
 * TWO-25326. The company-search control must stand down on a shop whose Two
 * API key does not currently verify.
 *
 * The payment option is withheld server-side on the same verdict, so there is
 * nothing a captured company can be used for - the search would still function
 * (that endpoint is called unauthenticated), it just leads nowhere, and a
 * "verify your company" journey that cannot complete is worse than a plain
 * field. Two things have to happen, and only the first is obvious: no search
 * control is constructed, AND the search-mode placeholder the address-form
 * override applied SERVER-side is taken back off the field. That placeholder
 * survives the control never existing, so without the second half the buyer is
 * told to "enter company name to search" on what is now a plain text input.
 *
 * The gate is deliberately explicit-false-only: an absent `api_key_verified`
 * (an older cached config payload, a theme that reconstructs the config) must
 * read as verified rather than take the search away from a healthy shop.
 */

'use strict';

const { loadCompanySearch, loadOrderIntent, loadScript, releaseWidgets, stubAjax, buildAddressForm } = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const SEARCH_PLACEHOLDER = 'Enter company name to search';

let TwoCheckoutManager;
let $;

function managerConfig(overrides) {
    return Object.assign(
        {
            companySearchInAddressArea: true,
            checkoutHost: CHECKOUT_HOST,
            orderIntentEnabled: false
        },
        overrides || {}
    );
}

function companyField() {
    return document.querySelector("input[name='company']");
}

beforeEach(() => {
    const loaded = loadCompanySearch();
    $ = loaded.$;
    stubAjax($);
    loadOrderIntent();
    loadScript('views/js/modules/TwoCheckoutManager.js');
    TwoCheckoutManager = window.TwoCheckoutManager;

    window.twopayment = { checkout_host: CHECKOUT_HOST };
    buildAddressForm();
    // What the address-form override puts there server-side.
    companyField().setAttribute('placeholder', SEARCH_PLACEHOLDER);
});

afterEach(() => {
    releaseWidgets($);
    delete window.twopayment;
    document.body.innerHTML = '';
});

describe('company search under an unverified API key', () => {
    test('no search control is mounted when the key does not verify', () => {
        const manager = new TwoCheckoutManager(managerConfig({ apiKeyVerified: false }));

        expect(manager.companySearch).toBeNull();
    });

    test('the search-mode placeholder is removed, so the field reads as a plain input', () => {
        new TwoCheckoutManager(managerConfig({ apiKeyVerified: false }));

        expect(companyField().getAttribute('placeholder')).toBeNull();
    });

    test('a translated search placeholder is removed too', () => {
        const translated = 'Introduce el nombre de la empresa para buscar';
        window.twopayment.i18n = { company_search_placeholder: translated };
        companyField().setAttribute('placeholder', translated);

        new TwoCheckoutManager(managerConfig({ apiKeyVerified: false }));

        expect(companyField().getAttribute('placeholder')).toBeNull();
    });

    test("a placeholder the module did not set is left alone", () => {
        // Only the module's own search wording is ours to remove. A theme's
        // placeholder is the theme's, verified key or not.
        companyField().setAttribute('placeholder', 'Firmenname (optional)');

        new TwoCheckoutManager(managerConfig({ apiKeyVerified: false }));

        expect(companyField().getAttribute('placeholder')).toBe('Firmenname (optional)');
    });

    test('every company field is neutralised, not just the first', () => {
        // PrestaShop renders a SECOND address form, with its own
        // name='company', the moment the buyer ticks "billing address differs
        // from shipping address" - and the override places the hint on both.
        document.body.insertAdjacentHTML(
            'beforeend',
            "<div class='js-address-form'><form data-id-address='9'>" +
                "<input type='text' name='company' value='' placeholder='" + SEARCH_PLACEHOLDER + "' />" +
                '</form></div>'
        );
        const fields = document.querySelectorAll("input[name='company']");
        expect(fields.length).toBe(2);

        new TwoCheckoutManager(managerConfig({ apiKeyVerified: false }));

        fields.forEach((field) => {
            expect(field.getAttribute('placeholder')).toBeNull();
        });
    });

    test('the gate also holds in tile mode, where the mount point is in the payment tile', () => {
        document.body.insertAdjacentHTML(
            'beforeend',
            "<div class='payment-option'><input type='text' id='two_tile_company' /></div>"
        );

        const manager = new TwoCheckoutManager(
            managerConfig({ apiKeyVerified: false, companySearchInAddressArea: false })
        );

        expect(manager.companySearch).toBeNull();
    });

    test('a later re-init edge does not sneak the control back in', () => {
        const manager = new TwoCheckoutManager(managerConfig({ apiKeyVerified: false }));

        // The manager calls initializeCompanySearch() again on every DOM-settle
        // and step-change edge; the gate has to hold on each of them, not only
        // on the first pass through construction.
        manager.initializeCompanySearch();
        manager.initializeCompanySearch();

        expect(manager.companySearch).toBeNull();
    });
});

describe('company search on a healthy shop', () => {
    test('a verified key mounts the control and keeps the search placeholder', () => {
        const manager = new TwoCheckoutManager(managerConfig({ apiKeyVerified: true }));

        expect(manager.companySearch).not.toBeNull();
        expect(companyField().getAttribute('placeholder')).toBe(SEARCH_PLACEHOLDER);
    });

    test('an absent verdict reads as verified, never as broken', () => {
        const manager = new TwoCheckoutManager(managerConfig());

        expect(manager.companySearch).not.toBeNull();
        expect(companyField().getAttribute('placeholder')).toBe(SEARCH_PLACEHOLDER);
    });

    test('only an explicit false disables the search', () => {
        // Guards against the gate being written as a falsiness check: the
        // sibling switches in this config arrive as the strings '0'/'1', and a
        // truthy-string test here would disable the search on a shop whose
        // verdict simply came through in an unexpected shape.
        [undefined, null, 'false', 0, ''].forEach((value) => {
            const manager = new TwoCheckoutManager(managerConfig({ apiKeyVerified: value }));
            expect(manager.companySearch).not.toBeNull();
            manager.cleanup && manager.cleanup();
        });
    });
});

describe('the bootstrap translation of the server payload', () => {
    // views/js/twopayment.js is where the verdict actually crosses from the
    // server payload into the manager's config, and it used to do that twice
    // (primary + retry path) with no coverage of either - so the
    // explicit-false-only semantics the comments and the tests above advertise
    // could have been an === true check and nothing would have noticed.
    function buildConfig(payload) {
        window.twopayment = payload;
        loadScript('views/js/twopayment.js');
        return window.twoBuildCheckoutManagerConfig(payload);
    }

    test('a real false from the server disables the search', () => {
        expect(buildConfig({ api_key_verified: false }).apiKeyVerified).toBe(false);
    });

    test('a real true enables it', () => {
        expect(buildConfig({ api_key_verified: true }).apiKeyVerified).toBe(true);
    });

    test('anything else - absent included - reads as verified', () => {
        [{}, { api_key_verified: undefined }, { api_key_verified: '0' }, { api_key_verified: 0 }].forEach(
            (payload) => {
                expect(buildConfig(payload).apiKeyVerified).toBe(true);
            }
        );
    });

    test('the location and lookup switches keep their own string semantics', () => {
        const config = buildConfig({ company_search_in_address_area: '0', address_lookup: '0', api_key_verified: true });

        expect(config.companySearchInAddressArea).toBe(false);
        expect(config.addressLookupEnabled).toBe(false);
        expect(buildConfig({}).companySearchInAddressArea).toBe(true);
        expect(buildConfig({}).addressLookupEnabled).toBe(true);
    });
});
