/**
 * TWO-40, Doug's ruling, OPTION A: an internal (`TWO:`-prefixed) organisation
 * number MUST NEVER be written into the visible `dni` ("Identification
 * number") address field. Everything else about such a number stays
 * byte-identical to any other.
 *
 * Replaces `internal-identifier-visibility.test.js`, which pinned the
 * opposite (write into `dni`, then hide the field with CSS) - every spec here
 * is the INVERSE of one that stood there.
 *
 * Why the write is wrong, verified against real PrestaShop core:
 *
 *  1. CORE REFUSES TO SAVE THE ADDRESS. `Validate::isDniLite()` is
 *     `/^[0-9A-Za-z-.]{1,16}$/U`; `TWO:ST123456789012` fails it twice (colon,
 *     18 chars) - an invisible, unfixable dead-end at checkout.
 *  2. IT COULD NEVER BE READ BACK. `extractOrgNumberFromAddress()` validates
 *     `dni` against `/^[A-Z0-9\-]{5,20}$/i`, which also rejects the colon.
 *  3. IT IS THE WRONG FIELD. `dni` is the buyer's own fiscal number
 *     (NIF/CIF), required of every buyer in such a country regardless of our
 *     identifier - leaving it alone blocks nobody.
 *
 * No hiding rule either: core renders `dni` into address blocks, invoice PDFs
 * and confirmation emails via `AddressFormat::generateAddress()`, which no
 * CSS rule of this plugin's reaches.
 *
 * Not the earlier reverted approach (`.ai/decisions.md`), which also
 * withheld the pairing and the name - the uniformity specs below are as
 * load-bearing as the skip specs. Not a sole-trader rule either: registered
 * companies can legitimately carry a `TWO:` identifier, so every assertion
 * here is keyed on the VALUE, never on how it was captured.
 */

'use strict';

const {
    loadCompanySearch,
    buildAddressesStep,
    stubAjax,
    releaseWidgets,
    DNI_COUNTRY_ID
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const MARKER = 'data-two-autofilled-value';
const PAIR_TAG = 'data-two-company-name';
const INTERNAL = 'TWO:ST123456789012';
const REAL = '923456789';
const NAME = 'Sole Trader Test Co';

const ES_OPTION = DNI_COUNTRY_ID;

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
});

function mount(extraConfig) {
    return new TwoCompanySearch(Object.assign({
        checkoutHost: CHECKOUT_HOST,
        addressLookupEnabled: true
    }, extraConfig || {}));
}

function identifierField() {
    return $("input[name='dni']");
}

function identifierGroup() {
    return identifierField().closest('.form-group');
}

function organizationField() {
    return $("input[name='companyid']");
}

function companyField() {
    return $("input[name='company']");
}

/**
 * Asserted as one object so a half-applied change cannot pass. `display` is
 * the INLINE value - this class writes no declaration of its own at all,
 * which a computed value would hide.
 *
 * @returns {Object} `{value, marker, display}`
 */
function fieldState() {
    return {
        value: identifierField().val(),
        marker: identifierField().attr(MARKER),
        display: identifierGroup().get(0).style.display
    };
}

describe('the write gate refuses an internal identifier and answers false', () => {
    /**
     * `false` is LOAD-BEARING: the invoice mirror takes `wroteNumber` from
     * this answer, and recording a write that never happened makes the next
     * render read the empty field as buyer tampering.
     */
    test('returns false, writes nothing, claims nothing, and hides nothing', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();
        expect(identifierField().length).toBe(1);
        expect(fieldState()).toEqual({ value: '', marker: undefined, display: '' });

        expect(search.writeOrganizationToAddressIdentifiers(INTERNAL, false)).toBe(false);

        expect(fieldState()).toEqual({ value: '', marker: undefined, display: '' });
    });

    /** Control: a gate that refused everything would pass every skip
     * assertion in this file, so the ordinary path must be untouched. */
    test('a real organisation number IS written, marked, and returns true', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();

        expect(search.writeOrganizationToAddressIdentifiers(REAL, false)).toBe(true);

        expect(fieldState()).toEqual({ value: REAL, marker: REAL, display: '' });
    });

    test.each([
        ['upper', 'TWO:ST123456789012'],
        ['lower', 'two:st123456789012'],
        ['mixed', 'Two:St123456789012']
    ])('the prefix test folds case: a %s-case prefix is still refused', (_label, value) => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();

        expect(search.writeOrganizationToAddressIdentifiers(value, false)).toBe(false);
        expect(identifierField().val()).toBe('');
    });

    /** The gate must be a PREFIX test, not a substring one, or a real
     * register number that happens to contain the letters is silently blocked. */
    test('a number that merely CONTAINS the prefix is written - it must START with it', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();

        expect(search.writeOrganizationToAddressIdentifiers('99TWO:123', false)).toBe(true);
        expect(identifierField().val()).toBe('99TWO:123');
    });

    test('the clear path is unaffected: a real number this class wrote is still cleared', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();
        search.writeOrganizationToAddressIdentifiers(REAL, false);
        expect(identifierField().val()).toBe(REAL);

        search.clearLookupWrittenAddressIdentifiers();

        expect(fieldState()).toEqual({ value: '', marker: undefined, display: '' });
    });
});

/**
 * THE KEY GROUP: only `dni` is skipped. `companyid` and its pairing tag are
 * written for a `TWO:` value exactly as for any other - the tag is what
 * makes the selection survive, since an untagged non-empty `companyid` is
 * read by clearStaleOrganizationSelection() as a stale selection and wiped
 * on the next input event.
 */
describe('uniformity: everything OTHER than `dni` is identical for an internal identifier', () => {
    test('the hidden `companyid` and its pairing tag are written, byte-identically', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();

        expect(search.markOrganizationFieldSelected(NAME, INTERNAL)).toBe(true);

        expect(organizationField().length).toBe(1);
        // Byte-identical: the prefix is never stripped, cased, or transformed where it IS stored.
        expect(organizationField().val()).toBe(INTERNAL);
        expect(organizationField().attr(PAIR_TAG)).toBe(NAME);
        expect(identifierField().val()).toBe('');
    });

    test('the pair survives an `input` event in the company field', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();
        companyField().val(NAME);
        search.markOrganizationFieldSelected(NAME, INTERNAL);

        companyField().get(0).dispatchEvent(new window.Event('input', { bubbles: true }));

        expect(organizationField().val()).toBe(INTERNAL);
        expect(organizationField().attr(PAIR_TAG)).toBe(NAME);
    });

    /** Uniform in BOTH directions: a buyer who retypes a different name
     * still loses the pair, even for an internal value. */
    test('and the stale-selection guard still clears it when the buyer retypes the name', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();
        companyField().val(NAME);
        search.markOrganizationFieldSelected(NAME, INTERNAL);

        companyField().val('Someone Elses Shop');
        companyField().get(0).dispatchEvent(new window.Event('input', { bubbles: true }));

        expect(organizationField().val()).toBe('');
        expect(organizationField().attr(PAIR_TAG)).toBeUndefined();
    });

    /** A rule widened to "every field holding an organisation number" would
     * start decorating this hidden field - asserted rather than assumed. */
    test('the hidden field carries no inline display of its own', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();
        search.markOrganizationFieldSelected(NAME, INTERNAL);

        expect(organizationField().attr('type')).toBe('hidden');
        expect(organizationField().get(0).style.display).toBe('');
    });
});

/** No visibility rule, no marker attribute, no method to call - each of
 * these fails if the hide comes back. */
describe('there is no identification-field visibility rule at all', () => {
    test('the method that hid the field no longer exists', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();

        expect(search.syncInternalIdentifierVisibility).toBeUndefined();
    });

    test('nor does the attribute it tracked its own work with', () => {
        expect(TwoCompanySearch.INTERNAL_HIDDEN_ATTR).toBeUndefined();
    });

    /** A SERVER-rendered value is the case a write-side gate cannot reach:
     * whatever core rendered into `dni` earlier stays editable and unclaimed. */
    test('a server-rendered value in `dni` is left visible, untouched and unclaimed at mount', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: REAL, formGroups: true });
        expect(identifierField().val()).toBe(REAL);

        mount();

        expect(fieldState()).toEqual({ value: REAL, marker: undefined, display: '' });
        expect(window.getComputedStyle(identifierGroup().get(0)).display).not.toBe('none');
    });

    /** Even a value that IS internal, which nothing this class produces now
     * but a legacy saved address can still hold: it stays visible so the
     * buyer can correct it. */
    test('even a server-rendered internal-looking value is left visible and untouched', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: INTERNAL, formGroups: true });

        mount();

        expect(fieldState()).toEqual({ value: INTERNAL, marker: undefined, display: '' });
        expect(window.getComputedStyle(identifierGroup().get(0)).display).not.toBe('none');
        expect(identifierGroup().find('label').length).toBe(1);
    });

    test('and no element anywhere on the page carries the retired marker', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: INTERNAL, formGroups: true });
        const search = mount();
        search.markOrganizationFieldSelected(NAME, INTERNAL);
        search.writeOrganizationToAddressIdentifiers(INTERNAL, false);

        expect(document.querySelectorAll('[data-two-internal-hidden]').length).toBe(0);
        // Scoped to the identifier field's own chain, not the whole form: this
        // class legitimately hides other widgets of its own elsewhere in it.
        const chain = [];
        for (let node = identifierField().get(0); node && node !== document.body; node = node.parentElement) {
            chain.push(node);
        }
        expect(chain).toContain(identifierGroup().get(0));
        expect(chain.filter(node => node.style.display === 'none')).toEqual([]);
    });
});

/** The other `TWO:` display sites are unchanged - genuine display surfaces
 * that suppress the prefix through `TwoCompanyNumber.forDisplay()`. */
describe('the genuine display suppression is unchanged', () => {
    test('the inline hint under the company field shows nothing for an internal identifier', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();

        search.setCompanyIdHint(INTERNAL);

        expect($('.two-company-id-hint').text()).toBe('');
    });

    test('but does show a real organisation number', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();

        search.setCompanyIdHint(REAL);

        expect($('.two-company-id-hint').text()).toContain(REAL);
    });
});
