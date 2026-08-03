/**
 * TWO-25288. The read-only company summary in the payment tile.
 *
 * Before this, the payment tile showed the buyer nothing about which company was
 * about to be credit-checked: the name lived in the address step's `company`
 * input, two steps back, and the organisation number was only ever carried in a
 * hidden input. This suite pins the three capture modes onto the two visible
 * slots, and pins that the display remains a display - not editable, and not a
 * second writer of the field that actually submits.
 *
 * The tile markup comes from the shipped `paymentinfo.tpl` via the harness, not
 * from a fixture in this file, so renaming a class or dropping a slot in the
 * real template fails here.
 */

'use strict';

const {
    loadCompanySearch,
    loadCompanySummary,
    buildAddressForm,
    buildPaymentTile,
    installStylesheet,
    loadScript,
    stubAjax,
    releaseWidgets,
    flushPromises,
    panelParts,
    openPanel
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

const SEARCH_RESPONSE = {
    items: [{ name: 'Example Trading Ltd', national_identifier: { id: '12345678' } }]
};

let TwoCompanySearch;
let TwoCompanySummary;
let summary;
let $;
let bus;
let ajax;

beforeEach(() => {
    buildAddressForm({ country: 'GB' });
    buildPaymentTile();
    // The REAL stylesheet, because TWO-25326 §7 puts half of this block's
    // behaviour in CSS: the number and its parentheses are hidden as a unit
    // unless render() has put `two-company-summary--has-number` on the root.
    // Without the cascade loaded, a suite asserting on the rendered label would
    // read the parentheses of a manual-entry buyer as visible and pass on the
    // exact defect §7 exists to prevent.
    installStylesheet('views/css/two.css');
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    bus = loaded.bus;
    ajax = stubAjax($);
    TwoCompanySummary = loadCompanySummary();
    // The label only renders while the order-intent message is on screen
    // (TWO-25326 §7, revised). That is a precondition for every test below that
    // asserts on CONTENT - which slot holds what - so it is established once
    // here rather than restated in each, and the coupling itself is pinned
    // separately in "the label rides on the intent message's visibility".
    //
    // Done BEFORE the instance is constructed, so init()'s own render() already
    // sees it and the tests observe one consistent state rather than a block
    // that was briefly hidden for reasons unrelated to what they assert.
    showIntentMessage();
    // Constructed exactly as views/js/twopayment.js does. Load-bearing rather
    // than setup noise: the document-level input listener that catches a
    // hand-typed company name belongs to the INSTANCE, so a suite that only
    // loaded the class would find the whole manual-entry path dead and pass
    // anyway on the paths that call render() directly.
    summary = new TwoCompanySummary();
});

afterEach(() => {
    summary.cleanup();
    releaseWidgets($);
    ajax.restore();
    document.body.innerHTML = '';
    // The stylesheet is re-injected per test; without this the head accumulates
    // one copy per test in the file.
    document.head.innerHTML = '';
});

function makeInstance(extraConfig) {
    return new TwoCompanySearch(
        Object.assign({ checkoutHost: CHECKOUT_HOST }, extraConfig || {})
    );
}

/**
 * Put the order-intent message on screen, the way TwoCheckoutManager does.
 *
 * `.two-payment-info` is the section the shipped template carries, hidden inline
 * (`style="display: none"`) until an intent decision arrives - and it is the
 * container that actually renders on PrestaShop, so it is the one the label's
 * gate observes.
 *
 * BOTH halves of the real mechanism, because the stylesheet makes them both
 * load-bearing: TwoCheckoutManager sets `display: block` AND adds `.show`, and
 * `.two-payment-info` is `opacity: 0` until that class takes it to 1. Setting
 * only the display would leave the section fully transparent - a state a buyer
 * cannot read, so a suite that called it "shown" would be asserting against
 * something invisible.
 *
 * @returns {Element} the message section
 */
function showIntentMessage() {
    const info = document.querySelector('.two-payment-info');
    if (!info) {
        throw new Error('the tile template carries no .two-payment-info section');
    }
    info.style.display = 'block';
    info.classList.add('show');
    return info;
}

/** Take it back down, the way the notice-off branch does - display and class. */
function hideIntentMessage() {
    const info = document.querySelector('.two-payment-info');
    if (!info) {
        throw new Error('the tile template carries no .two-payment-info section');
    }
    info.style.display = 'none';
    info.classList.remove('show');
    return info;
}

/** The block under test. */
function root() {
    return document.querySelector('.two-company-summary');
}

/** @returns {?Element} */
function slot(which) {
    return root().querySelector('[data-two-company-summary="' + which + '"]');
}

/** What the buyer can actually read off the tile. */
function shown() {
    return {
        name: slot('name').textContent,
        number: slot('number').textContent,
        hidden: root().style.display === 'none'
    };
}

/**
 * The one line of text the tile actually renders (TWO-25326 §7).
 *
 * Read off the ROOT rather than assembled from the two slots, because the
 * parentheses and the space between name and number are template text owned by
 * neither slot - and hiding them with the number is the §7 behaviour that
 * distinguishes "Example Ltd" from "Example Ltd ()".
 *
 * `.two-company-summary__number-wrap` is hidden by the stylesheet unless the
 * root carries `two-company-summary--has-number`, and jsdom resolves that
 * cascade but does NOT exclude `display:none` subtrees from `textContent`. So
 * the hidden wrapper is dropped explicitly here, which is what makes this read
 * what a buyer sees rather than what the DOM holds.
 *
 * @returns {string}
 */
function label() {
    const text = Array.prototype.map.call(root().childNodes, (node) => {
        if (node.nodeType === 1 && window.getComputedStyle(node).display === 'none') {
            return '';
        }
        return node.textContent;
    }).join('');
    // The template's own indentation lands in the block as text nodes. A
    // browser collapses that run of whitespace to nothing at the edges and to a
    // single space between words; jsdom does no layout, so do it here rather
    // than let template indentation decide whether this test passes.
    return text.replace(/\s+/g, ' ').trim();
}

/**
 * Search, settle, then pick the first row the way a buyer's click does.
 *
 * TWO-25326 §1 moved the widget off `input[name='company']` and onto the
 * anchored panel's query field, so the search is driven from THERE now and the
 * company-name field only receives the picked name. Driven through the widget's
 * own menu rather than by calling onCompanySelected() directly, so an unwired
 * `select` option would fail these tests - and NOT by dispatching Down/Enter
 * keydowns, because jQuery UI's `_move` gates on `:visible`, which jsdom
 * performs no layout for and can never satisfy.
 */
function selectFirstResult(term, response) {
    const field = $("input[name='company']");
    openPanel();
    const query = panelParts().query;
    const instance = query.autocomplete('instance');
    query.val(term);
    instance.search(term);
    ajax.last().succeed(response);
    const row = instance.menu.element.children('li').first();
    instance.menu.focus(null, row);
    instance.menu.select($.Event('click'));
    return field;
}

/** Type into the company field the way a buyer does, events and all. */
function typeCompanyName(value) {
    const field = document.querySelector("input[name='company']");
    field.value = value;
    field.dispatchEvent(new window.Event('input', { bubbles: true }));
    return field;
}

describe('the tile template carries the summary block', () => {
    test('both slots exist and start empty and hidden', () => {
        // The shipped template, not a fixture: the harness strips Smarty from
        // views/templates/hook/paymentinfo.tpl.
        expect(root()).not.toBeNull();
        expect(slot('name')).not.toBeNull();
        expect(slot('number')).not.toBeNull();
        expect(shown()).toEqual({ name: '', number: '', hidden: true });
    });

    test('it declares no second companyid input', () => {
        // A duplicate `name="companyid"` inside the tile would sit OUTSIDE the
        // address form and would make createOrganizationField() adopt the wrong
        // node, so the number would stop submitting. The tile must contain none.
        expect(root().querySelectorAll("[name='companyid']")).toHaveLength(0);
        expect(
            document.querySelectorAll(".two-payment-container [name='companyid']")
        ).toHaveLength(0);
    });
});

describe('search mode shows the name and the number', () => {
    test('a selection paints both slots', () => {
        makeInstance();

        selectFirstResult('exa', SEARCH_RESPONSE);

        expect(shown()).toEqual({
            name: 'Example Trading Ltd',
            number: '12345678',
            hidden: false
        });
    });

    test('the tile reads as one label, "<name> (<number>)"', () => {
        // TWO-25326 §7, replacing the two-row label/value block this used to
        // render. Asserted on the rendered text of the whole block rather than
        // on the two slots separately, because the parentheses and the space
        // belong to neither slot - and they are exactly what a slot-only
        // assertion would let a template edit drop silently.
        makeInstance();

        selectFirstResult('exa', SEARCH_RESPONSE);

        expect(label()).toBe('Example Trading Ltd (12345678)');
        expect(root().classList.contains('two-company-summary--has-number')).toBe(true);
        // No label spans: §7 removed the "Company name:" / "Company number:"
        // captions along with the rows that carried them.
        expect(root().querySelectorAll('.two-company-summary__label')).toHaveLength(0);
    });

    test('a number that only arrives with the details repaints the slot', async () => {
        makeInstance();

        selectFirstResult('exa', {
            items: [{ name: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }]
        });

        // The GB shape: nothing to show in the number slot yet, and the name
        // alone is already worth showing.
        expect(shown()).toEqual({
            name: 'Example Trading Ltd',
            number: '',
            hidden: false
        });

        ajax.last().succeed({ national_identifier: { id: '87654321' } });
        await flushPromises();

        expect(shown()).toEqual({
            name: 'Example Trading Ltd',
            number: '87654321',
            hidden: false
        });
    });

    test('a number tagged to a different company is not shown beside a new name', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);

        // Retyping over a selection disowns it. Showing the old number under the
        // new name would assert a pairing that does not exist.
        typeCompanyName('Someone Else Ltd');

        expect(shown()).toEqual({
            name: 'Someone Else Ltd',
            number: '',
            hidden: false
        });
    });
});

describe('manual entry shows the name with a blank number', () => {
    test('the typed name renders and the number slot stays blank', () => {
        const search = makeInstance();

        search.enterManualEntryMode();
        typeCompanyName('Unlisted Trading Ltd');

        expect(shown()).toEqual({
            name: 'Unlisted Trading Ltd',
            number: '',
            hidden: false
        });
    });

    test('the blank number slot is present in the DOM, not removed', () => {
        const search = makeInstance();
        search.enterManualEntryMode();
        typeCompanyName('Unlisted Trading Ltd');

        // Blank, not absent: a slot that disappears reads as a rendering fault
        // rather than as "this buyer has no number". It is HIDDEN rather than
        // emptied-in-place now (see the label test below) - but the element
        // itself still has to be there for the next render to paint into.
        expect(slot('number')).not.toBeNull();
        expect(root().contains(slot('number'))).toBe(true);
    });

    test('the label reads as the name alone - no empty parentheses', () => {
        // TWO-25326 §7. The number and its brackets are one unit, hidden
        // together: a manual-entry buyer supplies a name and no number (§5), and
        // "Unlisted Trading Ltd ()" reads as a rendering fault. The state class
        // is what governs it, so both the class and the resulting text are
        // pinned - the class alone would pass against a stylesheet that had
        // stopped acting on it.
        const search = makeInstance();
        search.enterManualEntryMode();
        typeCompanyName('Unlisted Trading Ltd');

        expect(root().classList.contains('two-company-summary--has-number')).toBe(false);
        expect(label()).toBe('Unlisted Trading Ltd');
        expect(label()).not.toContain('(');
    });

    test('choosing manual entry drops the number a previous selection showed', () => {
        const search = makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);
        expect(shown().number).toBe('12345678');

        search.enterManualEntryMode();

        // enterManualEntryMode() forgets the selected company; the tile must
        // stop advertising its number in the same breath.
        expect(shown().number).toBe('');
    });
});

describe('sole trader shows the enrolled pair', () => {
    test('the pushed pair paints both slots', () => {
        // Pushed rather than read from the DOM: that flow writes neither the
        // `company` input nor the hidden `companyid` one.
        TwoCompanySummary.setSoleTrader({ name: 'Sole Trader AS', number: 'NO-999888777' });

        expect(shown()).toEqual({
            name: 'Sole Trader AS',
            number: 'NO-999888777',
            hidden: false
        });
    });

    test('a sole trader with no company name still shows their number', () => {
        // Sole traders often trade under their own name, so a blank company
        // name is data rather than an error.
        TwoCompanySummary.setSoleTrader({ name: '', number: 'NO-999888777' });

        expect(shown()).toEqual({ name: '', number: 'NO-999888777', hidden: false });
    });

    test('the pair outlives a re-render of the tile', () => {
        TwoCompanySummary.setSoleTrader({ name: 'Sole Trader AS', number: 'NO-999888777' });

        // PrestaShop re-renders the payment step wholesale on a cart change.
        root().remove();
        buildPaymentTile();
        TwoCompanySummary.render();

        expect(shown()).toEqual({
            name: 'Sole Trader AS',
            number: 'NO-999888777',
            hidden: false
        });
    });
});

describe('a retype-then-submit does not resurrect a false pairing (PR two-inc/prestashop-plugin#122 interaction)', () => {
    // #122's own body documents this as a residual: retyping over a selection
    // clears `companyid` and its tag but leaves the lookup-written, MARKED
    // `dni` field behind. The pre-submit sync (`syncOrganizationToAddressIdentifiers`)
    // then adopts that leftover `dni` as the org number for whatever name is
    // now in the field. #122 only cared that the number reached the POST;
    // this tile now also has to not show it as a CONFIRMED pairing.
    test('the resurrected number does not get shown beside the retyped name', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);
        expect($("input[name='dni']").val()).toBe('12345678');

        // Retype over the selection: disowns companyid/tag, leaves dni alone.
        typeCompanyName('Someone Else Ltd');
        expect($("input[name='companyid']").val()).toBe('');
        expect($("input[name='dni']").val()).toBe('12345678');
        expect(shown()).toEqual({ name: 'Someone Else Ltd', number: '', hidden: false });

        // Address form submits; the pre-submit sync fills the empty companyid
        // back in from the leftover dni.
        $('form').triggerHandler('submit');
        expect($("input[name='companyid']").val()).toBe('12345678');

        // The number must still not be shown paired with the retyped name -
        // it was never confirmed against it.
        TwoCompanySummary.render();
        expect(shown()).toEqual({ name: 'Someone Else Ltd', number: '', hidden: false });
    });
});

describe('it survives PrestaShop re-rendering the payment step', () => {
    test('a cart update repaints the replacement block', async () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);

        // PrestaShop replaces the whole payment step's DOM on a cart change, so
        // the block that was painted is gone and its replacement starts empty.
        root().remove();
        buildPaymentTile();
        expect(shown()).toEqual({ name: '', number: '', hidden: true });

        bus.emit('updatedCart');
        // Deferred a macrotask, because the render has to land AFTER PrestaShop's
        // own handlers have finished swapping the DOM, not during.
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(shown()).toEqual({
            name: 'Example Trading Ltd',
            number: '12345678',
            hidden: false
        });
    });

    test('the repaint is deferred, not synchronous with the event', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);
        root().remove();
        buildPaymentTile();

        bus.emit('updatedCart');

        // Synchronous here would paint DURING PrestaShop's own handling of the
        // event - i.e. into the block it is about to replace - which is the whole
        // reason for the setTimeout. Still empty immediately after the emit is
        // what proves the deferral exists.
        expect(shown()).toEqual({ name: '', number: '', hidden: true });
    });

    test('a second instance does not stack bus handlers', () => {
        // The bus has no `off`, so a handler registered on it can never be taken
        // back and cleanup() cannot remove one. Registering per instance would
        // leak silently, since only one instance is built today.
        const extra = new TwoCompanySummary();
        const third = new TwoCompanySummary();

        expect(bus.handlerCount('updatedCart')).toBe(1);

        extra.cleanup();
        third.cleanup();
    });

    test('cleanup stops the instance listening', () => {
        makeInstance();
        summary.cleanup();

        typeCompanyName('Typed After Cleanup Ltd');

        expect(shown()).toEqual({ name: '', number: '', hidden: true });
        // afterEach calls cleanup() again; it has to be idempotent.
        expect(() => summary.cleanup()).not.toThrow();
    });
});

describe('a DOM-captured company outranks a sole-trader enrolment', () => {
    test('switching back to registered business and searching replaces the pair', () => {
        makeInstance();
        TwoCompanySummary.setSoleTrader({ name: 'Sole Trader AS', number: 'NO-999888777' });

        selectFirstResult('exa', SEARCH_RESPONSE);

        // The company that will actually be credit-checked is the one in the
        // form. A sole-trader pair consulted first outranked it for the rest of
        // the page, so the tile named one company while the order carried
        // another.
        expect(shown()).toEqual({
            name: 'Example Trading Ltd',
            number: '12345678',
            hidden: false
        });
    });

    test('a typed name alone still outranks it', () => {
        makeInstance();
        TwoCompanySummary.setSoleTrader({ name: 'Sole Trader AS', number: 'NO-999888777' });

        typeCompanyName('Manually Typed Ltd');

        expect(shown()).toEqual({
            name: 'Manually Typed Ltd',
            number: '',
            hidden: false
        });
    });

    test('the sole-trader toggle returning to business forgets the pair', () => {
        // TwoSoleTrader.setMode('business') is the switch the buyer clicks; the
        // ordering above is the backstop for a path that forgets to call it.
        loadScript('views/js/modules/TwoSoleTrader.js');
        const soleTrader = Object.create(window.TwoSoleTrader.prototype);
        soleTrader.mode = 'sole_trader';
        soleTrader.updateChips = () => {};
        soleTrader.hidePrompt = () => {};

        TwoCompanySummary.setSoleTrader({ name: 'Sole Trader AS', number: 'NO-999888777' });
        expect(shown().number).toBe('NO-999888777');

        soleTrader.setMode('business');

        expect(TwoCompanySummary._soleTrader).toBeNull();
        expect(shown()).toEqual({ name: '', number: '', hidden: true });
    });
});

describe('a country change stops the tile advertising the old company', () => {
    test('the summary clears with the fields the listener clears', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);
        expect(shown().number).toBe('12345678');

        const country = document.querySelector("select[name='id_country']");
        country.dispatchEvent(new window.Event('change', { bubbles: true }));

        // The listener blanks company + companyid through `.val()`, which fires
        // no event, so nothing else here could observe it. The form is empty and
        // the tile has to be too.
        expect(document.querySelector("input[name='company']").value).toBe('');
        expect(shown()).toEqual({ name: '', number: '', hidden: true });
    });
});

describe('the values are written as text, never as markup', () => {
    test('a company name shaped like a tag renders as characters', () => {
        makeInstance();

        // The name comes from a third-party register and from the buyer's own
        // keyboard. `innerHTML` here would be an injection point on both.
        selectFirstResult('exa', {
            items: [
                {
                    name: '<img src=x onerror="window.__twoXss = true">Example',
                    national_identifier: { id: '12345678' }
                }
            ]
        });

        expect(root().querySelectorAll('img')).toHaveLength(0);
        expect(window.__twoXss).toBeUndefined();
        expect(slot('name').textContent).toBe('<img src=x onerror="window.__twoXss = true">Example');
        expect(slot('name').children).toHaveLength(0);
    });

    test('a typed name shaped like a tag renders as characters', () => {
        makeInstance();

        typeCompanyName('<b>Bold Ltd</b>');

        expect(root().querySelectorAll('b')).toHaveLength(0);
        expect(slot('name').textContent).toBe('<b>Bold Ltd</b>');
    });
});

describe('nothing captured shows nothing', () => {
    test('an empty company field leaves the block hidden', () => {
        makeInstance();
        TwoCompanySummary.render();

        expect(shown()).toEqual({ name: '', number: '', hidden: true });
    });

    test('a missing block is not an error', () => {
        makeInstance();
        root().remove();

        // The module ships on the address step too, where the tile does not
        // exist yet, and TwoCompanySearch calls into it on every capture change.
        expect(() => TwoCompanySummary.render()).not.toThrow();
        expect(() => selectFirstResult('exa', SEARCH_RESPONSE)).not.toThrow();
        expect($("input[name='companyid']").val()).toBe('12345678');
    });
});

describe('the display is read-only', () => {
    test('the block contains no editable element at all', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);

        expect(root().querySelectorAll('input, select, textarea, button')).toHaveLength(0);
        expect(root().querySelectorAll('[contenteditable]')).toHaveLength(0);
    });

    test('the slots are spans, so there is nothing to type into or clear', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);

        expect(slot('name').tagName).toBe('SPAN');
        expect(slot('number').tagName).toBe('SPAN');
        // A span has no value to submit and no form to submit it to.
        expect(slot('name').getAttribute('name')).toBeNull();
        expect(slot('number').getAttribute('name')).toBeNull();
    });

    test('no part of the block sits inside a form', () => {
        // Belt and braces on the above: an element inside the payment form could
        // submit even without being an input, via a name on a fieldset control.
        expect(root().closest('form')).toBeNull();
    });
});

describe('the hidden companyid input is untouched', () => {
    test('the selection still lands in it, in the address form', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);

        const org = document.querySelector("input[name='companyid']");
        expect(org).not.toBeNull();
        expect(org.type).toBe('hidden');
        expect(org.value).toBe('12345678');
        expect(org.getAttribute('data-two-company-name')).toBe('Example Trading Ltd');
        // Inside the form is what makes it submit; the summary lives outside it.
        expect(org.closest('form')).not.toBeNull();
        expect(org.closest('.two-payment-container')).toBeNull();
        // Exactly one, so nothing has been shadowed or duplicated.
        expect(document.querySelectorAll("input[name='companyid']")).toHaveLength(1);
    });

    test('rendering the summary repeatedly never writes to it', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);
        const org = document.querySelector("input[name='companyid']");

        for (let i = 0; i < 25; i += 1) {
            TwoCompanySummary.render();
        }

        expect(org.value).toBe('12345678');
        expect(org.getAttribute('data-two-company-name')).toBe('Example Trading Ltd');
        expect(document.querySelector("input[name='companyid']")).toBe(org);
    });

    test('a sole-trader push does not invent a companyid in the DOM', () => {
        makeInstance();
        TwoCompanySummary.setSoleTrader({ name: 'Sole Trader AS', number: 'NO-999888777' });

        // That flow persists its pair server-side through saveCompany. If this
        // display started writing the number into the address form instead, the
        // number would submit twice by two different routes.
        expect($("input[name='companyid']").val()).toBe('');
        expect($("input[name='company']").val()).toBe('');
        expect($("input[name='dni']").val()).toBe('');
    });

    test('the identifier mirroring the selection performs is unchanged', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);

        // Pinned here as well as in the company-search suite, because this
        // change added a call into the middle of onCompanySelected().
        expect($("input[name='dni']").val()).toBe('12345678');
        expect($("input[name='vat_number']").val()).toBe('12345678');
    });
});

describe('the payment tile is fed by the order-intent payload (TWO-25326 §7)', () => {
    // The §7 failure recorded on TWO-25326 for PrestaShop: the tile label never
    // rendered at all. Measured on a real PS 8 checkout - at the payment step
    // PrestaShop marks the address step `-complete` and REMOVES the address
    // form, so `input[name=company]` and the hidden `companyid` are both gone
    // and readState() had nothing left to read. The block sat `display:none`
    // with empty slots on every checkout.
    //
    // The pair now arrives from the module's own backend, on the same
    // order-intent response that already feeds the intent message beside it.

    /** Reproduce the payment step: PrestaShop has taken the address form away. */
    function removeAddressForm() {
        document.querySelectorAll("input[name='company'], input[name='companyid']")
            .forEach((el) => el.remove());
    }

    test('the pushed pair paints the label once the address form is gone', () => {
        removeAddressForm();
        expect(shown()).toEqual({ name: '', number: '', hidden: true });

        TwoCompanySummary.setIntentCompany({
            name: 'Example Trading Ltd',
            number: '12345678'
        });

        expect(shown()).toEqual({
            name: 'Example Trading Ltd',
            number: '12345678',
            hidden: false
        });
    });

    test('a name with no number shows the name alone', () => {
        // §5: a manual-entry buyer supplies a name and no number, and
        // "Example Ltd ()" is worse than "Example Ltd".
        removeAddressForm();

        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '' });

        expect(shown()).toEqual({ name: 'Example Trading Ltd', number: '', hidden: false });
        expect(root().classList.contains('two-company-summary--has-number')).toBe(false);
    });

    test('the pair outlives a re-render of the tile', () => {
        // PrestaShop replaces the payment step wholesale on a cart change,
        // which is why this is held on the class and not the instance.
        removeAddressForm();
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });

        root().remove();
        buildPaymentTile();
        TwoCompanySummary.render();

        expect(shown()).toEqual({
            name: 'Example Trading Ltd',
            number: '12345678',
            hidden: false
        });
    });

    test('a live address form still wins over the intent pair', () => {
        // On the ADDRESS step both exist. The field the buyer is looking at is
        // the truth; a stale intent pair from a company they have since moved
        // off must not override it.
        // A real instance, because it is what creates the hidden `companyid`
        // input the DOM path reads.
        makeInstance();
        TwoCompanySummary.setIntentCompany({ name: 'Stale Holdings Ltd', number: '99999999' });

        const nameField = document.querySelector("input[name='company']");
        const idField = document.querySelector("input[name='companyid']");
        nameField.value = 'Example Trading Ltd';
        idField.value = '12345678';
        idField.setAttribute('data-two-company-name', 'Example Trading Ltd');
        TwoCompanySummary.render();

        expect(shown()).toEqual({
            name: 'Example Trading Ltd',
            number: '12345678',
            hidden: false
        });
    });

    test('an enrolled sole trader outranks the intent pair', () => {
        // The enrolment happened on this step, in front of the buyer; the
        // intent pair may predate it.
        removeAddressForm();
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });
        TwoCompanySummary.setSoleTrader({ name: 'Sole Trader AS', number: 'NO-999888777' });

        expect(shown()).toEqual({
            name: 'Sole Trader AS',
            number: 'NO-999888777',
            hidden: false
        });
    });

    test('null forgets the pair', () => {
        removeAddressForm();
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });
        expect(shown().hidden).toBe(false);

        TwoCompanySummary.setIntentCompany(null);

        expect(shown()).toEqual({ name: '', number: '', hidden: true });
    });
});

describe('TwoOrderIntent publishes the payload company to the tile (TWO-25326 §7)', () => {
    // The wiring, tested separately from the rendering. Without this, deleting
    // the publish call in TwoOrderIntent leaves every test above green while
    // the tile goes blank again on a real checkout - the defect this whole
    // section exists to close.
    let intent;

    beforeEach(() => {
        loadScript('views/js/modules/TwoOrderIntent.js');
        intent = new window.TwoOrderIntent({});
        document.querySelectorAll("input[name='company'], input[name='companyid']")
            .forEach((el) => el.remove());
    });

    const payloadFor = (name, number) => ({
        buyer: { company: { company_name: name, organization_number: number } }
    });

    test('a payload with both values paints the label', () => {
        intent.publishPayloadCompany(payloadFor('Example Trading Ltd', '12345678'));

        expect(shown()).toEqual({
            name: 'Example Trading Ltd',
            number: '12345678',
            hidden: false
        });
        expect(intent.lastCompany).toBe('Example Trading Ltd');
        expect(intent.lastCompanyNumber).toBe('12345678');
    });

    test('a payload with a name and no number shows the name alone', () => {
        intent.publishPayloadCompany(payloadFor('Example Trading Ltd', ''));

        expect(shown()).toEqual({ name: 'Example Trading Ltd', number: '', hidden: false });
    });

    test('a later name-only payload does not reuse the previous number', () => {
        // The retained `lastCompanyNumber` is for the intent wording, not for
        // the label. Pairing it with a different company's name would assert a
        // company/number pairing that never existed - the one thing this block
        // must never do.
        intent.publishPayloadCompany(payloadFor('Example Trading Ltd', '12345678'));
        expect(shown().number).toBe('12345678');

        intent.publishPayloadCompany(payloadFor('Second Holdings Ltd', ''));

        expect(shown()).toEqual({ name: 'Second Holdings Ltd', number: '', hidden: false });
    });

    test('a payload with no company touches nothing', () => {
        intent.publishPayloadCompany(payloadFor('Example Trading Ltd', '12345678'));

        intent.publishPayloadCompany({ buyer: {} });
        intent.publishPayloadCompany(null);

        // Still the last real company, not blanked: an intent call that failed
        // to carry a company is not evidence the buyer lost theirs.
        expect(shown()).toEqual({
            name: 'Example Trading Ltd',
            number: '12345678',
            hidden: false
        });
    });

    test('values are trimmed', () => {
        intent.publishPayloadCompany(payloadFor('  Example Trading Ltd  ', '  12345678  '));

        expect(shown()).toEqual({
            name: 'Example Trading Ltd',
            number: '12345678',
            hidden: false
        });
    });
});


describe("the label rides on the intent message's visibility (TWO-25326 §7, revised)", () => {
    // Revised rule (TWO-25326): the company label is not shown unconditionally
    // once a company is captured. It is shown exactly when the order-intent
    // message is shown and hidden exactly when that message is hidden.
    //
    // Driven through TwoCheckoutManager rather than TwoOrderIntent, because that
    // is the module whose message the buyer actually sees on PrestaShop.
    // `.two-payment-info` is a section of the shipped template and
    // TwoCheckoutManager shows and hides it; TwoOrderIntent's own
    // `.two-order-intent-message` is appended to
    // `.payment-option-content, .payment-form, .additional-information` searched
    // WITHIN the `.payment-option`, and on PS 8's classic theme none of those
    // exist there - measured on a real PS 8 checkout, where that element never
    // enters the DOM at all. Pinning the gate to the container that does render
    // is the difference between this rule working and the label vanishing for
    // good.
    let manager;

    /**
     * A TwoCheckoutManager with no init().
     *
     * The constructor wires listeners, kicks off requests and reads config this
     * suite has no interest in. Object.create gives the real prototype - so
     * these are the shipped methods, not stubs - without any of that.
     */
    function makeManager() {
        const instance = Object.create(window.TwoCheckoutManager.prototype);
        instance.config = {};
        instance.twoPaymentOption = document.querySelector('.two-payment-container');
        instance.isLoadingUIShown = false;
        return instance;
    }

    /** Is the message the buyer would read actually on screen? */
    function messageVisible() {
        const info = document.querySelector('.two-payment-info');
        return !!info && window.getComputedStyle(info).display !== 'none';
    }

    /** Reproduce the payment step: PrestaShop has taken the address form away. */
    function removeAddressForm() {
        document.querySelectorAll("input[name='company'], input[name='companyid']")
            .forEach((el) => el.remove());
    }

    beforeEach(() => {
        loadScript('views/js/modules/TwoCheckoutManager.js');
        manager = makeManager();
        removeAddressForm();
        // Undo the file-wide precondition: these tests are about how the
        // visibility is ARRIVED at, so each drives it through the real code path
        // instead of inheriting it from setup.
        hideIntentMessage();
        TwoCompanySummary.render();
    });

    afterEach(() => {
        delete window.twopayment;
    });

    test('a captured company alone is not enough - no message, no label', () => {
        // The superseded rule. This is the assertion that fails if the label goes
        // back to being shown whenever a company is captured.
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });

        expect(messageVisible()).toBe(false);
        expect(shown().hidden).toBe(true);
        // The content is ready and waiting - it is the visibility that is gated,
        // not the rendering. Distinguishes "gated" from "broken".
        expect(slot('name').textContent).toBe('Example Trading Ltd');
    });

    test('the message going up takes the label with it', () => {
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });
        expect(shown().hidden).toBe(true);

        // The real show path every decision funnels through.
        manager.getOrCreateMessageContainer();

        expect(messageVisible()).toBe(true);
        expect(shown().hidden).toBe(false);
        expect(label()).toBe('Example Trading Ltd (12345678)');
    });

    test('an approval with the notice OFF hides the message and the label', () => {
        // The configuration that motivated this change: the tile is deliberately
        // silent on approval, and the label was still naming the company beside
        // it.
        //
        // Driven as a TRANSITION from a visible state rather than from the hidden
        // default, and that is load-bearing: from hidden, this test would pass
        // whether or not the suppression branch nudges anything, because the
        // label was already down. A decline first is also the real sequence - a
        // buyer is refused, fixes something, and is approved on a later poll.
        window.twopayment = { intent_approved_notice_enabled: false };
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });

        manager.showOrderIntentDecline('not yet');
        expect(messageVisible()).toBe(true);
        expect(shown().hidden).toBe(false);

        manager.showOrderIntentApproval('approved');

        expect(messageVisible()).toBe(false);
        expect(shown().hidden).toBe(true);
    });

    test('an approval with the notice ON shows the message and the label', () => {
        window.twopayment = { intent_approved_notice_enabled: true };
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });

        manager.showOrderIntentApproval('approved');

        expect(messageVisible()).toBe(true);
        expect(shown().hidden).toBe(false);
    });

    test('the label agrees with the message in every state, one gate not two', () => {
        // The anti-duplication test. Whatever the combination, the two answers are
        // compared to EACH OTHER rather than to a hardcoded expectation, so a
        // second copy of the approved-notice rule that disagreed with
        // TwoCheckoutManager by even one case fails here.
        const cases = [
            { enabled: true, approved: true },
            { enabled: false, approved: true },
            { enabled: true, approved: false },
            { enabled: false, approved: false }
        ];

        cases.forEach(({ enabled, approved }) => {
            window.twopayment = { intent_approved_notice_enabled: enabled };
            TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });

            if (approved) {
                manager.showOrderIntentApproval('approved');
            } else {
                manager.showOrderIntentDecline('declined');
            }

            expect(shown().hidden).toBe(!messageVisible());
        });
    });

    test('a message hidden by an ancestor hides the label too', () => {
        // The message's own `display` is not the question - the buyer cannot read
        // it through a collapsed wrapper either, and on a real checkout this
        // section sits inside containers both the theme and the module hide.
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });
        showIntentMessage();
        TwoCompanySummary.render();
        expect(shown().hidden).toBe(false);

        document.querySelector('.two-payment-container').style.display = 'none';
        TwoCompanySummary.render();

        expect(shown().hidden).toBe(true);
    });

    test('a message left fully transparent does not show the label', () => {
        // `.two-payment-info` is `opacity: 0` in the stylesheet and is revealed
        // by adding `.show`. A gate that only looked at `display` would read the
        // displayed-but-un-shown section as visible and put the label beside a
        // message the buyer cannot see. The real cascade is loaded by this
        // suite's beforeEach, so this is the shipped rule doing the hiding.
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });

        const info = document.querySelector('.two-payment-info');
        info.style.display = 'block';
        info.classList.remove('show');
        TwoCompanySummary.render();

        expect(window.getComputedStyle(info).opacity).toBe('0');
        expect(shown().hidden).toBe(true);

        // And the class that reveals it brings the label with it.
        info.classList.add('show');
        TwoCompanySummary.render();

        expect(shown().hidden).toBe(false);
    });

    test('a mid-fade message still counts as shown', () => {
        // The same rule carries a 0.3s opacity transition, so a value between 0
        // and 1 is a message arriving, not a hidden one. Pinned because the
        // obvious "less than 1" test would blink the label off during every
        // fade-in.
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });

        const info = showIntentMessage();
        info.style.opacity = '0.4';
        TwoCompanySummary.render();

        expect(shown().hidden).toBe(false);
    });

    test("TwoOrderIntent's own message element counts as the message too", () => {
        // It does not render on PS 8's classic theme, but the gate is about what
        // the buyer can see rather than about which module drew it - so if this
        // element IS in the DOM and visible on some theme, the label belongs
        // beside it.
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });
        expect(shown().hidden).toBe(true);

        const msg = document.createElement('div');
        msg.className = 'two-order-intent-message';
        msg.textContent = 'declined';
        document.querySelector('.two-payment-container').appendChild(msg);
        TwoCompanySummary.render();

        expect(shown().hidden).toBe(false);
    });

    test('clearing the intent UI takes the label down', () => {
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });
        window.twopayment = { intent_approved_notice_enabled: true };
        manager.showOrderIntentDecline('declined');
        expect(shown().hidden).toBe(false);

        // clearOrderIntentUI() hides its fallback container; the template section
        // is what is on screen here, so hide that the way the module does and
        // confirm the label follows rather than being left behind.
        hideIntentMessage();
        manager.clearOrderIntentUI();

        expect(messageVisible()).toBe(false);
        expect(shown().hidden).toBe(true);
    });

    test('a visible message does not conjure a label out of nothing', () => {
        // The ceiling, not a floor: a decline with no company captured at all
        // shows a message the label has nothing to accompany, and two empty slots
        // with a stray "()" are worse than no block.
        window.twopayment = { intent_approved_notice_enabled: true };
        manager.showOrderIntentDecline('no company found');

        expect(messageVisible()).toBe(true);
        expect(shown()).toEqual({ name: '', number: '', hidden: true });
    });

    test('the visibility survives a re-render of the tile', () => {
        // PrestaShop replaces the payment step wholesale on a cart change. The
        // captured pair is class-static for that reason, and the gate is re-read
        // from the replacement DOM rather than remembered - so a tile rebuilt
        // while the message is down must come back hidden.
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });
        showIntentMessage();
        TwoCompanySummary.render();
        expect(shown().hidden).toBe(false);

        root().remove();
        document.querySelector('.two-payment-container').remove();
        buildPaymentTile();
        showIntentMessage();
        TwoCompanySummary.render();
        expect(shown().hidden).toBe(false);

        root().remove();
        document.querySelector('.two-payment-container').remove();
        buildPaymentTile();
        hideIntentMessage();
        TwoCompanySummary.render();
        expect(shown().hidden).toBe(true);
    });

    test('the nudge tolerates the tile module being absent', () => {
        // twopayment.js constructs these independently; a checkout running
        // without the summary class must not throw.
        const saved = window.TwoCompanySummary;
        delete window.TwoCompanySummary;
        try {
            expect(() => manager.refreshCompanyLabel()).not.toThrow();
        } finally {
            window.TwoCompanySummary = saved;
        }
    });
});

describe("TwoOrderIntent's own message drives the label on themes that render it (TWO-25326 §7)", () => {
    // TwoOrderIntent.updateUI() appends `.two-order-intent-message` into
    // `.payment-option-content, .payment-form, .additional-information` searched
    // WITHIN the `.payment-option`. PS 8's classic theme provides none of those
    // there, so on that theme the element never enters the DOM at all and this
    // module's message is invisible - which is why the gate is anchored on
    // TwoCheckoutManager's container instead.
    //
    // On a theme that DOES provide one, this module's message is the one the
    // buyer reads, and the label has to track it. That is what this suite builds
    // and pins. Without it, TwoOrderIntent's nudges are untested - confirmed by
    // mutation: deleting them left every other test in this file green.
    let intent;

    /** A theme whose payment option contains the container updateUI looks for. */
    function wrapTileAsPaymentOption() {
        const container = document.querySelector('.two-payment-container');
        const option = document.createElement('div');
        option.className = 'payment-option';
        const content = document.createElement('div');
        content.className = 'payment-option-content';
        const marker = document.createElement('div');
        marker.setAttribute('data-module-name', 'twopayment');
        option.appendChild(marker);
        option.appendChild(content);
        container.parentNode.insertBefore(option, container);
        content.appendChild(container);
        return option;
    }

    function ownMessageVisible() {
        const msg = document.querySelector('.two-order-intent-message');
        return !!msg && window.getComputedStyle(msg).display !== 'none';
    }

    beforeEach(() => {
        loadScript('views/js/modules/TwoOrderIntent.js');
        intent = new window.TwoOrderIntent({});
        document.querySelectorAll("input[name='company'], input[name='companyid']")
            .forEach((el) => el.remove());
        wrapTileAsPaymentOption();
        // Only this module's message is in play here, so take the template
        // section out of the picture - otherwise the label could be riding on
        // TwoCheckoutManager's container and these tests would prove nothing.
        hideIntentMessage();
        TwoCompanySummary.render();
    });

    afterEach(() => {
        delete window.twopayment;
    });

    test('its message going up brings the label with it', () => {
        window.twopayment = { intent_approved_notice_enabled: true };
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });
        expect(shown().hidden).toBe(true);

        intent.updateUI({ approved: true, message: 'looks fine', rawResponse: {} });

        expect(ownMessageVisible()).toBe(true);
        expect(shown().hidden).toBe(false);
        expect(label()).toBe('Example Trading Ltd (12345678)');
    });

    test('its message being removed takes the label down', () => {
        // Driven as a transition, so the suppression is actually observed rather
        // than the label merely being down already.
        window.twopayment = { intent_approved_notice_enabled: false };
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });

        intent.updateUI({ approved: false, message: 'not yet', rawResponse: {} });
        expect(ownMessageVisible()).toBe(true);
        expect(shown().hidden).toBe(false);

        intent.updateUI({ approved: true, message: '', rawResponse: {} });

        expect(ownMessageVisible()).toBe(false);
        expect(shown().hidden).toBe(true);
    });

    test('a blocked submit puts its message up, and the label with it', () => {
        window.twopayment = { intent_approved_notice_enabled: false };
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });
        expect(shown().hidden).toBe(true);

        intent.lastResult = { approved: false, message: 'resolve this first' };
        intent.showOrderPreventionMessage();

        expect(ownMessageVisible()).toBe(true);
        expect(shown().hidden).toBe(false);
    });

    test('reset() takes the label down with the decision', () => {
        window.twopayment = { intent_approved_notice_enabled: true };
        TwoCompanySummary.setIntentCompany({ name: 'Example Trading Ltd', number: '12345678' });
        intent.updateUI({ approved: true, message: 'looks fine', rawResponse: {} });
        expect(shown().hidden).toBe(false);

        // reset() forgets the decision; the message element it drew is removed
        // with it on a real checkout by the re-render that follows, so the label
        // must not be left announcing a company for a decision that is gone.
        document.querySelectorAll('.two-order-intent-message').forEach((el) => el.remove());
        intent.reset();

        expect(shown().hidden).toBe(true);
    });
});
