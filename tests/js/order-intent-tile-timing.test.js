/**
 * TWO-25326. Doug found this running a real checkout with "Enable Company
 * Search In Address Entry" set to No (company search relocated into the
 * payment tile): the order-intent check ran the instant the payment tile
 * mounted/was selected, before the buyer had picked a company from the
 * search results the tile contains.
 *
 * Address mode never had this problem - by the time the payment step (and
 * its generic "Two payment selected" triggers) exists, address-step company
 * capture is already resolved, well before the tile even renders. In tile
 * mode the company control lives INSIDE the tile, so the same generic
 * triggers used to fire checkOrderIntent() off the tile's own mount/default-
 * selection, independent of whether TwoCompanySearch.onCompanySelected() had
 * ever run.
 *
 * These tests pin TwoCheckoutManager.canAutoTriggerOrderIntent(): in tile
 * mode it must hold the generic triggers back until a selection (or manual
 * entry) has actually happened, and address mode must be unaffected.
 */

'use strict';

const {
    loadCompanySearch,
    loadOrderIntent,
    loadScript,
    releaseWidgets,
    buildAddressForm
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';

let TwoCompanySearch;
let TwoOrderIntent;
let TwoCheckoutManager;
let $;

/**
 * The tile-mode payment step: Two is the ONLY payment method (so
 * TwoCheckoutManager treats it as selected by default, with no buyer click
 * needed - the exact case initializeModules() auto-triggers off) and the
 * company-search control is mounted inside the tile, matching
 * paymentinfo.tpl's `#two_tile_company` when the admin setting is off.
 */
function buildTilePaymentStepWithTwoAutoSelected() {
    document.body.innerHTML = [
        '<div class="payment-options">',
        '  <div class="payment-option" data-module-name="twopayment">',
        "    <input type='radio' name='payment-option' value='twopayment' checked />",
        '    <div class="two-payment-container">',
        '      <section class="two-payment-info" style="display: none;">',
        '        <p class="two-subtitle"></p>',
        '        <p class="two-payment-message"></p>',
        '      </section>',
        '      <div class="two-tile-company-search" id="two-tile-company-search">',
        "        <input type='text' class='form-control' id='two_tile_company' name='two_tile_company' autocomplete='off' />",
        '      </div>',
        '    </div>',
        '  </div>',
        '</div>'
    ].join('\n');
}

beforeEach(() => {
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    TwoOrderIntent = loadOrderIntent();
    loadScript('views/js/modules/TwoCheckoutManager.js');
    TwoCheckoutManager = window.TwoCheckoutManager;

    window.twopayment = {
        order_intent_url: ORDER_INTENT_URL,
        ajax_token: 'test-token',
        checkout_host: CHECKOUT_HOST,
        billing_country: 'GB'
    };

    // TwoOrderIntent.checkOrderIntent() is the exact boundary the bug is
    // about ("did the network check run"), decoupled from the ajax/promise
    // plumbing inside it - stubbed rather than driven through $.ajax so the
    // assertions below stay about WHEN it runs, not HOW its response
    // resolves.
    jest.spyOn(TwoOrderIntent.prototype, 'checkOrderIntent')
        .mockImplementation(() => Promise.resolve({ success: true, approved: true }));
});

afterEach(() => {
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
    delete window.TwoCheckoutManager_Instance;
    document.cookie = 'two_company_id=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

function makeTileManager() {
    const manager = new TwoCheckoutManager({
        checkoutHost: CHECKOUT_HOST,
        orderIntentEnabled: true,
        orderIntentUrl: ORDER_INTENT_URL,
        ajaxToken: 'test-token',
        companySearchInAddressArea: false
    });
    // Mirrors views/js/twopayment.js's real bootstrap wiring: onCompanySelected()
    // reaches the manager through this global.
    window.TwoCheckoutManager_Instance = manager;
    return manager;
}

describe('tile mode: order-intent waits for an actual company selection', () => {
    beforeEach(() => {
        buildTilePaymentStepWithTwoAutoSelected();
    });

    test('mounting the tile with Two auto-selected does not fire the check', () => {
        makeTileManager();

        expect(TwoOrderIntent.prototype.checkOrderIntent).not.toHaveBeenCalled();
    });

    test('selecting a company from the search results fires the check', () => {
        const manager = makeTileManager();
        expect(TwoOrderIntent.prototype.checkOrderIntent).not.toHaveBeenCalled();

        manager.companySearch.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', organization_number: '11111111' }
        });

        expect(TwoOrderIntent.prototype.checkOrderIntent).toHaveBeenCalled();
    });

    /**
     * The GB-style deferred path: the search result carries no organisation
     * number up front (it only arrives later via fetchCompanyDetails), so
     * the selection callback still has to count as "selected" immediately -
     * gating on hasConfirmedSelection() (which needs the org number) would
     * wedge this open forever for a buyer who really did pick something.
     */
    test('selecting a result with no organisation number yet still counts as a selection', () => {
        const manager = makeTileManager();

        manager.companySearch.onCompanySelected(null, {
            item: { value: 'No Number Ltd' }
        });

        expect(TwoOrderIntent.prototype.checkOrderIntent).toHaveBeenCalled();
    });

    /**
     * Once a selection has happened, the generic "Two payment selected"
     * triggers (radio change, periodic check, etc.) are exactly as safe as
     * they are in address mode - the gate only needs to hold before the
     * first selection, not forever.
     */
    test('after a selection, the generic payment-option-change trigger is no longer blocked', () => {
        const manager = makeTileManager();
        manager.companySearch.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', organization_number: '11111111' }
        });
        TwoOrderIntent.prototype.checkOrderIntent.mockClear();
        manager.orderIntent.lastResult = null;
        // Past triggerOrderIntentForSelection()'s own 800ms same-selection
        // cooldown, unrelated to the tile-selection gate under test here.
        manager._lastIntentRunAt = 0;

        manager.handlePaymentOptionChange(document.querySelector('input[type="radio"]'));

        expect(TwoOrderIntent.prototype.checkOrderIntent).toHaveBeenCalled();
    });
});

describe('address mode: existing behaviour is unaffected', () => {
    beforeEach(() => {
        buildAddressForm({ country: 'GB' });
        // Address mode's payment step: no tile-mounted search control at all.
        document.body.insertAdjacentHTML(
            'beforeend',
            [
                '<div class="payment-options">',
                '  <div class="payment-option" data-module-name="twopayment">',
                "    <input type='radio' name='payment-option' value='twopayment' checked />",
                '  </div>',
                '</div>'
            ].join('\n')
        );
    });

    test('mounting the payment step with Two auto-selected still fires the check immediately, as before', () => {
        const manager = new TwoCheckoutManager({
            checkoutHost: CHECKOUT_HOST,
            orderIntentEnabled: true,
            orderIntentUrl: ORDER_INTENT_URL,
            ajaxToken: 'test-token',
            companySearchInAddressArea: true
        });
        window.TwoCheckoutManager_Instance = manager;

        expect(TwoOrderIntent.prototype.checkOrderIntent).toHaveBeenCalled();
    });
});

/**
 * TWO-40 core principle: the company search control behaves IDENTICALLY
 * whether mounted in the address area or the payment tile, with exactly ONE
 * exception - it must never populate the address form from the tile.
 * autoFillAddress()/writeOrganizationToAddressIdentifiers() write into the
 * address form's OWN inputs by global selector, with no awareness of which
 * control triggered the fill - so the tile instance's `addressLookupEnabled`
 * has to be hardcoded false, never inherited from the merchant's general
 * auto-fill toggle, or a company picked in the tile would silently rewrite an
 * address the buyer is not even looking at.
 */
describe('tile mode never populates the address form (TWO-40 core principle)', () => {
    beforeEach(() => {
        buildTilePaymentStepWithTwoAutoSelected();
    });

    test('the tile control is constructed with addressLookupEnabled: false even when the merchant toggle is ON', () => {
        const manager = new TwoCheckoutManager({
            checkoutHost: CHECKOUT_HOST,
            orderIntentEnabled: true,
            orderIntentUrl: ORDER_INTENT_URL,
            ajaxToken: 'test-token',
            companySearchInAddressArea: false,
            // The merchant's general toggle is ON - the tile must not inherit it.
            addressLookupEnabled: true
        });
        window.TwoCheckoutManager_Instance = manager;

        expect(manager.companySearch.config.addressLookupEnabled).toBe(false);
    });

    test('the tile control is also addressLookupEnabled: false when the merchant toggle is OFF', () => {
        const manager = new TwoCheckoutManager({
            checkoutHost: CHECKOUT_HOST,
            orderIntentEnabled: true,
            orderIntentUrl: ORDER_INTENT_URL,
            ajaxToken: 'test-token',
            companySearchInAddressArea: false,
            addressLookupEnabled: false
        });
        window.TwoCheckoutManager_Instance = manager;

        expect(manager.companySearch.config.addressLookupEnabled).toBe(false);
    });
});
