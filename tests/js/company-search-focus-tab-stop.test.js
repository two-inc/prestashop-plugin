/**
 * TWO-25503: the company-name field opens the popover on focus, so for as long
 * as that popover is open the field must not be a tab stop.
 *
 * WHY the pair is one change: the focus opener puts the caret in the popover's
 * query field. Left as a tab stop, the field catches shift+Tab coming back out
 * of the query and pushes focus forward again, and the two adjacent controls
 * oscillate - WCAG 2.1.2.
 *
 * WHAT IS NOT COVERED HERE: the keyboard traversal itself. jsdom implements no
 * sequential focus navigation, so a `Tab`/shift+Tab `KeyboardEvent` moves focus
 * nowhere in either direction and a trap is unobservable. What is asserted is
 * the state the browser derives tab order FROM - `tabindex` on the field - held
 * while the popover is open and restored on every route out of it. Pressing
 * shift+Tab out of the query field is a real-browser check and this ticket
 * requires one.
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
let ajax;

function makeInstance(config) {
    return new TwoCompanySearch(Object.assign({ checkoutHost: CHECKOUT_HOST }, config || {}));
}

function companyField() {
    return $("input[name='company']");
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
    window.twopayment = {
        order_intent_url: 'https://shop.example.test/module/twopayment/orderintent',
        ajax_token: 'test-token'
    };
});

afterEach(() => {
    releaseWidgets($);
    ajax.restore();
    jest.useRealTimers();
    delete window.twopayment;
});

describe('the open popover holds the company field out of the tab order', () => {
    test('the field carries no tabindex until the popover opens', () => {
        makeInstance();
        expect(companyField().attr('tabindex')).toBeUndefined();

        openPanel();

        expect(companyField().attr('tabindex')).toBe('-1');
    });

    test('-1, not removed from the order outright: the Escape return still lands', () => {
        // closeDropdown(true) focuses the field, which a non-focusable field
        // could not take - and that return is what Escape means.
        const instance = makeInstance();
        openPanel();

        instance.closeDropdown(true);

        expect(document.activeElement).toBe(companyField().get(0));
    });

    test.each([
        ['Escape and a completed selection', (instance) => instance.closeDropdown(true)],
        ['focus leaving the panel', (instance) => instance.closeDropdown(false)],
        ['a re-render dropping the panel', (instance) => instance.removeDropdown()],
        ['teardown', (instance) => instance.destroy()],
        ['the Enter manually chip', (instance) => instance.enterManualEntryMode()]
    ])('the field is a tab stop again after %s', (why, close) => {
        const instance = makeInstance();
        openPanel();
        expect(companyField().attr('tabindex')).toBe('-1');

        close(instance);

        expect({ why: why, tabindex: companyField().attr('tabindex') })
            .toEqual({ why: why, tabindex: undefined });
    });

    test('a tabindex the theme set is given back exactly, not removed', () => {
        makeInstance();
        companyField().attr('tabindex', '3');
        openPanel();
        expect(companyField().attr('tabindex')).toBe('-1');

        panelParts().query.get(0).blur();
        $("input[name='dni']").get(0).focus();
        jest.advanceTimersByTime(10);

        expect(companyField().attr('tabindex')).toBe('3');
    });

    test('a panel rebuilt while the popover was open leaves the field closed and in the order', () => {
        // A host that discards the wrapper but keeps the field brings
        // buildDropdown() back to build a fresh, hidden panel. The state that
        // panel establishes is closed, so the field it belongs to must be back
        // in the tab order - otherwise it is stranded at -1 with nothing on
        // screen to put it back.
        const instance = makeInstance();
        openPanel();
        expect(companyField().attr('tabindex')).toBe('-1');

        panelParts().panel.remove();
        instance.buildDropdown();

        expect(instance._dropdownOpen).toBe(false);
        expect(shown(panelParts().panel)).toBe(false);
        expect(companyField().attr('tabindex')).toBeUndefined();
    });
});
