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
        cancelEnrollment: jest.fn(),
        closeSignupPopup: jest.fn()
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
        expect(panelParts().soleTrader.text()).toBe('Sole trader');
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
    /**
     * Regression test (TWO-40 round 3, live-verified against a real browser -
     * see .ai/decisions.md): PR #159 added renderChipSelection() but called it
     * in the SAME synchronous tick as closeDropdown(), so a real browser never
     * painted a single frame with the `--selected` class applied before the
     * panel's `display:none` hid it again - zero rendered frames ever showed
     * the selection to a buyer, even though jsdom (which has no render/paint
     * step) reported the class as set immediately and PR #159's own test
     * passed on exactly that basis. Superseded functionally by the round-4
     * keep-open behaviour below (the panel now stays open far longer than one
     * frame), but pinned in its own right: the selection must be visible
     * WHILE the panel is still open, not merely "eventually true in a
     * document nobody was watching".
     */
    test('the selected chip is visibly applied while the panel is still open, not only after it closes', () => {
        stubSoleTrader(true);
        makeInstance();
        openPanel();

        const { soleTrader } = panelParts();
        soleTrader.trigger('click');

        expect(shown(panelParts().panel)).toBe(true);
        expect(soleTrader.hasClass('two-company-mode-chip--selected')).toBe(true);
    });

    /**
     * TWO-40 round 4, Doug's explicit request: keep the company search
     * control open, with a spinner, for the duration of the Sole Trader
     * autofill round trip. Driven by the REAL settle event
     * TwoSoleTrader.js's notifyEnrollmentSettled() fires (see
     * TwoSoleTrader.js), not a fixed timeout - the panel must stay open for
     * however long the actual call takes, and no longer.
     *
     * The spinner is on the company-NAME field, not the query field (TWO-40
     * follow-up, Doug). The query field's whole row is hidden the instant
     * this chip is selected, so a spinner in it would have nowhere to paint;
     * and the name field is where the value being fetched is going to land.
     */
    test('clicking it starts sole-trader enrolment, keeps the panel open with the name-field spinner, and only closes when the flight settles', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        openPanel();
        typeQuery('exa');

        panelParts().soleTrader.trigger('click');

        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
        expect(shown(panelParts().panel)).toBe(true);
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(true);
        expect(shown(panelParts().nameSpinner)).toBe(true);
        // And NOT in the query field it used to live in - that row is gone.
        expect(panelParts().query.hasClass('two-company-search-loading')).toBe(false);

        // No fixed timeout closes it - it would still be open five seconds
        // later if the real call were still out.
        jest.advanceTimersByTime(5000);
        expect(shown(panelParts().panel)).toBe(true);
        expect(shown(panelParts().nameSpinner)).toBe(true);

        document.dispatchEvent(new CustomEvent('two:sole-trader-flight-settled'));

        expect(shown(panelParts().panel)).toBe(false);
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(false);
        expect(shown(panelParts().nameSpinner)).toBe(false);
    });

    test('does nothing destructive if TwoSoleTrader_Instance is missing, and still closes (after a paint) rather than dead-ending open', () => {
        stubSoleTrader(true);
        makeInstance();
        openPanel();
        // Row is built (and clickable in principle) even without the global;
        // remove it to prove the handler guards rather than throwing.
        delete global.window.TwoSoleTrader_Instance;

        expect(() => panelParts().soleTrader.trigger('click')).not.toThrow();

        // TWO-40 round 5 (adversarial review, round 2 follow-up): this
        // fallback branch does not go through beginSoleTraderLoading()'s
        // keep-open window at all, so it needs its OWN paint-timing fix
        // (deferred by one requestAnimationFrame) - round 1's review caught
        // that the round-4 rewrite had silently dropped it here, reopening
        // the exact same-tick "renderChipSelection() then closeDropdown()"
        // bug this whole PR chain exists to fix. This assertion is what
        // that fix's own regression test was missing: not just "doesn't
        // throw", but "actually closes, deferred, rather than staying open
        // forever with nothing left to close it".
        jest.advanceTimersByTime(20);
        expect(shown(panelParts().panel)).toBe(false);
    });

    /**
     * Regression test (TWO-40 round 5, adversarial review finding - Han and
     * Vader independently caught this): round 4 keeps the chip clickable for
     * the WHOLE round trip instead of closing on the first click, which
     * newly makes a second click reachable while the first is still
     * waiting. Without a guard, the second click re-entered
     * startEnrollment() and could fire a second, concurrent buyer lookup -
     * on the no-match path, that meant TWO signup popup windows from one
     * buyer gesture.
     */
    test('a second click while already loading does not start a second enrolment attempt', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        openPanel();

        panelParts().soleTrader.trigger('click');
        panelParts().soleTrader.trigger('click');
        panelParts().soleTrader.trigger('click');

        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
    });

    test('a fresh click after the flight has settled is allowed to start a new attempt', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        openPanel();

        panelParts().soleTrader.trigger('click');
        document.dispatchEvent(new CustomEvent('two:sole-trader-flight-settled'));
        // Re-open - the panel closed when the flight settled, same as any
        // other close.
        openPanel();
        panelParts().soleTrader.trigger('click');

        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(2);
    });

    /**
     * Regression test (TWO-40 round 5, Vader finding): startEnrollment() is
     * foreign-module code called with no try/catch before this fix - a
     * synchronous throw left the panel open with the spinner running and
     * nothing left to ever settle it, since beginSoleTraderLoading() had
     * already run.
     */
    test('a synchronous throw from startEnrollment() does not leave the panel stuck open with the spinner running', () => {
        const soleTrader = stubSoleTrader(true);
        soleTrader.startEnrollment.mockImplementation(() => {
            throw new Error('boom');
        });
        makeInstance();
        openPanel();

        panelParts().soleTrader.trigger('click');

        expect(shown(panelParts().panel)).toBe(false);
        expect(panelParts().query.hasClass('two-company-search-loading')).toBe(false);
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

describe('focus returning to the checkout page abandons the flow (TWO-40 follow-up, Doug live test)', () => {
    /**
     * Doug's live finding: the spinner stopped and the panel closed when the
     * buyer clicked back onto the checkout page with the hosted signup popup
     * still open, but the popup itself stayed on screen. All three go together.
     *
     * Driven with a real focusable element outside the panel rather than
     * `<body>`, for the same reason company-search-dropdown.test.js's own
     * focus-out tests are: jsdom will not make `<body>` the activeElement, so
     * the guard this has to get PAST would hold vacuously.
     */
    test('closes the hosted signup popup, and not only the panel and the spinner', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        openPanel();
        panelParts().soleTrader.trigger('click');
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();

        const outside = $("input[name='dni']").get(0);
        outside.focus();
        panelParts().panel.trigger('focusout');
        jest.advanceTimersByTime(10);

        expect(soleTrader.closeSignupPopup).toHaveBeenCalledTimes(1);
        // The two that already worked before this fix - asserted separately so
        // a regression in either is not mistaken for the popup one.
        expect(shown(panelParts().panel)).toBe(false);
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(false);
    });

    /**
     * The same focus-out, caused by the buyer clicking a DIFFERENT chip on the
     * panel. The pending close is cancelled by the `focusin` that chip's own
     * handler produces when it puts focus back in the query field, so the
     * popup-close must not run either - "Registered Company" means "stay here,
     * search normally", and it owns what happens to the flow (endSoleTraderLoading()
     * then cancelEnrollment()).
     *
     * Whether it should ALSO take an abandoned popup down is a separate
     * question, deliberately not answered here: its route is
     * cancelEnrollment(), which openDropdown() calls on every open and which
     * TwoSoleTrader.js documents as "still glancing around" rather than
     * "abandoned".
     */
    test('a focus-out from clicking a different chip leaves the popup to that chip handler', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        openPanel();
        panelParts().soleTrader.trigger('click');

        const { panel, registered } = panelParts();
        panel.trigger('focusout');
        registered.trigger('click');
        jest.advanceTimersByTime(10);

        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();
        expect(soleTrader.cancelEnrollment).toHaveBeenCalled();
        expect(shown(panelParts().panel)).toBe(true);
    });

    /**
     * The second layer of the same guard, and the one that makes the popup
     * close's PLACEMENT matter: a focus-out whose deferred close does run, but
     * finds focus settled on a control inside the panel. Nothing has been
     * abandoned, so neither the panel nor the popup may go.
     */
    test('a deferred close that finds focus still inside the panel closes neither the panel nor the popup', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        openPanel();
        panelParts().soleTrader.trigger('click');

        const { panel, registered } = panelParts();
        registered.get(0).focus();
        panel.triggerHandler('focusout');
        jest.advanceTimersByTime(10);

        expect(document.activeElement).toBe(registered.get(0));
        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();
        expect(shown(panelParts().panel)).toBe(true);
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
