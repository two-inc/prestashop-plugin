/**
 * TWO-40: the SECONDARY address's sync pin.
 *
 * Doug's rulings, which these specs exist to hold:
 *
 *  - the pin is triggered by ANY address field the buyer has entered, not only the
 *    company or the country;
 *  - there is no dedicated control for it. Sync is driven by a match on FIELD
 *    CONTENTS, trimming the address lines and folding case;
 *  - the comparison basis is the value the MIRROR LAST WROTE, never the primary
 *    address's live value. Comparing against the primary is self-defeating: the
 *    instant the primary changes, the secondary cannot equal it, so every legitimate
 *    re-sync would read as a buyer edit and syncing would stop forever;
 *  - the pin is ADDRESS-WIDE. One field that no longer holds what the plugin put
 *    there pins the whole secondary address and no field is synced.
 *
 * The last-written values have to survive a page load, because that is when the pin
 * is evaluated: PrestaShop reveals the invoice address form by NAVIGATING, and every
 * marker attribute the previous page wrote went with the nodes. That is what the
 * cart-scoped record the server publishes as `window.twopayment.mirror_writes` is
 * for, and half of these specs are about it being load-bearing.
 *
 * Every fixture is core's own markup, through buildAddressesStep() - see its
 * docblock for what is reproduced and from where.
 */

'use strict';

const {
    loadCompanySearch,
    buildAddressesStep,
    rebuildAddressesStepAsCoreDoes,
    stubAjax,
    releaseWidgets,
    DNI_COUNTRY_ID
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const MARKER = 'data-two-autofilled-value';

const GB_OPTION = '17';
const FR_OPTION = '8';
// What the fixture's server rendered as selected, as core always does: a real
// country, never the placeholder alone.
const SERVER_RENDERED_OPTION = '1';

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
        countries: { 17: 'gb', 8: 'fr', 1: 'de', 6: 'es', 144: 'mx' },
        order_intent_url: 'https://shop.example.test/module/twopayment/orderintent',
        ajax_token: 'tok'
    };
});

afterEach(() => {
    releaseWidgets($);
    ajax.restore();
    document.body.innerHTML = '';
    delete window.twopayment;
});

const PICKED = { company: 'Acme Trading Ltd', companyid: '12345678', countryIso: 'GB' };

function mount(selection, extraConfig) {
    return new TwoCompanySearch(Object.assign({
        checkoutHost: CHECKOUT_HOST,
        addressLookupEnabled: true,
        getConfirmedCompany: () => selection
    }, extraConfig || {}));
}

function companyField() {
    return $("#invoice-address input[name='company']");
}

function countrySelect() {
    return $("#invoice-address select[name='id_country']");
}

function street() {
    return $("#invoice-address input[name='address1']");
}

/**
 * What the server published about this cart's mirror writes on THIS page load.
 *
 * @param {?Object} record
 * @returns {void}
 */
function publishMirrorWrites(record) {
    window.twopayment.mirror_writes = record;
}

/**
 * The `saveMirrorWrites` reports the module has sent, newest last.
 *
 * @returns {Array<Object>}
 */
function reportedWrites() {
    return ajax.calls
        .map(call => call.settings && call.settings.data)
        .filter(data => data && data.action === 'saveMirrorWrites');
}

describe('the pin: a pristine secondary address is synced', () => {
    /**
     * The baseline, first, so nothing below can pass by never syncing at all.
     */
    test('mirrors the company and the country into an untouched invoice form', () => {
        buildAddressesStep({ editing: 'invoice' });
        publishMirrorWrites(null);

        mount(PICKED);

        expect(companyField().val()).toBe('Acme Trading Ltd');
        expect(countrySelect().val()).toBe(GB_OPTION);
    });

    /**
     * A `<select>` has no empty state on a PrestaShop form - core renders a real
     * country as selected - so "unanswered" for the country is "still what the
     * server rendered". A pin that tested emptiness would fire on every form.
     */
    test('the server-rendered country is not treated as the buyer having answered', () => {
        buildAddressesStep({ editing: 'invoice', countryId: SERVER_RENDERED_OPTION });
        publishMirrorWrites(null);

        const search = mount(PICKED);

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(false);
        expect(countrySelect().val()).toBe(GB_OPTION);
    });
});

describe('the pin is triggered by ANY address field, address-wide', () => {
    /**
     * Doug: "we need to pin it if any address field has been entered, not just
     * country/company". The street is the case that proves it, because the mirror
     * never writes the street itself - so a per-field rule would sync the company
     * happily while the buyer had already described a different place.
     */
    test('a street the buyer entered pins the whole address, so the company is not written', () => {
        buildAddressesStep({ editing: 'invoice' });
        publishMirrorWrites(null);
        street().val('12 Buyer Lane');

        mount(PICKED);

        expect(companyField().val()).toBe('');
        expect(countrySelect().val()).toBe(SERVER_RENDERED_OPTION);
    });

    test('a postcode the buyer entered pins the whole address', () => {
        buildAddressesStep({ editing: 'invoice' });
        publishMirrorWrites(null);
        $("#invoice-address input[name='postcode']").val('SW1A 2AA');

        mount(PICKED);

        expect(companyField().val()).toBe('');
    });

    test('a city the buyer entered pins the whole address', () => {
        buildAddressesStep({ editing: 'invoice' });
        publishMirrorWrites(null);
        $("#invoice-address input[name='city']").val('Bristol');

        mount(PICKED);

        expect(companyField().val()).toBe('');
    });

    /**
     * The address-wide half stated on its own: every OTHER field still holds exactly
     * what the plugin recorded writing, and one that does not is enough. A per-field
     * pin passes the company write here and fails this test.
     */
    test('one mismatched field pins fields that match perfectly well', () => {
        buildAddressesStep({ editing: 'invoice', countryId: GB_OPTION });
        publishMirrorWrites({
            company: 'Acme Trading Ltd',
            organization: '',
            country: 'GB',
            address1: '1 Register Street',
            postcode: '',
            city: ''
        });
        // Company and country still hold the recorded values; only the street was
        // changed after the mirror filled it.
        companyField().val('Acme Trading Ltd');
        street().val('12 Buyer Lane');

        const search = mount({ company: 'Beta Holdings Ltd', companyid: '99999999', countryIso: 'FR' });

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(true);
        expect(companyField().val()).toBe('Acme Trading Ltd');
        expect(countrySelect().val()).toBe(GB_OPTION);
    });

    /**
     * An identification number the buyer typed is an address field like any other.
     */
    test('an identification number the buyer entered pins the whole address', () => {
        buildAddressesStep({ editing: 'invoice', countryId: DNI_COUNTRY_ID });
        publishMirrorWrites(null);
        $("#invoice-address input[name='dni']").val('B12345678');

        mount({ company: 'Acme SA', companyid: '12345678', countryIso: 'ES' });

        expect(companyField().val()).toBe('');
    });

    /**
     * TWO-40: `address2` and `state` joined the tracked set when the sole-trader
     * autofill started routing `building`/`apartment` into the second line and
     * `region` into the state select (Doug's ruling). A buyer typing a second address
     * line, or choosing a county, is stating an independent answer exactly as much as
     * one typing a city - so it has to pin the address like any other field. While
     * they were absent from the record the address-wide rule missed both cases.
     */
    test('the tracked field set includes the second address line and the state', () => {
        // The whole list, not a containment check: a field silently dropped from it is
        // a field the pin stops judging.
        expect(TwoCompanySearch.MIRRORED_ADDRESS_FIELDS).toEqual([
            'company',
            'organization',
            'country',
            'address1',
            'address2',
            'postcode',
            'city',
            'state'
        ]);
    });

    test('a SECOND ADDRESS LINE the buyer entered pins the whole address, exactly as a city does', () => {
        buildAddressesStep({ editing: 'invoice' });
        publishMirrorWrites(null);
        // `address2` is a real PrestaShop address field, rendered for the country
        // formats that ask for it; the fixture omits it, so it is added here.
        street().after("<input type='text' name='address2' value='' />");
        $("#invoice-address input[name='address2']").val('Second Buyer Line');

        const search = mount(PICKED);

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(true);
        expect(companyField().val()).toBe('');
        expect(countrySelect().val()).toBe(SERVER_RENDERED_OPTION);
    });

    test('and an empty second address line does NOT pin it', () => {
        buildAddressesStep({ editing: 'invoice' });
        publishMirrorWrites(null);
        street().after("<input type='text' name='address2' value='' />");

        const search = mount(PICKED);

        expect($("#invoice-address input[name='address2']").length).toBe(1);
        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(false);
        expect(companyField().val()).toBe('Acme Trading Ltd');
    });

    /**
     * The state select is judged on the option's TEXT, never its value - PrestaShop
     * state ids are shop-local, so a record written on one shop would be unreadable on
     * another. And "unanswered" for a select is "still exactly what the server
     * rendered", because a select is never empty.
     */
    test('a STATE the buyer chose pins the whole address', () => {
        buildAddressesStep({ editing: 'invoice' });
        publishMirrorWrites(null);
        $("#invoice-address input[name='city']").after([
            '<select name="id_state">',
            '<option value="" selected>-</option>',
            '<option value="31">Kent</option>',
            '</select>'
        ].join(''));
        $("#invoice-address select[name='id_state']").val('31');

        const search = mount(PICKED);

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(true);
        expect(companyField().val()).toBe('');
    });

    test('but a state still holding what the SERVER rendered does not pin it', () => {
        buildAddressesStep({ editing: 'invoice' });
        publishMirrorWrites(null);
        $("#invoice-address input[name='city']").after([
            '<select name="id_state">',
            '<option value="" selected>-</option>',
            '<option value="31" selected>Kent</option>',
            '</select>'
        ].join(''));

        const search = mount(PICKED);

        expect($("#invoice-address select[name='id_state']").val()).toBe('31');
        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(false);
        expect(companyField().val()).toBe('Acme Trading Ltd');
    });

    /**
     * Deleting a value the mirror wrote is an edit. The buyer said no to that
     * company name, and repopulating it on the next render would be the plugin
     * arguing with them.
     */
    test('a field emptied after the mirror filled it pins the address', () => {
        buildAddressesStep({ editing: 'invoice', countryId: GB_OPTION });
        publishMirrorWrites({ company: 'Acme Trading Ltd', country: 'GB' });

        const search = mount(PICKED);

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(true);
        expect(companyField().val()).toBe('');
    });
});

describe('the comparison basis is what the mirror last wrote, not the primary', () => {
    /**
     * The load-bearing one. The primary's live company is now something else
     * entirely - the buyer changed it - and the secondary still holds the value the
     * PREVIOUS mirror wrote. That has to read as still-synced and re-sync to the new
     * company. Comparing against the primary's live value makes it read as a buyer
     * edit and pins the address forever after the first change.
     */
    test('a value the previous mirror wrote is replaced when the primary changes', () => {
        buildAddressesStep({ editing: 'invoice', countryId: GB_OPTION, company: 'Acme Trading Ltd' });
        publishMirrorWrites({ company: 'Acme Trading Ltd', organization: '', country: 'GB' });

        const search = mount({ company: 'Beta Holdings Ltd', companyid: '99999999', countryIso: 'FR' });

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(false);
        expect(companyField().val()).toBe('Beta Holdings Ltd');
        expect(countrySelect().val()).toBe(FR_OPTION);
    });

    /**
     * The same shape, with the primary's value ABSENT from the record entirely, so a
     * test that accidentally compared against the selection cannot pass by
     * coincidence: the field holds neither the recorded value nor the selection's.
     */
    test('a value that is neither the recorded one nor the primary pins the address', () => {
        buildAddressesStep({ editing: 'invoice', countryId: GB_OPTION, company: 'Gamma Ltd' });
        publishMirrorWrites({ company: 'Acme Trading Ltd', country: 'GB' });

        const search = mount({ company: 'Beta Holdings Ltd', companyid: '99999999', countryIso: 'GB' });

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(true);
        expect(companyField().val()).toBe('Gamma Ltd');
    });
});

describe('the content match trims and folds case', () => {
    test('surrounding space is not the buyer authoring a different answer', () => {
        buildAddressesStep({
            editing: 'invoice',
            countryId: GB_OPTION,
            company: '  Acme Trading Ltd  '
        });
        publishMirrorWrites({ company: 'Acme Trading Ltd', country: 'GB' });

        const search = mount({ company: 'Beta Holdings Ltd', companyid: '99999999', countryIso: 'GB' });

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(false);
        expect(companyField().val()).toBe('Beta Holdings Ltd');
    });

    test('a difference of case only is not the buyer authoring a different answer', () => {
        buildAddressesStep({
            editing: 'invoice',
            countryId: GB_OPTION,
            company: 'acme trading ltd'
        });
        publishMirrorWrites({ company: 'Acme Trading Ltd', country: 'GB' });

        const search = mount({ company: 'Beta Holdings Ltd', companyid: '99999999', countryIso: 'GB' });

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(false);
        expect(companyField().val()).toBe('Beta Holdings Ltd');
    });

    test('the street is trimmed and case-folded on the same rule', () => {
        buildAddressesStep({
            editing: 'invoice',
            countryId: GB_OPTION,
            address1: ' 1 REGISTER STREET '
        });
        publishMirrorWrites({ address1: '1 Register Street', country: 'GB' });

        const search = mount(PICKED);

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(false);
        expect(companyField().val()).toBe('Acme Trading Ltd');
    });
});

describe('an existing saved billing address is never synced over', () => {
    /**
     * The silent-overwrite case, and Doug's rule closes it with no separate
     * new-versus-existing heuristic: an address that already exists renders
     * non-empty, nothing is on record as having written those values, so it is
     * pinned.
     *
     * A rule that accepted the server-rendered `value` attribute as "unanswered" for
     * a text input would sync straight over the buyer's saved billing address, which
     * is exactly why text inputs get no such baseline while the country select does.
     */
    test('a server-rendered street, postcode and city pin the address', () => {
        buildAddressesStep({
            editing: 'invoice',
            countryId: FR_OPTION,
            address1: '9 Rue Ancienne',
            postcode: '75001',
            city: 'Paris'
        });
        publishMirrorWrites(null);

        const search = mount(PICKED);

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(true);
        expect(street().val()).toBe('9 Rue Ancienne');
        expect(companyField().val()).toBe('');
        expect(countrySelect().val()).toBe(FR_OPTION);
    });

    test('a saved company name on an existing address is not replaced', () => {
        buildAddressesStep({
            editing: 'invoice',
            countryId: FR_OPTION,
            company: 'Ancienne SARL',
            address1: '9 Rue Ancienne'
        });
        publishMirrorWrites(null);

        mount(PICKED);

        expect(companyField().val()).toBe('Ancienne SARL');
    });
});

describe('the last-written record has to survive the page load', () => {
    /**
     * The same DOM, twice, differing only in whether the cart-scoped record reached
     * this page. Without it the mirror's own previous write is indistinguishable
     * from buyer input and the address is pinned; with it, the value is recognised
     * and the re-sync happens.
     *
     * This is what makes the persisted record load-bearing rather than a
     * convenience: at mount there are no markers to read, because the navigation
     * that revealed this form destroyed them.
     */
    test('with no record published, the mirror\'s own earlier write reads as buyer input', () => {
        buildAddressesStep({ editing: 'invoice', countryId: GB_OPTION, company: 'Acme Trading Ltd' });
        publishMirrorWrites(null);

        const search = mount({ company: 'Beta Holdings Ltd', companyid: '99999999', countryIso: 'FR' });

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(true);
        expect(companyField().val()).toBe('Acme Trading Ltd');
    });

    test('with the record published, the same form re-syncs', () => {
        buildAddressesStep({ editing: 'invoice', countryId: GB_OPTION, company: 'Acme Trading Ltd' });
        publishMirrorWrites({ company: 'Acme Trading Ltd', country: 'GB' });

        const search = mount({ company: 'Beta Holdings Ltd', companyid: '99999999', countryIso: 'FR' });

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(false);
        expect(companyField().val()).toBe('Beta Holdings Ltd');
    });

    /**
     * No marker anywhere in the DOM at the moment of judgment, so the record is the
     * ONLY source. Asserted directly, because a mirror that happened to re-mark
     * first would hide the dependency.
     */
    test('the record is consulted with no marker present anywhere', () => {
        buildAddressesStep({ editing: 'invoice', countryId: GB_OPTION, company: 'Acme Trading Ltd' });
        publishMirrorWrites({ company: 'Acme Trading Ltd', country: 'GB' });

        expect(document.querySelectorAll('[' + MARKER + ']').length).toBe(0);

        const search = mount(PICKED);
        const root = search.secondaryAddressFormRoot();
        const states = search.mirroredAddressFieldStates(root);
        const companyState = states.find(state => state.name === 'company');

        expect(companyState.written).toContain('Acme Trading Ltd');
    });

    /**
     * And core's own rebuild, which is the in-page version of the same problem: it
     * replaces the address form and restores INPUT VALUES ONLY, so every marker dies
     * while every value lives. The record is what carries the attribution across it.
     */
    test('a value restored by core\'s rebuild is still recognised as ours', () => {
        buildAddressesStep({ editing: 'invoice', countryId: SERVER_RENDERED_OPTION });
        publishMirrorWrites(null);
        mount(PICKED);
        expect(companyField().val()).toBe('Acme Trading Ltd');

        // Core's country-change handler: fresh server render for the country the
        // mirror just chose, values copied back, no attributes.
        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: GB_OPTION });
        expect(companyField().attr(MARKER)).toBeUndefined();

        const search = mount({ company: 'Beta Holdings Ltd', companyid: '99999999', countryIso: 'FR' });

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(false);
    });
});

describe('the mirror reports what it wrote', () => {
    test('a populate reports the company, the number and the country', () => {
        buildAddressesStep({ editing: 'invoice', countryId: DNI_COUNTRY_ID });
        publishMirrorWrites(null);

        mount({ company: 'Acme SA', companyid: '12345678', countryIso: 'ES' });

        const reports = reportedWrites();
        expect(reports.length).toBeGreaterThan(0);
        const last = reports[reports.length - 1];
        expect(last.company).toBe('Acme SA');
        expect(last.organization).toBe('12345678');
        expect(last.country).toBe('ES');
        expect(last.token).toBe('tok');
    });

    /**
     * The country goes on the record as an ISO code, never as the option value or
     * its visible text: the id is shop-local and the label is locale-dependent, so
     * either would make the record unreadable on the next render.
     */
    test('the country is reported as an ISO code, not as a country id', () => {
        buildAddressesStep({ editing: 'invoice' });
        publishMirrorWrites(null);

        mount(PICKED);

        const last = reportedWrites().pop();
        expect(last.country).toBe('GB');
        expect(last.country).not.toBe(GB_OPTION);
    });

    /**
     * A second evaluation on the SAME page has to agree with what the server will
     * answer on the next one, or the re-mount core's own rebuild triggers would
     * judge the mirror's fresh writes against a record that predates them.
     */
    test('the published copy is kept in step with what was reported', () => {
        buildAddressesStep({ editing: 'invoice' });
        publishMirrorWrites(null);

        mount(PICKED);

        expect(window.twopayment.mirror_writes.company).toBe('Acme Trading Ltd');
        expect(window.twopayment.mirror_writes.country).toBe('GB');
    });

    test('a pinned address reports nothing, because nothing was written', () => {
        buildAddressesStep({ editing: 'invoice', address1: '9 Rue Ancienne' });
        publishMirrorWrites(null);

        mount(PICKED);

        expect(reportedWrites()).toHaveLength(0);
    });
});

describe('the country is compared as an ISO code', () => {
    /**
     * The record says GB and the form's country select holds the GB option. The
     * comparison must resolve the option to an ISO - a raw value comparison against
     * '17' would be comparing a country id to an ISO code and never match, pinning
     * every address whose country the mirror had already set.
     */
    test('the recorded ISO matches the select holding that country', () => {
        buildAddressesStep({ editing: 'invoice', countryId: GB_OPTION });
        publishMirrorWrites({ country: 'GB' });

        const search = mount(null);
        const root = search.secondaryAddressFormRoot();
        const state = search.mirroredAddressFieldStates(root).find(s => s.name === 'country');

        expect(state.current).toBe('GB');
        expect(search.mirroredFieldStillHoldsWhatWeWrote(state)).toBe(true);
    });

    test('a country the buyer changed to something else pins the address', () => {
        buildAddressesStep({ editing: 'invoice', countryId: FR_OPTION });
        publishMirrorWrites({ country: 'GB' });

        const search = mount(PICKED);

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(true);
        expect(countrySelect().val()).toBe(FR_OPTION);
    });
});

/**
 * TWO-40, round 1 of the content-match rework.
 *
 * The pin gates the POPULATE and nothing else. Of the three repair operations,
 * RE-MARK writes no value at all and RE-PUBLISH is gated on the mirror's own marked
 * company name - so a pin raised by the COMPANY field makes both inert. COMPLETE
 * shares that name gate, which means a pin raised by any OTHER field leaves it
 * firing, and it is meant to: gating it would put back the defect it was written to
 * close, a mirrored company name on the order with no organisation number beside it.
 *
 * Pinned here so the deliberate behaviour is a spec rather than a comment.
 */
describe('the completion is deliberately not gated on the pin', () => {
    test('completes the number half on an address pinned by an unrelated field', () => {
        // Spain, so the form has an identification field at all - see
        // buildAddressesStep().
        buildAddressesStep({ editing: 'invoice', countryId: DNI_COUNTRY_ID });
        publishMirrorWrites(null);
        const memory = {};
        const picked = { company: 'Acme SA', companyid: '12345678', countryIso: 'ES' };
        const identifier = () => $("#invoice-address input[name='dni']");

        mount(picked, { mirrorMemory: memory });
        expect(identifier().val()).toBe('12345678');

        // The buyer types a city of their own. Nothing is on record as having
        // written it, so from here on the address is pinned - by the CITY, with the
        // mirror's marked company name untouched.
        $("#invoice-address input[name='city']").val('Bristol');

        // Country to Great Britain: core rebuilds the form, which drops the
        // identification field, and the re-mark moves the number back to pending
        // because it is now placed nowhere.
        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: GB_OPTION });
        mount(picked, { mirrorMemory: memory });
        expect(identifier().length).toBe(0);
        expect(memory.organizationPending).toBe('12345678');

        // And back to Spain: the field returns, empty and unmarked.
        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: DNI_COUNTRY_ID });
        expect(identifier().val()).toBe('');
        expect(identifier().attr(MARKER)).toBeUndefined();

        const search = mount(picked, { mirrorMemory: memory });
        const root = search.secondaryAddressFormRoot();
        const states = search.mirroredAddressFieldStates(root);
        const stateOf = name => states.find(state => state.name === name);

        // The premise, stated rather than assumed: the address IS pinned, the field
        // that pinned it is the city, and the company name is still ours.
        expect(search.secondaryAddressIsPinned(root)).toBe(true);
        expect(search.mirroredFieldStillHoldsWhatWeWrote(stateOf('city'))).toBe(false);
        expect(search.mirroredFieldStillHoldsWhatWeWrote(stateOf('company'))).toBe(true);
        expect(companyField().val()).toBe('Acme SA');

        // The completion fires anyway, into the empty unmarked field, and reports
        // the write so the next render does not read it as the buyer's.
        expect(identifier().val()).toBe('12345678');
        expect(identifier().attr(MARKER)).toBe('12345678');
        expect(reportedWrites().pop().organization).toBe('12345678');
        // The city the buyer typed is untouched throughout.
        expect($("#invoice-address input[name='city']").val()).toBe('Bristol');
    });
});

describe('scope: the pin only applies where there is a secondary address', () => {
    test('the shipping pass has no secondary address form to judge', () => {
        buildAddressesStep({ editing: 'delivery' });

        const search = mount(PICKED);

        expect(search.secondaryAddressFormRoot()).toBeNull();
    });

    /**
     * When the buyer's current selection indicates the two addresses are the SAME
     * there is one address, and no secondary to protect or to write into.
     */
    test('no secondary address exists when the buyer states the addresses are the same', () => {
        buildAddressesStep({ editing: 'delivery', sameAddress: true });

        const search = mount(PICKED);

        expect(search.secondaryAddressFormRoot()).toBeNull();
    });

    /**
     * The ordinary company lookup's street/postcode/city fill is document-wide
     * unless it is handed a block, and on the shipping pass it stays that way -
     * those writes go into the PRIMARY address and are not the secondary's
     * business.
     */
    test('a fill on the shipping pass reports nothing about the secondary address', () => {
        buildAddressesStep({ editing: 'delivery' });
        const search = mount(PICKED);

        search.autoFillAddressIfNeeded({
            addresses: [{ street_address: '1 Register Street', postal_code: 'EC1A 1BB', city: 'London' }]
        }, undefined);

        expect($("input[name='address1']").val()).toBe('1 Register Street');
        expect(reportedWrites()).toHaveLength(0);
    });

    /**
     * On the secondary address the same fill IS attributable, and has to be, or the
     * street the lookup wrote pins the address on the next render.
     */
    test('a fill on the secondary address is reported as ours', () => {
        buildAddressesStep({ editing: 'invoice' });
        publishMirrorWrites(null);
        const search = mount(PICKED);

        search.autoFillAddressIfNeeded({
            addresses: [{ street_address: '1 Register Street', postal_code: 'EC1A 1BB', city: 'London' }]
        }, undefined);

        expect(street().val()).toBe('1 Register Street');
        const last = reportedWrites().pop();
        expect(last.address1).toBe('1 Register Street');
        expect(last.postcode).toBe('EC1A 1BB');
        expect(last.city).toBe('London');
    });

    /**
     * TWO-40, round 1 of the content-match rework.
     *
     * "No secondary address on screen" and "the invoice form IS on screen but the
     * scope resolution failed closed" are different states, and the fill used to
     * treat them as one - so on a theme that flattens the block containers away, the
     * document-wide fill wrote into exactly the markup visibleAddressFormRoot() had
     * just refused to scope to, with the other address inside it. It fails closed
     * now, the same way the mirror does.
     */
    test('a fill on an invoice form whose scope failed closed writes nothing, anywhere', () => {
        buildAddressesStep({
            editing: 'invoice',
            countryId: GB_OPTION,
            blockContainers: false,
            blockIds: false
        });
        publishMirrorWrites(null);
        const search = mount(PICKED);

        // The premise, both halves: the invoice form is the editable one, and no
        // single-address scope resolves for it.
        expect(search.visibleAddressFormType()).toBe('invoice');
        expect(search.visibleAddressFormRoot()).toBeNull();

        search.autoFillAddressIfNeeded({
            addresses: [{ street_address: '1 Register Street', postal_code: 'EC1A 1BB', city: 'London' }]
        }, undefined);

        expect($("input[name='address1']").val()).toBe('');
        expect($("input[name='postcode']").val()).toBe('');
        expect($("input[name='city']").val()).toBe('');
        expect($("input[name='address1']").attr(MARKER)).toBeUndefined();
        expect(reportedWrites()).toHaveLength(0);
        // And the other address, which is what a document-wide write here would be
        // reaching into, still names only the saved address the buyer picked.
        expect($("input[name='id_address_delivery']").val()).toBe('7');
    });

    /**
     * The same flattened theme with the OTHER address gone, so the scope resolves
     * again - the companion that stops the skip above from passing because the
     * fixture is inert rather than because the guard fired.
     */
    test('the same flattened form still fills when it is the page\'s only address', () => {
        buildAddressesStep({
            editing: 'invoice',
            countryId: GB_OPTION,
            blockContainers: false,
            blockIds: false
        });
        document.querySelector('.js-address-selector').remove();
        publishMirrorWrites(null);
        const search = mount(PICKED);

        expect(search.visibleAddressFormRoot()).not.toBeNull();

        search.autoFillAddressIfNeeded({
            addresses: [{ street_address: '1 Register Street', postal_code: 'EC1A 1BB', city: 'London' }]
        }, undefined);

        expect($("input[name='address1']").val()).toBe('1 Register Street');
    });

    /**
     * And the fill's own writes must not then pin the address they just filled.
     */
    test('a street the fill wrote does not pin the address on the next render', () => {
        buildAddressesStep({
            editing: 'invoice',
            countryId: GB_OPTION,
            address1: '1 Register Street',
            postcode: 'EC1A 1BB',
            city: 'London'
        });
        publishMirrorWrites({
            address1: '1 Register Street',
            postcode: 'EC1A 1BB',
            city: 'London',
            country: 'GB'
        });

        const search = mount({ company: 'Beta Holdings Ltd', companyid: '99999999', countryIso: 'FR' });

        expect(search.secondaryAddressIsPinned(search.secondaryAddressFormRoot())).toBe(false);
        expect(companyField().val()).toBe('Beta Holdings Ltd');
    });
});
