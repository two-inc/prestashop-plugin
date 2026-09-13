/**
 * ABN-554 — closing the popover hands the buyer back to the company-name
 * field, and the field's own open-on-focus opener is held off for that one
 * programmatic focus alone.
 *
 * The deferred focus-leave close is the deliberate exception and is asserted
 * here too: it only ever fires once focus has landed on another control, so
 * taking focus back would undo the buyer's own Tab (TWO-25326).
 *
 * jsdom CAVEAT: no sequential focus navigation, so a Tab arrival at the field
 * is a programmatic `focus()`. What a real browser adds is in the PR notes.
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
let ajax;

const SEARCH_RESPONSE = {
    items: [{ name: 'Example Trading Ltd', lookup_id: 'lk-1', national_identifier: { id: '11111111' } }]
};

function makeInstance() {
    return new TwoCompanySearch({ checkoutHost: CHECKOUT_HOST });
}

function companyFieldNode() {
    return $("input[name='company']").get(0);
}

/** A page area with nothing focusable in it, so no default action places focus. */
function backdrop() {
    if (!document.getElementById('two-test-backdrop')) {
        document.body.insertAdjacentHTML('beforeend', '<div id="two-test-backdrop">page</div>');
    }
    return document.getElementById('two-test-backdrop');
}

function dispatchMousedown(node) {
    node.dispatchEvent(new window.MouseEvent('mousedown', { bubbles: true, cancelable: true }));
}

function pressKey(node, key) {
    node.dispatchEvent(new window.KeyboardEvent('keydown', { key: key, bubbles: true, cancelable: true }));
}

/** Open, search and settle one hit, so a row the buyer can pick exists. */
function openWithRows() {
    openPanel();
    const query = panelParts().query;
    query.val('exa');
    query.get(0).dispatchEvent(new window.Event('input', { bubbles: true }));
    jest.advanceTimersByTime(400);
    ajax.last().succeed(SEARCH_RESPONSE);
    jest.advanceTimersByTime(50);
    const instance = query.data('ui-autocomplete');
    expect(instance.menu.element.children('li').length).toBeGreaterThan(0);
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
    ajax = stubAjax($);
    window.twopayment = { order_intent_url: 'https://shop.example.test/module/twopayment/orderintent', ajax_token: 'test-token' };
});

afterEach(() => {
    releaseWidgets($);
    jest.useRealTimers();
    delete window.twopayment;
});

describe('closing the popover hands focus back to the company-name field', () => {
    test.each([
        {
            drive: () => {
                openPanel();
                pressKey(panelParts().query.get(0), 'Escape');
            },
            description: 'Escape inside the panel'
        },
        {
            drive: () => {
                const instance = openWithRows();
                instance.menu.focus(null, instance.menu.element.children('li').eq(0));
                instance.menu.select($.Event('click'));
            },
            description: 'a company adopted from the results'
        },
        {
            drive: () => {
                openPanel();
                panelParts().notListed.trigger('click');
            },
            description: 'manual entry taking the field over'
        }
    ])('the panel is shut with focus on the field after $description', ({ drive }) => {
        makeInstance();

        drive();

        expect(shown(panelParts().panel)).toBe(false);
        expect(document.activeElement).toBe(companyFieldNode());
    });
});

describe('the opener suppression covers the close\'s own focus and nothing after it', () => {
    test.each([
        {
            reopen: () => { pressKey(companyFieldNode(), 'a'); },
            description: 'any keydown on the field'
        },
        {
            reopen: () => { dispatchMousedown(companyFieldNode()); },
            description: 'a pointer press on the field'
        },
        {
            reopen: () => {
                $("input[name='dni']").get(0).focus();
                companyFieldNode().focus();
            },
            description: 'focus arriving at the field from elsewhere, as a Tab back does'
        }
    ])('the popover comes back on $description', ({ reopen }) => {
        makeInstance();
        openPanel();
        pressKey(panelParts().query.get(0), 'Escape');
        expect(shown(panelParts().panel)).toBe(false);

        reopen();
        jest.advanceTimersByTime(10);

        expect(shown(panelParts().panel)).toBe(true);
        expect($("input[name='company']").attr('aria-expanded')).toBe('true');
    });

    /**
     * The press's own default action runs after the panel's handler: it focuses
     * whatever it hit, or clears focus where it hit nothing focusable. jsdom
     * performs neither, so each case plays the browser's part explicitly -
     * which is also what makes the two cases distinguishable at all.
     */
    test.each([
        {
            settleFocus: () => $("input[name='dni']").get(0).focus(),
            expected: () => $("input[name='dni']").get(0),
            description: 'a press on another control leaves focus on that control'
        },
        {
            settleFocus: () => document.activeElement.blur(),
            expected: () => companyFieldNode(),
            description: 'a press on anything unfocusable hands focus to the company field'
        }
    ])('$description', ({ settleFocus, expected }) => {
        makeInstance();
        openPanel();

        dispatchMousedown(backdrop());
        settleFocus();
        jest.advanceTimersByTime(1);

        expect(shown(panelParts().panel)).toBe(false);
        expect(document.activeElement).toBe(expected());
    });

    test('focus DROPPED rather than moved leaves the buyer on the company field', () => {
        makeInstance();
        openPanel();

        document.activeElement.blur();
        panelParts().panel.trigger('focusout');
        jest.advanceTimersByTime(10);

        expect(shown(panelParts().panel)).toBe(false);
        expect(document.activeElement).toBe(companyFieldNode());
    });

    test('focus leaving the panel for another control closes it and leaves that control alone', () => {
        makeInstance();
        openPanel();
        const next = $("input[name='dni']").get(0);

        next.focus();
        panelParts().panel.trigger('focusout');
        jest.advanceTimersByTime(10);

        expect(shown(panelParts().panel)).toBe(false);
        expect(document.activeElement).toBe(next);
    });
});

describe('the field announces whether the popover is showing', () => {
    test.each([
        { open: false, expected: 'false', description: 'shut' },
        { open: true, expected: 'true', description: 'open' }
    ])('aria-expanded is $expected while the popover is $description', ({ open, expected }) => {
        makeInstance();
        if (open) {
            openPanel();
        }

        expect($("input[name='company']").attr('aria-expanded')).toBe(expected);
    });
});
