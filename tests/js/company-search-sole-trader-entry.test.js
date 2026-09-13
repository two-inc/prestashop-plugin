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
let bus;
// Shared across every instance a test builds, so an arm survives the destroy +
// reconstruct a real re-render performs.
let reopenMemory;

function makeInstance(config) {
    return new TwoCompanySearch(Object.assign(
        { checkoutHost: CHECKOUT_HOST, reopenMemory: reopenMemory },
        config || {}
    ));
}

/** Install a stub TwoSoleTrader_Instance, as twopayment.js's global would be. */
function stubSoleTrader(available) {
    const instance = {
        isAvailableForCurrentCountry: jest.fn(() => available),
        startEnrollment: jest.fn(),
        cancelEnrollment: jest.fn(),
        closeSignupPopup: jest.fn(),
        // The atomic "buyer is leaving this flow" pair (TWO-40). Stubbed
        // as ONE fn, not as a composition of the two above:
        // what belongs to TwoCompanySearch is which operation each gesture
        // picks. That it really does close before cancelling is
        // TwoSoleTrader's own contract, pinned against the real module in
        // sole-trader-abandon-enrollment.test.js.
        abandonEnrollment: jest.fn(),
        // "Was there a popup to raise?" - false is the no-popup-open default,
        // and the tests that need one still up flip it (see popupOpen()).
        reclaimSignupPopup: jest.fn(() => false),
        isPopupOpen: jest.fn(() => false)
    };
    global.window.TwoSoleTrader_Instance = instance;
    return instance;
}

/** Put the stub in the "popup on screen" state. */
function popupOpen(soleTrader) {
    soleTrader.reclaimSignupPopup.mockReturnValue(true);
    soleTrader.isPopupOpen.mockReturnValue(true);
}

beforeEach(() => {
    jest.useFakeTimers();
    reopenMemory = {};
    document.body.innerHTML = '';
    document.head.innerHTML = '';
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    bus = loaded.bus;
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
     * Regression test (TWO-40, live-verified against a real browser - see
     * .ai/decisions.md): prestashop-plugin PR #159 called renderChipSelection()
     * in the SAME tick as closeDropdown(), so a real browser never painted the
     * `--selected` class before `display:none` hid it - jsdom's paint-less
     * assertion passed anyway. The selection must be visible WHILE the panel is still open, not
     * merely true in an unwatched document.
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
     * TWO-40: the panel stays open with a spinner for the Sole Trader
     * autofill round trip, driven by the real notifyEnrollmentSettled()
     * settle event, not a fixed timeout.
     *
     * The spinner is on the company-NAME field, not the query field: the
     * query row is hidden once this chip is selected, and the name field is
     * where the fetched value lands.
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

    /**
     * ABN-554: a browser re-fires `focus` on the control the opener window
     * still holds the moment the popup closes, which is the company-name field
     * the launch parked focus on. jsdom fires nothing on a window return, so
     * the re-fire is dispatched here by hand.
     */
    describe('the popup closing must not leave the popover open (ABN-554)', () => {
        /** The enrolment up, its popup on screen, and focus parked on the name field. */
        function launched(instance, soleTrader, popupId) {
            openPanel();
            panelParts().soleTrader.trigger('click');
            popupOpen(soleTrader);
            if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
            document.dispatchEvent(new CustomEvent('two:sole-trader-popup-opened', {
                detail: { id: popupId === undefined ? 'popup-1' : popupId, launcher: instance._instanceNs }
            }));
            jest.advanceTimersByTime(0);
        }

        /**
         * What a browser sends the checkout window when it comes back from the popup: its
         * own `focus`, then the settle TwoSoleTrader dispatches from its document-capture
         * `focusin` listener, then the pair re-fired on the field the launch parked on.
         */
        function refireFocusOnNameField(soleTrader) {
            soleTrader.isPopupOpen.mockReturnValue(false);
            const node = panelParts().nameField[0];
            $(global.window).trigger('focus');
            document.dispatchEvent(new CustomEvent('two:sole-trader-focus-settled', {
                detail: { target: node, popupClosed: true }
            }));
            panelParts().nameField.trigger('focus');
        }

        test.each([
            [true, false, 'the re-fire is not the buyer coming back, so the settle still closes'],
            [false, true, 'a popover the buyer clicked back into is theirs to keep']
        ])('re-fire only=%p -> panel shown=%p afterwards (%s)',
            (refireOnly, expectedShown, why) => {
                const soleTrader = stubSoleTrader(true);
                const instance = makeInstance();
                launched(instance, soleTrader);

                refireFocusOnNameField(soleTrader);
                if (!refireOnly) {
                    panelParts().nameField.trigger('mousedown');
                }
                document.dispatchEvent(new CustomEvent('two:sole-trader-flight-settled'));

                expect([shown(panelParts().panel), why]).toEqual([expectedShown, why]);
            });

        test('a real mousedown on the name field opens the panel after the flight (the pointer opener is never held)', () => {
            const soleTrader = stubSoleTrader(true);
            const instance = makeInstance();
            launched(instance, soleTrader);
            refireFocusOnNameField(soleTrader);
            document.dispatchEvent(new CustomEvent('two:sole-trader-flight-settled'));
            expect(shown(panelParts().panel)).toBe(false);

            panelParts().nameField.trigger('mousedown');

            expect(shown(panelParts().panel)).toBe(true);
        });

        /**
         * The hold ends on the WINDOW's return, which a browser signals by
         * re-firing `focus` and `focusin` at whatever was focused when the tab
         * lost focus - here, the field the launch parked on. jsdom sends none of
         * that on a tab switch, so every event below is dispatched by hand; and
         * jsdom fires a window-targeted `focus` on any element `blur()`, so
         * these fixtures blur nothing after the launch.
         */
        function settledWithPanelClosed(soleTrader, instance) {
            launched(instance, soleTrader);
            refireFocusOnNameField(soleTrader);
            document.dispatchEvent(new CustomEvent('two:sole-trader-flight-settled'));
            expect(shown(panelParts().panel)).toBe(false);
        }

        /** The panel closed with the hold still standing: the popup is gone, the window has not come back. */
        function heldWithPanelClosed(soleTrader, instance) {
            launched(instance, soleTrader);
            soleTrader.isPopupOpen.mockReturnValue(false);
            instance.closeDropdown(false);
            expect(shown(panelParts().panel)).toBe(false);
        }

        test('a focus pair with no window return behind it is a buyer arriving by Tab, and opens the panel', () => {
            const soleTrader = stubSoleTrader(true);
            const instance = makeInstance();
            heldWithPanelClosed(soleTrader, instance);

            panelParts().nameField.trigger('focus');

            expect(shown(panelParts().panel)).toBe(true);
        });

        test('that arrival ends the hold, so focus alone opens the panel afterwards', () => {
            const soleTrader = stubSoleTrader(true);
            const instance = makeInstance();
            heldWithPanelClosed(soleTrader, instance);
            panelParts().nameField.trigger('focus');
            instance.closeDropdown(false);
            expect(shown(panelParts().panel)).toBe(false);

            panelParts().nameField.trigger('focus');

            expect(shown(panelParts().panel)).toBe(true);
        });

        test('the park the launch itself performs is not that arrival, and leaves the hold standing', () => {
            const soleTrader = stubSoleTrader(true);
            const instance = makeInstance();
            // The park runs inside this, on the field, with the hold already set.
            launched(instance, soleTrader);
            instance.closeDropdown(false);
            expect(shown(panelParts().panel)).toBe(false);

            // The half a buyer's Tab would complete; only an opener the park freed answers it.
            panelParts().nameField.trigger('focusin');

            expect(shown(panelParts().panel)).toBe(false);
        });

        test('the window return spends its focus pair without opening the panel, and the field opens on the next focus', () => {
            const soleTrader = stubSoleTrader(true);
            const instance = makeInstance();
            heldWithPanelClosed(soleTrader, instance);

            $(global.window).trigger('focus');
            panelParts().nameField.trigger('focus');
            expect(shown(panelParts().panel)).toBe(false);

            panelParts().nameField.trigger('focus');

            expect(shown(panelParts().panel)).toBe(true);
        });

        test('a pointerdown on the name field mid-flight ends the hold, so the focus it brings opens the panel', () => {
            const soleTrader = stubSoleTrader(true);
            const instance = makeInstance();
            launched(instance, soleTrader);
            instance.closeDropdown(false);
            expect(shown(panelParts().panel)).toBe(false);

            panelParts().nameField.trigger('pointerdown');
            panelParts().nameField.trigger('focus');

            expect(shown(panelParts().panel)).toBe(true);
        });

        test('a re-render mid-flight leaves the re-fire no way to keep the panel open', () => {
            const soleTrader = stubSoleTrader(true);
            soleTrader.popupLaunchId = jest.fn(() => 7);
            const launcher = makeInstance();
            launched(launcher, soleTrader, 7);
            launcher.armReopen(Date.now() + 1000);
            // PrestaShop's own destroy + construct, with the popup still up.
            launcher.destroy();
            makeInstance();

            refireFocusOnNameField(soleTrader);
            document.dispatchEvent(new CustomEvent('two:sole-trader-flight-settled'));

            expect(shown(panelParts().panel)).toBe(false);
        });
    });

    test('does nothing destructive if TwoSoleTrader_Instance is missing, and still closes (after a paint) rather than dead-ending open', () => {
        stubSoleTrader(true);
        makeInstance();
        openPanel();
        // Remove the global to prove the handler guards rather than throwing.
        delete global.window.TwoSoleTrader_Instance;

        expect(() => panelParts().soleTrader.trigger('click')).not.toThrow();

        // TWO-40: this fallback branch skips beginSoleTraderLoading()'s
        // keep-open window, so it needs its own deferred (rAF) close - without
        // it the same-tick paint bug returns.
        jest.advanceTimersByTime(20);
        expect(shown(panelParts().panel)).toBe(false);
    });

    /**
     * Regression test (TWO-40): the chip stays clickable for the whole round
     * trip, so a second click while the first is still waiting could re-enter
     * startEnrollment() and open a second signup popup.
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
     * Regression test (TWO-40): startEnrollment() is foreign-module code, so
     * without this try/catch a synchronous throw left the panel stuck open
     * with the spinner running.
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

describe('focus leaving the panel closes the panel only (TWO-25658)', () => {
    /** A focus-out cannot tell a return to checkout from the popup taking focus; the popup is the return watch's. */
    test('closes the panel and the spinner, and leaves the popup to the return watch', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        openPanel();
        panelParts().soleTrader.trigger('click');
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
        soleTrader.abandonEnrollment.mockClear();
        soleTrader.closeSignupPopup.mockClear();

        const outside = $("input[name='dni']").get(0);
        outside.focus();
        panelParts().panel.trigger('focusout');
        jest.advanceTimersByTime(10);

        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();
        expect(soleTrader.abandonEnrollment).not.toHaveBeenCalled();
        expect(shown(panelParts().panel)).toBe(false);
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(false);
    });

    test('a deferred close that finds focus still inside the panel closes neither the panel nor the popup', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        openPanel();
        panelParts().soleTrader.trigger('click');
        soleTrader.closeSignupPopup.mockClear();

        const { panel, registered } = panelParts();
        registered.get(0).focus();
        panel.triggerHandler('focusout');
        jest.advanceTimersByTime(10);

        expect(document.activeElement).toBe(registered.get(0));
        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();
        expect(shown(panelParts().panel)).toBe(true);
    });
});

describe('a chip clicked while the signup popup is open (TWO-40)', () => {
    /** TWO-25658: the Sole trader chip raises, any other chip closes - close only; abandon is for a popup already gone. */
    /** Launch the flow so a popup is notionally up, then clear the bookkeeping. */
    function launchThenPopupOpen(soleTrader) {
        openPanel();
        panelParts().soleTrader.trigger('click');
        popupOpen(soleTrader);
        soleTrader.closeSignupPopup.mockClear();
        soleTrader.reclaimSignupPopup.mockClear();
        soleTrader.abandonEnrollment.mockClear();
    }

    test.each([
        ['soleTrader', false, true, 'the one exception - raises the popup'],
        ['registered', true, false, 'close only (Safari focuses no button on mousedown); the click keeps the panel'],
        ['notListed', true, false, 'close only; the click hands off to manual entry']
    ])('%s: closed=%s raised=%s - %s', (chip, closed, raised) => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        panelParts()[chip].trigger('click');

        expect(soleTrader.closeSignupPopup.mock.calls.length > 0).toBe(closed);
        expect(soleTrader.reclaimSignupPopup.mock.calls.length > 0).toBe(raised);
        // Never the cancel half while a popup is up.
        expect(soleTrader.abandonEnrollment).not.toHaveBeenCalled();
    });

    /**
     * `_soleTraderLoading` stays true for the popup's whole lifetime, so the
     * re-entrancy guard must not swallow this click - it has to raise the
     * window the buyer is asking for.
     */
    test('Sole trader: raises the popup, keeps the panel open, and stays the selected chip', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        const { soleTrader: chip } = panelParts();
        chip.trigger('click');
        jest.advanceTimersByTime(10);

        expect(soleTrader.reclaimSignupPopup).toHaveBeenCalledTimes(1);
        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();
        expect(shown(panelParts().panel)).toBe(true);
        expect(panelParts().soleTrader.hasClass('two-company-mode-chip--selected')).toBe(true);
        // No second enrolment either - the popup on screen IS the flight.
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
    });

    /** Focus genuinely outside the panel, or scheduleDropdownClose()'s own guard passes this with the `clearTimeout` deleted. */
    test('Sole trader: a panel close already pending when the chip is clicked does not fire', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        $("input[name='dni']").get(0).focus();
        panelParts().panel.trigger('focusout');
        panelParts().soleTrader.trigger('click');
        jest.advanceTimersByTime(10);

        expect(shown(panelParts().panel)).toBe(true);
    });

    /**
     * Closing the popup must not cost the chip its own job - "stay here,
     * search normally" still means the query row is back and focused.
     */
    test('Registered company: never cancels, and still shows and focuses the query field', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        panelParts().registered.trigger('click');
        jest.advanceTimersByTime(10);

        expect(soleTrader.abandonEnrollment).not.toHaveBeenCalled();
        expect(soleTrader.cancelEnrollment).not.toHaveBeenCalled();
        expect(shown(panelParts().searchRow)).toBe(true);
        expect(document.activeElement).toBe(panelParts().query.get(0));
        expect(shown(panelParts().panel)).toBe(true);
    });

    /** adoptSoleTraderBuyer()'s manual-entry guard, not a cancel, keeps a lookup still out off the hand-typed name. */
    test('Enter manually: never cancels, and still switches to manual entry', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        launchThenPopupOpen(soleTrader);

        panelParts().notListed.trigger('click');
        expect(soleTrader.abandonEnrollment).not.toHaveBeenCalled();
        expect(soleTrader.cancelEnrollment).not.toHaveBeenCalled();

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

        expect(soleTrader.reclaimSignupPopup).toHaveBeenCalledTimes(1);
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
    });

    /**
     * Fail-soft against an older TwoSoleTrader.js that has no reclaimSignupPopup()
     * - twopayment.js loads the two modules independently, and the panel must
     * not lose its chip behaviour to a missing method.
     */
    test('a TwoSoleTrader without reclaimSignupPopup() still gets an ordinary chip click', () => {
        const soleTrader = stubSoleTrader(true);
        delete soleTrader.reclaimSignupPopup;
        makeInstance();
        openPanel();

        panelParts().soleTrader.trigger('click');

        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
    });
});

describe('reopening search closes the popup only (TWO-25658)', () => {
    test('opening the dropdown again closes an open popup and cancels nothing', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        openPanel();
        panelParts().soleTrader.trigger('click');
        popupOpen(soleTrader);
        soleTrader.closeSignupPopup.mockClear();

        openPanel();

        expect(soleTrader.closeSignupPopup).toHaveBeenCalledTimes(1);
        expect(soleTrader.abandonEnrollment).not.toHaveBeenCalled();
        expect(soleTrader.cancelEnrollment).not.toHaveBeenCalled();
    });

    /**
     * An address-form re-render restores a panel the buyer already had, which
     * says nothing about their intent, so it must NOT abandon (TWO-40).
     * PrestaShop fires
     * `updatedAddressForm` for shipping recalculations whose XHR callback can
     * run with the buyer looking at the popup in another window.
     */
    test('a re-render restore leaves an in-flight enrolment and its popup alone', () => {
        const soleTrader = stubSoleTrader(true);
        const instance = makeInstance();
        openPanel();
        panelParts().soleTrader.trigger('click');
        popupOpen(soleTrader);
        soleTrader.abandonEnrollment.mockClear();
        soleTrader.closeSignupPopup.mockClear();
        soleTrader.cancelEnrollment.mockClear();
        const panelNode = panelParts().panel.get(0);
        const closeDropdown = jest.spyOn(instance, 'closeDropdown');

        // Core's own event, not a direct call: it is what tears the panel down
        // and arms the deadline the restore then runs inside.
        bus.emit('updatedAddressForm');

        expect(soleTrader.abandonEnrollment).not.toHaveBeenCalled();
        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();
        expect(soleTrader.cancelEnrollment).not.toHaveBeenCalled();
        expect(shown(panelParts().panel)).toBe(true);
        // The restore has to hand back the panel the buyer already had: a
        // rebuilt node loses the enrolment's spinner/settle state with it, and
        // `shown()` cannot tell the two apart.
        expect(panelParts().panel.get(0)).toBe(panelNode);
        // A listener that did nothing at all would satisfy the assertions
        // above; this one closes on the buyer's behalf and restores.
        expect(closeDropdown.mock.calls).toEqual([[false]]);
        soleTrader.reclaimSignupPopup.mockClear();
        panelParts().soleTrader.trigger('click');
        expect(soleTrader.reclaimSignupPopup).toHaveBeenCalledTimes(1);
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
    });

    /**
     * The FULL re-render path: the checkout manager destroys this
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
        soleTrader.reclaimSignupPopup.mockReturnValue(true);
        soleTrader.cancelEnrollment.mockClear();
        soleTrader.abandonEnrollment.mockClear();
        soleTrader.closeSignupPopup.mockClear();

        // Core's event first: it arms the deadline in the shared reopen memory,
        // which is the only thing that survives the destroy below.
        bus.emit('updatedAddressForm');
        instance.destroy();

        expect(soleTrader.cancelEnrollment.mock.calls).toEqual([[true]]);
        expect(soleTrader.abandonEnrollment).not.toHaveBeenCalled();
        expect(soleTrader.closeSignupPopup).not.toHaveBeenCalled();
        expect(shown(panelParts().panel)).toBe(false);

        const replacement = makeInstance();
        replacement.restorePanelAfterRerender();
        expect(shown(panelParts().panel)).toBe(true);
        soleTrader.reclaimSignupPopup.mockClear();

        panelParts().soleTrader.trigger('click');

        expect(soleTrader.reclaimSignupPopup).toHaveBeenCalledTimes(1);
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
        expect(panelParts().nameField.hasClass('two-company-name-loading')).toBe(true);
    });

    /**
     * The reopen deadline is armed by the buyer's OWN click too
     * (setupAddressFormListener()), so a re-render landing in the same tick
     * as a genuine click cannot make one look like the other.
     */
    test('a buyer-initiated open inside the re-render window still closes the popup', () => {
        const soleTrader = stubSoleTrader(true);
        const instance = makeInstance();
        openPanel();
        panelParts().soleTrader.trigger('click');
        popupOpen(soleTrader);
        soleTrader.closeSignupPopup.mockClear();

        instance.armReopen(Date.now() + 1000);
        openPanel();

        expect(soleTrader.closeSignupPopup).toHaveBeenCalledTimes(1);
        expect(soleTrader.abandonEnrollment).not.toHaveBeenCalled();
    });
});
