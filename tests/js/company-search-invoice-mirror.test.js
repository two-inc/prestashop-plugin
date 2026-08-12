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

function organizationField() {
    return $("input[name='companyid']");
}

/**
 * Retype the company name over what the mirror wrote, the way a buyer does.
 *
 * Through a real `input` event, because that is what
 * setupCompanyInputSync() binds clearStaleOrganizationSelection() to - setting
 * `.val()` alone would prove nothing about the guard ever running.
 *
 * @param {string} value
 * @returns {void}
 */
function retypeCompanyName(value) {
    const field = companyField();
    field.val(value);
    field.get(0).dispatchEvent(new window.Event('input', { bubbles: true }));
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

/**
 * TWO-40, adversarial review round 5, B6.
 *
 * The scope resolution's candidate list used to end in `form`, so a theme whose
 * markup does not carry core's block ids resolved to the step's OUTER form - the
 * one that contains BOTH address blocks. A mirror scoped to that is a
 * document-wide write, which is the single defect class this whole feature exists
 * to prevent. It went unnoticed because every fixture carried the block ids, so
 * the fallback was never the path taken.
 *
 * It now fails CLOSED: no identifiable single-address scope means no mirror.
 */
describe('the scope fails closed rather than widening', () => {
    test('resolves nothing when the only candidate spans both address blocks', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION, blockContainers: false });

        // The premise: the step wrapper is the only ancestor left, and the OTHER
        // address block - the delivery selector over saved addresses - is inside it.
        expect(document.querySelector('#invoice-address')).toBeNull();
        expect(document.querySelector('#delivery-addresses')).not.toBeNull();

        expect(mount(PICKED).visibleAddressFormRoot()).toBeNull();
    });

    test('and therefore writes nothing, anywhere', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION, blockContainers: false });
        // The live shared-address control, reporting NOT shared. Load-bearing, not
        // scenery: flattening the block containers also removes the structural
        // signal the mirror reads for "the buyer says the addresses differ", so
        // without this the mirror no-ops for that reason instead and the assertions
        // below hold no matter what the scope resolution answers.
        document.querySelector("input[name='saveAddress']").insertAdjacentHTML(
            'beforebegin',
            '<input name="use_same_address" type="checkbox" value="1">'
        );
        expect(mount(PICKED).buyerStatesInvoiceAddressDiffers()).toBe(true);

        mount(PICKED);

        expect($("input[name='company']").val()).toBe('');
        expect($("input[name='company']").attr(MARKER)).toBeUndefined();
        expect($("input[name='dni']").val()).toBe('');
        expect($("input[name='dni']").attr(MARKER)).toBeUndefined();
        expect($("select[name='id_country']").val()).toBe(ES_OPTION);
        expect($("select[name='id_country']").attr(MARKER)).toBeUndefined();
        expect($("input[name='companyid']").val()).toBe('');
    });

    // Round 6. The guard above recognised address blocks by their ids only, so the
    // very case it was written for got through: drop core's ids, keep the rest of
    // its markup, and the step wrapper - which core emits itself - looks blockless
    // while the other address is still inside it. Probed at HEAD before the fix: the
    // root resolved to `js-address-form` with the delivery radio inside the scope.
    test('resolves nothing when the other block kept core\'s classes but lost its ids', () => {
        buildAddressesStep({
            editing: 'invoice',
            countryId: ES_OPTION,
            blockContainers: false,
            blockIds: false
        });

        // The premise, stated both ways round: no id anywhere for an id-only guard
        // to find, and the other address block nonetheless present and reachable.
        expect(document.querySelector('#invoice-address')).toBeNull();
        expect(document.querySelector('#delivery-addresses')).toBeNull();
        expect(document.querySelector('.js-address-selector')).not.toBeNull();
        expect(document.querySelectorAll("input[name='id_address_delivery']").length).toBe(1);

        expect(mount(PICKED).visibleAddressFormRoot()).toBeNull();
    });

    test('and writes nothing, anywhere, on that markup either', () => {
        buildAddressesStep({
            editing: 'invoice',
            countryId: ES_OPTION,
            blockContainers: false,
            blockIds: false
        });
        // Same trap as the test above, and the same reason it is defused here:
        // flattening the containers also removes the structural signal for "the
        // buyer says the addresses differ", which would no-op the mirror for the
        // WRONG reason and make every assertion below pass vacuously. So put the
        // live control in and assert the resolver is true FIRST.
        document.querySelector("input[name='saveAddress']").insertAdjacentHTML(
            'beforebegin',
            '<input name="use_same_address" type="checkbox" value="1">'
        );
        expect(mount(PICKED).buyerStatesInvoiceAddressDiffers()).toBe(true);

        mount(PICKED);

        expect($("input[name='company']").val()).toBe('');
        expect($("input[name='company']").attr(MARKER)).toBeUndefined();
        expect($("input[name='dni']").val()).toBe('');
        expect($("input[name='dni']").attr(MARKER)).toBeUndefined();
        expect($("select[name='id_country']").val()).toBe(ES_OPTION);
        expect($("select[name='id_country']").attr(MARKER)).toBeUndefined();
        expect($("input[name='companyid']").val()).toBe('');
        // And the other address is untouched - the radio still names the saved
        // address the buyer picked, with nothing written alongside it.
        expect($("input[name='id_address_delivery']").val()).toBe('7');
    });

    test('still resolves the wrapper when it is the page\'s ONLY address block', () => {
        // The guard is about a candidate SPANNING two addresses, not about the block
        // ids as such: a buyer with no saved addresses gets one form and no
        // selector, and there is then nothing a wide scope could reach. Asserted on
        // the resolver rather than through a mirrored write, because this flattened
        // shape also strips the structural signal the mirror reads for "the buyer
        // says the addresses differ" - a separate no-op, and not the one under test.
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION, blockContainers: false });
        document.querySelector('#delivery-addresses').remove();

        const root = mount(PICKED).visibleAddressFormRoot();

        expect(root).not.toBeNull();
        expect(root.className).toBe('js-address-form');
        expect(root.querySelectorAll("input[name='company']").length).toBe(1);
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

/**
 * TWO-40, adversarial review round 5, B1.
 *
 * The mirror used to write the identification field directly and never touch the
 * hidden `companyid` input or its `data-two-company-name` pairing tag. Those two
 * are the ENTIRE input to clearStaleOrganizationSelection(), which reads
 * `companyid` first and returns immediately when it is empty - so the "the buyer
 * retyped the company name over a selection" cleanup could never fire for a
 * mirrored value, and the mirrored company's organisation number shipped attached
 * to whatever name the buyer typed instead. That is a credit check on one company
 * under another company's name, which clearSelectedCompany()'s own docblock says
 * must never happen.
 *
 * Fixed by routing the mirror through the same browser-side publish path a real
 * selection uses (markOrganizationFieldSelected()), NOT by teaching the guard
 * about a second kind of selection.
 */
describe('a mirrored selection is a real selection, and the stale-selection guard can see it', () => {
    test('publishes the organisation number and its pairing tag, not just the address field', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });

        mount(PICKED);

        expect(organizationField().val()).toBe('12345678');
        expect(organizationField().attr('data-two-company-name')).toBe('Acme Trading Ltd');
    });

    test('publishes it on a country whose form has no identification field at all', () => {
        // The hidden field is not the address's identification field and does not
        // depend on the country's address format. Germany renders without a `dni`,
        // and the selection still has to be visible to the guard.
        buildAddressesStep({ editing: 'invoice' });

        mount(PICKED);

        expect(identifierField().length).toBe(0);
        expect(organizationField().val()).toBe('12345678');
        expect(organizationField().attr('data-two-company-name')).toBe('Acme Trading Ltd');
    });

    test('retyping the mirrored company name drops the mirrored organisation number', () => {
        // THE REPRO. Company A is picked on the shipping pass and mirrored onto the
        // invoice form; the buyer then types a different company name over it and
        // submits. Before the fix, A's organisation number went to the order
        // attached to the newly typed name.
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });

        mount(PICKED);
        expect(identifierField().val()).toBe('12345678');

        retypeCompanyName('Some Other Company Ltd');

        expect(organizationField().val()).toBe('');
        expect(organizationField().attr('data-two-company-name')).toBeUndefined();
        // And the identification field the submit-time sync would otherwise
        // re-adopt AS the organisation number goes with it.
        expect(identifierField().val()).toBe('');
    });

    test('clearing the mirrored name also drops the cart-scoped record, so the next page load does not re-fill it', () => {
        // The cross-page-load half of the same defect. The mirror's page-lifetime
        // memory dies with the document, so the ONLY thing stopping the next
        // address-step load from mirroring the same company back in is the server's
        // cart-scoped record being dropped - and that drop happens inside
        // clearSelectedCompany(), which the guard could not reach for a mirrored
        // value at all.
        window.twopayment.order_intent_url = 'https://shop.example.test/module/twopayment/orderintent';
        window.twopayment.ajax_token = 'test-token';
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });

        mount(PICKED);
        const before = ajax.calls.length;

        retypeCompanyName('');

        const cleared = ajax.calls.slice(before).filter(
            call => call.settings && call.settings.data && call.settings.data.action === 'clearCompany'
        );
        expect(cleared.length).toBe(1);
    });

    test('the mirrored pair survives being retyped back to the same name', () => {
        // The guard compares NAMES, so an edit that lands back on the mirrored name
        // is not a stale selection and must not clear one.
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });

        mount(PICKED);
        retypeCompanyName('Acme Trading Ltd');

        expect(organizationField().val()).toBe('12345678');
        expect(identifierField().val()).toBe('12345678');
    });

    test('re-publishes the pair core\'s rebuild destroyed, so the guard stays able to see it', () => {
        // The hidden field is inserted into `.js-address-form`, which core's
        // country-change rebuild replaces wholesale - and the mirror's OWN country
        // write is what triggers that rebuild, so this is the ordinary path. Core's
        // INPUT-only restore loop cannot restore a field the new render does not
        // emit, and init() builds a fresh empty one.
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const memory = {};

        mount(PICKED, { mirrorMemory: memory });
        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: MX_OPTION });

        // Not merely emptied - the hidden field is gone from the document
        // altogether, because the render that replaced it never emitted one.
        expect(organizationField().length).toBe(0);

        mount(PICKED, { mirrorMemory: memory });

        expect(organizationField().val()).toBe('12345678');
        expect(organizationField().attr('data-two-company-name')).toBe('Acme Trading Ltd');

        // ...and the guard works on the re-published pair exactly as on the first.
        retypeCompanyName('Some Other Company Ltd');

        expect(organizationField().val()).toBe('');
        expect(identifierField().val()).toBe('');
    });

    test('never re-attaches a number to a name the buyer has made their own', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const memory = {};

        mount(PICKED, { mirrorMemory: memory });
        retypeCompanyName('Renamed By The Buyer');
        expect(organizationField().val()).toBe('');

        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: MX_OPTION });
        mount(PICKED, { mirrorMemory: memory });

        expect(companyField().val()).toBe('Renamed By The Buyer');
        expect(organizationField().val()).toBe('');
        expect(organizationField().attr('data-two-company-name')).toBeUndefined();
    });
});

/**
 * TWO-40: the single organisation-number gate ANSWERS, and the mirror takes its
 * record from that answer.
 *
 * writeOrganizationToAddressIdentifiers() returns whether the value actually
 * reached a field. That return is not a convenience - it is the only thing that
 * stops the mirror recording a write that never happened, and a recorded write the
 * form does not hold is read as buyer tampering by the very next render, which
 * pins the WHOLE secondary address.
 *
 * Every refusal path is pinned separately, because they are four independent
 * conditions and a `return true` in any one of them produces the same defect.
 */
describe('the gate reports whether the number actually reached a field', () => {
    /**
     * A live instance with no selection to mirror, so the gate can be exercised
     * on its own rather than through a write the mirror had already performed.
     *
     * @param {Object} [extraConfig]
     * @returns {Object} `{search, root}`
     */
    function gateOn(extraConfig) {
        const search = mount(null, extraConfig);
        return { search: search, root: search.visibleAddressFormRoot() };
    }

    test('true when the value lands, which is the baseline everything else is a refusal of', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const { search, root } = gateOn();

        expect(search.writeOrganizationToAddressIdentifiers('12345678', false, root)).toBe(true);
        expect(identifierField().val()).toBe('12345678');
        expect(identifierField().attr(MARKER)).toBe('12345678');
    });

    test('false when the merchant has address population switched off', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const { search, root } = gateOn({ addressLookupEnabled: false });

        expect(search.writeOrganizationToAddressIdentifiers('12345678', false, root)).toBe(false);
        expect(identifierField().val()).toBe('');
        expect(identifierField().attr(MARKER)).toBeUndefined();
    });

    test('false for an empty value', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const { search, root } = gateOn();

        // And for one that is only whitespace, which trims to the same thing.
        expect(search.writeOrganizationToAddressIdentifiers('', false, root)).toBe(false);
        expect(search.writeOrganizationToAddressIdentifiers('   ', false, root)).toBe(false);
        expect(identifierField().val()).toBe('');
    });

    /**
     * TWO-40, Doug's ruling, Option A. An internal (`TWO:`) identifier is NEVER
     * written into the visible `dni` field, and the gate answers FALSE for it.
     *
     * `false` is load-bearing rather than incidental: the mirror below takes
     * `wroteNumber` from this answer, and recording a write that did not happen has
     * the next render read the empty field as buyer tampering and pin the whole
     * secondary address.
     *
     * A round that wrote the value there was tried and reversed. Core declares `dni`
     * with `isDniLite` (`/^[0-9A-Za-z-.]{1,16}$/U`) at size 16, which
     * `TWO:ST123456789012` fails twice - the colon is not in the class and it is 18
     * characters - so core REFUSED TO SAVE THE ADDRESS, with the error landing on a
     * field that round was hiding. It was also unreadable: this plugin's own
     * extractOrgNumberFromAddress() validates `dni` against `/^[A-Z0-9\-]{5,20}$/i`,
     * which rejects the colon too.
     *
     * The inverse of the test that used to stand here: re-introducing the write fails
     * this.
     */
    test('FALSE for an internal `TWO:` identifier - it never reaches the visible field', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION, formGroups: true });
        const { search, root } = gateOn();

        expect(search.writeOrganizationToAddressIdentifiers('TWO:ST123456789012', false, root)).toBe(false);
        expect(identifierField().val()).toBe('');
        // Not claimed either: a marker on an empty field would let the clear path
        // believe it had work to undo.
        expect(identifierField().attr(MARKER)).toBeUndefined();
        // And the field stays VISIBLE. There is no hiding rule any more - nothing
        // internal reaches the field, whatever is in it belongs to the buyer, and a
        // checkout-only hide could never have covered the address blocks, invoice
        // PDFs and order emails core renders `dni` into.
        const group = identifierField().closest('.form-group');
        expect(group.length).toBe(1);
        expect(group.get(0).style.display).toBe('');
    });

    test('false when every candidate field was skipped by onlyIfEmpty', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        identifierField().val('BUYER-OWN-ID');
        const { search, root } = gateOn();

        expect(search.writeOrganizationToAddressIdentifiers('12345678', true, root)).toBe(false);
        // The buyer's own answer stands, and is not claimed as ours.
        expect(identifierField().val()).toBe('BUYER-OWN-ID');
        expect(identifierField().attr(MARKER)).toBeUndefined();
    });

    test('false when the country\'s address format carries no identification field at all', () => {
        // Germany, the default render: nowhere to put a number, so nothing landed.
        buildAddressesStep({ editing: 'invoice' });
        const { search, root } = gateOn();

        expect(identifierField().length).toBe(0);
        expect(search.writeOrganizationToAddressIdentifiers('12345678', false, root)).toBe(false);
    });
});

/**
 * TWO-40: the record follows the WRITE, not the attempt.
 *
 * The mirror used to set `memory.organization` and report the `organization` half
 * of the record from having CALLED the writer. A recorded write the form does not
 * hold is read as buyer tampering by the very next render, which pins the WHOLE
 * secondary address - so the record has to be taken from the writer's answer.
 *
 * An internal (`TWO:`) identifier is ONE of the ways that answer can be no (TWO-40,
 * Option A): it never enters the visible `dni` field. So it is the sharpest case for
 * this rule - the write is declined, and nothing may be recorded as written. The
 * other refusals - lookup off, empty value, no field, every field skipped - are
 * pinned in the describe above.
 */
describe('the record follows the write, not the attempt', () => {
    const INTERNAL = { company: 'Sole Trader Test Co', companyid: 'TWO:ST123456789012', countryIso: 'ES' };
    const REAL = { company: 'Acme SA', companyid: '12345678', countryIso: 'ES' };

    test('an internal `TWO:` number is NOT claimed by the record, because it was not written', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const memory = {};

        mount(INTERNAL, { mirrorMemory: memory });

        // The name still travels - only `dni` is skipped.
        expect(companyField().val()).toBe('Sole Trader Test Co');
        expect(identifierField().length).toBe(1);
        expect(identifierField().val()).toBe('');

        // Nothing recorded as written. A record claiming a number this empty field does
        // not hold is precisely what the next render reads as buyer tampering, pinning
        // the whole secondary address.
        expect(memory.organization).toBe('');
        expect(window.twopayment.mirror_writes.company).toBe('Sole Trader Test Co');
        expect(window.twopayment.mirror_writes.organization).toBe('');
        // The number is not LOST, though - it is owed, which is what keeps the hidden
        // pair restorable across core's rebuilds.
        expect(memory.organizationPending).toBe('TWO:ST123456789012');
        // And the pair itself is published, uniformly with any other selection.
        expect(organizationField().val()).toBe('TWO:ST123456789012');
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');
    });

    test('a REAL number is recorded the same way, so the two are provably not on different paths', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const memory = {};

        mount(REAL, { mirrorMemory: memory });

        expect(identifierField().val()).toBe('12345678');
        expect(memory.organization).toBe('12345678');
        expect(window.twopayment.mirror_writes.organization).toBe('12345678');
    });

    test('a write the gate genuinely refuses is still not claimed', () => {
        // Address population off: the one refusal that leaves the name behind, because
        // the name write is not an address-lookup write.
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const memory = {};
        const search = mount(REAL, { mirrorMemory: memory, addressLookupEnabled: false });
        const root = search.visibleAddressFormRoot();

        expect(search.writeOrganizationToAddressIdentifiers(REAL.companyid, false, root)).toBe(false);
        expect(identifierField().val()).toBe('');
        expect(identifierField().attr(MARKER)).toBeUndefined();
    });
});

/**
 * TWO-40: `organizationPending` is a DEBT - "there is nowhere to put this number
 * yet" - and the mirror's own country write can rebuild the form into one that DOES
 * have an identification field, which is what makes the debt worth recording.
 *
 * Since TWO-40 Option A the debt has a SECOND job, and it is why the condition is
 * keyed on `!wroteNumber` rather than on "the form has no identification field". An
 * internal (`TWO:`) identifier is never written into the visible `dni` field, so on a
 * form that HAS that field the number still lands nowhere - and `organizationPending`
 * is then the only surviving record of it. republishMirroredSelection() reads
 * `organization || organizationPending` to restore the hidden `companyid` pair after
 * core's country-change rebuild, so leaving both halves empty for a sole trader would
 * take that restore away entirely.
 */
describe('a number with nowhere to go is owed, whatever shape it is', () => {
    test('a real number with nowhere to go is owed', () => {
        // Germany: the address format carries no identification field.
        buildAddressesStep({ editing: 'invoice' });
        const memory = {};

        mount({ company: 'Acme Trading Ltd', companyid: '12345678', countryIso: 'GB' },
            { mirrorMemory: memory });

        expect(identifierField().length).toBe(0);
        expect(companyField().val()).toBe('Acme Trading Ltd');
        expect(memory.organizationPending).toBe('12345678');
    });

    test('an internal `TWO:` number on the same form is owed exactly as a real one is', () => {
        buildAddressesStep({ editing: 'invoice' });
        const memory = {};

        mount({ company: 'Sole Trader Test Co', companyid: 'TWO:ST123456789012', countryIso: 'GB' },
            { mirrorMemory: memory });

        // Same form, same "nowhere to put it" shape, same name write - and now the same
        // debt, because the gate has no verdict to give on the number's shape.
        expect(identifierField().length).toBe(0);
        expect(companyField().val()).toBe('Sole Trader Test Co');
        expect(memory.organizationPending).toBe('TWO:ST123456789012');
    });

    /**
     * THE SKIP PATH, and the case the old `identifierFields.length === 0` condition
     * got wrong. The form HAS an identification field; the number is internal, so the
     * write skips it and answers false. Both halves of the memory would be empty
     * without this, and republishMirroredSelection() would have nothing to restore
     * the pair from.
     */
    test('an internal number is owed on a form that DOES have the field, because it was skipped', () => {
        // Spain: the address format carries the identification field.
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const memory = {};

        mount({ company: 'Sole Trader Test Co', companyid: 'TWO:ST123456789012', countryIso: 'ES' },
            { mirrorMemory: memory });

        // The premise: the field exists and stayed empty.
        expect(identifierField().length).toBe(1);
        expect(identifierField().val()).toBe('');
        expect(companyField().val()).toBe('Sole Trader Test Co');
        // Nothing recorded as WRITTEN - the record follows the write.
        expect(memory.organization).toBe('');
        // But the number is not lost.
        expect(memory.organizationPending).toBe('TWO:ST123456789012');
    });

    /**
     * What that debt buys: the pair is still restorable after core's rebuild has taken
     * the hidden `companyid` with it. This is the assertion that fails if
     * `organizationPending` goes back to being keyed on the field's absence.
     */
    test('and republishMirroredSelection() restores the pair from that debt alone', () => {
        buildAddressesStep({ editing: 'invoice', countryId: ES_OPTION });
        const memory = {};
        const selection = { company: 'Sole Trader Test Co', companyid: 'TWO:ST123456789012', countryIso: 'ES' };

        mount(selection, { mirrorMemory: memory });
        expect(memory.organization).toBe('');
        expect(memory.organizationPending).toBe('TWO:ST123456789012');

        // Core rebuilds the address form; the hidden pairing field goes with it, and a
        // fresh instance mounts over the new markup.
        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: ES_OPTION });
        const search = mount(selection, { mirrorMemory: memory });
        // The name is back, marked, from the mirror's own record.
        expect(companyField().val()).toBe('Sole Trader Test Co');

        expect(search.republishMirroredSelection()).toBe(true);

        expect(organizationField().val()).toBe('TWO:ST123456789012');
        expect(organizationField().attr('data-two-company-name')).toBe('Sole Trader Test Co');
    });

    /**
     * The debt discharged on the very form the mirror's country write produces: a
     * country whose address format DOES carry an identification field. A REAL number,
     * because that is the only shape `dni` ever takes now.
     */
    test('and a rebuild into a country that has the field discharges a real number\'s debt', () => {
        buildAddressesStep({ editing: 'invoice' });
        const memory = {};
        const selection = { company: 'Acme Trading Ltd', companyid: '12345678', countryIso: 'GB' };

        mount(selection, { mirrorMemory: memory });
        expect(memory.organizationPending).toBe('12345678');

        rebuildAddressesStepAsCoreDoes({ editing: 'invoice', countryId: ES_OPTION });
        mount(selection, { mirrorMemory: memory });

        expect(identifierField().length).toBe(1);
        expect(identifierField().val()).toBe('12345678');
        expect(memory.organization).toBe('12345678');
        expect(memory.organizationPending).toBe('');
    });
});

/**
 * TWO-40: the completion takes its answer from the writer too - it records what was
 * actually placed - and a gate refusal settles the debt rather than leaving it
 * owing.
 */
describe('the completion records what the writer actually placed', () => {
    /**
     * The state the completion acts on: a name the mirror wrote and marked, and a
     * number it has not been able to place anywhere. Reached through the injected
     * page-lifetime memory, which is where this state genuinely lives - the manager
     * owns it precisely because it outlives the search instance.
     *
     * The memory is filled AFTER the mount deliberately: the completion also runs
     * from init(), so a memory handed to the constructor would already have been
     * consumed by the time the explicit call below is made, and the assertions would
     * be reading a settled debt rather than the refusal under test.
     *
     * @param {string} pending
     * @param {Object} [options] `formGroups` builds the fixture with core's own
     *        `.form-group` wrappers (needed for anything about visibility); every
     *        other key is passed to the instance's config.
     * @returns {Object} `{search, memory}`
     */
    function withPendingNumber(pending, options) {
        const opts = options || {};
        const config = Object.assign({}, opts);
        delete config.formGroups;
        buildAddressesStep({
            editing: 'invoice',
            countryId: ES_OPTION,
            formGroups: opts.formGroups === true
        });
        const memory = {};
        const search = mount(null, Object.assign({ mirrorMemory: memory }, config));
        memory.companyid = pending;
        memory.company = 'Sole Trader Test Co';
        memory.organization = '';
        memory.organizationPending = pending;
        companyField().val('Sole Trader Test Co');
        companyField().attr(MARKER, 'Sole Trader Test Co');

        return { search: search, memory: memory };
    }

    /**
     * TWO-40, Doug's ruling, Option A. An internal (`TWO:`) pending number publishes
     * THE PAIR - the hidden `companyid` and its `data-two-company-name` tag - exactly
     * as a real one does, and leaves the visible `dni` field alone.
     *
     * The pair is the whole point. An untagged or absent `companyid` is what
     * clearStaleOrganizationSelection() wipes on the buyer's next keystroke, and that
     * single mechanism killed three previous attempts at this write-back. Only the
     * visible field differs.
     */
    test('an internal `TWO:` pending number publishes the pair and leaves `dni` alone', () => {
        const { search, memory } = withPendingNumber('TWO:ST123456789012', { formGroups: true });
        const root = search.visibleAddressFormRoot();
        // The premise: an empty, unmarked identification field is present.
        expect(identifierField().length).toBe(1);
        expect(identifierField().val()).toBe('');

        // False: nothing landed in the visible field, and the answer follows the
        // write rather than the attempt.
        expect(search.completeMirroredOrganizationNumber(root)).toBe(false);

        // The visible field is untouched, unclaimed and still visible.
        expect(identifierField().val()).toBe('');
        expect(identifierField().attr(MARKER)).toBeUndefined();
        expect(identifierField().closest('.form-group').get(0).style.display).toBe('');
        // The pair IS published - uniform with any other organisation number.
        const organizationField = $("input[name='companyid']");
        expect(organizationField.val()).toBe('TWO:ST123456789012');
        expect(organizationField.attr('data-two-company-name')).toBe('Sole Trader Test Co');
        // Nothing recorded as written to the secondary address, because nothing was.
        expect(memory.organization).toBe('');
        // Still OWING, deliberately: `organizationPending` is now the only surviving
        // record of the number, and republishMirroredSelection() reads it to restore
        // the pair after the NEXT rebuild too. Clearing it would work exactly once.
        expect(memory.organizationPending).toBe('TWO:ST123456789012');
    });

    /**
     * The other refusal branch, reached by the merchant switch rather than by the
     * number's shape. Same treatment: the debt stays owing so the pair survives.
     */
    test('a gate refusal writes nothing and leaves the debt owing', () => {
        const { search, memory } = withPendingNumber('12345678', { addressLookupEnabled: false });
        const root = search.visibleAddressFormRoot();

        expect(search.completeMirroredOrganizationNumber(root)).toBe(false);

        expect(identifierField().val()).toBe('');
        expect(identifierField().attr(MARKER)).toBeUndefined();
        expect(memory.organization).toBe('');
        expect(memory.organizationPending).toBe('12345678');
    });

    test('a real pending number completes too, so neither result above is an accident', () => {
        const { search, memory } = withPendingNumber('12345678');
        const root = search.visibleAddressFormRoot();

        expect(search.completeMirroredOrganizationNumber(root)).toBe(true);

        expect(identifierField().val()).toBe('12345678');
        expect(memory.organization).toBe('12345678');
        expect(memory.organizationPending).toBe('');
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
