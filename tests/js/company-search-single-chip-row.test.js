/**
 * TWO-40 follow-up: a chip row offering exactly one chip offers no choice, so
 * it is not rendered. Per-chip gating lives in company-search-mode-chips.test.js
 * and company-search-sole-trader-entry.test.js.
 */

'use strict';

const {
    loadCompanySearch,
    buildAddressForm,
    installStylesheet,
    stubAjax,
    releaseWidgets,
    loadSoleTrader,
    panelParts,
    openPanel,
    shown
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

let TwoCompanySearch;
let $;

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
    delete global.window.TwoCheckoutManager_Instance;
});

describe('the chip row is only rendered when it offers a choice', () => {
    test.each([
        [false, false, 1, false, 'registered alone: no row at all'],
        [true, false, 2, true, 'registered + sole trader: row'],
        [false, true, 2, true, 'registered + enter manually: row'],
        [true, true, 3, true, 'all three: row']
    ])(
        'soleTraderCountry=%p addressAreaSearch=%p -> %i visible chips, row shown=%p (%s)',
        (soleTraderCountry, addressAreaSearch, expectedVisible, rowShown) => {
            global.window.TwoSoleTrader_Instance = {
                isAvailableForCurrentCountry: () => soleTraderCountry
            };
            new TwoCompanySearch({
                checkoutHost: CHECKOUT_HOST,
                companySearchInAddressArea: addressAreaSearch
            });
            openPanel();

            const { modeChips, registered, soleTrader, notListed } = panelParts();
            // All three are always CONSTRUCTED; only the rendered ones count.
            expect(registered.length).toBe(1);
            expect(soleTrader.length).toBe(1);
            expect(notListed.length).toBe(1);

            const visible = [registered, soleTrader, notListed].filter((chip) => {
                return chip.css('display') !== 'none';
            }).length;
            expect(visible).toBe(expectedVisible);
            expect(shown(modeChips)).toBe(rowShown);
        }
    );

    test('no row while the panel is closed, where no chip is rendered', () => {
        global.window.TwoSoleTrader_Instance = { isAvailableForCurrentCountry: () => true };
        new TwoCompanySearch({ checkoutHost: CHECKOUT_HOST });

        expect(shown(panelParts().modeChips)).toBe(false);
    });

    test('the row stays the third child of the panel while hidden', () => {
        global.window.TwoSoleTrader_Instance = { isAvailableForCurrentCountry: () => false };
        new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: false
        });
        openPanel();

        const children = panelParts().panel.children().toArray();
        expect(children).toHaveLength(3);
        expect(children[0].className).toContain('two-company-dropdown__search');
        expect(children[1].className).toContain('two-company-dropdown__results');
        expect(children[2].className).toContain('two-company-mode-chips');
        expect(shown(panelParts().modeChips)).toBe(false);
    });

    test('a late sole-trader answer brings the row back with it', () => {
        // Given a panel opened before the availability round trip landed.
        let available = false;
        global.window.TwoSoleTrader_Instance = {
            isAvailableForCurrentCountry: () => available
        };
        const search = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: false
        });
        openPanel();
        expect(shown(panelParts().modeChips)).toBe(false);

        // When the answer lands and TwoSoleTrader pushes it in.
        available = true;
        const TwoSoleTrader = loadSoleTrader();
        global.window.TwoCheckoutManager_Instance = { companySearch: search };
        TwoSoleTrader.prototype.resyncSoleTraderChip.call({});

        // Then the second chip is reachable, not stranded behind a hidden row.
        expect(shown(panelParts().soleTrader)).toBe(true);
        expect(shown(panelParts().modeChips)).toBe(true);
    });
});
