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
    loadScript,
    stubAjax,
    releaseWidgets,
    flushPromises
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
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    bus = loaded.bus;
    ajax = stubAjax($);
    TwoCompanySummary = loadCompanySummary();
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
});

function makeInstance(extraConfig) {
    return new TwoCompanySearch(
        Object.assign({ checkoutHost: CHECKOUT_HOST }, extraConfig || {})
    );
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

/** Search, settle, then pick the first row the way a buyer's click does. */
function selectFirstResult(term, response) {
    const field = $("input[name='company']");
    const instance = field.autocomplete('instance');
    field.val(term);
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

    test('the blank number slot is present, not removed', () => {
        const search = makeInstance();
        search.enterManualEntryMode();
        typeCompanyName('Unlisted Trading Ltd');

        // Blank, not absent: a slot that disappears reads as a rendering fault
        // rather than as "this buyer has no number".
        expect(slot('number')).not.toBeNull();
        expect(root().contains(slot('number'))).toBe(true);
        // The row keeps its height, so the blank reads as an empty answer rather
        // than as a collapsed element. The stylesheet does that with a
        // min-height on the value span; nothing needs a state class for it.
        expect(root().querySelectorAll('.two-company-summary__row')).toHaveLength(2);
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
