/**
 * TWO-25503. Doug found this in the payment tile: selecting a company there
 * correctly ran the order-intent check, but the two ways of arriving at a SOLE
 * TRADER did not re-run it - switching into sole-trader mode where an identity
 * is adopted, and "select a different sole trader". The tile went on showing the
 * previous company's approval sentence, because the stale result from the first
 * selection was never refreshed.
 *
 * triggerOrderIntentForSelection() reuses `lastResult` whenever it has one, so
 * changing which entity the buyer is has to invalidate that first. The ordinary
 * search re-selection already did; these paths did not.
 */

'use strict';

const {
    loadCompanySearch,
    loadOrderIntent,
    loadSoleTrader,
    loadScript,
    flushPromises,
    releaseWidgets
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const ORDER_INTENT_URL = 'https://shop.example.test/module/twopayment/orderintent';

const ADOPTED_BUYER = {
    company_name: 'Aurora Sole Trading',
    country_code: 'GB',
    email: 'buyer@example.test',
    first_name: 'Alex',
    last_name: 'Buyer',
    organization_number: 'TWO:ST123456789012',
    billing_address: {
        street: 'Wharf Lane',
        building: 'Wharf Lane',
        apartment: '',
        city: 'Ashford',
        postal_code: 'TN23 1AA',
        region: '',
        country: null
    },
    shipping_address: null
};

let TwoCompanySearch;
let TwoOrderIntent;
let TwoSoleTrader;
let TwoCheckoutManager;
let $;

function buildTilePaymentStep() {
    document.body.innerHTML = [
        '<div class="payment-options">',
        '  <div class="payment-option" data-module-name="twopayment">',
        "    <input type='radio' name='payment-option' value='twopayment' checked />",
        '    <div class="payment-option-content">',
        '      <div class="two-payment-container">',
        '        <section class="two-payment-info" style="display: none;">',
        '          <p class="two-subtitle"></p>',
        '          <p class="two-payment-message"></p>',
        '        </section>',
        '        <div class="two-tile-company-search">',
        "          <input type='text' id='two_tile_company' name='two_tile_company' autocomplete='off' />",
        '        </div>',
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
    TwoSoleTrader = loadSoleTrader();
    loadScript('views/js/modules/TwoCheckoutManager.js');
    TwoCheckoutManager = window.TwoCheckoutManager;

    window.twopayment = {
        order_intent_url: ORDER_INTENT_URL,
        ajax_token: 'test-token',
        checkout_host: CHECKOUT_HOST
    };
    window.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve({ success: true }) });
    global.fetch = window.fetch;

    buildTilePaymentStep();
});

afterEach(() => {
    releaseWidgets($);
    document.body.innerHTML = '';
    delete window.twopayment;
    delete window.TwoCheckoutManager_Instance;
    delete window.TwoSoleTrader_Instance;
    delete global.fetch;
    delete window.fetch;
    window.localStorage.clear();
});

/**
 * A manager holding the settled result of a company the buyer picked FIRST -
 * the state the stale sentence was rendered from.
 */
function managerShowingPreviousCompany() {
    const manager = new TwoCheckoutManager({
        checkoutHost: CHECKOUT_HOST,
        orderIntentEnabled: true,
        orderIntentUrl: ORDER_INTENT_URL,
        ajaxToken: 'test-token',
        companySearchInAddressArea: false
    });
    window.TwoCheckoutManager_Instance = manager;
    manager.currentStep = 'payment';
    manager.initializeOrderIntent();
    manager.orderIntent.lastResult = { success: true, approved: true, message: '' };
    manager.orderIntent.lastCompany = 'Previous Trading Ltd';
    manager.orderIntent.lastCompanyNumber = '11111111';

    return manager;
}

describe('changing which entity the buyer is always re-runs the check', () => {
    test('a sole-trader adoption discards the previous company result', async () => {
        const manager = managerShowingPreviousCompany();
        const soleTrader = new TwoSoleTrader({
            checkoutHost: CHECKOUT_HOST,
            orderIntentUrl: ORDER_INTENT_URL,
            ajaxToken: 'test-token',
            billingCountry: 'GB'
        });
        soleTrader.tokens = { country: 'GB' };
        const recheck = jest.spyOn(manager, 'recheckOrderIntentForNewSelection');

        soleTrader.applyBuyer(ADOPTED_BUYER, soleTrader._enrollGeneration);
        await flushPromises();

        expect(recheck).toHaveBeenCalled();
        expect(manager.getConfirmedCompanySelection().company).toBe('Aurora Sole Trading');
    });

    test('an ordinary search re-selection still re-runs it', () => {
        const manager = managerShowingPreviousCompany();
        manager.initializeCompanySearch();
        const recheck = jest.spyOn(manager, 'recheckOrderIntentForNewSelection');

        manager.companySearch.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', organization_number: '22222222' }
        });

        expect(recheck).toHaveBeenCalled();
    });

    test('the re-run clears the previous result so the tile cannot reuse it', () => {
        const manager = managerShowingPreviousCompany();
        const trigger = jest.spyOn(manager, 'triggerOrderIntentForSelection')
            .mockImplementation(() => {});

        manager.recheckOrderIntentForNewSelection();

        expect(manager.orderIntent.lastResult).toBeNull();
        expect(trigger).toHaveBeenCalled();
    });

    test('nothing runs when the buyer is not paying with Two', () => {
        const manager = managerShowingPreviousCompany();
        document.querySelector("input[name='payment-option']").checked = false;
        const trigger = jest.spyOn(manager, 'triggerOrderIntentForSelection')
            .mockImplementation(() => {});

        manager.recheckOrderIntentForNewSelection();

        expect(trigger).not.toHaveBeenCalled();
        expect(manager.orderIntent.lastResult).not.toBeNull();
    });
});
