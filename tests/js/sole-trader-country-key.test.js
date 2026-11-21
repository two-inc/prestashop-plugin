/**
 * Sole-trader enrolment answers for the BILLING country, never the shipping
 * one (TWO-40).
 *
 * `twopayment.company_search_country` carries the company search's country chain -
 * the billing address, falling back to the shipping address, because the
 * payment step renders no country select for the search to read. That fallback
 * must not reach TwoSoleTrader: its availability answer is pre-rendered
 * server-side against the billing address alone, and a capture stamped with a
 * country other than the billing one is withheld by
 * getTwoBrowserCompanySelection(). The bootstrap therefore reads
 * `sole_trader_country`, the billing-only key.
 */

'use strict';

const { loadScript } = require('./ps-harness');

/** jQuery's UMD build sets no globals of its own; twopayment.js waits on them. */
function installJQuery() {
    const jQuery = require('jquery');
    global.$ = jQuery;
    global.jQuery = jQuery;
    global.window.$ = jQuery;
    global.window.jQuery = jQuery;
}

/** Wait for jQuery's document-ready queue to drain - it is not synchronous. */
async function waitFor(predicate) {
    for (let attempt = 0; attempt < 100 && !predicate(); attempt += 1) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

/**
 * Run the real bootstrap over a published payload.
 *
 * @param {Object} payload what the media hook injected as `window.twopayment`
 * @returns {Promise<Object>} the config TwoSoleTrader was constructed with
 */
async function bootstrapSoleTraderConfig(payload) {
    let captured = null;

    global.window.TwoCheckoutManager = class {
        cleanup() {}
    };
    global.TwoCheckoutManager = global.window.TwoCheckoutManager;
    global.window.TwoSoleTrader = class {
        constructor(config) {
            captured = config;
        }
    };
    global.TwoSoleTrader = global.window.TwoSoleTrader;
    global.window.twopayment = payload;
    global.twopayment = payload;

    loadScript('views/js/twopayment.js');
    await waitFor(() => captured !== null);

    if (captured === null) {
        throw new Error('bootstrap did not construct TwoSoleTrader');
    }
    return captured;
}

describe('the country the bootstrap hands TwoSoleTrader', () => {
    beforeEach(() => {
        installJQuery();
    });

    afterEach(() => {
        delete global.window.TwoSoleTrader_Instance;
        delete global.window.TwoCheckoutManager_Instance;
        delete global.window.twopayment;
        delete global.twopayment;
        document.body.innerHTML = '';
    });

    // Given/When/Then: given a payload whose two country keys disagree - the
    // state a cart with an unresolvable billing address and a resolvable
    // shipping one produces - when the bootstrap runs, then enrolment answers
    // for the billing-only key.
    test.each([
        ['GB', 'FR', 'GB', 'both keys resolve'],
        ['', 'FR', '', 'the billing address resolves to nothing'],
        [undefined, 'FR', undefined, 'the billing-only key is absent']
    ])(
        'sole_trader_country=%p alongside company_search_country=%p is handed over as %p (%s)',
        async (soleTraderCountry, searchCountry, expected) => {
            const config = await bootstrapSoleTraderConfig({
                checkout_host: 'https://api.example.test',
                company_search_country: searchCountry,
                sole_trader_country: soleTraderCountry,
                shop_country: 'NL'
            });

            expect(config.billingCountry).toBe(expected);
            // Not vacuous: the shipping fallback reaching enrolment is the
            // defect, and it is a truthy value in every row above.
            expect(config.billingCountry).not.toBe(searchCountry);
        }
    );

    test('the shop country is still published beside it, unmerged', async () => {
        const config = await bootstrapSoleTraderConfig({
            company_search_country: 'FR',
            sole_trader_country: 'GB',
            shop_country: 'NL'
        });

        expect(config.shopCountry).toBe('NL');
    });
});
