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
            cancelEnrollment: jest.fn()
        };
        global.window.TwoSoleTrader_Instance = soleTraderInstance;

        makeInstance();
        openPanel();
        panelParts().soleTrader.trigger('click');
        expect(soleTraderInstance.startEnrollment).toHaveBeenCalledTimes(1);
        const cancelCallsBefore = soleTraderInstance.cancelEnrollment.mock.calls.length;

        $("input[name='company']").trigger('mousedown');
        panelParts().registered.trigger('click');

        expect(soleTraderInstance.cancelEnrollment.mock.calls.length).toBeGreaterThan(cancelCallsBefore);
    });
});
