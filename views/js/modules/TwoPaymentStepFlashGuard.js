/**
 * Two Payment Step Flash Guard (TWO-25326 bug 11).
 *
 * WHAT IT FIXES
 * Selecting a payment method other than Two made the Two tile "disappear for a
 * second, then reappear briefly and disappear again". The middle beat of that
 * sequence is a real, measured repaint, not a perception:
 *
 *  1. Deselecting Two runs the surcharge cart-line sync. When the line actually
 *     changes the module asks PrestaShop core to refresh the cart, and on the
 *     payment step core's refresh is a FULL checkout-page reload (the step
 *     carries core's payment-step-refresh marker class). The tile goes away with
 *     the old document - beat one.
 *  2. The reloaded page renders every payment option's additional-information
 *     block EXPANDED, because a reload wipes radio state and core only collapses
 *     the unselected ones from its own DOM-ready handler. So the Two tile is
 *     present and laid out at first paint - beat two.
 *  3. Tens of milliseconds later that ready handler runs, the module restores the
 *     option the buyer had actually clicked, and the tile collapses - beat three.
 *
 * Measured on the staging shop: first paint at ~244ms into the reload with the
 * tile ~497px tall, collapsed to 0px at ~278ms. A ~34ms flash of a tile the
 * buyer had just navigated away from.
 *
 * HOW
 * Nothing that runs at DOM-ready can fix this - by then the flash has already
 * been painted. This file therefore loads in the HEAD, before the payment
 * options are parsed, and marks the document so the stylesheet keeps the Two
 * tile out of that first paint entirely; the mark is lifted as soon as the
 * payment selection has been restored (or by its own failsafe timer).
 *
 * SCOPE, deliberately narrow on three axes:
 *  - Only on a load caused by this module's OWN surcharge refresh, identified by
 *    the restore key in sessionStorage. An ordinary arrival at the payment step
 *    is left exactly as PrestaShop renders it.
 *  - Only when the option being restored is NOT Two's. When Two IS the restored
 *    selection the tile is painted expanded and STAYS expanded, so there is no
 *    flash to suppress and hiding it would invent one.
 *  - Only when this file genuinely got in ahead of the markup. If the tile is
 *    already in the document when this runs, the browser may already have
 *    painted it and hiding it now would ADD a transition rather than remove one,
 *    so it does nothing at all. That makes a mis-registered asset position a
 *    no-op rather than a regression.
 *
 * No jQuery, no config, no dependency on any other module: it has to run before
 * all of them.
 */
(function () {
    'use strict';

    var KEY = 'two_restore_payment_selection';
    var CLASS = 'two-restoring-payment-selection';
    // Failsafe timing (round 3 adversarial review). A flat wall-clock timer was
    // wrong: on a slow connection or a large combined bundle, DOM-ready can land
    // AFTER it, in which case the suppression lifted first, the tile painted
    // expanded, and the restore collapsed it later - a LONGER flash than the ~34ms
    // one this file exists to remove. So the failsafe is anchored to DOM-ready
    // (which the restore runs just after) with a small grace period, and the flat
    // timer is kept only as an absolute cap for a document that never gets there.
    var FAILSAFE_AFTER_READY_MS = 500;
    var ABSOLUTE_CAP_MS = 5000;

    var root = document.documentElement;

    /**
     * Lift the suppression. Exposed on `window` so TwoCheckoutManager can call
     * it the moment the payment selection has been restored, which is the real
     * end of the window this guards - the timer below is only a failsafe for the
     * case where that code never runs at all (a JS error elsewhere, an asset
     * that failed to load). Idempotent.
     */
    var release = function () {
        if (root && root.classList) {
            root.classList.remove(CLASS);
        }
    };
    window.TwoReleasePaymentStepFlashGuard = release;

    if (!root || !root.classList) {
        return;
    }

    var stored = null;
    try {
        stored = sessionStorage.getItem(KEY);
    } catch (e) {
        // Private mode / storage disabled: no restore will happen either, so
        // there is nothing to suppress.
        return;
    }
    if (!stored) {
        return;
    }

    // A payload this file cannot read as "restoring something other than Two"
    // is treated as Two, i.e. as "do nothing". That covers the legacy plain-id
    // payload written before this guard existed (one transitional page load) as
    // well as anything malformed - both resolve to today's behaviour rather
    // than to hiding a tile that should be visible.
    var restoringTwo = true;
    try {
        var parsed = JSON.parse(stored);
        restoringTwo = !parsed || parsed.two !== false;
    } catch (e) {
        restoringTwo = true;
    }
    if (restoringTwo) {
        return;
    }

    // Already parsed means possibly already painted - see SCOPE above.
    if (document.querySelector('.two-payment-container')) {
        return;
    }

    root.classList.add(CLASS);

    var scheduleReadyFailsafe = function () {
        setTimeout(release, FAILSAFE_AFTER_READY_MS);
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleReadyFailsafe);
    } else {
        scheduleReadyFailsafe();
    }
    setTimeout(release, ABSOLUTE_CAP_MS);
})();
