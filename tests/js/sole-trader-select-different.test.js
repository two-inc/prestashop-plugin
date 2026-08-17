/**
 * TWO-40 follow-up: "Select a different sole trader".
 *
 * A completed sole-trader enrolment writes the buyer's identity into the
 * company field (TwoCompanySearch.adoptSoleTraderBuyer(), see
 * sole-trader-writeback.test.js) but previously left no way back into the
 * hosted signup other than reloading the page. This adds a reverse link,
 * same slot/pattern as the existing "Search for company" link
 * (renderBackToSearchLink()), that reopens the popup directly - skipping
 * getCurrentBuyer()'s silent-autofill check, since the buyer is explicitly
 * asking to replace an identity that check would just hand straight back -
 * with `autoselect=false` appended so the hosted signup does not silently
 * re-apply the same registration.
 */

'use strict';

const {
    loadCompanySearch,
    loadSoleTrader,
    buildAddressForm,
    flushPromises
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

const NAMED_BUYER = {
    company_name: 'Sole Trader Test Co',
    organization_number: 'TWO:ST123456789012',
    email: 'buyer@example.test',
    billing_address: null,
    shipping_address: null
};

let TwoCompanySearch;

function makeSearchInstance(config) {
    return new TwoCompanySearch(Object.assign({ checkoutHost: CHECKOUT_HOST }, config || {}));
}

beforeEach(() => {
    document.body.innerHTML = '';
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    buildAddressForm();
});

afterEach(() => {
    delete global.window.TwoSoleTrader_Instance;
    delete global.fetch;
    delete global.window.fetch;
    delete global.window.open;
    document.body.innerHTML = '';
});

describe('rendering (a)', () => {
    test('the link renders under the company field once a named sole-trader identity lands', () => {
        const instance = makeSearchInstance();

        expect(document.querySelector('.two-company-select-different-sole-trader')).toBeNull();

        const wrote = instance.adoptSoleTraderBuyer(NAMED_BUYER);

        expect(wrote).toBe(true);
        const link = document.querySelector('.two-company-select-different-sole-trader');
        expect(link).not.toBeNull();
        expect(link.tagName).toBe('BUTTON');
        expect(link.getAttribute('type')).toBe('button');
        expect(link.textContent).toBe('Select a different sole trader');

        // Same slot as "Search for company": a sibling of the company field
        // that comes AFTER it in document order, not folded into some other
        // row (e.g. the org-number hint span company search also appends).
        const companyField = document.querySelector("input[name='company']");
        expect(companyField.parentNode).toBe(link.parentNode);
        // eslint-disable-next-line no-bitwise
        expect(companyField.compareDocumentPosition(link) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

        instance.destroy();
    });

    test('a nameless sole trader (no trading name) does not render the link - nothing to "replace" visibly', () => {
        const instance = makeSearchInstance();
        instance.adoptSoleTraderBuyer(Object.assign({}, NAMED_BUYER, { company_name: '' }));

        expect(document.querySelector('.two-company-select-different-sole-trader')).toBeNull();
        instance.destroy();
    });

    test('clearSelectedCompany() removes the link again, mirroring the reverse link\'s own gating', () => {
        const instance = makeSearchInstance();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        expect(document.querySelector('.two-company-select-different-sole-trader')).not.toBeNull();

        instance.clearSelectedCompany();

        expect(document.querySelector('.two-company-select-different-sole-trader')).toBeNull();
        instance.destroy();
    });
});

describe('click behaviour (b)', () => {
    test('clicking the link calls TwoSoleTrader_Instance.startReplacement(), not startEnrollment()', () => {
        const instance = makeSearchInstance();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);

        const startReplacement = jest.fn();
        const startEnrollment = jest.fn();
        global.window.TwoSoleTrader_Instance = { startReplacement: startReplacement, startEnrollment: startEnrollment };

        const link = document.querySelector('.two-company-select-different-sole-trader');
        link.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(startReplacement).toHaveBeenCalledTimes(1);
        expect(startEnrollment).not.toHaveBeenCalled();

        instance.destroy();
    });

    test('the click does not bubble into a delegated ancestor handler (same accordion-toggle guard as the reverse link)', () => {
        const instance = makeSearchInstance();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        global.window.TwoSoleTrader_Instance = { startReplacement: jest.fn() };

        const ancestorHandler = jest.fn();
        document.body.addEventListener('click', ancestorHandler);

        const link = document.querySelector('.two-company-select-different-sole-trader');
        link.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(ancestorHandler).not.toHaveBeenCalled();

        document.body.removeEventListener('click', ancestorHandler);
        instance.destroy();
    });

    test('a rapid double-click only calls startReplacement() once (re-entrancy guard, TWO-40 review finding)', () => {
        const instance = makeSearchInstance();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);

        const startReplacement = jest.fn();
        global.window.TwoSoleTrader_Instance = { startReplacement: startReplacement };

        const link = document.querySelector('.two-company-select-different-sole-trader');
        link.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
        link.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(startReplacement).toHaveBeenCalledTimes(1);

        instance.destroy();
    });

    test('the guard releases on the settle event, so a LATER click (after the flight settled) works again', () => {
        const instance = makeSearchInstance();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);

        const startReplacement = jest.fn();
        global.window.TwoSoleTrader_Instance = { startReplacement: startReplacement };

        const link = document.querySelector('.two-company-select-different-sole-trader');
        link.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
        expect(startReplacement).toHaveBeenCalledTimes(1);

        // Simulate the popup-opened (or blocked/failed) settle signal
        // TwoSoleTrader.js's notifyEnrollmentSettled() fires from every
        // terminal branch of startReplacement()'s call graph.
        document.dispatchEvent(new window.CustomEvent('two:sole-trader-flight-settled'));

        link.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
        expect(startReplacement).toHaveBeenCalledTimes(2);

        instance.destroy();
    });

    test('a missing/malformed TwoSoleTrader_Instance logs visibly instead of a silent no-op', () => {
        const instance = makeSearchInstance();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        delete global.window.TwoSoleTrader_Instance;

        const errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
        const link = document.querySelector('.two-company-select-different-sole-trader');
        link.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));

        expect(errorSpy).toHaveBeenCalled();
        errorSpy.mockRestore();
        instance.destroy();
    });
});

describe('stale link removal on a real registered-company selection (d)', () => {
    test('onCompanySelected() with an organisation number removes a stale "Select a different sole trader" link', () => {
        const instance = makeSearchInstance();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        expect(document.querySelector('.two-company-select-different-sole-trader')).not.toBeNull();

        instance.onCompanySelected(
            { preventDefault: () => {} },
            { item: { value: 'Real Registered Co', organization_number: '923456789' } }
        );

        expect(document.querySelector('.two-company-select-different-sole-trader')).toBeNull();
        instance.destroy();
    });

    test('onCompanySelected() with NO organisation number (clearSelectedCompany() path) also removes it', () => {
        const instance = makeSearchInstance();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        expect(document.querySelector('.two-company-select-different-sole-trader')).not.toBeNull();

        instance.onCompanySelected(
            { preventDefault: () => {} },
            { item: { value: 'Some Result With No Number' } }
        );

        expect(document.querySelector('.two-company-select-different-sole-trader')).toBeNull();
        instance.destroy();
    });
});

describe('popup URL (c)', () => {
    let TwoSoleTrader;

    beforeEach(() => {
        TwoSoleTrader = loadSoleTrader();
    });

    function buildTrader(overrides) {
        return new TwoSoleTrader(Object.assign({
            checkoutHost: CHECKOUT_HOST,
            orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
            ajaxToken: 'test-token',
            billingCountry: 'GB'
        }, overrides || {}));
    }

    function stubFetch() {
        const buyerCurrentSpy = jest.fn();
        global.window.fetch = (url) => {
            if (String(url).includes('soleTraderAvailability')) {
                return Promise.resolve({ json: () => Promise.resolve({ success: true, available: true }) });
            }
            if (String(url).includes('soleTraderTokens')) {
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
            if (String(url).includes('/autofill/v1/buyer/current')) {
                buyerCurrentSpy(url);
                return Promise.resolve({ ok: false, status: 404 });
            }
            return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
        };
        global.fetch = global.window.fetch;
        return buyerCurrentSpy;
    }

    test('startReplacement() mints tokens, skips getCurrentBuyer(), and opens the popup with autoselect=false', async () => {
        buildAddressForm();
        const buyerCurrentSpy = stubFetch();
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = buildTrader();
        instance.startReplacement();
        await flushPromises();
        await flushPromises();
        await flushPromises();

        expect(buyerCurrentSpy).not.toHaveBeenCalled();
        expect(openSpy).toHaveBeenCalledTimes(1);
        const url = String(openSpy.mock.calls[0][0]);
        expect(url).toContain('businessToken=');
        expect(url).toContain('autoselect=false');
        expect(String(openSpy.mock.calls[0][2])).toContain('width=700');

        instance.destroy();
    });

    test('startReplacement() on an instance with tokens already minted opens the popup directly (no re-mint, no autofill check)', async () => {
        buildAddressForm();
        const buyerCurrentSpy = stubFetch();
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = buildTrader();
        // Simulate an already-completed ordinary enrolment: tokens minted,
        // flow started, buyer lookup already resolved once.
        instance.flowStarted = true;
        instance.tokens = {
            success: true,
            autofill_token: 'af-token',
            delegation_token: 'del-token',
            signup_url: 'https://signup.example.test/',
            country: 'GB'
        };

        instance.startReplacement();
        await flushPromises();

        expect(buyerCurrentSpy).not.toHaveBeenCalled();
        expect(openSpy).toHaveBeenCalledTimes(1);
        expect(String(openSpy.mock.calls[0][0])).toContain('autoselect=false');

        instance.destroy();
    });

    test('an ordinary startEnrollment() (unaffected) still runs the autofill check and does not carry autoselect=false', async () => {
        buildAddressForm();
        const buyerCurrentSpy = stubFetch();
        const openSpy = jest.fn(() => ({ closed: false }));
        global.window.open = openSpy;

        const instance = buildTrader();
        instance.startEnrollment();
        await flushPromises();
        await flushPromises();
        await flushPromises();

        expect(buyerCurrentSpy).toHaveBeenCalledTimes(1);
        expect(openSpy).toHaveBeenCalledTimes(1);
        expect(String(openSpy.mock.calls[0][0])).not.toContain('autoselect=');
        expect(String(openSpy.mock.calls[0][2])).toContain('width=700');

        instance.destroy();
    });
});

describe('country change abandons an in-flight replacement flow (e, round-2 review finding)', () => {
    test('changing the billing country calls TwoSoleTrader_Instance.cancelEnrollment()', () => {
        const instance = makeSearchInstance();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);

        const cancelEnrollment = jest.fn();
        global.window.TwoSoleTrader_Instance = {
            startReplacement: jest.fn(),
            cancelEnrollment: cancelEnrollment
        };

        // Simulate the buyer having clicked "Select a different sole
        // trader" (a mint/lookup is now conceptually in flight for the OLD
        // country) - this test targets the country-change listener itself,
        // not startReplacement(), so the click is not required to exercise
        // the fix; the point is that cancelEnrollment() fires REGARDLESS of
        // whether a flow is actually in flight, exactly like the
        // "Registered Company" chip handler and openDropdown() already do.
        const countryField = document.querySelector("select[name='id_country']");
        expect(countryField).not.toBeNull();
        countryField.dispatchEvent(new window.Event('change', { bubbles: true }));

        expect(cancelEnrollment).toHaveBeenCalledTimes(1);

        instance.destroy();
    });
});

describe('destroy() abandons an in-flight replacement flow too (f, round-3 review finding)', () => {
    test('destroy() calls TwoSoleTrader_Instance.cancelEnrollment() - covers updatedAddressForm rebuilds, not just a country change', () => {
        const instance = makeSearchInstance();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);

        const cancelEnrollment = jest.fn();
        global.window.TwoSoleTrader_Instance = {
            startReplacement: jest.fn(),
            cancelEnrollment: cancelEnrollment
        };

        // TwoCheckoutManager.handleAddressFormUpdate() destroys and rebuilds
        // this instance on EVERY `updatedAddressForm` firing (not only a
        // country change - that's the round-2 fix's gap) - destroy() itself
        // is the one choke point common to all of those triggers.
        instance.destroy();

        expect(cancelEnrollment).toHaveBeenCalledTimes(1);
    });
});
