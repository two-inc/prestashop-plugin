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

const fs = require('fs');
const path = require('path');

const {
    loadCompanySearch,
    buildAddressForm,
    replaceAddressForm,
    stubAjax,
    callbackRecorder,
    releaseWidgets,
    flushPromises,
    installStylesheet,
    countGifFrames,
    REPO_ROOT
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
        // 0 is deliberate (TWO-25288). jQuery UI does not invoke `source` for a
        // term shorter than `minLength`, so a threshold here would swallow
        // sub-threshold keystrokes and the too-short hint could never render. The
        // threshold lives in the `source` guard instead - see the hint tests
        // below, which pin that no request escapes it.
        expect(liveField().autocomplete('option', 'minLength')).toBe(0);
        expect(liveField().autocomplete('option', 'delay')).toBe(300);
    });
});

describe('the company-search hints (TWO-25288)', () => {
    /**
     * Drive the widget's own search so the `source` guard, jQuery UI's menu and
     * the `_renderItem` patch are all the things under test.
     */
    function search(term) {
        const field = liveField();
        // Bootstrapped-guard: without the widget bound, every hint assertion
        // below would pass vacuously against an untouched DOM.
        expect(field.hasClass('ui-autocomplete-input')).toBe(true);
        expect(field.autocomplete('instance')).toBeTruthy();
        field.val(term);
        field.autocomplete('instance').search(term);
        return field;
    }

    /** Rows currently in the jQuery UI menu. */
    function rows() {
        return $('ul.ui-autocomplete li');
    }

    describe('the threshold is one constant', () => {
        test('the published constant is 3', () => {
            // Pins the shipped default so a change to it is a visible diff here
            // rather than a silent change of checkout behaviour. Every other
            // assertion in this file derives from the constant, on purpose: they
            // must pin the RELATIONSHIP between what is claimed and what is
            // enforced, which is the thing that drifts.
            expect(TwoCompanySearch.MIN_SEARCH_LENGTH).toBe(3);
        });

        test('the hint states the number the guard actually enforces', () => {
            const instance = makeInstance();
            const threshold = TwoCompanySearch.MIN_SEARCH_LENGTH;

            // The msgid's own `%d` must be gone, replaced by the constant.
            expect(instance.getTooShortText()).toBe(
                'Please enter ' + threshold + ' or more characters'
            );
            expect(instance.getTooShortText()).not.toContain('%d');
        });

        test('the number comes from the constant, not from the translation', () => {
            const saved = window.twopayment;
            window.twopayment = { i18n: { company_search_too_short: 'Introduce %d o más caracteres' } };
            try {
                expect(makeInstance().getTooShortText()).toBe(
                    'Introduce ' + TwoCompanySearch.MIN_SEARCH_LENGTH + ' o más caracteres'
                );
            } finally {
                window.twopayment = saved;
            }
        });
    });

    describe('the empty-field hint occupies the placeholder', () => {
        test('setup applies it when the field carries none', () => {
            expect(liveField().attr('placeholder')).toBeUndefined();

            makeInstance();

            expect(liveField().attr('placeholder')).toBe('Enter company name to search');
        });

        test('it uses the translated wording when one is supplied', () => {
            const saved = window.twopayment;
            window.twopayment = { i18n: { company_search_placeholder: 'Introduce el nombre de la empresa para buscar' } };
            try {
                makeInstance();
                expect(liveField().attr('placeholder')).toBe('Introduce el nombre de la empresa para buscar');
            } finally {
                window.twopayment = saved;
            }
        });

        test('a placeholder the theme already set is left alone', () => {
            liveField().attr('placeholder', 'Theme wording');

            makeInstance();

            expect(liveField().attr('placeholder')).toBe('Theme wording');
        });

        test('it is reapplied to the fresh input after an address-form re-render', () => {
            makeInstance();
            replaceAddressForm({ country: 'GB' });
            expect(liveField().attr('placeholder')).toBeUndefined();

            // PrestaShop swapped the node; the hint has to land on the new one.
            bus.emit('updatedAddressForm');

            expect(liveField().attr('placeholder')).toBe('Enter company name to search');
        });
    });

    describe('the min-chars hint on the jQuery UI path', () => {
        test('a sub-threshold term shows the hint and fires no request', () => {
            const instance = makeInstance();
            const short = 'a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH - 1);

            search(short);

            expect(rows()).toHaveLength(1);
            expect(rows().hasClass('two-autocomplete-too-short')).toBe(true);
            expect(rows().text()).toBe(instance.getTooShortText());
            // Before TWO-25288 the widget swallowed this term and showed nothing,
            // which is indistinguishable from a search that found no match.
            expect(ajax.calls).toHaveLength(0);
        });

        test('the hint row is not the failure row', () => {
            makeInstance();

            search('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH - 1));

            // Nothing is broken and retrying will not help, so it must not carry
            // the class the failure copy is styled and asserted by.
            expect(rows().hasClass('two-autocomplete-unavailable')).toBe(false);
        });

        test('the hint row is non-selectable and cannot reach the field', () => {
            const instance = makeInstance();
            const field = liveField();

            search('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH - 1));

            // `ui-state-disabled` is what jQuery UI own menu checks, so the row is
            // skipped by keyboard navigation rather than merely refused on select.
            expect(rows().hasClass('ui-state-disabled')).toBe(true);
            expect(rows().attr('aria-disabled')).toBe('true');

            const item = { item: instance.buildTooShortItem() };
            expect(field.autocomplete('option', 'select')(null, item)).toBe(false);
            expect(field.autocomplete('option', 'focus')(null, item)).toBe(false);
            expect(field.val()).toBe('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH - 1));
        });

        test('a term at the threshold searches and shows no hint', () => {
            makeInstance();

            search('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH));

            expect(ajax.calls).toHaveLength(1);
            expect($('ul.ui-autocomplete li.two-autocomplete-too-short')).toHaveLength(0);
        });

        test('an empty field shows no row at all', () => {
            makeInstance();

            search('');

            // The placeholder is already the hint for this state; a dropdown
            // repeating it under an untouched field is noise.
            expect(rows()).toHaveLength(0);
            expect(ajax.calls).toHaveLength(0);
        });

        test('whitespace alone is told to type more rather than searched for', () => {
            makeInstance();

            // Long enough to clear the threshold by raw length, but there is
            // nothing here the search could match on, so it must not go on the
            // wire - and it must not be refused silently either.
            search('   ');

            expect(ajax.calls).toHaveLength(0);
            expect(rows()).toHaveLength(1);
            expect(rows().hasClass('two-autocomplete-too-short')).toBe(true);
        });
    });

    describe('the country-not-chosen row is not the failure row', () => {
        test('it carries its own class on the jQuery UI path', () => {
            // No resolvable ISO code, so no search can be made. The cause is the
            // buyer not having picked a country, which is not a failure of the
            // service - and the DOM must say which of the two it is.
            document.body.innerHTML = '';
            buildAddressForm({ country: null, countryId: '999' });

            const instance = makeInstance();
            search('exa');

            expect(ajax.calls).toHaveLength(0);
            expect(rows()).toHaveLength(1);
            expect(rows().text()).toBe(instance.getSelectCountryText());
            expect(rows().hasClass('two-autocomplete-select-country')).toBe(true);
            expect(rows().hasClass('two-autocomplete-unavailable')).toBe(false);
        });
    });

    /**
     * Every message row must LOOK like a message, whatever its cause.
     *
     * The row classes above are asserted by name, and that assertion passes just
     * as happily with a row that renders in body text under a pointer cursor and
     * takes jQuery UI's hover highlight - i.e. one that looks exactly like a
     * clickable company while being unselectable. Only the resolved style catches
     * that, which is why the shipped stylesheet is loaded here, and why the rules
     * are keyed on a class shared by every message row rather than on one cause.
     */
    describe('a message row is painted as a message', () => {
        let stylesheet;

        beforeEach(() => {
            stylesheet = installStylesheet('views/css/two.css');
        });

        afterEach(() => {
            if (stylesheet && stylesheet.parentNode) {
                stylesheet.parentNode.removeChild(stylesheet);
            }
        });

        test('the too-short row is muted and unclickable, exactly as the failure row is', () => {
            makeInstance();

            search('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH - 1));
            const hint = rows().get(0);

            expect(rows().hasClass('two-autocomplete-message')).toBe(true);
            expect(window.getComputedStyle(hint).color).toBe('rgb(136, 136, 136)');
            expect(window.getComputedStyle(hint).cursor).toBe('default');

            // The wrapper div is where jQuery UI puts the text, and where the
            // module's own generic row rules paint body-text colour `!important`.
            const wrapper = rows().children('div').get(0);
            expect(window.getComputedStyle(wrapper).color).toBe('rgb(136, 136, 136)');
            expect(window.getComputedStyle(wrapper).cursor).toBe('default');

            // The same row rendered for the failure cause, for comparison: the
            // two must be indistinguishable in appearance, since the whole point
            // of the per-cause class is that it changes the WORDING and the DOM
            // identity, not the look.
            const failure = $('<li>')
                .addClass('two-autocomplete-message two-autocomplete-unavailable')
                .appendTo(document.body)
                .get(0);
            expect(window.getComputedStyle(hint).color)
                .toBe(window.getComputedStyle(failure).color);
            expect(window.getComputedStyle(hint).cursor)
                .toBe(window.getComputedStyle(failure).cursor);
        });

        test('the hover highlight is suppressed on the row jQuery UI would highlight', () => {
            makeInstance();
            search('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH - 1));

            // `ui-state-active` is what jQuery UI's menu puts on the wrapper of
            // the row under the pointer. On a message row that highlight is a
            // promise the row cannot keep, so the stylesheet has to out-rank it.
            const wrapper = rows().children('div').addClass('ui-state-active').get(0);
            const painted = window.getComputedStyle(wrapper);

            expect(painted.color).toBe('rgb(136, 136, 136)');
            expect(painted.cursor).toBe('default');
            expect(painted.margin).toBe('0px');
            // The highlight the generic wrapper rule paints, declared
            // `!important` there, so this row's rules have to out-rank it.
            expect(painted.backgroundColor).not.toBe('rgb(248, 249, 250)');
        });

        test('the country-not-chosen row gets the same paint', () => {
            document.body.innerHTML = '';
            buildAddressForm({ country: null, countryId: '999' });
            makeInstance();

            search('exa');

            expect(rows().hasClass('two-autocomplete-message')).toBe(true);
            expect(window.getComputedStyle(rows().get(0)).color).toBe('rgb(136, 136, 136)');
        });
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

    test('a partial address leaves a field the buyer had already filled alone', async () => {
        $("input[name='city']").val('Buyerton');

        await fillFrom({
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street' }]
        });

        // A company record missing a key is not evidence the buyer's own answer
        // is wrong. The key variants all coalesce to '' before the write, so
        // "absent" and "empty string" are the same thing here - which is why
        // this used to blank the field and why the fix keys off whether WE wrote
        // the current value rather than off the incoming shape.
        expect($("input[name='city']").val()).toBe('Buyerton');
        expect($("input[name='address1']").val()).toBe('1 Example Street');
    });

    test('a second company clears an address part the first one filled', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });
        ajax.last().succeed({
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street', city: 'Exampleton' }]
        });
        await flushPromises();
        expect($("input[name='city']").val()).toBe('Exampleton');

        // Same instance, second selection: the new company has no city. Leaving
        // the previous company's city behind is just as wrong as blanking the
        // buyer's own - it silently attributes one company's address to another.
        search.onCompanySelected(null, {
            item: { value: 'Second Trading Ltd', lookup_id: 'lookup-def-456' }
        });
        ajax.last().succeed({
            addresses: [{ type: 'BUSINESS', street_address: '2 Second Street' }]
        });
        await flushPromises();

        expect($("input[name='address1']").val()).toBe('2 Second Street');
        expect($("input[name='city']").val()).toBe('');
    });

    test('a buyer edit after an autofill survives the next company selection', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });
        ajax.last().succeed({
            addresses: [{ type: 'BUSINESS', street_address: '1 Example Street', city: 'Exampleton' }]
        });
        await flushPromises();

        // The buyer corrects the autofilled city by hand. That makes it buyer
        // input, so the marker recorded at fill time no longer matches and the
        // clearing arm above must not claim it.
        $("input[name='city']").val('Buyerton');

        search.onCompanySelected(null, {
            item: { value: 'Second Trading Ltd', lookup_id: 'lookup-def-456' }
        });
        ajax.last().succeed({
            addresses: [{ type: 'BUSINESS', street_address: '2 Second Street' }]
        });
        await flushPromises();

        expect($("input[name='city']").val()).toBe('Buyerton');
    });

    test('an autofilled value that the company repeats is still recognised as ours', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });
        ajax.last().succeed({
            addresses: [{ type: 'BUSINESS', city: 'Exampleton' }]
        });
        await flushPromises();

        // Second company repeats the same city, so there is no write to do. The
        // marker still has to be refreshed, or a third company without a city
        // would read the value as buyer-typed and keep it forever.
        search.onCompanySelected(null, {
            item: { value: 'Second Trading Ltd', lookup_id: 'lookup-def-456' }
        });
        ajax.last().succeed({ addresses: [{ type: 'BUSINESS', city: 'Exampleton' }] });
        await flushPromises();

        search.onCompanySelected(null, {
            item: { value: 'Third Trading Ltd', lookup_id: 'lookup-ghi-789' }
        });
        ajax.last().succeed({ addresses: [{ type: 'BUSINESS', street_address: '3 Third Street' }] });
        await flushPromises();

        expect($("input[name='city']").val()).toBe('');
    });

    test('a company confirming a value the buyer typed still claims it', async () => {
        // The load-bearing case for recording the marker OUTSIDE the write
        // branch: there is nothing to write, because the company's city is the
        // one the buyer already typed. Record it anyway - the fill has claimed
        // that value for this company, so the next company that lacks a city
        // must be able to clear it. With the marker recorded only on write, this
        // reads as untouched buyer input and the city sticks to the wrong
        // company for the rest of checkout.
        $("input[name='city']").val('Exampleton');

        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });
        ajax.last().succeed({ addresses: [{ type: 'BUSINESS', city: 'Exampleton' }] });
        await flushPromises();
        expect($("input[name='city']").val()).toBe('Exampleton');

        search.onCompanySelected(null, {
            item: { value: 'Second Trading Ltd', lookup_id: 'lookup-def-456' }
        });
        ajax.last().succeed({ addresses: [{ type: 'BUSINESS', street_address: '2 Second Street' }] });
        await flushPromises();

        expect($("input[name='city']").val()).toBe('');
    });

    test('clearing a stale autofill notifies the theme', async () => {
        const search = makeInstance();
        search.onCompanySelected(null, {
            item: { value: 'Example Trading Ltd', lookup_id: 'lookup-abc-123' }
        });
        ajax.last().succeed({ addresses: [{ type: 'BUSINESS', city: 'Exampleton' }] });
        await flushPromises();

        const events = [];
        $("input[name='city']").on('input change', (e) => events.push(e.type));

        search.onCompanySelected(null, {
            item: { value: 'Second Trading Ltd', lookup_id: 'lookup-def-456' }
        });
        ajax.last().succeed({ addresses: [{ type: 'BUSINESS', street_address: '2 Second Street' }] });
        await flushPromises();

        // Themes bind validation and their own state to these; a cleared field
        // that never announced itself leaves the theme showing the old value as
        // still valid.
        expect(events).toEqual(['input', 'change']);
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

/**
 * TWO-25288. The in-field spinner is the loader GIF, set as the company input's
 * own `background-image` and shown purely by the loading class the module already
 * puts on that input.
 *
 * Nothing in this suite covered that before, and "the class is set" is not the
 * same claim: the class was already asserted elsewhere while an unscoped
 * `!important` rule further down the stylesheet quietly out-ranked the scoped one
 * and painted a white background over the field. Only reading the resolved style
 * catches that, so these are the sole tests here that load a real stylesheet
 * (`installStylesheet()` in the harness).
 *
 * Both render paths are pinned deliberately. The jQuery UI path and the custom
 * fallback set different classes and share nothing but the CSS contract, so
 * covering one and assuming the other leaves half the surface untested with a
 * green suite.
 */
describe('the in-field spinner GIF', () => {
    let stylesheet;

    beforeEach(() => {
        // The real shipped stylesheet, so the rule under test is the one that
        // ships rather than a restatement of it.
        stylesheet = installStylesheet('views/css/two.css');
    });

    afterEach(() => {
        if (stylesheet && stylesheet.parentNode) {
            stylesheet.parentNode.removeChild(stylesheet);
        }
    });

    function styleOf(el) {
        return window.getComputedStyle(el);
    }

    test('the asset the stylesheet asks for is actually in the repo', () => {
        // A rule naming a file that is not shipped resolves in jsdom exactly as
        // happily as one naming a file that is, so the URL assertions below would
        // all pass with the GIF deleted. This is the case that would not.
        const css = fs.readFileSync(path.join(REPO_ROOT, 'views/css/two.css'), 'utf8');
        const match = css.match(/\.two-company-search-input\.ui-autocomplete-loading[\s\S]*?url\("([^"]+)"\)/);
        expect(match).not.toBeNull();

        // Resolved the way a browser resolves it: relative to the stylesheet.
        const asset = path.resolve(path.join(REPO_ROOT, 'views/css'), match[1]);
        expect(fs.existsSync(asset)).toBe(true);

        // Animated, and the size the rule pins. A still image here would be a
        // spinner that never spins, which no CSS assertion can tell apart.
        const bytes = fs.readFileSync(asset);
        expect(bytes.slice(0, 6).toString('latin1')).toBe('GIF89a');
        expect(bytes.readUInt16LE(6)).toBe(16);
        expect(bytes.readUInt16LE(8)).toBe(16);
        // Frame count. A GIF with one image descriptor is a static picture; this
        // one must have several or it does not animate. Counted by walking the
        // block structure rather than by scanning for the image-descriptor
        // byte, which also occurs inside the colour table and the compressed
        // pixel data - a scan finds 'frames' in a single-frame file, so the
        // assertion below could never fail.
        expect(countGifFrames(bytes)).toBeGreaterThan(1);
    });

    test('nothing paints on the field while it is idle', () => {
        makeInstance();
        const input = liveField().get(0);

        // The paint is gated on the loading class, so an idle field must be
        // untouched. The gutter, by contrast, is reserved unconditionally:
        // toggling the padding with the spinner reflowed the field's text in and
        // out on every keystroke.
        expect(styleOf(input).backgroundImage).toBe('');
        expect(styleOf(input).paddingRight).toBe('32px');
    });

    test('the scoped rule is the one that applies while loading, not an !important one', () => {
        makeInstance();
        const field = liveField();
        const input = field.get(0);

        field.val('exa');
        field.autocomplete('instance').search('exa');
        expect(field.hasClass(LOADING_CLASS)).toBe(true);

        // This is the substantive change. A second, unscoped
        // `.ui-autocomplete-loading` rule used to sit further down this
        // stylesheet declaring `background: white ... !important` and
        // `padding-right: 25px !important`. Being `!important` it out-ranked the
        // scoped rule whatever the specificity, so the field really did get a
        // white box painted over the merchant's theme and a 25px gutter while the
        // 32px one was reserved - and both rules named the same GIF, so the
        // spinner looked fine and nothing gave it away.
        //
        // Both values below flip if that rule comes back, which is why they are
        // asserted here, in the loading state, rather than on an idle field: the
        // removed rule was gated on the same class and an idle field cannot see
        // it.
        //
        // `background-size` is the proxy for the white box rather than
        // `background-color`, which cannot do the job: jsdom's own default
        // stylesheet already resolves every `input` to a white background, so
        // that assertion passes whatever this stylesheet says. The removed rule
        // used the `background` SHORTHAND, which resets the longhands it omits -
        // so `background-size` reverts to `auto` if it comes back, and the
        // spinner is drawn at whatever size the field gives it.
        const painted = styleOf(input);
        expect(painted.paddingRight).toBe('32px');
        expect(painted.backgroundSize).toBe('16px 16px');
    });

    test('the GIF paints on the input during a jQuery UI search, and stops after', () => {
        makeInstance();
        const field = liveField();
        const input = field.get(0);

        field.val('exa');
        field.autocomplete('instance').search('exa');

        // jQuery UI puts `ui-autocomplete-loading` on the input itself; the
        // stylesheet turns that into the GIF with no JS involvement at all.
        expect(field.hasClass(LOADING_CLASS)).toBe(true);
        const painted = styleOf(input);
        expect(painted.backgroundImage).toContain('loader.gif');
        // Repeated across the field would tile the spinner; unpinned size would
        // let a themed input scale it.
        expect(painted.backgroundRepeat).toBe('no-repeat');
        expect(painted.backgroundSize).toBe('16px 16px');

        ajax.last().succeed(SEARCH_RESPONSE);

        expect(styleOf(input).backgroundImage).toBe('');
    });

    describe('on the custom fallback path, where jQuery UI is absent', () => {
        let savedUi;

        beforeEach(() => {
            jest.useFakeTimers();
            savedUi = $.ui;
            $.ui = undefined;
        });

        afterEach(() => {
            jest.useRealTimers();
            $.ui = savedUi;
        });

        function type(term) {
            const input = document.querySelector("input[name='company']");
            input.value = term;
            input.dispatchEvent(new window.Event('input'));
            jest.advanceTimersByTime(300);
            return input;
        }

        test('the GIF paints during a search and stops afterwards', () => {
            makeInstance();
            const input = liveField().get(0);

            expect(styleOf(input).backgroundImage).toBe('');

            type('exa');

            // This path sets its own class by hand, matched by the same rule -
            // one CSS contract, two arming mechanisms.
            expect(liveField().hasClass('two-company-search-loading')).toBe(true);
            expect(styleOf(input).backgroundImage).toContain('loader.gif');

            ajax.last().succeed(SEARCH_RESPONSE);

            expect(styleOf(input).backgroundImage).toBe('');
        });

        test('a failed search stops it too', () => {
            makeInstance();
            const input = liveField().get(0);
            type('exa');

            ajax.last().fail('timeout');

            expect(styleOf(input).backgroundImage).toBe('');
        });
    });

    test('it still paints after an address-form re-render', () => {
        const search = makeInstance();
        replaceAddressForm();
        search.setupAutocomplete();

        // The re-render replaces the input, so the class the stylesheet keys off
        // has to be re-applied to the replacement. A field that searches with no
        // visible feedback is the failure this catches, and every class-level
        // assertion elsewhere passes while it is broken.
        const field = liveField();
        const input = field.get(0);
        expect(styleOf(input).backgroundImage).toBe('');

        field.val('exa');
        field.autocomplete('instance').search('exa');

        expect(styleOf(input).backgroundImage).toContain('loader.gif');

        ajax.last().succeed(SEARCH_RESPONSE);

        expect(styleOf(input).backgroundImage).toBe('');
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

    describe('the company-search hints (TWO-25288)', () => {
        test('the empty-field hint is applied on this path too', () => {
            expect(liveField().attr('placeholder')).toBeUndefined();

            const search = makeInstance();

            // Bootstrapped-guard: this must be the fallback path, or the
            // assertion is really re-testing the jQuery UI one.
            expect(liveField().hasClass('ui-autocomplete-input')).toBe(false);
            expect(search._customAutocomplete).toBeTruthy();
            expect(liveField().attr('placeholder')).toBe('Enter company name to search');
        });

        test('a sub-threshold term shows the hint and fires no request', () => {
            const search = makeInstance();
            expect(search._customAutocomplete).toBeTruthy();

            type('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH - 1));

            const row = $('.two-autocomplete-too-short');
            expect(row).toHaveLength(1);
            expect(row.text()).toBe(search.getTooShortText());
            expect($('.two-autocomplete-list').css('display')).not.toBe('none');
            expect(ajax.calls).toHaveLength(0);
            // Nothing was requested, so nothing may leave a spinner running.
            expect(liveField().hasClass('two-company-search-loading')).toBe(false);
            expect($('.two-autocomplete-unavailable')).toHaveLength(0);
        });

        test('a term at the threshold searches and shows no hint', () => {
            const search = makeInstance();
            expect(search._customAutocomplete).toBeTruthy();

            type('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH));

            expect(ajax.calls).toHaveLength(1);
            expect($('.two-autocomplete-too-short')).toHaveLength(0);
        });

        test('whitespace alone is told to type more rather than searched for', () => {
            const search = makeInstance();
            expect(search._customAutocomplete).toBeTruthy();

            type('   ');

            expect(ajax.calls).toHaveLength(0);
            expect($('.two-autocomplete-too-short')).toHaveLength(1);
        });

        test('the hint row is painted as a message on this path too', () => {
            const stylesheet = installStylesheet('views/css/two.css');
            try {
                makeInstance();
                type('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH - 1));

                // The shared class is what carries the message look, and this
                // path must carry it as well or the two paths diverge again. The
                // cursor comes from the stylesheet alone - the row inlines its
                // colour but nothing inlines this - so it is the assertion that
                // fails if the shared class is dropped here.
                const row = $('.two-autocomplete-too-short');
                expect(row.hasClass('two-autocomplete-message')).toBe(true);
                expect(window.getComputedStyle(row.get(0)).cursor).toBe('default');
                expect(window.getComputedStyle(row.get(0)).color).toBe('rgb(136, 136, 136)');
            } finally {
                if (stylesheet && stylesheet.parentNode) {
                    stylesheet.parentNode.removeChild(stylesheet);
                }
            }
        });

        test('clearing the field closes the list rather than showing the hint', () => {
            const search = makeInstance();
            type('a'.repeat(TwoCompanySearch.MIN_SEARCH_LENGTH - 1));
            expect($('.two-autocomplete-too-short')).toHaveLength(1);

            type('');

            expect(search._customAutocomplete).toBeTruthy();
            expect($('.two-autocomplete-too-short')).toHaveLength(0);
            expect($('.two-autocomplete-list').css('display')).toBe('none');
            expect(ajax.calls).toHaveLength(0);
        });
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

    test('blurring the field closes the dropdown, and does not reopen it after destroy', () => {
        const search = makeInstance();
        const input = document.querySelector("input[name='company']");
        type('exa');
        ajax.last().succeed(SEARCH_RESPONSE);
        expect($('.two-autocomplete-list').css('display')).not.toBe('none');

        input.dispatchEvent(new window.Event('blur'));
        jest.advanceTimersByTime(150);
        expect($('.two-autocomplete-list').css('display')).toBe('none');

        // NOTE what this does and does not pin. The blur handler's unbind in
        // teardownCustomAutocomplete() has no observable consequence: the closure
        // only re-hides the list it was created with, and that node is already
        // detached by the time a leaked handler could fire, so a leaked one is
        // idempotent. Recorded as redundancy in the README rather than pretended
        // to be covered. What IS pinned here: blur closes the dropdown while
        // live, and destroy leaves nothing for a blur to act on.
        search.destroy();
        expect($('.two-autocomplete-container')).toHaveLength(0);
        expect(() => {
            input.dispatchEvent(new window.Event('blur'));
            jest.advanceTimersByTime(150);
        }).not.toThrow();
        expect($('.two-autocomplete-container')).toHaveLength(0);
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
