/**
 * Two properties of the company-search control that nothing else pins.
 *
 * The length hint is wider than the query field in some themes, so it is
 * clipped by the stylesheet and repeated in full on `title`. And the sweep that
 * collects the companion links below the company field has to stay inside the
 * field it is cleaning, or one widget deletes another's route back to search.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const {
    loadCompanySearch,
    buildAddressForm,
    stubAjax,
    releaseWidgets,
    panelParts,
    openPanel,
    REPO_ROOT
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

const REMOVED_WATERMARK = 'Enter company name to search';

const BACK = '.two-company-search-back';

let TwoCompanySearch;
let $;

beforeEach(() => {
    const loaded = loadCompanySearch();
    $ = loaded.$;
    TwoCompanySearch = loaded.TwoCompanySearch || window.TwoCompanySearch;
    stubAjax($);
    window.twopayment = { checkout_host: CHECKOUT_HOST };
    buildAddressForm();
});

afterEach(() => {
    releaseWidgets($);
    delete window.twopayment;
    document.body.innerHTML = '';
});

function makeInstance(config) {
    return new TwoCompanySearch(Object.assign({ checkoutHost: CHECKOUT_HOST }, config || {}));
}

function source(relPath) {
    return fs.readFileSync(path.join(REPO_ROOT, relPath), 'utf8');
}

describe('the length hint survives a field too narrow to show it', () => {
    test('the query field hovers the FULL hint, not the clipped form', () => {
        makeInstance();
        openPanel();
        const query = panelParts().query;

        expect(query.attr('title')).toBe(TwoCompanySearch.getQueryPlaceholderText());
        expect(query.attr('title')).toBe(query.attr('placeholder'));
    });

    // jsdom does not lay text out, so nothing here proves the hint visibly
    // clips; it proves the declarations that do the clipping are shipped.
    test.each([
        ['.two-company-dropdown__query {', 'text-overflow: ellipsis;', 'the input itself (Firefox)'],
        ['.two-company-dropdown__query::placeholder {', 'text-overflow: ellipsis;', 'the pseudo-element (Chrome, Safari)'],
        ['.two-company-dropdown__query::placeholder {', 'overflow: hidden;', 'the pseudo-element clips'],
        ['.two-company-dropdown__query::placeholder {', 'white-space: nowrap;', 'the hint stays on one line']
    ])('%s declares %s for %s', (selector, declaration) => {
        const css = source('views/css/two.css');
        const block = css.slice(css.indexOf(selector));

        expect(css).toContain(selector);
        expect(block.slice(0, block.indexOf('}'))).toContain(declaration);
    });
});

describe('the removed company-field watermark', () => {
    test.each([
        ['views/js/modules/TwoCompanySearch.js', 'the search widget'],
        ['views/js/modules/TwoCheckoutManager.js', 'the checkout manager'],
        ['twopayment.php', 'the module'],
        ['override/classes/form/CustomerAddressFormatter.php', 'the address-form override'],
        ['translations/es.php', 'the es catalogue'],
        ['translations/nl.php', 'the nl catalogue'],
        ['translations/no.php', 'the no catalogue'],
        ['translations/sv.php', 'the sv catalogue']
    ])('%s keeps no trace of it (%s)', (relPath) => {
        expect(source(relPath)).not.toContain(REMOVED_WATERMARK);
    });
});

describe('two widgets on one page own their own return link', () => {
    /**
     * A second address form, with its own company input for a second widget.
     *
     * @returns {void}
     */
    function addSecondForm() {
        document.body.insertAdjacentHTML(
            'beforeend',
            '<div class="js-address-form"><form data-id-address="9">' +
                '<input type="text" name="billing_company" value="" />' +
                '<select name="id_country">' +
                '<option value="17" data-iso-code="GB" selected>Selected country</option>' +
                '</select></form></div>'
        );
    }

    function backLinkOwners() {
        return Array.prototype.map
            .call(document.querySelectorAll(BACK), function (node) {
                return node.closest('form').querySelector('input').getAttribute('name');
            })
            .sort();
    }

    function twoWidgets() {
        addSecondForm();
        const first = makeInstance();
        const second = makeInstance({ companyFieldSelector: "input[name='billing_company']" });
        first.renderBackToSearchLink();
        // Bootstrapped guard: without the first link every assertion is vacuous.
        expect(backLinkOwners()).toEqual(['company']);
        return { first: first, second: second };
    }

    test.each([
        [
            'the second widget rendering its own link',
            'both links stand',
            function (widgets) { widgets.second.renderBackToSearchLink(); },
            ['billing_company', 'company']
        ],
        [
            'the second widget removing its own link',
            "only the second widget's link is collected",
            function (widgets) {
                widgets.second.renderBackToSearchLink();
                widgets.second.removeBackToSearchLink();
            },
            ['company']
        ]
    ])('%s: %s', (name, description, act, expected) => {
        const widgets = twoWidgets();

        act(widgets);

        expect(backLinkOwners()).toEqual(expected);
    });
});
