/**
 * TWO-25326 bug 11. Doug: "when I select a different payment method, our tile
 * disappears for a second, then reappears briefly and disappears again."
 *
 * All three beats were measured on the staging shop with a rAF-rate sampler:
 *
 *   1. Deselecting Two syncs the surcharge cart line; when the line changes the
 *      module asks core to refresh the cart, and on the payment step core's
 *      refresh is a FULL checkout-page reload. The tile leaves with the old
 *      document.
 *   2. The reloaded page renders EVERY payment option's additional-information
 *      block expanded (a reload wipes radio state, and core only collapses the
 *      unselected ones from its DOM-ready handler). Two's tile was ~497px tall
 *      at first paint, ~244ms into the load.
 *   3. ~34ms later the module restores the option the buyer had actually clicked
 *      and the block collapses to 0px.
 *
 * Beat 2 is the bug: a tile flashing back for a moment after the buyer has
 * navigated away from it. Nothing running at DOM ready can prevent it - by then
 * it has been painted - so the fix is a head-loaded guard that marks the
 * document before the payment markup is parsed, plus the stylesheet rule that
 * keeps the tile out of that first paint, released as soon as the selection has
 * been restored.
 *
 * These tests pin the three conditions that must ALL hold before the guard
 * suppresses anything (it is the kind of mechanism that turns into "the tile is
 * gone" if any of them is wrong), the release on every path out of the restore,
 * and the {id, two} payload the guard reads.
 */

'use strict';

const {
    loadCompanySearch,
    loadOrderIntent,
    loadScript,
    releaseWidgets,
    stubAjax,
    flushPromises,
    installStylesheet,
    buildPaymentTile
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';
const KEY = 'two_restore_payment_selection';
const CLASS = 'two-restoring-payment-selection';

let TwoCheckoutManager;
let $;
let ajax;

/**
 * The payment step, with the Two tile built from the REAL template
 * (paymentinfo.tpl) rather than from a literal `.two-payment-container` written
 * here. The guard's early-return selector, the two.css rule and the template's
 * root class all have to agree on that class name, and a hand-written fixture
 * would keep this suite green if the template renamed it.
 */
function buildPaymentStep() {
    document.body.innerHTML = [
        '<div class="payment-options">',
        '  <div class="payment-option" data-module-name="twopayment">',
        "    <input type='radio' name='payment-option' value='twopayment' id='payment-option-1' />",
        '  </div>',
        '  <div class="payment-option" data-module-name="othermethod">',
        "    <input type='radio' name='payment-option' value='othermethod' id='payment-option-2' />",
        '  </div>',
        '</div>'
    ].join('\n');
    const tile = buildPaymentTile();
    document.querySelector('.payment-option[data-module-name="twopayment"]').appendChild(tile);
    return tile;
}

/** Load the head-time guard, as a <script> in <head> would. */
function loadGuard() {
    delete window.TwoReleasePaymentStepFlashGuard;
    loadScript('views/js/modules/TwoPaymentStepFlashGuard.js');
}

function guardActive() {
    return document.documentElement.classList.contains(CLASS);
}

beforeEach(() => {
    const loaded = loadCompanySearch();
    $ = loaded.$;
    ajax = stubAjax($);
    loadOrderIntent();
    loadScript('views/js/modules/TwoCheckoutManager.js');
    TwoCheckoutManager = window.TwoCheckoutManager;

    window.twopayment = {
        order_intent_url: ORDER_INTENT_URL,
        ajax_token: 'test-token',
        checkout_host: CHECKOUT_HOST
    };
    sessionStorage.clear();
    document.documentElement.classList.remove(CLASS);
});

afterEach(async () => {
    ajax.calls.forEach((call) => {
        if (call.aborted) {
            return;
        }
        try {
            call.fail('abort', 'abort');
        } catch (e) { /* not what this suite is about - see the render-loop suite */ }
    });
    await flushPromises();
    ajax.restore();
    releaseWidgets($);
    document.body.innerHTML = '';
    document.documentElement.classList.remove(CLASS);
    sessionStorage.clear();
    delete window.twopayment;
    delete window.TwoReleasePaymentStepFlashGuard;
    delete window.TwoCheckoutManager_Instance;
});

function makeManager() {
    const manager = new TwoCheckoutManager({
        checkoutHost: CHECKOUT_HOST,
        orderIntentEnabled: true,
        orderIntentUrl: ORDER_INTENT_URL,
        ajaxToken: 'test-token',
        companySearchInAddressArea: true
    });
    window.TwoCheckoutManager_Instance = manager;
    return manager;
}

describe('the guard suppresses the first paint only when all three conditions hold', () => {
    test('restoring a NON-Two option before the markup exists: suppressed', () => {
        sessionStorage.setItem(KEY, JSON.stringify({ id: 'payment-option-2', two: false }));
        loadGuard();

        expect(guardActive()).toBe(true);
    });

    test('restoring TWO\'s own option: not suppressed', () => {
        // The tile is painted expanded and STAYS expanded in this case, so
        // there is no flash. Hiding it here would invent one.
        sessionStorage.setItem(KEY, JSON.stringify({ id: 'payment-option-1', two: true }));
        loadGuard();

        expect(guardActive()).toBe(false);
    });

    test('an ordinary arrival at the payment step: not suppressed', () => {
        loadGuard();

        expect(guardActive()).toBe(false);
    });

    test('the markup is already in the document: not suppressed', () => {
        // Then the browser may already have painted it, and hiding it now would
        // ADD a transition rather than remove one. This is also what makes a
        // mis-registered asset position a no-op instead of a regression.
        sessionStorage.setItem(KEY, JSON.stringify({ id: 'payment-option-2', two: false }));
        buildPaymentStep();
        loadGuard();

        expect(guardActive()).toBe(false);
    });

    test('the legacy bare-id payload is not suppressed', () => {
        // Written by the version of this module that shipped before the guard.
        // It cannot say which option it is, so it resolves to "leave it alone".
        sessionStorage.setItem(KEY, 'payment-option-2');
        loadGuard();

        expect(guardActive()).toBe(false);
    });

    test('the suppression lifts on its own if nothing else ever runs', () => {
        jest.useFakeTimers();
        try {
            sessionStorage.setItem(KEY, JSON.stringify({ id: 'payment-option-2', two: false }));
            loadGuard();
            expect(guardActive()).toBe(true);

            // No TwoCheckoutManager, no restore - a JS error elsewhere, an asset
            // that failed to load. The tile must not stay hidden for the rest of
            // the checkout.
            //
            // 600ms, not 2000: jsdom reports readyState 'complete', so this is the
            // post-DOM-ready grace path, and 2000 now coincides with the absolute
            // cap - which made either path satisfy the assertion. Proven by
            // mutation: stretching the grace period alone left this test passing.
            jest.advanceTimersByTime(600);
            expect(guardActive()).toBe(false);
        } finally {
            jest.useRealTimers();
        }
    });

    test('the failsafe is anchored to DOM-ready, not to the wall clock', () => {
        // A flat timer is wrong: if DOM-ready lands after it, the suppression
        // lifts first, the tile paints expanded, and the restore collapses it
        // later - a LONGER flash than the one this file removes.
        jest.useFakeTimers();
        // Own property on the instance, which is what shadows the prototype
        // getter jsdom provides - and `delete` on the instance is what undoes it.
        // (An earlier version also saved and restored the PROTOTYPE descriptor,
        // which it never touched: a no-op that read as the real restore.)
        Object.defineProperty(document, 'readyState', { value: 'loading', configurable: true });
        try {
            sessionStorage.setItem(KEY, JSON.stringify({ id: 'payment-option-2', two: false }));
            loadGuard();

            // Past the post-ready grace period, but DOM-ready has not happened
            // yet, so the suppression must still hold.
            jest.advanceTimersByTime(1000);
            expect(guardActive()).toBe(true);

            document.dispatchEvent(new window.Event('DOMContentLoaded'));
            jest.advanceTimersByTime(100);
            expect(guardActive()).toBe(true);
            jest.advanceTimersByTime(600);
            expect(guardActive()).toBe(false);
        } finally {
            delete document.readyState;
            jest.useRealTimers();
        }
        expect(document.readyState).toBe('complete');
    });

    test('an absolute cap still lifts it on a document that never reaches DOM-ready', () => {
        jest.useFakeTimers();
        Object.defineProperty(document, 'readyState', { value: 'loading', configurable: true });
        try {
            sessionStorage.setItem(KEY, JSON.stringify({ id: 'payment-option-2', two: false }));
            loadGuard();

            // Still held at the grace period's own length, so this asserts the CAP
            // rather than the DOM-ready path having fired early.
            jest.advanceTimersByTime(600);
            expect(guardActive()).toBe(true);
            jest.advanceTimersByTime(1500);
            expect(guardActive()).toBe(false);
        } finally {
            delete document.readyState;
            jest.useRealTimers();
        }
    });
});

describe('the class and the shipped stylesheet actually agree', () => {
    test('the tile is not displayed while the class is set, and is once it is not', () => {
        // The one assertion that fails if the class name in the JS and the class
        // name in two.css ever drift apart - everything else in this file would
        // keep passing with a stylesheet that suppresses nothing.
        const style = installStylesheet('views/css/two.css');
        try {
            sessionStorage.setItem(KEY, JSON.stringify({ id: 'payment-option-2', two: false }));
            loadGuard();
            const tile = buildPaymentStep();

            expect(window.getComputedStyle(tile).display).toBe('none');

            window.TwoReleasePaymentStepFlashGuard();

            expect(window.getComputedStyle(tile).display).not.toBe('none');
        } finally {
            style.remove();
        }
    });
});

describe('the restore always releases the suppression', () => {
    test('released after the stored option has been re-checked', () => {
        sessionStorage.setItem(KEY, JSON.stringify({ id: 'payment-option-2', two: false }));
        loadGuard();
        buildPaymentStep();

        makeManager();

        expect(document.getElementById('payment-option-2').checked).toBe(true);
        expect(guardActive()).toBe(false);
    });

    test('released even when the stored radio is no longer in the document', () => {
        sessionStorage.setItem(KEY, JSON.stringify({ id: 'no-such-radio', two: false }));
        loadGuard();
        buildPaymentStep();

        makeManager();

        expect(guardActive()).toBe(false);
    });

    test('released even when there is nothing stored at all', () => {
        document.documentElement.classList.add(CLASS);
        buildPaymentStep();

        makeManager();

        expect(guardActive()).toBe(false);
    });

    test('released when the guard file never loaded', () => {
        // The manager clears the class itself in that case, so a missing asset
        // cannot leave a tile permanently hidden.
        delete window.TwoReleasePaymentStepFlashGuard;
        document.documentElement.classList.add(CLASS);
        sessionStorage.setItem(KEY, JSON.stringify({ id: 'payment-option-2', two: false }));
        buildPaymentStep();

        makeManager();

        expect(guardActive()).toBe(false);
    });

    test('released even when applyStoredPaymentSelection throws', () => {
        loadGuard();
        buildPaymentStep();
        document.documentElement.classList.add(CLASS);

        const manager = Object.create(TwoCheckoutManager.prototype);
        manager.applyStoredPaymentSelection = () => { throw new Error('boom'); };

        expect(() => manager.restorePaymentSelectionAfterCartRefresh()).toThrow('boom');
        expect(guardActive()).toBe(false);
    });
});

describe('the payload the guard reads', () => {
    test('a non-Two selection is recorded as two: false', () => {
        buildPaymentStep();
        const manager = makeManager();
        document.getElementById('payment-option-2').checked = true;

        manager.triggerNativeCartRefresh();

        expect(JSON.parse(sessionStorage.getItem(KEY))).toEqual({ id: 'payment-option-2', two: false });
    });

    test('Two\'s own selection is recorded as two: true', () => {
        buildPaymentStep();
        const manager = makeManager();
        document.getElementById('payment-option-1').checked = true;

        manager.triggerNativeCartRefresh();

        expect(JSON.parse(sessionStorage.getItem(KEY))).toEqual({ id: 'payment-option-1', two: true });
    });

    test("Two's selection is recorded as two: true even with no data-module-name element", () => {
        // A theme that renders no `data-module-name` leaves containment unable to
        // answer. Recording `two: false` there would make the guard hide the tile
        // through the first paint of a load on its way to SELECTING Two - the very
        // flash the guard exists to remove, invented by the guard.
        document.body.innerHTML = [
            '<div class="payment-options">',
            "  <input type='radio' name='payment-option' value='twopayment' id='payment-option-1' checked />",
            "  <input type='radio' name='payment-option' value='othermethod' id='payment-option-2' />",
            '</div>'
        ].join('\n');
        const manager = makeManager();

        manager.triggerNativeCartRefresh();

        expect(JSON.parse(sessionStorage.getItem(KEY))).toEqual({ id: 'payment-option-1', two: true });
    });

    test("Two's selection is recorded as two: true when containment FAILS but the radio is Two's", () => {
        // Round 4 review, finding 2. A theme can render `data-module-name` on a
        // wrapper that does not contain the radio - containment then answers "not
        // Two" for Two's own radio, and the guard hides the tile through the first
        // paint of a load on its way to SELECTING Two. So the value tests must run
        // whenever containment FAILS, not only when no such element exists.
        document.body.innerHTML = [
            '<div class="payment-options">',
            '  <div data-module-name="twopayment"><span>label only, no radio</span></div>',
            "  <input type='radio' name='payment-option' value='twopayment' id='payment-option-1' checked />",
            "  <input type='radio' name='payment-option' value='othermethod' id='payment-option-2' />",
            '</div>'
        ].join('\n');
        const manager = makeManager();

        manager.triggerNativeCartRefresh();

        expect(JSON.parse(sessionStorage.getItem(KEY))).toEqual({ id: 'payment-option-1', two: true });
    });

    test('the restore consumes the key, so a later plain reload restores nothing', () => {
        sessionStorage.setItem(KEY, JSON.stringify({ id: 'payment-option-2', two: false }));
        buildPaymentStep();

        makeManager();

        expect(sessionStorage.getItem(KEY)).toBeNull();
    });
});
