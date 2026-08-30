/**
 * The reopen deadline is page-lifetime state the manager owns and injects,
 * because `updatedAddressForm` throws away the search instance holding it. The
 * tests here drive the manager's OWN rebuild path rather than injecting a
 * memory object by hand, so they fail if that injection is ever dropped.
 */

'use strict';

const { loadCompanySearch, loadScript, releaseWidgets } = require('./ps-harness');

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

function makeManager() {
    return new TwoCheckoutManager({
        checkoutHost: CHECKOUT_HOST,
        companySearchInAddressArea: false
    });
}

describe('the manager owns the reopen deadline across its own rebuilds', () => {
    beforeEach(() => {
        const loaded = loadCompanySearch();
        TwoCompanySearch = loaded.TwoCompanySearch;
        $ = loaded.$;
        loadScript('views/js/modules/TwoCheckoutManager.js');
        TwoCheckoutManager = window.TwoCheckoutManager;
        buildTileStep();
    });

    afterEach(() => {
        releaseWidgets($);
        delete window.TwoCheckoutManager_Instance;
    });

    test('a deadline armed before the rebuild is still owed after it', () => {
        // Given a manager that has mounted the tile search
        const manager = makeManager();
        manager.initializeCompanySearch();
        const first = manager.companySearch;
        expect(first).toBeTruthy();

        // When the buyer's armed reopen is followed by the theme replacing the
        // mount, which is what makes the manager tear down and build again
        first.armReopen(Date.now() + 1000);
        document.getElementById('two-tile-company-search').innerHTML =
            "<input type='text' id='two_tile_company' name='two_tile_company' />";
        manager.initializeCompanySearch();

        // Then it really is a new instance, and it inherited the deadline
        expect(manager.companySearch).not.toBe(first);
        expect(manager.companySearch.reopenDeadline()).toBeGreaterThan(0);
    });

    test('a manager hands its own memory down, never the search class default', () => {
        // Given a mounted tile search
        const manager = makeManager();
        manager.initializeCompanySearch();

        // Then the object it reads deadlines from is the manager's, so the
        // deadline survives an instance that does not
        expect(manager.companySearch._reopenMemory).toBe(manager._companySearchReopenMemory);
    });

    test('two managers do not share a deadline', () => {
        // Given two managers, as a failed init and its retry produce
        const first = makeManager();
        first.initializeCompanySearch();
        first.companySearch.armReopen(Date.now() + 1000);

        const second = makeManager();
        second.initializeCompanySearch();

        // Then the second owes the buyer nothing
        expect(second.companySearch.reopenDeadline()).toBe(0);
    });
});
