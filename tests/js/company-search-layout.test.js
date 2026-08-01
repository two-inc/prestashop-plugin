/**
 * TWO-30.x.10. Regression tests for four live layout/reachability bugs Doug
 * found testing the checkout by hand, on top of a widget already confirmed
 * working (PR two-inc/prestashop-plugin#128):
 *
 *   - 2.1 the dropdown auto-widened past the field (jQuery UI's own
 *     `_resizeMenu` sizes it to whichever is WIDER, the field or the longest
 *     label) instead of staying a small control anchored to the field.
 *   - 2.2/2.3 the post-selection chip and the org-number hint were positioned
 *     against the field's THEME wrapper (a Bootstrap column div, commonly
 *     padded) rather than the field itself, so both rendered wider than the
 *     field and offset from its edge - and, live, the chip's opaque
 *     background painted directly over the hint, which is why a plain
 *     DOM-visibility assertion on the hint would have passed throughout.
 *   - 2.4 the manual-entry row was reachable only by scrolling past every
 *     other result first - with up to 50 companies ahead of it in a 200px
 *     scroll viewport, effectively never in practice.
 *
 * jsdom computes no real layout (offsetWidth/getBoundingClientRect are 0
 * regardless of CSS), so these tests pin what jsdom CAN observe: the DOM
 * structure the width fix depends on, the exact value the CSS custom
 * property is set to, and the computed style the sticky-positioning rule
 * resolves to. The visual result (dropdown no longer 625px against a 281px
 * field; hint visible below the field, not painted over) was verified live
 * against https://prestashop-dev.staging.two.inc both before and after this
 * change - that is what these unit tests cannot themselves prove.
 */

'use strict';

const {
    loadCompanySearch,
    buildAddressForm,
    replaceAddressForm,
    stubAjax,
    releaseWidgets,
    installStylesheet
} = require('./ps-harness');

const CHECKOUT_HOST = 'https://api.example.test';

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
    document.documentElement.style.removeProperty('--two-company-search-width');
    jest.useRealTimers();
});

function makeInstance(extraConfig) {
    return new TwoCompanySearch(
        Object.assign({ checkoutHost: CHECKOUT_HOST }, extraConfig || {})
    );
}

function liveField() {
    return $("input[name='company']");
}

describe('the field wrapper (2.2/2.3)', () => {
    test('wraps the company field in a single tight-fitting wrapper', () => {
        makeInstance();

        const parent = liveField().parent();

        expect(parent.hasClass('two-company-field-wrap')).toBe(true);
    });

    test('does not double-wrap across a re-run of setupAutocomplete on the SAME field', () => {
        const instance = makeInstance();

        instance.setupAutocomplete();
        instance.setupAutocomplete();

        expect($('.two-company-field-wrap').length).toBe(1);
        expect(liveField().parent().hasClass('two-company-field-wrap')).toBe(true);
    });

    test('wraps the fresh field after an address-form re-render', () => {
        makeInstance();

        replaceAddressForm({ country: 'GB' });
        // PrestaShop swapped the node - the old wrapper, if any, went with it.
        expect(liveField().parent().hasClass('two-company-field-wrap')).toBe(false);

        bus.emit('updatedAddressForm');

        expect(liveField().parent().hasClass('two-company-field-wrap')).toBe(true);
        expect($('.two-company-field-wrap').length).toBe(1);
    });

    test('the org-id hint and the reveal chip both land inside the field wrapper', () => {
        makeInstance();

        const wrapper = liveField().parent();

        expect(wrapper.find('.two-company-id-hint').length).toBe(1);
        expect(wrapper.find('.two-company-search-reveal').length).toBe(1);
    });

    test('the hidden organisation-number field is NOT pulled into the wrapper', () => {
        // createOrganizationField() runs before ensureFieldWrapper() in init()
        // and inserts a plain sibling - it must stay a sibling of the wrapper,
        // not get swept inside it, since jQuery's wrap() only wraps the
        // selected element itself.
        makeInstance();

        const wrapper = liveField().parent();

        expect(wrapper.find("input[name='companyid']").length).toBe(0);
        expect(wrapper.siblings("input[name='companyid']").length).toBe(1);
    });
});

describe('the dropdown width CSS variable (2.1)', () => {
    test('constrainAutocompleteMenuWidth() publishes the field width as a CSS custom property', () => {
        const instance = makeInstance();
        jest.spyOn($.fn, 'outerWidth').mockReturnValue(281);

        instance.constrainAutocompleteMenuWidth();

        expect(document.documentElement.style.getPropertyValue('--two-company-search-width'))
            .toBe('281px');
    });

    test('a falsy width (e.g. a detached/hidden field) CLEARS the property rather than leaving a stale value', () => {
        // TWO-30.x.10 review finding (Han + Vader, convergent): this is a
        // page-wide singleton variable. Leaving a stale value behind when a
        // field goes hidden/detached would silently mis-clamp whatever
        // dropdown reads the variable next.
        const instance = makeInstance();
        document.documentElement.style.setProperty('--two-company-search-width', '999px');
        jest.spyOn($.fn, 'outerWidth').mockReturnValue(0);

        instance.constrainAutocompleteMenuWidth();

        expect(document.documentElement.style.getPropertyValue('--two-company-search-width'))
            .toBe('');
    });

    test('a missing/destroyed field is a no-op, not a throw', () => {
        const instance = makeInstance();
        instance.companyField = $();

        expect(() => instance.constrainAutocompleteMenuWidth()).not.toThrow();
    });

    test('destroy() clears the property so a later, differently-sized field is not mis-clamped', () => {
        const instance = makeInstance();
        document.documentElement.style.setProperty('--two-company-search-width', '281px');

        instance.destroy();

        expect(document.documentElement.style.getPropertyValue('--two-company-search-width'))
            .toBe('');
    });

    test('the widget gets the scoping marker class, not left as bare .ui-autocomplete', () => {
        // TWO-30.x.10 review finding (Han): `.ui-autocomplete` is jQuery UI's
        // own un-namespaced default class - shared by any OTHER jQuery UI
        // autocomplete the page might have live. The width clamp must be
        // scoped to a marker THIS class controls, or it would mis-size an
        // unrelated widget elsewhere on the page.
        const instance = makeInstance();

        const widget = liveField().autocomplete('widget');

        expect(widget.hasClass('two-company-autocomplete-menu')).toBe(true);
    });

    test('a throwing autocomplete("widget") degrades to an unclamped dropdown, not a dead company search', () => {
        // TWO-30.x.10 round-2 review finding (Han): this is a cosmetic
        // clamp, not core search functionality. `autocomplete('widget')`/
        // `autocomplete('instance')` right below it in the source are
        // already documented as capable of throwing on a non-standard
        // jQuery UI build - an uncaught throw here would escape
        // setupAutocomplete(), init() and the constructor itself, since
        // TwoCheckoutManager.initializeCompanySearch() calls `new
        // TwoCompanySearch(...)` with no surrounding try/catch.
        buildAddressForm({ country: 'GB' });
        const original = $.fn.autocomplete;
        jest.spyOn($.fn, 'autocomplete').mockImplementation(function (...args) {
            if (args[0] === 'widget') {
                throw new Error('simulated non-standard jQuery UI build');
            }
            return original.apply(this, args);
        });

        expect(() => makeInstance()).not.toThrow();
    });

    test('the stylesheet clamps only the scoped marker class, never the bare .ui-autocomplete', () => {
        // jsdom's CSS engine does not resolve `var()` at getComputedStyle time
        // (it reports the raw value), so this cannot assert the resolved
        // pixel figure the way a real browser would - that is exactly what
        // the live verification against the staging shop covers instead.
        // What jsdom CAN prove is that the rule keys off the variable this
        // class publishes AND is scoped to the marker class, rather than a
        // single fixed width applied to every jQuery UI autocomplete on the
        // page.
        installStylesheet('views/css/two.css');
        const bareUl = document.createElement('ul');
        bareUl.className = 'ui-autocomplete';
        document.body.appendChild(bareUl);
        const scopedUl = document.createElement('ul');
        scopedUl.className = 'ui-autocomplete two-company-autocomplete-menu';
        document.body.appendChild(scopedUl);

        // jsdom reports an unmatched property as '' rather than the browser's
        // 'none' initial value - a jsdom quirk, not a claim about real
        // browsers. What matters here is that the two elements resolve
        // DIFFERENTLY: the bare class gets nothing, the scoped one gets the
        // clamp.
        expect(getComputedStyle(bareUl).maxWidth).toBe('');
        const scopedMaxWidth = getComputedStyle(scopedUl).maxWidth;
        expect(scopedMaxWidth).toContain('var(--two-company-search-width');
        expect(scopedMaxWidth).toContain('320px');
    });
});

describe('the field wrapper width is pinned explicitly, not left to block auto-sizing (2.2 hardening)', () => {
    test('ensureFieldWrapper() sets the wrapper width to the field\'s own outerWidth()', () => {
        // TWO-30.x.10 review finding (Han + Vader, convergent): a
        // `display:block` wrapper with no padding only matches the input's
        // width when the input already fills its container - false on a
        // theme where the field has its own narrower intrinsic width. Pin it
        // explicitly instead of trusting block auto-sizing.
        const instance = makeInstance();
        jest.spyOn($.fn, 'outerWidth').mockReturnValue(240);

        instance.ensureFieldWrapper();

        expect(liveField().parent().css('width')).toBe('240px');
    });

    test('a falsy width clears a previously-set inline width rather than leaving it stale', () => {
        // jQuery's `.css('width')` reads the COMPUTED style, which jsdom
        // resolves to '0px' for an unset width regardless - the inline style
        // itself is what proves whether a stale pixel value was left behind.
        const instance = makeInstance();
        liveField().parent().get(0).style.width = '999px';
        jest.spyOn($.fn, 'outerWidth').mockReturnValue(0);

        instance.ensureFieldWrapper();

        expect(liveField().parent().get(0).style.width).toBe('');
    });
});

describe('the width-refresh listener on resize/orientationchange (2.1/2.2 hardening)', () => {
    test('is bound at most once per instance across repeated setupAutocomplete() calls', () => {
        // makeInstance() itself already runs init() -> setupAutocomplete(),
        // which is the FIRST bind - so the spy has to be in place before
        // construction to see it, and two MORE explicit calls after should
        // add no further binds.
        const onSpy = jest.spyOn($.fn, 'on');
        const instance = makeInstance();

        instance.setupAutocomplete();
        instance.setupAutocomplete();

        const resizeBindCalls = onSpy.mock.calls.filter(
            (call) => typeof call[0] === 'string' && call[0].includes('resize.twoCompanyWidth')
        );
        expect(resizeBindCalls).toHaveLength(1);
    });

    test('destroy() actually unbinds the handler - a later resize no longer refreshes geometry', () => {
        // TWO-30.x.10 round-2 review finding (Yoda): a bare "does not throw"
        // assertion passes identically whether or not the listener was ever
        // removed, since the handler itself never throws. Spy on the two
        // methods the handler calls to prove the unbind actually happened.
        jest.useFakeTimers();
        const instance = makeInstance();
        const wrapperSpy = jest.spyOn(instance, 'ensureFieldWrapper');
        const widthSpy = jest.spyOn(instance, 'constrainAutocompleteMenuWidth');

        instance.destroy();
        wrapperSpy.mockClear();
        widthSpy.mockClear();

        $(window).trigger('resize');
        jest.advanceTimersByTime(200);

        expect(wrapperSpy).not.toHaveBeenCalled();
        expect(widthSpy).not.toHaveBeenCalled();
    });

    test('a resize DOES refresh geometry before destroy (sanity check the spy setup itself is meaningful)', () => {
        jest.useFakeTimers();
        const instance = makeInstance();
        const wrapperSpy = jest.spyOn(instance, 'ensureFieldWrapper');
        const widthSpy = jest.spyOn(instance, 'constrainAutocompleteMenuWidth');
        wrapperSpy.mockClear();
        widthSpy.mockClear();

        $(window).trigger('resize');
        jest.advanceTimersByTime(200);

        expect(wrapperSpy).toHaveBeenCalled();
        expect(widthSpy).toHaveBeenCalled();
    });

    test('unbinds by function reference, not by namespace alone, so a sibling instance is never at risk', () => {
        // TWO-30.x.10 round-2 review finding (Vader): `window` is a genuine
        // page-wide singleton; a namespace-only `.off('.twoCompanyWidth')`
        // would remove ANY instance's handler under that name, not just the
        // one being destroyed. Assert the actual call shape.
        const instance = makeInstance();
        const offSpy = jest.spyOn($.fn, 'off');

        instance.destroy();

        const call = offSpy.mock.calls.find(
            (c) => typeof c[0] === 'string' && c[0].includes('twoCompanyWidth')
        );
        expect(call).toBeDefined();
        expect(typeof call[1]).toBe('function');
        expect(call[1]).toBe(instance._widthRefreshHandler);
    });

    test('the search source callback also refreshes the wrapper width, not just the CSS variable', () => {
        // TWO-30.x.10 round-2 review finding (Vader): a field hidden behind a
        // collapsed checkout step at page load measures 0 width, so its
        // wrapper's pinned width is cleared at construction time - and no
        // resize/orientationchange fires just because a later step reveals
        // it. The first keystroke is the next point either measurement can
        // be trusted, so ensureFieldWrapper() has to run alongside the CSS
        // variable refresh already known to run there.
        const instance = makeInstance();
        const field = liveField();
        const ensureSpy = jest.spyOn(instance, 'ensureFieldWrapper');
        ensureSpy.mockClear();

        field.val('Example');
        field.autocomplete('instance').search('Example');

        expect(ensureSpy).toHaveBeenCalled();
    });

    test('the geometry refresh also fires in manual-entry mode, not only normal search', () => {
        // TWO-30.x.10 round-3 review finding (Vader): the manual-entry
        // early-return (`response([])`, no dropdown at all while the buyer
        // types their own company name) sits BELOW both geometry calls in
        // `source`, not above them - confirmed correct by inspection, and
        // pinned here so a future reordering that moved the early-return
        // above them would fail this test rather than regress silently.
        const instance = makeInstance();
        const field = liveField();
        instance._manualEntry = true;
        const ensureSpy = jest.spyOn(instance, 'ensureFieldWrapper');
        const widthSpy = jest.spyOn(instance, 'constrainAutocompleteMenuWidth');
        ensureSpy.mockClear();
        widthSpy.mockClear();

        field.val('Some Manually Typed Name');
        field.autocomplete('instance').search('Some Manually Typed Name');

        expect(ensureSpy).toHaveBeenCalled();
        expect(widthSpy).toHaveBeenCalled();
    });
});

describe('the manual-entry row stays reachable without scrolling (2.4)', () => {
    test('.two-autocomplete-manual-entry resolves to a sticky, opaque row', () => {
        installStylesheet('views/css/two.css');
        const ul = document.createElement('ul');
        ul.className = 'ui-autocomplete';
        const li = document.createElement('li');
        li.className = 'two-autocomplete-manual-entry';
        ul.appendChild(li);
        document.body.appendChild(ul);

        const style = getComputedStyle(li);
        expect(style.position).toBe('sticky');
        expect(style.bottom).toBe('0px');
        // Opaque, not transparent/none - a sticky row over scrolling
        // company names needs a real background or the names show through it.
        expect(style.backgroundColor).not.toBe('');
        expect(style.backgroundColor).not.toBe('transparent');
    });

    test('the SAME rule covers the custom (non-jQuery-UI) fallback path’s row', () => {
        installStylesheet('views/css/two.css');
        const list = document.createElement('div');
        list.className = 'two-autocomplete-list';
        const row = document.createElement('div');
        row.className = 'two-autocomplete-item two-autocomplete-manual-entry';
        list.appendChild(row);
        document.body.appendChild(list);

        expect(getComputedStyle(row).position).toBe('sticky');
    });

    test('buildManualEntryItem() / withManualEntryRow() are unchanged - still the last row', () => {
        const instance = makeInstance();
        const items = instance.withManualEntryRow([{ label: 'Example Trading Ltd', value: 'Example Trading Ltd' }]);

        expect(items).toHaveLength(2);
        expect(items[1].two_manual_entry).toBe(true);
        expect(items[1].label).toBe(instance.getManualEntryText());
    });
});
