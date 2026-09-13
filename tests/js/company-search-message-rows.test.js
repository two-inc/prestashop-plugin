/**
 * ABN-554: a row that carries a message instead of a company must reach the
 * buyer as a disabled row, on every jQuery UI a PrestaShop theme may ship.
 *
 * These drive the REAL widget render path rather than calling a renderer
 * directly: the marking is done from the autocomplete `open` callback, which is
 * the only per-repaint hook that exists on jQuery UI 1.10 as well as 1.14.
 * Reaching in past that hook would pass against a mechanism the shop's own
 * jQuery UI never runs - which is how the previous `_renderItem` override went
 * green here and inert in production.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const {
    REPO_ROOT,
    loadCompanySearch,
    buildAddressForm,
    stubAjax,
    releaseWidgets,
    replaceAddressForm,
    panelParts,
    openPanel,
    typeQuery
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

let TwoCompanySearch;
let $;
let ajax;

function makeInstance(config) {
    return new TwoCompanySearch(Object.assign({ checkoutHost: CHECKOUT_HOST }, config || {}));
}

function rows() {
    return panelParts().results.find('ul').first().children('li');
}

function row(index) {
    return rows().eq(index);
}

/** The element inside a row that the widget wraps its label in. */
function wrapper(index) {
    return row(index).children().first();
}

function settle(outcome) {
    jest.advanceTimersByTime(400);
    const pending = ajax.last();
    if (pending) {
        outcome(pending);
    }
    jest.advanceTimersByTime(50);
}

/** A search that comes back with nothing to offer. */
function searchWithNoMatches() {
    typeQuery('Nothing At All');
    settle((pending) => pending.succeed({ items: [] }));
}

/** A search the register could not answer. */
function searchThatFails() {
    typeQuery('Example');
    settle((pending) => pending.fail('error', 'error'));
}

/** A search that returns companies. */
function searchWithResults(label) {
    typeQuery('Example');
    settle((pending) => pending.succeed({
        items: [{ name: label || 'Example Trading Ltd', lookup_id: 'lk-1', national_identifier: { id: '11111111' } }]
    }));
}

beforeEach(() => {
    jest.useFakeTimers();
    document.body.innerHTML = '';
    document.head.innerHTML = '';
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    buildAddressForm();
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

describe('a message row is marked as a message, whatever put it there', () => {
    test.each([
        { arrange: searchWithNoMatches, cause: 'two-autocomplete-no-matches', description: 'a search with no matches' },
        { arrange: searchThatFails, cause: 'two-autocomplete-unavailable', description: 'a search the register could not answer' }
    ])('$description', ({ arrange, cause }) => {
        arrange();
        expect(row(0).hasClass('two-autocomplete-message')).toBe(true);
        expect(row(0).hasClass(cause)).toBe(true);
        // What jQuery UI's own menu checks before acting on Enter.
        expect(row(0).hasClass('ui-state-disabled')).toBe(true);
        expect(wrapper(0).attr('aria-disabled')).toBe('true');
    });

    test.each([
        { actual: () => row(0).hasClass('two-autocomplete-message'), description: 'is not a message row' },
        { actual: () => row(0).hasClass('ui-state-disabled'), description: 'is not disabled' },
        { actual: () => wrapper(0).attr('aria-disabled') === 'true', description: 'is not announced as disabled' }
    ])('a company row $description', ({ actual }) => {
        searchWithResults();
        expect(actual()).toBe(false);
    });

    test('the message is rendered as text, never as markup', () => {
        searchThatFails();
        expect(row(0).find('img')).toHaveLength(0);
        expect(row(0).text()).toContain('temporarily unavailable');
    });
});

describe('a message row survives the widget rebuilding its list', () => {
    test.each([
        {
            rerender: (search) => { search.setupAutocomplete(); },
            description: 'a repeated setupAutocomplete()'
        },
        {
            rerender: () => { document.querySelector("select[name='id_country']").dispatchEvent(new window.Event('change')); },
            description: 'a country change'
        }
    ])('$description leaves the next message row still disabled', ({ rerender }) => {
        const search = makeInstance();
        openPanel();
        searchWithNoMatches();
        for (let i = 0; i < 20; i += 1) {
            rerender(search);
        }
        searchWithNoMatches();
        expect(row(0).hasClass('ui-state-disabled')).toBe(true);
        expect(wrapper(0).attr('aria-disabled')).toBe('true');
    });

    test('a fresh widget after an address-form rebuild marks its rows too', () => {
        replaceAddressForm();
        makeInstance();
        openPanel();
        searchWithNoMatches();
        expect(row(0).hasClass('ui-state-disabled')).toBe(true);
    });
});

describe('a message row is not selectable', () => {
    /** A real Enter, so the widget's own key handling is what is under test. */
    function pressEnter() {
        const event = new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        Object.defineProperty(event, 'keyCode', { get: () => 13 });
        Object.defineProperty(event, 'which', { get: () => 13 });
        panelParts().query.get(0).dispatchEvent(event);
    }

    function highlight(index) {
        panelParts().query.data('ui-autocomplete').menu.focus(null, row(index));
    }

    test.each([
        { arrange: searchWithNoMatches, selections: 0, company: '', description: 'Enter on a message row does nothing at all' },
        { arrange: () => searchWithResults('Example Trading Ltd'), selections: 1, company: 'Example Trading Ltd', description: 'Enter on a company row still picks it' }
    ])('$description', ({ arrange, selections, company }) => {
        arrange();
        highlight(0);
        const selected = [];
        panelParts().query.data('ui-autocomplete').menu.element.on('menuselect', () => selected.push(true));
        pressEnter();
        // The count, not just the field: the select handler already refuses to
        // write a message row, but the widget reaching that handler at all
        // means it has treated the row as the buyer's choice and closed the list.
        expect(selected).toHaveLength(selections);
        expect(panelParts().nameField.val()).toBe(company);
    });
});

describe('the widget is reached only through APIs jQuery UI 1.10 has', () => {
    // The suite runs a far newer jQuery UI than the shops do, so a call that
    // only exists on the newer one passes here and throws in production.
    const ABSENT_BEFORE_111 = "autocomplete('instance')";

    test.each(
        fs.readdirSync(path.join(REPO_ROOT, 'views/js/modules'))
            .filter((name) => name.endsWith('.js'))
            .map((name) => ({ name, description: name }))
    )('$description calls no autocomplete API added after 1.10', ({ name }) => {
        const code = fs.readFileSync(path.join(REPO_ROOT, 'views/js/modules', name), 'utf8')
            .split('\n')
            .filter((line) => !/^\s*(\/\/|\*|\/\*)/.test(line))
            .join('\n');
        expect(code).not.toContain(ABSENT_BEFORE_111);
    });

    test('clearAutocompleteMenu empties the list it is given', () => {
        const search = makeInstance();
        openPanel();
        searchWithResults();
        expect(rows().length).toBeGreaterThan(0);
        search.clearAutocompleteMenu();
        expect(rows().length).toBe(0);
    });
});
