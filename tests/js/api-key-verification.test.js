/**
 * TWO-25326. The company-search control must stand down on a shop whose Two
 * API key does not currently verify.
 *
 * The search itself is authenticated with that key, so on such a shop every
 * keystroke can only fail - and the payment option is withheld server-side on
 * the same verdict, so there is nothing a captured company could be used for
 * anyway. Two things have to happen, and only the first is obvious: no search
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
