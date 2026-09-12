/**
 * ABN-554 - typing while the query row is withdrawn.
 *
 * Two modes withdraw it: a country the registry search does not cover
 * (ABN-525), and the sole-trader signup flight. The company-name field is a
 * readonly search trigger in both, and a mode chip is a `<button>`, which
 * swallows text - so every character the buyer typed was lost with nothing on
 * screen to say so.
 *
 * jsdom CAVEAT: a keydown performs no default action, so these cases assert the
 * state the capture produces - manual entry, the field editable and holding the
 * character - rather than a character the browser inserted.
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
    shown
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

let TwoCompanySearch;
let $;

/** @returns {Object} the keydown event that was dispatched */
function press(node, key, modifiers) {
    const event = $.Event('keydown', Object.assign({ key: key }, modifiers || {}));
    $(node).trigger(event);
    return event;
}

beforeEach(() => {
    document.body.innerHTML = '';
    document.head.innerHTML = '';
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    buildAddressForm({ country: 'GB' });
    installStylesheet('views/css/two.css');
    stubAjax($);
});

afterEach(() => {
    releaseWidgets($);
});

describe('a keystroke in a country the search does not cover', () => {
    beforeEach(() => {
        TwoCompanySearch._supportedSearchCountries = ['NO'];
    });

    test.each([
        {
            open: false,
            target: () => panelParts().nameField.get(0),
            key: 'f',
            description: 'the closed field'
        },
        {
            open: true,
            target: () => panelParts().notListed.get(0),
            key: 'f',
            description: 'a mode chip holding the caret while the popover is up'
        }
    ])('$description takes the buyer into manual entry and keeps the character', ({ open, target, key }) => {
        new TwoCompanySearch({ checkoutHost: CHECKOUT_HOST });
        if (open) {
            openPanel();
        }

        press(target(), key);

        const field = panelParts().nameField;
        expect(field.val()).toBe('f');
        expect(field.prop('readonly')).toBe(false);
        expect(document.activeElement).toBe(field.get(0));
        expect(shown(panelParts().panel)).toBe(false);
    });

    test.each([
        { key: ' ', modifiers: {}, description: 'Space, which activates the focused chip' },
        { key: 'Enter', modifiers: {}, description: 'Enter, which activates the focused chip' },
        { key: 'f', modifiers: { ctrlKey: true }, description: 'a keyboard shortcut rather than text' }
    ])('$description is not text and captures nothing', ({ key, modifiers }) => {
        new TwoCompanySearch({ checkoutHost: CHECKOUT_HOST });
        openPanel();

        press(panelParts().notListed.get(0), key, modifiers);

        expect(panelParts().nameField.val()).toBe('');
        expect(panelParts().nameField.prop('readonly')).toBe(true);
    });
});

describe('a keystroke in a country the search covers', () => {
    test('still opens the popover and lands in the query field', () => {
        TwoCompanySearch._supportedSearchCountries = ['GB'];
        new TwoCompanySearch({ checkoutHost: CHECKOUT_HOST });

        press(panelParts().nameField.get(0), 'f');

        expect(panelParts().query.val()).toBe('f');
        expect(panelParts().nameField.val()).toBe('');
        expect(shown(panelParts().panel)).toBe(true);
    });
});

describe('a keystroke during the sole-trader signup flight', () => {
    beforeEach(() => {
        TwoCompanySearch._supportedSearchCountries = ['GB'];
        global.window.TwoSoleTrader_Instance = {
            isAvailableForCurrentCountry: () => true,
            startEnrollment: () => {},
            cancelEnrollment: () => {},
            closeSignupPopup: () => {},
            abandonEnrollment: () => {},
            reclaimSignupPopup: () => false,
            isPopupOpen: () => false
        };
    });

    /** Open the popover and take the flight, which withdraws the query row. */
    function launchFlight() {
        const search = new TwoCompanySearch({ checkoutHost: CHECKOUT_HOST });
        openPanel();
        panelParts().soleTrader.trigger('click');
        return search;
    }

    test.each([
        {
            target: () => panelParts().nameField.get(0),
            keys: ['a'],
            query: 'a',
            description: 'the company field, where the launch parks the caret'
        },
        {
            target: () => panelParts().soleTrader.get(0),
            keys: ['a'],
            query: 'a',
            description: 'a mode chip holding the caret'
        },
        {
            target: () => panelParts().nameField.get(0),
            keys: ['a', 'b', 'c'],
            query: 'abc',
            description: 'successive characters, which accumulate in order'
        }
    ])('$description parks the character in the withdrawn query row', ({ target, keys, query }) => {
        launchFlight();

        keys.forEach((key) => press(target(), key));

        expect(panelParts().query.val()).toBe(query);
        // The field is PrestaShop's own address value; a stray character in it would be submitted.
        expect(panelParts().nameField.val()).toBe('');
        expect(shown(panelParts().panel)).toBe(true);
    });

    test.each([
        { key: ' ', modifiers: {}, description: 'Space, which activates the focused chip' },
        { key: 'Enter', modifiers: {}, description: 'Enter, which activates the focused chip' },
        { key: 'f', modifiers: { ctrlKey: true }, description: 'a keyboard shortcut rather than text' }
    ])('$description is not text and parks nothing', ({ key, modifiers }) => {
        launchFlight();

        press(panelParts().nameField.get(0), key, modifiers);

        expect(panelParts().query.val()).toBe('');
    });

    test('a chip repaint while the flight is still up keeps what was parked', () => {
        const search = launchFlight();
        press(panelParts().nameField.get(0), 'a');

        // What a late sole-trader availability answer does to an open panel.
        search.syncModeChipVisibility();

        expect(panelParts().query.val()).toBe('a');
    });

    test('the mode change that reveals the row keeps what was parked, and searches for it', () => {
        const search = launchFlight();
        ['a', 'b', 'c'].forEach((key) => press(panelParts().nameField.get(0), key));
        const rerun = jest.spyOn(search, 'openSearchForCurrentTerm');

        panelParts().registered.trigger('click');

        expect(panelParts().query.val()).toBe('abc');
        expect(shown(panelParts().searchRow)).toBe(true);
        expect(document.activeElement).toBe(panelParts().query.get(0));
        // Nothing has searched for the parked term, so the revealed row would otherwise sit over an empty body.
        expect(rerun).toHaveBeenCalledTimes(1);
    });
});
