/**
 * TWO-30.x.10. Regression tests for layout bugs found in checkout, on top of
 * the widget already confirmed working (PR two-inc/prestashop-plugin#128):
 * jQuery UI's own `_resizeMenu` sizes the dropdown to whichever is WIDER, the
 * field or the longest label, so it needs to be explicitly clamped (2.1/2.2).
 *
 * TWO-25326 removed the reveal chip and manual-entry row those bugs also
 * touched (2.3/2.4); their reachability behaviour is now pinned in
 * company-search-dropdown.test.js. Only the width work (2.1/2.2) survives
 * here, now anchored to the panel's query field rather than
 * `input[name='company']`.
 *
 * jsdom computes no real layout (offsetWidth/getBoundingClientRect are 0
 * regardless of CSS), so these tests pin the DOM structure and computed-style
 * values the width fix depends on; the actual pixel result was verified live
 * against https://prestashop-dev.staging.two.inc instead.
 */

'use strict';

const {
    loadCompanySearch,
    buildAddressForm,
    replaceAddressForm,
    stubAjax,
    releaseWidgets,
    installStylesheet,
    panelParts,
    openPanel
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

// TWO-25326 §1: the panel's query field, not `input[name='company']`. Resolves
// without the panel being open - setupAutocomplete() builds it at instance
// creation.
function widgetField() {
    return panelParts().query;
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

    test('the org-id hint lands inside the field wrapper, and nothing can cover it', () => {
        // TWO-25326 removed the chip (its opaque background painted over the
        // hint, 2.3); absence is asserted explicitly since re-adding a chip is
        // exactly how that regression would come back.
        makeInstance();

        const wrapper = liveField().parent();

        expect(wrapper.find('.two-company-id-hint').length).toBe(1);
        expect($('.two-company-search-reveal')).toHaveLength(0);
    });

    test('the hidden organisation-number field is NOT pulled into the wrapper', () => {
        // createOrganizationField() runs before ensureFieldWrapper() in init()
        // and inserts a plain sibling - wrap() only wraps the selected element
        // itself.
        makeInstance();

        const wrapper = liveField().parent();

        expect(wrapper.find("input[name='companyid']").length).toBe(0);
        expect(wrapper.siblings("input[name='companyid']").length).toBe(1);
    });
});

describe('the dropdown width CSS variable (2.1)', () => {
    test('constrainAutocompleteMenuWidth() publishes the field width on the instance own panel', () => {
        const instance = makeInstance();
        openPanel();
        jest.spyOn($.fn, 'outerWidth').mockReturnValue(281);

        instance.constrainAutocompleteMenuWidth();

        expect(instance._dropdown.get(0).style.getPropertyValue('--two-company-search-width'))
            .toBe('281px');
        // The page root is where it used to live, and where a second control
        // would have been able to read this one's measurement.
        expect(document.documentElement.style.getPropertyValue('--two-company-search-width'))
            .toBe('');
    });

    test('a falsy width (e.g. a detached/hidden field) CLEARS the property rather than leaving a stale value', () => {
        const instance = makeInstance();
        openPanel();
        instance._dropdown.get(0).style.setProperty('--two-company-search-width', '999px');
        jest.spyOn($.fn, 'outerWidth').mockReturnValue(0);

        instance.constrainAutocompleteMenuWidth();

        expect(instance._dropdown.get(0).style.getPropertyValue('--two-company-search-width'))
            .toBe('');
    });

    test('a missing/destroyed field is a no-op, not a throw', () => {
        const instance = makeInstance();
        instance.companyField = $();

        expect(() => instance.constrainAutocompleteMenuWidth()).not.toThrow();
    });

    test('destroy() takes the property out of the document along with the panel', () => {
        const instance = makeInstance();
        openPanel();
        jest.spyOn($.fn, 'outerWidth').mockReturnValue(281);
        instance.constrainAutocompleteMenuWidth();
        const panel = instance._dropdown.get(0);

        instance.destroy();

        expect(document.contains(panel)).toBe(false);
    });

    test('the widget gets the scoping marker class, not left as bare .ui-autocomplete', () => {
        // TWO-30.x.10 (Han): `.ui-autocomplete` is jQuery UI's own
        // un-namespaced class, shared by any other autocomplete widget on the
        // page.
        makeInstance();

        const widget = widgetField().autocomplete('widget');

        expect(widget.hasClass('two-company-autocomplete-menu')).toBe(true);
    });

    test('a throwing autocomplete("widget") degrades to an unclamped dropdown, not a dead company search', () => {
        // TWO-30.x.10 (Han): cosmetic clamp, not core search - an uncaught
        // throw here would escape setupAutocomplete()/init()/the ctor since
        // TwoCheckoutManager.initializeCompanySearch() has no surrounding
        // try/catch.
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
        // (raw value reported), so this can't assert the resolved pixel
        // figure - it proves instead that the rule keys off the variable and
        // is scoped to the marker class, not applied to every jQuery UI
        // autocomplete on the page.
        installStylesheet('views/css/two.css');
        const bareUl = document.createElement('ul');
        bareUl.className = 'ui-autocomplete';
        document.body.appendChild(bareUl);
        const scopedUl = document.createElement('ul');
        scopedUl.className = 'ui-autocomplete two-company-autocomplete-menu';
        document.body.appendChild(scopedUl);

        // jsdom reports an unmatched property as '' rather than browser's
        // 'none' - what matters is the two elements resolve DIFFERENTLY.
        expect(getComputedStyle(bareUl).maxWidth).toBe('');
        const scopedMaxWidth = getComputedStyle(scopedUl).maxWidth;
        expect(scopedMaxWidth).toContain('var(--two-company-search-width');
        expect(scopedMaxWidth).toContain('320px');
    });
});

describe('the field wrapper width is pinned explicitly, not left to block auto-sizing (2.2 hardening)', () => {
    test('ensureFieldWrapper() sets the wrapper width to the field\'s own outerWidth()', () => {
        // TWO-30.x.10 (Han + Vader): a `display:block` wrapper with no
        // padding only matches input width when the input already fills its
        // container - false when the theme gives the field its own narrower
        // intrinsic width.
        const instance = makeInstance();
        jest.spyOn($.fn, 'outerWidth').mockReturnValue(240);

        instance.ensureFieldWrapper();

        expect(liveField().parent().css('width')).toBe('240px');
    });

    test('a falsy width clears a previously-set inline width rather than leaving it stale', () => {
        // jQuery's `.css('width')` reads the computed style, which jsdom
        // always resolves to '0px' - the inline style itself proves whether a
        // stale value was left behind.
        const instance = makeInstance();
        liveField().parent().get(0).style.width = '999px';
        jest.spyOn($.fn, 'outerWidth').mockReturnValue(0);

        instance.ensureFieldWrapper();

        expect(liveField().parent().get(0).style.width).toBe('');
    });
});

describe('the width-refresh listener on resize/orientationchange (2.1/2.2 hardening)', () => {
    test('is bound at most once per instance across repeated setupAutocomplete() calls', () => {
        // makeInstance() itself runs init() -> setupAutocomplete(), the first
        // bind - so the spy must be in place before construction to see it.
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
        // TWO-30.x.10 (Yoda): a bare "does not throw" assertion passes
        // whether or not the listener was removed, since the handler itself
        // never throws.
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
        // TWO-30.x.10 (Vader): `window` is page-wide - a namespace-only
        // `.off('.twoCompanyWidth')` would remove ANY instance's handler
        // under that name, not just this one.
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
        // TWO-30.x.10 (Vader): a field hidden behind a collapsed checkout
        // step measures 0 width at construction, and no resize fires when a
        // later step reveals it - the first keystroke is the next chance to
        // remeasure.
        const instance = makeInstance();
        const query = widgetField();
        const ensureSpy = jest.spyOn(instance, 'ensureFieldWrapper');
        ensureSpy.mockClear();

        query.val('Example');
        query.autocomplete('instance').search('Example');

        expect(ensureSpy).toHaveBeenCalled();
    });

    test('the geometry refresh also fires in manual-entry mode, not only normal search', () => {
        // TWO-30.x.10 (Vader): the manual-entry early-return (`response([])`)
        // sits below both geometry calls in `source` - pinned so a future
        // reordering above them fails this test rather than regress silently.
        const instance = makeInstance();
        const query = widgetField();
        instance._manualEntry = true;
        const ensureSpy = jest.spyOn(instance, 'ensureFieldWrapper');
        const widthSpy = jest.spyOn(instance, 'constrainAutocompleteMenuWidth');
        ensureSpy.mockClear();
        widthSpy.mockClear();

        query.val('Some Manually Typed Name');
        query.autocomplete('instance').search('Some Manually Typed Name');

        expect(ensureSpy).toHaveBeenCalled();
        expect(widthSpy).toHaveBeenCalled();
    });
});

describe('the manual-entry route stays reachable without scrolling (2.4, reshaped by TWO-25326 §2)', () => {
    // TWO-25326 §2 took the route out of the scroll container entirely (a
    // real <button>, sibling of it) rather than pinning it to the bottom of
    // the list. Behaviour (tab order, activation) is pinned in
    // company-search-dropdown.test.js; this covers only the layout half.

    test('the scrolling is confined to the results host, which the route out sits outside', () => {
        installStylesheet('views/css/two.css');
        makeInstance();
        const { panel, results, notListed } = panelParts();

        // The panel's one scroll box, and bounded - unbounded height would
        // push the button below the fold on a long result set.
        const resultsStyle = getComputedStyle(results.get(0));
        expect(resultsStyle.overflowY).toBe('auto');
        expect(resultsStyle.maxHeight).toBe('240px');

        // Sits outside the scroll box so it can't be scrolled away from -
        // nested one level deeper since TWO-40's three-chip mode selector,
        // but still outside the results container either way.
        expect(results.get(0).contains(notListed.get(0))).toBe(false);
        expect(notListed.parent().is(panelParts().modeChips)).toBe(true);
        expect(panelParts().modeChips.parent().is(panel)).toBe(true);
        expect(getComputedStyle(notListed.get(0)).overflowY).not.toBe('auto');
    });
});

describe('the org-number hint reserves no space until it has something to say (TWO-25326 §5/§7)', () => {
    // The wrapper's old `padding-bottom` / `--two-company-hint-clearance`
    // pairing was the defect: `top: 100%` on an absolutely positioned child
    // resolves against the containing block's PADDING box, so reserved
    // padding pushed the hint onto the VAT field below rather than making
    // room above it.

    test('the wrapper reserves nothing and defines no clearance constant', () => {
        installStylesheet('views/css/two.css');
        makeInstance();
        const wrapper = liveField().parent().get(0);
        const style = getComputedStyle(wrapper);

        // jsdom reports an unmatched property as '' rather than the initial
        // value, so both spellings of "nothing reserved" are accepted.
        expect(['', '0px']).toContain(style.paddingBottom);
        expect(style.getPropertyValue('--two-company-hint-clearance')).toBe('');
    });

    test('the hint takes its own height in normal flow, and none at all while empty', () => {
        installStylesheet('views/css/two.css');
        const instance = makeInstance();
        const hint = $('.two-company-id-hint');

        // Empty: no line box, so the address form is the same height as any
        // other row when nothing's selected.
        expect(getComputedStyle(hint.get(0)).display).toBe('none');

        instance.setCompanyIdHint('12345678');

        // Populated: an in-flow block, so it can't overlap the field below by
        // construction.
        const style = getComputedStyle(hint.get(0));
        expect(style.display).toBe('block');
        expect(style.position).not.toBe('absolute');
        expect(style.textAlign).toBe('end');
    });
});
