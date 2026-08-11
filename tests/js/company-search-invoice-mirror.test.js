/**
 * TWO-40 #13, the ENABLED-mode write side: carrying a company selection made on
 * the shipping pass over to the invoice address form.
 *
 * The design this replaces assumed the mirror could run at selection time,
 * copying company + country from the block the search ran in into the billing
 * block. PrestaShop cannot do that. Core sets the delivery and invoice form
 * flags in mutually exclusive branches, so there is never more than one editable
 * address form on the page: at the moment the buyer picks a company there are no
 * invoice inputs in the document to write into, and the invoice side is either a
 * radio selector over saved addresses or absent. The buyer states the two
 * addresses differ by following a LINK, whose href navigates - so the reveal is
 * a page load and there is no client-side event to hook.
 *
 * The mirror is therefore a cross-page-load operation, run at mount, and the
 * selection it reads has to come from the cart-scoped record the server
 * publishes (the in-memory one dies with the page).
 *
 * Every fixture here is core's own markup - see buildAddressesStep() in
 * ps-harness.js for what is reproduced and from where. A fixture invented for
 * the occasion would prove nothing about a plugin whose job is to read and write
 * that markup.
 */

'use strict';

const {
    loadCompanySearch,
    buildAddressesStep,
    rebuildAddressesStepAsCoreDoes,
    stubAjax,
    releaseWidgets,
    DNI_COUNTRY_ID,
    OTHER_DNI_COUNTRY_ID
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const MARKER = 'data-two-autofilled-value';

const GB_OPTION = '17';
const FR_OPTION = '8';
// Spain and Mexico: the only two countries whose stock `need_identification_number`
// flag makes core append `dni` to the address format, so the only two whose forms
// carry an identification field at all. Every other option here renders without
// one - see buildAddressesStep().
const ES_OPTION = DNI_COUNTRY_ID;
const MX_OPTION = OTHER_DNI_COUNTRY_ID;
// What the fixture's server rendered as selected, as core always does: a real
// country, never the placeholder alone. So an "unanswered" country select on a
// PrestaShop form reads as THIS, not as ''.
const SERVER_RENDERED_OPTION = '1';

let TwoCompanySearch;
let $;
let ajax;

beforeEach(() => {
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    ajax = stubAjax($);
    // The server-built country id -> ISO map, which is how the module resolves a
    // country on core's classic theme: its country <option>s carry no ISO
    // attribute at all, only the country id.
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

/**
 * Mount the search exactly as TwoCheckoutManager mounts it in the address area,
 * with a confirmed selection available through the injected getter.
 *
 * @param {?Object} selection what the page-lifetime holder answers with
 * @param {Object} [extraConfig]
 * @returns {Object} the instance
 */
function mount(selection, extraConfig) {
    return new TwoCompanySearch(Object.assign({
        checkoutHost: CHECKOUT_HOST,
        addressLookupEnabled: true,
        getConfirmedCompany: () => selection
    }, extraConfig || {}));
}

const PICKED = { company: 'Acme Trading Ltd', companyid: '12345678', addressId: 0, countryIso: 'GB' };

function companyField() {
    return $("#invoice-address input[name='company']");
}

function countrySelect() {
    return $("#invoice-address select[name='id_country']");
}

function identifierField() {
    return $("#invoice-address input[name='dni']");
}

describe('the signal: what the buyer has stated about their invoice address', () => {
    test('the shared-address control being present and reporting "shared" states they do NOT differ', () => {
        buildAddressesStep({ editing: 'delivery', sameAddress: true, invoiceBlock: true });

        // Deliberately WITH an invoice block present too, so the answer can only
        // come from the live control and not from the structural fallback - the
        // control's polarity is inverted from the question, and reading it the
        // wrong way round is the failure this asserts against.
        expect(mount(PICKED).buyerStatesInvoiceAddressDiffers()).toBe(false);
    });

    test('the shared-address control reporting "not shared" states they differ', () => {
        buildAddressesStep({ editing: 'delivery', sameAddress: false });

        expect(mount(PICKED).buyerStatesInvoiceAddressDiffers()).toBe(true);
    });

    test('an invoice address FORM on a later pass states they differ, with no control on the page', () => {
        buildAddressesStep({ editing: 'invoice' });

        expect(document.querySelector("input[name='use_same_address']")).toBeNull();
        expect(mount(PICKED).buyerStatesInvoiceAddressDiffers()).toBe(true);
    });

    test('an invoice address SELECTOR states they differ just as a form does', () => {
        buildAddressesStep({ editing: 'delivery', sameAddress: false });
        // Turn the delivery side into the editable one with the control removed,
        // leaving the invoice SELECTOR as the only evidence - core's shape once
        // the buyer has saved addresses on both sides.
        document.querySelector("input[name='use_same_address']").remove();

        expect(document.querySelector('#invoice-addresses')).not.toBeNull();
        expect(mount(PICKED).buyerStatesInvoiceAddressDiffers()).toBe(true);
    });

    test('no control and no invoice block at all is NOT read as differing', () => {
        // core's shared-address pass: a link that navigates, and nothing else.
        buildAddressesStep({ editing: 'delivery', sameAddress: true, invoiceBlock: false });
        document.querySelector("input[name='use_same_address']").remove();

        expect(document.querySelector('a[data-link-action="different-invoice-address"]')).not.toBeNull();
        expect(mount(PICKED).buyerStatesInvoiceAddressDiffers()).toBe(false);
    });
});

describe('which address the editable form is for', () => {
    test('reads the delivery pass from the form field core emits for it', () => {
        buildAddressesStep({ editing: 'delivery' });

        expect(mount(PICKED).visibleAddressFormType()).toBe('delivery');
    });

    test('reads the invoice pass the same way', () => {
        buildAddressesStep({ editing: 'invoice' });

        expect(mount(PICKED).visibleAddressFormType()).toBe('invoice');
    });

    test('is empty when neither side is editable', () => {
        buildAddressesStep({ editing: 'delivery', sameAddress: false });
        document.querySelector('#delivery-address').remove();

        expect(mount(PICKED).visibleAddressFormType()).toBe('');
    });
});

describe('the scope every field lookup is confined to', () => {
    test('resolves to the rendered form\'s own wrapper, the innermost of the nested scopes', () => {
        // Core nests three candidate scopes: the step's outer `.js-address-form`,
        // the block id, and the rendered form's own `.js-address-form` inside it
        // (`customer/_partials/address-form.tpl`). Innermost-first, so the answer is
        // the inner wrapper - and nothing asserted on which one it actually is
        // until a fixture carried all three.
        buildAddressesStep({ editing: 'invoice' });

        const root = mount(PICKED).visibleAddressFormRoot();

        expect(root.className).toBe('js-address-form');
        expect(root.closest('#invoice-address')).not.toBeNull();
        // And whichever it is, it has to scope: every field the mirror writes is
        // inside it, and no field of the other address block is.
        expect(root.querySelectorAll("input[name='company']").length).toBe(1);
        expect(root.querySelectorAll("select[name='id_country']").length).toBe(1);
        expect(root.querySelector("input[name='saveAddress']").value).toBe('invoice');
    });
});

describe('the mirror, on the invoice pass', () => {
    test('writes the company name, its organisation number and the country, and nothing else', () => {
        // Rendered for Spain, because that is what puts an identification field on
        // the form at all - see buildAddressesStep().
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });

        mount(PICKED);

        expect(companyField().val()).toBe('Acme Trading Ltd');
        expect(identifierField().val()).toBe('12345678');
        expect(countrySelect().val()).toBe(GB_OPTION);
        // The address lines are emphatically not mirrored: the buyer has just
        // said this is a different address.
        expect($("#invoice-address input[name='address1']").val()).toBe('');
        expect($("#invoice-address input[name='postcode']").val()).toBe('');
        expect($("#invoice-address input[name='city']").val()).toBe('');
        // And never the VAT field, which is not an organisation-number field and
        // switches tax off on a foreign address.
        expect($("#invoice-address input[name='vat_number']").val()).toBe('');
    });

    test('marks what it wrote, so a later pass can tell it from buyer input', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });

        mount(PICKED);

        expect(identifierField().attr(MARKER)).toBe('12345678');
        expect(companyField().attr(MARKER)).toBe('Acme Trading Ltd');
        expect(countrySelect().attr(MARKER)).toBe(GB_OPTION);
    });

    test('resolves the country through an ISO attribute when the theme emits one', () => {
        buildAddressesStep({ editing: 'invoice', countryIsoAttrs: true });
        delete window.twopayment.countries;

        mount(PICKED);

        expect(countrySelect().val()).toBe(GB_OPTION);
    });

    test('leaves the country alone when the shop does not sell to it', () => {
        buildAddressesStep({ editing: 'invoice' });

        mount({ company: 'Acme Trading Ltd', companyid: '1', countryIso: 'NO' });

        expect(companyField().val()).toBe('Acme Trading Ltd');
        expect(countrySelect().val()).toBe(SERVER_RENDERED_OPTION);
        expect(countrySelect().attr(MARKER)).toBeUndefined();
    });

    test('writes only inside the visible form, never by global selector', () => {
        buildAddressesStep({ editing: 'invoice' });
        // A company input elsewhere on the page - the relocated payment-tile
        // control is one, and a theme can produce others. A write by global
        // selector is the defect class this whole feature has to avoid.
        document.body.insertAdjacentHTML(
            'beforeend',
            '<div id="elsewhere"><input type="text" name="company" value=""></div>'
        );

        mount(PICKED);

        expect(companyField().val()).toBe('Acme Trading Ltd');
        expect($("#elsewhere input[name='company']").val()).toBe('');
    });

    test('does not write a country it cannot shape-check', () => {
        buildAddressesStep({ editing: 'invoice' });

        mount({ company: 'Acme Trading Ltd', companyid: '1', countryIso: 'not-a-country' });

        expect(countrySelect().val()).toBe(SERVER_RENDERED_OPTION);
        expect(countrySelect().attr(MARKER)).toBeUndefined();
    });
});

describe('the marker guard: buyer input is never overwritten', () => {
    test('leaves a company name the buyer typed by hand exactly as it is', () => {
        buildAddressesStep({ editing: 'invoice', company: 'My Own Company Name' });

        mount(PICKED);

        expect(companyField().val()).toBe('My Own Company Name');
        // And it is not falsely claimed as ours, which would let a later clear
        // delete the buyer's own answer.
        expect(companyField().attr(MARKER)).toBeUndefined();
    });

    test('leaves a country the buyer already answered alone', () => {
        // "Answered" cannot mean "non-empty" on a PrestaShop form - core renders a
        // real country selected, so the select is non-empty from the start. It
        // means the buyer has MOVED it off what the server rendered.
        buildAddressesStep({ editing: 'invoice' });
        countrySelect().val(FR_OPTION);

        mount(PICKED);

        expect(countrySelect().val()).toBe(FR_OPTION);
        expect(countrySelect().attr(MARKER)).toBeUndefined();
    });

    test('DOES write over the country the server rendered, which is the whole point', () => {
        // The regression this guards: on a real form the country select is never
        // empty, so a mirror that only writes into an empty select never fires at
        // all. Verified against core - `form-fields.tpl` marks the placeholder
        // `selected` AND the option matching the field's value, which
        // `CustomerAddressFormatter` always sets, so last-selected wins.
        buildAddressesStep({ editing: 'invoice' });

        expect(countrySelect().val()).toBe(SERVER_RENDERED_OPTION);

        mount(PICKED);

        expect(countrySelect().val()).toBe(GB_OPTION);
        expect(countrySelect().attr(MARKER)).toBe(GB_OPTION);
    });

    test('writes into a placeholder-only select too, for themes that render one', () => {
        // The EXCEPTION case, named as one: core does not emit a form with no real
        // country selected. A theme that overrides the country field block can, so
        // an empty select stays writable - it is just not the case that matters.
        buildAddressesStep({ editing: 'invoice', countryId: null });

        expect(countrySelect().val()).toBeFalsy();

        mount(PICKED);

        expect(countrySelect().val()).toBe(GB_OPTION);
    });

    test('DOES replace a value a previous mirror wrote and the buyer has not touched', () => {
        buildAddressesStep({ editing: 'invoice' });
        // One page, one memory - the manager's, shared across every rebuild of the
        // search. A genuinely DIFFERENT company still mirrors.
        const memory = {};

        mount(PICKED, { mirrorMemory: memory });
        mount({ company: 'Beta Holdings AS', companyid: '99', countryIso: 'FR' }, { mirrorMemory: memory });

        expect(companyField().val()).toBe('Beta Holdings AS');
        expect(countrySelect().val()).toBe(FR_OPTION);
    });

    test('stops replacing once the buyer edits what a previous mirror wrote', () => {
        buildAddressesStep({ editing: 'invoice' });
        const memory = {};

        mount(PICKED, { mirrorMemory: memory });
        companyField().val('Renamed By The Buyer');

        mount({ company: 'Beta Holdings AS', companyid: '99', countryIso: 'FR' }, { mirrorMemory: memory });

        expect(companyField().val()).toBe('Renamed By The Buyer');
    });
});

describe('the company name and its organisation number travel together', () => {
    /**
     * Why this is a requirement and not a nicety: once the buyer saves this
     * address, the order-payload resolver can reach the tier that reads the
     * company off the ADDRESS - the `company` field for the name, the
     * identification field for the number. A mirrored name with no number beside
     * it is then an order carrying a company the buyer never typed and no
     * organisation number at all, which is worse than not mirroring.
     */
    test('a buyer\'s own identification number blocks the name write too', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        identifierField().val('99999999');

        mount(PICKED);

        expect(identifierField().val()).toBe('99999999');
        expect(companyField().val()).toBe('');
        expect(companyField().attr(MARKER)).toBeUndefined();
        expect(countrySelect().val()).toBe(ES_OPTION);
    });

    test('a new company replaces both halves of the previous mirror\'s pair', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const memory = {};

        mount(PICKED, { mirrorMemory: memory });
        mount({ company: 'Beta Holdings AS', companyid: '99887766', countryIso: 'FR' }, { mirrorMemory: memory });

        expect(companyField().val()).toBe('Beta Holdings AS');
        expect(identifierField().val()).toBe('99887766');
    });

    test('the name still travels on a form with no identification field at all', () => {
        // Whether the field exists is decided by the country's address format, so
        // on most countries there is nowhere to put a number. The ordinary company
        // lookup has always behaved this way on those forms. The default render is
        // Germany, which is one of them - no removal needed, and none wanted: a
        // fixture that had to be edited to reach the majority case was the reason
        // this gap went unseen.
        buildAddressesStep({ editing: 'invoice' });
        expect(identifierField().length).toBe(0);

        mount(PICKED);

        expect(companyField().val()).toBe('Acme Trading Ltd');
    });

    test('the number is written only inside the visible form', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        document.body.insertAdjacentHTML(
            'beforeend',
            '<div id="elsewhere"><input type="text" name="dni" value=""></div>'
        );

        mount(PICKED);

        expect(identifierField().val()).toBe('12345678');
        expect($("#elsewhere input[name='dni']").val()).toBe('');
    });
});

describe('populating and re-marking are two different operations', () => {
    test('re-marks the organisation number core restored, as it does the name', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const memory = {};
        mount(PICKED, { mirrorMemory: memory });

        // Mexico, not France: the buyer moving between the only two countries whose
        // format carries an identification field is the one way the field SURVIVES
        // a rebuild, which is what this test is about. A rebuild into a country
        // without the field is a different case entirely - see the completion tests.
        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: MX_OPTION });

        expect(identifierField().val()).toBe('12345678');
        expect(identifierField().attr(MARKER)).toBeUndefined();

        mount(PICKED, { mirrorMemory: memory });

        // Without this the plugin can no longer tell its own number from the
        // buyer's, and clearLookupWrittenAddressIdentifiers() may never delete it.
        expect(identifierField().attr(MARKER)).toBe('12345678');
    });

    /**
     * Core's `.js-country` handler is delegated on `body`, so the mirror's own
     * country write triggers it: it POSTs `action=addressForm`, replaces every
     * `.js-address-form` with the server's response, and restores the previous
     * values with an INPUT-only, VALUE-only loop. See
     * rebuildAddressesStepAsCoreDoes() for the reproduction and its source.
     */
    test('re-marks the company core restored, without restoring the marker itself', () => {
        buildAddressesStep({ editing: 'invoice' });
        const memory = {};
        mount(PICKED, { mirrorMemory: memory });

        expect(companyField().attr(MARKER)).toBe('Acme Trading Ltd');

        // The buyer changes country; core rebuilds the form around them.
        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: FR_OPTION });

        expect(companyField().val()).toBe('Acme Trading Ltd');
        expect(companyField().attr(MARKER)).toBeUndefined();

        // ...and the manager rebuilds the search on `updatedAddressForm`.
        mount(PICKED, { mirrorMemory: memory });

        expect(companyField().attr(MARKER)).toBe('Acme Trading Ltd');
    });

    test('does not re-mirror the country the buyer just changed to', () => {
        buildAddressesStep({ editing: 'invoice' });
        const memory = {};
        mount(PICKED, { mirrorMemory: memory });

        expect(countrySelect().val()).toBe(GB_OPTION);

        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: FR_OPTION });
        mount(PICKED, { mirrorMemory: memory });

        // The buyer's new country stands, and is not claimed as ours: a populate
        // that ran again would see it as "still what the server rendered" and put
        // the company's country back, overruling them.
        expect(countrySelect().val()).toBe(FR_OPTION);
        expect(countrySelect().attr(MARKER)).toBeUndefined();
    });

    test('does not re-fill a company the buyer deliberately cleared', () => {
        buildAddressesStep({ editing: 'invoice' });
        const memory = {};
        mount(PICKED, { mirrorMemory: memory });

        companyField().val('');
        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: FR_OPTION });
        mount(PICKED, { mirrorMemory: memory });

        expect(companyField().val()).toBe('');
        expect(companyField().attr(MARKER)).toBeUndefined();
    });

    test('the re-mark never populates: an empty field stays empty and unclaimed', () => {
        buildAddressesStep({ editing: 'invoice' });
        const memory = { companyid: '12345678', company: 'Acme Trading Ltd', countryValue: GB_OPTION };

        const instance = mount(null, { mirrorMemory: memory });
        expect(instance.reapplyMirrorMarkers(instance.visibleAddressFormRoot())).toBe(false);

        expect(companyField().val()).toBe('');
        expect(companyField().attr(MARKER)).toBeUndefined();
        expect(countrySelect().val()).toBe(SERVER_RENDERED_OPTION);
    });
});

describe('the rebuild that separates the number from the name', () => {
    /**
     * Whether the form HAS an identification field is decided by the COUNTRY:
     * `AddressFormat::getFormat()` appends `dni` only when
     * `Country::isNeedDniByCountryId()` is true, which on stock data is Spain and
     * Mexico alone. So the mirror's own country write is a write that changes which
     * fields exist - and core's rebuild is where the pair can come apart in both
     * directions.
     */
    test('completes the number half when the rebuild is what produced somewhere to put it', () => {
        // Germany rendered: no identification field, so the name travels alone and
        // the number is owed. Then the mirror's country write to Spain rebuilds the
        // form WITH an empty, required identification field.
        buildAddressesStep({ editing: 'invoice' });
        const memory = {};
        const picked = { company: 'Acme Trading Ltd', companyid: '12345678', countryIso: 'ES' };

        mount(picked, { mirrorMemory: memory });

        expect(companyField().val()).toBe('Acme Trading Ltd');
        expect(countrySelect().val()).toBe(ES_OPTION);
        expect(identifierField().length).toBe(0);

        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: ES_OPTION });
        expect(identifierField().length).toBe(1);
        expect(identifierField().val()).toBe('');

        mount(picked, { mirrorMemory: memory });

        // Without this the once-per-company populate gate refuses, and the order
        // carries a company name the buyer never typed with no organisation number
        // beside it - on a form where core requires one.
        expect(identifierField().val()).toBe('12345678');
        expect(identifierField().attr(MARKER)).toBe('12345678');
        expect(companyField().val()).toBe('Acme Trading Ltd');
    });

    test('restores the number the rebuild dropped when a field for it comes back', () => {
        // The reverse pairing. Spain rendered, so the pair is written in full; the
        // country write to Great Britain rebuilds into a form with no identification
        // field, and core's INPUT-only restore loop cannot restore a field the new
        // render does not emit. The number is not the buyer's to keep or clear - it
        // simply has nowhere to be - so it must go back when somewhere reappears.
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const memory = {};

        mount(PICKED, { mirrorMemory: memory });
        expect(identifierField().val()).toBe('12345678');

        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: GB_OPTION });
        mount(PICKED, { mirrorMemory: memory });

        expect(identifierField().length).toBe(0);
        expect(companyField().val()).toBe('Acme Trading Ltd');

        // The buyer goes back to a country that asks for one.
        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: ES_OPTION });
        mount(PICKED, { mirrorMemory: memory });

        expect(identifierField().val()).toBe('12345678');
        expect(identifierField().attr(MARKER)).toBe('12345678');
    });

    test('will not complete the number against a name the buyer has made their own', () => {
        // The completion is gated on the MARKED name, never on the number field
        // being empty - "empty" is the very test the populate gate exists to
        // refuse. A name the buyer has retyped is not the mirror's pair to finish.
        buildAddressesStep({ editing: 'invoice' });
        const memory = {};
        const picked = { company: 'Acme Trading Ltd', companyid: '12345678', countryIso: 'ES' };

        mount(picked, { mirrorMemory: memory });
        companyField().val('Renamed By The Buyer');

        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: ES_OPTION });
        mount(picked, { mirrorMemory: memory });

        expect(companyField().val()).toBe('Renamed By The Buyer');
        expect(identifierField().val()).toBe('');
        expect(identifierField().attr(MARKER)).toBeUndefined();
    });

    test('never completes over a number already in the form', () => {
        buildAddressesStep({ editing: 'invoice' });
        const memory = {};
        const picked = { company: 'Acme Trading Ltd', companyid: '12345678', countryIso: 'ES' };

        mount(picked, { mirrorMemory: memory });

        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: ES_OPTION });
        identifierField().val('99999999');
        mount(picked, { mirrorMemory: memory });

        expect(identifierField().val()).toBe('99999999');
        expect(identifierField().attr(MARKER)).toBeUndefined();
    });

    test('does not re-fill a number the buyer cleared on a form that kept its field', () => {
        // The buyer-cleared gate, on the path where it still applies in full: the
        // field existed, the mirror wrote it, the buyer emptied it, and the rebuild
        // kept the field. Nothing is owed and nothing is refilled.
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const memory = {};

        mount(PICKED, { mirrorMemory: memory });
        identifierField().val('');

        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: MX_OPTION });
        mount(PICKED, { mirrorMemory: memory });

        expect(identifierField().val()).toBe('');
        expect(identifierField().attr(MARKER)).toBeUndefined();
    });
});

describe('when the mirror must be a true no-op', () => {
    test('when the selection carries a company name with no organisation number', () => {
        // Every other guard on this selection requires the PAIR - the manager's
        // setter and the intent check both do - and a name that travels without
        // its number is exactly what a weaker guard here would produce.
        buildAddressesStep({ editing: 'invoice' });

        mount({ company: 'Acme Trading Ltd', companyid: '', countryIso: 'GB' });

        expect(companyField().val()).toBe('');
        expect(companyField().attr(MARKER)).toBeUndefined();
        expect(countrySelect().val()).toBe(SERVER_RENDERED_OPTION);
    });

    test('on the delivery pass, even with everything else in place', () => {
        buildAddressesStep({ editing: 'delivery', sameAddress: false });

        mount(PICKED);

        expect($("#delivery-address input[name='company']").val()).toBe('');
        expect($("#delivery-address select[name='id_country']").val()).toBe(SERVER_RENDERED_OPTION);
        expect($("#delivery-address select[name='id_country']").attr(MARKER)).toBeUndefined();
    });

    test('when the buyer has not stated the addresses differ', () => {
        buildAddressesStep({ editing: 'invoice' });
        // The invoice form is on screen, but the buyer's live statement says the
        // two addresses are one - the control wins over the structural fallback.
        document.querySelector('#invoice-address').insertAdjacentHTML(
            'afterbegin',
            '<input name="use_same_address" type="checkbox" value="1" checked>'
        );

        mount(PICKED);

        expect(companyField().val()).toBe('');
        expect(companyField().attr(MARKER)).toBeUndefined();
    });

    test('when the merchant has address population switched off', () => {
        buildAddressesStep({ editing: 'invoice' });

        mount(PICKED, { addressLookupEnabled: false });

        expect(companyField().val()).toBe('');
    });

    test('when there is no confirmed selection for this cart', () => {
        buildAddressesStep({ editing: 'invoice' });

        mount(null);

        expect(companyField().val()).toBe('');
    });

    test('when no getter was injected at all', () => {
        buildAddressesStep({ editing: 'invoice' });

        const instance = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            addressLookupEnabled: true
        });

        expect(instance.confirmedCompanyForMirror()).toBeNull();
        expect(companyField().val()).toBe('');
    });

    test('when the getter throws', () => {
        buildAddressesStep({ editing: 'invoice' });

        mount(undefined, {
            getConfirmedCompany: () => {
                throw new Error('holder unavailable');
            }
        });

        expect(companyField().val()).toBe('');
    });
});
