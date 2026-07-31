/**
 * TWO-25288 element 2: click-to-reveal search box.
 *
 * Woo/Luma's select2 keeps a `.select2-selection__rendered` chip in front of a
 * separate `.select2-search__field` the buyer actually types into, so typing
 * never touches the confirmed name until a result is chosen. PrestaShop's
 * address form has no such split - `input[name='company']` IS the search box
 * on both render paths - so the same protection is built here as a covering
 * chip (`.two-company-search-reveal`) that is shown only while a confirmed
 * selection exists and stands aside the moment the buyer reopens the search.
 *
 * The riskiest part of this build is PrestaShop replacing the address form's
 * DOM on `updatedAddressForm` for something as ordinary as a country change
 * (TwoCheckoutManager.handleAddressFormUpdate()) - so the chip, like the
 * empty-field hint next to it, has to be rebuilt every time setupAutocomplete()
 * re-runs, not only once at construction.
 */

'use strict';

const {
    loadCompanySearch,
    buildAddressForm,
    replaceAddressForm,
    stubAjax,
    releaseWidgets
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const SEARCH_RESPONSE = {
    items: [{ name: 'Example Trading Ltd', national_identifier: { id: '12345678' } }]
};
const OTHER_RESPONSE = {
    items: [{ name: 'Another Company Ltd', national_identifier: { id: '87654321' } }]
};

let TwoCompanySearch;
let $;
let bus;
let ajax;

beforeEach(() => {
    buildAddressForm({ country: 'GB' });
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    bus = loaded.bus;
    ajax = stubAjax($);
});

afterEach(() => {
    releaseWidgets($);
    ajax.restore();
    document.body.innerHTML = '';
    jest.useRealTimers();
});

function makeInstance(extraConfig) {
    return new TwoCompanySearch(
        Object.assign({ checkoutHost: CHECKOUT_HOST }, extraConfig || {})
    );
}

function liveField() {
    return $("input[name='company']");
}

function chip() {
    return $('.two-company-search-reveal');
}

/**
 * Whether the chip is currently shown. The chip element itself is created
 * once, on the first setupAutocomplete() call, and toggled with jQuery's
 * show()/hide() from then on - so "absent" in the buyer's sense means hidden,
 * not missing from the DOM. jQuery's `:visible` pseudo-selector depends on
 * layout (offsetWidth/height/getClientRects()), which jsdom does not compute,
 * so it is unusable here; the inline `display` jQuery's show()/hide() sets is
 * checked directly instead.
 */
function chipVisible() {
    const el = chip();
    return el.length > 0 && el.css('display') !== 'none';
}

/** Search, settle, then pick the first row the way a buyer's click does. */
function selectFirstResult(term, response) {
    const field = liveField();
    const instance = field.autocomplete('instance');
    field.val(term);
    instance.search(term);
    ajax.last().succeed(response);
    const row = instance.menu.element.children('li').first();
    instance.menu.focus(null, row);
    instance.menu.select($.Event('click'));
    return field;
}

/**
 * Pre-seed the address form with a confirmed pair, as if the server had
 * round-tripped a previous selection - the same construction the rerender
 * suite's stale-organisation tests use, reused here because a genuine
 * re-render carries this forward while `replaceAddressForm()` alone does not.
 */
function seedConfirmedPair(company, companyid) {
    const form = document.querySelector('form');
    form.querySelector("input[name='company']").value = company;
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'companyid';
    hidden.value = companyid;
    hidden.setAttribute('data-two-company-name', company);
    form.appendChild(hidden);
}

describe('the chip is shown only for a confirmed selection', () => {
    test('absent when nothing has been searched for', () => {
        makeInstance();
        expect(chipVisible()).toBe(false);
    });

    test('appears once a real selection lands, showing the picked name', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);

        expect(chipVisible()).toBe(true);
        expect(chip().text()).toBe('Example Trading Ltd');
    });

    test('it is a real, focusable, accessibly-labelled button', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);

        expect(chip().prop('tagName')).toBe('BUTTON');
        expect(chip().attr('type')).toBe('button');
        expect(chip().attr('aria-label')).toContain('Example Trading Ltd');
    });

    test('covers the field and takes it out of the tab order', () => {
        makeInstance();
        const field = selectFirstResult('exa', SEARCH_RESPONSE);

        expect(field.attr('tabindex')).toBe('-1');
    });

    test('a selection carrying no organisation number (deferred GB lookup) shows no chip yet', () => {
        makeInstance();
        selectFirstResult('exa', {
            items: [{ name: 'Example Trading Ltd', lookup_id: 'lookup-abc' }]
        });

        // No organisation number landed with the pick itself, so there is
        // nothing confirmed to protect until fetchCompanyDetails() resolves.
        expect(chipVisible()).toBe(false);
    });

    test('manual entry never shows a chip over free-typed text', () => {
        const instance = makeInstance();
        const field = liveField();
        field.val('exa');
        field.autocomplete('instance').search('exa');
        ajax.last().succeed(SEARCH_RESPONSE);

        field.autocomplete('option', 'select')(null, { item: instance.buildManualEntryItem() });
        field.val('My Own Trading Name');
        field.trigger('input');

        expect(chipVisible()).toBe(false);
    });
});

describe('clicking the chip reveals a fresh search box', () => {
    test('blanks the field, focuses it, and hides the chip', () => {
        makeInstance();
        const field = selectFirstResult('exa', SEARCH_RESPONSE);

        chip().trigger('click');

        expect(field.val()).toBe('');
        expect(document.activeElement).toBe(field.get(0));
        expect(chipVisible()).toBe(false);
        expect(field.attr('tabindex')).toBeUndefined();
    });

    test('typing into the revealed field does not touch the covered name until a pick is made', () => {
        makeInstance();
        const field = selectFirstResult('exa', SEARCH_RESPONSE);

        chip().trigger('click');
        field.val('Ano');
        field.trigger('input');
        field.autocomplete('instance').search('Ano');

        // The buyer is mid-search; the confirmed name from before is gone from
        // the visible field (by design - see the module's own reasoning) but
        // has not been overwritten by keystrokes landing on top of it, and
        // nothing has been submitted as a new company yet.
        expect(field.val()).toBe('Ano');
        expect($("input[name='companyid']").val()).toBe('');
    });

    test('the search still works normally once revealed', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);
        const field = liveField();

        chip().trigger('click');
        const picked = selectFirstResult('ano', OTHER_RESPONSE);

        expect(picked.val()).toBe('Another Company Ltd');
        expect($("input[name='companyid']").val()).toBe('87654321');
        expect(chip().text()).toBe('Another Company Ltd');
        expect(chipVisible()).toBe(true);
        expect(field.attr('tabindex')).toBe('-1');
    });
});

describe('abandoning a revealed search restores what it covered', () => {
    test('blurring with nothing picked puts the name AND the organisation number back', () => {
        jest.useFakeTimers();
        makeInstance();
        const field = selectFirstResult('exa', SEARCH_RESPONSE);

        chip().trigger('click');
        field.val('some other typing');
        field.trigger('blur');

        jest.advanceTimersByTime(250);

        expect(field.val()).toBe('Example Trading Ltd');
        expect($("input[name='companyid']").val()).toBe('12345678');
        expect($("input[name='companyid']").attr('data-two-company-name')).toBe(
            'Example Trading Ltd'
        );
        expect(chipVisible()).toBe(true);
        expect(chip().text()).toBe('Example Trading Ltd');
        expect(field.attr('tabindex')).toBe('-1');
    });

    test('typing alone (before the threshold) already cleared the tag - blur restore still recovers it', () => {
        // Regression guard for the exact hazard the module documents at length
        // elsewhere: clearStaleOrganizationSelection() fires on the very first
        // keystroke into the revealed (now-empty) field and clears the hidden
        // organisation number and its tag well before any blur happens. The
        // snapshot revealSearch() took has to be what puts both back - reading
        // the live (already-cleared) fields at blur time would restore the
        // name with nothing behind it.
        jest.useFakeTimers();
        makeInstance();
        const field = selectFirstResult('exa', SEARCH_RESPONSE);

        chip().trigger('click');
        field.val('x');
        field.trigger('input');
        expect($("input[name='companyid']").val()).toBe('');

        field.trigger('blur');
        jest.advanceTimersByTime(250);

        expect(field.val()).toBe('Example Trading Ltd');
        expect($("input[name='companyid']").val()).toBe('12345678');
    });

    test('a pick before the blur timer elapses wins - the stale timer does not clobber it', () => {
        jest.useFakeTimers();
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);
        const field = liveField();

        chip().trigger('click');
        field.trigger('blur');
        // The pick lands inside the same 200ms window as the pending restore.
        const instance = field.autocomplete('instance');
        field.val('ano');
        instance.search('ano');
        ajax.last().succeed(OTHER_RESPONSE);
        const row = instance.menu.element.children('li').first();
        instance.menu.focus(null, row);
        instance.menu.select($.Event('click'));

        jest.advanceTimersByTime(250);

        expect(field.val()).toBe('Another Company Ltd');
        expect($("input[name='companyid']").val()).toBe('87654321');
    });

    test('entering manual entry mid-reveal disarms the restore instead of fighting it later', () => {
        jest.useFakeTimers();
        const instance = makeInstance();
        const field = selectFirstResult('exa', SEARCH_RESPONSE);

        chip().trigger('click');
        instance.enterManualEntryMode();
        field.val('My Own Trading Name');

        jest.advanceTimersByTime(250);

        // The buyer's own manual entry must survive - not be silently
        // overwritten by a restore that belongs to a different escape path.
        expect(field.val()).toBe('My Own Trading Name');
        expect(chipVisible()).toBe(false);
    });
});

describe('the chip survives PrestaShop replacing the address form', () => {
    test('a fresh instance on the re-rendered DOM shows the chip again, bound to the new field', () => {
        makeInstance();
        const first = selectFirstResult('exa', SEARCH_RESPONSE);
        const firstChip = chip().get(0);
        expect(chipVisible()).toBe(true);

        // PrestaShop re-renders from server state, which carries the confirmed
        // pair forward - unlike a bare DOM wipe, this is what a real
        // `updatedAddressForm` looks like for an already-selected company.
        replaceAddressForm({ country: 'GB' });
        seedConfirmedPair('Example Trading Ltd', '12345678');
        // The old form (and its chip) is gone with it.
        expect(chip()).toHaveLength(0);
        expect(liveField().get(0)).not.toBe(first.get(0));

        // The instance's own `updatedAddressForm` handler re-runs
        // setupAutocomplete() against whatever field is live now.
        bus.emit('updatedAddressForm');

        expect(chipVisible()).toBe(true);
        expect(chip().get(0)).not.toBe(firstChip);
        expect(chip().text()).toBe('Example Trading Ltd');
        expect(liveField().attr('tabindex')).toBe('-1');
    });

    test('clicking the chip on the re-rendered field still reveals a working search', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);

        replaceAddressForm({ country: 'GB' });
        seedConfirmedPair('Example Trading Ltd', '12345678');
        bus.emit('updatedAddressForm');

        chip().trigger('click');
        const field = liveField();

        expect(field.val()).toBe('');
        expect(document.activeElement).toBe(field.get(0));

        field.val('ano');
        field.autocomplete('instance').search('ano');
        ajax.last().succeed(OTHER_RESPONSE);
        const row = field.autocomplete('instance').menu.element.children('li').first();
        field.autocomplete('instance').menu.focus(null, row);
        field.autocomplete('instance').menu.select($.Event('click'));

        expect(field.val()).toBe('Another Company Ltd');
        expect(chip().text()).toBe('Another Company Ltd');
    });

    test('a re-render with no persisted selection leaves the chip absent, not stale', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);
        expect(chipVisible()).toBe(true);

        // Ordinary re-render with nothing carried forward (e.g. the buyer had
        // not selected anything yet on a fresh address).
        replaceAddressForm({ country: 'GB' });
        bus.emit('updatedAddressForm');

        expect(chipVisible()).toBe(false);
    });
});

describe('the same chip works on the custom fallback path (jQuery UI absent)', () => {
    let savedUi;

    beforeEach(() => {
        jest.useFakeTimers();
        savedUi = $.ui;
        // A theme can ship jQuery without jQuery UI, or load it late - both
        // take this path. Path B has to get its own reveal chip built by
        // hand, same as the jQuery UI path.
        $.ui = undefined;
    });

    afterEach(() => {
        jest.useRealTimers();
        $.ui = savedUi;
    });

    /** Type into the field and let the 300ms debounce elapse. */
    function type(term) {
        const input = document.querySelector("input[name='company']");
        input.value = term;
        input.dispatchEvent(new window.Event('input'));
        jest.advanceTimersByTime(300);
        return input;
    }

    /** A real company row, excluding the manual-entry/message footer rows. */
    function firstCompanyRow() {
        return $('.two-autocomplete-item')
            .not('.two-autocomplete-manual-entry, .two-autocomplete-message')
            .first();
    }

    function selectFirstResultFallback(term, response) {
        type(term);
        ajax.last().succeed(response);
        firstCompanyRow().get(0).dispatchEvent(
            new window.MouseEvent('mousedown', { bubbles: true, cancelable: true })
        );
        return liveField();
    }

    test('bootstrapped-guard: this is genuinely the fallback path', () => {
        makeInstance();
        expect(liveField().hasClass('ui-autocomplete-input')).toBe(false);
        expect(chipVisible()).toBe(false);
    });

    test('the chip appears after a selection and shows the picked name', () => {
        makeInstance();
        const field = selectFirstResultFallback('exa', SEARCH_RESPONSE);

        expect(field.val()).toBe('Example Trading Ltd');
        expect(chipVisible()).toBe(true);
        expect(chip().text()).toBe('Example Trading Ltd');
        expect(field.attr('tabindex')).toBe('-1');
    });

    test('clicking the chip reveals a blank field the buyer can search again from', () => {
        makeInstance();
        const field = selectFirstResultFallback('exa', SEARCH_RESPONSE);

        chip().trigger('click');

        expect(field.val()).toBe('');
        expect(document.activeElement).toBe(field.get(0));
        expect(chipVisible()).toBe(false);

        const picked = selectFirstResultFallback('ano', OTHER_RESPONSE);
        expect(picked.val()).toBe('Another Company Ltd');
        expect(chip().text()).toBe('Another Company Ltd');
    });

    test('abandoning a revealed search on this path restores the covered pair too', () => {
        makeInstance();
        const field = selectFirstResultFallback('exa', SEARCH_RESPONSE);

        chip().trigger('click');
        field.val('some other typing');
        field.trigger('input');
        field.trigger('blur');

        jest.advanceTimersByTime(250);

        expect(field.val()).toBe('Example Trading Ltd');
        expect($("input[name='companyid']").val()).toBe('12345678');
    });
});

describe('teardown', () => {
    test('destroy() removes the chip and its click handler', () => {
        const instance = makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);
        expect(chip()).toHaveLength(1);

        instance.destroy();

        expect(chip()).toHaveLength(0);
    });

    test('a second instance on the same field does not leave two chips behind', () => {
        makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);
        expect(chip()).toHaveLength(1);

        const second = makeInstance();
        second.companyField.val('Example Trading Ltd');
        second.organizationField.val('12345678');
        second.organizationField.attr('data-two-company-name', 'Example Trading Ltd');
        second.updateRevealChip();

        expect(chip()).toHaveLength(1);
    });
});
