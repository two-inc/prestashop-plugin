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

describe('a chip clicked while the signup popup is open (TWO-40 follow-up, Doug spec)', () => {
    /**
     * The rule: focus returning to the checkout closes the popup, and the ONLY
     * exception is the Sole trader chip, which raises it to the front instead.
     *
     * Every chip now says so in its own handler. Before this, none of the three
     * reached the popup by intent: the deferred close is cancelled by the
     * `focusin` a chip click produces, so whether a chip closed the popup was
     * decided by whether its own action happened to push focus out of the panel
     * again - which "Enter manually" does (the company-name field) and
     * "Registered company" does not (the query field, inside the panel).
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
        // NOT advanced past the deferred close, deliberately (round 2
        // adversarial review finding, mutation-proved): letting it run lets
        // "Enter manually" satisfy this through the OLD accidental route -
        // enterManualEntryMode() focuses the company-name field, re-schedules
        // a close, and that close closes the popup. Asserted synchronously,
        // only the handler's own call can have happened.
        //
        // On abandonEnrollment() rather than closeSignupPopup(), which also
        // shuts that accidental route out by construction: the deferred close
        // only ever takes the popup down, never the enrolment with it, so it
        // cannot satisfy this column however the timers are advanced.
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
     * raise - it would close the popup the click just asked for.
     *
     * Driven with focus genuinely OUTSIDE the panel (round 2 adversarial review
     * finding, mutation-proved): with focus left on a control inside the panel,
     * scheduleDropdownClose()'s own activeElement guard returns first and the
     * test passes with the `clearTimeout` deleted, pinning nothing.
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
     * handler has already returned, so that close is scheduled with nothing
     * left to cancel it.
     *
     * What stops it is the checkout page no longer having focus at all - the
     * direct form of Doug's rule ("if I move focus back to the page the popup
     * should be closed"), rather than the browser incidentally leaving
     * `activeElement` on the clicked chip, which is what the first round
     * actually relied on.
     */
    test('Sole trader: the close provoked by the raise itself does not fire either', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        panelParts().soleTrader.trigger('click');
        // The popup now holds focus, and the page's own activeElement is
        // OUTSIDE the panel - which is the whole point of this test. With it
        // left on the clicked chip, scheduleDropdownClose()'s activeElement
        // guard returns first and this passes with the hasFocus() guard
        // deleted (caught by re-running the mutation: Chrome retaining focus
        // on a clicked `<button>` is exactly the incidental behaviour round 1
        // leaned on, so a test that reproduces it pins nothing).
        $("input[name='dni']").get(0).focus();
        jest.spyOn(document, 'hasFocus').mockReturnValue(false);
        panelParts().panel.trigger('focusout');
        jest.advanceTimersByTime(10);

        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();
        // The PANEL is not asserted here. Its close keeps its existing meaning
        // - focus left it, so it goes - and narrowing the new guard to the
        // popup decision alone is deliberate: widening it to the panel too
        // changes when the panel survives an app switch, which is neither
        // asked for nor covered by the spec.
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
     * The ordering that makes #5.2 work at all - close BEFORE cancel, because
     * the cancel nulls the popup handle - is no longer any caller's to get
     * right: it lives inside abandonEnrollment() (Doug, TWO-40 follow-up,
     * "closure and enrolment cancelation [must be] a single atomic operation,
     * not two separate functions as now"). Pinned against the real
     * implementation in sole-trader-abandon-enrollment.test.js.
     *
     * What is pinned HERE is that this handler no longer takes the two halves
     * itself, in any order. A future edit reaching for the pair directly is
     * the failure mode the merge exists to stop, so it fails here.
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
     * "Enter manually" reached the same outcome before this change, but only
     * via enterManualEntryMode() focusing the company-name field OUTSIDE the
     * panel and re-scheduling a close nobody asked for. Pinned here on the
     * chip's own call, together with the effects that must survive it.
     *
     * The ENROLMENT half is the bug Doug found (TWO-40 follow-up): this chip
     * used to close the popup and leave the enrolment running, so a lookup
     * already in flight still resolved into adoptSoleTraderBuyer() and
     * overwrote the name the buyer had just typed by hand. That the abandon
     * really does stop such a resolution is pinned on the real modules in
     * sole-trader-abandon-enrollment.test.js.
     */
    test('Enter manually: abandons the flow AND still switches to manual entry', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        panelParts().notListed.trigger('click');
        // Same reason as the table above: the chip's OWN call is the claim, and
        // it is only distinguishable from the deferred close's before the
        // timers run.
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
        // openDropdown() abandons unconditionally on every buyer-initiated
        // open - cheap and idempotent when nothing was pending - so this
        // baselines against the FIRST open rather than asserting zero calls.
        openPanel();
        const callsBeforeEnrolling = soleTrader.abandonEnrollment.mock.calls.length;
        panelParts().soleTrader.trigger('click');
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);

        // The buyer comes back to ordinary company search - e.g. clicking the
        // company field again - rather than completing the sole-trader flow.
        openPanel();

        expect(soleTrader.abandonEnrollment.mock.calls.length).toBe(callsBeforeEnrolling + 1);
    });

    /**
     * The other side of that rule, and the bug Doug found (TWO-40 follow-up):
     * an address-form re-render restores a panel the buyer already had, which
     * says nothing about their intent - so it must NOT abandon. It used to,
     * and because the cancel nulls TwoSoleTrader's popup handle without
     * closing the window, that left a live popup tracked by nothing, from
     * where the Sole trader chip would open a SECOND one (guide §14).
     *
     * Not a focus event and not blocked by one: PrestaShop fires
     * `updatedAddressForm` for shipping recalculations whose XHR callback runs
     * with the buyer looking at the popup in another window.
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

        // Arm the window the re-render restore runs inside, exactly as the
        // `updatedAddressForm` handler does for a panel that was open.
        TwoCompanySearch._reopenPanelUntil = Date.now() + 1000;
        instance.restorePanelAfterRerender();

        // Neither the pair nor either half - the popup is still the buyer's,
        // and still TwoSoleTrader's to track.
        expect(soleTrader.abandonEnrollment).not.toHaveBeenCalled();
        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();
        expect(soleTrader.cancelEnrollment).not.toHaveBeenCalled();
        // Still tracked, so the Sole trader chip raises that popup rather than
        // opening a second one beside it.
        expect(shown(panelParts().panel)).toBe(true);
        soleTrader.focusSignupPopup.mockClear();
        panelParts().soleTrader.trigger('click');
        expect(soleTrader.focusSignupPopup).toHaveBeenCalledTimes(1);
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
    });

    /**
     * The restore path is told what it is, rather than inferring it from
     * `_reopenPanelUntil` being armed - which the buyer's OWN click arms too
     * (setupAddressFormListener()). So a re-render landing in the same tick as
     * a genuine click cannot make one look like the other: this is the genuine
     * click, inside an armed window, and it still abandons.
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
