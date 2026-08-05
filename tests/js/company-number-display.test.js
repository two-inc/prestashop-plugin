/**
 * TWO-25326 requirement 12 (cross-platform): a company/organisation number that
 * starts with `TWO:` is an internal identifier - the sole-trader enrolment flow
 * mints one (`TWO:ST…`) and stores it in the same field as a real register
 * number - and must never be displayed. Where removing it would leave empty
 * brackets, the brackets go too: `Example Ltd`, never `Example Ltd ()`.
 *
 * Three display sites, in two modules, all routed through ONE helper
 * (views/js/modules/TwoCompanyNumber.js) rather than three prefix tests:
 *
 *   a) the label under the company-name field  - TwoCompanySearch.setCompanyIdHint
 *   b) the search-results rows                 - TwoCompanySearch's result mapping
 *   c) the order-intent sentence in the tile   - TwoOrderIntent.buildCompanyIntentMessage
 *
 * The value itself is untouched everywhere it is not being RENDERED: it is what
 * gets selected, persisted to the session and sent for the credit decision.
 */

'use strict';

const {
    loadCompanySearch,
    loadOrderIntent,
    loadSoleTrader,
    stubAjax,
    releaseWidgets,
    openPanel,
    typeQuery,
    resultTexts,
    callbackRecorder,
    buildAddressForm,
    flushPromises
} = require('./ps-harness');

let TwoCompanySearch;
let TwoOrderIntent;
let TwoCompanyNumber;
let $;
let ajax;

beforeEach(() => {
    const loaded = loadCompanySearch();
    $ = loaded.$;
    TwoCompanySearch = loaded.TwoCompanySearch;
    TwoCompanyNumber = global.window.TwoCompanyNumber;
    global.window.twopayment = { i18n: {}, checkout_host: 'https://api.example.test' };
    TwoOrderIntent = loadOrderIntent();
    ajax = stubAjax($);
});

afterEach(() => {
    ajax.restore();
    releaseWidgets($);
    delete global.window.twopayment;
    document.body.innerHTML = '';
});

describe('the shared helper', () => {
    test('suppresses a TWO:-prefixed number and keeps a real one', () => {
        expect(TwoCompanyNumber.forDisplay('TWO:ST12345')).toBe('');
        expect(TwoCompanyNumber.forDisplay('923456789')).toBe('923456789');
    });

    test('is case- and whitespace-insensitive about the prefix', () => {
        // The value round-trips through a cookie, a form post and the API; a
        // display gate must not be defeated by casing or a stray space.
        expect(TwoCompanyNumber.forDisplay('two:st1')).toBe('');
        expect(TwoCompanyNumber.forDisplay('  TWO:ST1  ')).toBe('');
        expect(TwoCompanyNumber.forDisplay('Two:ST1')).toBe('');
    });

    test('does not suppress a number that merely CONTAINS the prefix text', () => {
        // The rule is "starts with", not "contains" - a register number that
        // happens to embed those characters is still the buyer's real number.
        expect(TwoCompanyNumber.forDisplay('NOTWO:1')).toBe('NOTWO:1');
    });

    test('handles the empty and non-string cases without throwing', () => {
        expect(TwoCompanyNumber.forDisplay(null)).toBe('');
        expect(TwoCompanyNumber.forDisplay(undefined)).toBe('');
        expect(TwoCompanyNumber.forDisplay('')).toBe('');
        expect(TwoCompanyNumber.forDisplay('   ')).toBe('');
        expect(TwoCompanyNumber.forDisplay(12345)).toBe('12345');
    });

    test('labelFor drops the brackets along with the number', () => {
        expect(TwoCompanyNumber.labelFor('Example Ltd', 'TWO:ST9')).toBe('Example Ltd');
        expect(TwoCompanyNumber.labelFor('Example Ltd', '')).toBe('Example Ltd');
        expect(TwoCompanyNumber.labelFor('Example Ltd', '923456789')).toBe('Example Ltd (923456789)');
    });
});

describe('site (a): the label under the company-name field', () => {
    function buildSearch() {
        buildAddressForm();
        return new TwoCompanySearch({ companyFieldSelector: "input[name='company']" });
    }

    test('a real number is shown', () => {
        const search = buildSearch();
        search.setCompanyIdHint('923456789');
        const hint = document.querySelector('.two-company-id-hint');
        expect(hint.textContent).toBe('923456789');
        expect(hint.classList.contains('two-company-id-hint--visible')).toBe(true);
    });

    test('a TWO:-prefixed number renders no label at all', () => {
        const search = buildSearch();
        search.setCompanyIdHint('TWO:ST12345');
        const hint = document.querySelector('.two-company-id-hint');
        expect(hint.textContent).toBe('');
        // Not merely blank text: the visible class is what reserves a line box
        // in normal flow, so leaving it on would add height for a label that
        // shows nothing.
        expect(hint.classList.contains('two-company-id-hint--visible')).toBe(false);
    });

    test('selecting a sole-trader company hides the number but still captures it', () => {
        const search = buildSearch();
        search.onCompanySelected(null, { item: { value: 'Sole Trader AS', organization_number: 'TWO:ST777' } });

        expect(document.querySelector('.two-company-id-hint').textContent).toBe('');
        // The number is still the buyer's identity for the credit decision.
        expect($("input[name='companyid']").val()).toBe('TWO:ST777');
    });
});

describe('site (b): the search-results list', () => {
    test('a TWO:-prefixed result renders the name with no brackets', async () => {
        buildAddressForm();
        const search = new TwoCompanySearch({
            companyFieldSelector: "input[name='company']",
            checkoutHost: 'https://api.example.test'
        });
        const recorder = callbackRecorder();

        search.searchCompanies('sole', recorder.fn);
        ajax.last().succeed({
            items: [
                { name: 'Sole Trader AS', lookup_id: 'l1', national_identifier: { id: 'TWO:ST777' } },
                { name: 'Real Company AS', lookup_id: 'l2', national_identifier: { id: '923456789' } }
            ]
        });
        await flushPromises();

        const labels = recorder.calls[0].results.map((row) => row.label);
        expect(labels).toEqual(['Sole Trader AS', 'Real Company AS (923456789)']);
        // The row still carries the real number, so selecting it captures it.
        expect(recorder.calls[0].results[0].organization_number).toBe('TWO:ST777');
    });

    test('the rendered rows show no TWO: text', () => {
        // Fake timers: the query field's search is debounced 300ms by the
        // widget, so without running that out no request is ever made and the
        // assertions below would have nothing to look at.
        jest.useFakeTimers();
        try {
            buildAddressForm();
            new TwoCompanySearch({
                companyFieldSelector: "input[name='company']",
                checkoutHost: 'https://api.example.test'
            });
            openPanel();
            typeQuery('sole');
            jest.advanceTimersByTime(400);

            const request = ajax.calls.find((record) => String(record.url).includes('companies'));
            // Asserted, not tolerated: with no request there are no rows, and
            // the loop below would pass without ever seeing a rendered result.
            expect(request).toBeDefined();
            request.succeed({ items: [{ name: 'Sole Trader AS', lookup_id: 'l1', national_identifier: { id: 'TWO:ST777' } }] });
            jest.advanceTimersByTime(400);

            expect(resultTexts()).toContain('Sole Trader AS');
            resultTexts().forEach((text) => {
                expect(text).not.toContain('TWO:');
                expect(text).not.toContain('()');
            });
        } finally {
            jest.useRealTimers();
        }
    });
});

describe('site (c): the order-intent sentence', () => {
    function buildIntent() {
        return new TwoOrderIntent({
            enabled: true,
            orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
            ajaxToken: 'test-token'
        });
    }

    test('an approved sentence for a real number keeps it', () => {
        expect(buildIntent().buildCompanyIntentMessage(true, 'Real Company AS', '923456789'))
            .toBe('This order by Real Company AS (923456789) is likely to be accepted by Two');
    });

    test('an approved sentence for a TWO: number drops number AND brackets', () => {
        expect(buildIntent().buildCompanyIntentMessage(true, 'Sole Trader AS', 'TWO:ST777'))
            .toBe('This order by Sole Trader AS is likely to be accepted by Two');
    });

    test('a declined sentence for a TWO: number drops number AND brackets', () => {
        expect(buildIntent().buildCompanyIntentMessage(false, 'Sole Trader AS', 'TWO:ST777'))
            .toBe('Two is not available for this order by Sole Trader AS');
    });

    test('the sentence never renders empty brackets on any path', () => {
        const intent = buildIntent();
        [true, false].forEach((approved) => {
            ['TWO:ST1', '', null, undefined, '   '].forEach((number) => {
                expect(intent.buildCompanyIntentMessage(approved, 'Example Ltd', number)).not.toContain('()');
            });
        });
    });
});

/**
 * A FOURTH display site (review round 2): TwoSoleTrader.applyBuyer() falls
 * back to `buyer.organization_number` as its status LABEL when
 * `buyer.company_name` is blank - and blank company_name is exactly when a
 * sole trader's organisation number is the synthetic `TWO:`-prefixed one this
 * whole requirement exists to hide (it stands in for a buyer with no name or
 * number of their own). Missed by the "three display sites" this requirement
 * was scoped to, because this one is a STATUS label, not a company/number pair
 * being formatted - but it renders the same forbidden value to the buyer.
 */
describe('a fourth site: the sole-trader status label', () => {
    function buildSoleTrader() {
        const TwoSoleTrader = loadSoleTrader();
        const soleTrader = Object.create(TwoSoleTrader.prototype);
        soleTrader.config = {
            orderIntentUrl: 'https://shop.example.test/module/twopayment/orderintent',
            ajaxToken: 'test-token',
            i18n: {}
        };
        soleTrader.tokens = { country: 'NO' };
        soleTrader.hidePrompt = () => {};
        soleTrader.stopObserving = () => {};
        global.window.fetch = () => Promise.resolve({ json: () => Promise.resolve({ success: true }) });
        global.fetch = global.window.fetch;
        return soleTrader;
    }

    afterEach(() => {
        delete global.window.fetch;
        delete global.fetch;
    });

    test('a real company name is shown as-is', async () => {
        const soleTrader = buildSoleTrader();
        let shown = null;
        soleTrader.showStatus = (label) => { shown = label; };

        soleTrader.applyBuyer({ company_name: 'Sole Trader AS', organization_number: '923456789' });
        await flushPromises();

        expect(shown).toBe('Sole Trader AS');
    });

    test('a blank name falling back to a TWO: number shows a generic label, never the number', async () => {
        const soleTrader = buildSoleTrader();
        let shown = null;
        soleTrader.showStatus = (label) => { shown = label; };

        soleTrader.applyBuyer({ company_name: '', organization_number: 'TWO:ST777' });
        await flushPromises();

        expect(shown).not.toBe('TWO:ST777');
        expect(shown).not.toContain('TWO:');
        expect(shown).toBe('Sole trader');
    });

    test('a blank name falling back to a REAL number still shows it - only TWO: is suppressed', async () => {
        const soleTrader = buildSoleTrader();
        let shown = null;
        soleTrader.showStatus = (label) => { shown = label; };

        soleTrader.applyBuyer({ company_name: '', organization_number: '923456789' });
        await flushPromises();

        expect(shown).toBe('923456789');
    });

    test('the PERSISTED company field still carries the TWO: number - only the on-screen status is filtered', async () => {
        const soleTrader = buildSoleTrader();
        soleTrader.showStatus = () => {};
        let posted = null;
        global.window.fetch = (url, opts) => {
            posted = opts.body;
            return Promise.resolve({ json: () => Promise.resolve({ success: true }) });
        };
        global.fetch = global.window.fetch;

        soleTrader.applyBuyer({ company_name: '', organization_number: 'TWO:ST777' });
        await flushPromises();

        expect(posted).toContain('TWO%3AST777');
    });
});
