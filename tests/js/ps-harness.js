/**
 * TWO-25239. Browser-JS-in-Jest harness for the PrestaShop module.
 *
 * The module's JS is not AMD and not ESM: `views/js/modules/*.js` are plain
 * classic scripts that declare a class and hang it off `window`, loaded by a
 * Smarty template into a page where jQuery, jQuery UI and PrestaShop's own
 * `prestashop` event bus are already globals. There is nothing to `require()`.
 *
 * So rather than mock the browser, this harness assembles the real one:
 *
 *   - jsdom (Jest's `testEnvironment`) supplies document/window,
 *   - the REAL jQuery and the REAL jQuery UI autocomplete widget are loaded
 *     onto that window,
 *   - `prestashop` is a small stub, because it is an event bus PrestaShop
 *     itself supplies and has no npm distribution,
 *   - the module source is then evaluated in global scope exactly as a
 *     `<script>` tag would evaluate it.
 *
 * Using the real widget rather than a mock is deliberate. Two of the three
 * defects these tests exist to pin are properties OF jQuery UI, not of our
 * code: that the widget bridge reuses an already-initialised instance instead
 * of building a fresh one (so a `_renderItem` wrapper applied on every setup
 * nests), and that it only clears `ui-autocomplete-loading` when a search's
 * `response()` callback actually runs (so a dropped callback leaks the
 * spinner). A hand-written mock would have to reproduce both behaviours
 * correctly to catch either bug, which is precisely the assumption that let
 * them ship.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '..', '..');

/**
 * Put the real jQuery + jQuery UI autocomplete on the jsdom window.
 *
 * @returns {Function} the jQuery instance bound to the current jsdom window
 */
function installJQuery() {
    // jquery's UMD head keys on `global.document` — present under
    // jest-environment-jsdom — and calls its factory with `noGlobal = true`, so
    // it deliberately does NOT assign window.$ / window.jQuery itself. The four
    // assignments below are therefore load-bearing, not tidying: without them
    // the module source's free `$` resolves to nothing. Do not remove them.
    const jQuery = require('jquery');
    global.$ = jQuery;
    global.jQuery = jQuery;
    global.window.$ = jQuery;
    global.window.jQuery = jQuery;
    // Every jquery-ui file the harness needs is AMD-or-browser-globals with no
    // CommonJS branch (its bundled jquery-color vendor copy does have one, but
    // nothing here loads it), so under Jest each one falls through to
    // `factory(jQuery)` and picks up the global set above. That branch does NOT
    // pull a file's own dependencies, so they have to be required in
    // dependency order by hand — exactly the load order a theme's <script>
    // tags would produce.
    require('jquery-ui/ui/jquery-patch');
    require('jquery-ui/ui/version');
    require('jquery-ui/ui/widget');
    require('jquery-ui/ui/position');
    require('jquery-ui/ui/keycode');
    require('jquery-ui/ui/labels');
    require('jquery-ui/ui/unique-id');
    require('jquery-ui/ui/widgets/menu');
    require('jquery-ui/ui/widgets/autocomplete');
    if (typeof jQuery.fn.autocomplete !== 'function' || !jQuery.ui.autocomplete) {
        throw new Error('harness: jQuery UI autocomplete did not register');
    }
    return jQuery;
}

/**
 * Load one of the module's REAL stylesheets into the jsdom document.
 *
 * The in-field company-search spinner is a loader GIF painted by CSS alone,
 * keyed off the loading class the module puts on the input, so a test that only
 * asserted on that class would pass with a spinner that never appears - and did:
 * an unscoped `!important` rule further down the stylesheet out-ranked the scoped
 * one and painted a white background over the field, with the class set correctly
 * throughout. jsdom applies the cascade for selectors of this shape, so reading
 * `getComputedStyle(...)` exercises the rule that actually ships.
 *
 * Not automatic: only the tests that assert on rendered appearance need it, and
 * jsdom's CSS parser drops declarations it does not understand - notably the
 * multi-value `background-position` form, which resolves to an empty string here
 * even when the rule is correct, so do not assert on it.
 *
 * @param {string} relPath repo-relative path, e.g. 'views/css/two.css'
 * @returns {HTMLStyleElement} the injected <style>
 */
function installStylesheet(relPath) {
    const css = fs.readFileSync(path.join(REPO_ROOT, relPath), 'utf8');
    const style = global.document.createElement('style');
    style.textContent = css;
    global.document.head.appendChild(style);
    return style;
}

/**
 * Minimal stand-in for PrestaShop's front-office event bus.
 *
 * Note the deliberate absence of an `off`: the real bus has none either, which
 * is the entire reason TwoCompanySearch has to defend itself with a
 * `_destroyed` flag instead of unregistering its handler.
 *
 * @returns {{on: Function, emit: Function, handlerCount: Function}}
 */
function installPrestashopBus() {
    const handlers = {};
    const bus = {
        on: function (event, handler) {
            (handlers[event] = handlers[event] || []).push(handler);
        },
        emit: function (event, payload) {
            (handlers[event] || []).slice().forEach(function (handler) {
                handler(payload);
            });
        },
        handlerCount: function (event) {
            return (handlers[event] || []).length;
        }
    };
    global.prestashop = bus;
    global.window.prestashop = bus;
    return bus;
}

/**
 * Evaluate a module source file the way a <script> tag would.
 *
 * `indirectEval` keeps evaluation in global scope, so the file's top-level
 * `class Foo {}` and its `window.Foo = Foo` both behave as they do in the
 * browser, and its free references to `$` / `prestashop` resolve to the
 * globals installed above.
 *
 * @param {string} relPath repo-relative path, e.g. 'views/js/modules/X.js'
 */
function loadScript(relPath) {
    const src = fs.readFileSync(path.join(REPO_ROOT, relPath), 'utf8');
    const indirectEval = eval;
    indirectEval(src);
}

/**
 * Load TwoCompanySearch with jQuery, jQuery UI and the event bus in place.
 *
 * @returns {{TwoCompanySearch: Function, $: Function, bus: Object}}
 */
function loadCompanySearch() {
    const $ = installJQuery();
    const bus = installPrestashopBus();
    loadScript('views/js/modules/TwoCompanySearch.js');
    const TwoCompanySearch = global.window.TwoCompanySearch;
    if (typeof TwoCompanySearch !== 'function') {
        throw new Error('harness: TwoCompanySearch was not exported onto window');
    }
    // The result cache is class-static and therefore survives instances by
    // design. It must not survive TESTS.
    TwoCompanySearch._resultCache.clear();
    return { TwoCompanySearch: TwoCompanySearch, $: $, bus: bus };
}

/**
 * The subset of the PrestaShop address form the module reads and writes.
 *
 * `id_country` carries `data-iso-code`, which is the first of getCurrentCountry()'s
 * three resolution strategies — after it come the server-supplied
 * `window.twopayment.countries` id-to-ISO map and then the option's visible text.
 * Pass `country: null` to build the select WITHOUT the attribute, which is how a
 * test reaches the later strategies and the unresolvable case.
 *
 * @param {Object} [options]
 * @param {?string} [options.country] ISO code for the selected option; null omits
 *        the `data-iso-code` attribute entirely
 * @param {string} [options.countryId] `id_country` value, default '17'
 * @param {string} [options.countryText] option text, default 'Selected country'
 * @returns {void}
 */
function buildAddressForm(options) {
    const opts = options || {};
    const country = 'country' in opts ? opts.country : 'GB';
    const countryId = opts.countryId || '17';
    const countryText = opts.countryText || 'Selected country';
    const isoAttr = country ? ' data-iso-code="' + country + '"' : '';
    document.body.innerHTML = [
        '<div class="js-address-form">',
        '  <form data-id-address="7">',
        "    <input type='text' name='company' value='' />",
        "    <input type='text' name='dni' value='' />",
        "    <input type='text' name='vat_number' value='' />",
        "    <input type='text' name='address1' value='' />",
        "    <input type='text' name='postcode' value='' />",
        "    <input type='text' name='city' value='' />",
        "    <select name='id_country'>",
        '      <option value="' + countryId + '"' + isoAttr + ' selected>' + countryText + '</option>',
        '    </select>',
        '  </form>',
        '</div>'
    ].join('\n');
}

/**
 * Replace the address form's DOM, as PrestaShop does on `updatedAddressForm`.
 *
 * The company input the caller held a jQuery object for is detached by this;
 * a fresh node with the same selector takes its place. That substitution is
 * what turned a harmless zombie instance into one that wrote the selected
 * company's organisation number into a detached field.
 *
 * @param {Object} [options] forwarded to buildAddressForm
 * @returns {HTMLElement} the new live company input
 */
function replaceAddressForm(options) {
    buildAddressForm(options);
    return document.querySelector("input[name='company']");
}

/**
 * Replace `$.ajax` with a recorder that hands each call's handlers back so a
 * test can resolve them in whatever order it wants.
 *
 * Real network timing is the thing under test here — out-of-order responses,
 * aborts, timeouts — so driving the handlers explicitly is the point, not a
 * shortcut.
 *
 * @param {Function} $ jQuery instance
 * @returns {{calls: Array, last: Function, restore: Function}}
 */
function stubAjax($) {
    const original = $.ajax;
    const calls = [];
    $.ajax = function (settings) {
        const record = {
            settings: settings,
            url: settings.url,
            aborted: false,
            /** Resolve as HTTP 200 with `data`. */
            succeed: function (data) {
                settings.success(data, 'success', record.xhr);
            },
            /** Resolve as a failure. `status` is jQuery's textStatus. */
            fail: function (status, error) {
                settings.error(record.xhr, status, error || status);
            }
        };
        record.xhr = {
            abort: function () {
                record.aborted = true;
                // jQuery reports an aborted request through the error handler
                // with textStatus 'abort', synchronously. Reproducing that is
                // load-bearing: searchCompanies() bumps its sequence BEFORE
                // aborting precisely so this re-entrant call sees a stale
                // sequence.
                settings.error(record.xhr, 'abort', 'abort');
            }
        };
        calls.push(record);
        return record.xhr;
    };
    return {
        calls: calls,
        last: function () {
            return calls[calls.length - 1];
        },
        restore: function () {
            $.ajax = original;
        }
    };
}

/**
 * Collect every `responseCallback` invocation from searchCompanies().
 *
 * The invariant these tests are built around is that this array has length
 * exactly 1 per search, on every path. Both zero (spinner never cleared) and
 * two (a superseded result overwriting a live one) are bugs.
 *
 * @returns {{fn: Function, calls: Array}}
 */
function callbackRecorder() {
    const calls = [];
    return {
        calls: calls,
        fn: function (results, meta) {
            calls.push({ results: results, meta: meta === undefined ? null : meta });
        }
    };
}

/**
 * Release every autocomplete widget bound to a company field.
 *
 * jQuery UI binds document-level handlers in `_create` that wiping
 * `document.body.innerHTML` does not unbind, so an abandoned widget keeps
 * listening for the rest of the test file. Call this from `afterEach` BEFORE
 * clearing the DOM: without it the handlers accumulate one per test, and the
 * first test that dispatches a document-level event inherits `close()` calls
 * from every zombie — which presents as an order-dependent flake rather than
 * as the leak it is.
 *
 * @param {Function} $ jQuery instance
 * @returns {void}
 */
function releaseWidgets($) {
    $("input[name='company']").each(function () {
        const field = $(this);
        if (field.hasClass('ui-autocomplete-input')) {
            field.autocomplete('destroy');
        }
    });
}

/**
 * Drain the microtask queue.
 *
 * One tick is enough for `fetchCompanyDetails().then(...)`, but the `.finally()`
 * that triggers the order-intent recheck lands three ticks later. Anything
 * asserting past the address fill should await this rather than a bare
 * `Promise.resolve()`, which passes vacuously.
 *
 * Chained microtasks rather than `setImmediate`/`setTimeout`: jsdom provides no
 * `setImmediate`, and a real timer would not fire under `jest.useFakeTimers()`.
 *
 * @returns {Promise<void>}
 */
async function flushPromises() {
    for (let i = 0; i < 8; i += 1) {
        await Promise.resolve();
    }
}

/**
 * Count the image frames in a GIF by walking its block structure.
 *
 * Counting raw 0x2C bytes across the whole file does not work: that value
 * occurs freely inside the colour tables and the LZW-compressed pixel data, so
 * a genuinely single-frame GIF reports plenty of 'frames' and an
 * animation assertion built on the scan can never fail. The structure has to
 * be walked so only bytes actually sitting in an image-descriptor position
 * count.
 *
 * @param {Buffer} bytes the whole GIF file
 * @returns {number} the number of image descriptors in the stream
 */
function countGifFrames(bytes) {
    // Header (6) + logical screen descriptor (7).
    let at = 13;

    // Global colour table, when the descriptor's packed field says there is one.
    const packed = bytes[10];
    if (packed & 0x80) {
        at += 3 * Math.pow(2, (packed & 0x07) + 1);
    }

    // Data sub-blocks: a length byte, that many bytes, repeated until a
    // zero-length block terminates the sequence.
    const skipSubBlocks = function () {
        while (at < bytes.length) {
            const size = bytes[at];
            at += 1;
            if (size === 0) {
                return;
            }
            at += size;
        }
    };

    let frames = 0;

    while (at < bytes.length) {
        const block = bytes[at];
        at += 1;

        if (block === 0x3b) {
            // Trailer: end of stream.
            return frames;
        }

        if (block === 0x21) {
            // Extension: a label byte, then sub-blocks.
            at += 1;
            skipSubBlocks();
            continue;
        }

        if (block === 0x2c) {
            frames += 1;
            // Image descriptor: 4x2 bytes of geometry plus a packed field.
            const localPacked = bytes[at + 8];
            at += 9;
            if (localPacked & 0x80) {
                at += 3 * Math.pow(2, (localPacked & 0x07) + 1);
            }
            // LZW minimum code size, then the compressed data sub-blocks.
            at += 1;
            skipSubBlocks();
            continue;
        }

        // Anything else means the walk has lost sync with the stream; stop
        // rather than counting noise.
        break;
    }

    return frames;
}

module.exports = {
    REPO_ROOT: REPO_ROOT,
    countGifFrames: countGifFrames,
    releaseWidgets: releaseWidgets,
    flushPromises: flushPromises,
    loadCompanySearch: loadCompanySearch,
    loadScript: loadScript,
    installStylesheet: installStylesheet,
    buildAddressForm: buildAddressForm,
    replaceAddressForm: replaceAddressForm,
    stubAjax: stubAjax,
    callbackRecorder: callbackRecorder
};
