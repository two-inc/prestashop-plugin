/**
 * TWO-25239. Regression tests for the company-search widget across an
 * address-form re-render.
 *
 * PrestaShop fires `updatedAddressForm` on ordinary interactions like a
 * country change; TwoCheckoutManager.handleAddressFormUpdate() responds by
 * destroying the TwoCompanySearch instance and building a fresh one. Two
 * defects live on that path:
 *
 *   - `_renderItem` was re-wrapped on every setup. jQuery UI's widget bridge
 *     does not build a fresh instance when `.autocomplete({...})` runs on an
 *     already-initialised field — it runs option() + _init() on the existing
 *     one — so each call wrapped the previous wrapper again, nesting deeper
 *     per event until rendering a row blew the stack.
 *   - a destroyed instance kept acting. `prestashop.on` has no `off`, so a
 *     destroyed instance's handler still fires; once setupAutocomplete()
 *     re-resolved the field against the live DOM, the zombie resolved to the
 *     SAME live input as the live instance while its own `companyid` field
 *     was the detached one its init() had created — so a selected company's
 *     organisation number was written somewhere that no longer submits.
 *
 * A third defect (stuck spinner) is covered here too: the spinner is jQuery
 * UI's `pending` counter and only the real widget has one.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const {
    loadCompanySearch,
    buildAddressForm,
    replaceAddressForm,
    stubAjax,
    callbackRecorder,
    releaseWidgets,
    flushPromises,
    installStylesheet,
    countGifFrames,
    shown,
    REPO_ROOT
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const LOADING_CLASS = 'ui-autocomplete-loading';

const SEARCH_RESPONSE = {
    items: [{ name: 'Example Trading Ltd', national_identifier: { id: '12345678' } }]
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
});

function makeInstance(extraConfig) {
    return new TwoCompanySearch(
        Object.assign({ checkoutHost: CHECKOUT_HOST }, extraConfig || {})
    );
}

function liveField() {
    return $("input[name='company']");
}

/**
 * The input the SEARCH WIDGET is bound to (TWO-25326).
 *
 * Not the same node as `liveField()`: the company-name field is now only the
 * trigger that opens the panel; the widget - `minLength`, `delay`, loading
 * class, menu - lives on the panel's own query input.
 *
 * Resolved fresh every call, never cached: the panel is rebuilt whenever
 * PrestaShop replaces the address form.
 */
function searchInput() {
    return $('.two-company-dropdown__query');
}

function panel() {
    return $('.two-company-dropdown');
}

/** Open the panel via a real mousedown, and return the query input. */
function openPanel() {
    liveField().trigger('mousedown');
    return searchInput();
}

describe('the real jQuery UI widget is what gets bound', () => {
    test('setup binds the widget and marks the field for the spinner CSS', () => {
        makeInstance();

        expect(liveField().hasClass('two-company-search-input')).toBe(true);
        expect(searchInput().hasClass('ui-autocomplete-input')).toBe(true);
        expect(searchInput().autocomplete('instance')).toBeTruthy();
        // 0 is deliberate (TWO-25288): jQuery UI skips `source` below
        // `minLength`, so a threshold here would swallow keystrokes before
        // `source` runs. The threshold lives in the `source` guard instead.
        expect(searchInput().autocomplete('option', 'minLength')).toBe(0);
        expect(searchInput().autocomplete('option', 'delay')).toBe(300);
    });

    test('#30.x.14 bug 2.1: the menu opens with a visible gap below the field, not flush against it', () => {
        makeInstance();

        // Live-verified: jQuery UI's default butted the menu flush against
        // the field with zero gap, reading as one continuous control.
        expect(searchInput().autocomplete('option', 'position')).toEqual({
            my: 'left top+8',
            at: 'left bottom',
            collision: 'none'
        });
    });
});

describe('#30.x.14 bug 2.1: a real click opens a control, plain keyboard focus does not', () => {
    function menu() {
        return $('ul.ui-autocomplete');
    }

    /** A real user click: mousedown fires before focus, in that order. */
    function click(field) {
        field.trigger('mousedown');
        field.trigger('focus');
    }

    test('clicking into an EMPTY field opens the panel with no result rows - the hint lives in the placeholder now', () => {
        makeInstance();
        const field = liveField();

        // TWO-25326 §1: the PANEL is what opens, not jQuery UI's floating
        // menu - its own `display` is meaningless while the panel is shut.
        // `shown()` walks the ancestor chain; jsdom computes no layout, so
        // jQuery's `:visible` cannot be used here.
        expect(shown(panel())).toBe(false);

        click(field);

        expect(shown(panel())).toBe(true);
        // buildFocusHintItem() and buildTooShortItem() are both gone (TWO-40
        // follow-up): an empty/too-short query renders NO row any more - the
        // length requirement is the query field's own placeholder instead.
        expect(menu().find('li')).toHaveLength(0);
    });

    test('plain keyboard focus (Tab, no mousedown) opens nothing', () => {
        // Round-1 adversarial review (Vader): opening for keyboard focus with
        // no signal of intent would announce a result with nothing keyboard-
        // selectable. Gating on a real `mousedown` (which Tab never fires)
        // keeps Tab silent while still opening for an actual click.
        makeInstance();
        const field = liveField();

        field.trigger('focus');

        expect(shown(panel())).toBe(false);
    });

    test('clicking the field while in manual entry opens nothing', () => {
        const instance = makeInstance();
        const field = liveField();

        instance.enterManualEntryMode();
        click(field);

        expect(shown(panel())).toBe(false);
    });

    test('the pointer signal is one-shot: a second focus with no mousedown in between does not reopen it', () => {
        const instance = makeInstance();
        const field = liveField();

        click(field);
        expect(shown(panel())).toBe(true);

        // Closed through the control's own close path rather than by hiding
        // jQuery UI's menu element by hand: the panel - not the menu - is what
        // opens and shuts now, so this test is about whether the SECOND focus
        // below reopens THAT.
        instance.closeDropdown(false);
        expect(shown(panel())).toBe(false);

        field.trigger('focus');

        expect(shown(panel())).toBe(false);
    });

    test('a destroyed instance does not reopen the menu on click', () => {
        const instance = makeInstance();
        const field = liveField();

        instance.destroy();
        // destroy() unbinds both namespaced handlers; nothing left to throw
        // or reopen the menu on a field the instance no longer owns. The
        // widget itself is torn down too, so the menu element is gone
        // outright rather than merely hidden.
        expect(() => click(field)).not.toThrow();
        expect(menu()).toHaveLength(0);
    });

    test('moving to a replaced field during setupAutocomplete() unbinds the old field\'s pointer/focus handlers', () => {
        // Round-1 adversarial review finding (Han): setupAutocomplete()'s
        // "moving to a replaced field" branch released the old field's
        // jQuery UI widget but left `focus.twoCompanyOpen` /
        // `mousedown.twoCompanyOpen` bound to it directly (they are bound
        // via jQuery, not through the widget, so `autocomplete('destroy')`
        // does not touch them).
        const instance = makeInstance();
        const oldField = liveField();

        replaceAddressForm({ country: 'GB' });
        instance.setupAutocomplete();

        // The old node is detached, so it can never receive a REAL user
        // mousedown/focus again - but a directly `.trigger()`ed one still
        // reaches any handler still bound, which is exactly what a leaked
        // binding would answer to.
        expect(() => {
            oldField.trigger('mousedown');
            oldField.trigger('focus');
        }).not.toThrow();
        // No menu opens for the detached node - it has no widget any more -
        // and the live field's own listener is unaffected.
        expect($('ul.ui-autocomplete').filter((i, el) => $(el).css('display') !== 'none')).toHaveLength(0);
    });
});

describe('the company-search hints (TWO-25288)', () => {
    /**
     * Drive the widget's own search so the `source` guard, jQuery UI's menu and
     * the `_renderItem` patch are all the things under test.
     */
    function search(term) {
        // Panel must be open before a query field exists (TWO-25326 §1); its
        // empty-query open renders no row and makes no request (TWO-40
        // follow-up), so ajax counts below measure only what `term` caused.
        openPanel();
        const field = searchInput();
        // Guard against passing vacuously with no widget bound.
        expect(field.hasClass('ui-autocomplete-input')).toBe(true);
        expect(field.autocomplete('instance')).toBeTruthy();
        field.val(term);
        // Driven via the widget's own search() - no fake timers here, so an
        // `input` event would only arm the 300ms debounce and never render.
        field.autocomplete('instance').search(term);
        return field;
    }

    /** Rows currently in the jQuery UI menu. */
    function rows() {
        return $('ul.ui-autocomplete li');
    }

    describe('the threshold is one constant', () => {
        test('the published constant is 3', () => {
            // Pins the shipped default so a change to it is a visible diff here
            // rather than a silent change of checkout behaviour. Every other
            // assertion in this file derives from the constant, on purpose: they
            // must pin the RELATIONSHIP between what is claimed and what is
            // enforced, which is the thing that drifts.
            expect(TwoCompanySearch.MIN_SEARCH_LENGTH).toBe(3);
        });

        test('the query field placeholder states the number the guard actually enforces (TWO-40 follow-up)', () => {
            const threshold = TwoCompanySearch.MIN_SEARCH_LENGTH;
            const search = makeInstance();

            // The msgid's own `%d` must be gone, replaced by the constant.
            expect(search.getQueryPlaceholderText()).toBe(
                'Enter ' + threshold + ' or more characters'
            );
            expect(search.getQueryPlaceholderText()).not.toContain('%d');
        });

        test('the number comes from the constant, not from the translation', () => {
            const saved = window.twopayment;
            window.twopayment = { i18n: { company_search_query_placeholder: 'Introduce %d o más caracteres' } };
            try {
                // Constructed AFTER the config is in place: the instance reads
                // the page config once, at construction.
                expect(makeInstance().getQueryPlaceholderText()).toBe(
                    'Introduce ' + TwoCompanySearch.MIN_SEARCH_LENGTH + ' o más caracteres'
                );
            } finally {
                window.twopayment = saved;
            }
        });
    });

    describe('the empty company field carries no wording of ours', () => {
        test('setup leaves an empty placeholder slot empty', () => {
            makeInstance();

            expect(liveField().attr('placeholder')).toBeUndefined();
        });

        test('a placeholder the theme already set is left alone', () => {
            liveField().attr('placeholder', 'Theme wording');

            makeInstance();

            expect(liveField().attr('placeholder')).toBe('Theme wording');
        });

        test('the fresh input after an address-form re-render gets none either', () => {
            makeInstance();
            replaceAddressForm({ country: 'GB' });

            // PrestaShop swapped the node; setup runs again against the new one.
            bus.emit('updatedAddressForm');

            expect(liveField().attr('placeholder')).toBeUndefined();
        });
    });

    describe('the min-chars gate on the jQuery UI path (TWO-40 follow-up: no row rendered any more)', () => {
        test('a sub-threshold term renders no row and fires no request', () => {
            const instance = makeInstance();
            const short = 'a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH - 1);

            search(short);

            // buildTooShortItem() is gone: the length requirement lives in the
            // query field's placeholder (getQueryPlaceholderText()) instead of a
            // dropdown row.
            expect(rows()).toHaveLength(0);
            expect(searchInput().attr('placeholder')).toBe(instance.getQueryPlaceholderText());
            // Before TWO-25288 the widget swallowed this term and showed nothing,
            // which is indistinguishable from a search that found no match -
            // that gate (no request escapes it) is what survives here.
            expect(ajax.calls).toHaveLength(0);
        });

        test('a term at the threshold searches and shows results, no leftover row', () => {
            makeInstance();

            search('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH));

            expect(ajax.calls).toHaveLength(1);
            ajax.last().succeed(SEARCH_RESPONSE);
            expect(rows()).toHaveLength(SEARCH_RESPONSE.items.length);
        });

        test('an empty query renders no row and makes no request', () => {
            makeInstance();

            search('');

            // #30.x.14 bug 2.1: a click into an empty field must open
            // something, i.e. the PANEL - a plain `response([])` here is
            // indistinguishable from "not a dropdown at all", which was the
            // live complaint. TWO-25326 §1's separate focus-hint row and the
            // too-short row it later merged into are BOTH gone now (TWO-40
            // follow-up): the empty query renders no row at all, and the
            // requirement lives in the query field's placeholder. Still not a
            // real search, so no request goes out.
            expect(rows()).toHaveLength(0);
            expect(ajax.calls).toHaveLength(0);
        });

        test('whitespace alone is told to type more rather than searched for', () => {
            makeInstance();

            // Long enough to clear the threshold by raw length, but there is
            // nothing here the search could match on, so it must not go on the
            // wire.
            search('   ');

            expect(ajax.calls).toHaveLength(0);
            expect(rows()).toHaveLength(0);
        });
    });

    describe('the country-not-chosen row is not the failure row', () => {
        test('it carries its own class on the jQuery UI path', () => {
            // No resolvable ISO code, so no search can be made. The cause is the
            // buyer not having picked a country, which is not a failure of the
            // service - and the DOM must say which of the two it is.
            document.body.innerHTML = '';
            buildAddressForm({ country: null, countryId: '999' });

            const instance = makeInstance();
            search('exa');

            expect(ajax.calls).toHaveLength(0);
            // Exactly one row now: the manual-entry footer left the list
            // entirely in TWO-25326 §2 and is a real <button> outside the
            // scroll container, so every jQuery-UI-path row count here drops
            // by one.
            expect(rows()).toHaveLength(1);
            expect(rows().eq(0).text()).toBe(instance.getSelectCountryText());
            expect(rows().eq(0).hasClass('two-autocomplete-select-country')).toBe(true);
            expect(rows().eq(0).hasClass('two-autocomplete-unavailable')).toBe(false);
            // ...and the manual-entry control is still on offer, just not as a row.
            expect(shown($('.two-company-not-listed'))).toBe(true);
        });
    });

    /**
     * Every message row must LOOK like a message, whatever its cause.
     *
     * The row classes above are asserted by name, and that assertion passes just
     * as happily with a row that renders in body text under a pointer cursor and
     * takes jQuery UI's hover highlight - i.e. one that looks exactly like a
     * clickable company while being unselectable. Only the resolved style catches
     * that, which is why the shipped stylesheet is loaded here, and why the rules
     * are keyed on a class shared by every message row rather than on one cause.
     */
    describe('a message row is painted as a message', () => {
        let stylesheet;

        beforeEach(() => {
            stylesheet = installStylesheet('views/css/two.css');
        });

        afterEach(() => {
            if (stylesheet && stylesheet.parentNode) {
                stylesheet.parentNode.removeChild(stylesheet);
            }
        });

        // buildTooShortItem()/getTooShortText() are gone (TWO-40 follow-up) -
        // the length requirement no longer renders a row at all, so these two
        // now drive the paint check off the "unavailable" (search-failure)
        // message row instead. Still exercises the same generic
        // `.two-autocomplete-message` styling every message row shares -
        // that is what these tests are actually pinning.
        test('the unavailable row is muted and unclickable, exactly as any message row is', () => {
            makeInstance();

            search('exa');
            ajax.last().fail('timeout');
            const hint = rows().get(0);

            expect(rows().hasClass('two-autocomplete-message')).toBe(true);
            expect(rows().hasClass('two-autocomplete-unavailable')).toBe(true);
            expect(window.getComputedStyle(hint).color).toBe('rgb(136, 136, 136)');
            expect(window.getComputedStyle(hint).cursor).toBe('default');

            // The wrapper div is where jQuery UI puts the text, and where the
            // module's own generic row rules paint body-text colour `!important`.
            const wrapper = rows().children('div').get(0);
            expect(window.getComputedStyle(wrapper).color).toBe('rgb(136, 136, 136)');
            expect(window.getComputedStyle(wrapper).cursor).toBe('default');

            // The same row rendered for the no-country cause, for comparison:
            // the two must be indistinguishable in appearance, since the whole
            // point of the per-cause class is that it changes the WORDING and
            // the DOM identity, not the look.
            const otherCause = $('<li>')
                .addClass('two-autocomplete-message two-autocomplete-select-country')
                .appendTo(document.body)
                .get(0);
            expect(window.getComputedStyle(hint).color)
                .toBe(window.getComputedStyle(otherCause).color);
            expect(window.getComputedStyle(hint).cursor)
                .toBe(window.getComputedStyle(otherCause).cursor);
        });

        test('the hover highlight is suppressed on the row jQuery UI would highlight', () => {
            makeInstance();
            search('exa');
            ajax.last().fail('timeout');

            // `ui-state-active` is what jQuery UI's menu puts on the wrapper of
            // the row under the pointer. On a message row that highlight is a
            // promise the row cannot keep, so the stylesheet has to out-rank it.
            const wrapper = rows().children('div').addClass('ui-state-active').get(0);
            const painted = window.getComputedStyle(wrapper);

            expect(painted.color).toBe('rgb(136, 136, 136)');
            expect(painted.cursor).toBe('default');
            expect(painted.margin).toBe('0px');
            // The highlight the generic wrapper rule paints, declared
            // `!important` there, so this row's rules have to out-rank it.
            expect(painted.backgroundColor).not.toBe('rgb(248, 249, 250)');
        });

        test('the country-not-chosen row gets the same paint', () => {
            document.body.innerHTML = '';
            buildAddressForm({ country: null, countryId: '999' });
            makeInstance();

            search('exa');

            expect(rows().hasClass('two-autocomplete-message')).toBe(true);
            expect(window.getComputedStyle(rows().get(0)).color).toBe('rgb(136, 136, 136)');
        });
    });
});

/**
 * TWO-25288 element 5, rearchitected by TWO-25326 §2. The manual-entry
 * affordance on the jQuery UI path.
 *
 * It used to be a pseudo-ROW that entered through the same `source` callback
 * the message rows use, with the treatment inverted so jQuery UI's menu would
 * navigate to it. §2 took it out of the list entirely: it is a real
 * `<button class="two-company-not-listed">`, a SIBLING of the scroll container,
 * so it is reachable without scrolling past up to 50 results and the cursor
 * keys - which only ever move within the list - cannot reach it.
 *
 * Every assertion below therefore moved from "the last row of the menu" to
 * "the button next to the menu", and every jQuery-UI-path row count in this
 * file dropped by one.
 */
describe('the manual-entry affordance on the jQuery UI path (TWO-25326 §2)', () => {
    const AT_THRESHOLD = 'a'.repeat(3);

    function search(term) {
        // The panel has to be open before there is a query field at all; see
        // the identical helper in the hints suite above for why the search is
        // driven synchronously rather than by typing.
        openPanel();
        const field = searchInput();
        // Bootstrapped-guard: without the widget bound, every assertion below
        // would pass vacuously against an untouched DOM.
        expect(field.hasClass('ui-autocomplete-input')).toBe(true);
        expect(field.autocomplete('instance')).toBeTruthy();
        field.val(term);
        field.autocomplete('instance').search(term);
        return field;
    }

    function rows() {
        return $('ul.ui-autocomplete li');
    }

    /** The affordance itself: a real <button>, no longer a row (§2). */
    function notListed() {
        return $('.two-company-not-listed');
    }

    /**
     * Activate it the way a buyer does. There is no `select` handler to call
     * any more - the button owns its own click handler, which is the whole
     * mechanism.
     */
    function chooseManualEntry() {
        notListed().trigger('click');
    }

    function menu() {
        return $('ul.ui-autocomplete');
    }

    test('the threshold this suite types against is the shipped one', () => {
        // The literal above is only safe while it agrees with the constant.
        expect(TwoCompanySearch.MIN_SEARCH_LENGTH).toBe(AT_THRESHOLD.length);
    });

    test('it is present, and is not a row in the list at all', () => {
        const instance = makeInstance();

        search(AT_THRESHOLD);
        ajax.last().succeed(SEARCH_RESPONSE);

        // Only the real results are in the list now.
        expect(rows()).toHaveLength(SEARCH_RESPONSE.items.length);
        expect(rows().hasClass('two-autocomplete-manual-entry')).toBe(false);
        // The affordance is a real button, outside the scroll container and a
        // SIBLING of it, so it is reachable without scrolling past 50 results.
        expect(notListed()).toHaveLength(1);
        expect(notListed().prop('tagName')).toBe('BUTTON');
        expect(notListed().attr('type')).toBe('button');
        // Nested one level deeper since TWO-40's three-chip mode selector -
        // a sibling of the other two chips inside `.two-company-mode-chips`,
        // which is itself a direct child of the panel and a sibling of the
        // results container, painted after it.
        expect(notListed().parent().is('.two-company-mode-chips')).toBe(true);
        expect(notListed().parent().parent().is('.two-company-dropdown')).toBe(true);
        expect(notListed().parent().prev().is('.two-company-dropdown__results')).toBe(true);
        expect(notListed().text()).toBe(instance.getManualEntryText());
        expect(instance.getManualEntryText()).toBe('Enter manually');
        expect(shown(notListed())).toBe(true);
    });

    test('it is there when the search found nothing at all', () => {
        const instance = makeInstance();

        search(AT_THRESHOLD);
        ajax.last().succeed({ items: [] });

        // Zero results is the state the affordance exists for. §1 requires the
        // list to say so explicitly rather than close silently, so the row that
        // shows is the "No matches found" one - and the button is still there
        // beside it.
        expect(rows()).toHaveLength(1);
        expect(rows().hasClass('two-autocomplete-no-matches')).toBe(true);
        expect(rows().text()).toBe(instance.getNoMatchesText());
        expect(shown(notListed())).toBe(true);
    });

    test('it is there alongside the failure row', () => {
        makeInstance();

        search(AT_THRESHOLD);
        ajax.last().fail('timeout');

        expect(rows()).toHaveLength(1);
        expect(rows().eq(0).hasClass('two-autocomplete-unavailable')).toBe(true);
        expect(shown(notListed())).toBe(true);
    });

    test('it is there alongside the country-not-chosen row', () => {
        document.body.innerHTML = '';
        buildAddressForm({ country: null, countryId: '999' });
        makeInstance();

        search(AT_THRESHOLD);

        expect(ajax.calls).toHaveLength(0);
        expect(rows()).toHaveLength(1);
        expect(rows().eq(0).hasClass('two-autocomplete-select-country')).toBe(true);
        expect(shown(notListed())).toBe(true);
    });

    test('it is offered before any search, not gated on the threshold', () => {
        makeInstance();

        // §2, verbatim: a buyer must have a route into manual entry without
        // typing a doomed query first. The WC regression recorded on
        // TWO-25326 was exactly this - gating the control on the 3-character
        // threshold removed it for a buyer who had typed nothing. That is the
        // deliberate REVERSAL of the pseudo-row's old below-threshold gating.
        search('');
        // No row renders below the threshold any more (TWO-40 follow-up) -
        // the assertion that matters here is that manual entry is offered
        // regardless.
        expect(rows()).toHaveLength(0);
        expect(shown(notListed())).toBe(true);

        search('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH - 1));
        expect(shown(notListed())).toBe(true);
    });

    test('it is NOT disabled and NOT aria-disabled, unlike every message row', () => {
        makeInstance();

        search(AT_THRESHOLD);
        ajax.last().fail('timeout');

        // The message row next to it, for contrast: `ui-state-disabled` is what
        // jQuery UI's own menu checks, so carrying it is what makes a row
        // keyboard-SKIPPED. The affordance must stay activatable.
        expect(rows().eq(0).hasClass('ui-state-disabled')).toBe(true);
        expect(rows().eq(0).attr('aria-disabled')).toBe('true');

        expect(notListed().hasClass('ui-state-disabled')).toBe(false);
        expect(notListed().attr('aria-disabled')).toBeUndefined();
        expect(notListed().prop('disabled')).toBe(false);
    });

    test('the cursor keys cannot reach it, and the results list is not a tab stop', () => {
        makeInstance();

        search(AT_THRESHOLD);
        ajax.last().succeed(SEARCH_RESPONSE);

        // §2's structural claim, pinned through the widget's OWN accounting:
        // `ui-menu-item` is what refresh() puts on the rows it will move focus
        // through, and the button is not one of them because it is not in the
        // list. Asserting only on our own classes would pass just as happily
        // for a button the menu had adopted.
        const navigable = menu().find('li.ui-menu-item');
        expect(navigable).toHaveLength(SEARCH_RESPONSE.items.length);
        expect(navigable.filter('.two-company-not-listed')).toHaveLength(0);
        expect(menu().find('.two-company-not-listed')).toHaveLength(0);
        // §2/§4: jQuery UI's menu puts `tabindex="0"` on its own <ul>, which
        // would make the scroll container a tab stop of its own and land Tab
        // there instead of on this button. That is the Hyva defect logged on
        // this ticket.
        expect(menu().attr('tabindex')).toBe('-1');
    });

    test('activating it runs the action and writes nothing into either field', () => {
        const instance = makeInstance();

        const query = search(AT_THRESHOLD);
        ajax.last().succeed(SEARCH_RESPONSE);
        expect(instance._manualEntry).toBe(false);

        chooseManualEntry();

        expect(instance._manualEntry).toBe(true);
        // The affordance's own wording must never land in the company-name
        // field - the defect the old row's `select`-returns-false guarded.
        expect(liveField().val()).toBe('');
        expect(liveField().val()).not.toBe(instance.getManualEntryText());
        // And the query field is cleared by the close, not left holding a term.
        expect(query.val()).toBe('');
    });

    test('choosing it closes the dropdown and stops the search opening another', () => {
        const instance = makeInstance();

        search(AT_THRESHOLD);
        ajax.last().succeed(SEARCH_RESPONSE);
        expect(shown(panel())).toBe(true);

        chooseManualEntry();

        expect(shown(panel())).toBe(false);

        // In manual entry the company-name field is a plain text input again,
        // so the click that used to open the panel must do nothing.
        const callsBefore = ajax.calls.length;
        liveField().trigger('mousedown');

        expect(ajax.calls).toHaveLength(callsBefore);
        expect(shown(panel())).toBe(false);
    });

    test('the reverse link appears as a real button and switches back', () => {
        const instance = makeInstance();

        search(AT_THRESHOLD);
        ajax.last().succeed(SEARCH_RESPONSE);
        expect($('.two-company-search-back')).toHaveLength(0);

        chooseManualEntry();

        const link = $('.two-company-search-back');
        expect(link).toHaveLength(1);
        // A <div> or an href-less <a> is not keyboard reachable; a button is,
        // with no tabindex or keydown bridge of our own.
        expect(link.prop('tagName')).toBe('BUTTON');
        // Inside PrestaShop's own address form, a default-type button submits it.
        expect(link.attr('type')).toBe('button');
        expect(link.text()).toBe('Search for company');

        link.trigger('click');

        expect(instance._manualEntry).toBe(false);
        expect($('.two-company-search-back')).toHaveLength(0);
        // Back in search mode with the panel open and focused, and the state
        // of whatever the query field holds already painted rather than a
        // blank box (§3 routes through openDropdown() for exactly that). The
        // query field opens EMPTY by design now - it is deliberately not
        // re-seeded from the confirmed name - so what is on screen is the
        // too-short state, which renders no row any more (TWO-40 follow-up).
        expect(shown(panel())).toBe(true);
        expect(document.activeElement).toBe(searchInput().get(0));
        expect(rows()).toHaveLength(0);
        expect(shown(notListed())).toBe(true);
    });

    test('#30.x.14: clicking the reverse link fires exactly one search, not two', () => {
        // Round-1 adversarial review finding (Vader): exitManualEntryMode()
        // used to BOTH `.trigger('focus')` (which the then-ungated
        // `focus.twoCompanyOpen` handler treated as a fresh open) AND make
        // its own explicit `autocomplete('search', term)` call - firing the
        // search twice on every click. A raw ajax-call count can't tell the
        // two apart: the result cache created for this same term by the
        // search below serves BOTH calls with zero network requests either
        // way, whether the fix landed or not. Spying on the one shared call
        // site (openSearchForCurrentTerm()) is what actually distinguishes
        // them.
        const instance = makeInstance();

        search(AT_THRESHOLD);
        ajax.last().succeed(SEARCH_RESPONSE);
        chooseManualEntry();

        const spy = jest.spyOn(instance, 'openSearchForCurrentTerm');
        $('.two-company-search-back').trigger('click');

        expect(spy).toHaveBeenCalledTimes(1);
        spy.mockRestore();
    });

    test('#30.x.14 bug 2.5: clicking it does not bubble into an ancestor handler (accordion-toggle regression)', () => {
        const instance = makeInstance();

        search(AT_THRESHOLD);
        ajax.last().succeed(SEARCH_RESPONSE);
        chooseManualEntry();

        const link = $('.two-company-search-back');
        expect(link).toHaveLength(1);

        // Stands in for the checkout theme's own delegated accordion-toggle
        // handler on the address step container - live, this click bubbled up
        // into whatever collapses that step and closed the whole address
        // section the buyer was mid-edit in.
        let ancestorClicks = 0;
        link.closest('.js-address-form').on('click', () => { ancestorClicks += 1; });

        link.trigger('click');

        expect(ancestorClicks).toBe(0);
        // The button's own behaviour must still run - this is stopPropagation,
        // not a swallowed click.
        expect(instance._manualEntry).toBe(false);
    });

    test('a country change while in manual entry does not strand the buyer', () => {
        const instance = makeInstance();

        search(AT_THRESHOLD);
        ajax.last().succeed(SEARCH_RESPONSE);
        chooseManualEntry();
        expect($('.two-company-search-back')).toHaveLength(1);

        // PrestaShop replaces the address form for something as ordinary as a
        // country change, taking the link's node with it. setupAutocomplete()
        // re-runs on the same instance, and has to put the link back.
        replaceAddressForm({ country: 'GB' });
        expect($('.two-company-search-back')).toHaveLength(0);
        instance.setupAutocomplete();

        expect($('.two-company-search-back')).toHaveLength(1);
    });

    test('the country SELECT firing change does not wipe a hand-typed company in manual entry', () => {
        // Doug's ruling (TWO-40 follow-up): "the ONLY time that a country
        // change should not wipe company details is if the control is in
        // manual entry mode." Before this, the listener wiped
        // unconditionally - a hand-typed name and its dni went with it.
        const instance = makeInstance();

        search(AT_THRESHOLD);
        ajax.last().succeed(SEARCH_RESPONSE);
        chooseManualEntry();

        liveField().val('My Own Trading Name');
        $("input[name='dni']").val('12345678');

        document.querySelector("select[name='id_country']")
            .dispatchEvent(new window.Event('change'));

        expect(liveField().val()).toBe('My Own Trading Name');
        expect($("input[name='dni']").val()).toBe('12345678');
        expect(instance._manualEntry).toBe(true);
    });

    test('both strings come from the translation dictionary when one is supplied', () => {
        const saved = window.twopayment;
        window.twopayment = {
            i18n: {
                company_search_manual_entry: 'Mi empresa no está en la lista',
                company_search_back_to_search: 'Buscar empresa'
            }
        };
        try {
            makeInstance();
            search(AT_THRESHOLD);
            ajax.last().succeed(SEARCH_RESPONSE);

            expect(notListed().text()).toBe('Mi empresa no está en la lista');

            chooseManualEntry();
            expect($('.two-company-search-back').text()).toBe('Buscar empresa');
        } finally {
            window.twopayment = saved;
        }
    });

    test('the decoration rows are not cached, so a cache hit does not stack two of them', () => {
        // Same invariant, one row over: the manual-entry row left the list, but
        // the "No matches found" row that replaced it is decoration owned by
        // the render in exactly the same way. Caching it would put a second
        // copy in the list on the next cache hit, which is the defect this
        // test has always been about.
        const instance = makeInstance();

        search(AT_THRESHOLD);
        ajax.last().succeed({ items: [] });
        expect(rows().filter('.two-autocomplete-no-matches')).toHaveLength(1);

        // Same term again: served from the cache, no second request.
        const callsBefore = ajax.calls.length;
        search('');
        search(AT_THRESHOLD);

        expect(ajax.calls).toHaveLength(callsBefore);
        expect(rows()).toHaveLength(1);
        expect(rows().filter('.two-autocomplete-no-matches')).toHaveLength(1);
        // And exactly one affordance, not one per render.
        expect(notListed()).toHaveLength(1);
        expect(instance._manualEntry).toBe(false);
    });

    /**
     * "My company is not on the list" is a statement that the selected company is
     * WRONG, so the selection has to go — on the browser and on the server.
     *
     * The server-side resolver consults the session company FIRST, ahead of the
     * address, and discards it only on a country mismatch or an address switch —
     * never on the company name changing. So clearing the hidden field alone
     * leaves the buyer looking at an empty field while the order still carries the
     * company they have just disowned.
     */
    describe('choosing it forgets the selected company', () => {
        const OI_URL = '/module/twopayment/orderintent';

        function withEndpoint(run) {
            const saved = window.twopayment;
            window.twopayment = { order_intent_url: OI_URL, ajax_token: 'tok' };
            try {
                run();
            } finally {
                window.twopayment = saved;
            }
        }

        function callsFor(action) {
            return ajax.calls.filter(
                (call) => call.settings.data && call.settings.data.action === action
            );
        }

        /**
         * Complete a selection the way a buyer's click does, through jQuery UI's
         * own menu and therefore through onCompanySelected().
         *
         * Deliberately NOT `organizationField.val(...)` by hand. A selection
         * writes TWO places - the hidden organisation field and `dni` - and a
         * hand-set stand-in reaches only the first, so the address identifier
         * stays empty and every assertion about what a clear does to it passes
         * vacuously. That is exactly how the disowned organisation number
         * survived into the order payload unnoticed.
         */
        function selectFirstCompany() {
            const widget = searchInput().autocomplete('instance');
            const row = widget.menu.element.children('li').first();
            widget.menu.focus(null, row);
            widget.menu.select($.Event('click'));
        }

        /**
         * `triggerHandler` rather than `trigger`: the pre-submit sync is bound to
         * the form itself so both reach it, but `trigger` also runs the native
         * default action, which jsdom answers with a "not implemented" dump.
         */
        function submitForm() {
            $('form').triggerHandler('submit');
        }

        test('the hidden organisation number and its company tag are dropped', () => {
            withEndpoint(() => {
                const instance = makeInstance();
                search(AT_THRESHOLD);
                ajax.last().succeed(SEARCH_RESPONSE);

                selectFirstCompany();
                expect(instance.organizationField.val()).toBe('12345678');
                expect(instance.organizationField.attr('data-two-company-name'))
                    .toBe('Example Trading Ltd');

                // Driven through the API rather than by clicking the button:
                // §2 HIDES that button once hasConfirmedSelection() is true, which
                // is precisely the state every test in this block sets up. The
                // action itself is what is under test here, not the affordance.
                instance.enterManualEntryMode();

                expect(instance.organizationField.val()).toBe('');
                expect(instance.organizationField.attr('data-two-company-name')).toBeUndefined();
            });
        });

        /**
         * The organisation number is what decides WHO gets credit-checked and
         * invoiced, so a buyer who disowns a company and types their own name
         * must not leave that company's number anywhere the server can read it.
         * `dni` is read off the saved address by the server's own resolver,
         * independently of the session company, so leaving it behind makes the
         * typed name cosmetic.
         */
        test('the identification number the lookup wrote is dropped too', () => {
            withEndpoint(() => {
                const instance = makeInstance();
                search(AT_THRESHOLD);
                ajax.last().succeed(SEARCH_RESPONSE);

                selectFirstCompany();
                // Guard: with this empty the assertion below could not fail.
                expect($("input[name='dni']").val()).toBe('12345678');

                instance.enterManualEntryMode();

                expect($("input[name='dni']").val()).toBe('');
            });
        });

        /**
         * The second half of the same defect. Clearing the hidden field is not
         * enough on its own, because the pre-submit sync adopts a `dni` with no
         * organisation number beside it as the organisation number - so a `dni`
         * left behind is re-adopted at submit and re-tagged with the name the
         * buyer has just typed, producing a credit check on one company under
         * the name of another.
         */
        test('the pre-submit sync does not re-adopt the disowned number', () => {
            withEndpoint(() => {
                const instance = makeInstance();
                search(AT_THRESHOLD);
                ajax.last().succeed(SEARCH_RESPONSE);
                selectFirstCompany();

                instance.enterManualEntryMode();
                // The buyer now types the company they actually are.
                instance.companyField.val('Unregistered Trading Name');

                submitForm();

                expect(instance.organizationField.val()).toBe('');
                expect(instance.organizationField.attr('data-two-company-name')).toBeUndefined();
                expect($("input[name='dni']").val()).toBe('');
            });
        });

        /**
         * The marker-match guard, pinned directly. A buyer who corrects the
         * identification number after selecting a company has replaced the
         * lookup's value with their own, which leaves the marker stale rather
         * than matching - so the value is theirs and must survive the clear.
         *
         * Without this case the clear could be a blanket one and every other
         * assertion here would still pass, because they all disown BEFORE
         * anything buyer-typed is in the field.
         */
        test('an identification number the buyer edited after selecting survives', () => {
            withEndpoint(() => {
                const instance = makeInstance();
                search(AT_THRESHOLD);
                ajax.last().succeed(SEARCH_RESPONSE);
                selectFirstCompany();
                expect($("input[name='dni']").val()).toBe('12345678');

                // The buyer corrects it by hand. Same field, different value, so
                // the marker no longer describes what is there.
                $("input[name='dni']").val('55554444');

                instance.enterManualEntryMode();

                expect($("input[name='dni']").val()).toBe('55554444');
                // And the VAT field was never the lookup's to write or clear.
                expect($("input[name='vat_number']").val()).toBe('');
            });
        });

        /**
         * The constraint that rules out a blanket clear: the pre-submit sync's
         * adoption of a buyer-typed identification number is legitimate and is
         * the only way a manual-entry buyer's own number reaches the Two flow.
         * Only what the LOOKUP wrote may be cleared.
         */
        test('an identification number the buyer typed is still adopted at submit', () => {
            withEndpoint(() => {
                const instance = makeInstance();
                search(AT_THRESHOLD);
                ajax.last().succeed(SEARCH_RESPONSE);
                selectFirstCompany();

                instance.enterManualEntryMode();
                instance.companyField.val('Unregistered Trading Name');
                $("input[name='dni']").val('99887766');

                submitForm();

                expect(instance.organizationField.val()).toBe('99887766');
                expect(instance.organizationField.attr('data-two-company-name'))
                    .toBe('Unregistered Trading Name');
            });
        });

        test('the session company is cleared through its OWN endpoint action', () => {
            withEndpoint(() => {
                const instance = makeInstance();
                search(AT_THRESHOLD);
                ajax.last().succeed(SEARCH_RESPONSE);

                instance.enterManualEntryMode();

                const cleared = callsFor('clearCompany');
                expect(cleared).toHaveLength(1);
                expect(cleared[0].settings.method).toBe('POST');
                expect(cleared[0].settings.data.token).toBe('tok');
                // NOT the save action carrying empty values: it rejects an empty
                // company id outright, so using it to clear is a silent no-op and
                // the stale session company survives.
                //
                // Asserted as a count over ALL calls, deliberately. Asserting
                // `cleared[0].action !== 'saveCompany'` would be unfalsifiable -
                // `cleared` is already filtered on the action being 'clearCompany',
                // so that comparison can never fail whatever the source does.
                expect(callsFor('saveCompany')).toHaveLength(0);
            });
        });

        test('no endpoint configured is tolerated rather than thrown', () => {
            const saved = window.twopayment;
            window.twopayment = undefined;
            try {
                const instance = makeInstance();
                search(AT_THRESHOLD);
                ajax.last().succeed(SEARCH_RESPONSE);
                instance.organizationField.val('12345678');

                instance.enterManualEntryMode();

                // The local half still has to happen.
                expect(instance.organizationField.val()).toBe('');
                expect(instance._manualEntry).toBe(true);
            } finally {
                window.twopayment = saved;
            }
        });
    });

    describe('focus goes where the buyer has to type next', () => {
        test('activating the row moves focus to the company field', () => {
            makeInstance();
            search(AT_THRESHOLD);
            ajax.last().succeed(SEARCH_RESPONSE);

            chooseManualEntry();

            // A keyboard user has just activated a control that the close has
            // taken off the page with the panel; leaving focus there strands
            // them silently, and a sighted mouse user would never notice the
            // regression. §2 requires it to land in the manual company-name
            // field, which is the thing they now have to type into.
            expect(document.activeElement).toBe(liveField().get(0));
        });

        test('leaving manual entry moves focus back to the company field', () => {
            makeInstance();
            search(AT_THRESHOLD);
            ajax.last().succeed(SEARCH_RESPONSE);
            chooseManualEntry();

            const link = $('.two-company-search-back');
            link.get(0).focus();
            expect(document.activeElement).toBe(link.get(0));

            link.trigger('click');

            // §3 routes the way back through openDropdown(), so focus lands in
            // the panel's QUERY field - the one thing on screen the buyer can
            // now type a search into - rather than on the readonly trigger.
            expect(document.activeElement).toBe(searchInput().get(0));
        });
    });

    test('a link orphaned by a previous instance does not leave two on the form', () => {
        makeInstance();
        search(AT_THRESHOLD);
        ajax.last().succeed(SEARCH_RESPONSE);
        chooseManualEntry();
        expect($('.two-company-search-back')).toHaveLength(1);

        // A second instance on the SAME field — the case the class-wide sweep
        // exists for. Its own reference is null, so only the sweep can find the
        // link the first instance left in the document.
        const second = makeInstance();
        search(AT_THRESHOLD);
        second.enterManualEntryMode();

        expect($('.two-company-search-back')).toHaveLength(1);
    });

    test('destroy takes the reverse link with it', () => {
        const instance = makeInstance();

        search(AT_THRESHOLD);
        ajax.last().succeed(SEARCH_RESPONSE);
        chooseManualEntry();
        expect($('.two-company-search-back')).toHaveLength(1);

        instance.destroy();

        expect($('.two-company-search-back')).toHaveLength(0);
    });
});

describe('the spinner always comes back down', () => {
    /**
     * Drive a search through the widget rather than calling searchCompanies()
     * directly, so jQuery UI's own `pending` counter and loading class are the
     * things under test.
     */
    function search(term) {
        // TWO-25326 §1: the widget lives on the panel's query field, so the
        // panel must be open first. Opened at most once: a second mousedown
        // re-runs openSearchForCurrentTerm(), inserting a search that would
        // shift every `ajax.calls[N]` index below.
        if (!shown(panel())) {
            openPanel();
        }
        const field = searchInput();
        field.val(term);
        field.autocomplete('instance').search(term);
        return field;
    }

    test('a successful search clears it', () => {
        makeInstance();
        const field = search('exa');
        expect(field.hasClass(LOADING_CLASS)).toBe(true);

        ajax.last().succeed(SEARCH_RESPONSE);

        expect(field.hasClass(LOADING_CLASS)).toBe(false);
    });

    test('a timeout clears it and shows the unavailable row', () => {
        makeInstance();
        const field = search('exa');

        ajax.last().fail('timeout');

        // The defect: a failure that never calls response() leaves `pending`
        // stuck above zero, so the spinner runs for the rest of the session.
        expect(field.hasClass(LOADING_CLASS)).toBe(false);
        // One row: the failure message. The manual-entry footer that used to
        // follow it left the list in TWO-25326 §2 and is a button beside the
        // scroll container now, so every row count on this path drops by one.
        const menu = $('ul.ui-autocomplete li');
        expect(menu).toHaveLength(1);
        expect(menu.eq(0).hasClass('two-autocomplete-unavailable')).toBe(true);
        expect(shown($('.two-company-not-listed'))).toBe(true);
    });

    test('an empty-but-healthy result clears it without an error row', () => {
        makeInstance();
        const field = search('exa');

        ajax.last().succeed({ items: [] });

        expect(field.hasClass(LOADING_CLASS)).toBe(false);
        expect($('ul.ui-autocomplete li.two-autocomplete-unavailable')).toHaveLength(0);
    });

    test('a degraded-and-empty response clears it and shows the unavailable row', () => {
        makeInstance();
        const field = search('exa');

        ajax.last().succeed({ items: [], degraded: true });

        expect(field.hasClass(LOADING_CLASS)).toBe(false);
        expect($('ul.ui-autocomplete li.two-autocomplete-unavailable')).toHaveLength(1);
    });

    test('a superseded search keeps the spinner up for the one that replaced it', () => {
        makeInstance();
        const field = search('exa');
        search('exam');

        // The first request is aborted by the second and settles as `silent`.
        // That must decrement `pending` (so the count stays balanced) without
        // dropping the class, which still belongs to the live request.
        expect(ajax.calls[0].aborted).toBe(true);
        expect(field.hasClass(LOADING_CLASS)).toBe(true);

        ajax.calls[1].succeed(SEARCH_RESPONSE);
        expect(field.hasClass(LOADING_CLASS)).toBe(false);
    });

    test('a superseded response does not repopulate the dropdown', () => {
        makeInstance();
        search('exa');
        ajax.calls[0].xhr.abort = function () {};
        search('exam');

        ajax.calls[1].succeed({ items: [] });
        ajax.calls[0].succeed(SEARCH_RESPONSE);

        // jQuery UI drops a superseded requestIndex itself — but only because
        // the callback is delivered rather than swallowed. The one row left is
        // the "No matches found" row the LIVE (empty) response rendered - that
        // is what §1 puts there in place of the old manual-entry footer; the
        // stale response's company row is the thing that must not be here.
        const menu = $('ul.ui-autocomplete li');
        expect(menu).toHaveLength(1);
        expect(menu.hasClass('two-autocomplete-no-matches')).toBe(true);
    });

    test('the source callback fires exactly once per meta shape', () => {
        makeInstance();
        const source = searchInput().autocomplete('option', 'source');

        [
            function () { ajax.last().succeed(SEARCH_RESPONSE); },
            function () { ajax.last().succeed({ items: [], degraded: true }); },
            function () { ajax.last().succeed(Object.assign({ degraded: true }, SEARCH_RESPONSE)); },
            function () { ajax.last().fail('timeout'); },
            function () { ajax.last().fail('abort'); }
        ].forEach(function (settle) {
            TwoCompanySearch._resultCache.clear();
            const rec = callbackRecorder();
            source({ term: 'exa' }, function (results) {
                rec.fn(results);
            });
            settle();
            expect(rec.calls).toHaveLength(1);
        });
    });

    test('a cache hit answers without a request, still exactly once', () => {
        makeInstance();
        const source = searchInput().autocomplete('option', 'source');
        const first = callbackRecorder();

        source({ term: 'exa' }, first.fn);
        ajax.last().succeed(SEARCH_RESPONSE);
        expect(first.calls).toHaveLength(1);

        const second = callbackRecorder();
        source({ term: 'exa' }, second.fn);

        expect(ajax.calls).toHaveLength(1);
        expect(second.calls).toHaveLength(1);
        expect(second.calls[0].results).toEqual(first.calls[0].results);
    });

    test('a degraded result set is not cached', () => {
        makeInstance();
        const source = searchInput().autocomplete('option', 'source');

        source({ term: 'exa' }, callbackRecorder().fn);
        ajax.last().succeed(Object.assign({ degraded: true }, SEARCH_RESPONSE));

        // Pinning a known-partial list for five minutes would keep serving it
        // after the upstream provider recovered.
        source({ term: 'exa' }, callbackRecorder().fn);
        expect(ajax.calls).toHaveLength(2);
    });

    test('an unavailable answer is not cached either', () => {
        makeInstance();
        const source = searchInput().autocomplete('option', 'source');

        source({ term: 'exa' }, callbackRecorder().fn);
        ajax.last().fail('timeout');

        // The service may well be healthy again by the next keystroke.
        source({ term: 'exa' }, callbackRecorder().fn);
        expect(ajax.calls).toHaveLength(2);
    });
});

describe('selecting a company through the real widget', () => {
    /**
     * Search, settle, then pick the first row the way a buyer's click does.
     *
     * Typed into the PANEL'S query field (TWO-25326 §1). The company-name
     * field is no longer the search box - it is what the selection WRITES
     * INTO, which is what these tests assert on, so the two must not be
     * conflated.
     */
    function selectFirstResult(term, response) {
        const query = openPanel();
        const instance = query.autocomplete('instance');
        query.val(term);
        instance.search(term);
        ajax.last().succeed(response);
        const row = instance.menu.element.children('li').first();
        instance.menu.focus(null, row);
        instance.menu.select($.Event('click'));
        return query;
    }

    test('the selection reaches the live fields', () => {
        makeInstance();

        selectFirstResult('exa', SEARCH_RESPONSE);

        // The happy path, driven end to end through jQuery UI rather than by
        // calling onCompanySelected() directly — otherwise the `select` option
        // could be unwired entirely and every direct-call test would still pass.
        expect(liveField().val()).toBe('Example Trading Ltd');
        // §1: a completed selection ends the search - the panel closes and
        // focus returns to the company-name field holding the picked name.
        expect(shown(panel())).toBe(false);
        expect(document.activeElement).toBe(liveField().get(0));
        expect($("input[name='companyid']").val()).toBe('12345678');
        expect($("input[name='companyid']").attr('data-two-company-name')).toBe(
            'Example Trading Ltd'
        );
        expect($("input[name='dni']").val()).toBe('12345678');
        // An organisation number is NOT a VAT number, so the selection must
        // leave the VAT field exactly as it found it.
        expect($("input[name='vat_number']").val()).toBe('');
        expect(
            $("input[name='vat_number']").attr('data-two-autofilled-value')
        ).toBeUndefined();
    });

    test('a company selection never writes into the VAT-number field', () => {
        makeInstance();
        // The buyer's own VAT number, already in the form. The selection path
        // overwrites the identifiers it owns unconditionally (a re-search has to
        // replace the previous company's number), so if the VAT field were still
        // on that list this value would be destroyed as well as falsified.
        $("input[name='vat_number']").val('GB123456789');

        selectFirstResult('exa', SEARCH_RESPONSE);

        expect($("input[name='dni']").val()).toBe('12345678');
        expect($("input[name='vat_number']").val()).toBe('GB123456789');
    });

    test('the pre-submit sync never writes into the VAT-number field', () => {
        const instance = makeInstance();
        selectFirstResult('exa', SEARCH_RESPONSE);
        // A re-render between selection and submit blanks the address inputs;
        // the submit hook restores what it owns, which is `dni` and nothing else.
        $("input[name='dni']").val('');
        $("input[name='vat_number']").val('');

        instance.syncOrganizationToAddressIdentifiers();

        expect($("input[name='dni']").val()).toBe('12345678');
        expect($("input[name='vat_number']").val()).toBe('');
    });

    test('selecting the unavailable row writes nothing into the field', () => {
        makeInstance();

        const query = selectFirstResult('exa', { items: [], degraded: true });

        // `_normalize()` rewrites the row's value as `value || label`, so without
        // the two_unavailable checks the message text itself lands in the field.
        // Neither field may receive it: not the query the buyer is still
        // typing, and above all not the company-name field the order is built
        // from.
        expect(query.val()).toBe('exa');
        expect(liveField().val()).toBe('');
        expect($("input[name='companyid']").val()).toBe('');
    });

    test('a company with only a lookup id fetches its details', async () => {
        makeInstance();

        selectFirstResult('exa', {
            items: [{ name: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }]
        });

        // Some registries (GB among them) return the organisation number only on
        // the detail endpoint, so the number has to arrive by that second call.
        expect(ajax.calls).toHaveLength(2);
        expect(ajax.last().url).toContain('/companies/v2/company/lookup-abc-123');
        ajax.last().succeed({
            national_identifier: { id: '87654321' },
            addresses: [
                {
                    type: 'BUSINESS',
                    street_address: '1 Example Street',
                    postal_code: 'EX1 1EX',
                    city: 'Exampleton'
                }
            ]
        });

        // fetchCompanyDetails() wraps the call in a Promise, so the fill lands
        // microtasks after the response rather than synchronously with it.
        await flushPromises();

        expect($("input[name='companyid']").val()).toBe('87654321');
        expect($("input[name='address1']").val()).toBe('1 Example Street');
        expect($("input[name='postcode']").val()).toBe('EX1 1EX');
        expect($("input[name='city']").val()).toBe('Exampleton');
    });

    test('the address-lookup toggle stops the detail fill but not companyid', async () => {
        makeInstance({ addressLookupEnabled: false });

        selectFirstResult('exa', {
            items: [{ name: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }]
        });
        ajax.last().succeed({
            national_identifier: { id: '87654321' },
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street' }]
        });

        await flushPromises();

        expect($("input[name='companyid']").val()).toBe('87654321');
        expect($("input[name='address1']").val()).toBe('');
        expect($("input[name='dni']").val()).toBe('');
    });
});

describe('the _renderItem patch does not nest', () => {
    test('it is applied once and survives repeated setup unchanged', () => {
        const search = makeInstance();
        const instance = searchInput().autocomplete('instance');
        const patched = instance._renderItem;

        expect(instance._twoRenderItemPatched).toBe(true);

        for (let i = 0; i < 100; i += 1) {
            search.setupAutocomplete();
        }

        // Identity is the assertion. Each re-wrap would produce a NEW function
        // closing over the previous one; that is the nesting that eventually
        // blew the stack, and nothing else about the widget would look wrong.
        expect(searchInput().autocomplete('instance')).toBe(instance);
        expect(instance._renderItem).toBe(patched);
    });

    test('the country-change listener re-setup does not nest it either', () => {
        makeInstance();
        const instance = searchInput().autocomplete('instance');
        const patched = instance._renderItem;
        const countrySelect = document.querySelector("select[name='id_country']");

        for (let i = 0; i < 20; i += 1) {
            countrySelect.dispatchEvent(new window.Event('change'));
        }

        expect(instance._renderItem).toBe(patched);
    });

    test('the updatedAddressForm handler does not nest it either', () => {
        makeInstance();
        const instance = searchInput().autocomplete('instance');
        const patched = instance._renderItem;

        for (let i = 0; i < 20; i += 1) {
            bus.emit('updatedAddressForm');
        }

        expect(instance._renderItem).toBe(patched);
    });

    test('rendering still works after many re-setups', () => {
        const search = makeInstance();
        for (let i = 0; i < 200; i += 1) {
            search.setupAutocomplete();
        }
        const instance = searchInput().autocomplete('instance');
        const ul = $('<ul></ul>');

        expect(() => {
            instance._renderItem(ul, { label: 'Example Trading Ltd', value: 'Example Trading Ltd' });
            instance._renderItem(ul, search.buildUnavailableItem());
        }).not.toThrow();
        expect(ul.children('li')).toHaveLength(2);
    });

    test('a genuinely new widget is patched again', () => {
        const first = makeInstance();
        const firstInstance = searchInput().autocomplete('instance');
        first.destroy();

        replaceAddressForm();
        makeInstance();
        const secondInstance = searchInput().autocomplete('instance');

        // The guard must be per-instance, not a global "already done" latch —
        // a destroyed-and-recreated widget carries no flag and needs the patch.
        expect(secondInstance).not.toBe(firstInstance);
        expect(secondInstance._twoRenderItemPatched).toBe(true);
    });

    test('the unavailable row is rendered non-selectable and as text', () => {
        const search = makeInstance();
        const instance = searchInput().autocomplete('instance');
        const ul = $('<ul></ul>');

        instance._renderItem(ul, { label: '<img src=x onerror=alert(1)>', two_unavailable: true });

        const li = ul.children('li');
        expect(li.hasClass('two-autocomplete-unavailable')).toBe(true);
        // `ui-state-disabled` is what jQuery UI's own menu checks, so the row is
        // skipped by keyboard navigation rather than merely refused on select.
        expect(li.hasClass('ui-state-disabled')).toBe(true);
        expect(li.attr('aria-disabled')).toBe('true');
        expect(li.find('img')).toHaveLength(0);
        expect(li.text()).toBe('<img src=x onerror=alert(1)>');

        expect(search.getSearchUnavailableText()).toContain('temporarily unavailable');
    });

    test('a normal row goes through jQuery UI own renderer', () => {
        makeInstance();
        const instance = searchInput().autocomplete('instance');
        const ul = $('<ul></ul>');

        instance._renderItem(ul, { label: 'Example Trading Ltd', value: 'Example Trading Ltd' });

        const li = ul.children('li');
        expect(li.hasClass('two-autocomplete-unavailable')).toBe(false);
        expect(li.text()).toBe('Example Trading Ltd');
    });

    test('select and focus refuse the unavailable row', () => {
        makeInstance();
        // The handlers are options of the widget, which lives on the query
        // field now - reading them off the company-name field throws.
        const query = searchInput();
        const item = { item: { two_unavailable: true, value: 'nope', label: 'nope' } };

        // `_normalize()` rewrites the item's value as `value || label`, so by the
        // time it reaches these handlers its value IS the message text. These
        // checks are the only thing keeping it out of the company field.
        expect(query.autocomplete('option', 'select')(null, item)).toBe(false);
        expect(query.autocomplete('option', 'focus')(null, item)).toBe(false);
        expect(query.val()).toBe('');
        expect(liveField().val()).toBe('');
    });
});

describe('a stale organisation number is cleared across a re-render', () => {
    /**
     * A re-render can leave `companyid` populated while `company` no longer
     * matches it — PrestaShop re-renders the form from server state, and the
     * hidden field is not part of the address it round-trips. Submitting that
     * pairing sends Two an organisation number for a different company.
     */
    function seed(company, companyid, tag) {
        buildAddressForm({ country: 'GB' });
        const form = document.querySelector('form');
        form.querySelector("input[name='company']").value = company;
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'companyid';
        hidden.value = companyid;
        if (tag !== undefined) {
            hidden.setAttribute('data-two-company-name', tag);
        }
        form.appendChild(hidden);
    }

    test('a matching pair is left alone', () => {
        seed('Example Trading Ltd', '12345678', 'Example Trading Ltd');
        makeInstance();

        expect($("input[name='companyid']").val()).toBe('12345678');
    });

    test('a differing name clears the number and its marker', () => {
        seed('Another Company Ltd', '12345678', 'Example Trading Ltd');
        makeInstance();

        expect($("input[name='companyid']").val()).toBe('');
        expect($("input[name='companyid']").attr('data-two-company-name')).toBeUndefined();
    });

    test('the comparison ignores case and whitespace', () => {
        seed('  EXAMPLE   TRADING   LTD ', '12345678', 'Example Trading Ltd');
        makeInstance();

        // Themes and server round-trips both reflow whitespace and casing; a
        // strict comparison would drop a valid selection on every re-render.
        expect($("input[name='companyid']").val()).toBe('12345678');
    });

    test('a number with no selection marker is treated as stale', () => {
        seed('Example Trading Ltd', '12345678');
        makeInstance();

        // No marker means nothing recorded that this number came from a company
        // the buyer picked, so it cannot be trusted to still belong to the name.
        expect($("input[name='companyid']").val()).toBe('');
    });

    test('an empty company name clears the number and its marker', () => {
        seed('', '12345678', 'Example Trading Ltd');
        makeInstance();

        expect($("input[name='companyid']").val()).toBe('');
        expect($("input[name='companyid']").attr('data-two-company-name')).toBeUndefined();
    });

    test('an empty company name also drops an empty selection marker', () => {
        seed('', '12345678', '');
        makeInstance();

        // The distinguishing case for the no-company branch: an empty marker is
        // falsy, so the untagged branch would otherwise catch this input first
        // and leave the marker attribute behind on the field.
        expect($("input[name='companyid']").val()).toBe('');
        expect($("input[name='companyid']").attr('data-two-company-name')).toBeUndefined();
    });

    test('editing the company name by hand clears the number', () => {
        seed('Example Trading Ltd', '12345678', 'Example Trading Ltd');
        makeInstance();
        const input = document.querySelector("input[name='company']");

        input.value = 'Example Trading Limited';
        input.dispatchEvent(new window.Event('input'));

        expect($("input[name='companyid']").val()).toBe('');
    });
});

describe('the organisation number reaches the address identifiers on submit', () => {
    /**
     * `triggerHandler` rather than `trigger`: the module's handler is bound to
     * the form itself so both reach it identically, but `trigger` also runs the
     * native default action, which jsdom answers with a "not implemented"
     * console dump.
     */
    function submitForm() {
        $('form').triggerHandler('submit');
    }

    test('submitting the form syncs companyid into the identifier fields', () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', organization_number: '12345678' }
        });
        $("input[name='dni']").val('');
        $("input[name='vat_number']").val('');

        submitForm();

        // The selection writes this too, but a re-render between selection and
        // submit can blank it; the submit hook is the last chance to restore.
        expect($("input[name='dni']").val()).toBe('12345678');
        // Never the VAT field - an organisation number is not a VAT number.
        expect($("input[name='vat_number']").val()).toBe('');
    });

    test('a value the buyer typed is not overwritten on submit', () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', organization_number: '12345678' }
        });
        $("input[name='dni']").val('buyer-typed');

        submitForm();

        // The submit sync is `onlyIfEmpty`: it fills a gap, it does not correct
        // the customer.
        expect($("input[name='dni']").val()).toBe('buyer-typed');
    });

    test('selecting a result with no organization_number and no lookup_id clears a PREVIOUS selection\'s number, not just its hint (adversarial review round 4, TWO-25326)', () => {
        // First selection: a real org number captured.
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', organization_number: '12345678' }
        });
        expect($("input[name='companyid']").val()).toBe('12345678');

        // Second selection: a DIFFERENT company, this result carries no
        // organization_number and no lookup_id (the else-branch previously
        // cleared only the visual hint, leaving the field itself - and its
        // data-two-company-name tag - holding the FIRST company's number).
        search.onCompanySelected(null, {
            item: { value: 'Second Company Ltd' }
        });

        expect($("input[name='companyid']").val()).toBe('');
        expect($("input[name='companyid']").attr('data-two-company-name')).toBeUndefined();
    });

    test('selecting a no-org-number result also clears the DNI residue the previous selection wrote (adversarial review round 5, TWO-25326)', () => {
        // Round 4 fixed organizationField/its tag but missed that
        // writeOrganizationToAddressIdentifiers() (called on the FIRST,
        // org-number selection below) also marks the DNI field as
        // autofilled with that number. setupAddressIdentifierSync()'s
        // submit-time sync would otherwise adopt that leftover marked DNI
        // value as the NEW company's org number, re-pairing it with the
        // wrong name at submit.
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', organization_number: '12345678' }
        });
        expect($("input[name='dni']").val()).toBe('12345678');

        search.onCompanySelected(null, {
            item: { value: 'Second Company Ltd' }
        });

        expect($("input[name='dni']").val()).toBe('');
    });

    test('a DNI the buyer typed becomes the org number when none was selected', () => {
        makeInstance();
        $("input[name='dni']").val('99999999');

        submitForm();

        // The customer's own input flowing INTO the Two flow, which is the
        // opposite direction to the lookup and so is not gated by the toggle.
        expect($("input[name='companyid']").val()).toBe('99999999');
    });
});

describe('the company-detail fill', () => {
    /** Select a lookup-id-only company and settle its detail response. */
    async function fillFrom(details, config) {
        const search = makeInstance(config);
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });
        ajax.last().succeed(details);
        await flushPromises();
        return search;
    }

    test.each([
        [{ national_identifier: { id: '1' } }, '1'],
        [{ nationalIdentifier: { id: '2' } }, '2'],
        [{ company: { national_identifier: { id: '3' } } }, '3'],
        [{ company: { nationalIdentifier: { id: '4' } } }, '4'],
        [{ registration_number: '5' }, '5'],
        [{ company: { company_number: '6' } }, '6']
    ])('reads the number out of %p', async (details, expected) => {
        // Detail payloads arrive in as many shapes as search results do, and the
        // detail number is the authoritative one for the countries that only
        // return it here.
        await fillFrom(details);

        expect($("input[name='companyid']").val()).toBe(expected);
        expect($("input[name='dni']").val()).toBe(expected);
        // The selection marker has to be written alongside the number. Without
        // it the next clearStaleOrganizationSelection() — any input/change event,
        // or the next re-render — reads the freshly fetched number as stale and
        // wipes it, which is the same defect the stale-selection block pins.
        expect($("input[name='companyid']").attr('data-two-company-name')).toBe(
            'Example Trading Ltd'
        );
    });

    test('a detail number overrides the one the search supplied', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: {
                value: 'Example Trading Ltd',
                organization_number: '11111111',
                lookup_id: 'lookup-abc-123'
            }
        });
        expect($("input[name='companyid']").val()).toBe('11111111');

        ajax.last().succeed({ national_identifier: { id: '87654321' } });
        await flushPromises();

        // The detail endpoint is the more authoritative source; a search result
        // that disagrees with it must not be the value that submits.
        expect($("input[name='companyid']").val()).toBe('87654321');
        expect($("input[name='dni']").val()).toBe('87654321');
    });

    test('a detail number equal to the search one changes nothing', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: {
                value: 'Example Trading Ltd',
                organization_number: '12345678',
                lookup_id: 'lookup-abc-123'
            }
        });
        $("input[name='dni']").val('buyer-typed');

        ajax.last().succeed({ national_identifier: { id: '12345678' } });
        await flushPromises();

        // No divergence means no re-write, so a value the buyer put in the DNI
        // field afterwards is left alone.
        expect($("input[name='companyid']").val()).toBe('12345678');
        expect($("input[name='dni']").val()).toBe('buyer-typed');
    });

    test('the detail request carries no credentials either', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });

        // A second cross-origin call with its own settings block — the search
        // endpoint's twin being right says nothing about this one.
        expect(ajax.last().settings.crossDomain).toBe(true);
        expect(ajax.last().settings.xhrFields).toEqual({ withCredentials: false });
        expect(ajax.last().settings.timeout).toBe(10000);
        ajax.last().succeed({});
        await flushPromises();
    });

    test('a partial address writes the parts it has', async () => {
        await fillFrom({
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street' }]
        });

        expect($("input[name='address1']").val()).toBe('1 Example Street');
        expect($("input[name='postcode']").val()).toBe('');
    });

    test('a partial address leaves a field the buyer had already filled alone', async () => {
        $("input[name='city']").val('Buyerton');

        await fillFrom({
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street' }]
        });

        // A company record missing a key is not evidence the buyer's own answer
        // is wrong. The key variants all coalesce to '' before the write, so
        // "absent" and "empty string" are the same thing here - which is why
        // this used to blank the field and why the fix keys off whether WE wrote
        // the current value rather than off the incoming shape.
        expect($("input[name='city']").val()).toBe('Buyerton');
        expect($("input[name='address1']").val()).toBe('1 Example Street');
    });

    test('a second company clears an address part the first one filled', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });
        ajax.last().succeed({
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street', city: 'Exampleton' }]
        });
        await flushPromises();
        expect($("input[name='city']").val()).toBe('Exampleton');

        // Same instance, second selection: the new company has no city. Leaving
        // the previous company's city behind is just as wrong as blanking the
        // buyer's own - it silently attributes one company's address to another.
        search.onCompanySelected(null, {
            item: { value: 'Second Trading Ltd', lookup_id: 'lookup-def-456' }
        });
        ajax.last().succeed({
            addresses: [{ type: 'BUSINESS', street_address: '2 Second Street' }]
        });
        await flushPromises();

        expect($("input[name='address1']").val()).toBe('2 Second Street');
        expect($("input[name='city']").val()).toBe('');
    });

    test('a buyer edit after an autofill survives the next company selection', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });
        ajax.last().succeed({
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street', city: 'Exampleton' }]
        });
        await flushPromises();

        // The buyer corrects the autofilled city by hand. That makes it buyer
        // input, so the marker recorded at fill time no longer matches and the
        // clearing arm above must not claim it.
        $("input[name='city']").val('Buyerton');

        search.onCompanySelected(null, {
            item: { value: 'Second Trading Ltd', lookup_id: 'lookup-def-456' }
        });
        ajax.last().succeed({
            addresses: [{ type: 'BUSINESS', street_address: '2 Second Street' }]
        });
        await flushPromises();

        expect($("input[name='city']").val()).toBe('Buyerton');
    });

    test('an autofilled value that the company repeats is still recognised as ours', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });
        ajax.last().succeed({
            addresses: [{ type: 'BUSINESS', city: 'Exampleton' }]
        });
        await flushPromises();

        // Second company repeats the same city, so there is no write to do. The
        // marker still has to be refreshed, or a third company without a city
        // would read the value as buyer-typed and keep it forever.
        search.onCompanySelected(null, {
            item: { value: 'Second Trading Ltd', lookup_id: 'lookup-def-456' }
        });
        ajax.last().succeed({ addresses: [{ type: 'BUSINESS', city: 'Exampleton' }] });
        await flushPromises();

        search.onCompanySelected(null, {
            item: { value: 'Third Trading Ltd', lookup_id: 'lookup-ghi-789' }
        });
        ajax.last().succeed({ addresses: [{ type: 'BUSINESS', street_address: '3 Third Street' }] });
        await flushPromises();

        expect($("input[name='city']").val()).toBe('');
    });

    test('a company confirming a value the buyer typed still claims it', async () => {
        // The load-bearing case for recording the marker OUTSIDE the write
        // branch: there is nothing to write, because the company's city is the
        // one the buyer already typed. Record it anyway - the fill has claimed
        // that value for this company, so the next company that lacks a city
        // must be able to clear it. With the marker recorded only on write, this
        // reads as untouched buyer input and the city sticks to the wrong
        // company for the rest of checkout.
        $("input[name='city']").val('Exampleton');

        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });
        ajax.last().succeed({ addresses: [{ type: 'BUSINESS', city: 'Exampleton' }] });
        await flushPromises();
        expect($("input[name='city']").val()).toBe('Exampleton');

        search.onCompanySelected(null, {
            item: { value: 'Second Trading Ltd', lookup_id: 'lookup-def-456' }
        });
        ajax.last().succeed({ addresses: [{ type: 'BUSINESS', street_address: '2 Second Street' }] });
        await flushPromises();

        expect($("input[name='city']").val()).toBe('');
    });

    test('clearing a stale autofill notifies the theme', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });
        ajax.last().succeed({ addresses: [{ type: 'BUSINESS', city: 'Exampleton' }] });
        await flushPromises();

        const events = [];
        $("input[name='city']").on('input change', (e) => events.push(e.type));

        search.onCompanySelected(null, {
            item: { value: 'Second Trading Ltd', lookup_id: 'lookup-def-456' }
        });
        ajax.last().succeed({ addresses: [{ type: 'BUSINESS', street_address: '2 Second Street' }] });
        await flushPromises();

        // Themes bind validation and their own state to these; a cleared field
        // that never announced itself leaves the theme showing the old value as
        // still valid.
        expect(events).toEqual(['input', 'change']);
    });

    test('a filled address field notifies the theme', async () => {
        const events = [];
        $("input[name='address1']").on('input change', (e) => events.push(e.type));

        await fillFrom({
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street' }]
        });

        // PrestaShop's own checkout scripts listen on these; a silent val() write
        // fills the box without the theme noticing the address changed.
        expect(events).toEqual(['input', 'change']);
    });

    test('an address field already holding the value is not rewritten', async () => {
        $("input[name='address1']").val('1 Example Street');
        const events = [];
        $("input[name='address1']").on('input change', (e) => events.push(e.type));

        await fillFrom({
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street' }]
        });

        expect(events).toEqual([]);
    });

    test('a business address wins over a mailing one whatever the order', async () => {
        await fillFrom({
            addresses: [
                { type: 'MAILING', street_address: 'Wrong Street', city: 'Wrongton' },
                { type: 'BUSINESS', street_address: '1 Example Street', city: 'Exampleton' }
            ]
        });

        expect($("input[name='address1']").val()).toBe('1 Example Street');
        expect($("input[name='city']").val()).toBe('Exampleton');
    });

    test.each([['REGISTERED'], ['VISITING'], ['BUSINESS']])(
        'a %s address is preferred over an untyped first entry',
        async (type) => {
            await fillFrom({
                addresses: [
                    { street_address: 'Wrong Street' },
                    { type: type, street_address: '1 Example Street' }
                ]
            });

            expect($("input[name='address1']").val()).toBe('1 Example Street');
        }
    );

    test('the first address is used when none carries a preferred type', async () => {
        await fillFrom({
            addresses: [{ street_address: '1 Example Street' }, { street_address: 'Second Street' }]
        });

        expect($("input[name='address1']").val()).toBe('1 Example Street');
    });

    test.each([
        [{ streetAddress: 'x', postalCode: 'p', locality: 'c' }],
        [{ street: 'x', zip: 'p', city: 'c' }],
        [{ address_line_1: 'x', zip_code: 'p', city: 'c' }],
        [{ addressLine1: 'x', postal_code: 'p', city: 'c' }]
    ])('normalises the address key variant %p', async (address) => {
        await fillFrom({ addresses: [Object.assign({ type: 'BUSINESS' }, address)] });

        expect($("input[name='address1']").val()).toBe('x');
        expect($("input[name='postcode']").val()).toBe('p');
        expect($("input[name='city']").val()).toBe('c');
    });

    test('a failed detail lookup leaves the selection intact', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: {
                value: 'Example Trading Ltd',
                organization_number: '12345678',
                lookup_id: 'lookup-abc-123'
            }
        });
        ajax.last().fail('timeout');
        await flushPromises();

        // Address auto-fill is a convenience; losing it must not cost the buyer
        // the company they already picked.
        expect($("input[name='companyid']").val()).toBe('12345678');
        expect($("input[name='company']").val()).toBe('Example Trading Ltd');
    });
});

describe('a destroyed instance cannot act on the live DOM', () => {
    test('destroy drops the spinner classes', () => {
        const search = makeInstance();
        const field = liveField();
        field.addClass('ui-autocomplete-loading two-company-search-loading');

        search.destroy();

        expect(field.hasClass('two-company-search-input')).toBe(false);
        expect(field.hasClass('two-company-search-loading')).toBe(false);
        expect(field.hasClass(LOADING_CLASS)).toBe(false);
        expect(search._destroyed).toBe(true);
    });

    test('setupAutocomplete on a destroyed instance leaves the new field alone', () => {
        const search = makeInstance();
        search.destroy();
        const newField = replaceAddressForm();

        search.setupAutocomplete();

        // Before the guard, the zombie re-resolved the selector, found the SAME
        // live input the live instance uses, and re-bound the widget with its
        // own stale closures.
        expect($(newField).hasClass('two-company-search-input')).toBe(false);
        expect($(newField).hasClass('ui-autocomplete-input')).toBe(false);
        expect(search.companyField.get(0)).not.toBe(newField);
    });

    test('onCompanySelected on a destroyed instance writes nothing', () => {
        const zombie = makeInstance();
        zombie.destroy();
        replaceAddressForm();
        makeInstance();

        const result = zombie.onCompanySelected(null, {
            item: {
                value: 'Example Trading Ltd',
                organization_number: '12345678',
                lookup_id: 'lookup-abc-123'
            }
        });

        // The zombie's organizationField is the detached hidden input its own
        // init() created, so a write through it silently loses `companyid`.
        expect(result).toBe(false);
        expect($("input[name='company']").val()).toBe('');
        expect($("input[name='companyid']")).toHaveLength(1);
        expect($("input[name='companyid']").val()).toBe('');
        expect($("input[name='dni']").val()).toBe('');
        // ...and it must not have fired the company-details lookup either.
        expect(ajax.calls).toHaveLength(0);
    });

    test('the live instance in the same position does write companyid', () => {
        const zombie = makeInstance();
        zombie.destroy();
        replaceAddressForm();
        const live = makeInstance();

        // The mirror image of the test above: proof that the guard blocks only
        // the zombie, not the behaviour the buyer depends on.
        expect(
            live.onCompanySelected(null, {
                item: { value: 'Example Trading Ltd', organization_number: '12345678' }
            })
        ).toBe(true);
        expect($("input[name='company']").val()).toBe('Example Trading Ltd');
        expect($("input[name='companyid']").val()).toBe('12345678');
        expect($("input[name='companyid']").attr('data-two-company-name')).toBe(
            'Example Trading Ltd'
        );
        expect($("input[name='dni']").val()).toBe('12345678');
    });

    test('the address-lookup toggle gates the dni write but not companyid', () => {
        const live = makeInstance({ addressLookupEnabled: false });

        live.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', organization_number: '12345678' }
        });

        expect($("input[name='companyid']").val()).toBe('12345678');
        expect($("input[name='dni']").val()).toBe('');
        expect($("input[name='vat_number']").val()).toBe('');
    });

    test('setupCountryChangeListener on a destroyed instance binds nothing', () => {
        const search = makeInstance();
        search.destroy();
        replaceAddressForm();
        const countrySelect = document.querySelector("select[name='id_country']");

        search.setupCountryChangeListener(0);
        countrySelect.dispatchEvent(new window.Event('change'));

        // The listener from its own live lifetime is still on the object — what
        // must not happen is it being bound to the REPLACED select and re-running
        // setupAutocomplete() against the live field from there.
        expect(search._boundCountrySelector).not.toBe(countrySelect);
        expect($("input[name='company']").hasClass('two-company-search-input')).toBe(false);
        expect($("input[name='company']").hasClass('ui-autocomplete-input')).toBe(false);
    });

    test('the updatedAddressForm handler stands down once destroyed', () => {
        const search = makeInstance();
        search.destroy();
        const newField = replaceAddressForm();

        // `prestashop.on` has no `off`, so this handler outlives the instance.
        // The only available defence is for the callback to check the flag.
        expect(bus.handlerCount('updatedAddressForm')).toBe(1);
        const setup = jest.spyOn(search, 'setupAutocomplete');
        const listener = jest.spyOn(search, 'setupCountryChangeListener');
        try {
            bus.emit('updatedAddressForm');

            // Asserted on the guard itself as well as on the outcome: the two
            // methods below carry their own `_destroyed` checks, so an outcome-only
            // test cannot tell whether THIS guard is still there.
            expect(setup).not.toHaveBeenCalled();
            expect(listener).not.toHaveBeenCalled();
        } finally {
            setup.mockRestore();
            listener.mockRestore();
        }

        expect($(newField).hasClass('two-company-search-input')).toBe(false);
        expect($(newField).hasClass('ui-autocomplete-input')).toBe(false);
    });

    test('one instance registers the bus handler at most once', () => {
        makeInstance();
        expect(bus.handlerCount('updatedAddressForm')).toBe(1);

        for (let i = 0; i < 10; i += 1) {
            bus.emit('updatedAddressForm');
        }

        // The handler calls back into setupCountryChangeListener(), which used
        // to register another one — so the count DOUBLED per event and every
        // duplicate re-ran the whole re-setup.
        expect(bus.handlerCount('updatedAddressForm')).toBe(1);
    });

    test('a pending country-listener retry does not fire after destroy', () => {
        jest.useFakeTimers();
        try {
            document.body.innerHTML = "<form><input type='text' name='company' value='' /></form>";
            const search = makeInstance();
            expect(search._countryRetryTimeoutId).not.toBeNull();

            search.destroy();
            buildAddressForm({ country: 'GB' });
            jest.advanceTimersByTime(5000);

            // Left armed, it would resolve the country select against the LIVE
            // document up to 3s later and bind a dying instance's listener.
            expect(search._countryRetryTimeoutId).toBeNull();
            expect(search.countryListener).toBeNull();
        } finally {
            jest.useRealTimers();
        }
    });

    test('destroy unbinds the country change listener', () => {
        const search = makeInstance();
        const countrySelect = document.querySelector("select[name='id_country']");
        const setup = jest.spyOn(search, 'setupAutocomplete');

        search.destroy();
        countrySelect.dispatchEvent(new window.Event('change'));

        // A listener left bound leaks one live handler per address-form update,
        // each of which re-runs the whole re-setup for a dead instance.
        expect(setup).not.toHaveBeenCalled();
    });

    test('destroy unbinds from the selector it actually bound, not the default one', () => {
        // setupCountryChangeListener() picks the first of five fallback selectors.
        // Re-querying only `select[name='id_country']` in destroy() missed the
        // listener entirely on a theme matching one of the others.
        document.body.innerHTML = [
            '<form>',
            "  <input type='text' name='company' value='' />",
            '  <select class="js-country"><option value="17" data-iso-code="GB" selected>C</option></select>',
            '</form>'
        ].join('\n');
        const search = makeInstance();
        const countrySelect = document.querySelector('.js-country');

        expect(search._boundCountrySelector).toBe(countrySelect);
        const setup = jest.spyOn(search, 'setupAutocomplete');
        search.destroy();
        countrySelect.dispatchEvent(new window.Event('change'));

        expect(setup).not.toHaveBeenCalled();
    });

    test('the country change listener is de-duplicated on re-setup', () => {
        const search = makeInstance();
        const countrySelect = document.querySelector("select[name='id_country']");
        for (let i = 0; i < 10; i += 1) {
            search.setupCountryChangeListener(0);
        }
        const setup = jest.spyOn(search, 'setupAutocomplete');

        countrySelect.dispatchEvent(new window.Event('change'));

        // Without the removeEventListener that precedes each re-bind, ten
        // address-form updates stack ten handlers on the one select and every
        // country change re-runs the setup ten times.
        expect(setup).toHaveBeenCalledTimes(1);
    });

    test('destroy tears the custom dropdown down even if the widget release throws', () => {
        const savedUi = $.ui;
        $.ui = undefined;
        let search;
        try {
            search = makeInstance();
            expect(search._customAutocomplete).toBeTruthy();
            $.ui = savedUi;
            // jQuery UI's bridge throws on a foreign or half-initialised widget.
            // The custom teardown lives in its own try precisely so that cannot
            // skip it: it unbinds live listeners and clears the pending debounce,
            // and leaving those bound while the instance is marked destroyed is
            // the exact zombie the flag exists to stop.
            //
            // Aimed at the QUERY field, not the company-name field: that is
            // where the widget lives since TWO-25326 §1, so it is the
            // `hasClass` guard destroy() actually reaches on the bridge path.
            search._queryField.hasClass = () => {
                throw new Error('simulated jQuery UI bridge failure');
            };

            search.destroy();
        } finally {
            $.ui = savedUi;
        }

        expect(search._customAutocomplete).toBeNull();
        // The panel is removed in its own try for the same reason, so the
        // throw above must not have left it - with its live click/keydown
        // handlers - on the page.
        expect(panel()).toHaveLength(0);
        // Set last and unconditionally, outside both trys.
        expect(search._destroyed).toBe(true);
        expect(search.isInitialized).toBe(false);
    });

    test('destroy releases the widget from the field', () => {
        const search = makeInstance();
        // The widget lives on the PANEL'S query field since TWO-25326 §1, so
        // that is the node this has to be asserted against. Asserting on the
        // company-name field would pass vacuously - it can never carry a
        // widget any more, whatever destroy() does or fails to do.
        const query = searchInput().get(0);
        expect($(query).hasClass('ui-autocomplete-input')).toBe(true);

        search.destroy();

        expect($(query).hasClass('ui-autocomplete-input')).toBe(false);
        expect($(query).autocomplete('instance')).toBeUndefined();
        // ...and the panel it lived in goes with it.
        expect(panel()).toHaveLength(0);
    });

    test('moving to a replaced field releases the widget left on the old one', () => {
        const search = makeInstance();
        const oldField = liveField().get(0);
        // The widget is on the OUTGOING panel's query field (TWO-25326 §1),
        // not on the company-name node, so that is what has to be released.
        const oldQuery = searchInput().get(0);
        expect($(oldQuery).hasClass('ui-autocomplete-input')).toBe(true);
        const newField = replaceAddressForm();

        search.setupAutocomplete();

        // The old node stays detached deliberately — re-attaching it would make
        // the company selector match two inputs, which is not a shape PrestaShop
        // ever produces. Releasing the widget matters even so: jQuery UI binds
        // handlers on `document` in `_create` that dropping the element does
        // not unbind, so an abandoned widget leaks one set of them per
        // address-form re-render with nothing left holding a reference that
        // could clean it up.
        expect($(oldQuery).hasClass('ui-autocomplete-input')).toBe(false);
        expect($(oldQuery).autocomplete('instance')).toBeUndefined();
        expect($(oldField).hasClass('two-company-search-input')).toBe(false);
        // The outgoing panel goes with it, and exactly one live panel - the
        // new field's - is left on the page.
        expect(document.contains(oldQuery)).toBe(false);
        expect(panel()).toHaveLength(1);
        expect(searchInput().hasClass('ui-autocomplete-input')).toBe(true);
        expect($(newField).hasClass('two-company-search-input')).toBe(true);
        expect(search.companyField.get(0)).toBe(newField);
    });
});

/**
 * TWO-25288. The in-field spinner is the loader GIF, set as the company input's
 * own `background-image` and shown purely by the loading class the module already
 * puts on that input.
 *
 * Nothing in this suite covered that before, and "the class is set" is not the
 * same claim: the class was already asserted elsewhere while an unscoped
 * `!important` rule further down the stylesheet quietly out-ranked the scoped one
 * and painted a white background over the field. Only reading the resolved style
 * catches that, so these are the sole tests here that load a real stylesheet
 * (`installStylesheet()` in the harness).
 *
 * Both render paths are pinned deliberately. The jQuery UI path and the custom
 * fallback set different classes and share nothing but the CSS contract, so
 * covering one and assuming the other leaves half the surface untested with a
 * green suite.
 */
describe('the in-field spinner GIF', () => {
    let stylesheet;

    beforeEach(() => {
        // The real shipped stylesheet, so the rule under test is the one that
        // ships rather than a restatement of it.
        stylesheet = installStylesheet('views/css/two.css');
    });

    afterEach(() => {
        if (stylesheet && stylesheet.parentNode) {
            stylesheet.parentNode.removeChild(stylesheet);
        }
    });

    function styleOf(el) {
        return window.getComputedStyle(el);
    }

    /**
     * The spinner element (TWO-25326 §1).
     *
     * The GIF is no longer a `background-image` on an input at all. §1 wants it
     * "within and at the right hand end of the QUERY field", and a background
     * on the input cannot be positioned there reliably - where it lands depends
     * on whatever padding the merchant's theme gives text inputs. So it is a
     * real absolutely-positioned `<span>`, a SIBLING of the query field, shown
     * by the same loading classes as before through a `~` rule. The CSS
     * contract is what these tests are about, so they moved with it.
     */
    function spinner() {
        return $('.two-company-dropdown__spinner');
    }

    test('the asset the stylesheet asks for is actually in the repo', () => {
        // A rule naming a file that is not shipped resolves in jsdom exactly as
        // happily as one naming a file that is, so the URL assertions below would
        // all pass with the GIF deleted. This is the case that would not.
        const css = fs.readFileSync(path.join(REPO_ROOT, 'views/css/two.css'), 'utf8');
        const match = css.match(/\.two-company-dropdown__spinner\s*\{[\s\S]*?url\('([^']+)'\)/);
        expect(match).not.toBeNull();

        // Resolved the way a browser resolves it: relative to the stylesheet.
        const asset = path.resolve(path.join(REPO_ROOT, 'views/css'), match[1]);
        expect(fs.existsSync(asset)).toBe(true);

        // Animated, and the size the rule pins. A still image here would be a
        // spinner that never spins, which no CSS assertion can tell apart.
        const bytes = fs.readFileSync(asset);
        expect(bytes.slice(0, 6).toString('latin1')).toBe('GIF89a');
        expect(bytes.readUInt16LE(6)).toBe(16);
        expect(bytes.readUInt16LE(8)).toBe(16);
        // Frame count. A GIF with one image descriptor is a static picture; this
        // one must have several or it does not animate. Counted by walking the
        // block structure rather than by scanning for the image-descriptor
        // byte, which also occurs inside the colour table and the compressed
        // pixel data - a scan finds 'frames' in a single-frame file, so the
        // assertion below could never fail.
        expect(countGifFrames(bytes)).toBeGreaterThan(1);
    });

    test('nothing paints while the search is idle', () => {
        makeInstance();
        openPanel();

        // The spinner is gated on the loading class, so an idle search must
        // show nothing.
        expect(spinner()).toHaveLength(1);
        expect(styleOf(spinner().get(0)).display).toBe('none');
        // §7, and the reason this assertion INVERTED rather than moved: the
        // company-name field used to carry `padding-right: 32px`
        // unconditionally to reserve the spinner's lane and stop its text
        // reflowing as the GIF came and went. With the spinner gone from that
        // field the padding is 32px of dead space making it visibly unlike
        // every other input on the address form, which is what §7 forbids. The
        // lane is reserved on the QUERY field now, where the spinner actually
        // is.
        expect(styleOf(liveField().get(0)).paddingRight).not.toBe('32px');
        expect(styleOf(searchInput().get(0)).paddingRight).toBe('30px');
    });

    test('the scoped rule is the one that applies while loading, not an !important one', () => {
        makeInstance();
        const field = openPanel();

        field.val('exa');
        field.autocomplete('instance').search('exa');
        expect(field.hasClass(LOADING_CLASS)).toBe(true);

        // This is the substantive change. A second, unscoped
        // `.ui-autocomplete-loading` rule used to sit further down this
        // stylesheet declaring `background: white ... !important` and
        // `padding-right: 25px !important`. Being `!important` it out-ranked the
        // scoped rule whatever the specificity, so the field really did get a
        // white box painted over the merchant's theme and a 25px gutter while the
        // 32px one was reserved - and both rules named the same GIF, so the
        // spinner looked fine and nothing gave it away.
        //
        // Both values below flip if that rule comes back, which is why they are
        // asserted here, in the loading state, rather than on an idle field: the
        // removed rule was gated on the same class and an idle field cannot see
        // it.
        //
        // `background-size` is the proxy for the white box rather than
        // `background-color`, which cannot do the job: jsdom's own default
        // stylesheet already resolves every `input` to a white background, so
        // that assertion passes whatever this stylesheet says. The removed rule
        // used the `background` SHORTHAND, which resets the longhands it omits -
        // so `background-size` reverts to `auto` if it comes back, and the
        // spinner is drawn at whatever size the field gives it.
        //
        // Read off the spinner element rather than the input, because that is
        // where the GIF lives since §1 - but the failure mode is unchanged: a
        // `background` SHORTHAND declared anywhere that out-ranks this rule
        // resets the longhands it omits, so `background-size` reverts to
        // `auto` and the spinner is drawn at whatever size its box gives it.
        const painted = styleOf(spinner().get(0));
        expect(painted.display).toBe('block');
        expect(painted.backgroundSize).toBe('16px 16px');
        // The query field's own gutter, which keeps a long query from running
        // underneath the spinner, is reserved unconditionally - toggling it
        // with the spinner would reflow the text on every keystroke.
        expect(styleOf(field.get(0)).paddingRight).toBe('30px');
    });

    test('the GIF paints during a jQuery UI search, and stops after', () => {
        makeInstance();
        const field = openPanel();
        const gif = spinner().get(0);

        expect(styleOf(gif).display).toBe('none');

        field.val('exa');
        field.autocomplete('instance').search('exa');

        // jQuery UI puts `ui-autocomplete-loading` on the input it is bound
        // to - the query field now - and the stylesheet turns that into the
        // GIF on the sibling span, with no JS involvement at all.
        expect(field.hasClass(LOADING_CLASS)).toBe(true);
        const painted = styleOf(gif);
        expect(painted.display).toBe('block');
        expect(painted.backgroundImage).toContain('loader.gif');
        // Repeated across the box would tile the spinner; unpinned size would
        // let a themed box scale it.
        expect(painted.backgroundRepeat).toBe('no-repeat');
        expect(painted.backgroundSize).toBe('16px 16px');

        ajax.last().succeed(SEARCH_RESPONSE);

        expect(styleOf(gif).display).toBe('none');
    });

    describe('on the custom fallback path, where jQuery UI is absent', () => {
        let savedUi;

        beforeEach(() => {
            jest.useFakeTimers();
            savedUi = $.ui;
            $.ui = undefined;
        });

        afterEach(() => {
            jest.useRealTimers();
            $.ui = savedUi;
        });

        /**
         * Type into the PANEL'S query field, which is the search box on both
         * paths now, and run the fallback's own 300ms debounce out.
         */
        function type(term) {
            const input = searchInput().get(0);
            input.value = term;
            input.dispatchEvent(new window.Event('input'));
            jest.advanceTimersByTime(300);
            return input;
        }

        test('the GIF paints during a search and stops afterwards', () => {
            makeInstance();
            openPanel();
            const gif = spinner().get(0);

            expect(styleOf(gif).display).toBe('none');

            type('exa');

            // This path sets its own class by hand, on the same field, matched
            // by the same rule - one CSS contract, two arming mechanisms.
            expect(searchInput().hasClass('two-company-search-loading')).toBe(true);
            expect(styleOf(gif).display).toBe('block');
            expect(styleOf(gif).backgroundImage).toContain('loader.gif');

            ajax.last().succeed(SEARCH_RESPONSE);

            expect(styleOf(gif).display).toBe('none');
        });

        test('a failed search stops it too', () => {
            makeInstance();
            openPanel();
            const gif = spinner().get(0);
            type('exa');

            ajax.last().fail('timeout');

            expect(styleOf(gif).display).toBe('none');
        });
    });

    test('it still paints after an address-form re-render', () => {
        const search = makeInstance();
        replaceAddressForm();
        search.setupAutocomplete();

        // The re-render replaces the input, so the whole panel - spinner
        // included - has to be rebuilt against the replacement. A search with
        // no visible feedback is the failure this catches, and every
        // class-level assertion elsewhere passes while it is broken.
        const field = openPanel();
        expect(spinner()).toHaveLength(1);
        const gif = spinner().get(0);
        expect(styleOf(gif).display).toBe('none');

        field.val('exa');
        field.autocomplete('instance').search('exa');

        expect(styleOf(gif).display).toBe('block');
        expect(styleOf(gif).backgroundImage).toContain('loader.gif');

        ajax.last().succeed(SEARCH_RESPONSE);

        expect(styleOf(gif).display).toBe('none');
    });
});

describe('the custom fallback used when jQuery UI is absent', () => {
    const AT_THRESHOLD_FALLBACK = 'a'.repeat(3);
    let savedUi;

    beforeEach(() => {
        jest.useFakeTimers();
        savedUi = $.ui;
        // A theme can ship jQuery without jQuery UI, or load UI after this
        // module. Both take this path.
        $.ui = undefined;
    });

    afterEach(() => {
        jest.useRealTimers();
        $.ui = savedUi;
    });

    /**
     * Type into the PANEL'S query field and let the 300ms debounce elapse.
     *
     * TWO-25326 turned this path into an ENGINE only: the panel, the query
     * field, the scrollable results host and the "not on the list" button are
     * built by buildDropdown() and are identical on both paths. So the thing
     * typed into here is the same node the jQuery UI path types into, and the
     * two hand-rolled dropdowns that used to diverge are gone.
     *
     * Opened once: a second mousedown would re-run openSearchForCurrentTerm()
     * and insert a render of its own between the ones under test.
     */
    function type(term) {
        if (!shown(panel())) {
            openPanel();
        }
        const input = searchInput().get(0);
        input.value = term;
        input.dispatchEvent(new window.Event('input'));
        jest.advanceTimersByTime(300);
        return input;
    }

    /** Rows currently rendered into the shared results host. */
    function listRows() {
        return $('.two-company-dropdown__results li');
    }

    /** The "My company is not on the list" button (§2), shared by both paths. */
    function notListed() {
        return $('.two-company-not-listed');
    }

    test('it is the path taken, and it arms the fallback spinner', () => {
        const search = makeInstance();
        expect(searchInput().hasClass('ui-autocomplete-input')).toBe(false);
        expect(search._customAutocomplete).toBeTruthy();
        // The engine renders into the SHARED panel; it has no container of its
        // own any more, which is the whole point of the rework.
        expect(search._customAutocomplete.list)
            .toBe($('.two-company-dropdown__results').get(0));
        expect(search._customAutocomplete.inputEl).toBe(searchInput().get(0));

        type('exa');

        // Nothing paints the loading class for this path, so it sets it by
        // hand - on the query field, where the shared spinner rule reads it.
        expect(searchInput().hasClass('two-company-search-loading')).toBe(true);
    });

    describe('the company-search hints (TWO-25288)', () => {
        test('the company field carries no wording on this path either', () => {
            const search = makeInstance();

            // Bootstrapped-guard: this must be the fallback path, or the
            // assertion is really re-testing the jQuery UI one.
            expect(searchInput().hasClass('ui-autocomplete-input')).toBe(false);
            expect(search._customAutocomplete).toBeTruthy();
            expect(liveField().attr('placeholder')).toBeUndefined();
        });

        test('a sub-threshold term renders no row and fires no request (TWO-40 follow-up)', () => {
            const search = makeInstance();
            expect(search._customAutocomplete).toBeTruthy();

            type('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH - 1));

            // buildTooShortItem() is gone: the length requirement lives in the
            // query field's placeholder instead of a dropdown row.
            expect(listRows()).toHaveLength(0);
            expect(searchInput().attr('placeholder')).toBe(search.getQueryPlaceholderText());
            expect(shown(panel())).toBe(true);
            expect(ajax.calls).toHaveLength(0);
            // Nothing was requested, so nothing may leave a spinner running.
            expect(searchInput().hasClass('two-company-search-loading')).toBe(false);
            expect($('.two-autocomplete-unavailable')).toHaveLength(0);
        });

        test('a term at the threshold searches and shows results', () => {
            const search = makeInstance();
            expect(search._customAutocomplete).toBeTruthy();

            type('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH));

            expect(ajax.calls).toHaveLength(1);
            ajax.last().succeed(SEARCH_RESPONSE);
            expect(listRows()).toHaveLength(SEARCH_RESPONSE.items.length);
        });

        test('whitespace alone is told to type more rather than searched for', () => {
            const search = makeInstance();
            expect(search._customAutocomplete).toBeTruthy();

            type('   ');

            expect(ajax.calls).toHaveLength(0);
            expect(listRows()).toHaveLength(0);
        });

        test('clearing the field shows no row and makes no request, rather than leaving stale results', () => {
            // This path used to close the list outright when the field was
            // cleared; TWO-25326 §1 required the too-short state to stay on
            // screen rather than close, and TWO-40 removed the row that state
            // rendered entirely (see the query-field placeholder tests above).
            // What survives is that clearing the field must not leave the
            // PREVIOUS result set on screen.
            const search = makeInstance();
            type(AT_THRESHOLD_FALLBACK);
            ajax.last().succeed(SEARCH_RESPONSE);
            expect(listRows()).toHaveLength(SEARCH_RESPONSE.items.length);

            const callsBefore = ajax.calls.length;
            type('');

            expect(search._customAutocomplete).toBeTruthy();
            expect(listRows()).toHaveLength(0);
            expect(shown(panel())).toBe(true);
            expect(ajax.calls).toHaveLength(callsBefore);
        });
    });

    /**
     * TWO-25288 element 5, rearchitected by TWO-25326 §2, fallback path.
     *
     * The affordance used to be a row this path rendered for itself, with a
     * hand-rolled `role="button"` / `tabindex="0"` / Enter-Space bridge and its
     * own close timer, because the path had no keyboard model at all. Both are
     * gone: the control is the SAME real `<button>` the jQuery UI path uses,
     * built once by buildDropdown() outside the results host, and this path now
     * has real cursor-key navigation of its own bound to the query field. So
     * "it survives every renderer" is no longer a claim about re-appending a
     * row - the button is not in the list and no renderer can wipe it - but it
     * is still worth pinning per state, because §2 gates its VISIBILITY.
     */
    describe('the manual-entry affordance (TWO-25326 §2)', () => {
        const AT_THRESHOLD = AT_THRESHOLD_FALLBACK;

        test('the threshold this block types against is the shipped one', () => {
            expect(TwoCompanySearch.MIN_SEARCH_LENGTH).toBe(AT_THRESHOLD.length);
        });

        test('it is inside the mode-chip row, after the results host', () => {
            const search = makeInstance();
            expect(search._customAutocomplete).toBeTruthy();

            type(AT_THRESHOLD);
            ajax.last().succeed(SEARCH_RESPONSE);

            // Only companies in the list...
            expect(listRows()).toHaveLength(SEARCH_RESPONSE.items.length);
            expect(listRows().hasClass('two-autocomplete-manual-entry')).toBe(false);
            // ...and the affordance below it, outside the scroll container, so
            // it is reachable without scrolling past up to 50 results (§2).
            expect(notListed()).toHaveLength(1);
            expect(notListed().text()).toBe(search.getManualEntryText());
            // Nested inside the three-chip mode selector (TWO-40 design
            // revision): a sibling of "Sole Trader"/"Registered Company"
            // inside `.two-company-mode-chips`, which sits directly after the
            // results host - still outside the scroll container either way,
            // so still reachable without scrolling past up to 50 results (§2).
            const chips = notListed().parent();
            expect(chips.is('.two-company-mode-chips')).toBe(true);
            expect(chips.prev().is('.two-company-dropdown__results')).toBe(true);
        });

        test('it survives the zero-result render, and the panel stays open for it', () => {
            const search = makeInstance();
            expect(search._customAutocomplete).toBeTruthy();

            type(AT_THRESHOLD);
            ajax.last().succeed({ items: [] });

            // This path used to close the list outright on an empty result set.
            // §1 makes it say so explicitly instead, and the affordance is
            // still on offer beside the message - which is the state it exists
            // for.
            expect(listRows()).toHaveLength(1);
            expect(listRows().hasClass('two-autocomplete-no-matches')).toBe(true);
            expect(listRows().text()).toBe(search.getNoMatchesText());
            expect(shown(notListed())).toBe(true);
            expect(shown(panel())).toBe(true);
        });

        test('it survives the failure render', () => {
            const search = makeInstance();
            expect(search._customAutocomplete).toBeTruthy();

            type(AT_THRESHOLD);
            ajax.last().fail('timeout');

            expect(listRows()).toHaveLength(1);
            expect(listRows().eq(0).hasClass('two-autocomplete-unavailable')).toBe(true);
            expect(shown(notListed())).toBe(true);
        });

        test('it survives the country-not-chosen render', () => {
            document.body.innerHTML = '';
            buildAddressForm({ country: null, countryId: '999' });
            const search = makeInstance();
            expect(search._customAutocomplete).toBeTruthy();

            type(AT_THRESHOLD);

            expect(ajax.calls).toHaveLength(0);
            expect(listRows()).toHaveLength(1);
            expect(listRows().eq(0).hasClass('two-autocomplete-select-country')).toBe(true);
            expect(shown(notListed())).toBe(true);
        });

        test('it is offered before any search, not gated on the threshold', () => {
            // §2, verbatim, and the REVERSAL of the old row's gating: a buyer
            // must have a route into manual entry without typing a doomed query
            // first. Gating it on the 3-character threshold is the WC
            // regression recorded on TWO-25326.
            const search = makeInstance();
            expect(search._customAutocomplete).toBeTruthy();

            type('');
            // No row renders below the threshold any more (TWO-40 follow-up).
            expect(listRows()).toHaveLength(0);
            expect(shown(notListed())).toBe(true);

            type('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH - 1));
            expect(shown(notListed())).toBe(true);
        });

        test('it is a real button, focusable, and named by its own text', () => {
            const search = makeInstance();
            expect(search._customAutocomplete).toBeTruthy();

            type(AT_THRESHOLD);
            ajax.last().succeed(SEARCH_RESPONSE);

            const button = notListed();
            // A real <button>, so the browser supplies the role, the focus
            // behaviour and Enter/Space activation - none of which this path
            // has to hand-roll any more, and none of which can drift from the
            // jQuery UI path, because it is the same element.
            expect(button.prop('tagName')).toBe('BUTTON');
            // Inside PrestaShop's own address form, a default-type button submits it.
            expect(button.attr('type')).toBe('button');
            expect(button.attr('role')).toBeUndefined();
            expect(button.attr('tabindex')).toBeUndefined();
            // Its accessible name is its text content; nothing else supplies one.
            expect(button.text()).toBe('Enter manually');
            // NOT disabled and NOT aria-disabled, unlike the message rows.
            expect(button.prop('disabled')).toBe(false);
            expect(button.attr('aria-disabled')).toBeUndefined();
        });

        test('the cursor keys move through the results and cannot reach it', () => {
            // The keyboard model this path did NOT have before §1: a buyer
            // could open the panel, type, see results and have no way at all to
            // choose one. The rows are navigable; the button, being outside the
            // list, is not - which is the same contract the jQuery UI path has.
            const search = makeInstance();
            type(AT_THRESHOLD);
            ajax.last().succeed(SEARCH_RESPONSE);

            searchInput().trigger($.Event('keydown', { key: 'ArrowDown' }));

            const active = $('.two-autocomplete-item--active');
            expect(active).toHaveLength(1);
            expect(active.is('li.two-autocomplete-item')).toBe(true);
            expect(notListed().hasClass('two-autocomplete-item--active')).toBe(false);
            expect(search._manualEntry).toBe(false);
        });

        test('the pointer activates it', () => {
            const search = makeInstance();
            type(AT_THRESHOLD);
            ajax.last().succeed(SEARCH_RESPONSE);

            notListed().trigger('click');

            expect(search._manualEntry).toBe(true);
            expect(shown(panel())).toBe(false);
            expect($('.two-company-search-back')).toHaveLength(1);
        });

        test('while in manual entry, typing opens nothing and searches nothing', () => {
            const search = makeInstance();
            type(AT_THRESHOLD);
            ajax.last().succeed(SEARCH_RESPONSE);
            notListed().trigger('click');
            const callsBefore = ajax.calls.length;

            type(AT_THRESHOLD + 'more');

            expect(search._manualEntry).toBe(true);
            expect(ajax.calls).toHaveLength(callsBefore);
            expect(shown(panel())).toBe(false);
        });

        test('the reverse link switches back and re-opens the search', () => {
            const search = makeInstance();
            type(AT_THRESHOLD);
            ajax.last().succeed(SEARCH_RESPONSE);
            notListed().trigger('click');

            const link = $('.two-company-search-back');
            expect(link).toHaveLength(1);
            expect(link.prop('tagName')).toBe('BUTTON');
            expect(link.attr('type')).toBe('button');
            expect(link.text()).toBe('Search for company');

            link.trigger('click');
            jest.advanceTimersByTime(300);

            expect(search._manualEntry).toBe(false);
            expect($('.two-company-search-back')).toHaveLength(0);
            // §3 routes back through openDropdown(), so the panel is open
            // rather than blank, with the query field deliberately opening
            // EMPTY rather than re-seeded - which renders no row any more
            // (TWO-40 follow-up).
            expect(shown(panel())).toBe(true);
            expect($('.two-autocomplete-too-short')).toHaveLength(0);
            expect(shown(notListed())).toBe(true);
        });

        test('focus lands where the buyer has to type, on activation and on the way back', () => {
            const search = makeInstance();
            type(AT_THRESHOLD);
            ajax.last().succeed(SEARCH_RESPONSE);

            notListed().trigger('click');
            // §2: the company-name field is a plain text input again, and it is
            // the thing they now have to type into.
            expect(document.activeElement).toBe(liveField().get(0));

            const link = $('.two-company-search-back');
            link.get(0).focus();
            expect(document.activeElement).toBe(link.get(0));

            link.trigger('click');
            jest.advanceTimersByTime(300);

            expect(search._manualEntry).toBe(false);
            // ...and back in search mode it is the QUERY field, the only thing
            // on screen a search can be typed into.
            expect(document.activeElement).toBe(searchInput().get(0));
        });

        test('a cache hit does not stack a second decoration row on this path either', () => {
            makeInstance();

            type(AT_THRESHOLD);
            ajax.last().succeed({ items: [] });
            expect(listRows().filter('.two-autocomplete-no-matches')).toHaveLength(1);

            // The result cache is shared by both render paths, so the
            // raw-results-only invariant has to hold here too.
            const callsBefore = ajax.calls.length;
            type('');
            type(AT_THRESHOLD);

            expect(ajax.calls).toHaveLength(callsBefore);
            expect(listRows()).toHaveLength(1);
            expect(listRows().filter('.two-autocomplete-no-matches')).toHaveLength(1);
            expect(notListed()).toHaveLength(1);
        });

        test('no timer survives teardown while the close is pending', () => {
            const search = makeInstance();
            type(AT_THRESHOLD);
            ajax.last().succeed(SEARCH_RESPONSE);

            // The panel's own deferred close, which replaced this path's
            // hand-rolled blurTimer: focus leaving the panel schedules it one
            // tick out so a Tab from the query field to the button - a
            // focusout followed by a focusin - does not tear the panel down
            // mid-move.
            panel().trigger('focusout');
            expect(jest.getTimerCount()).toBeGreaterThan(0);

            search.destroy();

            // Same class as the debounce tick covered below: a timer that outlives
            // teardown reaches into a panel that has been removed from the document.
            expect(jest.getTimerCount()).toBe(0);
        });

        test('the reverse link sits below the dropdown, not between it and the field', () => {
            const search = makeInstance();
            type(AT_THRESHOLD);
            ajax.last().succeed(SEARCH_RESPONSE);
            notListed().trigger('click');

            // §3 wants it in normal block flow below the company-name field and
            // never overlapping it, so it is appended to the wrapper AFTER the
            // panel rather than inserted straight after the input.
            const link = $('.two-company-search-back');
            expect(link).toHaveLength(1);
            expect(link.get(0).previousElementSibling).toBe(panel().get(0));
            void search;
        });

        test('both strings come from the translation dictionary when supplied', () => {
            const saved = window.twopayment;
            window.twopayment = {
                i18n: {
                    company_search_manual_entry: 'Mi empresa no está en la lista',
                    company_search_back_to_search: 'Buscar empresa'
                }
            };
            try {
                makeInstance();
                type(AT_THRESHOLD);
                ajax.last().succeed(SEARCH_RESPONSE);
                expect(notListed().text()).toBe('Mi empresa no está en la lista');

                notListed().trigger('click');
                expect($('.two-company-search-back').text()).toBe('Buscar empresa');
            } finally {
                window.twopayment = saved;
            }
        });
    });

    test('a failure clears the spinner and shows the unavailable row', () => {
        makeInstance();
        type('exa');

        ajax.last().fail('timeout');

        // jQuery UI is absent here, so nothing else would ever take the
        // spinner down.
        expect(searchInput().hasClass('two-company-search-loading')).toBe(false);
        expect($('.two-autocomplete-unavailable')).toHaveLength(1);
    });

    test('a success clears the spinner and renders the results', () => {
        makeInstance();
        type('exa');

        ajax.last().succeed(SEARCH_RESPONSE);

        expect(searchInput().hasClass('two-company-search-loading')).toBe(false);
        expect($('.two-autocomplete-item').text()).toContain('Example Trading Ltd (12345678)');
    });

    test('a superseded request leaves the live request spinner alone', () => {
        makeInstance();
        type('exa');
        type('exam');

        // The abort resolves the first request as `silent`; clearing the
        // loading state there would kill the spinner for the request still in
        // flight.
        expect(ajax.calls[0].aborted).toBe(true);
        expect(searchInput().hasClass('two-company-search-loading')).toBe(true);

        ajax.calls[1].succeed(SEARCH_RESPONSE);
        expect(searchInput().hasClass('two-company-search-loading')).toBe(false);
    });

    test('repeated setup leaves exactly one dropdown and one input listener', () => {
        const search = makeInstance();
        for (let i = 0; i < 10; i += 1) {
            search.setupAutocomplete();
        }

        // buildDropdown() adopts an existing panel rather than building a
        // second one, and setupCustomAutocomplete() tears its own previous
        // binding down before re-binding.
        expect(panel()).toHaveLength(1);
        expect(searchInput()).toHaveLength(1);
        expect($('.two-company-dropdown__results')).toHaveLength(1);

        type('exa');
        // An orphan container's listener still fired on the shared field:
        // duplicate searches and two things fighting over one spinner.
        expect(ajax.calls).toHaveLength(1);
    });

    test('focus leaving the panel closes it, and nothing reopens it after destroy', () => {
        const search = makeInstance();
        type('exa');
        ajax.last().succeed(SEARCH_RESPONSE);
        expect(shown(panel())).toBe(true);

        // The close is the PANEL'S now, not this path's own blurTimer: a
        // deferred `focusout`, cancelled by any `focusin` back inside the
        // panel, so a Tab from the query field to the "not on the list" button
        // does not tear it down mid-move.
        // Focus genuinely has to have LEFT: the deferred close re-checks
        // `document.activeElement` and stands down if it is still inside the
        // panel, which is what makes "left the panel" mean what it says.
        searchInput().get(0).blur();
        panel().trigger('focusout');
        jest.advanceTimersByTime(1);
        expect(shown(panel())).toBe(false);

        // What IS pinned here: focus leaving closes the panel while live, and
        // destroy leaves nothing for a later focusout to act on.
        search.destroy();
        expect(panel()).toHaveLength(0);
        expect(() => {
            liveField().trigger('focusout');
            jest.advanceTimersByTime(1);
        }).not.toThrow();
        expect(panel()).toHaveLength(0);
    });

    test('switching to the jQuery UI path removes the fallback and its spinner', () => {
        const search = makeInstance();
        type('exa');
        expect(searchInput().hasClass('two-company-search-loading')).toBe(true);

        $.ui = savedUi;
        search.setupAutocomplete();

        // The panel is SHARED and stays; what must go is the engine - its
        // input listener, its debounce and its hand-set spinner class - or the
        // two paths fight over one field.
        expect(search._customAutocomplete).toBeNull();
        expect(searchInput().hasClass('two-company-search-loading')).toBe(false);
        expect(searchInput().hasClass('ui-autocomplete-input')).toBe(true);
    });

    test('destroy unbinds the listener and drops the panel', () => {
        const search = makeInstance();
        const input = openPanel().get(0);
        search.destroy();

        expect(panel()).toHaveLength(0);
        expect(search._customAutocomplete).toBeNull();

        // The detached query field still answers a directly-dispatched event
        // if a listener was left on it, which is exactly what a leak looks like.
        input.value = 'exa';
        input.dispatchEvent(new window.Event('input'));
        jest.advanceTimersByTime(1000);

        expect(ajax.calls).toHaveLength(0);
    });

    test('a debounce tick pending at destroy never reaches the network', () => {
        const search = makeInstance();
        const input = openPanel().get(0);
        input.value = 'exa';
        input.dispatchEvent(new window.Event('input'));

        search.destroy();
        jest.advanceTimersByTime(1000);

        // A surviving tick would call searchCompanies(), bump the sequence and
        // abort the NEW dropdown's in-flight request — which then resolves as
        // `silent`, so its spinner is never cleared.
        expect(ajax.calls).toHaveLength(0);
    });

    test('a term the input has moved past still clears the spinner', () => {
        makeInstance();
        const input = type('exa');

        // A programmatic val('') on country change fires no input event, so
        // nothing else would ever clear it.
        input.value = 'something else';
        ajax.last().succeed(SEARCH_RESPONSE);

        expect(searchInput().hasClass('two-company-search-loading')).toBe(false);
    });
});

describe('the inline grey company-id hint (TWO-25288)', () => {
    function hintText() {
        return $('.two-company-id-hint').text();
    }

    test('is empty before any selection', () => {
        makeInstance();

        expect($('.two-company-id-hint').length).toBe(1);
        expect(hintText()).toBe('');
    });

    test('shows the selected company\'s org number', () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', organization_number: '12345678' }
        });

        expect(hintText()).toBe('12345678');
    });

    test('is cleared when the buyer edits the company name after selecting one', () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', organization_number: '12345678' }
        });

        // Mirrors clearStaleOrganizationSelection()'s own trigger: an input event
        // on the company field once a selection marker exists.
        liveField().val('Example Trading Lt').trigger('input');

        expect(hintText()).toBe('');
    });

    test('picks up a GB org number that only arrives via the details lookup', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });

        // No organization_number on the search result itself, so the hint must
        // not claim one yet.
        expect(hintText()).toBe('');

        ajax.last().succeed({
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street' }],
            national_identifier: { id: '87654321' }
        });
        await flushPromises();

        expect(hintText()).toBe('87654321');
    });

    test('a second, hidden-number selection does not keep the first hint on screen', () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', organization_number: '12345678' }
        });
        expect(hintText()).toBe('12345678');

        search.onCompanySelected(null, {
            item: { value: 'Second Trading Ltd', lookup_id: 'lookup-def-456' }
        });

        expect(hintText()).toBe('');
    });
});

describe('a panel the buyer opened survives a re-render (TWO-25326 §1)', () => {
    // Found in a real browser, invisible to every test that existed.
    //
    // PrestaShop fires `updatedAddressForm` for ordinary interactions, and it
    // can land AFTER the click that opened the panel rather than before it -
    // measured against a real PrestaShop 8 at click +165ms, re-render +195ms.
    // The handler closed the panel and rebuilt it shut, so the buyer clicked
    // the company field, saw a dropdown appear and vanish, and had no route
    // into manual entry at all. §1 requires the click to open the panel; a
    // panel that reopens and then disappears on its own does not satisfy it.
    test('an open panel is reopened after the address form is re-rendered', () => {
        makeInstance();
        openPanel();
        expect(shown(panel())).toBe(true);

        bus.emit('updatedAddressForm');

        expect(shown(panel())).toBe(true);
        // Rebuilt, not merely left alone - otherwise this passes on a build
        // where the re-render never tore the panel down in the first place and
        // proves nothing about the restore.
        expect(searchInput().length).toBe(1);
        expect(searchInput().hasClass('ui-autocomplete-input')).toBe(true);
    });

    test('a panel the buyer had CLOSED is not reopened by a re-render', () => {
        // The restore must not resurrect a panel that was already shut, or a
        // re-render becomes a way to force the dropdown open on a buyer who
        // dismissed it.
        const search = makeInstance();
        openPanel();
        search.closeDropdown(false);
        expect(shown(panel())).toBe(false);

        bus.emit('updatedAddressForm');

        expect(shown(panel())).toBe(false);
    });

    test('a close after the re-render is not undone by a later rebuild', () => {
        // The deadline is left armed across the double teardown one
        // `updatedAddressForm` causes, so an explicit close inside that window
        // has to clear it - otherwise the next rebuild reopens a panel the
        // buyer just dismissed.
        const search = makeInstance();
        openPanel();
        bus.emit('updatedAddressForm');
        expect(shown(panel())).toBe(true);

        search.closeDropdown(false);
        bus.emit('updatedAddressForm');

        expect(shown(panel())).toBe(false);
    });
});
