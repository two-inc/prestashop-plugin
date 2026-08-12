/**
 * TWO-40: the two upfront Business / Sole trader chips are gone, and sole
 * trader enrolment is folded directly into the company search control. This
 * pins the "I'm a sole trader" row TwoCompanySearch.js adds to its dropdown,
 * a sibling of "My company is not on the list" (see buildDropdown()).
 *
 * The row's own gate - whether the registry says the current billing
 * country supports sole traders at all - lives in TwoSoleTrader.js
 * (isAvailableForCurrentCountry()); these tests stub that directly via
 * `window.TwoSoleTrader_Instance` rather than loading the real module, so
 * they are about TwoCompanySearch's wiring, not TwoSoleTrader's own
 * availability resolution (covered by sole-trader-server-rendered-toggle.test.js).
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

let TwoCompanySearch;
let $;

function makeInstance(config) {
    return new TwoCompanySearch(Object.assign({ checkoutHost: CHECKOUT_HOST }, config || {}));
}

/** Install a stub TwoSoleTrader_Instance, as twopayment.js's global would be. */
function stubSoleTrader(available) {
    const instance = {
        isAvailableForCurrentCountry: jest.fn(() => available),
        startEnrollment: jest.fn(),
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

describe('visibility', () => {
    test('is shown when TwoSoleTrader says the country is eligible', () => {
        stubSoleTrader(true);
        makeInstance();
        openPanel();

        expect(shown(panelParts().soleTrader)).toBe(true);
        expect(panelParts().soleTrader.text()).toBe('Sole Trader');
    });

    test('is hidden when TwoSoleTrader says the country is not eligible', () => {
        stubSoleTrader(false);
        makeInstance();
        openPanel();

        expect(shown(panelParts().soleTrader)).toBe(false);
    });

    test('is hidden when TwoSoleTrader_Instance does not exist yet (fail-soft)', () => {
        makeInstance();
        openPanel();

        expect(shown(panelParts().soleTrader)).toBe(false);
    });

    test('is hidden while in manual-entry mode - same open/manual-entry/confirmed gate as "not on the list"', () => {
        stubSoleTrader(true);
        makeInstance();
        openPanel();
        panelParts().notListed.trigger('click');

        expect(shown(panelParts().soleTrader)).toBe(false);
    });
});

describe('activation', () => {
    test('clicking it starts sole-trader enrolment and closes the panel after a paint', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        openPanel();
        typeQuery('exa');

        panelParts().soleTrader.trigger('click');

        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
        // Not synchronous any more (TWO-40 round 3 paint-timing fix) - see the
        // regression test below for why.
        expect(shown(panelParts().panel)).toBe(true);

        jest.advanceTimersByTime(20);

        expect(shown(panelParts().panel)).toBe(false);
    });

    /**
     * Regression test (TWO-40 round 3, live-verified against a real browser -
     * see .ai/decisions.md): PR #159 added renderChipSelection() but called it
     * in the SAME synchronous tick as closeDropdown(), so a real browser never
     * painted a single frame with the `--selected` class applied before the
     * panel's `display:none` hid it again - zero rendered frames ever showed
     * the selection to a buyer, even though jsdom (which has no render/paint
     * step) reported the class as set immediately and PR #159's own test
     * passed on exactly that basis. This pins the actual requirement: the
     * panel must still be visibly OPEN, with the class already applied, for
     * at least one tick after the click - not merely "the DOM node eventually
     * carries the class, in a document nobody was watching".
     */
    test('the selected chip stays visibly open for at least one frame before the panel closes', () => {
        stubSoleTrader(true);
        makeInstance();
        openPanel();

        const { soleTrader } = panelParts();
        soleTrader.trigger('click');

        // Still open AND already showing the selection - this is the frame a
        // real buyer would actually see.
        expect(shown(panelParts().panel)).toBe(true);
        expect(soleTrader.hasClass('two-company-mode-chip--selected')).toBe(true);

        jest.advanceTimersByTime(20);

        expect(shown(panelParts().panel)).toBe(false);
    });

    test('does nothing destructive if TwoSoleTrader_Instance is missing', () => {
        stubSoleTrader(true);
        makeInstance();
        openPanel();
        // Row is built (and clickable in principle) even without the global;
        // remove it to prove the handler guards rather than throwing.
        delete global.window.TwoSoleTrader_Instance;

        expect(() => panelParts().soleTrader.trigger('click')).not.toThrow();
    });

    /**
     * Regression test: the handler correctly set `this._chipMode =
     * 'sole_trader'` and started enrolment, but never called
     * renderChipSelection() to reflect that onto the DOM - so
     * "Registered Company" (the default) kept the `--selected` class
     * forever and "Sole Trader" never got it, even though its own
     * handler had just fired successfully. Asserting `startEnrollment`
     * was called (as the test above does) does NOT catch this - the
     * handler ran fine, only its cosmetic class write was missing.
     * Checked directly against the DOM classes rather than `_chipMode`
     * (an internal field a future refactor could rename) because the
     * live bug this pins was observed exactly this way: DevTools reading
     * `.className` on the real chip buttons.
     */
    test('marks itself selected and un-marks "Registered Company", even though the panel closes', () => {
        stubSoleTrader(true);
        makeInstance();
        openPanel();

        const { registered, soleTrader, notListed } = panelParts();
        expect(registered.hasClass('two-company-mode-chip--selected')).toBe(true);

        soleTrader.trigger('click');

        expect(soleTrader.hasClass('two-company-mode-chip--selected')).toBe(true);
        expect(registered.hasClass('two-company-mode-chip--selected')).toBe(false);
        expect(notListed.hasClass('two-company-mode-chip--selected')).toBe(false);
    });
});

describe('reopening search cancels a pending enrolment (TWO-40)', () => {
    test('opening the dropdown again cancels an in-progress sole-trader enrolment', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        // openDropdown() unconditionally calls cancelEnrollment() on every
        // open - cheap and idempotent when nothing was pending - so this
        // baselines against the FIRST open rather than asserting zero calls.
        openPanel();
        const callsBeforeEnrolling = soleTrader.cancelEnrollment.mock.calls.length;
        panelParts().soleTrader.trigger('click');
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);

        // The buyer comes back to ordinary company search - e.g. clicking the
        // company field again - rather than completing the sole-trader flow.
        openPanel();

        expect(soleTrader.cancelEnrollment.mock.calls.length).toBe(callsBeforeEnrolling + 1);
    });
});
