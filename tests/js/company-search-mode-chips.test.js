/**
 * TWO-40: the chip row itself. "Sole Trader"'s conditional visibility lives in
 * company-search-sole-trader-entry.test.js; manual-entry mechanics in
 * company-search-dropdown.test.js/company-search-rerender.test.js.
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
        // A confirmed selection as onCompanySelected() leaves it.
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

        // No `order:` CSS on these chips, so visual order is DOM order.
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

        // Re-enter via the field first, so the readonly state below is the
        // chip's own handler rather than the reopen.
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

        // Reopening ALSO abandons once via openDropdown(), so the chip's own
        // call is only visible as an exact delta, not as "more than before".
        $("input[name='company']").trigger('mousedown');
        const callsAfterReopen = soleTraderInstance.abandonEnrollment.mock.calls.length;
        expect(callsAfterReopen).toBe(callsBefore + 1);

        panelParts().registered.trigger('click');

        expect(soleTraderInstance.abandonEnrollment.mock.calls.length).toBe(callsAfterReopen + 1);
    });
});

describe('clicking "Enter Manually" while a sole-trader enrolment is active', () => {
    /**
     * TWO-40 follow-up. openDropdown()'s cancel is not enough: it runs BEFORE
     * the buyer clicks this chip, so it cannot disown a lookup started
     * afterwards by clicking Sole trader from the reopened panel. That flight
     * resolves into adoptSoleTraderBuyer(), which has no manual-entry guard,
     * over a hand-typed name.
     */
    test('abandons the flow on the click itself, not only via the earlier reopen', () => {
        const soleTraderInstance = {
            isAvailableForCurrentCountry: () => true,
            startEnrollment: jest.fn(),
            abandonEnrollment: jest.fn()
        };
        global.window.TwoSoleTrader_Instance = soleTraderInstance;

        makeInstance();
        // The FIRST open already abandons once, even with nothing to cancel.
        openPanel();
        const callsAfterFirstOpen = soleTraderInstance.abandonEnrollment.mock.calls.length;
        panelParts().soleTrader.trigger('click');
        expect(soleTraderInstance.abandonEnrollment.mock.calls.length).toBe(callsAfterFirstOpen);

        // A SECOND enrolment from the reopened panel - the flight the reopen's
        // own abandon cannot have covered.
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

/**
 * TWO-25503: manual entry captures no company number, and the address-step
 * lookup is the only path that captures one, so the chip is only offered while
 * company search lives in the address area.
 */
describe('"Enter Manually" is gated on address-area company search', () => {
    test.each([
        [undefined, true, 'absent flag reads as address-area, matching the server-side default'],
        [true, true, 'address-area search: all three chips'],
        [false, false, 'tile-mounted search: Registered + Sole Trader only']
    ])('companySearchInAddressArea=%p -> "Enter Manually" shown=%p (%s)', (inAddressArea, manualShown, description) => {
        global.window.TwoSoleTrader_Instance = { isAvailableForCurrentCountry: () => true };
        makeInstance(inAddressArea === undefined ? {} : { companySearchInAddressArea: inAddressArea });
        openPanel();

        const { registered, soleTrader, notListed } = panelParts();
        // The other two chips are unaffected by this gate.
        expect(shown(registered)).toBe(true);
        expect(shown(soleTrader)).toBe(true);
        expect(shown(notListed)).toBe(manualShown);
        expect(notListed.length).toBe(1);
    });
});
