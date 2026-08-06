/**
 * TWO-25326, round 4. The payment tile must not flicker on ANY payment-option
 * change, because a payment-option change must no longer navigate the document.
 *
 * Doug, after round 3 shipped: "when I select our payment method, the tile is
 * rendered then flickers - looks like it is removed and re-rendered. [...] And
 * when I click to another payment method, it is still behaving as before: after
 * disappearing, our tile reappears for a fraction of a second before
 * disappearing again."
 *
 * Round 3 measured the reload and then tried to hide its artefacts at first
 * paint. That could only ever address the second sentence, and only for the
 * inner `.two-payment-container`; the first sentence has no first paint to
 * suppress at all - the tile the buyer just opened is genuinely destroyed with
 * the old document and rebuilt in the new one. So this suite is written against
 * the CAUSE: the navigation.
 *
 * The navigation is core's, and it is entered from this module's own request for
 * it. Emitting `updateCart` reaches `themes/_core/js/cart.js`, which on the
 * payment step calls `refreshCheckoutPage()` (`themes/_core/js/common.js`):
 *
 *     window.location.href = `${window.location.pathname}?${joined}`;   // first
 *     window.location.reload();                                        // after
 *
 * jsdom cannot navigate, so `installCoreCartPlumbing()` below stands in for it -
 * faithfully, and deliberately not as a spy on an event name. It reproduces what
 * a real navigation DOES to the tile: the checkout subtree is torn down and a
 * fresh one is built a tick later, exactly the "removed and re-rendered" Doug
 * describes. `installCorePaymentStep()` likewise mirrors
 * `Payment.toggleOrderButton()` from `themes/_core/js/checkout-payment.js` -
 * collapse every additional-information block, then show only the selected one -
 * which is the mechanism behind the reappear-and-vanish beat, because the
 * reloaded markup carries no inline hiding and core only applies it at DOM
 * ready.
 *
 * The state log is a MutationObserver rather than an abstract "no flicker"
 * assertion: it is the jsdom equivalent of the rAF-rate sampler the live
 * investigation used, and it records the ORDERED sequence of tile existence and
 * rendered visibility so a test can assert on the shape of the sequence - a
 * true->false->true existence run, or a visible->hidden->visible->hidden
 * visibility run - rather than on its endpoints, which look identical either way.
 */

'use strict';

const {
    loadCompanySearch,
    loadOrderIntent,
    loadScript,
    releaseWidgets,
    stubAjax,
    flushPromises,
    buildPaymentTile
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';
const CART_REFRESH_URL = 'https://shop.example.test/en/cart?ajax=1&action=refresh';

const TWO_RADIO_ID = 'payment-option-1';
const OTHER_RADIO_ID = 'payment-option-2';

let TwoCheckoutManager;
let $;
let ajax;

/**
 * The payment step as PrestaShop's classic theme renders it: two payment
 * options, each followed by its own `.js-additional-information` wrapper, plus
 * the order-summary partials core's cart refresh replaces. The Two tile is the
 * REAL template (paymentinfo.tpl via buildPaymentTile), moved into its wrapper -
 * a hand-written `.two-payment-container` would keep this suite green after a
 * template rename.
 *
 * @returns {void}
 */
function buildPaymentStep() {
    document.body.innerHTML = [
        '<div id="checkout">',
        '  <div class="js-cart" data-refresh-url="' + CART_REFRESH_URL + '"></div>',
        '  <div id="js-checkout-summary">',
        '    <div class="cart-summary-subtotals-container">OLD SUBTOTALS</div>',
        '    <div class="cart-summary-totals">OLD TOTAL</div>',
        '    <div class="cart-summary-products">OLD PRODUCTS</div>',
        '  </div>',
        '  <div class="payment-options">',
        '    <div class="payment-option" data-module-name="twopayment">',
        '      <input type="radio" name="payment-option" id="' + TWO_RADIO_ID + '"',
        '             value="twopayment" data-module-name="twopayment">',
        '      <label for="' + TWO_RADIO_ID + '">Two - invoice</label>',
        '    </div>',
        '    <div id="' + TWO_RADIO_ID + '-additional-information" class="js-additional-information"></div>',
        '    <div class="payment-option">',
        '      <input type="radio" name="payment-option" id="' + OTHER_RADIO_ID + '" value="cheque">',
        '      <label for="' + OTHER_RADIO_ID + '">Cheque</label>',
        '    </div>',
        '    <div id="' + OTHER_RADIO_ID + '-additional-information" class="js-additional-information">',
        '      <p>Post us a cheque.</p>',
        '    </div>',
        '  </div>',
        '</div>'
    ].join('\n');

    const tile = buildPaymentTile();
    document.getElementById(TWO_RADIO_ID + '-additional-information').appendChild(tile);
}

/**
 * Mirror of core's `Payment.toggleOrderButton()` collapse/reveal
 * (`themes/_core/js/checkout-payment.js`): every additional-information block is
 * hidden, then the selected option's is shown. Bound delegated on `body`, as
 * core binds it, and called once at init, as core calls it.
 *
 * This is what makes the "reappears for a fraction of a second" beat real: the
 * markup carries NO inline hiding, so between a fresh document's first paint and
 * this running, every block - including Two's - is visible.
 *
 * @returns {void}
 */
function installCorePaymentStep() {
    const toggle = function () {
        $('.js-additional-information').hide();
        const selected = $('input[name="payment-option"]:checked').attr('id');
        if (selected) {
            $('#' + selected + '-additional-information').show();
        }
    };
    $('body').on('change', 'input[name="payment-option"]', toggle);
    toggle();
}

/**
 * Stand-in for core's `updateCart` handler on the payment step
 * (`themes/_core/js/cart.js` -> `refreshCheckoutPage()`), which navigates.
 *
 * jsdom will not navigate, so this reproduces the OBSERVABLE consequence
 * instead: the checkout subtree is destroyed and rebuilt on a later tick, which
 * is what a fresh document does to the tile. Rebuilt WITHOUT any inline hiding,
 * because that is how the reloaded markup arrives - so a rebuild also
 * reproduces the reappear beat unless something suppresses it.
 *
 * @returns {{navigations: number}} live counter
 */
function installCoreCartPlumbing(bus) {
    const state = { navigations: 0 };
    bus.on('updateCart', function () {
        state.navigations += 1;
        document.getElementById('checkout').remove();
        // A navigation's new document arrives later, and with the tile present
        // and unhidden.
        Promise.resolve().then(function () {
            buildPaymentStep();
            installCorePaymentStep();
        });
    });
    return state;
}

/**
 * Ordered log of every DOM state the tile passes through - the jsdom stand-in
 * for the live rAF sampler.
 *
 * A MutationObserver callback is a microtask, so `await flushPromises()`
 * delivers it; consecutive identical states are collapsed so the log is a
 * sequence of TRANSITIONS, which is what the assertions are about.
 *
 * @returns {{rows: Array<{tile: boolean, tileVisible: boolean, wrapVisible: boolean}>, stop: Function}}
 */
function installStateLog() {
    const rows = [];
    const sample = function () {
        const tile = document.querySelector('.two-payment-container');
        const wrap = document.getElementById(TWO_RADIO_ID + '-additional-information');
        return {
            tile: !!tile,
            tileVisible: !!tile && window.getComputedStyle(tile).display !== 'none',
            wrapVisible: !!wrap && window.getComputedStyle(wrap).display !== 'none'
        };
    };
    const record = function () {
        const next = sample();
        const last = rows[rows.length - 1];
        if (!last
            || last.tile !== next.tile
            || last.tileVisible !== next.tileVisible
            || last.wrapVisible !== next.wrapVisible) {
            rows.push(next);
        }
    };
    record();
    const observer = new MutationObserver(record);
    observer.observe(document.documentElement, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['style', 'class']
    });
    return { rows: rows, stop: function () { observer.disconnect(); }, record: record };
}

/** Existence transitions as a compact string, e.g. 'present' or 'present,gone,present'. */
function existenceRun(rows) {
    return rows.map(function (row) { return row.tile ? 'present' : 'gone'; })
        .filter(function (value, index, all) { return index === 0 || all[index - 1] !== value; })
        .join(',');
}

/** Visibility transitions of the tile's own wrapper, same shape. */
function visibilityRun(rows) {
    return rows.map(function (row) { return row.wrapVisible ? 'shown' : 'hidden'; })
        .filter(function (value, index, all) { return index === 0 || all[index - 1] !== value; })
        .join(',');
}

beforeEach(() => {
    const loaded = loadCompanySearch();
    $ = loaded.$;
    loadOrderIntent();
    loadScript('views/js/modules/TwoCheckoutManager.js');
    TwoCheckoutManager = window.TwoCheckoutManager;

    window.twopayment = {
        order_intent_url: ORDER_INTENT_URL,
        ajax_token: 'test-token',
        checkout_host: CHECKOUT_HOST,
        billing_country: 'GB',
        surcharge_cart_line: true
    };

    buildPaymentStep();
    ajax = stubAjax($);
});

afterEach(() => {
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
    delete window.TwoCheckoutManager_Instance;
    jest.restoreAllMocks();
});

function makeManager() {
    return new TwoCheckoutManager({
        checkoutHost: CHECKOUT_HOST,
        orderIntentEnabled: false,
        orderIntentUrl: ORDER_INTENT_URL,
        ajaxToken: 'test-token'
    });
}

/** The sync call, i.e. not the summary refresh that may follow it. */
function syncCalls() {
    return ajax.calls.filter(function (call) {
        return call.settings && call.settings.data && call.settings.data.action === 'syncSurchargeLine';
    });
}

function summaryCalls() {
    return ajax.calls.filter(function (call) { return call.url === CART_REFRESH_URL; });
}

/**
 * Click a payment option the way a buyer does, then let the sync round trip
 * report an actual cart change - the only case that ever triggered a refresh.
 *
 * @param {string} radioId
 * @returns {Promise<void>}
 */
async function selectOptionAndReportCartChanged(radioId) {
    const before = syncCalls().length;
    const radio = document.getElementById(radioId);
    radio.checked = true;
    radio.dispatchEvent(new window.Event('change', { bubbles: true }));
    await flushPromises();

    const call = syncCalls()[before];
    expect(call).toBeDefined();
    call.succeed({ success: true, changed: true, present: radioId === TWO_RADIO_ID });
    await flushPromises();
    await flushPromises();
}

describe('a payment-option change never navigates the document', () => {
    /**
     * Doug's first sentence. The whole tile, not a chip inside it: an existence
     * run of 'present,gone,present' IS the report, and it is what round 3 left
     * completely unaddressed.
     */
    test('selecting Two does not destroy and rebuild the tile', async () => {
        makeManager();
        installCorePaymentStep();
        const core = installCoreCartPlumbing(window.prestashop);
        const log = installStateLog();

        await selectOptionAndReportCartChanged(TWO_RADIO_ID);
        log.record();

        expect(core.navigations).toBe(0);
        expect(existenceRun(log.rows)).toBe('present');
        log.stop();
    });

    /**
     * Doug's second sentence. The tile must collapse ONCE and stay collapsed;
     * 'shown,hidden,shown,hidden' is the reported symptom.
     */
    test('selecting another method collapses the tile once and it stays collapsed', async () => {
        document.getElementById(TWO_RADIO_ID).checked = true;
        makeManager();
        installCorePaymentStep();
        const core = installCoreCartPlumbing(window.prestashop);
        const log = installStateLog();
        expect(log.rows[0].wrapVisible).toBe(true);

        await selectOptionAndReportCartChanged(OTHER_RADIO_ID);
        log.record();

        expect(core.navigations).toBe(0);
        expect(existenceRun(log.rows)).toBe('present');
        expect(visibilityRun(log.rows)).toBe('shown,hidden');
        log.stop();
    });

    /**
     * The event itself, pinned separately from its consequences. The consequence
     * assertions above depend on this suite's model of core being right; this one
     * does not, and it is the line that must never be re-crossed: emitting
     * `updateCart` on the payment step IS asking core to navigate.
     */
    test('core updateCart is never emitted', async () => {
        makeManager();
        let emitted = 0;
        window.prestashop.on('updateCart', function () { emitted += 1; });

        await selectOptionAndReportCartChanged(TWO_RADIO_ID);
        await selectOptionAndReportCartChanged(OTHER_RADIO_ID);

        expect(emitted).toBe(0);
    });

    /**
     * ...and the fix is not "do nothing". A cart change the buyer can see in the
     * totals still has to reach the totals, or the flicker is gone because the
     * feature is.
     */
    test('a reported cart change refreshes the summary partials in place', async () => {
        makeManager();

        await selectOptionAndReportCartChanged(TWO_RADIO_ID);

        const refresh = summaryCalls();
        expect(refresh).toHaveLength(1);
        // jQuery.post normalises the verb to lower case internally.
        expect(String(refresh[0].settings.type).toUpperCase()).toBe('POST');

        refresh[0].succeed({
            cart_summary_subtotals_container: '<div class="cart-summary-subtotals-container">NEW SUBTOTALS</div>',
            cart_summary_totals: '<div class="cart-summary-totals">NEW TOTAL</div>'
        });
        await flushPromises();

        expect(document.querySelector('.cart-summary-subtotals-container').textContent).toBe('NEW SUBTOTALS');
        expect(document.querySelector('.cart-summary-totals').textContent).toBe('NEW TOTAL');
    });

    /** A partial response must not blank the sections it did not carry. */
    test('a partial response leaves the sections it omits alone', async () => {
        makeManager();

        await selectOptionAndReportCartChanged(TWO_RADIO_ID);
        summaryCalls()[0].succeed({
            cart_summary_totals: '<div class="cart-summary-totals">NEW TOTAL</div>',
            cart_summary_products: ''
        });
        await flushPromises();

        expect(document.querySelector('.cart-summary-totals').textContent).toBe('NEW TOTAL');
        expect(document.querySelector('.cart-summary-products').textContent).toBe('OLD PRODUCTS');
    });

    /**
     * A theme with no `.js-cart` refresh URL is the one case where the totals
     * genuinely cannot be updated. It must stay a warning and a stale total, not
     * a thrown error and not a navigation.
     */
    test('a theme with no cart refresh URL warns instead of navigating', async () => {
        const warn = jest.spyOn(console, 'warn').mockImplementation(() => {});
        document.querySelector('.js-cart').removeAttribute('data-refresh-url');
        makeManager();
        const core = installCoreCartPlumbing(window.prestashop);

        await selectOptionAndReportCartChanged(TWO_RADIO_ID);

        expect(core.navigations).toBe(0);
        expect(summaryCalls()).toHaveLength(0);
        expect(warn).toHaveBeenCalled();
    });

    /**
     * A failed refresh is fail-soft, as the sync it follows is: the summary keeps
     * its previous total and checkout is not blocked. The order-create parity
     * gate is what makes that safe.
     */
    test('a failed refresh leaves the previous total and does not throw', async () => {
        const warn = jest.spyOn(console, 'warn').mockImplementation(() => {});
        makeManager();

        await selectOptionAndReportCartChanged(TWO_RADIO_ID);
        summaryCalls()[0].fail('error');
        await flushPromises();

        expect(document.querySelector('.cart-summary-totals').textContent).toBe('OLD TOTAL');
        expect(warn).toHaveBeenCalled();
    });

    /**
     * A latent bug that went out with the emit, kept as a regression pin: core's
     * handler starts `prestashop.cart = event.resp.cart`, and the module passed
     * `resp: {cart: prestashop.cart || {}}` - so a sync with no cart object on the
     * bus replaced core's cart with an empty one for the rest of the page's life.
     */
    test('the cart object on the bus is left alone', async () => {
        const cart = { totals: { total: { amount: 100 } } };
        window.prestashop.cart = cart;
        makeManager();

        await selectOptionAndReportCartChanged(TWO_RADIO_ID);

        expect(window.prestashop.cart).toBe(cart);
    });
});
