/**
 * TWO-40, Doug's ruling, OPTION A: an internal (`TWO:`-prefixed) organisation number
 * MUST NEVER be written into the visible `dni` ("Identification number") address
 * field. Everything else about such a number stays byte-identical to any other.
 *
 * THIS FILE REPLACES `internal-identifier-visibility.test.js`, which pinned the
 * opposite: a round that wrote the value into `dni` and then hid the field with CSS.
 * Every spec here is the INVERSE of one that stood there, so re-introducing that round
 * fails in this file rather than shipping.
 *
 * Why the write is wrong, verified against real PrestaShop core:
 *
 *  1. CORE REFUSES TO SAVE THE ADDRESS. `Address` declares `dni` with
 *     `validate => isDniLite, size => 16`, and `Validate::isDniLite()` is
 *     `/^[0-9A-Za-z-.]{1,16}$/U`. `TWO:ST123456789012` fails it twice - a colon is not
 *     in the character class, and it is 18 characters. The resulting error lands on a
 *     field that round was hiding: an invisible, unfixable dead-end at checkout.
 *  2. IT COULD NEVER BE READ BACK. This plugin's own reader,
 *     `extractOrgNumberFromAddress()`, validates `dni` against
 *     `/^[A-Z0-9\-]{5,20}$/i`, which rejects the colon too. The value was write-only.
 *  3. IT IS THE WRONG FIELD. `Country::isNeedDniByCountryId()` is purely
 *     country-level, so `dni` is required of EVERY buyer in such a country. It is the
 *     buyer's own fiscal number (NIF/CIF), not a slot for our identifier - the buyer
 *     fills it in themselves, which is why leaving it alone blocks nobody.
 *
 * AND WHY THERE IS NO HIDING RULE TO KEEP EITHER: core renders `dni` into address
 * blocks, invoice PDFs and order confirmation emails through
 * `AddressFormat::generateAddress()`, which no CSS rule of this plugin's reaches. A
 * checkout-only hide was never a complete one.
 *
 * NOT the earlier reverted approach (`.ai/decisions.md`): that one ALSO withheld the
 * pairing and the name, sending a sole trader's number down a different path through
 * storage, pairing, mirroring and submission, and every defect that followed came from
 * that divergence. The uniformity specs below are as load-bearing as the skip specs.
 *
 * Not a sole-trader rule either: registered companies in some countries legitimately
 * carry a `TWO:` identifier, so every assertion here is keyed on the VALUE and never
 * on how it was captured.
 *
 * Every fixture is core's own markup, through buildAddressesStep() - including the
 * `.form-group` + label wrapper `form-fields.tpl` puts around each address field.
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
 * The state of the visible field, asserted as one object so a half-applied change
 * cannot pass. `display` is the INLINE value: the point is that this class writes no
 * declaration of its own at all, which a computed value (`block` from the cascade)
 * would hide.
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
     * `false` is LOAD-BEARING, not incidental. The invoice mirror takes `wroteNumber`
     * from this answer; recording a write that did not happen makes the next render
     * read the empty field as buyer tampering and pin the whole secondary address.
     */
    test('returns false, writes nothing, claims nothing, and hides nothing', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();
        // The premise: the field exists and is empty, so nothing but the gate can be
        // what stops the write.
        expect(identifierField().length).toBe(1);
        expect(fieldState()).toEqual({ value: '', marker: undefined, display: '' });

        expect(search.writeOrganizationToAddressIdentifiers(INTERNAL, false)).toBe(false);

        expect(fieldState()).toEqual({ value: '', marker: undefined, display: '' });
    });

    /**
     * The control. The ordinary path must be untouched by the gate - a gate that
     * refused everything would pass every skip assertion in this file.
     */
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

    /**
     * The gate must be a PREFIX test, not a substring one. A real register number that
     * happens to contain the letters is an ordinary number and has to reach the field,
     * or a buyer's required identification is silently left blank.
     */
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
 * THE KEY GROUP. Only `dni` is skipped. The hidden `companyid`, its
 * `data-two-company-name` pairing tag and the company name are written for a `TWO:`
 * value exactly as for any other - and the pairing tag is what makes the selection
 * survive: an untagged non-empty `companyid` is read by
 * clearStaleOrganizationSelection() as "the buyer has edited past a stale selection"
 * and wiped on their very next input event. That one mechanism killed three previous
 * attempts at this write-back.
 */
describe('uniformity: everything OTHER than `dni` is identical for an internal identifier', () => {
    test('the hidden `companyid` and its pairing tag are written, byte-identically', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();

        expect(search.markOrganizationFieldSelected(NAME, INTERNAL)).toBe(true);

        expect(organizationField().length).toBe(1);
        // Byte-identical: the prefix is never stripped, cased or otherwise transformed
        // anywhere it IS stored.
        expect(organizationField().val()).toBe(INTERNAL);
        expect(organizationField().attr(PAIR_TAG)).toBe(NAME);
        // And still nothing in the visible field.
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

    /**
     * The guard is not disabled for internal values either - it is uniform in BOTH
     * directions. A buyer who retypes a different name still loses the pair.
     */
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

    /**
     * `companyid` is the plugin's own `<input type="hidden">`, so it is never on
     * screen and carries no presentation attributes. Asserted rather than assumed: a
     * rule widened to "every field holding an organisation number" would start
     * decorating it.
     */
    test('the hidden field carries no inline display of its own', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();
        search.markOrganizationFieldSelected(NAME, INTERNAL);

        expect(organizationField().attr('type')).toBe('hidden');
        expect(organizationField().get(0).style.display).toBe('');
    });
});

/**
 * THE INVERSE OF THE DELETED FILE'S PREMISE. There is no visibility rule, no marker
 * attribute for one, and no method to call. Each of these fails if the hide comes
 * back.
 */
describe('there is no identification-field visibility rule at all', () => {
    test('the method that hid the field no longer exists', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();

        expect(search.syncInternalIdentifierVisibility).toBeUndefined();
    });

    test('nor does the attribute it tracked its own work with', () => {
        expect(TwoCompanySearch.INTERNAL_HIDDEN_ATTR).toBeUndefined();
    });

    /**
     * A SERVER-rendered value is the case a write-side gate cannot reach, and it is
     * exactly why the old rule ran at init(). It is also the case that proves the
     * field belongs to the buyer: whatever core rendered into `dni` on an address they
     * saved earlier stays on screen, editable, and unclaimed.
     */
    test('a server-rendered value in `dni` is left visible, untouched and unclaimed at mount', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: REAL, formGroups: true });
        expect(identifierField().val()).toBe(REAL);

        mount();

        expect(fieldState()).toEqual({ value: REAL, marker: undefined, display: '' });
        expect(window.getComputedStyle(identifierGroup().get(0)).display).not.toBe('none');
    });

    /**
     * Even a value that IS internal - which nothing this class does can now produce,
     * but which a legacy saved address can still hold. It is the buyer's field: it
     * stays visible so they can correct it, which is the only way core will ever accept
     * that address.
     */
    test('even a server-rendered internal-looking value is left visible and untouched', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: INTERNAL, formGroups: true });

        mount();

        expect(fieldState()).toEqual({ value: INTERNAL, marker: undefined, display: '' });
        expect(window.getComputedStyle(identifierGroup().get(0)).display).not.toBe('none');
        // The label is not orphaned, because nothing was hidden.
        expect(identifierGroup().find('label').length).toBe(1);
    });

    test('and no element anywhere on the page carries the retired marker', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: INTERNAL, formGroups: true });
        const search = mount();
        search.markOrganizationFieldSelected(NAME, INTERNAL);
        search.writeOrganizationToAddressIdentifiers(INTERNAL, false);

        expect(document.querySelectorAll('[data-two-internal-hidden]').length).toBe(0);
        // Nor is anything on the identification field's own chain hidden - the input,
        // its `.form-group`, or anything between them. Scoped to that chain rather
        // than the whole form, because this class legitimately hides widgets of its
        // own (the autocomplete menu, the unselected mode chips) elsewhere in it.
        const chain = [];
        for (let node = identifierField().get(0); node && node !== document.body; node = node.parentElement) {
            chain.push(node);
        }
        expect(chain).toContain(identifierGroup().get(0));
        expect(chain.filter(node => node.style.display === 'none')).toEqual([]);
    });
});

/**
 * The three other `TWO:` display sites are untouched by this change and stay: they are
 * genuine display surfaces, and they suppress the prefix through
 * `TwoCompanyNumber.forDisplay()`. Pinned here so a reader of this file does not
 * conclude that Option A removed the display rule as well.
 */
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
