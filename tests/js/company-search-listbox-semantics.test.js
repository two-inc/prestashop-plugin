/**
 * ABN-554: the company-search results must reach assistive technology as a
 * listbox of options, matching the WooCommerce panel's contract.
 *
 * jQuery UI's autocomplete builds its menu with `role: null` and re-renders
 * every row on every keystroke, so these pin the roles AFTER a render and
 * again after a re-render, not once at setup.
 *
 * jsdom has no accessibility layer: these assert the rendered attributes a
 * browser derives the tree from. The tree itself is evidence for the PR body.
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
    typeQuery
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

let TwoCompanySearch;
let $;
let ajax;

const SEARCH_RESPONSE = {
    items: [
        { name: 'Example Trading Ltd', lookup_id: 'lk-1', national_identifier: { id: '11111111' } },
        { name: 'Example Holdings Ltd', lookup_id: 'lk-2', national_identifier: { id: '22222222' } }
    ]
};

function makeInstance(config) {
    return new TwoCompanySearch(Object.assign({ checkoutHost: CHECKOUT_HOST }, config || {}));
}

function settleSearch(response) {
    jest.advanceTimersByTime(400);
    const pending = ajax.last();
    if (pending) {
        pending.succeed(response === undefined ? SEARCH_RESPONSE : response);
    }
    jest.advanceTimersByTime(50);
}

/** The menu's own `<ul>`, which jQuery UI appends into the results host. */
function listbox() {
    return panelParts().results.find('ul').first();
}

function rows() {
    return listbox().children('li');
}

/** The element inside a row that carries the option role. */
function option(index) {
    return rows().eq(index).find('[role="option"]').first();
}

/** Highlight a row the way a cursor key does, through the widget's own menu. */
function highlight(index) {
    // `.data()`, not `autocomplete('instance')`: this file runs against 1.10 too.
    panelParts().query.data('ui-autocomplete').menu.focus(null, rows().eq(index));
}

function search(term) {
    typeQuery(term === undefined ? 'Example' : term);
    settleSearch();
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
    makeInstance();
    openPanel();
});

afterEach(() => {
    releaseWidgets($);
    jest.useRealTimers();
    delete window.twopayment;
});

describe('the result set is exposed as a listbox', () => {
    test.each([
        { actual: () => listbox().attr('role'), expected: 'listbox', description: 'the results list is a listbox' },
        { actual: () => option(0).attr('role'), expected: 'option', description: 'the first row is an option' },
        { actual: () => option(1).attr('role'), expected: 'option', description: 'the second row is an option' },
        { actual: () => rows().eq(0).attr('role'), expected: 'presentation', description: 'the <li> scaffolding is inert' },
        { actual: () => option(0).attr('aria-selected'), expected: 'false', description: 'an unhighlighted option is not selected' },
        { actual: () => panelParts().query.attr('role'), expected: 'combobox', description: 'the query field is the combobox' },
        { actual: () => panelParts().query.attr('aria-controls'), expected: () => listbox().attr('id'), description: 'the combobox points at the listbox' },
        { actual: () => panelParts().query.attr('aria-activedescendant'), expected: undefined, description: 'nothing is active before a key press' }
    ])('$description', ({ actual, expected }) => {
        search();
        expect(actual()).toBe(typeof expected === 'function' ? expected() : expected);
    });

    test('every option carries an id, so aria-activedescendant can name one', () => {
        search();
        const ids = rows().map(function () { return $(this).find('[role="option"]').attr('id'); }).get();
        expect(ids.filter(Boolean)).toHaveLength(rows().length);
    });
});

describe('the highlighted option is announced', () => {
    test.each([
        { index: 0, first: 'true', second: 'false', description: 'highlighting the first row selects it and only it' },
        { index: 1, first: 'false', second: 'true', description: 'highlighting the second row moves the selection off the first' }
    ])('$description', ({ index, first: firstSelected, second: secondSelected }) => {
        search();
        highlight(index);
        expect(option(0).attr('aria-selected')).toBe(firstSelected);
        expect(option(1).attr('aria-selected')).toBe(secondSelected);
        expect(panelParts().query.attr('aria-activedescendant')).toBe(option(index).attr('id'));
    });

    test('moving the highlight repoints the combobox rather than accumulating', () => {
        search();
        highlight(0);
        const first = panelParts().query.attr('aria-activedescendant');
        highlight(1);
        expect(panelParts().query.attr('aria-activedescendant')).not.toBe(first);
        expect(option(1).attr('id')).toBe(panelParts().query.attr('aria-activedescendant'));
    });
});

describe('the roles survive jQuery UI re-rendering the menu', () => {
    test.each([
        { actual: () => listbox().attr('role'), expected: 'listbox', description: 'a second query keeps the listbox role' },
        { actual: () => option(0).attr('role'), expected: 'option', description: 'a second query keeps the option role' },
        { actual: () => option(0).attr('aria-selected'), expected: 'false', description: 'a second query resets aria-selected' }
    ])('$description', ({ actual, expected }) => {
        search('Example');
        highlight(0);
        search('Example Holdings');
        expect(actual()).toBe(expected);
    });

    test('a re-render drops the stale aria-activedescendant', () => {
        search('Example');
        highlight(0);
        expect(panelParts().query.attr('aria-activedescendant')).toBeDefined();
        search('Example Holdings');
        expect(panelParts().query.attr('aria-activedescendant')).toBeUndefined();
    });
});

describe('message rows are options the keyboard skips', () => {
    test.each([
        { attribute: 'aria-disabled', expected: 'true', description: 'a message row is a disabled option, not a company' },
        { attribute: 'role', expected: 'option', description: 'a message row is still exposed in the listbox' }
    ])('$description', ({ attribute, expected }) => {
        typeQuery('Nothing At All');
        settleSearch({ items: [] });
        expect(option(0).attr(attribute)).toBe(expected);
    });
});
