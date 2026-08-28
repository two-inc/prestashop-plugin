/**
 * `TwoCompanySearch` must be genuinely multi-instance: two live controls on one
 * page each hold their own state, own their own DOM nodes, read their own
 * collaborators, and neither one's mutation reaches the other.
 *
 * The class was already instance-SHAPED - a real constructor with its own
 * fields - but a handful of its state and half its inputs were page-global: two
 * undeclared class statics, ~30 live `window.twopayment` reads, the sibling
 * modules reached for on `window`, a CSS custom property on
 * `document.documentElement`, and the country select, the hidden `companyid`
 * input and the current address id all resolved document-wide. A second control
 * inherited all of it. Eighteen of the twenty-three tests below fail against
 * that shape, so they are what stops it coming back. The remaining five pass
 * either way: they pin state that was already per-instance (the request
 * counter, the event namespace, the panel and query field, the manual-entry
 * flag, the no-config fallback) and are regression pins on it, not leak proofs.
 *
 * The shipped module mounts ONE control per page - the address-area field or the
 * payment tile, in mutually exclusive branches of
 * TwoCheckoutManager.initializeCompanySearch(). So the fixture here is not a
 * shape a shop serves today; it is the shape the class must already survive
 * before a second mount can be added.
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
    stubAjax,
    releaseWidgets,
    loadScript
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

let TwoCompanySearch;
let $;
let ajax;

/**
 * Two address blocks, each a complete editable form with its own company input
 * and its own country select.
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

beforeEach(() => {
    buildTwoAddressBlocks();
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    ajax = stubAjax($);
});

afterEach(() => {
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

    test('the cache key carries each control OWN country, so results cannot cross', () => {
        // Given two controls in different countries
        const first = makeFirst();
        const second = makeSecond();

        // Then the same term files under two different keys
        expect(first.buildCacheKey('exa')).toBe('exa|GB');
        expect(second.buildCacheKey('exa')).toBe('exa|NO');
    });

    test('a shared cache entry is reachable only through a matching key', () => {
        // Given a control that has filled the cache for its own country
        const first = makeFirst();
        const second = makeSecond();
        TwoCompanySearch.cacheSet(first.buildCacheKey('exa'), [{ value: 'Example Ltd' }]);

        // Then the class-wide cache answers the first and not the second - the
        // sharing is by key, which is what makes it safe
        expect(TwoCompanySearch.cacheGet(first.buildCacheKey('exa'))).toEqual([
            { value: 'Example Ltd' }
        ]);
        expect(TwoCompanySearch.cacheGet(second.buildCacheKey('exa'))).toBeNull();
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
        // Given two controls, each with its own reopen memory
        const first = makeFirst({ reopenMemory: {} });
        const second = makeSecond({ reopenMemory: {} });

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

    test('each holds its own request state', () => {
        // Given two controls
        const first = makeFirst();
        const second = makeSecond();

        // When one is put mid-flight
        first._companySearchSeq = 7;
        first._companySearchXhr = { abort: jest.fn() };

        // Then the other is untouched
        expect(second._companySearchSeq).toBe(0);
        expect(second._companySearchXhr).toBeNull();
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

    test('a page carrying no config at all falls back per control, not to a shared blank', () => {
        // Given no published config
        const first = makeFirst();

        // Then the English source string answers
        expect(first.getNoMatchesText()).toBe('No matches found');
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
    test('TwoCheckoutManager exposes markTileCompanySelected(), so no collaborator writes the field', () => {
        // Given the real manager class
        loadScript('views/js/modules/TwoCheckoutManager.js');
        const TwoCheckoutManager = window.TwoCheckoutManager;
        const manager = Object.create(TwoCheckoutManager.prototype);
        manager._tileCompanySelected = false;

        // When the company search reports a tile selection
        manager.markTileCompanySelected();

        // Then the gate is open
        expect(manager._tileCompanySelected).toBe(true);
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
 * PrestaShop's real addresses step, which the fixture above deliberately is
 * not: core wraps BOTH address blocks in one step-wide `.js-address-form`, and
 * the address form's own inner `<form>` tag is dropped by the parser (a `<form>`
 * inside a `<form>` is invalid), so there is no per-block form element to scope
 * to at all. The fixture above nests `.js-address-form` inside each block - the
 * opposite arrangement - so it cannot see this class of bug.
 */
function buildCoreAddressesStep(options) {
    const settings = options || {};
    const block = function (id, company, countryId, iso) {
        return [
            '<div id="' + id + '">',
            '  <input type="text" name="company" id="' + company + '" value="" />',
            "  <input type='text' name='address1' value='' />",
            '  <select name="id_country">',
            '    <option value="' + countryId + '" data-iso-code="' + iso + '" selected>' + iso + '</option>',
            '  </select>',
            '</div>'
        ].join('\n');
    };
    document.body.innerHTML = [
        '<div class="js-address-form">',
        settings.dropBlockIds ? '<div>' : '',
        block(settings.dropBlockIds ? 'first-block' : 'delivery-address', 'company-a', '17', 'GB'),
        block(settings.dropBlockIds ? 'second-block' : 'invoice-address', 'company-b', '10', 'NO'),
        settings.dropBlockIds
            ? "<input type='radio' name='id_address_invoice' value='9' checked>"
            : '',
        settings.dropBlockIds ? '</div>' : '',
        '  <input type="hidden" name="saveAddress" value="delivery">',
        '</div>'
    ].join('\n');
}

describe('addressScope() on PrestaShop core markup', () => {
    test('resolves to the control own address block, not the step-wide wrapper', () => {
        // Given core's markup: one `.js-address-form` around both blocks
        buildCoreAddressesStep();
        const first = makeFirst();
        const second = makeSecond();

        // Then neither scope is the wrapper that spans both
        expect(first.addressScope()).toBe(document.getElementById('delivery-address'));
        expect(second.addressScope()).toBe(document.getElementById('invoice-address'));
    });

    test('so the two controls still own separate companyid inputs', () => {
        // Given core's markup
        buildCoreAddressesStep();
        const first = makeFirst();
        const second = makeSecond();

        // Then the second has not adopted the first's hidden input
        expect(first.organizationField.get(0)).not.toBe(second.organizationField.get(0));
        expect($.contains(document.getElementById('invoice-address'),
            second.organizationField.get(0))).toBe(true);
    });

    test('fails closed to the document when the only candidate spans both blocks', () => {
        // Given a theme that keeps core's structure but drops its block ids, so
        // the nearest candidate contains the other address's radio
        buildCoreAddressesStep({ dropBlockIds: true });
        const first = makeFirst();

        // Then no scope is claimed, rather than one spanning both addresses
        expect(first.addressScope()).toBe(document);
    });
});
