/**
 * Manual-entry mode is page-lifetime state the manager owns and injects, for
 * the same reason the reopen deadline is (see
 * checkout-manager-reopen-memory-wiring.test.js, which this mirrors):
 * `updatedAddressForm` throws away the search instance holding it. The tests
 * here drive the manager's OWN rebuild path rather than injecting a memory
 * object by hand, so they fail if that injection is ever dropped from either
 * mount branch.
 */

'use strict';

const { loadCompanySearch, loadScript, releaseWidgets, buildAddressForm } = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

let TwoCompanySearch;
let TwoCheckoutManager;
let $;

/** The payment step in tile mode - the one mount the manager rebuilds in place. */
function buildTileStep() {
    document.body.innerHTML = [
        '<div class="two-payment-container">',
        '  <div class="two-tile-company-search" id="two-tile-company-search">',
        "    <input type='text' id='two_tile_company' name='two_tile_company' />",
        '  </div>',
        '</div>'
    ].join('\n');
}

function makeTileManager() {
    return new TwoCheckoutManager({
        checkoutHost: CHECKOUT_HOST,
        companySearchInAddressArea: false
    });
}

function makeAddressAreaManager() {
    return new TwoCheckoutManager({
        checkoutHost: CHECKOUT_HOST,
        companySearchInAddressArea: true
    });
}

describe('the manager owns manual-entry mode across its own rebuilds', () => {
    beforeEach(() => {
        const loaded = loadCompanySearch();
        TwoCompanySearch = loaded.TwoCompanySearch;
        $ = loaded.$;
        loadScript('views/js/modules/TwoCheckoutManager.js');
        TwoCheckoutManager = window.TwoCheckoutManager;
    });

    afterEach(() => {
        releaseWidgets($);
        delete window.TwoCheckoutManager_Instance;
    });

    test('tile mount: manual entry chosen before the rebuild is still active after it', () => {
        buildTileStep();
        const manager = makeTileManager();
        manager.initializeCompanySearch();
        const first = manager.companySearch;
        expect(first).toBeTruthy();

        first._manualEntry = true;
        document.getElementById('two-tile-company-search').innerHTML =
            "<input type='text' id='two_tile_company' name='two_tile_company' />";
        manager.initializeCompanySearch();

        expect(manager.companySearch).not.toBe(first);
        expect(manager.companySearch.isManualEntry()).toBe(true);
    });

    test('address-area mount: manual entry chosen before the rebuild is still active after it', () => {
        buildAddressForm({ country: 'GB' });
        const manager = makeAddressAreaManager();
        manager.initializeCompanySearch();
        const first = manager.companySearch;
        expect(first).toBeTruthy();

        first._manualEntry = true;
        first.destroy();
        manager.companySearch = null;
        manager.initializeCompanySearch();

        expect(manager.companySearch).not.toBe(first);
        expect(manager.companySearch.isManualEntry()).toBe(true);
    });

    test('a manager hands its own manual-entry memory down, never the search class default', () => {
        buildTileStep();
        const manager = makeTileManager();
        manager.initializeCompanySearch();

        expect(manager.companySearch._manualEntryMemory)
            .toBe(manager._companySearchManualEntryMemory);
    });

    /**
     * TWO-26775: every pick lifts the page's manual-entry refusal, including a
     * sole-trader enrolment whose reply lands with no live search instance to
     * write the form (a re-render mid round trip). The rebuilt instance must not
     * still refuse the old number while the sole trader's sits unrefused.
     */
    test.each([
        ['a search pick', (manager) => manager.companySearch.publishConfirmedSelection('Example Trading Ltd', '99887766')],
        ['a sole-trader enrolment with no live search instance', (manager) => {
            loadScript('views/js/modules/TwoSoleTrader.js');
            manager.companySearch.destroy();
            manager.companySearch = null;
            window.TwoCheckoutManager_Instance = manager;
            const buyer = { company_name: 'Sam Reed Trading', organization_number: '55667788' };
            expect(window.TwoSoleTrader.prototype.adoptEnrolledIdentity.call({}, buyer)).toBe(false);
            window.TwoSoleTrader.prototype.publishConfirmedSelection.call({}, buyer.company_name, buyer.organization_number);
            manager.initializeCompanySearch();
        }],
    ])('%s lifts the page refusal', (description, pick) => {
        buildTileStep();
        const manager = makeTileManager();
        manager.initializeCompanySearch();
        manager._companySearchManualEntryMemory.disownedId = '12345678';

        pick(manager);

        expect([description, manager.companySearch.isDisownedCompanyNumber('12345678')])
            .toEqual([description, false]);
    });

    test('two managers do not share manual-entry mode', () => {
        buildTileStep();
        const first = makeTileManager();
        first.initializeCompanySearch();
        first.companySearch._manualEntry = true;

        document.body.innerHTML = '';
        buildTileStep();
        const second = makeTileManager();
        second.initializeCompanySearch();

        expect(second.companySearch.isManualEntry()).toBe(false);
    });
});
