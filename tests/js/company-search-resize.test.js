/**
 * TWO-25326 bug 10. Doug live-tested: the optional fields (invoice email et al)
 * reflow when the window is resized; the company-search control does not.
 *
 * ROOT CAUSE. The optional fields have no JS width anywhere - they are theme
 * `.form-control` inputs at `width: 100%`, so the browser reflows them for free.
 * The company control's wrapper, by contrast, has its width PINNED in pixels by
 * ensureFieldWrapper(), measured from the input's own `outerWidth()`. Once that
 * pin is on, the input - being `width: 100%` of its container, which is now that
 * wrapper - measures the pinned value back. So the resize listener re-runs the
 * measurement on every viewport change, reads the number it wrote last time, and
 * re-pins it: a latch, not a missing listener.
 *
 * jsdom has no layout engine, so `outerWidth()` cannot be measured here. The
 * model below reproduces the ONE property that matters and nothing else: an
 * input whose width is 100% of its container, i.e. the pinned wrapper width when
 * there is one, and the available viewport width when there is not. Under that
 * model the latch is exactly reproducible, and so is its absence.
 */

'use strict';

const { loadCompanySearch, releaseWidgets } = require('./ps-harness');

let TwoCompanySearch;
let $;
let viewportWidth;

/**
 * Model the input as `width: 100%` of whatever box contains it.
 *
 * @param {Object} search live TwoCompanySearch instance
 * @returns {void}
 */
function modelLayout(search) {
    jest.spyOn(search.companyField, 'outerWidth').mockImplementation(function () {
        const wrapper = search.companyField.parent();
        const pinned = wrapper.length ? wrapper.get(0).style.width : '';
        if (pinned) {
            return parseInt(pinned, 10);
        }
        return viewportWidth;
    });
    jest.spyOn(search.companyField, 'outerHeight').mockImplementation(() => 38);
}

function wrapperWidth() {
    const wrapper = document.querySelector('.two-company-field-wrap');
    return wrapper ? wrapper.style.width : null;
}

beforeEach(() => {
    viewportWidth = 600;
    const loaded = loadCompanySearch();
    $ = loaded.$;
    TwoCompanySearch = loaded.TwoCompanySearch;
    global.window.twopayment = { i18n: {} };
    document.body.innerHTML = "<div class='form-group'>"
        + "<input type='text' name='company' value='' />"
        + "<input type='hidden' name='companyid' value='' />"
        + '</div>';
});

afterEach(() => {
    releaseWidgets($);
    delete global.window.twopayment;
    document.body.innerHTML = '';
});

describe('the company-search control follows the viewport', () => {
    test('a narrower viewport re-pins the wrapper narrower', () => {
        const search = new TwoCompanySearch({ companyFieldSelector: "input[name='company']" });
        modelLayout(search);
        search.ensureFieldWrapper();
        expect(wrapperWidth()).toBe('600px');

        // The buyer drags the window narrower. Before the fix the measurement
        // read the 600px pin straight back and the control stayed 600px wide
        // inside a 320px viewport - the defect Doug reported.
        viewportWidth = 320;
        search.ensureFieldWrapper();

        expect(wrapperWidth()).toBe('320px');
    });

    test('a wider viewport re-pins the wrapper wider', () => {
        const search = new TwoCompanySearch({ companyFieldSelector: "input[name='company']" });
        modelLayout(search);
        search.ensureFieldWrapper();

        viewportWidth = 980;
        search.ensureFieldWrapper();

        expect(wrapperWidth()).toBe('980px');
    });

    test('the window resize listener is what drives it, debounced', () => {
        const search = new TwoCompanySearch({ companyFieldSelector: "input[name='company']" });
        modelLayout(search);
        search.setupWidthRefreshListener();
        search.ensureFieldWrapper();
        expect(wrapperWidth()).toBe('600px');

        jest.useFakeTimers();
        try {
            viewportWidth = 400;
            $(window).trigger('resize');
            // Debounced: nothing yet.
            expect(wrapperWidth()).toBe('600px');
            jest.advanceTimersByTime(200);
            expect(wrapperWidth()).toBe('400px');
        } finally {
            jest.useRealTimers();
        }
    });

    test('an unmeasurable field clears the pin rather than keeping a stale one', () => {
        const search = new TwoCompanySearch({ companyFieldSelector: "input[name='company']" });
        modelLayout(search);
        search.ensureFieldWrapper();
        expect(wrapperWidth()).toBe('600px');

        // A collapsed checkout step measures 0.
        viewportWidth = 0;
        search.ensureFieldWrapper();

        expect(wrapperWidth()).toBe('');
    });
});
