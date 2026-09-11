/**
 * ABN-554 — a press on the popover's own dead space is a no-op: it leaves the
 * caret where the buyer put it, and it never closes the panel.
 *
 * jsdom CAVEAT: a press performs no default action, so each case asserts the
 * press was CANCELLED — the one thing that stops the browser blurring the caret
 * — rather than an unmoved caret alone. The panel's own `mouseup` reclaim,
 * which places focus in the query field when a press left it nowhere, is what
 * an uncancelled dead-space press reaches.
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

function pressMouse(node) {
    const event = new window.MouseEvent('mousedown', { bubbles: true, cancelable: true });
    node.dispatchEvent(event);
    return event;
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
});

describe('a press on the popover\'s dead space changes nothing', () => {
    test.each([
        {
            target: () => panelParts().panel.get(0),
            cancelled: true,
            description: 'the panel\'s own padding'
        },
        {
            target: () => panelParts().results.get(0),
            cancelled: true,
            description: 'the results area below the rows'
        },
        {
            target: () => panelParts().modeChips.get(0),
            cancelled: true,
            description: 'the chip row between two chips'
        },
        {
            target: () => panelParts().query.get(0),
            cancelled: false,
            description: 'the query field, which the press must still be able to place the caret in'
        },
        {
            target: () => panelParts().notListed.get(0),
            cancelled: false,
            description: 'a chip, which the press must still be able to activate'
        }
    ])('$description', ({ target, cancelled }) => {
        new TwoCompanySearch({ checkoutHost: CHECKOUT_HOST });
        openPanel();
        const before = document.activeElement;

        const event = pressMouse(target());

        expect(event.defaultPrevented).toBe(cancelled);
        expect(shown(panelParts().panel)).toBe(true);
        expect(document.activeElement).toBe(before);
    });
});
