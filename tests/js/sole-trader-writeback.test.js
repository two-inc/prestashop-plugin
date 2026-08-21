/**
 * TWO-40 sole-trader enrolment write-back.
 *
 * Fixture is a real captured `/autofill/v1/buyer/current` response, not an
 * invented shape.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const {
    loadCompanySearch,
    loadSoleTrader,
    buildAddressesStep,
    buildAddressForm,
    buildPaymentTileWithSoleTraderAnswer,
    flushPromises,
    stubAjax,
    releaseWidgets,
    DNI_COUNTRY_ID,
    REPO_ROOT
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

function buyerWithNumber(number) {
    return Object.assign({}, REAL_BUYER, { organization_number: number });
}

const REGISTER_NUMBER = '923456789';
const BUYER_REAL_NUMBER = buyerWithNumber(REGISTER_NUMBER);
const BUYER_INTERNAL_NUMBER = REAL_BUYER; // organization_number is TWO:-prefixed

// No trading name. Carries a different registered address than
// REAL_BUYER's, so an address fill is observable rather than
// indistinguishable from "nothing changed".
const BUYER_NAMELESS = Object.assign({}, BUYER_REAL_NUMBER, {
    company_name: '',
    billing_address: {
        apartment: '',
        building: 'Second Registered Building',
        city: 'Dover',
        country: null,
        organization_name: null,
        postal_code: 'CT16 1AA',
        region: '',
        street: 'Second Registered Street'
    }
});

// REAL_BUYER's `building` is byte-identical to its `street`, which makes
// line-routing unobservable. This variant gives street/building/apartment/
// region four distinct values so routing can be asserted.
const BUYER_DISTINCT_BUILDING = Object.assign({}, BUYER_REAL_NUMBER, {
    billing_address: Object.assign({}, REAL_BUYER.billing_address, {
        building: 'Unit 4 Wharf Court',
        apartment: 'Flat 9',
        region: 'Kent'
    })
});

/**
 * @param {Object} overrides merged over REAL_BUYER's captured billing address
 * @returns {Object} a buyer carrying that address and a real register number
 */
function buyerWithAddress(overrides) {
    return Object.assign({}, BUYER_REAL_NUMBER, {
        billing_address: Object.assign({}, REAL_BUYER.billing_address, overrides)
    });
}

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

function hintField() {
    return $('.two-company-id-hint');
}

/**
 * Give the module's fire-and-forget endpoint calls somewhere to go.
 *
 * clearPersistedCompany() and recordMirrorWrites() both return early without
 * these two, so a test asserting that one of them did NOT happen has to install
 * them or it passes with the call site deleted.
 *
 * @returns {void}
 */
function enableEndpointCalls() {
    window.twopayment.order_intent_url = 'https://shop.example.test/module/twopayment/orderintent';
    window.twopayment.ajax_token = 'test-token';
}

/**
 * The `$.ajax` calls carrying a given orderintent action.
 *
 * @param {string} action
 * @returns {Array}
 */
function ajaxCallsFor(action) {
    return ajax.calls.filter(
        call => call.settings && call.settings.data && call.settings.data.action === action
    );
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

/**
 * TWO-40, Doug's ruling, OPTION A. An internal (`TWO:`) organisation number is
 * handled EXACTLY like any other everywhere except one place: it is never written
 * into the visible `dni` field.
 *
 * Why that one field, verified against core: `Address` declares `dni` with
 * `validate => isDniLite, size => 16`, and `Validate::isDniLite()` is
 * `/^[0-9A-Za-z-.]{1,16}$/U`. `TWO:ST123456789012` fails it twice - a colon is not in
 * the character class, and it is 18 characters - so core REFUSES TO SAVE THE ADDRESS,
 * and the error lands on a field the round that wrote it there was hiding: an
 * invisible, unfixable dead-end at checkout. It was pointless anyway, because this
 * plugin's own reader validates `dni` against `/^[A-Z0-9\-]{5,20}$/i` and rejects the
 * colon too. And it is the wrong field: `isNeedDniByCountryId()` is country-level, so
 * `dni` is required of EVERY buyer in such a country - it is the buyer's own fiscal
 * number, which they fill themselves.
 *
 * NOT the earlier reverted approach, which ALSO withheld the pairing and the name and
 * broke the "name and number travel together" invariant. Everything but `dni` stays
 * uniform here, and the tests below pin that uniformity as hard as they pin the skip.
 */
describe('an internal (`TWO:`) identifier: uniform everywhere except the visible `dni`', () => {
    test('a TWO:-prefixed number is NOT written into the identification field', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });

        mount().adoptSoleTraderBuyer(BUYER_INTERNAL_NUMBER);

        expect(identifierField().length).toBe(1);
        expect(identifierField().val()).toBe('');
        // Unclaimed too: a marker on an empty field would let the clear path believe
        // it had work of its own to undo.
        expect(identifierField().attr(MARKER)).toBeUndefined();
    });

    /**
     * THE KEY ONE. The hidden `companyid` and its `data-two-company-name` pairing tag
     * are written for a `TWO:` value exactly as for any other. An untagged non-empty
     * `companyid` is read by clearStaleOrganizationSelection() as "the buyer has
     * edited past a stale selection" and wiped on their very next input event - the
     * single mechanism that killed three previous attempts at this write-back.
     */
    test('but the hidden pairing IS written for it, byte-identically, with its name tag', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });

        mount().adoptSoleTraderBuyer(BUYER_INTERNAL_NUMBER);

        expect(organizationField().val()).toBe('TWO:ST123456789012');
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');
        expect(companyField().val()).toBe('Sole Trader Test Co');
    });

    test('and the pair survives an input event in the company field', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });
        mount().adoptSoleTraderBuyer(BUYER_INTERNAL_NUMBER);

        companyField().get(0).dispatchEvent(new window.Event('input', { bubbles: true }));

        expect(organizationField().val()).toBe('TWO:ST123456789012');
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');
    });

    /**
     * There is NO visibility rule any more, and this is the inverse of the test that
     * used to stand here. Re-introducing the hide fails this. It could never have
     * been complete: core renders `dni` into address blocks, invoice PDFs and order
     * confirmation emails through `AddressFormat::generateAddress()`, which no CSS
     * rule of ours reaches.
     */
    test('the identification field stays VISIBLE - there is nothing to hide', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });

        mount().adoptSoleTraderBuyer(BUYER_INTERNAL_NUMBER);

        const group = identifierField().closest('.form-group');
        expect(group.length).toBe(1);
        expect(group.get(0).style.display).toBe('');
        expect(window.getComputedStyle(group.get(0)).display).not.toBe('none');
        expect(identifierField().get(0).style.display).toBe('');
        // Nor is the buyer's label orphaned: the field it belongs to is still there
        // for them to fill in themselves.
        expect(group.find('label').length).toBe(1);
    });

    test('a real register number IS written into the visible identification field, marked, and left visible', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });

        mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(identifierField().val()).toBe(REGISTER_NUMBER);
        expect(identifierField().attr(MARKER)).toBe(REGISTER_NUMBER);
        const group = identifierField().closest('.form-group');
        expect(group.get(0).style.display).toBe('');
    });
});

describe('the address-lookup toggle', () => {
    /**
     * TWO-40 follow-up (live bug reported by Doug 2026-08-12): the
     * address-lookup switch (PS_TWO_ADDRESS_LOOKUP) governs whether an
     * ORDINARY company-SEARCH selection writes into the address step, and
     * `Twopayment::getAddressLookupEnabled()` forces it to '0' outright once
     * company search has relocated out of the address area and into the
     * payment tile - which TWO-40 made the ONLY place the sole-trader entry
     * point lives. Gating the sole-trader completion's address write on this
     * same switch meant every shop running the current design had it
     * permanently off, and the buyer's registered address silently never
     * reached the form. This test used to assert exactly that no-write
     * outcome as correct; it now asserts the fix - the switch has nothing to
     * say about a signup completion, so address, the visible identification
     * field and everything else all write regardless of its value.
     */
    test('OFF: address and the visible identification field still write - this switch has nothing to say about a signup completion', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });

        mount({ addressLookupEnabled: false }).adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(companyField().val()).toBe('Sole Trader Test Co');
        expect(organizationField().val()).toBe(REGISTER_NUMBER);
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');

        expect($("input[name='address1']").val()).toBe('Wharf Lane');
        expect($("input[name='postcode']").val()).toBe('TN23 1AA');
        expect($("input[name='city']").val()).toBe('Ashford');
        expect(identifierField().val()).toBe(REGISTER_NUMBER);
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

describe('adoptSoleTraderBuyer(): nothing to adopt', () => {
    test('a response with no organisation number writes nothing and says so', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });

        const wrote = mount().adoptSoleTraderBuyer(buyerWithNumber(''));

        expect(wrote).toBe(false);
        expect(companyField().val()).toBe('');
        expect(organizationField().val()).toBe('');
        expect(identifierField().val()).toBe('');
        expect($("input[name='address1']").val()).toBe('');
    });

    test.each([
        ['null', null],
        ['undefined', undefined],
        ['a string', 'Sole Trader Test Co'],
        ['a number', 42]
    ])('a non-object buyer (%s) writes nothing and says so', (_label, buyer) => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });

        const wrote = mount().adoptSoleTraderBuyer(buyer);

        expect(wrote).toBe(false);
        expect(companyField().val()).toBe('');
        expect(organizationField().val()).toBe('');
    });

    test('a DESTROYED instance writes nothing and says so', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });
        const search = mount();
        search.destroy();

        const wrote = search.adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(wrote).toBe(false);
        expect(organizationField().val()).toBe('');
        expect(identifierField().val()).toBe('');
        expect($("input[name='address1']").val()).toBe('');
    });
});

describe('a sole trader with NO trading name: no identity, no residue, but the address still lands', () => {
    /**
     * The pre-existing state every test here starts from: a REAL selection this
     * class itself wrote a moment ago, which the buyer has now moved off by
     * enrolling as a nameless sole trader. Built through adoptSoleTraderBuyer()
     * rather than by setting fields by hand - a hand-set stand-in reaches one of
     * the three places a selection lives and leaves the other two empty, and every
     * assertion about what the clear does to them then passes vacuously.
     *
     * @returns {Object} the live TwoCompanySearch instance
     */
    function withStandingSelection() {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });
        enableEndpointCalls();
        const search = mount();
        search.adoptSoleTraderBuyer(BUYER_REAL_NUMBER);
        // The state under test only exists if all four of these landed.
        expect(organizationField().val()).toBe(REGISTER_NUMBER);
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');
        expect(identifierField().val()).toBe(REGISTER_NUMBER);
        expect(hintField().text()).toBe(REGISTER_NUMBER);

        return search;
    }

    test('the hidden pair and its tag are dropped rather than left pointing at the abandoned company', () => {
        const search = withStandingSelection();

        search.adoptSoleTraderBuyer(BUYER_NAMELESS);

        expect(organizationField().val()).toBe('');
        expect(organizationField().attr('data-two-company-name')).toBeUndefined();
    });

    test('the visible company-number hint goes with the number behind it', () => {
        const search = withStandingSelection();

        search.adoptSoleTraderBuyer(BUYER_NAMELESS);

        expect(hintField().text()).toBe('');
        expect(hintField().hasClass('two-company-id-hint--visible')).toBe(false);
    });

    test('the identification number the lookup itself wrote is cleared', () => {
        const search = withStandingSelection();

        search.adoptSoleTraderBuyer(BUYER_NAMELESS);

        expect(identifierField().val()).toBe('');
        expect(identifierField().attr(MARKER)).toBeUndefined();
    });

    test('an identification number the BUYER typed is not touched by that clear', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });
        enableEndpointCalls();
        identifierField().val('BUYER-OWN-ID');

        mount().adoptSoleTraderBuyer(BUYER_NAMELESS);

        expect(identifierField().val()).toBe('BUYER-OWN-ID');
    });

    test('the registered ADDRESS is still filled - only the identity half is withheld', () => {
        const search = withStandingSelection();

        const wrote = search.adoptSoleTraderBuyer(BUYER_NAMELESS);

        expect(wrote).toBe(true);
        // The BUILDING takes the first line and the street moves to the second - the
        // routing rule, which applies here as everywhere. This fixture renders no
        // `address2`, so the street simply has nowhere to go.
        expect($("input[name='address1']").val()).toBe('Second Registered Building');
        expect($("input[name='postcode']").val()).toBe('CT16 1AA');
        expect($("input[name='city']").val()).toBe('Dover');
    });

    test('the SERVER session company that `saveCompany` has just written is NOT cleared', () => {
        const search = withStandingSelection();

        search.adoptSoleTraderBuyer(BUYER_NAMELESS);

        // clearSelectedCompany()'s reach is the thing being refused here, and its
        // session half is the part that would destroy the enrolment: the clear
        // would land after the `saveCompany` this adoption is completing.
        expect(ajaxCallsFor('clearCompany')).toEqual([]);
    });
});

describe('soleTraderPairReport(): three outcomes for the identification field, never two', () => {
    /**
     * recordMirrorWrites() reads a reported '' as a positive statement - "nothing of
     * ours is in that field any more" - and an ABSENT key as "unchanged". So the
     * three cases below are genuinely distinct, and conflating any pair of them
     * either pins the address or leaves the record claiming a number the form does
     * not hold:
     *
     *  - LANDED  -> `organization: <number>`
     *  - EMPTY   -> `organization: ''`   (the retraction; truthful)
     *  - ANOTHER
     *    VALUE   -> key absent            (not ours to claim or to retract)
     */
    test('LANDED: a real number that reached the field is reported as itself', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });

        mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        const record = window.twopayment.mirror_writes;
        expect($("#invoice-address input[name='dni']").val()).toBe(REGISTER_NUMBER);
        expect(record.organization).toBe(REGISTER_NUMBER);
    });

    /**
     * EMPTY, for an internal identifier (TWO-40, Option A). A `TWO:` number never
     * enters the visible `dni` field, so the report reads the field back as empty and
     * says `''` - the truthful answer, and the reason the report is read off the form
     * rather than assumed from having called the writer.
     *
     * `''` and not an ABSENT key: `''` is the only value that retracts a number an
     * earlier mirror pass recorded writing there. Absent means "unchanged", which
     * would leave the record claiming a number the form does not hold - the exact
     * mismatch the pin reads as buyer tampering.
     *
     * The NAME half is still reported, because it is still written. Only `dni` is
     * skipped.
     */
    test('EMPTY: an internal (`TWO:`) number is reported as \'\', because it never reached the field', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });

        mount().adoptSoleTraderBuyer(BUYER_INTERNAL_NUMBER);

        expect($("#invoice-address input[name='dni']").val()).toBe('');
        expect(window.twopayment.mirror_writes).toEqual({
            company: 'Sole Trader Test Co',
            organization: '',
            address1: 'Wharf Lane',
            postcode: 'TN23 1AA',
            city: 'Ashford'
        });
        // And the pair the order actually travels on is intact.
        expect(organizationField().val()).toBe('TWO:ST123456789012');
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');
    });

    test('LANDED: a real register number is reported as itself', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });

        mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect($("#invoice-address input[name='dni']").val()).toBe(REGISTER_NUMBER);
        expect(window.twopayment.mirror_writes.organization).toBe(REGISTER_NUMBER);
    });

    test('EMPTY: the nameless-buyer clear empties the field, so `organization` is RETRACTED', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        enableEndpointCalls();
        const search = mount();

        // A real selection this class wrote and recorded a moment ago - the state the
        // retraction has to undo.
        search.adoptSoleTraderBuyer(BUYER_REAL_NUMBER);
        expect($("#invoice-address input[name='dni']").val()).toBe(REGISTER_NUMBER);
        expect(window.twopayment.mirror_writes.organization).toBe(REGISTER_NUMBER);

        search.adoptSoleTraderBuyer(BUYER_NAMELESS);

        // '' is the truthful report, and the only thing that retracts the number the
        // pass above recorded writing there. An ABSENT key would leave the record
        // claiming a number the form no longer holds - the exact mismatch the pin
        // reads as buyer tampering.
        expect($("#invoice-address input[name='dni']").val()).toBe('');
        const record = window.twopayment.mirror_writes;
        expect(Object.prototype.hasOwnProperty.call(record, 'organization')).toBe(true);
        expect(record).toEqual({
            // Unchanged from the pass above: a nameless buyer writes no name, so the
            // company half of the record is neither restated nor retracted.
            company: 'Sole Trader Test Co',
            organization: '',
            address1: 'Second Registered Building',
            postcode: 'CT16 1AA',
            city: 'Dover'
        });
    });

    /**
     * TWO-40 follow-up (live bug reported by Doug 2026-08-12): the
     * address-lookup switch does not gate the sole-trader completion (see
     * the identical note on the "the address-lookup toggle" describe block
     * above) - so a real register number reaches `dni` and is reported
     * exactly as it would with the switch on.
     */
    test('LANDED even with the switch OFF: this switch has nothing to say about a signup completion', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });

        mount({ addressLookupEnabled: false }).adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect($("#invoice-address input[name='dni']").val()).toBe(REGISTER_NUMBER);
        const record = window.twopayment.mirror_writes;
        expect(record).toEqual({
            company: 'Sole Trader Test Co',
            organization: REGISTER_NUMBER,
            address1: 'Wharf Lane',
            postcode: 'TN23 1AA',
            city: 'Ashford'
        });
    });

    /**
     * TWO-40 follow-up (live bug reported by Doug 2026-08-12): with the gate
     * bypassed, `writeOrganizationToAddressIdentifiers(number, false, ...)` is
     * reached with `onlyIfEmpty` false - an existing value in the buyer's own
     * identification field is a signup completion overwriting it
     * unconditionally, same as every other field this method touches, not a
     * value the gate declines to replace.
     */
    test('OVERWRITES a value already in the identification field - a signup completion is not a search selection the buyer\'s own input should survive', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        $("#invoice-address input[name='dni']").val('BUYER-OWN-ID');

        mount({ addressLookupEnabled: false }).adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect($("#invoice-address input[name='dni']").val()).toBe(REGISTER_NUMBER);
        const record = window.twopayment.mirror_writes;
        expect(record.organization).toBe(REGISTER_NUMBER);
    });

    test('ANOTHER VALUE: a form with NO identification field omits the key too', () => {
        // Germany, the default render: nothing to read back, so there is nothing to
        // claim and nothing to retract.
        buildAddressesStep({ editing: 'invoice' });

        mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect($("#invoice-address input[name='dni']").length).toBe(0);
        const record = window.twopayment.mirror_writes;
        expect(Object.prototype.hasOwnProperty.call(record, 'organization')).toBe(false);
        expect(record).toEqual({
            company: 'Sole Trader Test Co',
            address1: 'Wharf Lane',
            postcode: 'TN23 1AA',
            city: 'Ashford'
        });
    });
});

describe('the invoice form is on screen but cannot be scoped to one address block', () => {
    /**
     * A theme that flattens core's block containers away AND drops its ids: the
     * only candidate scope left is the step wrapper, which still contains the
     * delivery side, so the scope resolution FAILS CLOSED. The identity writes are
     * document-wide by design, but the ADDRESS fill is skipped outright rather
     * than written into a scope spanning two addresses.
     */
    test('the address fill is skipped, and nothing is reported into the mirror record', () => {
        buildAddressesStep({ editing: 'invoice', blockContainers: false, blockIds: false });
        const search = mount();
        expect(search.visibleAddressFormType()).toBe('invoice');
        expect(search.visibleAddressFormRoot()).toBeNull();
        expect(search.secondaryAddressFormRoot()).toBeNull();

        const wrote = search.adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(wrote).toBe(true);
        expect(companyField().val()).toBe('Sole Trader Test Co');
        expect($("input[name='address1']").val()).toBe('');
        expect($("input[name='postcode']").val()).toBe('');
        expect($("input[name='city']").val()).toBe('');
        expect(window.twopayment.mirror_writes).toBeUndefined();
    });
});

/**
 * Doug's ADDRESS ROUTING rule (TWO-40): the building/apartment locator is the more
 * specific of the two and takes the FIRST line, pushing the street to the second.
 * Where neither is given the street takes the first line and the second is left
 * alone.
 *
 * `address2` is a real PrestaShop address field - core renders it for the country
 * formats that ask for it - and the harness fixture omits it, so every test here
 * inserts one. Without it a fill that reached `address2` would be invisible.
 */
describe('address routing: a building or apartment takes the first line, the street moves to the second', () => {
    /**
     * @returns {void}
     */
    function addSecondLine() {
        $("input[name='address1']").after("<input type='text' name='address2' value='' />");
    }

    function line1() {
        return $("input[name='address1']");
    }

    function line2() {
        return $("input[name='address2']");
    }

    test('both a building and an apartment: joined most-specific-first on line one, street on line two', () => {
        buildAddressesStep({ editing: 'delivery' });
        addSecondLine();

        mount().adoptSoleTraderBuyer(BUYER_DISTINCT_BUILDING);

        // How an address is read aloud: "Flat 9, Unit 4 Wharf Court".
        expect(line1().val()).toBe('Flat 9, Unit 4 Wharf Court');
        expect(line1().attr(MARKER)).toBe('Flat 9, Unit 4 Wharf Court');
        expect(line2().val()).toBe('Wharf Lane');
        expect(line2().attr(MARKER)).toBe('Wharf Lane');
    });

    test('a building alone takes line one', () => {
        buildAddressesStep({ editing: 'delivery' });
        addSecondLine();

        mount().adoptSoleTraderBuyer(buyerWithAddress({
            building: 'Unit 4 Wharf Court',
            apartment: '',
            street: 'Wharf Lane'
        }));

        expect(line1().val()).toBe('Unit 4 Wharf Court');
        expect(line2().val()).toBe('Wharf Lane');
    });

    test('an apartment alone takes line one', () => {
        buildAddressesStep({ editing: 'delivery' });
        addSecondLine();

        mount().adoptSoleTraderBuyer(buyerWithAddress({
            building: '',
            apartment: 'Flat 9',
            street: 'Wharf Lane'
        }));

        expect(line1().val()).toBe('Flat 9');
        expect(line2().val()).toBe('Wharf Lane');
    });

    test('neither: the street takes line one and line two is left alone', () => {
        buildAddressesStep({ editing: 'delivery' });
        addSecondLine();

        mount().adoptSoleTraderBuyer(buyerWithAddress({
            building: '',
            apartment: '',
            street: 'Wharf Lane'
        }));

        expect(line1().val()).toBe('Wharf Lane');
        expect(line1().attr(MARKER)).toBe('Wharf Lane');
        expect(line2().val()).toBe('');
        expect(line2().attr(MARKER)).toBeUndefined();
    });

    /**
     * NO DE-DUPLICATION, on Doug's explicit ruling. The captured response is exactly
     * this shape - `building` byte-identical to `street` - and it is valid for an
     * address to carry the same text on both lines. An earlier round added a dedup
     * that suppressed the second line, and it was rejected: suppressing it discards
     * real data.
     */
    test('a building EQUAL to the street writes that same text to BOTH lines - no dedup', () => {
        buildAddressesStep({ editing: 'delivery' });
        addSecondLine();

        mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(REAL_BUYER.billing_address.building).toBe(REAL_BUYER.billing_address.street);
        expect(line1().val()).toBe('Wharf Lane');
        expect(line2().val()).toBe('Wharf Lane');
        expect(line1().attr(MARKER)).toBe('Wharf Lane');
        expect(line2().attr(MARKER)).toBe('Wharf Lane');
    });

    /**
     * The ORDINARY company lookup, whose addresses carry no `building`, `apartment`
     * or `address_line_2` key at all. Those coalesce to '', and an empty incoming
     * value may only clear a value this class wrote itself - so a second line the
     * BUYER typed survives a company selection.
     */
    test('a company-lookup address does not blank an address2 the buyer typed', () => {
        buildAddressesStep({ editing: 'delivery' });
        addSecondLine();
        line2().val('Buyer Second Line');

        mount().autoFillAddress([{ street: 'Register Street', postal_code: 'RG1 1RG', city: 'Reading' }]);

        expect(line1().val()).toBe('Register Street');
        expect(line2().val()).toBe('Buyer Second Line');
        expect(line2().attr(MARKER)).toBeUndefined();
    });
});

/**
 * Doug's REGION routing rule (TWO-40): the response's `region` must LAND rather
 * than be dropped. Into the form's own state/county select where the country has
 * one, and appended to the city where it does not - most countries (GB among them)
 * render no state field at all, and the alternative to appending is losing it.
 */
describe('region routing: the state select where there is one, the city where there is not', () => {
    /**
     * A state select in the editable form, in core's own shape.
     *
     * @param {Array<Array<string>>} options `[value, text, isoAttr]` triples
     * @returns {void}
     */
    function addStateSelect(options) {
        const html = ['<select name="id_state">', '<option value="" selected>-</option>']
            .concat(options.map(([value, text, iso]) => '<option value="' + value + '"'
                + (iso ? ' data-iso-code="' + iso + '"' : '') + '>' + text + '</option>'))
            .concat(['</select>'])
            .join('');
        $("input[name='city']").after(html);
    }

    function stateSelect() {
        return $("select[name='id_state']");
    }

    test('a matching option is selected, and the record carries the option TEXT rather than its shop-local value', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        addStateSelect([['31', 'Kent'], ['32', 'Surrey']]);

        mount().adoptSoleTraderBuyer(BUYER_DISTINCT_BUILDING);

        expect(stateSelect().val()).toBe('31');
        // `state: 'Kent'`, never `state: '31'`: PrestaShop state ids are shop-local, so
        // a record written on one shop would be meaningless on another.
        expect(window.twopayment.mirror_writes.state).toBe('Kent');
    });

    test('matched on the visible text, trimmed and case-folded', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        addStateSelect([['31', '  KENT  ']]);

        mount().adoptSoleTraderBuyer(BUYER_DISTINCT_BUILDING);

        expect(stateSelect().val()).toBe('31');
        expect(window.twopayment.mirror_writes.state).toBe('KENT');
    });

    test('or on a data-iso-code, for a registry that returns the abbreviation', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        addStateSelect([['9', 'California', 'CA'], ['10', 'Nevada', 'NV']]);

        mount().adoptSoleTraderBuyer(buyerWithAddress({ region: 'CA' }));

        expect(stateSelect().val()).toBe('9');
        expect(window.twopayment.mirror_writes.state).toBe('California');
    });

    test('a region matching NO option writes nothing rather than guessing', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        addStateSelect([['32', 'Surrey']]);

        mount().adoptSoleTraderBuyer(BUYER_DISTINCT_BUILDING);

        expect(stateSelect().val()).toBe('');
        expect(stateSelect().attr(MARKER)).toBeUndefined();
        expect(Object.prototype.hasOwnProperty.call(window.twopayment.mirror_writes, 'state')).toBe(false);
        // And it does not fall back to the city append: the form HAS a state field.
        expect($("#invoice-address input[name='city']").val()).toBe('Ashford');
    });

    test('a form with NO state select appends the region to the city, marked and recorded', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        expect($("select[name='id_state']").length).toBe(0);

        mount().adoptSoleTraderBuyer(BUYER_DISTINCT_BUILDING);

        const city = $("#invoice-address input[name='city']");
        expect(city.val()).toBe('Ashford, Kent');
        expect(city.attr(MARKER)).toBe('Ashford, Kent');
        expect(window.twopayment.mirror_writes.city).toBe('Ashford, Kent');
    });

    test('and never appends twice - a city already ending in the region is left alone', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const search = mount();

        search.adoptSoleTraderBuyer(BUYER_DISTINCT_BUILDING);
        expect($("#invoice-address input[name='city']").val()).toBe('Ashford, Kent');

        // A second pass, exactly as a re-mount produces.
        search.adoptSoleTraderBuyer(BUYER_DISTINCT_BUILDING);

        expect($("#invoice-address input[name='city']").val()).toBe('Ashford, Kent');
    });

    test('an empty region writes nothing at all - the captured response carries one', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });

        mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(REAL_BUYER.billing_address.region).toBe('');
        expect($("#invoice-address input[name='city']").val()).toBe('Ashford');
        expect(window.twopayment.mirror_writes.city).toBe('Ashford');
    });

    /**
     * TWO-40 follow-up (live bug reported by Doug 2026-08-12): see the
     * identical note on the "the address-lookup toggle" describe block above
     * - the switch does not gate a signup completion, region included.
     */
    test('the address-lookup switch being off does not suppress the region - this switch has nothing to say about a signup completion', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        addStateSelect([['31', 'Kent']]);

        mount({ addressLookupEnabled: false }).adoptSoleTraderBuyer(BUYER_DISTINCT_BUILDING);

        expect(stateSelect().val()).toBe('31');
    });
});

describe('the scoped writes reach ONE address block and no other', () => {
    /**
     * A second address block, holding the fields the scoped writes target.
     *
     * Core renders one editable address form and a radio SELECTOR (no address
     * inputs) for the other side, so on core markup a document-wide write and a
     * scoped one are indistinguishable - which is exactly why this suite could not
     * tell them apart. This puts a second set of those inputs in the document so
     * the scope is observable. That is the state the scope machinery
     * (ADDRESS_BLOCK_SELECTOR, visibleAddressFormRoot) exists for: a theme, a
     * module, or a future core that leaves the other address's fields on the page.
     *
     * Deliberately WITHOUT a `company` input: `this.companyField` is a
     * document-wide selector on purpose (see adoptSoleTraderBuyer's own comment on
     * why `.val()` writing every match is the accepted behaviour there), so a
     * second company input would be asserting against a documented decision rather
     * than against the scoping.
     *
     * @returns {void}
     */
    function appendOtherAddressBlock() {
        document.querySelector('.js-address-form').insertAdjacentHTML('beforeend', [
            '<div id="delivery-address">',
            '  <div class="js-address-form">',
            '    <form method="POST" data-id-address="9">',
            "      <input type='text' name='dni' value='' />",
            "      <input type='text' name='address1' value='' />",
            "      <input type='text' name='postcode' value='' />",
            "      <input type='text' name='city' value='' />",
            '    </form>',
            '  </div>',
            '</div>'
        ].join('\n'));
    }

    test('the other block keeps its empty, unmarked fields', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        appendOtherAddressBlock();

        const wrote = mount().adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(wrote).toBe(true);
        expect($("#invoice-address input[name='dni']").val()).toBe(REGISTER_NUMBER);
        expect($("#invoice-address input[name='address1']").val()).toBe('Wharf Lane');
        expect($("#invoice-address input[name='postcode']").val()).toBe('TN23 1AA');
        expect($("#invoice-address input[name='city']").val()).toBe('Ashford');
        ['dni', 'address1', 'postcode', 'city'].forEach(name => {
            const field = $(`#delivery-address input[name='${name}']`);
            expect(field.length).toBe(1);
            expect(field.val()).toBe('');
            expect(field.attr(MARKER)).toBeUndefined();
        });
    });
});

describe('a pre-filled secondary address is still written into (the pin does not apply here)', () => {
    /**
     * The INVERSE of an earlier review round's fix, and the reason is recorded in
     * adoptSoleTraderBuyer() itself so it is not reinstated.
     *
     * secondaryAddressFormRoot() resolves non-null ONLY when the invoice form is
     * the VISIBLE, editable form - so consulting the mirror's pin here would gate
     * the form the buyer is looking at and has just acted on. Worse, an invoice
     * form core pre-filled from a saved billing address carries street, postcode
     * and city with nothing on record as having written them, which reads as
     * buyer-authored and so is pinned BY DEFAULT: the adoption would write nothing
     * for every buyer editing an existing billing address, which is the originally
     * reported bug ("absolutely nothing is being populated") reinstated.
     *
     * Every test here therefore asserts secondaryAddressIsPinned() is TRUE and the
     * adoption writes ANYWAY. Reinstating the early return fails all three.
     */
    test('a saved billing address - pinned by the mirror\'s own rule - is written into regardless', () => {
        // Street, postcode and city the SERVER rendered into the invoice form, with
        // no marker and nothing on record as having written them. That is exactly
        // the content mismatch the mirror's pin is decided on.
        buildAddressesStep({
            editing: 'invoice',
            countryId: ES_OPTION,
            address1: 'Buyer Own Street',
            postcode: 'ZZ1 1ZZ',
            city: 'Buyer Own City'
        });
        const search = mount();
        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(true);

        const wrote = search.adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(wrote).toBe(true);
        expect(companyField().val()).toBe('Sole Trader Test Co');
        expect(companyField().attr(MARKER)).toBe('Sole Trader Test Co');
        expect(organizationField().val()).toBe(REGISTER_NUMBER);
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');
        expect($("#invoice-address input[name='dni']").val()).toBe(REGISTER_NUMBER);
        // The registered address replaces what was rendered.
        expect($("#invoice-address input[name='address1']").val()).toBe('Wharf Lane');
        expect($("#invoice-address input[name='postcode']").val()).toBe('TN23 1AA');
        expect($("#invoice-address input[name='city']").val()).toBe('Ashford');
    });

    test('and the whole write is reported into the mirror record, so the next render owns it', () => {
        buildAddressesStep({
            editing: 'invoice',
            countryId: ES_OPTION,
            address1: 'Buyer Own Street',
            postcode: 'ZZ1 1ZZ',
            city: 'Buyer Own City'
        });
        const search = mount();
        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(true);

        search.adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(window.twopayment.mirror_writes).toEqual({
            company: 'Sole Trader Test Co',
            organization: REGISTER_NUMBER,
            address1: 'Wharf Lane',
            postcode: 'TN23 1AA',
            city: 'Ashford'
        });
    });

    /**
     * The ORDERING that the removed early return used to sit above.
     *
     * A nameless sole trader clears the previous selection's residue from the form -
     * the hidden pair, its tag, the visible hint and the identification number the
     * lookup itself wrote - because the buyer has moved off that company and the
     * resolver's address tier reads the form. With no pin check there is nothing
     * above that clear, so it runs even on a secondary address the buyer has since
     * edited: the residue of the abandoned company goes, and the nameless buyer's
     * own registered address lands.
     */
    test('the nameless-buyer residue clear runs on an edited secondary address too', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        enableEndpointCalls();
        const search = mount();

        // A real selection this class wrote itself, into an address that was still
        // pristine at the time.
        expect(search.adoptSoleTraderBuyer(BUYER_REAL_NUMBER)).toBe(true);
        expect(organizationField().val()).toBe(REGISTER_NUMBER);
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');
        expect($("#invoice-address input[name='dni']").val()).toBe(REGISTER_NUMBER);
        expect(hintField().text()).toBe(REGISTER_NUMBER);

        // The buyer then types a city of their own over the one the fill wrote. The
        // record no longer matches the form, so by the mirror's rule this address is
        // now pinned - and the adoption below must still run.
        $("#invoice-address input[name='city']").val('Bristol');
        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(true);

        const wrote = search.adoptSoleTraderBuyer(BUYER_NAMELESS);

        expect(wrote).toBe(true);
        expect(organizationField().val()).toBe('');
        expect(organizationField().attr('data-two-company-name')).toBeUndefined();
        expect($("#invoice-address input[name='dni']").val()).toBe('');
        expect($("#invoice-address input[name='dni']").attr(MARKER)).toBeUndefined();
        expect(hintField().text()).toBe('');
        expect($("#invoice-address input[name='address1']").val()).toBe('Second Registered Building');
        expect($("#invoice-address input[name='postcode']").val()).toBe('CT16 1AA');
        expect($("#invoice-address input[name='city']").val()).toBe('Dover');
        expect(ajaxCallsFor('clearCompany')).toEqual([]);
    });
});

describe('the submit-time sync passes through the same single gate', () => {
    function submitAddressForm() {
        companyField().closest('form').triggerHandler('submit');
    }

    /**
     * The submit-time sync goes through writeOrganizationToAddressIdentifiers() and
     * therefore inherits its one carve-out: a `TWO:` companyid is NOT copied into
     * `dni` (TWO-40, Option A). Core's `isDniLite` would refuse the resulting address
     * outright, so a copy here would turn "submit the address step" into a dead end.
     *
     * The pairing this test sets up is deliberately NOT disturbed by the sync - that
     * is the uniformity half, and it is what carries the selection to the order.
     */
    test('an internal (`TWO:`) companyid is NOT copied into the identification field at submit', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();
        // The pair a selection establishes, through the class's own writer, leaving the
        // identification field empty for the submit sync to consider.
        search.markOrganizationFieldSelected('Sole Trader Test Co', 'TWO:ST123456789012');
        expect(identifierField().val()).toBe('');

        submitAddressForm();

        expect(identifierField().val()).toBe('');
        expect(identifierField().attr(MARKER)).toBeUndefined();
        // Visible, because nothing internal ever reached it.
        const group = identifierField().closest('.form-group');
        expect(group.get(0).style.display).toBe('');
        // And the pair the order needs is intact - the sync left it exactly as it was.
        expect(organizationField().val()).toBe('TWO:ST123456789012');
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');
    });

    test('a REAL organisation number still reaches it at submit - the gate did not break the ordinary path', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });
        const search = mount();
        // The pair a selection establishes, through the class's own writer.
        search.markOrganizationFieldSelected('Acme Trading Ltd', REGISTER_NUMBER);
        expect(identifierField().val()).toBe('');

        submitAddressForm();

        expect(identifierField().val()).toBe(REGISTER_NUMBER);
        expect(identifierField().attr(MARKER)).toBe(REGISTER_NUMBER);
    });
});

describe('the payment tile: the same adoption, on a page with no address form at all', () => {
    /**
     * The shipped tile, plus the company-search block the tile-location switch
     * renders.
     *
     * The harness's tile renderer strips every `{if}` block, which drops the
     * `{if $company_search_tile}` mount - so the block is re-rendered here from the
     * SAME shipped template rather than hand-copied, and a rename of the field or
     * its id fails this test instead of quietly passing it.
     *
     * @returns {void}
     */
    function buildTilePaymentStep() {
        const container = buildPaymentTileWithSoleTraderAnswer('1', 'GB');
        const tpl = fs.readFileSync(
            path.join(REPO_ROOT, 'views/templates/hook/paymentinfo.tpl'),
            'utf8'
        );
        const block = tpl.match(/\{if \$company_search_tile\}([\s\S]*?)\{\/if\}/);
        if (!block) {
            throw new Error('paymentinfo.tpl no longer carries the tile company-search block');
        }
        container.insertAdjacentHTML(
            'beforeend',
            block[1].replace(/\{l\s+s='([^']*)'[^}]*\}/g, '$1')
        );
    }

    test('the enrolled identity lands on the tile field and its hidden pair', () => {
        buildTilePaymentStep();
        expect(document.querySelector('#two_tile_company')).not.toBeNull();
        expect(document.querySelector("input[name='address1']")).toBeNull();

        const wrote = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companyFieldSelector: '#two_tile_company'
        }).adoptSoleTraderBuyer(BUYER_REAL_NUMBER);

        expect(wrote).toBe(true);
        expect($('#two_tile_company').val()).toBe('Sole Trader Test Co');
        expect($('#two_tile_company').attr(MARKER)).toBe('Sole Trader Test Co');
        expect(organizationField().val()).toBe(REGISTER_NUMBER);
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');
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

    test('a companySearch whose adoptSoleTraderBuyer THROWS costs the fill, not the enrolment', () => {
        const boom = jest.fn(() => { throw new Error('boom'); });
        window.TwoCheckoutManager_Instance = { companySearch: { adoptSoleTraderBuyer: boom } };

        let result;
        expect(() => {
            result = TwoSoleTrader.prototype.adoptEnrolledIdentity.call({}, BUYER_REAL_NUMBER);
        }).not.toThrow();
        expect(result).toBe(false);
        // The throw has to come from the real call, or this passes on a guard that
        // returned before reaching it.
        expect(boom).toHaveBeenCalledTimes(1);
    });
});

/**
 * THE INTEGRATION POINT. Everything above tests the two halves in isolation; this
 * is the only thing that pins them TOGETHER, and the defect this whole ticket
 * exists for ("absolutely nothing is being populated") lives exactly here - in
 * whether applyBuyer()'s success branch calls the adoption at all. Deleting that
 * one line leaves every other test in this file green.
 */
describe('applyBuyer(): a completed enrolment populates the FORM, end to end', () => {
    let TwoSoleTrader;
    let fetchCalls;

    beforeEach(() => {
        TwoSoleTrader = loadSoleTrader();
        fetchCalls = [];
        window.fetch = (url, options) => {
            fetchCalls.push({ url: String(url), options: options });
            if (String(url).includes('soleTraderAvailability')) {
                return Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve({ success: true, available: true })
                });
            }
            // `saveCompany` - the round trip applyBuyer() gates its whole success
            // branch on - and anything else this test does not care about.
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true }) });
        };
        global.fetch = window.fetch;
    });

    afterEach(() => {
        delete global.fetch;
        delete window.fetch;
        window.localStorage.clear();
    });

    /**
     * A real TwoSoleTrader instance with a real TwoCompanySearch reachable the way
     * adoptEnrolledIdentity() resolves it - lazily, off the manager. Neither side is
     * stubbed: a stubbed companySearch here would pin the wiring and prove nothing
     * about the writes, and a stubbed applyBuyer would prove nothing about the
     * wiring.
     *
     * @returns {Object} the TwoSoleTrader instance
     */
    function enrolledFlow() {
        const search = mount();
        const publishes = [];
        window.TwoCheckoutManager_Instance = {
            companySearch: search,
            setConfirmedCompanySelection: selection => publishes.push(selection)
        };
        const soleTrader = new TwoSoleTrader({
            checkoutHost: CHECKOUT_HOST,
            orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
            ajaxToken: 'test-token',
            billingCountry: 'GB'
        });
        soleTrader.tokens = { country: 'GB' };
        soleTrader._publishes = publishes;

        return soleTrader;
    }

    test('company name, hidden pair, identification number and registered address all land', async () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });
        const soleTrader = enrolledFlow();

        soleTrader.applyBuyer(BUYER_REAL_NUMBER, soleTrader._enrollGeneration);
        await flushPromises();

        expect(companyField().val()).toBe('Sole Trader Test Co');
        expect(companyField().attr(MARKER)).toBe('Sole Trader Test Co');
        expect(organizationField().val()).toBe(REGISTER_NUMBER);
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');
        expect(identifierField().val()).toBe(REGISTER_NUMBER);
        expect($("input[name='address1']").val()).toBe('Wharf Lane');
        expect($("input[name='postcode']").val()).toBe('TN23 1AA');
        expect($("input[name='city']").val()).toBe('Ashford');
    });

    /**
     * TWO-40 follow-up (live bug reported by Doug 2026-08-12): an order-intent
     * check fired off a completed sole-trader enrolment before the buyer had
     * reached the payment step. TwoCompanySearch.onCompanySelected() stamps
     * `_tileCompanySelected` on the manager the instant a search RESULT is
     * picked - TwoCheckoutManager.canAutoTriggerOrderIntent() reads that flag,
     * in tile mode, as "the buyer has made their choice" before a generic
     * mounted/re-rendered/periodic signal is allowed to auto-fire a check. A
     * completed sole-trader enrolment is exactly as much a confirmed choice as
     * a search result, so publishConfirmedSelection() must stamp the same flag
     * - see the doc on that method for why address mode does not need it
     * (canAutoTriggerOrderIntent() does not consult the flag there) while tile
     * mode does.
     */
    test('publishes _tileCompanySelected on the manager, the same signal a search selection sends', async () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });
        const soleTrader = enrolledFlow();
        expect(window.TwoCheckoutManager_Instance._tileCompanySelected).toBeUndefined();

        soleTrader.applyBuyer(BUYER_REAL_NUMBER, soleTrader._enrollGeneration);
        await flushPromises();

        expect(window.TwoCheckoutManager_Instance._tileCompanySelected).toBe(true);
    });

    test('the write survives the very next input event in the company field', async () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });
        const soleTrader = enrolledFlow();

        soleTrader.applyBuyer(BUYER_REAL_NUMBER, soleTrader._enrollGeneration);
        await flushPromises();
        // The mechanism that killed all three previous attempts at this write-back:
        // an untagged `companyid` is read as stale and wiped on the next keystroke.
        companyField().get(0).dispatchEvent(new window.Event('input', { bubbles: true }));

        expect(companyField().val()).toBe('Sole Trader Test Co');
        expect(organizationField().val()).toBe(REGISTER_NUMBER);
    });

    test('a saveCompany that fails writes nothing into the form', async () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });
        window.fetch = (url) => {
            if (String(url).includes('soleTraderAvailability')) {
                return Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve({ success: true, available: true })
                });
            }
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ success: false }) });
        };
        global.fetch = window.fetch;
        const soleTrader = enrolledFlow();

        soleTrader.applyBuyer(BUYER_REAL_NUMBER, soleTrader._enrollGeneration);
        await flushPromises();

        expect(companyField().val()).toBe('');
        expect(organizationField().val()).toBe('');
        expect($("input[name='address1']").val()).toBe('');
    });

    test('a SUPERSEDED save response - the buyer has moved on - populates nothing', async () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION });
        const soleTrader = enrolledFlow();
        const generation = soleTrader._enrollGeneration;

        soleTrader.applyBuyer(BUYER_REAL_NUMBER, generation);
        soleTrader.cancelEnrollment();
        await flushPromises();

        expect(companyField().val()).toBe('');
        expect(organizationField().val()).toBe('');
    });
});
