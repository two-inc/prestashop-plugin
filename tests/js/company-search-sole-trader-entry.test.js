/**
 * TWO-40: the two upfront Business / Sole trader chips are gone, and sole
 * trader enrolment is folded directly into the company search control. This
 * pins the "I'm a sole trader" row TwoCompanySearch.js adds to its dropdown,
 * a sibling of "My company is not on the list" (see buildDropdown()).
 *
 * The row's own gate - whether the registry says the current billing
 * country supports sole traders at all - lives in TwoSoleTrader.js
 * (isAvailableForCurrentCountry()); these tests stub that directly via
 * `window.TwoSoleTrader_Instance` rather than loading the real module, so
 * they are about TwoCompanySearch's wiring, not TwoSoleTrader's own
 * availability resolution (covered by sole-trader-server-rendered-toggle.test.js).
 */

'use strict';

const {
    loadCompanySearch,
    buildAddressForm,
    installStylesheet,
    stubAjax,
    releaseWidgets,
    panelParts,
    openPanel,
    typeQuery,
    shown
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

let TwoCompanySearch;
let $;

function makeInstance(config) {
    return new TwoCompanySearch(Object.assign({ checkoutHost: CHECKOUT_HOST }, config || {}));
}

/** Install a stub TwoSoleTrader_Instance, as twopayment.js's global would be. */
function stubSoleTrader(available) {
    const instance = {
        isAvailableForCurrentCountry: jest.fn(() => available),
        startEnrollment: jest.fn(),
        cancelEnrollment: jest.fn()
    };
    global.window.TwoSoleTrader_Instance = instance;
    return instance;
}

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
});

describe('visibility', () => {
    test('is shown when TwoSoleTrader says the country is eligible', () => {
        stubSoleTrader(true);
        makeInstance();
        openPanel();

        expect(shown(panelParts().soleTrader)).toBe(true);
        expect(panelParts().soleTrader.text()).toBe("I'm a sole trader");
    });

    test('is hidden when TwoSoleTrader says the country is not eligible', () => {
        stubSoleTrader(false);
        makeInstance();
        openPanel();

        expect(shown(panelParts().soleTrader)).toBe(false);
    });

    test('is hidden when TwoSoleTrader_Instance does not exist yet (fail-soft)', () => {
        makeInstance();
        openPanel();

        expect(shown(panelParts().soleTrader)).toBe(false);
    });

    test('is hidden while in manual-entry mode - same open/manual-entry/confirmed gate as "not on the list"', () => {
        stubSoleTrader(true);
        makeInstance();
        openPanel();
        panelParts().notListed.trigger('click');

        expect(shown(panelParts().soleTrader)).toBe(false);
    });
});

describe('activation', () => {
    test('clicking it closes the panel and starts sole-trader enrolment', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        openPanel();
        typeQuery('exa');

        panelParts().soleTrader.trigger('click');

        expect(shown(panelParts().panel)).toBe(false);
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);
    });

    test('does nothing destructive if TwoSoleTrader_Instance is missing', () => {
        stubSoleTrader(true);
        makeInstance();
        openPanel();
        // Row is built (and clickable in principle) even without the global;
        // remove it to prove the handler guards rather than throwing.
        delete global.window.TwoSoleTrader_Instance;

        expect(() => panelParts().soleTrader.trigger('click')).not.toThrow();
    });
});

describe('reopening search cancels a pending enrolment (TWO-40)', () => {
    test('opening the dropdown again cancels an in-progress sole-trader enrolment', () => {
        const soleTrader = stubSoleTrader(true);
        makeInstance();
        // openDropdown() unconditionally calls cancelEnrollment() on every
        // open - cheap and idempotent when nothing was pending - so this
        // baselines against the FIRST open rather than asserting zero calls.
        openPanel();
        const callsBeforeEnrolling = soleTrader.cancelEnrollment.mock.calls.length;
        panelParts().soleTrader.trigger('click');
        expect(soleTrader.startEnrollment).toHaveBeenCalledTimes(1);

        // The buyer comes back to ordinary company search - e.g. clicking the
        // company field again - rather than completing the sole-trader flow.
        openPanel();

        expect(soleTrader.cancelEnrollment.mock.calls.length).toBe(callsBeforeEnrolling + 1);
    });
});
