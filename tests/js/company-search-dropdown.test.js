/**
 * TWO-25326 §1-§4, pinned against the PrestaShop company-search control.
 *
 * One test per bullet of the cross-platform test script, worded so a failure
 * names the requirement rather than the implementation. These are the bullets
 * PrestaShop was recorded as failing on that ticket:
 *
 *   §1  in-field autocomplete with no separate dropdown control
 *   §1  no query field created at all
 *   §1  zero-result query shows no text
 *   §2  "not on the list" is an <li> inside the scrollable <ul>
 *   §2  reachable by cursor keys, not by Tab
 *   §2  no visual distinction from inert rows
 *   §2  activation behaviour untestable
 *   §4  Tab from query field to "not on the list"
 *
 * jsdom CAVEAT, stated once and relied on throughout: jsdom implements focus,
 * DOM order and event dispatch faithfully, but it does NOT implement the
 * browser's sequential focus navigation - there is no "press Tab and see where
 * focus lands". So the Tab-order bullets are asserted structurally, on the
 * property the browser derives tab order FROM: document order among focusable,
 * non-negative-tabindex elements within the same container. That is a genuine
 * proof for this control specifically, because the whole design point is that
 * the panel is a DOM child of the field wrapper and therefore inherits tab
 * order for free - see buildDropdown(). It is NOT a substitute for pressing
 * Tab in a real browser, and this ticket requires that too.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const {
    loadCompanySearch,
    buildAddressForm,
    installStylesheet,
    stubAjax,
    releaseWidgets,
    panelParts,
    openPanel,
    typeQuery,
    resultTexts,
    shown,
    REPO_ROOT
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

let TwoCompanySearch;
let $;
let ajax;

/** Two real companies, in the shape GET /companies/v2/company returns. */
const SEARCH_RESPONSE = {
    items: [
        { name: 'Example Trading Ltd', lookup_id: 'lk-1', national_identifier: { id: '11111111' } },
        { name: 'Example Holdings Ltd', lookup_id: 'lk-2', national_identifier: { id: '22222222' } }
    ]
};

function makeInstance(config) {
    return new TwoCompanySearch(Object.assign({ checkoutHost: CHECKOUT_HOST }, config || {}));
}

function companyField() {
    return $("input[name='company']");
}

/**
 * Pick the Nth rendered row the way a buyer's click or Enter does.
 *
 * Driven through the widget's own menu rather than by calling
 * onCompanySelected() directly, so an unwired `select` option would fail this.
 *
 * NOT driven by dispatching Down/Enter keydowns: jQuery UI's `_move` gates on
 * `this.menu.element.is(':visible')`, and jsdom performs no layout, so that
 * test can never pass there for reasons that have nothing to do with this
 * code. Cursor-key navigation is one of the bullets TWO-25326 requires a REAL
 * browser for; this asserts the selection pipeline behind it.
 */
function pickResult(index) {
    const instance = panelParts().query.autocomplete('instance');
    const row = instance.menu.element.children('li').eq(index || 0);
    instance.menu.focus(null, row);
    instance.menu.select($.Event('click'));
}

/** Run the widget's 300ms debounce out and settle the stubbed request. */
function settleSearch(response) {
    jest.advanceTimersByTime(400);
    const pending = ajax.last();
    if (pending) {
        pending.succeed(response === undefined ? SEARCH_RESPONSE : response);
    }
    jest.advanceTimersByTime(50);
}

beforeEach(() => {
    jest.useFakeTimers();
    document.body.innerHTML = '';
    document.head.innerHTML = '';
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    buildAddressForm();
    installStylesheet('views/css/two.css');
    ajax = stubAjax($);
});

afterEach(() => {
    releaseWidgets($);
    jest.useRealTimers();
});

describe('§1 the dropdown is a real control, not an in-field autocomplete', () => {
    test('a click on the company-name field opens a panel anchored to it', () => {
        makeInstance();
        expect(shown(panelParts().panel)).toBe(false);

        openPanel();

        const { panel } = panelParts();
        expect(panel.length).toBe(1);
        expect(shown(panel)).toBe(true);
        // Anchored to the field: a child of the field's own tight wrapper, so
        // it tracks the input's position and width rather than the theme's
        // padded column - and so DOM order gives tab order (see §2/§4 below).
        expect(panel.parent().hasClass('two-company-field-wrap')).toBe(true);
        expect(panel.siblings("input[name='company']").length).toBe(1);
    });

    test('it is visually separated from the input, not butted against it', () => {
        makeInstance();
        openPanel();
        const style = window.getComputedStyle(panelParts().panel.get(0));
        // A floating box of its own. The PS defect was a menu flush against
        // the input with no border of its own, which reads as a continuation
        // of the field rather than a separate control.
        expect(style.position).toBe('absolute');
        expect(style.border).toContain('1px');

        // The 8px gap itself CANNOT be asserted through `getComputedStyle`
        // here: the rule is `top: calc(var(--two-company-input-height, 100%)
        // + 8px)` and jsdom's CSS parser drops a `calc()` containing a
        // `var()`, resolving `top` to the empty string however correct the
        // rule is. So the two halves are pinned where they are each real -
        // the declaration in the shipped stylesheet, and the custom property
        // the JS feeds it. Whether that renders as 8px of daylight is a
        // real-browser question.
        const css = fs.readFileSync(path.join(REPO_ROOT, 'views/css/two.css'), 'utf8');
        expect(css).toContain('top: calc(var(--two-company-input-height, 100%) + 8px);');
    });

    test('the panel is anchored to the input height, not the wrapper height', () => {
        // The wrapper also holds the in-flow org-number label, so anchoring at
        // `100%` of it would push the panel further down as soon as a company
        // was selected.
        makeInstance();
        const wrapper = companyField().parent().get(0);
        Object.defineProperty(companyField().get(0), 'offsetHeight', {
            configurable: true,
            value: 38
        });
        openPanel();

        expect(wrapper.style.getPropertyValue('--two-company-input-height')).not.toBe('');
    });

    test('typing a character while the field has focus opens it too', () => {
        makeInstance();
        const field = companyField();
        field.trigger('focus');
        expect(shown(panelParts().panel)).toBe(false);

        const event = $.Event('keydown', { key: 'a' });
        field.trigger(event);

        expect(shown(panelParts().panel)).toBe(true);
        // The keystroke that opened it is carried into the query field rather
        // than swallowed.
        expect(panelParts().query.val()).toBe('a');
    });

    test('merely moving focus into the field does NOT open it', () => {
        makeInstance();
        companyField().trigger('focus');
        expect(shown(panelParts().panel)).toBe(false);
    });

    test('Tab while the field has focus does not open it either', () => {
        makeInstance();
        companyField().trigger('focus');
        companyField().trigger($.Event('keydown', { key: 'Tab' }));
        expect(shown(panelParts().panel)).toBe(false);
    });

    test('the panel contains a real query input and focus lands in it', () => {
        makeInstance();
        openPanel();
        const { query } = panelParts();
        expect(query.length).toBe(1);
        expect(query.get(0).tagName).toBe('INPUT');
        expect(document.activeElement).toBe(query.get(0));
    });

    test('the company-name field is left unchanged while the buyer searches', () => {
        makeInstance();
        companyField().val('Previously Chosen Ltd');
        openPanel();
        typeQuery('exa');
        settleSearch();

        expect(companyField().val()).toBe('Previously Chosen Ltd');
    });

    test('the company-name field cannot be typed into in search mode', () => {
        makeInstance();
        // readonly, not disabled: it still submits and is still a tab stop.
        expect(companyField().attr('readonly')).toBeDefined();
        expect(companyField().is(':disabled')).toBe(false);
    });

    test('a too-short query shows the "type N more characters" hint', () => {
        makeInstance();
        openPanel();
        jest.advanceTimersByTime(400);

        // Present from the moment the panel opens, before a single keystroke.
        expect(resultTexts().join(' ')).toContain(String(TwoCompanySearch.MIN_SEARCH_LENGTH));
        expect(ajax.calls.length).toBe(0);

        typeQuery('ex');
        jest.advanceTimersByTime(400);
        expect(resultTexts().join(' ')).toContain(String(TwoCompanySearch.MIN_SEARCH_LENGTH));
        expect(ajax.calls.length).toBe(0);
    });

    test('a zero-result query says exactly "No matches found"', () => {
        makeInstance();
        openPanel();
        typeQuery('zzzz');
        settleSearch({ items: [] });

        expect(resultTexts()).toContain('No matches found');
    });

    test('the search is debounced by 300ms', () => {
        makeInstance();
        openPanel();
        typeQuery('exa');
        jest.advanceTimersByTime(250);
        expect(ajax.calls.length).toBe(0);
        jest.advanceTimersByTime(100);
        expect(ajax.calls.length).toBe(1);
    });

    test('a spinner is shown in the query field while a search is running', () => {
        makeInstance();
        openPanel();
        typeQuery('exa');
        jest.advanceTimersByTime(400);

        const { query, panel } = panelParts();
        expect(query.hasClass('ui-autocomplete-loading') || query.hasClass('two-company-search-loading')).toBe(true);

        const spinner = panel.find('.two-company-dropdown__spinner');
        expect(spinner.length).toBe(1);
        // The GIF, at the right-hand end of the field - the requirement is
        // specific about both.
        const style = window.getComputedStyle(spinner.get(0));
        expect(style.backgroundImage).toContain('loader.gif');
        expect(style.right).toBe('8px');
    });

    test('cursor keys plus Enter pick a result, and the name replaces the field value', () => {
        makeInstance();
        openPanel();
        typeQuery('exa');
        settleSearch();

        pickResult(0);

        expect(companyField().val()).toBe('Example Trading Ltd');
        expect($("input[name='companyid']").val()).toBe('11111111');
    });

    test('Escape closes the panel and puts focus back on the company-name field', () => {
        makeInstance();
        openPanel();
        panelParts().panel.trigger($.Event('keydown', { key: 'Escape' }));

        expect(shown(panelParts().panel)).toBe(false);
        expect(document.activeElement).toBe(companyField().get(0));
    });

    test('a completed selection closes the panel', () => {
        makeInstance();
        openPanel();
        typeQuery('exa');
        settleSearch();
        pickResult(0);

        expect(shown(panelParts().panel)).toBe(false);
    });
});

describe('§2 "My company is not on the list"', () => {
    test('is a real <button>, never a pseudo-row', () => {
        makeInstance();
        openPanel();
        const { notListed } = panelParts();
        expect(notListed.length).toBe(1);
        expect(notListed.get(0).tagName).toBe('BUTTON');
        // type=button, or it submits the address form it sits inside.
        expect(notListed.attr('type')).toBe('button');
    });

    test('renders OUTSIDE the scrollable results container', () => {
        makeInstance();
        openPanel();
        typeQuery('exa');
        settleSearch();

        const { notListed, results } = panelParts();
        expect(results.get(0).contains(notListed.get(0))).toBe(false);
        // ...and it is the scroll container it sits outside of, not some other
        // box - otherwise this assertion would pass on a broken layout.
        expect(window.getComputedStyle(results.get(0)).overflowY).toBe('auto');
        expect(notListed.parent().is(panelParts().panel)).toBe(true);
    });

    test('is NOT reachable by the cursor keys', () => {
        makeInstance();
        openPanel();
        typeQuery('exa');
        settleSearch();

        // The cursor keys move within the widget's own menu and nowhere else,
        // so "unreachable by cursor keys" is exactly "not an item in that
        // menu". Asserted on the menu's contents rather than by dispatching
        // arrow keys, per the jsdom caveat at the top of this file.
        const instance = panelParts().query.autocomplete('instance');
        const items = instance.menu.element.children('li').get();
        expect(items.length).toBe(2);
        expect(items).not.toContain(panelParts().notListed.get(0));
        expect(panelParts().notListed.closest('.two-company-dropdown__results').length).toBe(0);
    });

    test('the results list is not itself a tab stop', () => {
        // jQuery UI's menu widget sets `tabindex="0"` on its own <ul>, which
        // would put the scroll container between the query field and the
        // button - the defect recorded against Hyva on this ticket.
        makeInstance();
        openPanel();
        const menu = panelParts().query.autocomplete('widget');
        expect(menu.attr('tabindex')).toBe('-1');
    });

    test('is the next tab stop after the query field', () => {
        makeInstance();
        openPanel();
        const { panel, query, notListed } = panelParts();

        // Structural proof, per the jsdom caveat at the top of this file: both
        // are focusable, neither is removed from the tab order, and the button
        // is the very next such element after the query field in document
        // order within the panel.
        const focusable = panel.find('input, button, a[href], [tabindex]')
            .filter(function () { return $(this).attr('tabindex') !== '-1'; })
            .get();
        expect(focusable.indexOf(notListed.get(0))).toBe(focusable.indexOf(query.get(0)) + 1);
    });

    test('is visually distinguishable from inert result rows', () => {
        makeInstance();
        openPanel();
        typeQuery('exa');
        settleSearch();

        const buttonColour = window.getComputedStyle(panelParts().notListed.get(0)).color;
        const rowColour = window.getComputedStyle(panelParts().results.find('li').get(0)).color;
        // Link-blue, matching WC's #search_company_btn.
        expect(buttonColour).toBe('rgb(48, 67, 209)');
        expect(buttonColour).not.toBe(rowColour);
    });

    test('a mouse click activates it and places focus in the company-name field', () => {
        makeInstance();
        openPanel();
        panelParts().notListed.trigger('click');

        expect(document.activeElement).toBe(companyField().get(0));
        // Manual entry: the field is a plain text box again.
        expect(companyField().attr('readonly')).toBeUndefined();
        expect(shown(panelParts().panel)).toBe(false);
    });

    test('Enter and Space activate it, because it is a real button', () => {
        // Not simulated by hand: a <button> gets Enter/Space activation from
        // the browser, and asserting on a keydown handler we did not write
        // would test nothing. What IS worth pinning is that nothing has
        // suppressed it - no role override, no preventDefault-ing keydown.
        makeInstance();
        openPanel();
        const notListed = panelParts().notListed.get(0);
        expect(notListed.tagName).toBe('BUTTON');
        expect(notListed.getAttribute('role')).toBeNull();
        expect(notListed.disabled).toBe(false);

        // The assertions above are what this test USED to stop at, and they
        // could not fail while Enter was in fact broken: the panel's own
        // keydown handler cancelled every Enter that bubbled through it, and a
        // button's activation click is precisely the default action of that
        // keydown - so Enter did nothing, with no role override and no
        // disabled flag to show for it. jsdom does not synthesise the click,
        // so the mechanism is what gets pinned here.
        const enter = $.Event('keydown', { key: 'Enter', keyCode: 13 });
        panelParts().notListed.trigger(enter);
        expect(enter.isDefaultPrevented()).toBe(false);
    });

    test('is visible with the panel freshly open and nothing typed', () => {
        // The WC regression recorded on TWO-25326: gating on the 3-character
        // threshold removed the button entirely for a buyer who typed nothing,
        // which is exactly the case Doug requires a manual-entry route for.
        makeInstance();
        openPanel();
        expect(shown(panelParts().notListed)).toBe(true);
    });

    test('is hidden once a company IS selected', () => {
        makeInstance();
        openPanel();
        typeQuery('exa');
        settleSearch();
        pickResult(0);

        // Reopen: a company is captured now, so the affordance is gone.
        openPanel();
        expect(shown(panelParts().notListed)).toBe(false);
    });
});

describe('panel handler binding', () => {
    test('re-running setup does not double-bind the manual-entry button', () => {
        // setupAutocomplete() re-runs on every country change and address-form
        // update, and buildDropdown() ADOPTS an existing panel rather than
        // building a second one. Adoption re-binds the panel's handlers to the
        // current instance (they close over whichever instance built them), so
        // the binder has to unbind its own namespace first or each re-entry
        // stacks another copy - and "My company is not on the list" would fire
        // enterManualEntryMode() once per address-form update the buyer had
        // happened to trigger.
        const search = makeInstance();
        search.setupAutocomplete();
        search.setupAutocomplete();

        let entered = 0;
        const real = search.enterManualEntryMode.bind(search);
        search.enterManualEntryMode = () => { entered += 1; real(); };

        openPanel();
        panelParts().notListed.trigger('click');

        expect(entered).toBe(1);
    });

    test('re-running setup leaves exactly one panel', () => {
        const search = makeInstance();
        search.setupAutocomplete();
        search.setupAutocomplete();

        expect($('.two-company-dropdown').length).toBe(1);
        expect($('.two-company-dropdown__query').length).toBe(1);
        expect($('.two-company-not-listed').length).toBe(1);
    });
});

describe('regressions found in adversarial review', () => {
    test('Enter in the query field never submits the address form', () => {
        // jQuery UI only preventDefaults Enter when it has an ACTIVE menu
        // item. In every other state - the too-short hint, "No matches found",
        // results painted but nothing highlighted - Enter fell through to
        // PrestaShop's <form> and triggered implicit submission: the buyer
        // types a name, presses Enter before results land, and submits the
        // address step.
        makeInstance();
        openPanel();
        typeQuery('exa');
        settleSearch();

        const event = $.Event('keydown', { key: 'Enter', keyCode: 13 });
        panelParts().query.trigger(event);

        expect(event.isDefaultPrevented()).toBe(true);
    });

    test('Enter is still prevented on the too-short hint, before any search', () => {
        makeInstance();
        openPanel();

        const event = $.Event('keydown', { key: 'Enter', keyCode: 13 });
        panelParts().query.trigger(event);

        expect(event.isDefaultPrevented()).toBe(true);
    });

    test('a pointer interaction inside the panel returns focus rather than closing', () => {
        // A scrollbar drag on the results container moves focus OFF the panel
        // (to `<body>` in Chrome), so the focus-out close fired mid-scroll -
        // with up to 50 results, the ordinary way to browse them. Deferring
        // that close to `mouseup` was NOT a fix: focus is still outside the
        // panel by then, so it closed anyway, just later. The panel must
        // reclaim focus instead.
        //
        // Driven with a real focusable element rather than `<body>`: jsdom
        // will not make `<body>` the activeElement, so a test written the way
        // the browser actually behaves would pass vacuously here. What this
        // pins is the mechanism - pointer went down on the panel, focus ended
        // up outside it, focus comes back and the panel stays.
        makeInstance();
        openPanel();
        typeQuery('exa');
        settleSearch();

        const outside = $("input[name='dni']").get(0);
        panelParts().panel.trigger('mousedown');
        outside.focus();
        panelParts().panel.trigger('focusout');
        jest.advanceTimersByTime(10);

        $(document).trigger('mouseup');
        jest.advanceTimersByTime(10);

        expect(shown(panelParts().panel)).toBe(true);
        expect(document.activeElement).toBe(panelParts().query.get(0));
    });

    test('a drag released outside the window cannot strand the panel open', () => {
        // The pointer-in-panel guard above suppresses the focus-out close for
        // as long as it believes a pointer is down on the panel, and the only
        // thing that clears it is a matching `mouseup` on `document`. A drag
        // begun on the panel and released outside the window fires no such
        // event, so the flag stuck `true` and every later focus-out close
        // early-returned - the panel stayed on screen with focus long gone,
        // for the rest of its life. Losing the window clears it instead.
        makeInstance();
        openPanel();

        panelParts().panel.trigger('mousedown');
        // No `mouseup` - the button came up beyond the document.
        $(window).trigger('blur');

        const elsewhere = $("input[name='dni']").get(0);
        elsewhere.focus();
        panelParts().panel.trigger('focusout');
        jest.advanceTimersByTime(10);

        expect(shown(panelParts().panel)).toBe(false);
    });

    test('Tab out of the query field does not let the widget pick the highlighted row', () => {
        // jQuery UI's autocomplete accepts the active menu item on Tab. With a
        // row arrow-keyed onto, that fired our `select` handler, which closes
        // the panel and returns focus to company-name - so a buyer who arrowed
        // down to read a result and then tabbed had it silently chosen for
        // them, and focus went backwards instead of on to "not on the list".
        //
        // Asserted through the widget's own keydown path rather than by
        // calling the guard: what matters is that the widget never SEES the
        // keystroke, which is a propagation property, not a return value.
        // Driving jQuery UI's menu itself is not possible here - jsdom does no
        // layout, the widget never marks a row active under synthetic keys,
        // and a test written that way passes whether the guard exists or not
        // (confirmed: it did). So this pins the mechanism directly. A listener
        // bound on the query input stands in for the widget's own, which is
        // bound in exactly that position; if Tab reaches target phase at all,
        // it reaches jQuery UI too.
        makeInstance();
        openPanel();
        typeQuery('exa');
        settleSearch();

        const queryEl = panelParts().query.get(0);
        const seen = [];
        queryEl.addEventListener('keydown', (e) => seen.push(e.key));

        // Dispatched natively, not via jQuery's `.trigger()`: that calls
        // jQuery handlers directly and never runs a capture phase, so it
        // cannot exercise this guard at all.
        const press = (key) => queryEl.dispatchEvent(
            new window.KeyboardEvent('keydown', { key: key, bubbles: true, cancelable: true })
        );

        press('Tab');
        // A non-Tab key must still get through, or the guard is too broad and
        // would break typing and arrow navigation.
        press('ArrowDown');

        expect(seen).toEqual(['ArrowDown']);
    });

    test('the results area cannot reflow while the pointer is pressed on the panel', () => {
        // The bug this pins was invisible to this entire suite and cost a full
        // CI cycle per guess to chase, so it is worth being explicit about
        // what it was and what this can honestly prove.
        //
        // A `<button>` only activates when mousedown and mouseup land on the
        // SAME element; otherwise the browser dispatches `click` on the two
        // targets' nearest common ancestor and the button's handler never
        // runs. The results area sits directly above "not on the list", and
        // pressing that button blurs the query field, which empties the
        // results area. Measured in real Chromium: results 30px -> 0px between
        // mousedown and mouseup, button top 658 -> 627, `click` retargeted to
        // `<section class="form-fields">`. Manual entry was unreachable by
        // mouse, on every real browser, while every test here passed.
        //
        // jsdom has no layout - every rect is 0x0 and it will happily dispatch
        // `click` on an element that moved - so the geometry itself is not
        // assertable here. What IS assertable, and what the fix turns on, is
        // the mechanism: the height is pinned for the duration of the press
        // and released only after the click has been dispatched. A real-
        // browser check of the resulting behaviour is in the e2e suite.
        makeInstance();
        openPanel();

        const results = panelParts().results;
        expect(results.length).toBe(1);
        expect(results.get(0).style.minHeight).toBe('');

        panelParts().panel.trigger('mousedown');
        // Pinned to *something* for the duration of the press. The value is
        // whatever jsdom measures (0px), so assert that it was set at all -
        // asserting a pixel figure here would only be testing jsdom.
        expect(results.get(0).style.minHeight).not.toBe('');

        // Still pinned as the mouseup handler runs: releasing synchronously
        // here would let the panel reflow before the browser dispatches the
        // click, which is the original bug moved one event later.
        $(document).trigger('mouseup');
        expect(results.get(0).style.minHeight).not.toBe('');

        // Released once the click has had its chance.
        jest.advanceTimersByTime(1);
        expect(results.get(0).style.minHeight).toBe('');
    });

    test('losing the window releases a pinned results height', () => {
        // A press that ends outside the window fires no `mouseup` this
        // document sees - the same hole the `_pointerInPanel` guard has - so
        // the pin would otherwise outlive the gesture and freeze the results
        // area at one row for the rest of the panel's life.
        makeInstance();
        openPanel();

        const results = panelParts().results;
        panelParts().panel.trigger('mousedown');
        expect(results.get(0).style.minHeight).not.toBe('');

        // `triggerHandler`, not `trigger`: jQuery's `trigger` also calls the
        // native `window.blur()`, which jsdom does not implement and logs a
        // "not implemented" stack for. Only the handler is under test.
        $(window).triggerHandler('blur');
        expect(results.get(0).style.minHeight).toBe('');
    });

    test('a genuine click away still closes the panel', () => {
        // The guard above must not swallow the ordinary case: a pointer that
        // went down somewhere OTHER than the panel.
        makeInstance();
        openPanel();

        const elsewhere = $("input[name='dni']").get(0);
        elsewhere.focus();
        panelParts().panel.trigger('focusout');
        jest.advanceTimersByTime(10);

        expect(shown(panelParts().panel)).toBe(false);
        expect(document.activeElement).toBe(elsewhere);
    });
});

describe('§3 the return-to-search link', () => {
    function enterManualEntry() {
        openPanel();
        panelParts().notListed.trigger('click');
        return $('.two-company-search-back');
    }

    test('renders below the company-name field in normal block flow', () => {
        makeInstance();
        const link = enterManualEntry();
        expect(link.length).toBe(1);
        const style = window.getComputedStyle(link.get(0));
        expect(style.position).not.toBe('absolute');
        expect(style.display).toBe('block');
        // Inside the field's own wrapper, after the input.
        expect(link.parent().hasClass('two-company-field-wrap')).toBe(true);
        const kids = link.parent().children().get();
        expect(kids.indexOf(link.get(0))).toBeGreaterThan(kids.indexOf(companyField().get(0)));
    });

    test('is right-aligned under the field', () => {
        makeInstance();
        const style = window.getComputedStyle(enterManualEntry().get(0));
        // An auto inline-start margin is what pushes it right. The paired
        // `width: fit-content` is deliberately NOT asserted: jsdom's CSS
        // parser drops the keyword, so the assertion would pin the parser
        // rather than the rule.
        expect(style.marginLeft).toBe('auto');
        expect(style.display).toBe('block');
    });

    test('is not underlined', () => {
        makeInstance();
        expect(window.getComputedStyle(enterManualEntry().get(0)).textDecoration)
            .not.toContain('underline');
    });

    test('clicking it returns to search mode and focuses the query field', () => {
        makeInstance();
        const link = enterManualEntry();
        link.trigger('click');

        expect($('.two-company-search-back').length).toBe(0);
        expect(shown(panelParts().panel)).toBe(true);
        expect(document.activeElement).toBe(panelParts().query.get(0));
        expect(companyField().attr('readonly')).toBeDefined();
    });

    test('clicking it does not let the click reach the accordion above', () => {
        // #30.x.14 bug 2.5: the theme's delegated accordion-toggle handler read
        // this click as "collapse the address step".
        makeInstance();
        const seen = [];
        $('.js-address-form').on('click', () => seen.push('accordion'));
        enterManualEntry().trigger('click');
        expect(seen).toEqual([]);
    });
});

describe('§4 keyboard navigation', () => {
    test('the query field is the next tab stop after the company-name field', () => {
        makeInstance();
        openPanel();
        const wrapper = companyField().parent();
        const focusable = wrapper.find('input, button')
            .filter(function () {
                return $(this).attr('tabindex') !== '-1'
                    && $(this).attr('type') !== 'hidden'
                    && shown(this);
            })
            .get();
        const field = companyField().get(0);
        const query = panelParts().query.get(0);
        expect(focusable.indexOf(field)).toBe(0);
        expect(focusable.indexOf(query)).toBe(1);
    });

    test('tabbing out of the panel closes it and does NOT drag focus back', () => {
        makeInstance();
        openPanel();
        // The browser has already moved focus to the next form control; the
        // panel finds out via focusout. Pulling focus back here is the keyboard
        // trap §4 forbids, and is the WC defect recorded on this ticket.
        const next = $("input[name='dni']").get(0);
        next.focus();
        panelParts().panel.trigger('focusout');
        jest.advanceTimersByTime(10);

        expect(shown(panelParts().panel)).toBe(false);
        expect(document.activeElement).toBe(next);
    });

    test('moving between the query field and the button does not close the panel', () => {
        makeInstance();
        openPanel();
        const notListed = panelParts().notListed.get(0);
        panelParts().panel.trigger('focusout');
        notListed.focus();
        panelParts().panel.trigger('focusin');
        jest.advanceTimersByTime(10);

        expect(shown(panelParts().panel)).toBe(true);
    });

    test('in manual-entry mode the company-name field is an ordinary tab stop', () => {
        makeInstance();
        openPanel();
        panelParts().notListed.trigger('click');

        // No readonly, no popup semantics, no panel in the way.
        expect(companyField().attr('readonly')).toBeUndefined();
        expect(companyField().attr('aria-haspopup')).toBeUndefined();
        expect(shown(panelParts().panel)).toBe(false);
        // Typing goes into the field itself now.
        companyField().val('Hand Typed Ltd').trigger('input');
        expect(companyField().val()).toBe('Hand Typed Ltd');
    });

    test('nothing in the control is a tab stop while it is closed', () => {
        makeInstance();
        const panel = panelParts().panel;
        expect(shown(panel)).toBe(false);
        // Hidden elements are not focusable, so a closed panel cannot trap or
        // detour a keyboard user - the property §4 asks for.
        expect(shown(panel)).toBe(false);
        expect(shown(panelParts().query)).toBe(false);
        expect(shown(panelParts().notListed)).toBe(false);
    });
});

describe('§7 spacing and stray company displays', () => {
    test('the company-name field reserves no spinner space of its own', () => {
        // The spinner moved into the panel's query field, but the 32px
        // `padding-right` that reserved room for it stayed on the company-name
        // field - 32px of dead space making it visibly unlike every other
        // input on the address form.
        makeInstance();
        expect(window.getComputedStyle(companyField().get(0)).paddingRight).not.toBe('32px');
    });

    test('the query field is what reserves the spinner lane', () => {
        makeInstance();
        openPanel();
        expect(window.getComputedStyle(panelParts().query.get(0)).paddingRight).toBe('30px');
    });

    test('nothing displays the company name in the address area', () => {
        // §7: no additional text labels showing the captured name. The
        // click-to-reveal chip used to paint the name over the field; it is
        // gone, and the only remaining label is the §5 company NUMBER.
        makeInstance();
        openPanel();
        typeQuery('exa');
        settleSearch();
        pickResult(0);

        expect($('.two-company-search-reveal').length).toBe(0);
        const labels = $('.js-address-form').find('span, div, button')
            .filter(function () {
                return $(this).children().length === 0
                    && $(this).text().trim() === 'Example Trading Ltd';
            });
        expect(labels.length).toBe(0);
    });

    test('the return-to-search link adds no gap below itself', () => {
        makeInstance();
        openPanel();
        panelParts().notListed.trigger('click');
        const link = $('.two-company-search-back');
        expect(window.getComputedStyle(link.get(0)).marginBottom).toBe('0px');
        // The wrapper's old unconditional bottom padding is what stacked
        // underneath it to make the gap Doug found live.
        expect(window.getComputedStyle(companyField().parent().get(0)).paddingBottom)
            .not.toBe('18px');
    });
});

describe('§5 the company number after selection', () => {
    test('renders as a plain text label, never an input, and only once picked', () => {
        makeInstance();
        const hint = $('.two-company-id-hint');
        expect(hint.get(0).tagName).toBe('SPAN');
        expect(shown(hint)).toBe(false);

        openPanel();
        typeQuery('exa');
        settleSearch();
        pickResult(0);

        expect($('.two-company-id-hint').text()).toBe('11111111');
        expect(shown($('.two-company-id-hint'))).toBe(true);
        // The hidden carrier stays hidden - it is not a visible field.
        expect($("input[name='companyid']").attr('type')).toBe('hidden');
    });

    test('it takes NO space in the form until a company is selected', () => {
        // §7: "additional space only when items such as the company number are
        // visible". The old rule reserved 18px on the wrapper unconditionally
        // AND still overlapped the field below, which is the defect this fixes.
        makeInstance();
        const wrapper = companyField().parent();
        expect(window.getComputedStyle(wrapper.get(0)).paddingBottom).not.toBe('18px');
        expect(window.getComputedStyle($('.two-company-id-hint').get(0)).display).toBe('none');
    });

    test('it cannot overlap the field below it', () => {
        makeInstance();
        openPanel();
        typeQuery('exa');
        settleSearch();
        pickResult(0);

        // In normal flow rather than absolutely positioned: an in-flow block
        // takes its own height and cannot be painted over the next row, which
        // is what the PS §5 collision was.
        const style = window.getComputedStyle($('.two-company-id-hint').get(0));
        expect(style.position).not.toBe('absolute');
        expect(style.display).toBe('block');
        expect(style.textAlign).toBe('end');
    });

    test('forgetting the selected company clears the label with the value', () => {
        // clearSelectedCompany() dropped the hidden organisation number and
        // left the visible label still showing it - the pairing every other
        // clear path in this module maintains. Reachable via manual entry, and
        // via any future caller of the same method.
        const search = makeInstance();
        openPanel();
        typeQuery('exa');
        settleSearch();
        pickResult(0);
        expect($('.two-company-id-hint').text()).toBe('11111111');

        search.clearSelectedCompany();

        expect($("input[name='companyid']").val()).toBe('');
        expect($('.two-company-id-hint').text()).toBe('');
        expect(shown($('.two-company-id-hint'))).toBe(false);
    });

    test('manual entry shows no company number at all', () => {
        makeInstance();
        openPanel();
        panelParts().notListed.trigger('click');

        expect($('.two-company-id-hint').text()).toBe('');
        expect(shown($('.two-company-id-hint'))).toBe(false);
    });
});

describe('the placeholder describes the mode the field is in (TWO-25326 §2)', () => {
    // Found on the staging shop in a real browser: after switching to manual
    // entry the field still read "Enter company name to search", instructing
    // the buyer to do something the field no longer does.
    test('manual entry swaps the search-mode placeholder', () => {
        const search = makeInstance();
        expect(companyField().attr('placeholder')).toBe('Enter company name to search');

        openPanel();
        panelParts().notListed.trigger('click');

        expect(companyField().attr('placeholder')).toBe('Enter your company name');
    });

    test('returning to search puts the search wording back', () => {
        const search = makeInstance();
        openPanel();
        panelParts().notListed.trigger('click');
        expect(companyField().attr('placeholder')).toBe('Enter your company name');

        search.exitManualEntryMode();

        expect(companyField().attr('placeholder')).toBe('Enter company name to search');
    });

    test("a theme's own placeholder is left alone in both modes", () => {
        // applyEmptyFieldHint() declines to overwrite a placeholder the theme
        // set; this must not undo that from the other direction.
        companyField().attr('placeholder', 'Firmennavn');
        const search = makeInstance();

        openPanel();
        panelParts().notListed.trigger('click');
        expect(companyField().attr('placeholder')).toBe('Firmennavn');

        search.exitManualEntryMode();
        expect(companyField().attr('placeholder')).toBe('Firmennavn');
    });
});
