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
        closeSignupPopup: jest.fn(),
        // The atomic "buyer is leaving this flow" pair (TWO-40 follow-up,
        // Doug). Stubbed as ONE fn, not as a composition of the two above:
        // what belongs to TwoCompanySearch is which operation each gesture
        // picks. That it really does close before cancelling is
        // TwoSoleTrader's own contract, pinned against the real module in
        // sole-trader-abandon-enrollment.test.js.
        abandonEnrollment: jest.fn(),
        // "Was there a popup to raise?" - false is the no-popup-open default,
        // and the tests that need one still up flip it (see soleTraderPopupOpen()).
        focusSignupPopup: jest.fn(() => false)
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
     * see .ai/decisions.md): PR #159 called renderChipSelection() in the SAME
     * tick as closeDropdown(), so a real browser never painted the
     * `--selected` class before `display:none` hid it - jsdom's paint-less
     * assertion passed anyway. The selection must be visible WHILE the panel
     * is still open, not merely true in an unwatched document.
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
     * TWO-40 round 4, Doug's request: keep the panel open with a spinner for
     * the Sole Trader autofill round trip, driven by the real
     * notifyEnrollmentSettled() settle event, not a fixed timeout.
     *
     * Spinner is on the company-NAME field, not the query field (TWO-40
     * follow-up, Doug): the query row is hidden once this chip is selected,
     * and the name field is where the fetched value lands.
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
        expect(panelParts().query.hasClass('two-company-search-loading')).toBe(false);

        // No fixed timeout closes it - only the settle event does.
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
        // Remove the global to prove the handler guards rather than throwing.
        delete global.window.TwoSoleTrader_Instance;

        expect(() => panelParts().soleTrader.trigger('click')).not.toThrow();

        // TWO-40 round 5: this fallback branch skips beginSoleTraderLoading()'s
        // keep-open window, so it needs its own deferred (rAF) close - round 1
        // review caught that missing, reopening the same-tick paint bug this
        // PR chain exists to fix.
        jest.advanceTimersByTime(20);
        expect(shown(panelParts().panel)).toBe(false);
    });

    /**
     * Regression test (TWO-40 round 5, Han/Vader review finding): round 4
     * keeps the chip clickable for the whole round trip, so a second click
     * while the first is still waiting could re-enter startEnrollment() and
     * open a second signup popup.
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
        openPanel();
        panelParts().soleTrader.trigger('click');

        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(2);
    });

    /**
     * Regression test (TWO-40 round 5, Vader finding): startEnrollment() is
     * foreign-module code with no try/catch before this fix - a synchronous
     * throw left the panel stuck open with the spinner running.
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
     * Regression test: the handler set `_chipMode` and started enrolment,
     * but never called renderChipSelection(), so "Registered Company" kept
     * the `--selected` class forever. Asserting `startEnrollment` alone
     * doesn't catch this. Checked against DOM classes, not `_chipMode`, since
     * the live bug was observed via DevTools reading `.className`.
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
     * Doug's live finding: spinner and panel closed on focus-out, but the
     * signup popup itself stayed on screen. All three go together.
     *
     * Driven with a real focusable element outside the panel, not `<body>`:
     * jsdom won't make `<body>` the activeElement, so the guard would hold
     * vacuously (same reason as company-search-dropdown.test.js).
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
        // Asserted separately so a regression here isn't mistaken for the popup one.
        expect(shown(panelParts().panel)).toBe(false);
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(false);
    });

    /**
     * The second layer of the same guard: a deferred close that runs but
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

describe('a chip clicked while the signup popup is open (TWO-40 follow-up, Doug spec)', () => {
    /**
     * The rule: focus returning to the checkout closes the popup, and the
     * ONLY exception is the Sole trader chip, which raises it to the front
     * instead. Every chip now says so explicitly in its own handler.
     */
    function popupOpen(soleTrader) {
        soleTrader.focusSignupPopup.mockReturnValue(true);
    }

    /** Launch the flow so a popup is notionally up, then clear the bookkeeping. */
    function launchThenPopupOpen(soleTrader) {
        openPanel();
        panelParts().soleTrader.trigger('click');
        popupOpen(soleTrader);
        soleTrader.closeSignupPopup.mockClear();
        soleTrader.focusSignupPopup.mockClear();
        soleTrader.abandonEnrollment.mockClear();
    }

    test.each([
        ['soleTrader', false, true, 'the one exception - raises the popup, never abandons'],
        ['registered', true, false, 'abandons the flow, keeps the panel'],
        ['notListed', true, false, 'abandons the flow, hands off to manual entry']
    ])('%s: abandoned=%s raised=%s - %s', (chip, abandoned, raised) => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        panelParts()[chip].trigger('click');
        // NOT advanced past the deferred close (round 2 adversarial review,
        // mutation-proved): letting it run would let "Enter manually" satisfy
        // this via the OLD accidental route instead. Asserted on
        // abandonEnrollment(), not closeSignupPopup(), since the deferred
        // close only ever takes the popup down, never the enrolment.
        expect(soleTrader.abandonEnrollment.mock.calls.length > 0).toBe(abandoned);
        expect(soleTrader.focusSignupPopup.mock.calls.length > 0).toBe(raised);
    });

    /**
     * #5.1. The gap was not "closed the popup when it should not have" - it was
     * that the click resolved to NOTHING: `_soleTraderLoading` stays true for
     * the popup's whole lifetime, so the re-entrancy guard swallowed the click
     * before anything could raise the window the buyer was asking for.
     */
    test('Sole trader: raises the popup, keeps the panel open, and stays the selected chip', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        const { soleTrader: chip } = panelParts();
        chip.trigger('click');
        jest.advanceTimersByTime(10);

        expect(soleTrader.focusSignupPopup).toHaveBeenCalledTimes(1);
        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();
        expect(shown(panelParts().panel)).toBe(true);
        expect(panelParts().soleTrader.hasClass('two-company-mode-chip--selected')).toBe(true);
        // No second enrolment either - the popup on screen IS the flight.
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
    });

    /**
     * A close ALREADY PENDING when the chip is clicked must not survive the
     * raise. Driven with focus genuinely OUTSIDE the panel (round 2 review,
     * mutation-proved): with focus left inside the panel,
     * scheduleDropdownClose()'s own guard returns first and the test passes
     * with the `clearTimeout` deleted, pinning nothing.
     */
    test('Sole trader: a close already pending when the chip is clicked does not fire', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        $("input[name='dni']").get(0).focus();
        panelParts().panel.trigger('focusout');
        panelParts().soleTrader.trigger('click');
        jest.advanceTimersByTime(10);

        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();
        expect(shown(panelParts().panel)).toBe(true);
    });

    /**
     * The close the RAISE itself provokes, which the `clearTimeout` above
     * cannot reach: the popup taking focus fires its focus-out after the chip
     * handler has already returned, with nothing left to cancel it. What
     * stops it is the checkout page no longer having focus at all, not the
     * browser incidentally leaving `activeElement` on the clicked chip
     * (what round 1 actually relied on).
     */
    test('Sole trader: the close provoked by the raise itself does not fire either', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        panelParts().soleTrader.trigger('click');
        // Focus left genuinely outside the panel, so scheduleDropdownClose()'s
        // activeElement guard doesn't return first and vacuously pass.
        $("input[name='dni']").get(0).focus();
        jest.spyOn(document, 'hasFocus').mockReturnValue(false);
        panelParts().panel.trigger('focusout');
        jest.advanceTimersByTime(10);

        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();
        // Panel not asserted: the new guard is deliberately scoped to the
        // popup decision only, not the panel's own close behavior.
    });

    /**
     * The other half of that guard, and the case Doug listed as case 4: focus
     * coming back to the checkout page itself still closes the popup, with no
     * chip involved.
     */
    test('focus returning to the page with the page focused still closes the popup', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        jest.spyOn(document, 'hasFocus').mockReturnValue(true);
        $("input[name='dni']").get(0).focus();
        panelParts().panel.trigger('focusout');
        jest.advanceTimersByTime(10);

        expect(soleTrader.closeSignupPopup).toHaveBeenCalledTimes(1);
        expect(shown(panelParts().panel)).toBe(false);
    });

    /**
     * #5.2. Closing the popup must not cost the chip its own job - "stay here,
     * search normally" still means the query row is back and focused.
     */
    test('Registered company: abandons the flow AND still shows and focuses the query field', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        panelParts().registered.trigger('click');
        jest.advanceTimersByTime(10);

        expect(soleTrader.abandonEnrollment).toHaveBeenCalledTimes(1);
        expect(shown(panelParts().searchRow)).toBe(true);
        expect(document.activeElement).toBe(panelParts().query.get(0));
        expect(shown(panelParts().panel)).toBe(true);
    });

    /**
     * The close-BEFORE-cancel ordering (Doug, TWO-40 follow-up: "closure and
     * enrolment cancelation must be a single atomic operation") now lives
     * inside abandonEnrollment(), pinned for real in
     * sole-trader-abandon-enrollment.test.js. Pinned HERE: this handler no
     * longer takes the two halves itself, in any order.
     */
    test('Registered company: goes through the atomic pair, never the halves', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        soleTrader.cancelEnrollment.mockClear();
        panelParts().registered.trigger('click');

        expect(soleTrader.abandonEnrollment).toHaveBeenCalledTimes(1);
        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();
        expect(soleTrader.cancelEnrollment).not.toHaveBeenCalled();
    });

    /**
     * The ENROLMENT half is the bug Doug found (TWO-40 follow-up): this chip
     * used to close the popup and leave the enrolment running, so an in-flight
     * lookup could still resolve into adoptSoleTraderBuyer() and overwrite the
     * name the buyer had just typed by hand. Pinned on the real modules in
     * sole-trader-abandon-enrollment.test.js.
     */
    test('Enter manually: abandons the flow AND still switches to manual entry', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        panelParts().notListed.trigger('click');
        // The chip's OWN call is the claim, distinguishable from the deferred
        // close's only before the timers run.
        expect(soleTrader.abandonEnrollment).toHaveBeenCalledTimes(1);

        jest.advanceTimersByTime(10);
        expect(shown(panelParts().panel)).toBe(false);
        expect(panelParts().nameField.attr('readonly')).toBeUndefined();
        expect(document.activeElement).toBe(panelParts().nameField.get(0));
    });

    /**
     * The no-popup-open case for the one chip that behaves differently: with
     * nothing to raise, the Sole trader chip must still be an ordinary chip.
     */
    test('Sole trader with no popup open starts an enrolment as before', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        openPanel();

        panelParts().soleTrader.trigger('click');

        expect(soleTrader.focusSignupPopup).toHaveBeenCalledTimes(1);
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
    });

    /**
     * Fail-soft against an older TwoSoleTrader.js that has no focusSignupPopup()
     * - twopayment.js loads the two modules independently, and the panel must
     * not lose its chip behaviour to a missing method.
     */
    test('a TwoSoleTrader without focusSignupPopup() still gets an ordinary chip click', () => {
        const soleTrader = stubSoleTrader(true);
        delete soleTrader.focusSignupPopup;
        makeInstance();
        openPanel();

        panelParts().soleTrader.trigger('click');

        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
    });
});

describe('reopening search cancels a pending enrolment (TWO-40)', () => {
    test('opening the dropdown again cancels an in-progress sole-trader enrolment', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        // openDropdown() abandons unconditionally on every open, so this
        // baselines against the FIRST open rather than asserting zero calls.
        openPanel();
        const callsBeforeEnrolling = soleTrader.abandonEnrollment.mock.calls.length;
        panelParts().soleTrader.trigger('click');
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);

        openPanel();

        expect(soleTrader.abandonEnrollment.mock.calls.length).toBe(callsBeforeEnrolling + 1);
    });

    /**
     * The bug Doug found (TWO-40 follow-up, guide §14): an address-form
     * re-render restores a panel the buyer already had, which says nothing
     * about their intent, so it must NOT abandon. PrestaShop fires
     * `updatedAddressForm` for shipping recalculations whose XHR callback can
     * run with the buyer looking at the popup in another window.
     */
    test('a re-render restore leaves an in-flight enrolment and its popup alone', () => {
        const soleTrader = stubSoleTrader(true);
        const instance = makeInstance();
        openPanel();
        panelParts().soleTrader.trigger('click');
        soleTrader.focusSignupPopup.mockReturnValue(true);
        soleTrader.abandonEnrollment.mockClear();
        soleTrader.closeSignupPopup.mockClear();
        soleTrader.cancelEnrollment.mockClear();

        // Arm the window the re-render restore runs inside, as the
        // `updatedAddressForm` handler does for a panel that was open.
        TwoCompanySearch._reopenPanelUntil = Date.now() + 1000;
        instance.restorePanelAfterRerender();

        expect(soleTrader.abandonEnrollment).not.toHaveBeenCalled();
        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();
        expect(soleTrader.cancelEnrollment).not.toHaveBeenCalled();
        expect(shown(panelParts().panel)).toBe(true);
        soleTrader.focusSignupPopup.mockClear();
        panelParts().soleTrader.trigger('click');
        expect(soleTrader.focusSignupPopup).toHaveBeenCalledTimes(1);
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
    });

    /**
     * The FULL re-render path (guide §14): the checkout manager destroys this
     * instance and builds a replacement. destroy()'s cancel must disown the
     * WRITE only - `cancelEnrollment(true)` - since the buyer may still be
     * filling that popup in. The replacement instance meets a live popup it
     * never launched, so its Sole trader chip has to raise that window and
     * pick up the spinner/settle bookkeeping the destroyed instance took with it.
     */
    test('destroy() keeps the popup for the replacement instance, which raises it rather than opening a second', () => {
        const soleTrader = stubSoleTrader(true);
        const instance = makeInstance();
        openPanel();
        panelParts().soleTrader.trigger('click');
        soleTrader.focusSignupPopup.mockReturnValue(true);
        soleTrader.cancelEnrollment.mockClear();
        soleTrader.abandonEnrollment.mockClear();
        soleTrader.closeSignupPopup.mockClear();

        instance.destroy();

        expect(soleTrader.cancelEnrollment.mock.calls).toEqual([[true]]);
        expect(soleTrader.abandonEnrollment).not.toHaveBeenCalled();
        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();

        const replacement = makeInstance();
        TwoCompanySearch._reopenPanelUntil = Date.now() + 1000;
        replacement.restorePanelAfterRerender();
        soleTrader.focusSignupPopup.mockClear();

        panelParts().soleTrader.trigger('click');

        expect(soleTrader.focusSignupPopup).toHaveBeenCalledTimes(1);
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(true);
    });

    /**
     * `_reopenPanelUntil` is armed by the buyer's OWN click too
     * (setupAddressFormListener()), so a re-render landing in the same tick
     * as a genuine click cannot make one look like the other.
     */
    test('a buyer-initiated open inside the re-render window still abandons', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        openPanel();
        panelParts().soleTrader.trigger('click');
        soleTrader.focusSignupPopup.mockReturnValue(true);
        soleTrader.abandonEnrollment.mockClear();

        TwoCompanySearch._reopenPanelUntil = Date.now() + 1000;
        openPanel();

        expect(soleTrader.abandonEnrollment).toHaveBeenCalledTimes(1);
    });
});
