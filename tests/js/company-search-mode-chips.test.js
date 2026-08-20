/**
 * TWO-40 design revision: the three-chip mode selector inside
 * TwoCompanySearch.js's dropdown - "Sole Trader", "Registered Company",
 * "Enter Manually". This file covers the two ALWAYS-visible chips and the
 * default selection; "Sole Trader"'s own conditional visibility and
 * activation are covered by company-search-sole-trader-entry.test.js, and
 * "Enter Manually"'s full manual-entry mechanics by
 * company-search-dropdown.test.js/company-search-rerender.test.js - this
 * file is about the CHIP ROW itself as a unit.
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
    shown
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

let TwoCompanySearch;
let $;

function makeInstance(config) {
    return new TwoCompanySearch(Object.assign({ checkoutHost: CHECKOUT_HOST }, config || {}));
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
    stubAjax($);
});

afterEach(() => {
    releaseWidgets($);
    jest.useRealTimers();
    delete global.window.TwoSoleTrader_Instance;
});

describe('the two unconditional chips', () => {
    test('"Registered Company" and "Enter Manually" are both real chips, always visible while the panel is open', () => {
        makeInstance();
        openPanel();

        const { registered, notListed } = panelParts();
        expect(registered.length).toBe(1);
        expect(registered.get(0).tagName).toBe('BUTTON');
        expect(shown(registered)).toBe(true);
        expect(shown(notListed)).toBe(true);
    });

    test('both are visible even with a company already confirmed - no confirmed-selection gate any more', () => {
        makeInstance();
        openPanel();
        // Simulate a confirmed selection the way onCompanySelected() leaves it:
        // company + org number + matching tag.
        $("input[name='company']").val('Example Trading Ltd');
        $("input[name='companyid']").val('11111111').attr('data-two-company-name', 'Example Trading Ltd');

        openPanel();
        expect(shown(panelParts().registered)).toBe(true);
        expect(shown(panelParts().notListed)).toBe(true);
    });

    test('both are hidden while the panel is closed', () => {
        makeInstance();
        expect(shown(panelParts().registered)).toBe(false);
        expect(shown(panelParts().notListed)).toBe(false);
    });
});

describe('chip DOM order (TWO-40 follow-up: Doug wants Registered/Sole Trader/Enter Manually)', () => {
    test('modeChips renders Registered Company, then Sole Trader, then Enter Manually', () => {
        makeInstance();
        openPanel();

        const chips = panelParts().modeChips.children().toArray();
        const classesInOrder = chips.map((el) => el.className);

        expect(classesInOrder).toHaveLength(3);
        expect(classesInOrder[0]).toContain('two-company-registered-entry');
        expect(classesInOrder[1]).toContain('two-company-sole-trader-entry');
        expect(classesInOrder[2]).toContain('two-company-not-listed');

        // No `order:` CSS on these chips (views/css/two.css) - visual order
        // is DOM order, so pinning the DOM sequence is sufficient; there is
        // no separate flex/grid `order` property to also assert against.
    });
});

describe('default selection (TWO-40: "Default selected chip: Registered Company")', () => {
    test('"Registered Company" carries the selected class on a fresh open', () => {
        makeInstance();
        openPanel();

        expect(panelParts().registered.hasClass('two-company-mode-chip--selected')).toBe(true);
    });

    test('re-opening after entering and leaving manual entry resets to "Registered Company"', () => {
        makeInstance();
        openPanel();
        panelParts().notListed.trigger('click');

        // Back in via the field (setupCompanyFieldOpeners' mousedown path),
        // exactly as a buyer re-opening search would.
        $("input[name='company']").trigger('mousedown');

        expect(panelParts().registered.hasClass('two-company-mode-chip--selected')).toBe(true);
    });
});

describe('clicking "Registered Company"', () => {
    test('exits manual-entry mode and re-opens ordinary search', () => {
        makeInstance();
        openPanel();
        panelParts().notListed.trigger('click');
        expect($("input[name='company']").attr('readonly')).toBeUndefined();

        // Re-enter search via the field, then click "Registered Company"
        // explicitly - proves the chip's own handler, not just re-opening,
        // gets the field back into search (readonly) mode.
        $("input[name='company']").trigger('mousedown');
        panelParts().registered.trigger('click');

        expect($("input[name='company']").attr('readonly')).toBe('readonly');
        expect(shown(panelParts().panel)).toBe(true);
    });

    test('cancels a pending sole-trader enrolment if one was started', () => {
        const soleTraderInstance = {
            isAvailableForCurrentCountry: () => true,
            startEnrollment: jest.fn(),
            abandonEnrollment: jest.fn()
        };
        global.window.TwoSoleTrader_Instance = soleTraderInstance;

        makeInstance();
        openPanel();
        panelParts().soleTrader.trigger('click');
        expect(soleTraderInstance.startEnrollment).toHaveBeenCalledTimes(1);
        const callsBefore = soleTraderInstance.abandonEnrollment.mock.calls.length;

        // Reopening ALSO abandons once, unconditionally, via openDropdown() -
        // isolate the chip's OWN call by asserting the exact delta (one from
        // the reopen, one from the chip click), not just "more than before". A
        // weaker assertion here would still pass even if the chip's own
        // handler stopped abandoning at all, since the reopen's call alone
        // already satisfies `toBeGreaterThan`.
        $("input[name='company']").trigger('mousedown');
        const callsAfterReopen = soleTraderInstance.abandonEnrollment.mock.calls.length;
        expect(callsAfterReopen).toBe(callsBefore + 1);

        panelParts().registered.trigger('click');

        expect(soleTraderInstance.abandonEnrollment.mock.calls.length).toBe(callsAfterReopen + 1);
    });
});

describe('clicking "Enter Manually" while a sole-trader enrolment is active', () => {
    /**
     * This test used to pin the OPPOSITE, and was wrong to (Doug, TWO-40
     * follow-up). A round-2 review observed that "Enter Manually" had no
     * cancel of its own and reasoned it was safe because the chip is
     * unreachable except through an open panel, and openDropdown() cancels
     * before the chip is even visible. The reopen's cancel does happen - but
     * it happens BEFORE the buyer clicks this chip, so it cannot disown a
     * lookup the buyer sets going afterwards by clicking Sole trader from the
     * reopened panel. That flight resolved into adoptSoleTraderBuyer(), which
     * has no manual-entry guard, over a name the buyer had typed by hand.
     *
     * So the chip now abandons on its own account, and what is pinned is that
     * it does so on the CLICK - the timing the earlier invariant could not
     * give.
     */
    test('abandons the flow on the click itself, not only via the earlier reopen', () => {
        const soleTraderInstance = {
            isAvailableForCurrentCountry: () => true,
            startEnrollment: jest.fn(),
            abandonEnrollment: jest.fn()
        };
        global.window.TwoSoleTrader_Instance = soleTraderInstance;

        makeInstance();
        // The FIRST open already abandons once too (openDropdown() does it
        // unconditionally, even with nothing to cancel yet) - baseline against
        // that rather than assuming zero.
        openPanel();
        const callsAfterFirstOpen = soleTraderInstance.abandonEnrollment.mock.calls.length;
        panelParts().soleTrader.trigger('click');
        expect(soleTraderInstance.abandonEnrollment.mock.calls.length).toBe(callsAfterFirstOpen);

        // Reopen, then start a SECOND enrolment from the reopened panel - the
        // flight the reopen's own abandon cannot possibly have covered.
        $("input[name='company']").trigger('mousedown');
        panelParts().soleTrader.trigger('click');
        expect(soleTraderInstance.startEnrollment).toHaveBeenCalledTimes(2);
        const callsBeforeChip = soleTraderInstance.abandonEnrollment.mock.calls.length;

        $("input[name='company']").trigger('mousedown');
        panelParts().notListed.trigger('click');

        // Two: the reopen's, and the chip's own.
        expect(soleTraderInstance.abandonEnrollment.mock.calls.length).toBe(callsBeforeChip + 2);
    });
});
