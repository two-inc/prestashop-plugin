/**
 * TWO-25239. Regression tests for what happens to the company-search widget when
 * the address form is re-rendered.
 *
 * PrestaShop fires `updatedAddressForm` for interactions as ordinary as a
 * country change, and TwoCheckoutManager.handleAddressFormUpdate() responds by
 * destroying the TwoCompanySearch instance and building a fresh one. Two of the
 * three defects found while reviewing the rewrite live on that path:
 *
 *   - `_renderItem` was re-wrapped on every setup. jQuery UI's widget bridge
 *     does not build a fresh instance when `.autocomplete({...})` is called on
 *     an already-initialised field — it runs option() + _init() on the existing
 *     one — so each call captured the previous wrapper and wrapped it again,
 *     nesting a layer deeper per event until rendering a row blew the stack.
 *   - a destroyed instance kept acting. `prestashop.on` has no `off`, so the
 *     handler a destroyed instance registered still fires; once setupAutocomplete()
 *     began re-resolving the field against the live DOM, the zombie resolved to
 *     the SAME live input as the live instance while its own `companyid` field
 *     was the detached one its init() had created — so a selected company's
 *     organisation number was written somewhere that no longer submits.
 *
 * The third (stuck spinner) is covered here at widget level too, because the
 * spinner is jQuery UI's `pending` counter and only the real widget has one.
 */

'use strict';

const {
    loadCompanySearch,
    buildAddressForm,
    replaceAddressForm,
    stubAjax,
    callbackRecorder,
    releaseWidgets,
    flushPromises
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';
const LOADING_CLASS = 'ui-autocomplete-loading';

const SEARCH_RESPONSE = {
    items: [{ name: 'Example Trading Ltd', national_identifier: { id: '12345678' } }]
};

let TwoCompanySearch;
let $;
let bus;
let ajax;

beforeEach(() => {
    buildAddressForm({ country: 'GB' });
    const loaded = loadCompanySearch();
    TwoCompanySearch = loaded.TwoCompanySearch;
    $ = loaded.$;
    bus = loaded.bus;
    ajax = stubAjax($);
});

afterEach(() => {
    releaseWidgets($);
    ajax.restore();
    document.body.innerHTML = '';
});

function makeInstance(extraConfig) {
    return new TwoCompanySearch(
        Object.assign({ checkoutHost: CHECKOUT_HOST }, extraConfig || {})
    );
}

/** The live company input as a jQuery object. */
function liveField() {
    return $("input[name='company']");
}

describe('the real jQuery UI widget is what gets bound', () => {
    test('setup binds the widget and marks the field for the spinner CSS', () => {
        makeInstance();

        expect(liveField().hasClass('two-company-search-input')).toBe(true);
        expect(liveField().hasClass('ui-autocomplete-input')).toBe(true);
        expect(liveField().autocomplete('instance')).toBeTruthy();
        expect(liveField().autocomplete('option', 'minLength')).toBe(3);
        expect(liveField().autocomplete('option', 'delay')).toBe(300);
    });
});

describe('the spinner always comes back down', () => {
    /**
     * Drive a search through the widget rather than calling searchCompanies()
     * directly, so jQuery UI's own `pending` counter and loading class are the
     * things under test.
     */
    function search(term) {
        const field = liveField();
        field.val(term);
        field.autocomplete('instance').search(term);
        return field;
    }

    test('a successful search clears it', () => {
        makeInstance();
        const field = search('exa');
        expect(field.hasClass(LOADING_CLASS)).toBe(true);

        ajax.last().succeed(SEARCH_RESPONSE);

        expect(field.hasClass(LOADING_CLASS)).toBe(false);
    });

    test('a timeout clears it and shows the unavailable row', () => {
        makeInstance();
        const field = search('exa');

        ajax.last().fail('timeout');

        // The defect: a failure that never calls response() leaves `pending`
        // stuck above zero, so the spinner runs for the rest of the session.
        expect(field.hasClass(LOADING_CLASS)).toBe(false);
        const menu = $('ul.ui-autocomplete li');
        expect(menu).toHaveLength(1);
        expect(menu.hasClass('two-autocomplete-unavailable')).toBe(true);
    });

    test('an empty-but-healthy result clears it without an error row', () => {
        makeInstance();
        const field = search('exa');

        ajax.last().succeed({ items: [] });

        expect(field.hasClass(LOADING_CLASS)).toBe(false);
        expect($('ul.ui-autocomplete li.two-autocomplete-unavailable')).toHaveLength(0);
    });

    test('a degraded-and-empty response clears it and shows the unavailable row', () => {
        makeInstance();
        const field = search('exa');

        ajax.last().succeed({ items: [], degraded: true });

        expect(field.hasClass(LOADING_CLASS)).toBe(false);
        expect($('ul.ui-autocomplete li.two-autocomplete-unavailable')).toHaveLength(1);
    });

    test('a superseded search keeps the spinner up for the one that replaced it', () => {
        makeInstance();
        const field = search('exa');
        search('exam');

        // The first request is aborted by the second and settles as `silent`.
        // That must decrement `pending` (so the count stays balanced) without
        // dropping the class, which still belongs to the live request.
        expect(ajax.calls[0].aborted).toBe(true);
        expect(field.hasClass(LOADING_CLASS)).toBe(true);

        ajax.calls[1].succeed(SEARCH_RESPONSE);
        expect(field.hasClass(LOADING_CLASS)).toBe(false);
    });

    test('a superseded response does not repopulate the dropdown', () => {
        makeInstance();
        search('exa');
        ajax.calls[0].xhr.abort = function () {};
        search('exam');

        ajax.calls[1].succeed({ items: [] });
        ajax.calls[0].succeed(SEARCH_RESPONSE);

        // jQuery UI drops a superseded requestIndex itself — but only because
        // the callback is delivered rather than swallowed.
        expect($('ul.ui-autocomplete li')).toHaveLength(0);
    });

    test('the source callback fires exactly once per meta shape', () => {
        makeInstance();
        const source = liveField().autocomplete('option', 'source');

        [
            function () { ajax.last().succeed(SEARCH_RESPONSE); },
            function () { ajax.last().succeed({ items: [], degraded: true }); },
            function () { ajax.last().succeed(Object.assign({ degraded: true }, SEARCH_RESPONSE)); },
            function () { ajax.last().fail('timeout'); },
            function () { ajax.last().fail('abort'); }
        ].forEach(function (settle) {
            TwoCompanySearch._resultCache.clear();
            const rec = callbackRecorder();
            source({ term: 'exa' }, function (results) {
                rec.fn(results);
            });
            settle();
            expect(rec.calls).toHaveLength(1);
        });
    });

    test('a cache hit answers without a request, still exactly once', () => {
        makeInstance();
        const source = liveField().autocomplete('option', 'source');
        const first = callbackRecorder();

        source({ term: 'exa' }, first.fn);
        ajax.last().succeed(SEARCH_RESPONSE);
        expect(first.calls).toHaveLength(1);

        const second = callbackRecorder();
        source({ term: 'exa' }, second.fn);

        expect(ajax.calls).toHaveLength(1);
        expect(second.calls).toHaveLength(1);
        expect(second.calls[0].results).toEqual(first.calls[0].results);
    });

    test('a degraded result set is not cached', () => {
        makeInstance();
        const source = liveField().autocomplete('option', 'source');

        source({ term: 'exa' }, callbackRecorder().fn);
        ajax.last().succeed(Object.assign({ degraded: true }, SEARCH_RESPONSE));

        // Pinning a known-partial list for five minutes would keep serving it
        // after the upstream provider recovered.
        source({ term: 'exa' }, callbackRecorder().fn);
        expect(ajax.calls).toHaveLength(2);
    });

    test('an unavailable answer is not cached either', () => {
        makeInstance();
        const source = liveField().autocomplete('option', 'source');

        source({ term: 'exa' }, callbackRecorder().fn);
        ajax.last().fail('timeout');

        // The service may well be healthy again by the next keystroke.
        source({ term: 'exa' }, callbackRecorder().fn);
        expect(ajax.calls).toHaveLength(2);
    });
});

describe('selecting a company through the real widget', () => {
    /** Search, settle, then pick the first row the way a buyer's click does. */
    function selectFirstResult(term, response) {
        const field = liveField();
        const instance = field.autocomplete('instance');
        field.val(term);
        instance.search(term);
        ajax.last().succeed(response);
        const row = instance.menu.element.children('li').first();
        instance.menu.focus(null, row);
        instance.menu.select($.Event('click'));
        return field;
    }

    test('the selection reaches the live fields', () => {
        makeInstance();

        const field = selectFirstResult('exa', SEARCH_RESPONSE);

        // The happy path, driven end to end through jQuery UI rather than by
        // calling onCompanySelected() directly — otherwise the `select` option
        // could be unwired entirely and every direct-call test would still pass.
        expect(field.val()).toBe('Example Trading Ltd');
        expect($("input[name='companyid']").val()).toBe('12345678');
        expect($("input[name='companyid']").attr('data-two-company-name')).toBe(
            'Example Trading Ltd'
        );
        expect($("input[name='dni']").val()).toBe('12345678');
        expect($("input[name='vat_number']").val()).toBe('12345678');
    });

    test('selecting the unavailable row writes nothing into the field', () => {
        makeInstance();

        const field = selectFirstResult('exa', { items: [], degraded: true });

        // `_normalize()` rewrites the row's value as `value || label`, so without
        // the two_unavailable checks the message text itself lands in the field.
        expect(field.val()).toBe('exa');
        expect($("input[name='companyid']").val()).toBe('');
    });

    test('a company with only a lookup id fetches its details', async () => {
        makeInstance();

        selectFirstResult('exa', {
            items: [{ name: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }]
        });

        // Some registries (GB among them) return the organisation number only on
        // the detail endpoint, so the number has to arrive by that second call.
        expect(ajax.calls).toHaveLength(2);
        expect(ajax.last().url).toContain('/companies/v2/company/lookup-abc-123');
        ajax.last().succeed({
            national_identifier: { id: '87654321' },
            addresses: [
                {
                    type: 'BUSINESS',
                    street_address: '1 Example Street',
                    postal_code: 'EX1 1EX',
                    city: 'Exampleton'
                }
            ]
        });

        // fetchCompanyDetails() wraps the call in a Promise, so the fill lands
        // microtasks after the response rather than synchronously with it.
        await flushPromises();

        expect($("input[name='companyid']").val()).toBe('87654321');
        expect($("input[name='address1']").val()).toBe('1 Example Street');
        expect($("input[name='postcode']").val()).toBe('EX1 1EX');
        expect($("input[name='city']").val()).toBe('Exampleton');
    });

    test('the address-lookup toggle stops the detail fill but not companyid', async () => {
        makeInstance({ addressLookupEnabled: false });

        selectFirstResult('exa', {
            items: [{ name: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }]
        });
        ajax.last().succeed({
            national_identifier: { id: '87654321' },
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street' }]
        });

        await flushPromises();

        expect($("input[name='companyid']").val()).toBe('87654321');
        expect($("input[name='address1']").val()).toBe('');
        expect($("input[name='dni']").val()).toBe('');
    });
});

describe('the _renderItem patch does not nest', () => {
    test('it is applied once and survives repeated setup unchanged', () => {
        const search = makeInstance();
        const instance = liveField().autocomplete('instance');
        const patched = instance._renderItem;

        expect(instance._twoRenderItemPatched).toBe(true);

        for (let i = 0; i < 100; i += 1) {
            search.setupAutocomplete();
        }

        // Identity is the assertion. Each re-wrap would produce a NEW function
        // closing over the previous one; that is the nesting that eventually
        // blew the stack, and nothing else about the widget would look wrong.
        expect(liveField().autocomplete('instance')).toBe(instance);
        expect(instance._renderItem).toBe(patched);
    });

    test('the country-change listener re-setup does not nest it either', () => {
        makeInstance();
        const instance = liveField().autocomplete('instance');
        const patched = instance._renderItem;
        const countrySelect = document.querySelector("select[name='id_country']");

        for (let i = 0; i < 20; i += 1) {
            countrySelect.dispatchEvent(new window.Event('change'));
        }

        expect(instance._renderItem).toBe(patched);
    });

    test('the updatedAddressForm handler does not nest it either', () => {
        makeInstance();
        const instance = liveField().autocomplete('instance');
        const patched = instance._renderItem;

        for (let i = 0; i < 20; i += 1) {
            bus.emit('updatedAddressForm');
        }

        expect(instance._renderItem).toBe(patched);
    });

    test('rendering still works after many re-setups', () => {
        const search = makeInstance();
        for (let i = 0; i < 200; i += 1) {
            search.setupAutocomplete();
        }
        const instance = liveField().autocomplete('instance');
        const ul = $('<ul></ul>');

        expect(() => {
            instance._renderItem(ul, { label: 'Example Trading Ltd', value: 'Example Trading Ltd' });
            instance._renderItem(ul, search.buildUnavailableItem());
        }).not.toThrow();
        expect(ul.children('li')).toHaveLength(2);
    });

    test('a genuinely new widget is patched again', () => {
        const first = makeInstance();
        const firstInstance = liveField().autocomplete('instance');
        first.destroy();

        replaceAddressForm();
        makeInstance();
        const secondInstance = liveField().autocomplete('instance');

        // The guard must be per-instance, not a global "already done" latch —
        // a destroyed-and-recreated widget carries no flag and needs the patch.
        expect(secondInstance).not.toBe(firstInstance);
        expect(secondInstance._twoRenderItemPatched).toBe(true);
    });

    test('the unavailable row is rendered non-selectable and as text', () => {
        const search = makeInstance();
        const instance = liveField().autocomplete('instance');
        const ul = $('<ul></ul>');

        instance._renderItem(ul, { label: '<img src=x onerror=alert(1)>', two_unavailable: true });

        const li = ul.children('li');
        expect(li.hasClass('two-autocomplete-unavailable')).toBe(true);
        // `ui-state-disabled` is what jQuery UI's own menu checks, so the row is
        // skipped by keyboard navigation rather than merely refused on select.
        expect(li.hasClass('ui-state-disabled')).toBe(true);
        expect(li.attr('aria-disabled')).toBe('true');
        expect(li.find('img')).toHaveLength(0);
        expect(li.text()).toBe('<img src=x onerror=alert(1)>');

        expect(search.getSearchUnavailableText()).toContain('temporarily unavailable');
    });

    test('a normal row goes through jQuery UI own renderer', () => {
        makeInstance();
        const instance = liveField().autocomplete('instance');
        const ul = $('<ul></ul>');

        instance._renderItem(ul, { label: 'Example Trading Ltd', value: 'Example Trading Ltd' });

        const li = ul.children('li');
        expect(li.hasClass('two-autocomplete-unavailable')).toBe(false);
        expect(li.text()).toBe('Example Trading Ltd');
    });

    test('select and focus refuse the unavailable row', () => {
        makeInstance();
        const field = liveField();
        const item = { item: { two_unavailable: true, value: 'nope', label: 'nope' } };

        // `_normalize()` rewrites the item's value as `value || label`, so by the
        // time it reaches these handlers its value IS the message text. These
        // checks are the only thing keeping it out of the company field.
        expect(field.autocomplete('option', 'select')(null, item)).toBe(false);
        expect(field.autocomplete('option', 'focus')(null, item)).toBe(false);
        expect(field.val()).toBe('');
    });
});

describe('a stale organisation number is cleared across a re-render', () => {
    /**
     * A re-render can leave `companyid` populated while `company` no longer
     * matches it — PrestaShop re-renders the form from server state, and the
     * hidden field is not part of the address it round-trips. Submitting that
     * pairing sends Two an organisation number for a different company.
     */
    function seed(company, companyid, tag) {
        buildAddressForm({ country: 'GB' });
        const form = document.querySelector('form');
        form.querySelector("input[name='company']").value = company;
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'companyid';
        hidden.value = companyid;
        if (tag !== undefined) {
            hidden.setAttribute('data-two-company-name', tag);
        }
        form.appendChild(hidden);
    }

    test('a matching pair is left alone', () => {
        seed('Example Trading Ltd', '12345678', 'Example Trading Ltd');
        makeInstance();

        expect($("input[name='companyid']").val()).toBe('12345678');
    });

    test('a differing name clears the number and its marker', () => {
        seed('Another Company Ltd', '12345678', 'Example Trading Ltd');
        makeInstance();

        expect($("input[name='companyid']").val()).toBe('');
        expect($("input[name='companyid']").attr('data-two-company-name')).toBeUndefined();
    });

    test('the comparison ignores case and whitespace', () => {
        seed('  EXAMPLE   TRADING   LTD ', '12345678', 'Example Trading Ltd');
        makeInstance();

        // Themes and server round-trips both reflow whitespace and casing; a
        // strict comparison would drop a valid selection on every re-render.
        expect($("input[name='companyid']").val()).toBe('12345678');
    });

    test('a number with no selection marker is treated as stale', () => {
        seed('Example Trading Ltd', '12345678');
        makeInstance();

        // No marker means nothing recorded that this number came from a company
        // the buyer picked, so it cannot be trusted to still belong to the name.
        expect($("input[name='companyid']").val()).toBe('');
    });

    test('an empty company name clears the number and its marker', () => {
        seed('', '12345678', 'Example Trading Ltd');
        makeInstance();

        expect($("input[name='companyid']").val()).toBe('');
        expect($("input[name='companyid']").attr('data-two-company-name')).toBeUndefined();
    });

    test('an empty company name also drops an empty selection marker', () => {
        seed('', '12345678', '');
        makeInstance();

        // The distinguishing case for the no-company branch: an empty marker is
        // falsy, so the untagged branch would otherwise catch this input first
        // and leave the marker attribute behind on the field.
        expect($("input[name='companyid']").val()).toBe('');
        expect($("input[name='companyid']").attr('data-two-company-name')).toBeUndefined();
    });

    test('editing the company name by hand clears the number', () => {
        seed('Example Trading Ltd', '12345678', 'Example Trading Ltd');
        makeInstance();
        const input = document.querySelector("input[name='company']");

        input.value = 'Example Trading Limited';
        input.dispatchEvent(new window.Event('input'));

        expect($("input[name='companyid']").val()).toBe('');
    });
});

describe('the organisation number reaches the address identifiers on submit', () => {
    /**
     * `triggerHandler` rather than `trigger`: the module's handler is bound to
     * the form itself so both reach it identically, but `trigger` also runs the
     * native default action, which jsdom answers with a "not implemented"
     * console dump.
     */
    function submitForm() {
        $('form').triggerHandler('submit');
    }

    test('submitting the form syncs companyid into the identifier fields', () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', organization_number: '12345678' }
        });
        $("input[name='dni']").val('');
        $("input[name='vat_number']").val('');

        submitForm();

        // The selection writes these too, but a re-render between selection and
        // submit can blank them; the submit hook is the last chance to restore.
        expect($("input[name='dni']").val()).toBe('12345678');
        expect($("input[name='vat_number']").val()).toBe('12345678');
    });

    test('a value the buyer typed is not overwritten on submit', () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', organization_number: '12345678' }
        });
        $("input[name='dni']").val('buyer-typed');

        submitForm();

        // The submit sync is `onlyIfEmpty`: it fills a gap, it does not correct
        // the customer.
        expect($("input[name='dni']").val()).toBe('buyer-typed');
    });

    test('a DNI the buyer typed becomes the org number when none was selected', () => {
        makeInstance();
        $("input[name='dni']").val('99999999');

        submitForm();

        // The customer's own input flowing INTO the Two flow, which is the
        // opposite direction to the lookup and so is not gated by the toggle.
        expect($("input[name='companyid']").val()).toBe('99999999');
    });
});

describe('the company-detail fill', () => {
    /** Select a lookup-id-only company and settle its detail response. */
    async function fillFrom(details, config) {
        const search = makeInstance(config);
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });
        ajax.last().succeed(details);
        await flushPromises();
        return search;
    }

    test.each([
        [{ national_identifier: { id: '1' } }, '1'],
        [{ nationalIdentifier: { id: '2' } }, '2'],
        [{ company: { national_identifier: { id: '3' } } }, '3'],
        [{ company: { nationalIdentifier: { id: '4' } } }, '4'],
        [{ registration_number: '5' }, '5'],
        [{ company: { company_number: '6' } }, '6']
    ])('reads the number out of %p', async (details, expected) => {
        // Detail payloads arrive in as many shapes as search results do, and the
        // detail number is the authoritative one for the countries that only
        // return it here.
        await fillFrom(details);

        expect($("input[name='companyid']").val()).toBe(expected);
        expect($("input[name='dni']").val()).toBe(expected);
        // The selection marker has to be written alongside the number. Without
        // it the next clearStaleOrganizationSelection() — any input/change event,
        // or the next re-render — reads the freshly fetched number as stale and
        // wipes it, which is the same defect the stale-selection block pins.
        expect($("input[name='companyid']").attr('data-two-company-name')).toBe(
            'Example Trading Ltd'
        );
    });

    test('a detail number overrides the one the search supplied', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: {
                value: 'Example Trading Ltd',
                organization_number: '11111111',
                lookup_id: 'lookup-abc-123'
            }
        });
        expect($("input[name='companyid']").val()).toBe('11111111');

        ajax.last().succeed({ national_identifier: { id: '87654321' } });
        await flushPromises();

        // The detail endpoint is the more authoritative source; a search result
        // that disagrees with it must not be the value that submits.
        expect($("input[name='companyid']").val()).toBe('87654321');
        expect($("input[name='dni']").val()).toBe('87654321');
    });

    test('a detail number equal to the search one changes nothing', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: {
                value: 'Example Trading Ltd',
                organization_number: '12345678',
                lookup_id: 'lookup-abc-123'
            }
        });
        $("input[name='dni']").val('buyer-typed');

        ajax.last().succeed({ national_identifier: { id: '12345678' } });
        await flushPromises();

        // No divergence means no re-write, so a value the buyer put in the DNI
        // field afterwards is left alone.
        expect($("input[name='companyid']").val()).toBe('12345678');
        expect($("input[name='dni']").val()).toBe('buyer-typed');
    });

    test('the detail request carries no credentials either', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });

        // A second cross-origin call with its own settings block — the search
        // endpoint's twin being right says nothing about this one.
        expect(ajax.last().settings.crossDomain).toBe(true);
        expect(ajax.last().settings.xhrFields).toEqual({ withCredentials: false });
        expect(ajax.last().settings.timeout).toBe(10000);
        ajax.last().succeed({});
        await flushPromises();
    });

    test('a partial address writes the parts it has', async () => {
        await fillFrom({
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street' }]
        });

        expect($("input[name='address1']").val()).toBe('1 Example Street');
        expect($("input[name='postcode']").val()).toBe('');
    });

    test('a partial address blanks a field the buyer had already filled', async () => {
        $("input[name='city']").val('Buyerton');

        await fillFrom({
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street' }]
        });

        // CHARACTERISATION, not endorsement. The missing keys coalesce to '' well
        // before the write guard sees them, so the guard's undefined/null arms are
        // unreachable and an absent city overwrites one the buyer typed. Pinned so
        // the behaviour cannot change silently; see Known gaps in the README for
        // why it is recorded rather than fixed here.
        expect($("input[name='city']").val()).toBe('');
    });

    test('a filled address field notifies the theme', async () => {
        const events = [];
        $("input[name='address1']").on('input change', (e) => events.push(e.type));

        await fillFrom({
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street' }]
        });

        // PrestaShop's own checkout scripts listen on these; a silent val() write
        // fills the box without the theme noticing the address changed.
        expect(events).toEqual(['input', 'change']);
    });

    test('an address field already holding the value is not rewritten', async () => {
        $("input[name='address1']").val('1 Example Street');
        const events = [];
        $("input[name='address1']").on('input change', (e) => events.push(e.type));

        await fillFrom({
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street' }]
        });

        expect(events).toEqual([]);
    });

    test('a business address wins over a mailing one whatever the order', async () => {
        await fillFrom({
            addresses: [
                { type: 'MAILING', street_address: 'Wrong Street', city: 'Wrongton' },
                { type: 'BUSINESS', street_address: '1 Example Street', city: 'Exampleton' }
            ]
        });

        expect($("input[name='address1']").val()).toBe('1 Example Street');
        expect($("input[name='city']").val()).toBe('Exampleton');
    });

    test.each([['REGISTERED'], ['VISITING'], ['BUSINESS']])(
        'a %s address is preferred over an untyped first entry',
        async (type) => {
            await fillFrom({
                addresses: [
                    { street_address: 'Wrong Street' },
                    { type: type, street_address: '1 Example Street' }
                ]
            });

            expect($("input[name='address1']").val()).toBe('1 Example Street');
        }
    );

    test('the first address is used when none carries a preferred type', async () => {
        await fillFrom({
            addresses: [{ street_address: '1 Example Street' }, { street_address: 'Second Street' }]
        });

        expect($("input[name='address1']").val()).toBe('1 Example Street');
    });

    test.each([
        [{ streetAddress: 'x', postalCode: 'p', locality: 'c' }],
        [{ street: 'x', zip: 'p', city: 'c' }],
        [{ address_line_1: 'x', zip_code: 'p', city: 'c' }],
        [{ addressLine1: 'x', postal_code: 'p', city: 'c' }]
    ])('normalises the address key variant %p', async (address) => {
        await fillFrom({ addresses: [Object.assign({ type: 'BUSINESS' }, address)] });

        expect($("input[name='address1']").val()).toBe('x');
        expect($("input[name='postcode']").val()).toBe('p');
        expect($("input[name='city']").val()).toBe('c');
    });

    test('a failed detail lookup leaves the selection intact', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: {
                value: 'Example Trading Ltd',
                organization_number: '12345678',
                lookup_id: 'lookup-abc-123'
            }
        });
        ajax.last().fail('timeout');
        await flushPromises();

        // Address auto-fill is a convenience; losing it must not cost the buyer
        // the company they already picked.
        expect($("input[name='companyid']").val()).toBe('12345678');
        expect($("input[name='company']").val()).toBe('Example Trading Ltd');
    });
});

describe('a destroyed instance cannot act on the live DOM', () => {
    test('destroy drops the spinner classes', () => {
        const search = makeInstance();
        const field = liveField();
        field.addClass('ui-autocomplete-loading two-company-search-loading');

        search.destroy();

        expect(field.hasClass('two-company-search-input')).toBe(false);
        expect(field.hasClass('two-company-search-loading')).toBe(false);
        expect(field.hasClass(LOADING_CLASS)).toBe(false);
        expect(search._destroyed).toBe(true);
    });

    test('setupAutocomplete on a destroyed instance leaves the new field alone', () => {
        const search = makeInstance();
        search.destroy();
        const newField = replaceAddressForm();

        search.setupAutocomplete();

        // Before the guard, the zombie re-resolved the selector, found the SAME
        // live input the live instance uses, and re-bound the widget with its
        // own stale closures.
        expect($(newField).hasClass('two-company-search-input')).toBe(false);
        expect($(newField).hasClass('ui-autocomplete-input')).toBe(false);
        expect(search.companyField.get(0)).not.toBe(newField);
    });

    test('onCompanySelected on a destroyed instance writes nothing', () => {
        const zombie = makeInstance();
        zombie.destroy();
        replaceAddressForm();
        makeInstance();

        const result = zombie.onCompanySelected(null, {
            item: {
                value: 'Example Trading Ltd',
                organization_number: '12345678',
                lookup_id: 'lookup-abc-123'
            }
        });

        // The zombie's organizationField is the detached hidden input its own
        // init() created, so a write through it silently loses `companyid`.
        expect(result).toBe(false);
        expect($("input[name='company']").val()).toBe('');
        expect($("input[name='companyid']")).toHaveLength(1);
        expect($("input[name='companyid']").val()).toBe('');
        expect($("input[name='dni']").val()).toBe('');
        // ...and it must not have fired the company-details lookup either.
        expect(ajax.calls).toHaveLength(0);
    });

    test('the live instance in the same position does write companyid', () => {
        const zombie = makeInstance();
        zombie.destroy();
        replaceAddressForm();
        const live = makeInstance();

        // The mirror image of the test above: proof that the guard blocks only
        // the zombie, not the behaviour the buyer depends on.
        expect(
            live.onCompanySelected(null, {
                item: { value: 'Example Trading Ltd', organization_number: '12345678' }
            })
        ).toBe(true);
        expect($("input[name='company']").val()).toBe('Example Trading Ltd');
        expect($("input[name='companyid']").val()).toBe('12345678');
        expect($("input[name='companyid']").attr('data-two-company-name')).toBe(
            'Example Trading Ltd'
        );
        expect($("input[name='dni']").val()).toBe('12345678');
    });

    test('the address-lookup toggle gates the dni/vat writes but not companyid', () => {
        const live = makeInstance({ addressLookupEnabled: false });

        live.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', organization_number: '12345678' }
        });

        expect($("input[name='companyid']").val()).toBe('12345678');
        expect($("input[name='dni']").val()).toBe('');
        expect($("input[name='vat_number']").val()).toBe('');
    });

    test('setupCountryChangeListener on a destroyed instance binds nothing', () => {
        const search = makeInstance();
        search.destroy();
        replaceAddressForm();
        const countrySelect = document.querySelector("select[name='id_country']");

        search.setupCountryChangeListener(0);
        countrySelect.dispatchEvent(new window.Event('change'));

        // The listener from its own live lifetime is still on the object — what
        // must not happen is it being bound to the REPLACED select and re-running
        // setupAutocomplete() against the live field from there.
        expect(search._boundCountrySelector).not.toBe(countrySelect);
        expect($("input[name='company']").hasClass('two-company-search-input')).toBe(false);
        expect($("input[name='company']").hasClass('ui-autocomplete-input')).toBe(false);
    });

    test('the updatedAddressForm handler stands down once destroyed', () => {
        const search = makeInstance();
        search.destroy();
        const newField = replaceAddressForm();

        // `prestashop.on` has no `off`, so this handler outlives the instance.
        // The only available defence is for the callback to check the flag.
        expect(bus.handlerCount('updatedAddressForm')).toBe(1);
        const setup = jest.spyOn(search, 'setupAutocomplete');
        const listener = jest.spyOn(search, 'setupCountryChangeListener');
        try {
            bus.emit('updatedAddressForm');

            // Asserted on the guard itself as well as on the outcome: the two
            // methods below carry their own `_destroyed` checks, so an outcome-only
            // test cannot tell whether THIS guard is still there.
            expect(setup).not.toHaveBeenCalled();
            expect(listener).not.toHaveBeenCalled();
        } finally {
            setup.mockRestore();
            listener.mockRestore();
        }

        expect($(newField).hasClass('two-company-search-input')).toBe(false);
        expect($(newField).hasClass('ui-autocomplete-input')).toBe(false);
    });

    test('one instance registers the bus handler at most once', () => {
        makeInstance();
        expect(bus.handlerCount('updatedAddressForm')).toBe(1);

        for (let i = 0; i < 10; i += 1) {
            bus.emit('updatedAddressForm');
        }

        // The handler calls back into setupCountryChangeListener(), which used
        // to register another one — so the count DOUBLED per event and every
        // duplicate re-ran the whole re-setup.
        expect(bus.handlerCount('updatedAddressForm')).toBe(1);
    });

    test('a pending country-listener retry does not fire after destroy', () => {
        jest.useFakeTimers();
        try {
            document.body.innerHTML = "<form><input type='text' name='company' value='' /></form>";
            const search = makeInstance();
            expect(search._countryRetryTimeoutId).not.toBeNull();

            search.destroy();
            buildAddressForm({ country: 'GB' });
            jest.advanceTimersByTime(5000);

            // Left armed, it would resolve the country select against the LIVE
            // document up to 3s later and bind a dying instance's listener.
            expect(search._countryRetryTimeoutId).toBeNull();
            expect(search.countryListener).toBeNull();
        } finally {
            jest.useRealTimers();
        }
    });

    test('destroy unbinds the country change listener', () => {
        const search = makeInstance();
        const countrySelect = document.querySelector("select[name='id_country']");
        const setup = jest.spyOn(search, 'setupAutocomplete');

        search.destroy();
        countrySelect.dispatchEvent(new window.Event('change'));

        // A listener left bound leaks one live handler per address-form update,
        // each of which re-runs the whole re-setup for a dead instance.
        expect(setup).not.toHaveBeenCalled();
    });

    test('destroy unbinds from the selector it actually bound, not the default one', () => {
        // setupCountryChangeListener() picks the first of five fallback selectors.
        // Re-querying only `select[name='id_country']` in destroy() missed the
        // listener entirely on a theme matching one of the others.
        document.body.innerHTML = [
            '<form>',
            "  <input type='text' name='company' value='' />",
            '  <select class="js-country"><option value="17" data-iso-code="GB" selected>C</option></select>',
            '</form>'
        ].join('\n');
        const search = makeInstance();
        const countrySelect = document.querySelector('.js-country');

        expect(search._boundCountrySelector).toBe(countrySelect);
        const setup = jest.spyOn(search, 'setupAutocomplete');
        search.destroy();
        countrySelect.dispatchEvent(new window.Event('change'));

        expect(setup).not.toHaveBeenCalled();
    });

    test('the country change listener is de-duplicated on re-setup', () => {
        const search = makeInstance();
        const countrySelect = document.querySelector("select[name='id_country']");
        for (let i = 0; i < 10; i += 1) {
            search.setupCountryChangeListener(0);
        }
        const setup = jest.spyOn(search, 'setupAutocomplete');

        countrySelect.dispatchEvent(new window.Event('change'));

        // Without the removeEventListener that precedes each re-bind, ten
        // address-form updates stack ten handlers on the one select and every
        // country change re-runs the setup ten times.
        expect(setup).toHaveBeenCalledTimes(1);
    });

    test('destroy tears the custom dropdown down even if the widget release throws', () => {
        const savedUi = $.ui;
        $.ui = undefined;
        let search;
        try {
            search = makeInstance();
            expect(search._customAutocomplete).toBeTruthy();
            $.ui = savedUi;
            // jQuery UI's bridge throws on a foreign or half-initialised widget.
            // The custom teardown lives in its own try precisely so that cannot
            // skip it: it unbinds live listeners and clears the pending debounce,
            // and leaving those bound while the instance is marked destroyed is
            // the exact zombie the flag exists to stop.
            search.companyField.hasClass = () => {
                throw new Error('simulated jQuery UI bridge failure');
            };

            search.destroy();
        } finally {
            $.ui = savedUi;
        }

        expect(search._customAutocomplete).toBeNull();
        expect($('.two-autocomplete-container')).toHaveLength(0);
        // Set last and unconditionally, outside both trys.
        expect(search._destroyed).toBe(true);
        expect(search.isInitialized).toBe(false);
    });

    test('destroy releases the widget from the field', () => {
        const search = makeInstance();
        const field = liveField();

        search.destroy();

        expect(field.hasClass('ui-autocomplete-input')).toBe(false);
        expect(field.autocomplete('instance')).toBeUndefined();
    });

    test('moving to a replaced field releases the widget left on the old one', () => {
        const search = makeInstance();
        const oldField = liveField().get(0);
        const newField = replaceAddressForm();

        search.setupAutocomplete();

        // The old node stays detached deliberately — re-attaching it would make
        // the company selector match two inputs, which is not a shape PrestaShop
        // ever produces. Releasing the widget matters even so: jQuery UI appends
        // the `<ul class="ui-autocomplete">` menu to document.body rather than
        // next to the input, so an abandoned widget leaks a live menu with
        // nothing left holding a reference that could clean it up.
        expect($(oldField).hasClass('ui-autocomplete-input')).toBe(false);
        expect($(oldField).autocomplete('instance')).toBeUndefined();
        expect($(oldField).hasClass('two-company-search-input')).toBe(false);
        expect($(newField).hasClass('ui-autocomplete-input')).toBe(true);
        expect(search.companyField.get(0)).toBe(newField);
    });
});

describe('the custom fallback used when jQuery UI is absent', () => {
    let savedUi;

    beforeEach(() => {
        jest.useFakeTimers();
        savedUi = $.ui;
        // A theme can ship jQuery without jQuery UI, or load UI after this
        // module. Both take this path.
        $.ui = undefined;
    });

    afterEach(() => {
        jest.useRealTimers();
        $.ui = savedUi;
    });

    /** Type into the field and let the 300ms debounce elapse. */
    function type(term) {
        const input = document.querySelector("input[name='company']");
        input.value = term;
        input.dispatchEvent(new window.Event('input'));
        jest.advanceTimersByTime(300);
        return input;
    }

    test('it is the path taken, and it arms the fallback spinner', () => {
        const search = makeInstance();
        expect(liveField().hasClass('ui-autocomplete-input')).toBe(false);
        expect(search._customAutocomplete).toBeTruthy();

        type('exa');

        expect(liveField().hasClass('two-company-search-loading')).toBe(true);
        expect($('.two-autocomplete-loading')).toHaveLength(1);
    });

    test('a failure clears the spinner and shows the unavailable row', () => {
        makeInstance();
        type('exa');

        ajax.last().fail('timeout');

        // jQuery UI is absent here, so nothing else would ever take the
        // spinner down.
        expect(liveField().hasClass('two-company-search-loading')).toBe(false);
        expect($('.two-autocomplete-unavailable')).toHaveLength(1);
        expect($('.two-autocomplete-loading')).toHaveLength(0);
    });

    test('a success clears the spinner and renders the results', () => {
        makeInstance();
        type('exa');

        ajax.last().succeed(SEARCH_RESPONSE);

        expect(liveField().hasClass('two-company-search-loading')).toBe(false);
        expect($('.two-autocomplete-item').text()).toContain('Example Trading Ltd (12345678)');
    });

    test('a superseded request leaves the live request spinner alone', () => {
        makeInstance();
        type('exa');
        type('exam');

        // The abort resolves the first request as `silent`; clearing the
        // loading state there would kill the spinner for the request still in
        // flight.
        expect(ajax.calls[0].aborted).toBe(true);
        expect(liveField().hasClass('two-company-search-loading')).toBe(true);

        ajax.calls[1].succeed(SEARCH_RESPONSE);
        expect(liveField().hasClass('two-company-search-loading')).toBe(false);
    });

    test('repeated setup leaves exactly one dropdown and one input listener', () => {
        const search = makeInstance();
        for (let i = 0; i < 10; i += 1) {
            search.setupAutocomplete();
        }

        expect($('.two-autocomplete-container')).toHaveLength(1);
        expect($('.two-autocomplete-list')).toHaveLength(1);

        type('exa');
        // An orphan container's listener still fired on the shared field:
        // duplicate searches and two things fighting over one spinner.
        expect(ajax.calls).toHaveLength(1);
    });

    test('switching to the jQuery UI path removes the fallback and its spinner', () => {
        const search = makeInstance();
        type('exa');
        expect(liveField().hasClass('two-company-search-loading')).toBe(true);

        $.ui = savedUi;
        search.setupAutocomplete();

        expect($('.two-autocomplete-container')).toHaveLength(0);
        expect(liveField().hasClass('two-company-search-loading')).toBe(false);
        expect(liveField().hasClass('ui-autocomplete-input')).toBe(true);
    });

    test('destroy unbinds the listener and drops the container', () => {
        const search = makeInstance();
        search.destroy();

        expect($('.two-autocomplete-container')).toHaveLength(0);
        expect(search._customAutocomplete).toBeNull();

        const input = document.querySelector("input[name='company']");
        input.value = 'exa';
        input.dispatchEvent(new window.Event('input'));
        jest.advanceTimersByTime(1000);

        expect(ajax.calls).toHaveLength(0);
    });

    test('a debounce tick pending at destroy never reaches the network', () => {
        const search = makeInstance();
        const input = document.querySelector("input[name='company']");
        input.value = 'exa';
        input.dispatchEvent(new window.Event('input'));

        search.destroy();
        jest.advanceTimersByTime(1000);

        // A surviving tick would call searchCompanies(), bump the sequence and
        // abort the NEW dropdown's in-flight request — which then resolves as
        // `silent`, so its spinner is never cleared.
        expect(ajax.calls).toHaveLength(0);
    });

    test('a term the input has moved past still clears the spinner', () => {
        makeInstance();
        const input = type('exa');

        // A programmatic val('') on country change fires no input event, so
        // nothing else would ever clear it.
        input.value = 'something else';
        ajax.last().succeed(SEARCH_RESPONSE);

        expect(liveField().hasClass('two-company-search-loading')).toBe(false);
    });
});
