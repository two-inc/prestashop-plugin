/**
 * TWO-25764: the search API marks the matched substring in each result's
 * `highlight` field, and the dropdown has to render that marker as a real
 * element while the rest of the label stays inert text.
 *
 * Driven through the REAL widget render path, for the reason
 * company-search-message-rows.test.js gives: the marking is applied from the
 * `open` callback, the only per-repaint hook every jQuery UI a shop may ship
 * actually has.
 */

'use strict';

const {
    loadCompanySearch,
    buildAddressForm,
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

function search(items) {
    typeQuery('Exam');
    jest.advanceTimersByTime(400);
    const pending = ajax.last();
    if (pending) {
        pending.succeed({ items: items });
    }
    jest.advanceTimersByTime(50);
}

function company(name, highlight) {
    return {
        name: name,
        highlight: highlight,
        lookup_id: 'lk-1',
        national_identifier: { id: '11111111' }
    };
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
    window.twopayment = {
        order_intent_url: 'https://shop.example.test/module/twopayment/orderintent',
        ajax_token: 'test-token'
    };
    new TwoCompanySearch({ checkoutHost: CHECKOUT_HOST });
    openPanel();
});

afterEach(() => {
    releaseWidgets($);
    jest.useRealTimers();
    delete window.twopayment;
});

describe('the matched substring is the only markup a result row keeps', () => {
    test.each([
        {
            items: [company('Example Trading Ltd', '<b>Exam</b>ple Trading Ltd')],
            marked: ['Exam'],
            text: 'Example Trading Ltd (11111111)',
            description: 'a plain match is wrapped and the rest of the label is text'
        },
        {
            items: [company('Example Trading Ltd', '<mark>Exam</mark>ple Trading Ltd')],
            marked: ['Exam'],
            text: 'Example Trading Ltd (11111111)',
            description: 'the other marker the API may send is kept as well'
        },
        {
            items: [company('Example <script>alert(1)</script> Ltd', '<b>Exam</b>ple <script>alert(1)</script> Ltd')],
            marked: ['Exam'],
            text: 'Example <script>alert(1)</script> Ltd (11111111)',
            description: 'markup inside the company name reaches the buyer as text'
        },
        {
            items: [company('Example Trading Ltd', '')],
            marked: [],
            text: 'Example Trading Ltd (11111111)',
            description: 'a response carrying no marker renders the plain label'
        },
        {
            items: [],
            marked: [],
            text: 'No matches found',
            description: 'a query that matches nothing marks nothing'
        }
    ])('$description', ({ items, marked, text }) => {
        search(items);
        expect(row(0).text()).toBe(text);
        expect(row(0).find('b, mark').map((i, node) => $(node).text()).get()).toEqual(marked);
        expect(row(0).find('script')).toHaveLength(0);
    });
});

describe('highlighting leaves the rest of the row contract alone', () => {
    test.each([
        {
            items: [],
            expected: { message: true, disabled: true, selected: undefined },
            description: 'a no-matches row stays muted and unselectable'
        },
        {
            items: [company('Example Trading Ltd', '<b>Exam</b>ple Trading Ltd')],
            expected: { message: false, disabled: false, selected: 'false' },
            description: 'a highlighted company row stays a selectable option'
        }
    ])('$description', ({ items, expected }) => {
        search(items);
        expect(row(0).hasClass('two-autocomplete-message')).toBe(expected.message);
        expect(row(0).hasClass('ui-state-disabled')).toBe(expected.disabled);
        expect(wrapper(0).attr('role')).toBe('option');
        expect(wrapper(0).attr('aria-selected')).toBe(expected.selected === undefined ? 'false' : expected.selected);
        expect(wrapper(0).attr('id')).toBeTruthy();
    });
});
