/**
 * ABN-554 - typing at the company-name field in a country the registry search
 * does not cover.
 *
 * That country renders no query row (ABN-525) and the field itself is a
 * readonly search trigger, so the popover opens with the caret on a mode chip -
 * a `<button>`, which swallows text. Every character the buyer typed was lost
 * with nothing on screen to say so.
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
