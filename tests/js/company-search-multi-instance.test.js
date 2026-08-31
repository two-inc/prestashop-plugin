/**
 * `TwoCompanySearch` must be genuinely multi-instance: two live controls on one
 * page each hold their own state, own their own DOM nodes, read their own
 * collaborators, and neither one's mutation reaches the other.
 *
 * The shipped module mounts ONE control per page - the address-area field or
 * the payment tile, in mutually exclusive branches of
 * TwoCheckoutManager.initializeCompanySearch(). So the two-control fixture here
 * is not a shape a shop serves today; it is the shape the class must survive
 * before a second mount can be added.
 *
 * A handful of the tests below are regression pins rather than leak proofs -
 * the event namespace, the panel and query field, the manual-entry flag.
 *
 * Deliberately NOT covered, because the sharing is the design and not a leak:
 * the result cache (keyed by term and country, so both controls asking the same
 * question want the same answer - pinned below), the `_instanceSeq` allocator
 * (class-scoped is what makes each namespace unique), and the company-cookie
 * write handle (a page-level mutex over PrestaShop's single session cookie).
 */

'use strict';

const {
    loadCompanySearch,
    buildAddressesStep,
    stubAjax,
    releaseWidgets,
    loadScript
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
// The step form's id and the block form's id, kept apart so an assertion can
// name which one answered; the block's is dropped by the parser.
const STEP_ADDRESS_ID = '5';
const BLOCK_ADDRESS_ID = '12';

let TwoCompanySearch;
let $;
let ajax;

/**
 * Two address blocks, each a complete editable form with its own company input
 * and its own country select.
 *
 * Not a shape core serves: it renders one editable form per step (see
 * mountOnCoreStep() below). It is the shape the class must survive before a
 * second mount can be added, and the only one in which a per-block leak is
 * observable at all - so the leak proofs live here and the core-markup
 * properties are asserted separately, against core's own fixture.
 *
 * Two countries on purpose: it is the one page fact that provably differs per
 * control, so a control answering with the other's country is visible rather
 * than coincidentally equal.
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
            "      <input type='text' name='address1' value='' />",
            "      <input type='text' name='postcode' value='' />",
            "      <input type='text' name='city' value='' />",
            '      <select name="id_country">',
            '        <option value="' + countryId + '" data-iso-code="' + iso + '" selected>' + iso + '</option>',
            '      </select>',
            '      <input type="hidden" name="saveAddress" value="delivery">',
            '    </form>',
            '  </div>',
            '</div>'
        ].join('\n');
    };
    document.body.innerHTML = [
        block('delivery-address', 'company-a', '17', 'GB'),
        block('invoice-address', 'company-b', '10', 'NO')
    ].join('\n');
}

/**
 * @param {Object} [extraConfig]
 * @returns {Object} a control on the first address block
 */
function makeFirst(extraConfig) {
    return new TwoCompanySearch(Object.assign({
        checkoutHost: CHECKOUT_HOST,
        companyFieldSelector: '#company-a'
    }, extraConfig || {}));
}

/**
 * @param {Object} [extraConfig]
 * @returns {Object} a control on the second address block
 */
function makeSecond(extraConfig) {
    return new TwoCompanySearch(Object.assign({
        checkoutHost: CHECKOUT_HOST,
        companyFieldSelector: '#company-b'
    }, extraConfig || {}));
}

/**
 * @param {Object} instance
 * @returns {void} opens that control's panel the way a buyer does
 */
function openPanelFor(instance) {
    instance.companyField.trigger('mousedown');
}

/**
 * @param {Object} instance
 * @param {string} value
 * @returns {void} types into that control's OWN query field
 */
function typeInto(instance, value) {
    const query = instance._queryField;
    query.val(value);
    query.get(0).dispatchEvent(new window.Event('input', { bubbles: true }));
}

/** In the shape GET /companies/v2/company returns. */
const SEARCH_RESPONSE = {
    items: [{ name: 'Example Ltd', lookup_id: 'lk-1', national_identifier: { id: '11111111' } }]
};

beforeEach(() => {
    buildTwoAddressBlocks();
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    ajax = stubAjax($);
});

afterEach(() => {
    jest.useRealTimers();
    releaseWidgets($);
    ajax.restore();
    delete window.twopayment;
    delete window.TwoSoleTrader_Instance;
    delete window.TwoCheckoutManager_Instance;
    document.body.innerHTML = '';
});

describe('two live controls do not share state', () => {
    test('each reads the country off its OWN address block, not the first in the document', () => {
        // Given two controls, one per address block
        const first = makeFirst();
        const second = makeSecond();

        // Then neither reports the other's country
        expect(first.getCurrentCountry()).toBe('GB');
        expect(second.getCurrentCountry()).toBe('NO');
    });

    test('a search fills the class cache for its OWN country only, so the other control still goes to the wire', () => {
        // Given two controls in different countries
        jest.useFakeTimers();
        const first = makeFirst();
        const second = makeSecond();

        // When the GB control runs a search to completion
        openPanelFor(first);
        typeInto(first, 'exa');
        jest.advanceTimersByTime(400);
        ajax.last().succeed(SEARCH_RESPONSE);
        jest.advanceTimersByTime(50);

        // Then the entry answers GB and is unreachable from NO
        expect(TwoCompanySearch.cacheGet(first.buildCacheKey('exa'))).not.toBeNull();
        expect(TwoCompanySearch.cacheGet(second.buildCacheKey('exa'))).toBeNull();

        // And the NO control asking the same question asks the API, rather
        // than showing the buyer GB companies
        const beforeSecond = ajax.calls.length;
        openPanelFor(second);
        typeInto(second, 'exa');
        jest.advanceTimersByTime(400);
        expect(ajax.calls.length).toBe(beforeSecond + 1);
    });

    test.each([
        ["select[name='id_country']"],
        ["input[name='company']"]
    ])('scopedQuery(%s) answers from this control OWN block, not first-in-document', (selector) => {
        // Given a control in the SECOND block, so a document-wide lookup would
        // answer with the first block's node
        const second = makeSecond();

        const found = second.scopedQuery(selector);
        expect(found).toBe(document.querySelector('#invoice-address ' + selector));
        expect(found).not.toBe(document.querySelector(selector));
    });

    test('each binds its country listener to its OWN select', () => {
        // Given two controls
        const first = makeFirst();
        const second = makeSecond();

        // Then each holds the select inside its own block
        expect(first._boundCountrySelector)
            .toBe(document.querySelector('#delivery-address select[name=\'id_country\']'));
        expect(second._boundCountrySelector)
            .toBe(document.querySelector('#invoice-address select[name=\'id_country\']'));
    });

    test('arming one control panel reopen does not arm the other', () => {
        // Given two controls built the un-injected way, so the separation under
        // test is the class's own default and not the fixture's
        const first = makeFirst();
        const second = makeSecond();

        // When a re-render arms the first
        first.armReopen(Date.now() + 1000);

        // Then the second is not owed a reopen
        expect(first.reopenDeadline()).toBeGreaterThan(0);
        expect(second.reopenDeadline()).toBe(0);
    });

    test('a reopen deadline survives the control OWN rebuild, via the injected memory', () => {
        // Given a page-lifetime memory for one mount
        const memory = {};
        const first = makeFirst({ reopenMemory: memory });

        // When that control is armed and then destroyed and rebuilt, as
        // `updatedAddressForm` does
        first.armReopen(Date.now() + 1000);
        first.destroy();
        const rebuilt = makeFirst({ reopenMemory: memory });

        // Then the replacement still owes the buyer the reopen
        expect(rebuilt.reopenDeadline()).toBeGreaterThan(0);
    });

    test('manual-entry mode on one control does not put the other into it', () => {
        // Given two controls built the un-injected way
        const first = makeFirst();
        const second = makeSecond();

        // When the first enters manual entry
        first._manualEntry = true;

        // Then the second is unaffected
        expect(first._manualEntry).toBe(true);
        expect(second._manualEntry).toBe(false);
    });

    test('manual-entry mode survives the control OWN rebuild, via the injected memory', () => {
        // Given a page-lifetime memory for one mount, matching reopenMemory
        const memory = {};
        const first = makeFirst({ manualEntryMemory: memory });

        // When the buyer switches to manual entry and the control is then
        // destroyed and rebuilt, as `updatedAddressForm` does
        first._manualEntry = true;
        first.destroy();
        const rebuilt = makeFirst({ manualEntryMemory: memory });

        // Then the replacement still knows it is in manual-entry mode
        expect(rebuilt._manualEntry).toBe(true);
    });

    test('aborting one control in-flight search leaves the other request alive', () => {
        // Given two controls each holding a request
        const first = makeFirst();
        const second = makeSecond();
        const firstXhr = { abort: jest.fn() };
        const secondXhr = { abort: jest.fn() };
        first._companySearchXhr = firstXhr;
        second._companySearchXhr = secondXhr;

        // When one abandons its search, as a country change does
        first._abortPendingCompanySearch();

        // Then only its own request was aborted, and only its own handle
        // released - a shared one would have taken both
        expect(firstXhr.abort).toHaveBeenCalled();
        expect(secondXhr.abort).not.toHaveBeenCalled();
        expect(first._companySearchXhr).toBeNull();
        expect(second._companySearchXhr).toBe(secondXhr);
    });

    test('each paints its org-number hint under its OWN field', () => {
        // Given two controls, one of which has a company selected
        const first = makeFirst();
        const second = makeSecond();

        first.setCompanyIdHint('918273645');

        // Then the number sits in the first block and the second block's hint
        // is blank - document-wide, it painted under whichever field came first
        const hintIn = (id) => document.getElementById(id)
            .querySelector('.two-company-id-hint').textContent;
        expect(hintIn('delivery-address')).toBe('918273645');
        expect(hintIn('invoice-address')).toBe('');
        expect(second.companyIdHintField.get(0))
            .not.toBe(first.companyIdHintField.get(0));
    });

    test('each gets its own event namespace, so one teardown cannot unbind the other', () => {
        // Given two controls
        const first = makeFirst();
        const second = makeSecond();

        // Then their document-level namespaces differ
        expect(first._instanceNs).not.toBe(second._instanceNs);
    });

    test('each publishes its width on its OWN panel, never on the page root', () => {
        // Given two open controls measuring different widths
        const first = makeFirst();
        const second = makeSecond();
        openPanelFor(first);
        openPanelFor(second);
        jest.spyOn($.fn, 'outerWidth').mockReturnValue(281);
        first.constrainAutocompleteMenuWidth();
        jest.spyOn($.fn, 'outerWidth').mockReturnValue(410);
        second.constrainAutocompleteMenuWidth();

        // Then each panel carries its own measurement and the page root none
        expect(first._dropdown.get(0).style.getPropertyValue('--two-company-search-width'))
            .toBe('281px');
        expect(second._dropdown.get(0).style.getPropertyValue('--two-company-search-width'))
            .toBe('410px');
        expect(document.documentElement.style.getPropertyValue('--two-company-search-width'))
            .toBe('');
    });

    test('each owns its own panel and query field', () => {
        // Given two open controls
        const first = makeFirst();
        const second = makeSecond();
        openPanelFor(first);
        openPanelFor(second);

        // Then neither shares the other's nodes
        expect(first._dropdown.get(0)).not.toBe(second._dropdown.get(0));
        expect(first._queryField.get(0)).not.toBe(second._queryField.get(0));
        expect($.contains(document.getElementById('delivery-address'), first._dropdown.get(0)))
            .toBe(true);
        expect($.contains(document.getElementById('invoice-address'), second._dropdown.get(0)))
            .toBe(true);
    });

    test('one control entering manual entry leaves the other in search', () => {
        // Given two open controls
        const first = makeFirst();
        const second = makeSecond();
        openPanelFor(first);
        openPanelFor(second);

        // When only the second switches to manual entry
        second.enterManualEntryMode();

        // Then the modes stay apart
        expect(second.isManualEntry()).toBe(true);
        expect(first.isManualEntry()).toBe(false);
    });
});

describe('two live controls do not share their inputs', () => {
    test('each snapshots the page config at construction, so a later write reaches neither', () => {
        // Given a control built while one translation is published
        window.twopayment = { i18n: { company_search_no_matches: 'Ingen treff' } };
        const first = makeFirst();

        // When the page config is replaced and a second control is built
        window.twopayment = { i18n: { company_search_no_matches: 'Sin resultados' } };
        const second = makeSecond();

        // Then each keeps the config it was constructed with, and a third write
        // reaches neither
        window.twopayment = { i18n: { company_search_no_matches: 'Nessun risultato' } };
        expect(first.getNoMatchesText()).toBe('Ingen treff');
        expect(second.getNoMatchesText()).toBe('Sin resultados');
    });

    test('a page carrying no config at all still answers with the English source string', () => {
        // Given no published config, and two controls built from it
        const first = makeFirst();
        const second = makeSecond();

        expect(first.getNoMatchesText()).toBe('No matches found');
        expect(second.getNoMatchesText()).toBe('No matches found');
    });

    test('each resolves the sibling modules it was GIVEN, not the page singletons', () => {
        // Given two controls handed different collaborators
        const soleTraderA = { startEnrollment: jest.fn() };
        const soleTraderB = { startEnrollment: jest.fn() };
        const managerA = { markTileCompanySelected: jest.fn() };
        const managerB = { markTileCompanySelected: jest.fn() };
        window.TwoSoleTrader_Instance = { startEnrollment: jest.fn() };
        window.TwoCheckoutManager_Instance = { markTileCompanySelected: jest.fn() };
        const first = makeFirst({ getSoleTrader: () => soleTraderA, getManager: () => managerA });
        const second = makeSecond({ getSoleTrader: () => soleTraderB, getManager: () => managerB });

        // Then each answers with its own, and the page singletons are ignored
        expect(first.soleTrader()).toBe(soleTraderA);
        expect(second.soleTrader()).toBe(soleTraderB);
        expect(first.manager()).toBe(managerA);
        expect(second.manager()).toBe(managerB);
    });

    test('an un-injected control still finds the page singletons', () => {
        // Given the shipped page, which publishes both
        window.TwoSoleTrader_Instance = { startEnrollment: jest.fn() };
        window.TwoCheckoutManager_Instance = { markTileCompanySelected: jest.fn() };
        const first = makeFirst();

        // Then the fallback keeps a standalone mount working
        expect(first.soleTrader()).toBe(window.TwoSoleTrader_Instance);
        expect(first.manager()).toBe(window.TwoCheckoutManager_Instance);
    });
});

describe('the tile-selected gate is set through the manager own method', () => {
    test('marking a tile selection is what opens the auto-trigger gate', () => {
        // Given the real manager in tile mode, with no selection made and a
        // search still in its searching (not manual-entry) state
        loadScript('views/js/modules/TwoCheckoutManager.js');
        const manager = Object.create(window.TwoCheckoutManager.prototype);
        manager.config = { companySearchInAddressArea: false };
        manager._tileCompanySelected = false;
        manager.companySearch = { isManualEntry: () => false };
        expect(manager.canAutoTriggerOrderIntent()).toBe(false);

        // When the company search reports a tile selection
        manager.markTileCompanySelected();

        // Then a generic mounted/re-rendered signal may fire the intent check
        expect(manager.canAutoTriggerOrderIntent()).toBe(true);
    });

    test('a real selection reports to the injected manager, and not to the page singleton', () => {
        // Given two controls with different injected managers, and a page
        // singleton that must stay untouched
        const managerA = { markTileCompanySelected: jest.fn() };
        const managerB = { markTileCompanySelected: jest.fn() };
        window.TwoCheckoutManager_Instance = { markTileCompanySelected: jest.fn() };
        const first = makeFirst({ getManager: () => managerA });
        makeSecond({ getManager: () => managerB });

        // When the buyer picks a company in the first
        first.onCompanySelected({}, { item: { value: 'Example Ltd', label: 'Example Ltd' } });

        // Then only that control's own manager was told
        expect(managerA.markTileCompanySelected).toHaveBeenCalledTimes(1);
        expect(managerB.markTileCompanySelected).not.toHaveBeenCalled();
        expect(window.TwoCheckoutManager_Instance.markTileCompanySelected).not.toHaveBeenCalled();
    });
});

describe('two live controls do not share their DOM', () => {
    test('each creates its OWN companyid input rather than adopting the first one', () => {
        // Given two controls on a page whose theme ships no `companyid` field
        const first = makeFirst();
        const second = makeSecond();

        // Then each holds a distinct node, inside its own address block
        expect(first.organizationField.get(0)).not.toBe(second.organizationField.get(0));
        expect($.contains(document.getElementById('delivery-address'),
            first.organizationField.get(0))).toBe(true);
        expect($.contains(document.getElementById('invoice-address'),
            second.organizationField.get(0))).toBe(true);
    });

    test('a companyid input already in a block is adopted only by that block control', () => {
        // Given a theme that ships the field in the first block only
        $('#delivery-address form').append(
            '<input type="hidden" name="companyid" id="shipped-companyid" value="" />'
        );
        const first = makeFirst();
        const second = makeSecond();

        // Then the second builds its own rather than writing the first's
        expect(first.organizationField.get(0)).toBe(document.getElementById('shipped-companyid'));
        expect(second.organizationField.get(0)).not.toBe(document.getElementById('shipped-companyid'));
    });

    test('each answers with its OWN address id', () => {
        // Given two blocks carrying different `data-id-address` values
        const first = makeFirst();
        const second = makeSecond();

        // Then neither reports the other's
        expect(first.getCurrentAddressId()).toBe(7);
        expect(second.getCurrentAddressId()).toBe(9);
    });
});

/**
 * The same properties against the markup PrestaShop actually renders, via the
 * shared `buildAddressesStep()` fixture rather than a local approximation.
 *
 * Core renders exactly ONE editable address form per step - every branch of
 * `CheckoutAddressesStep` sets `show_delivery_address_form` or
 * `show_invoice_address_form`, never both - so there is no two-control shape to
 * assert here, and the other side of the step is a saved-address selector. That
 * is why the two-control leak proofs above run on their own fixture.
 *
 * One parse-level fact drives every expectation below, and it is not visible in a
 * hand-built approximation: a `<form>` inside a `<form>` is invalid, so the
 * address form's own `<form>` tag is dropped and the block's `.js-address-form`
 * div - not a form - is the innermost usable scope.
 *
 * @param {string} editing 'delivery' or 'invoice' - the side core made editable
 * @param {Function} [beforeMount] runs against the built fixture
 * @returns {Object} a control mounted on that side's company field
 */
function mountOnCoreStep(editing, beforeMount) {
    buildAddressesStep({
        editing: editing,
        countryIsoAttrs: true,
        // Distinct so an assertion can say WHICH form answered.
        stepAddressId: STEP_ADDRESS_ID,
        addressId: BLOCK_ADDRESS_ID
    });
    if (beforeMount) {
        beforeMount();
    }

    return new TwoCompanySearch({
        checkoutHost: CHECKOUT_HOST,
        companyFieldSelector: '#field-company'
    });
}

describe('addressScope() on PrestaShop core markup', () => {
    test.each([
        ['delivery', 'id_address_invoice'],
        ['invoice', 'id_address_delivery']
    ])('editing the %s address scopes to that block own .js-address-form', (editing, otherRadio) => {
        // Given core's own addresses step with that side editable
        const control = mountOnCoreStep(editing);
        const block = document.getElementById(editing + '-address');

        // Then the scope is the wrapper INSIDE the block id, not the block div
        // and not the step-wide wrapper that spans both sides
        expect(control.addressScope()).toBe(block.querySelector('.js-address-form'));
        expect(control.addressScope()).not.toBe(block);
        expect(control.addressScope()).not.toBe(document);

        // And it reaches nothing belonging to the other address
        expect(control.addressScope().querySelector("input[name='" + otherRadio + "']")).toBeNull();
    });

    test('the companyid input is created inside the scope, never adopted from the other block', () => {
        // Given the other side of the step already carrying a hidden companyid
        const control = mountOnCoreStep('invoice', () => {
            $('#delivery-addresses').append(
                '<input type="hidden" name="companyid" value="" id="foreign-companyid" />'
            );
        });

        // Then this control built its own inside its own scope and left that
        // one alone - a document-wide lookup would have adopted it, and the
        // two blocks would then submit one node between them
        expect(control.organizationField.get(0)).not.toBe(document.getElementById('foreign-companyid'));
        expect($.contains(control.addressScope(), control.organizationField.get(0))).toBe(true);
        expect(document.querySelectorAll("input[name='companyid']").length).toBe(2);
    });

    test('fails closed to no scope when the only candidate spans both blocks', () => {
        // Given a theme that flattens core's per-block wrappers and ids, so the
        // nearest candidate is the step form, which holds the other side too
        buildAddressesStep({
            editing: 'invoice',
            blockContainers: false,
            blockIds: false,
            stepAddressId: STEP_ADDRESS_ID
        });
        const control = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companyFieldSelector: '#field-company'
        });

        // Then NO scope is claimed. `document` here would be the widest possible
        // scope - the cross-address write this scoping exists to prevent.
        expect(control.addressScope()).toBeNull();
    });

    /**
     * @param {Function} [beforeMount] runs against the built fixture
     * @returns {Object} a control whose addressScope() has failed closed
     */
    function mountWithNoScope(beforeMount) {
        buildAddressesStep({
            editing: 'invoice',
            blockContainers: false,
            blockIds: false,
            countryIsoAttrs: true,
            stepAddressId: STEP_ADDRESS_ID
        });
        if (beforeMount) {
            beforeMount();
        }

        return new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companyFieldSelector: '#field-company'
        });
    }

    test('scopedQuery() answers null on a failed-closed scope, never document-wide', () => {
        // Given no trusted scope, but a matching field elsewhere on the page
        const control = mountWithNoScope();
        expect(control.addressScope()).toBeNull();
        expect(document.querySelector("select[name='id_country']")).not.toBeNull();

        // Then the lookup finds nothing rather than widening to the document
        expect(control.scopedQuery("select[name='id_country']")).toBeNull();
    });

    test('a failed-closed scope reads no country at all, page value included', () => {
        // Given a country select the control must not trust, and a page value
        window.twopayment = { company_search_country: 'NO' };
        const control = mountWithNoScope();

        // Then neither is taken: the page value is the CART's billing country,
        // and this control cannot say which address block it is in
        expect(document.querySelector("select[name='id_country']").selectedOptions[0]
            .getAttribute('data-iso-code')).toBe('DE');
        expect(control.getCurrentCountry()).toBe('');
    });

    test('a failed-closed scope withdraws the search and leaves manual entry', () => {
        window.twopayment = { company_search_country: 'NO' };
        const control = mountWithNoScope();

        expect(control.searchUnavailable()).toBe(true);
        expect(control.isManualEntry()).toBe(true);

        // No panel was built, so there is nothing to open and no chip row
        expect(document.querySelector('.two-company-dropdown')).toBeNull();
        control.openDropdown();
        expect(document.querySelector('.two-company-dropdown')).toBeNull();

        // The company field is the plain editable input the buyer types into
        const field = document.getElementById('field-company');
        expect(field.hasAttribute('readonly')).toBe(false);

        // And no route back into search, from any path
        control.renderBackToSearchLink();
        control.exitManualEntryMode();
        expect(document.querySelector('.two-company-search-back')).toBeNull();
        expect(control.isManualEntry()).toBe(true);
    });

    test('a withheld search offers no sole-trader route back either', () => {
        // Given a control with no trusted scope, and a completed sole-trader
        // enrolment - the one path that renders a route back without going
        // through the panel
        const control = mountWithNoScope();
        control.adoptSoleTraderBuyer({
            organization_number: '918273645',
            company_name: 'Ola Nordmann'
        });

        // Then the button that relaunches the sole-trader flow is not offered
        expect(document.querySelector('.two-company-select-different-sole-trader')).toBeNull();
    });

    test('a scope lost under a live control sweeps the sole-trader route away', () => {
        // Given a scoped control showing the link after an enrolment
        const control = mountOnCoreStep('invoice');
        control.adoptSoleTraderBuyer({
            organization_number: '918273645',
            company_name: 'Ola Nordmann'
        });
        expect(document.querySelector('.two-company-select-different-sole-trader')).not.toBeNull();
        control.setCompanyIdHint('918273645');

        // When a re-render leaves the other side's selector inside this
        // control's only candidate, so the candidate spans both addresses
        control.addressScope().appendChild(document.getElementById('delivery-addresses'));
        expect(control.searchUnavailable()).toBe(true);
        control.setupAutocomplete();

        // Then the withdrawal takes the links with it, and the org number the
        // control can no longer stand behind
        expect(document.querySelector('.two-company-select-different-sole-trader')).toBeNull();
        expect(document.querySelector('.two-company-search-back')).toBeNull();
        expect(document.querySelector('.two-company-id-hint').textContent).toBe('');
        expect(control.companyField.hasClass('two-company-search-input')).toBe(false);
    });

    test('the tile mount is in no address block, so its scope is the document', () => {
        // Given the payment-tile mount as core's own payment step renders it
        document.body.innerHTML = "<input type='text' id='two_tile_company' />";
        const control = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: false,
            companyFieldSelector: '#two_tile_company'
        });

        expect(control.addressScope()).toBe(document);
        expect(control.searchUnavailable()).toBe(false);
    });

    /**
     * The tile mount's country is server-resolved (`twopayment.company_search_country`),
     * so an ambiguous scope costs it nothing: there is no block whose country
     * select it was going to read. A block mount in the same DOM position
     * withdraws, and it is `companySearchInAddressArea` that tells the two
     * apart - the scope is identical.
     */
    test('an ambiguous scope withdraws a block mount and leaves the tile mount searching', () => {
        // Given a one-page theme that keeps the payment step inside the address
        // step's own form, so the tile's nearest candidate spans the address
        // SELECTOR the payment step renders
        document.body.innerHTML = [
            '<div class="js-address-form">',
            '  <div id="delivery-addresses" class="js-address-selector">',
            '    <article class="js-address-item"><input type="radio" name="id_address_delivery" value="7" checked></article>',
            '  </div>',
            '  <div class="two-payment-option">',
            '    <input type="text" id="two_tile_company" value="" />',
            '    <input type="text" name="company" id="field-company" value="" />',
            '  </div>',
            '</div>'
        ].join('\n');

        const tile = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companySearchInAddressArea: false,
            companyFieldSelector: '#two_tile_company'
        });
        const block = new TwoCompanySearch({
            checkoutHost: CHECKOUT_HOST,
            companyFieldSelector: '#field-company'
        });

        // Then both fail closed on scope, and only the block mount is withdrawn
        expect(tile.addressScope()).toBeNull();
        expect(block.addressScope()).toBeNull();
        expect(tile.searchUnavailable()).toBe(false);
        expect(block.searchUnavailable()).toBe(true);

        // And the tile still has the panel the block mount was denied
        expect(tile.isManualEntry()).toBe(false);
        expect($.contains(document.getElementById('two_tile_company').parentNode,
            document.querySelector('.two-company-dropdown'))).toBe(true);
        expect(block.isManualEntry()).toBe(true);
    });

    test('a failed-closed scope adopts the shipped companyid, never a second one', () => {
        // Given a theme that ships the hidden input, and no trusted scope
        const control = mountWithNoScope(() => {
            $('#field-company').after('<input type="hidden" name="companyid" value="" id="shipped" />');
        });
        expect(control.addressScope()).toBeNull();

        // Then it writes that one input, not a duplicate the buyer submits twice
        expect(document.querySelectorAll("input[name='companyid']").length).toBe(1);
        expect(control.organizationField.get(0)).toBe(document.getElementById('shipped'));
    });

    test('a detached scope is no scope, as core replaceWith() leaves it', () => {
        const control = mountOnCoreStep('invoice');
        const scope = control.addressScope();

        // When core's own country-change handler swaps that root out
        scope.remove();

        expect(control.addressScope()).toBeNull();
    });
});
