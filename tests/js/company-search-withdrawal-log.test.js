/**
 * A theme whose markup gives the company field no identifiable address block
 * withdraws company search to manual entry. Silently, that is the failure
 * support cannot diagnose, so the withdrawal carries a console breadcrumb -
 * the same signal the PHP tile gate emits when it withholds the payment option.
 */

'use strict';

const {
    loadCompanySearch,
    buildAddressesStep,
    stubAjax,
    releaseWidgets
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const STEP_ADDRESS_ID = '5';

let TwoCompanySearch;
let $;
let ajax;
let warn;

beforeEach(() => {
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    ajax = stubAjax($);
    warn = jest.spyOn(console, 'warn').mockImplementation(() => {});
});

afterEach(() => {
    warn.mockRestore();
    releaseWidgets($);
    ajax.restore();
    delete window.twopayment;
    document.body.innerHTML = '';
});

/**
 * @returns {Object} a control whose addressScope() has failed closed
 */
function mountWithNoScope() {
    buildAddressesStep({
        editing: 'invoice',
        blockContainers: false,
        blockIds: false,
        countryIsoAttrs: true,
        stepAddressId: STEP_ADDRESS_ID
    });

    return new TwoCompanySearch({
        checkoutHost: CHECKOUT_HOST,
        companyFieldSelector: '#field-company'
    });
}

/**
 * @returns {Array<string>} the withdrawal lines console.warn was given
 */
function withdrawalWarnings() {
    return warn.mock.calls
        .map(function (args) {
            return String(args[0]);
        })
        .filter(function (line) {
            return line.indexOf('company search withheld') !== -1;
        });
}

describe('the withdrawal to manual entry is diagnosable', () => {
    test('a failed-closed scope warns, naming the theme markup as the cause', () => {
        // Given a theme whose markup leaves no identifiable address block
        const control = mountWithNoScope();

        // When the withdrawal gate answers
        expect(control.searchUnavailable()).toBe(true);

        // Then support has a line to find
        const lines = withdrawalWarnings();
        expect(lines.length).toBe(1);
        expect(lines[0]).toMatch(/^Two Payment: /);
        expect(lines[0]).toMatch(/manual entry/);
    });

    test('a re-rendering checkout gets one line per instance, not one per call', () => {
        // Given a control that has already warned
        const control = mountWithNoScope();
        control.searchUnavailable();

        // When the gate re-runs, as it does on every re-render
        control.searchUnavailable();
        control.searchUnavailable();
        control.setupAutocomplete();

        // Then the console is not flooded
        expect(withdrawalWarnings().length).toBe(1);

        // And a SECOND control is not silenced by the first's flag
        const other = mountWithNoScope();
        expect(other.searchUnavailable()).toBe(true);
        expect(withdrawalWarnings().length).toBe(2);
    });

    test('a control with a trusted scope stays quiet', () => {
        // Given core's own markup, where the field's block is identifiable
        buildAddressesStep({ editing: 'invoice', stepAddressId: STEP_ADDRESS_ID });
        const control = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companyFieldSelector: '#field-company'
        });

        expect(control.searchUnavailable()).toBe(false);
        expect(withdrawalWarnings()).toEqual([]);
    });
});
