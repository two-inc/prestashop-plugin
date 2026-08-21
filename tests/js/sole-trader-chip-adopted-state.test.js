/**
 * TWO-40 follow-up, Doug live-test findings (2026-08-19):
 *
 *  1. Reopening the dropdown while a sole trader is adopted must show the
 *     "Sole Trader" chip selected, not silently fall back to "Registered
 *     Company" (openDropdown() previously reset `_chipMode` unconditionally
 *     on every open).
 *  2. The free-text query input must not be usable while the Sole Trader
 *     chip is selected - there is deliberately only one way to pick a
 *     different company in that state (item 3 below), typing a query is
 *     not it.
 *  3. Re-clicking the "Sole Trader" chip while already adopted must behave
 *     EXACTLY like the standalone "Select a different sole trader" link
 *     (triggerSelectDifferentSoleTrader()/startReplacement()), not start a
 *     fresh enrolment and not no-op.
 */

'use strict';

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
    shown
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

const SEARCH_RESPONSE = {
    items: [
        { name: 'Example Trading Ltd', lookup_id: 'lk-1', national_identifier: { id: '11111111' } },
        { name: 'Example Holdings Ltd', lookup_id: 'lk-2', national_identifier: { id: '22222222' } }
    ]
};

const NAMED_BUYER = {
    company_name: 'Sole Trader Test Co',
    organization_number: 'TWO:ST123456789012',
    email: 'buyer@example.test',
    billing_address: null,
    shipping_address: null
};

let TwoCompanySearch;
let $;
let ajax;

function makeInstance(config) {
    return new TwoCompanySearch(Object.assign({ checkoutHost: CHECKOUT_HOST }, config || {}));
}

function stubSoleTrader() {
    const instance = {
        isAvailableForCurrentCountry: jest.fn(() => true),
        startEnrollment: jest.fn(),
        startReplacement: jest.fn(),
        cancelEnrollment: jest.fn()
    };
    global.window.TwoSoleTrader_Instance = instance;
    return instance;
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
    delete global.window.TwoSoleTrader_Instance;
});

describe('reopening while sole-trader-adopted (item 1)', () => {
    test('shows the "Sole Trader" chip selected, not "Registered Company"', () => {
        const instance = makeInstance();
        openPanel();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);

        // Close, then reopen exactly as a buyer clicking back into the
        // (readonly) company field would.
        instance.closeDropdown(false);
        $("input[name='company']").trigger('mousedown');

        expect(panelParts().soleTrader.hasClass('two-company-mode-chip--selected')).toBe(true);
        expect(panelParts().registered.hasClass('two-company-mode-chip--selected')).toBe(false);

        instance.destroy();
    });

    test('a fresh instance with no adoption still defaults to "Registered Company"', () => {
        const instance = makeInstance();
        openPanel();

        expect(panelParts().registered.hasClass('two-company-mode-chip--selected')).toBe(true);
        expect(panelParts().soleTrader.hasClass('two-company-mode-chip--selected')).toBe(false);

        instance.destroy();
    });
});

describe('query input suppressed while Sole Trader is selected (item 2)', () => {
    /**
     * HIDDEN, not merely readonly (Doug, 2026-08-19, second live round on this
     * same rule): "the field should not be VISIBLE. I did not tell you it was
     * editable, I told you it was visible." A readonly-but-painted search box
     * reads as a search box that has stopped working, so the readonly
     * assertions below are kept only as the defence-in-depth half - the
     * visibility ones are the requirement.
     */
    function adoptAndReopen() {
        const instance = makeInstance();
        openPanel();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        instance.closeDropdown(false);
        $("input[name='company']").trigger('mousedown');
        return instance;
    }

    /** Focusable panel controls, in document order, that are actually rendered. */
    function tabStops() {
        return panelParts().panel.find('input, button, a[href], [tabindex]')
            .filter(function () { return $(this).attr('tabindex') !== '-1'; })
            .get()
            .filter((node) => shown(node));
    }

    test('the query field is not VISIBLE on a reopen while adopted', () => {
        const instance = adoptAndReopen();

        expect(shown(panelParts().query)).toBe(false);
        // The whole row, spinner included - a lone absolutely-positioned
        // spinner in a collapsed row is not "hidden", it is misplaced.
        expect(shown(panelParts().searchRow)).toBe(false);
        // The panel itself is still open around it.
        expect(shown(panelParts().panel)).toBe(true);

        instance.destroy();
    });

    test('a hidden query field is out of the tab order, not just unpainted', () => {
        const instance = adoptAndReopen();

        // `display:none` removes it; `visibility:hidden`/`opacity:0` would
        // not, and a keyboard-only buyer would tab into an invisible input.
        expect(tabStops()).not.toContain(panelParts().query.get(0));
        // The chips it sits above are still reachable, so this is the field
        // leaving the tab order rather than the panel doing so.
        expect(tabStops()).toContain(panelParts().registered.get(0));

        instance.destroy();
    });

    test('focus opens on the selected chip, INSIDE the panel, so Escape can still close it', () => {
        const instance = makeInstance();
        stubSoleTrader();
        openPanel();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        instance.closeDropdown(false);
        $("input[name='company']").trigger('mousedown');

        // The field openDropdown() normally focuses is not rendered, and
        // focusing a `display:none` element is a no-op - which would strand
        // focus on the company-name field, outside the panel, where neither
        // the Escape handler nor the close-on-focus-leave one ever sees a
        // keystroke.
        expect(document.activeElement).toBe(panelParts().soleTrader.get(0));

        $(document.activeElement).trigger($.Event('keydown', { key: 'Escape' }));

        expect(shown(panelParts().panel)).toBe(false);

        instance.destroy();
    });

    test('falls back to the Registered company chip when the Sole trader chip is itself hidden', () => {
        // Adopted, but the registry no longer offers this country - the one
        // state where the selected chip is not on screen to receive focus.
        const instance = adoptAndReopen();

        expect(shown(panelParts().soleTrader)).toBe(false);
        expect(document.activeElement).toBe(panelParts().registered.get(0));
        expect(panelParts().panel.get(0).contains(document.activeElement)).toBe(true);

        instance.destroy();
    });

    test('the query field is readonly too, on top of being hidden', () => {
        const instance = adoptAndReopen();

        expect(panelParts().query.prop('readonly')).toBe(true);

        instance.destroy();
    });

    test('typing into the (readonly) query field triggers no search request', () => {
        const instance = makeInstance();
        openPanel();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        instance.closeDropdown(false);
        $("input[name='company']").trigger('mousedown');

        const ajaxStub = stubAjax($);
        typeQuery('Some Other Company');
        jest.runAllTimers();

        expect(ajaxStub.calls.length).toBe(0);

        instance.destroy();
    });

    test('the query field is visible and editable again once "Registered Company" is clicked', () => {
        const instance = adoptAndReopen();
        stubSoleTrader();
        expect(shown(panelParts().query)).toBe(false);

        panelParts().registered.trigger('click');

        expect(shown(panelParts().query)).toBe(true);
        expect(shown(panelParts().searchRow)).toBe(true);
        expect(tabStops()).toContain(panelParts().query.get(0));
        expect(panelParts().query.prop('readonly')).toBe(false);

        instance.destroy();
    });

    test('a term half-typed before picking Sole trader does not come back with the field', () => {
        const instance = makeInstance();
        stubSoleTrader();
        openPanel();
        typeQuery('Half A Compa');

        // Driven WITHOUT a close in between, deliberately: closeDropdown()
        // empties the field on its own, so a route through it would leave
        // this passing whatever the hide does with the term.
        panelParts().soleTrader.trigger('click');
        panelParts().registered.trigger('click');

        // A query describing a company the buyer then did NOT pick, restored
        // above results that no longer match it, is worse than an empty field.
        expect(panelParts().query.val()).toBe('');

        instance.destroy();
    });

    test('a fresh, never-adopted open leaves the query field visible and editable', () => {
        const instance = makeInstance();
        openPanel();

        expect(shown(panelParts().query)).toBe(true);
        expect(panelParts().query.prop('readonly')).toBe(false);

        instance.destroy();
    });

    /**
     * The direct reversal of what this test asserted for one round (Doug,
     * TWO-40 follow-up): the row used to be un-hidden again for the duration
     * of the flight, because the in-flight spinner lived inside it. The
     * spinner has moved to the company-name field, and the rule is now that
     * the hide is a pure function of the selected chip - "gate on mode alone,
     * nothing else". A regression here means someone reintroduced a second
     * condition in syncQueryFieldSuppression().
     */
    test('the row is hidden IMMEDIATELY by the chip click, and stays hidden for the flight', () => {
        const instance = makeInstance();
        stubSoleTrader();
        openPanel();
        expect(shown(panelParts().searchRow)).toBe(true);

        panelParts().soleTrader.trigger('click');

        // Synchronous with the click - no reopen needed, and the flight this
        // same click just started does not buy the row a reprieve.
        expect(shown(panelParts().searchRow)).toBe(false);
        expect(shown(panelParts().query)).toBe(false);
        // The panel is still open around it, and the spinner is on the name
        // field where it can actually be seen.
        expect(shown(panelParts().panel)).toBe(true);
        expect(shown(panelParts().nameSpinner)).toBe(true);

        instance.destroy();
    });

    /**
     * The reopen-independence half of the same rule. `openPanel()` is what
     * ran syncQueryFieldSuppression() in the version of this code where the
     * sync was only reachable from the open path, so a test that closes and
     * reopens cannot tell the two implementations apart - this one never
     * closes the panel at all, and drives the mode purely from clicks.
     */
    test('the row hides and returns on chip clicks alone, with no close or reopen in between', () => {
        const instance = makeInstance();
        stubSoleTrader();
        openPanel();

        panelParts().soleTrader.trigger('click');
        expect(shown(panelParts().searchRow)).toBe(false);
        expect(shown(panelParts().panel)).toBe(true);

        panelParts().registered.trigger('click');
        expect(shown(panelParts().searchRow)).toBe(true);
        expect(shown(panelParts().panel)).toBe(true);

        panelParts().soleTrader.trigger('click');
        expect(shown(panelParts().searchRow)).toBe(false);
        expect(shown(panelParts().panel)).toBe(true);

        instance.destroy();
    });
});

/**
 * Cross-round gap (final review before merge): item 1 made a reopen land in
 * sole-trader mode, item 2 blanked the query field on the way into that mode -
 * and nothing emptied the RESULT ROWS the blanked term had produced. jQuery
 * UI's `response([])` only runs `_close()`, which hides the menu without
 * emptying it, and this mode is the one that keeps the panel OPEN (manual entry
 * closes it), so those rows stayed painted and clickable: registered companies
 * still on offer for a term the field no longer held, next to a search row that
 * is not even rendered.
 */
describe('no stale result rows survive into sole-trader mode', () => {
    function searchAndSettle(term) {
        typeQuery(term);
        jest.advanceTimersByTime(400);
        const pending = ajax.last();
        if (pending) {
            pending.succeed(SEARCH_RESPONSE);
        }
        jest.advanceTimersByTime(50);
    }

    test('reopening while adopted does not re-offer the previous search\'s results', () => {
        const instance = makeInstance();
        stubSoleTrader();
        openPanel();
        searchAndSettle('Example');
        expect(resultTexts().length).toBeGreaterThan(0);

        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        instance.closeDropdown(false);
        $("input[name='company']").trigger('mousedown');

        // The field is hidden and blank here (see item 2 above), so a list of
        // registered companies above it belongs to nothing the buyer can see.
        expect(resultTexts()).toEqual([]);

        instance.destroy();
    });

    test('picking the chip mid-search clears the rows that search produced', () => {
        const instance = makeInstance();
        stubSoleTrader();
        openPanel();
        searchAndSettle('Example');

        panelParts().soleTrader.trigger('click');

        // The panel stays open for the flight (round 4's keep-open window), so
        // this is on screen, not merely in the DOM.
        expect(shown(panelParts().panel)).toBe(true);
        expect(resultTexts()).toEqual([]);

        instance.destroy();
    });

    test('coming back to "Registered company" starts from an empty list, not the old one', () => {
        const instance = makeInstance();
        stubSoleTrader();
        openPanel();
        searchAndSettle('Example');

        panelParts().soleTrader.trigger('click');
        panelParts().registered.trigger('click');

        // The term went with the trip into sole-trader mode; results matching
        // it must not outlive it.
        expect(panelParts().query.val()).toBe('');
        expect(resultTexts()).toEqual([]);

        instance.destroy();
    });
});

describe('re-clicking "Sole Trader" while adopted (item 3)', () => {
    test('calls startReplacement(), not startEnrollment() - same as the standalone link', () => {
        const instance = makeInstance();
        openPanel();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        instance.closeDropdown(false);
        $("input[name='company']").trigger('mousedown');

        const soleTrader = stubSoleTrader();
        panelParts().soleTrader.trigger('click');

        expect(soleTrader.startReplacement).toHaveBeenCalledTimes(1);
        expect(soleTrader.startEnrollment).not.toHaveBeenCalled();

        instance.destroy();
    });

    test('a rapid double-click only calls startReplacement() once (shares the link\'s re-entrancy guard)', () => {
        const instance = makeInstance();
        openPanel();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        instance.closeDropdown(false);
        $("input[name='company']").trigger('mousedown');

        const soleTrader = stubSoleTrader();
        panelParts().soleTrader.trigger('click');
        panelParts().soleTrader.trigger('click');

        expect(soleTrader.startReplacement).toHaveBeenCalledTimes(1);

        instance.destroy();
    });

    test('the chip still shows selected after routing through startReplacement()', () => {
        const instance = makeInstance();
        openPanel();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        instance.closeDropdown(false);
        $("input[name='company']").trigger('mousedown');

        stubSoleTrader();
        panelParts().soleTrader.trigger('click');

        expect(panelParts().soleTrader.hasClass('two-company-mode-chip--selected')).toBe(true);

        instance.destroy();
    });
});

describe('clicking back to "Registered company" while adopted (item 4)', () => {
    /**
     * The way out of the adopted state without launching a signup: the
     * "Registered company" chip is deliberately NOT a hand-off to another
     * flow, so it must leave the panel open with the buyer able to type a
     * query immediately. Pinned here rather than assumed - the sole-trader
     * chip's own handler closes the panel on one of its branches, and this
     * one runs cancelEnrollment(), which fires the same settle event another
     * listener answers by closing.
     */
    function adoptAndReopen() {
        const instance = makeInstance();
        openPanel();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        instance.closeDropdown(false);
        $("input[name='company']").trigger('mousedown');
        stubSoleTrader();
        return instance;
    }

    test('the panel stays OPEN, with the "Registered company" chip selected', () => {
        const instance = adoptAndReopen();

        panelParts().registered.trigger('click');

        expect(shown(panelParts().panel)).toBe(true);
        expect(panelParts().registered.hasClass('two-company-mode-chip--selected')).toBe(true);
        expect(panelParts().soleTrader.hasClass('two-company-mode-chip--selected')).toBe(false);

        instance.destroy();
    });

    test('focus lands in the query field, which is typable again', () => {
        const instance = adoptAndReopen();

        panelParts().registered.trigger('click');

        expect(document.activeElement).toBe(panelParts().query.get(0));
        expect(panelParts().query.prop('readonly')).toBe(false);

        instance.destroy();
    });
});

describe('unaffected: a fresh (non-adopted) "Sole Trader" click still enrolls normally', () => {
    test('calls startEnrollment(), not startReplacement()', () => {
        const instance = makeInstance();
        const soleTrader = stubSoleTrader();
        openPanel();

        panelParts().soleTrader.trigger('click');

        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
        expect(soleTrader.startReplacement).not.toHaveBeenCalled();

        instance.destroy();
    });
});
