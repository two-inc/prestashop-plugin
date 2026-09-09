/**
 * ABN-510 — only one company-search popover may be open at a time, and the
 * popover that closes gives its field's tab stop back.
 *
 * Core renders one editable address form per step, so a shop cannot currently
 * put two of these controls on a page. The two-control fixture is kept anyway:
 * the invariant is the same on every platform that carries this control, and
 * the guards must not drift from the other three implementations.
 *
 * jsdom has no sequential focus navigation and cannot tell a pointer-delivered
 * event from a focus-delivered one, so what is pinned here is the observable
 * state — which popover is open, and what each field's `tabindex` reads — never
 * the event ordering that motivated the fix.
 */

'use strict';

const {
    loadCompanySearch,
    stubAjax,
    releaseWidgets
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const FIELDS = { delivery: '#company-a', invoice: '#company-b' };
const OTHER = { delivery: 'invoice', invoice: 'delivery' };

let TwoCompanySearch;
let $;
let ajax;

/**
 * Two address blocks, each a complete editable form with its own company input.
 *
 * @returns {void}
 */
function buildTwoAddressBlocks() {
    const block = function (id, company, countryId, iso) {
        return [
            '<div id="' + id + '">',
            '  <div class="js-address-form">',
            '    <form method="POST" data-id-address="' + (id === 'delivery-address' ? '7' : '9') + '">',
            '      <input type="text" name="company" id="' + company + '" value="" />',
            "      <input type='text' name='dni' value='' />",
            '      <select name="id_country">',
            '        <option value="' + countryId + '" data-iso-code="' + iso + '" selected>' + iso + '</option>',
            '      </select>',
            '    </form>',
            '  </div>',
            '</div>'
        ].join('\n');
    };
    document.body.innerHTML = [
        block('delivery-address', 'company-a', '17', 'GB'),
        block('invoice-address', 'company-b', '10', 'GB')
    ].join('\n');
}

/** @returns {Object} a mounted control per address block */
function setup() {
    const controls = {};
    Object.keys(FIELDS).forEach(function (which) {
        controls[which] = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companyFieldSelector: FIELDS[which]
        });
    });
    return controls;
}

function isOpen(control) {
    const node = control._dropdown && control._dropdown.length ? control._dropdown.get(0) : null;
    return !!node && !node.hasAttribute('hidden');
}

function tabIndexOf(control) {
    return control.companyField.get(0).getAttribute('tabindex');
}

/** A pointer press, which is what the defect turned on. */
function mouseDownOn(node) {
    $(node).trigger('mousedown');
}

beforeEach(() => {
    buildTwoAddressBlocks();
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    ajax = stubAjax($);
    window.twopayment = {
        order_intent_url: 'https://shop.example.test/module/twopayment/orderintent',
        ajax_token: 'test-token'
    };
});

afterEach(() => {
    jest.useRealTimers();
    releaseWidgets($);
    ajax.restore();
    delete window.twopayment;
    document.body.innerHTML = '';
});

describe('single-open invariant', () => {
    test.each([
        ['delivery'],
        ['invoice']
    ])('%s open first, so opening the other one closes it', (first) => {
        // Given one popover open
        const controls = setup();
        const second = OTHER[first];
        controls[first].openDropdown();

        // When the other opens
        controls[second].openDropdown();

        // Then only the second is up
        expect([isOpen(controls[first]), isOpen(controls[second])])
            .toEqual([false, true]);
    });

    test('re-opening the already-open popover leaves it open', () => {
        const controls = setup();
        controls.delivery.openDropdown();
        controls.delivery.openDropdown();
        expect(isOpen(controls.delivery)).toBe(true);
    });

    test('a closed popover frees the slot, so the other one can take it back', () => {
        const controls = setup();
        controls.delivery.openDropdown();
        controls.invoice.openDropdown();
        controls.invoice.closeDropdown(false);
        controls.delivery.openDropdown();
        expect([isOpen(controls.delivery), isOpen(controls.invoice)]).toEqual([true, false]);
    });

    test('a torn-down control frees the slot it held', () => {
        const controls = setup();
        controls.delivery.openDropdown();
        controls.delivery.destroy();
        expect(TwoCompanySearch._openInstance).toBeNull();
    });
});

describe('tab stop of the popover that closes', () => {
    test.each([
        ['at rest, neither field is a tab stop', function () {}, [null, null]],
        ['delivery open, only that field holds it', function (c) { c.delivery.openDropdown(); }, ['-1', null]],
        ['invoice taking over gives delivery its own back', function (c) { c.delivery.openDropdown(); c.invoice.openDropdown(); }, [null, '-1']],
        ['both closed again leaves no field at -1', function (c) { c.delivery.openDropdown(); c.invoice.openDropdown(); c.invoice.closeDropdown(false); }, [null, null]]
    ])('%s', (name, act, expected) => {
        const controls = setup();
        act(controls);
        expect([tabIndexOf(controls.delivery), tabIndexOf(controls.invoice)])
            .toEqual(expected);
    });
});

describe('a pointer press outside the open popover', () => {
    test('closes it', () => {
        const controls = setup();
        controls.delivery.openDropdown();
        mouseDownOn(controls.invoice.companyField.get(0));
        expect(isOpen(controls.delivery)).toBe(false);
    });

    test('gives its field the tab stop back', () => {
        const controls = setup();
        controls.delivery.openDropdown();
        mouseDownOn(controls.invoice.companyField.get(0));
        expect(tabIndexOf(controls.delivery)).toBeNull();
    });

    test('inside it, leaves it open', () => {
        const controls = setup();
        controls.delivery.openDropdown();
        mouseDownOn(controls.delivery._dropdown.get(0));
        expect(isOpen(controls.delivery)).toBe(true);
    });

    test('on its own company field, leaves it open', () => {
        const controls = setup();
        controls.delivery.openDropdown();
        mouseDownOn(controls.delivery.companyField.get(0));
        expect(isOpen(controls.delivery)).toBe(true);
    });
});
