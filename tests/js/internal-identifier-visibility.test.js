/**
 * TWO-40: `TwoCompanySearch.syncInternalIdentifierVisibility()`.
 *
 * Doug's ruling: an internal (`TWO:`-prefixed) organisation number is handled
 * EXACTLY like any other everywhere except DISPLAY. This method is that single
 * exception, and it is the only place in the class where the prefix means anything
 * at all. It reads no value it does not already have and changes no value: hiding a
 * field is presentation, and keeping the real number in it is precisely what leaves
 * a required identification field satisfied and a name/number pair complete.
 *
 * An earlier round achieved the same visual result by refusing the WRITE, and every
 * defect that followed came from that one divergence - a mismatched pair left in the
 * invoice form, the "name and number travel together" invariant broken, and a
 * REQUIRED field left empty and unfillable on the countries that demand one. These
 * specs pin the presentation-only shape so that reversal is not undone.
 *
 * Not a sole-trader rule: registered companies in some countries legitimately carry
 * a `TWO:` identifier too, so every assertion here is keyed on the VALUE and never
 * on how it was captured.
 *
 * Every fixture is core's own markup, through buildAddressesStep() - including the
 * `.form-group` + label wrapper `form-fields.tpl` puts around each address field,
 * which is what the rule targets.
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
const INTERNAL = 'TWO:ST123456789012';
const REAL = '923456789';

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

/**
 * Whether the identification field's wrapper is hidden BY THIS CLASS - the two
 * halves asserted together, because either one alone passes on a half-applied rule.
 *
 * `display` is the INLINE value, not the computed one: the rule's un-hide clears the
 * inline declaration rather than setting a value, so `''` is what "we are no longer
 * hiding this" looks like, while the computed value would report whatever the
 * cascade says (`block` on a div). The computed value is asserted separately, where
 * the point is that the declaration actually hides something.
 *
 * @returns {Object} `{display, claimed}`
 */
function groupState() {
    const group = identifierGroup();

    return {
        display: group.get(0).style.display,
        claimed: group.attr(TwoCompanySearch.INTERNAL_HIDDEN_ATTR)
    };
}

describe('the attribute the rule tracks its own work with', () => {
    test('is the published constant, so a test cannot drift from the implementation', () => {
        expect(TwoCompanySearch.INTERNAL_HIDDEN_ATTR).toBe('data-two-internal-hidden');
    });
});

/**
 * THE CASE A WRITE-SIDE CALL ALONE CANNOT COVER, and the reason init() calls this at
 * mount: the value arrives from the SERVER, on an address the buyer saved earlier
 * whose identification number is an internal identifier. Nothing wrote it on this
 * page, so a write-side call alone would leave it on screen after every reload and
 * every re-render.
 */
describe('at init(): a SERVER-rendered internal identifier is hidden with no write involved', () => {
    test('hides the wrapper on mount', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: INTERNAL, formGroups: true });
        // The premise: the value is in the field before the module exists.
        expect(identifierField().val()).toBe(INTERNAL);

        mount();

        expect(groupState()).toEqual({ display: 'none', claimed: '1' });
    });

    test('and writes nothing: the value and its (absent) marker are untouched', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: INTERNAL, formGroups: true });

        mount();

        expect(identifierField().val()).toBe(INTERNAL);
        // No marker: the plugin did not put this here and must never claim it, or
        // clearLookupWrittenAddressIdentifiers() would delete the buyer's own value.
        expect(identifierField().attr(MARKER)).toBeUndefined();
    });

    test('a server-rendered REAL number is left visible and unclaimed', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: REAL, formGroups: true });

        mount();

        expect(groupState()).toEqual({ display: '', claimed: undefined });
        expect(identifierField().val()).toBe(REAL);
    });

    test('an empty field is left visible and unclaimed', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });

        mount();

        expect(groupState()).toEqual({ display: '', claimed: undefined });
    });
});

describe('the prefix test folds case', () => {
    test.each([
        ['upper', 'TWO:ST123456789012'],
        ['lower', 'two:st123456789012'],
        ['mixed', 'Two:St123456789012']
    ])('a %s-case prefix is still an internal identifier', (_label, value) => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: value, formGroups: true });

        mount();

        expect(groupState()).toEqual({ display: 'none', claimed: '1' });
    });

    test('a number that merely CONTAINS the prefix is not one - it must start with it', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: '99TWO:123', formGroups: true });

        mount();

        expect(groupState()).toEqual({ display: '', claimed: undefined });
    });
});

describe('the wrapper is hidden, never the input', () => {
    /**
     * Hiding the input alone leaves an orphaned "Identification number" label with
     * nothing under it, which is why the rule walks up to the `.form-group` core
     * renders around every address field.
     */
    test('the input itself carries no inline display of its own', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: INTERNAL, formGroups: true });

        mount();

        expect(identifierGroup().length).toBe(1);
        expect(identifierGroup().find('label').length).toBe(1);
        expect(identifierField().get(0).style.display).toBe('');
        expect(identifierField().attr(TwoCompanySearch.INTERNAL_HIDDEN_ATTR)).toBeUndefined();
    });

    /**
     * The fallback, for a theme that does not use that class: worse looking than an
     * orphan label is a visible internal identifier.
     */
    test('falls back to the input when there is no .form-group ancestor at all', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: INTERNAL });
        expect(identifierField().closest('.form-group').length).toBe(0);

        mount();

        expect(identifierField().get(0).style.display).toBe('none');
        expect(identifierField().attr(TwoCompanySearch.INTERNAL_HIDDEN_ATTR)).toBe('1');
    });
});

describe('`display: none`, which is the only value that actually hides anything', () => {
    /**
     * `display: hidden` is not a valid value for that property and does nothing at
     * all - a plausible-looking typo that ships a visible internal identifier, and
     * the reason this is asserted on the computed value rather than on the call.
     */
    test('the hidden wrapper computes to display: none', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: INTERNAL, formGroups: true });

        mount();

        expect(window.getComputedStyle(identifierGroup().get(0)).display).toBe('none');
    });
});

describe('it shows the field again when the value stops being internal', () => {
    test('an internal value replaced by a real one un-hides the wrapper and drops the claim', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: INTERNAL, formGroups: true });
        const search = mount();
        expect(groupState()).toEqual({ display: 'none', claimed: '1' });

        identifierField().val(REAL);
        search.syncInternalIdentifierVisibility();

        expect(groupState()).toEqual({ display: '', claimed: undefined });
    });

    test('an internal value CLEARED un-hides the wrapper too', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: INTERNAL, formGroups: true });
        const search = mount();
        expect(groupState()).toEqual({ display: 'none', claimed: '1' });

        identifierField().val('');
        search.syncInternalIdentifierVisibility();

        expect(groupState()).toEqual({ display: '', claimed: undefined });
    });
});

/**
 * ONLY EVER UN-HIDES ITS OWN WORK. A `.form-group` the THEME or the merchant has
 * hidden for their own reasons must be left exactly as it is - revealing it would be
 * this plugin overruling a decision it knows nothing about.
 */
describe('it never reveals a field it did not hide', () => {
    test('a wrapper the theme hid, holding a real number, is left hidden and unclaimed', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: REAL, formGroups: true });
        // The theme's own doing: hidden, with nothing of ours on record as having
        // hidden it.
        identifierGroup().css('display', 'none');

        mount();

        expect(groupState()).toEqual({ display: 'none', claimed: undefined });
    });

    test('a wrapper the theme hid, holding an EMPTY field, is left hidden and unclaimed', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        identifierGroup().css('display', 'none');

        mount();

        expect(groupState()).toEqual({ display: 'none', claimed: undefined });
    });

    test('and a wrapper THIS class hid is the one it does restore', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: INTERNAL, formGroups: true });
        const search = mount();
        // The distinguishing fact: this one carries the claim.
        expect(groupState()).toEqual({ display: 'none', claimed: '1' });

        identifierField().val(REAL);
        search.syncInternalIdentifierVisibility();

        expect(groupState()).toEqual({ display: '', claimed: undefined });
    });
});

/**
 * The three call sites, each asserted through the operation that owns it rather than
 * by calling the method directly - a call site deleted has to fail a test here.
 */
describe('the call sites', () => {
    test('after writeOrganizationToAddressIdentifiers(): written, then hidden', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();
        expect(groupState()).toEqual({ display: '', claimed: undefined });

        expect(search.writeOrganizationToAddressIdentifiers(INTERNAL, false)).toBe(true);

        expect(identifierField().val()).toBe(INTERNAL);
        expect(groupState()).toEqual({ display: 'none', claimed: '1' });
    });

    test('after writeOrganizationToAddressIdentifiers() with a real number: written, and shown', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: INTERNAL, formGroups: true });
        const search = mount();
        expect(groupState()).toEqual({ display: 'none', claimed: '1' });

        // A real company selection replacing the internal identifier that was there.
        expect(search.writeOrganizationToAddressIdentifiers(REAL, false)).toBe(true);

        expect(identifierField().val()).toBe(REAL);
        expect(groupState()).toEqual({ display: '', claimed: undefined });
    });

    test('after clearLookupWrittenAddressIdentifiers(): the emptied field comes back', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();
        // A number this class itself wrote and marked - the only kind that clear
        // touches.
        search.writeOrganizationToAddressIdentifiers(INTERNAL, false);
        expect(groupState()).toEqual({ display: 'none', claimed: '1' });

        search.clearLookupWrittenAddressIdentifiers();

        expect(identifierField().val()).toBe('');
        expect(groupState()).toEqual({ display: '', claimed: undefined });
    });

    test('a refused write leaves the visibility of the buyer\'s own value alone', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, dni: REAL, formGroups: true });
        const search = mount();

        // onlyIfEmpty: the buyer's own number stands, so nothing was written.
        expect(search.writeOrganizationToAddressIdentifiers(INTERNAL, true)).toBe(false);

        expect(identifierField().val()).toBe(REAL);
        expect(groupState()).toEqual({ display: '', claimed: undefined });
    });
});

/**
 * `companyid` is the hidden pairing field the plugin creates itself - `<input
 * type="hidden">` - so it is never on screen and needs no visibility handling. That
 * it gets none is asserted rather than assumed: a rule widened to "every field
 * holding an organisation number" would start decorating it, and the marker/claim
 * attributes on it would then be read by later passes as state that means something.
 */
describe('the hidden `companyid` field needs no visibility handling and gets none', () => {
    test('it is type=hidden, and carries neither the claim nor an inline display', () => {
        buildAddressesStep({ editing: 'delivery', countryId: ES_OPTION, formGroups: true });
        const search = mount();

        search.markOrganizationFieldSelected('Sole Trader Test Co', INTERNAL);
        search.syncInternalIdentifierVisibility();

        const organizationField = $("input[name='companyid']");
        expect(organizationField.length).toBe(1);
        expect(organizationField.attr('type')).toBe('hidden');
        expect(organizationField.val()).toBe(INTERNAL);
        expect(organizationField.attr(TwoCompanySearch.INTERNAL_HIDDEN_ATTR)).toBeUndefined();
        expect(organizationField.get(0).style.display).toBe('');
        // And its own wrapper - it is inserted beside the company input, so it shares
        // that field's group - is emphatically NOT hidden: doing so would take the
        // company search box off the page.
        const companyGroup = organizationField.closest('.form-group');
        expect(companyGroup.length).toBe(1);
        expect(window.getComputedStyle(companyGroup.get(0)).display).not.toBe('none');
    });
});

describe('scope: a root confines the pass to one address block', () => {
    test('only the block passed as root is judged', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION, dni: INTERNAL, formGroups: true });
        const search = mount();
        // A second block carrying the same field, which core does not render but a
        // theme can leave on the page. Added AFTER the mount deliberately: init() runs
        // an UNSCOPED pass, which would legitimately have judged this block too, and
        // the scoped call below is what is under test.
        document.querySelector('.js-address-form').insertAdjacentHTML('beforeend', [
            '<div id="delivery-address">',
            '  <div class="js-address-form">',
            '    <form method="POST" data-id-address="9">',
            '      <div class="form-group row">',
            '        <label>Identification number</label>',
            "        <input type='text' name='dni' value='" + INTERNAL + "' />",
            '      </div>',
            '    </form>',
            '  </div>',
            '</div>'
        ].join('\n'));

        search.syncInternalIdentifierVisibility(document.querySelector('#invoice-address'));

        const invoiceGroup = $("#invoice-address input[name='dni']").closest('.form-group');
        expect(invoiceGroup.get(0).style.display).toBe('none');
        expect(invoiceGroup.attr(TwoCompanySearch.INTERNAL_HIDDEN_ATTR)).toBe('1');
        // Untouched by the scoped pass.
        const otherGroup = $("#delivery-address input[name='dni']").closest('.form-group');
        expect(otherGroup.get(0).style.display).toBe('');
        expect(otherGroup.attr(TwoCompanySearch.INTERNAL_HIDDEN_ATTR)).toBeUndefined();
    });

    test('a root with no identification field at all is a no-op rather than a throw', () => {
        buildAddressesStep({ editing: 'invoice', formGroups: true });
        const search = mount();
        expect($("#invoice-address input[name='dni']").length).toBe(0);

        expect(() => {
            search.syncInternalIdentifierVisibility(document.querySelector('#invoice-address'));
        }).not.toThrow();
    });
});
