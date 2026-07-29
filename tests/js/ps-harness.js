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
    // jquery's CommonJS entry point binds itself to `global.window` when a
    // document is present, which under jest-environment-jsdom it is.
    const jQuery = require('jquery');
    global.$ = jQuery;
    global.jQuery = jQuery;
    global.window.$ = jQuery;
    global.window.jQuery = jQuery;
    // jquery-ui's distributed files are AMD-or-browser-globals; there is no
    // CommonJS branch, so under Jest each one falls through to
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
 * `id_country` carries `data-iso-code`, which is the first of the five country
 * resolution strategies in getCurrentCountry() and the only deterministic one
 * (the last is a navigator.language fallback).
 *
 * @param {Object} [options]
 * @param {string} [options.country] ISO code for the selected option
 * @returns {void}
 */
function buildAddressForm(options) {
    const opts = options || {};
    const country = opts.country || 'GB';
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
        '      <option value="17" data-iso-code="' + country + '" selected>Selected country</option>',
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

module.exports = {
    REPO_ROOT: REPO_ROOT,
    loadCompanySearch: loadCompanySearch,
    loadScript: loadScript,
    buildAddressForm: buildAddressForm,
    replaceAddressForm: replaceAddressForm,
    stubAjax: stubAjax,
    callbackRecorder: callbackRecorder
};
