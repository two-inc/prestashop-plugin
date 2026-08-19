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
    shown
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

const NAMED_BUYER = {
    company_name: 'Sole Trader Test Co',
    organization_number: 'TWO:ST123456789012',
    email: 'buyer@example.test',
    billing_address: null,
    shipping_address: null
};

let TwoCompanySearch;
let $;

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
    stubAjax($);
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
    test('the query field is readonly on a reopen while adopted', () => {
        const instance = makeInstance();
        openPanel();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        instance.closeDropdown(false);
        $("input[name='company']").trigger('mousedown');

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

    test('the query field is editable again once "Registered Company" is clicked', () => {
        const instance = makeInstance();
        openPanel();
        instance.adoptSoleTraderBuyer(NAMED_BUYER);
        instance.closeDropdown(false);
        $("input[name='company']").trigger('mousedown');
        expect(panelParts().query.prop('readonly')).toBe(true);

        panelParts().registered.trigger('click');

        expect(panelParts().query.prop('readonly')).toBe(false);

        instance.destroy();
    });

    test('a fresh, never-adopted open leaves the query field editable', () => {
        const instance = makeInstance();
        openPanel();

        expect(panelParts().query.prop('readonly')).toBe(false);

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
