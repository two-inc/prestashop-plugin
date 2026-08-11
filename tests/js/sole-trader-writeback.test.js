/**
 * TWO-40 sole-trader enrolment write-back:
 *
 *  - TwoCompanySearch.adoptSoleTraderBuyer(buyer) / autoFillSoleTraderAddress() /
 *    soleTraderPairReport() - putting a completed enrolment's data into the
 *    checkout form.
 *  - TwoSoleTrader.adoptEnrolledIdentity(buyer) - the thin, fail-soft caller in
 *    applyBuyer()'s success branch.
 *
 * Fixture is a real captured `/autofill/v1/buyer/current` response (Doug,
 * 2026-08-11), not an invented shape.
 */

'use strict';

const {
    loadCompanySearch,
    loadSoleTrader,
    buildAddressesStep,
    buildAddressForm,
    stubAjax,
    releaseWidgets,
    DNI_COUNTRY_ID
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const MARKER = 'data-two-autofilled-value';

const ES_OPTION = DNI_COUNTRY_ID; // '6' - a country whose address format carries `dni`
const FR_OPTION = '8';

const REAL_BUYER = {
    billing_address: {
        apartment: '',
        building: 'Wharf Lane',
        city: 'Ashford',
        country: null,
        organization_name: null,
        postal_code: 'TN23 1AA',
        region: '',
        street: 'Wharf Lane'
    },
    company_name: 'Sole Trader Test Co',
    country_code: 'GB',
    email: 'buyer@example.test',
    first_name: 'Alex',
    last_name: 'Buyer',
    organization_number: 'TWO:ST123456789012',
    phone_number: '+440000000000',
    shipping_address: null
};

/**
 * @param {string} number
 * @returns {Object} REAL_BUYER with a real (non-`TWO:`) register number
 */
function buyerWithNumber(number) {
    return Object.assign({}, REAL_BUYER, { organization_number: number });
}

const REGISTER_NUMBER = '923456789';
const BUYER_REAL_NUMBER = buyerWithNumber(REGISTER_NUMBER);
const BUYER_INTERNAL_NUMBER = REAL_BUYER; // organization_number is TWO:-prefixed

let TwoCompanySearch;
let $;
let ajax;

beforeEach(() => {
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    ajax = stubAjax($);
    window.twopayment = {
        checkout_host: CHECKOUT_HOST,
        countries: { 17: 'gb', 8: 'fr', 1: 'de', 6: 'es', 144: 'mx' }
    };
});

afterEach(() => {
    releaseWidgets($);
    ajax.restore();
    document.body.innerHTML = '';
    delete window.twopayment;
    delete window.TwoCheckoutManager_Instance;
});

function mount(extraConfig) {
    return new TwoCompanySearch(Object.assign({
        checkoutHost: CHECKOUT_HOST,
        addressLookupEnabled: true
    }, extraConfig || {}));
}

function companyField() {
    return $("input[name='company']");
}

function organizationField() {
    return $("input[name='companyid']");
}

function identifierField() {
    return $("input[name='dni']");
}

describe('adoptSoleTraderBuyer(): the happy path, shipping pass', () => {
    test('writes the company name into the visible field, marked as autofilled', () => {
        buildAddressesStep({ editing: 'delivery' });

        const wrote = mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(wrote).toBe(true);
        expect(companyField().val()).toBe('Sole Trader Test Co');
        expect(companyField().attr(MARKER)).toBe('Sole Trader Test Co');
    });

    test('writes the organisation number into the hidden field, tagged with what is actually in the company field', () => {
        buildAddressesStep({ editing: 'delivery' });

        mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(organizationField().val()).toBe(REGISTER_NUMBER);
        expect(organizationField().attr('data-two-company-name')).toBe(companyField().val());
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');
    });

    test('writes the registered address - street, postcode, city - each marked', () => {
        buildAddressesStep({ editing: 'delivery' });

        mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect($("input[name='address1']").val()).toBe('Wharf Lane');
        expect($("input[name='address1']").attr(MARKER)).toBe('Wharf Lane');
        expect($("input[name='postcode']").val()).toBe('TN23 1AA');
        expect($("input[name='postcode']").attr(MARKER)).toBe('TN23 1AA');
        expect($("input[name='city']").val()).toBe('Ashford');
        expect($("input[name='city']").attr(MARKER)).toBe('Ashford');
    });
});

describe('the survival test: the pair must outlive an ordinary input event, but not a genuine retype', () => {
    test('a plain input event on the company field (re-firing the stale-selection guard) does not wipe the pair', () => {
        buildAddressesStep({ editing: 'delivery' });
        mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);
        expect(organizationField().val()).toBe(REGISTER_NUMBER);

        // clearStaleOrganizationSelection() is bound to this event. Firing it
        // with the field's value UNCHANGED is the regression this pins: an
        // untagged (or wrongly tagged) companyid used to be read as stale and
        // wiped on the buyer's very next keystroke, however irrelevant.
        companyField().get(0).dispatchEvent(new window.Event('input', { bubbles: true }));

        expect(organizationField().val()).toBe(REGISTER_NUMBER);
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');
    });

    test('but the buyer actually retyping a different name still clears it - the guard is not disabled', () => {
        buildAddressesStep({ editing: 'delivery' });
        mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        companyField().val('Someone Elses Shop');
        companyField().get(0).dispatchEvent(new window.Event('input', { bubbles: true }));

        expect(organizationField().val()).toBe('');
        expect(organizationField().attr('data-two-company-name')).toBeUndefined();
    });
});

describe('the internal (`TWO:`) identifier is never shown to the buyer, a real one always is', () => {
    test('a TWO:-prefixed number is not written into the visible identification field, but does reach the hidden pairing', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });

        mount().adoptSoleTraderBuyer(BUYER_INTERNAL_NUMBER);

        expect(identifierField().val()).toBe('');
        expect(identifierField().attr(MARKER)).toBeUndefined();
        expect(organizationField().val()).toBe('TWO:ST123456789012');
    });

    test('a real register number IS written into the visible identification field, marked', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });

        mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(identifierField().val()).toBe(REGISTER_NUMBER);
        expect(identifierField().attr(MARKER)).toBe(REGISTER_NUMBER);
    });
});

describe('the address-lookup toggle', () => {
    test('OFF: address and the visible identification field are untouched, but the company name and hidden pairing still write', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });

        mount({ addressLookupEnabled: false }).adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(companyField().val()).toBe('Sole Trader Test Co');
        expect(organizationField().val()).toBe(REGISTER_NUMBER);
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');

        expect($("input[name='address1']").val()).toBe('');
        expect($("input[name='postcode']").val()).toBe('');
        expect($("input[name='city']").val()).toBe('');
        expect(identifierField().val()).toBe('');
    });
});

describe('the country is deliberately never touched', () => {
    test('even though the buyer is registered in GB and the form renders a different country', () => {
        buildAddressesStep({ editing: 'delivery', countryId: FR_OPTION });
        expect($("select[name='id_country']").val()).toBe(FR_OPTION);

        mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect($("select[name='id_country']").val()).toBe(FR_OPTION);
        expect($("select[name='id_country']").attr(MARKER)).toBeUndefined();
    });
});

describe('runs the same on the address-editor page, which has no .two-sole-trader container at all', () => {
    test('writes company, hidden pairing and address on a bare form', () => {
        buildAddressForm();
        expect(document.querySelector('.two-sole-trader')).toBeNull();

        const wrote = mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(wrote).toBe(true);
        expect(companyField().val()).toBe('Sole Trader Test Co');
        expect(organizationField().val()).toBe(REGISTER_NUMBER);
        expect($("input[name='address1']").val()).toBe('Wharf Lane');
    });
});

describe('shipping_address is a fallback only, and a null one blanks nothing', () => {
    test('fills from shipping_address when billing_address is absent', () => {
        buildAddressesStep({ editing: 'delivery' });
        const buyer = Object.assign({}, BUYER_REAL_NUMBER, {
            billing_address: null,
            shipping_address: { street: 'Fallback St', postal_code: 'AB1 1AB', city: 'Fallback City' }
        });

        mount().adoptSoleTraderBuyer(buyer);

        expect($("input[name='address1']").val()).toBe('Fallback St');
        expect($("input[name='postcode']").val()).toBe('AB1 1AB');
        expect($("input[name='city']").val()).toBe('Fallback City');
    });

    test('a null shipping_address alongside a null billing_address blanks nothing already in the form', () => {
        buildAddressesStep({ editing: 'delivery', address1: 'Buyer Typed Street' });
        const buyer = Object.assign({}, BUYER_REAL_NUMBER, { billing_address: null, shipping_address: null });

        mount().adoptSoleTraderBuyer(buyer);

        expect($("input[name='address1']").val()).toBe('Buyer Typed Street');
    });
});

describe('the secondary (invoice) address: scoped writes and the cart-scoped mirror record', () => {
    test('on the invoice pass, with the buyer stating the addresses differ, writes land in that form and mirror_writes gains all five fields', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });

        mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect($("#invoice-address input[name='company']").val()).toBe('Sole Trader Test Co');
        expect($("#invoice-address input[name='dni']").val()).toBe(REGISTER_NUMBER);
        expect($("#invoice-address input[name='address1']").val()).toBe('Wharf Lane');

        expect(window.twopayment.mirror_writes).toEqual({
            company: 'Sole Trader Test Co',
            organization: REGISTER_NUMBER,
            address1: 'Wharf Lane',
            postcode: 'TN23 1AA',
            city: 'Ashford'
        });
    });

    test('on a plain delivery form (no secondary address on screen), mirror_writes is never touched', () => {
        buildAddressesStep({ editing: 'delivery' });

        mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(window.twopayment.mirror_writes).toBeUndefined();
    });
});

describe('TwoSoleTrader.adoptEnrolledIdentity(): fails soft, never throws', () => {
    let TwoSoleTrader;

    beforeEach(() => {
        TwoSoleTrader = loadSoleTrader();
    });

    test('returns false without throwing when there is no TwoCheckoutManager_Instance at all', () => {
        delete window.TwoCheckoutManager_Instance;

        let result;
        expect(() => {
            result = TwoSoleTrader.prototype.adoptEnrolledIdentity.call({}, BUYER_REAL_NUMBER);
        }).not.toThrow();
        expect(result).toBe(false);
    });

    test('returns false without throwing when the manager exists but has no companySearch', () => {
        window.TwoCheckoutManager_Instance = {};

        let result;
        expect(() => {
            result = TwoSoleTrader.prototype.adoptEnrolledIdentity.call({}, BUYER_REAL_NUMBER);
        }).not.toThrow();
        expect(result).toBe(false);
    });

    test('wires through to a real companySearch.adoptSoleTraderBuyer and relays its result', () => {
        window.TwoCheckoutManager_Instance = {
            companySearch: { adoptSoleTraderBuyer: jest.fn(() => true) }
        };

        const result = TwoSoleTrader.prototype.adoptEnrolledIdentity.call({}, BUYER_REAL_NUMBER);

        expect(result).toBe(true);
        expect(window.TwoCheckoutManager_Instance.companySearch.adoptSoleTraderBuyer)
            .toHaveBeenCalledWith(BUYER_REAL_NUMBER);
    });
});
