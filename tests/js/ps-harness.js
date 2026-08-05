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
/**
 * Load the shared company-number display helper (TWO-25326 §12).
 *
 * Registered at a lower priority than every module that renders a number
 * (twopayment.php), so on a real page it is always in place before them. The
 * loaders below mirror that: the modules call `window.TwoCompanyNumber`
 * unguarded, exactly as they do in the browser, so nothing here may load them
 * without it.
 *
 * @returns {Object} the helper
 */
function loadCompanyNumber() {
    loadScript('views/js/modules/TwoCompanyNumber.js');
    const helper = global.window.TwoCompanyNumber;
    if (!helper || typeof helper.forDisplay !== 'function') {
        throw new Error('harness: TwoCompanyNumber was not exported onto window');
    }
    return helper;
}

function loadCompanySearch() {
    const $ = installJQuery();
    const bus = installPrestashopBus();
    loadCompanyNumber();
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
 * Load TwoOrderIntent as a <script> tag would.
 *
 * No jQuery/bus setup like loadCompanySearch(): buildCompanyIntentMessage()
 * (TWO-25326 §7.3) only reads window.twopayment, so a bare load is enough for
 * testing the sentence-building logic in isolation.
 *
 * @returns {Function} the TwoOrderIntent class
 */
function loadOrderIntent() {
    loadCompanyNumber();
    loadScript('views/js/modules/TwoOrderIntent.js');
    const TwoOrderIntent = global.window.TwoOrderIntent;
    if (typeof TwoOrderIntent !== 'function') {
        throw new Error('harness: TwoOrderIntent was not exported onto window');
    }
    return TwoOrderIntent;
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
        // Callers use BOTH jQuery ajax styles: `success`/`error` settings, and
        // the chained `.done()`/`.fail()` the real jqXHR promise exposes
        // (TwoOrderIntent.collectFormData's session-company read is the second
        // kind). The stub has to honour whichever a call site used, or a test
        // "fails" on the shape of the plumbing rather than on the behaviour.
        const doneHandlers = [];
        const failHandlers = [];
        const record = {
            settings: settings,
            url: settings.url,
            aborted: false,
            /** Resolve as HTTP 200 with `data`. */
            succeed: function (data) {
                if (typeof settings.success === 'function') {
                    settings.success(data, 'success', record.xhr);
                }
                doneHandlers.slice().forEach(function (handler) {
                    handler(data, 'success', record.xhr);
                });
            },
            /** Resolve as a failure. `status` is jQuery's textStatus. */
            fail: function (status, error) {
                if (typeof settings.error === 'function') {
                    settings.error(record.xhr, status, error || status);
                }
                failHandlers.slice().forEach(function (handler) {
                    handler(record.xhr, status, error || status);
                });
            }
        };
        record.xhr = {
            done: function (handler) {
                doneHandlers.push(handler);
                return record.xhr;
            },
            fail: function (handler) {
                failHandlers.push(handler);
                return record.xhr;
            },
            abort: function () {
                record.aborted = true;
                // jQuery reports an aborted request through the error handler
                // with textStatus 'abort', synchronously. Reproducing that is
                // load-bearing: searchCompanies() bumps its sequence BEFORE
                // aborting precisely so this re-entrant call sees a stale
                // sequence.
                record.fail('abort', 'abort');
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
    // Both nodes the widget has ever been bound to: the company field on the
    // pre-TWO-25326 architecture, and the panel's query field since. Sweeping
    // both keeps this callable from every suite without each one having to
    // know which era it is testing.
    $("input[name='company'], .two-company-dropdown__query").each(function () {
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

/**
 * Render the SHIPPED payment-tile template into the document.
 *
 * Deliberately reads `views/templates/hook/paymentinfo.tpl` and strips Smarty
 * rather than hand-writing a copy of the block under test. A hand-written
 * fixture would keep passing after someone renamed a class or deleted a slot in
 * the real template - which is precisely the failure a tile test exists to
 * catch, since nothing else in the suite reads that file.
 *
 * The transform is intentionally crude and covers only what this template uses:
 * comments, `{l s='...'}` translations, `{$var|modifiers}` output, and the one
 * `{if}...{/if}` block (the optional-fields loop, dropped wholesale - it is
 * server-gated and not what these tests are about).
 *
 * Call AFTER buildAddressForm(), which replaces document.body wholesale; this
 * appends.
 *
 * @returns {HTMLElement} the `.two-payment-container` that was appended
 */
function buildPaymentTile() {
    return renderPaymentTile(null);
}

/**
 * The same tile, with the SERVER-side sole-trader answer resolved (TWO-25326
 * bug 9, round 3).
 *
 * buildPaymentTile() leaves every `{if}` block stripped, which reproduces a
 * render where Smarty gave no answer - the fallback path where TwoSoleTrader
 * still has to fetch. This one evaluates the `$sole_trader_available` blocks
 * instead, so the markup under test is the markup a real shop serves: chips
 * already in the toggle, `data-two-built`, and the container's display and
 * data- attributes set. Rendered FROM the template rather than hand-written in
 * the test, so a template change that breaks the handover breaks these tests.
 *
 * @param {boolean} available the registry answer Smarty was given
 * @param {string} countryIso the country that answer is about
 *
 * @returns {HTMLElement} the `.two-payment-container` that was appended
 */
function buildPaymentTileWithSoleTraderAnswer(available, countryIso) {
    return renderPaymentTile({ available: !!available, country: countryIso });
}

/**
 * @param {{available: boolean, country: string}|null} soleTrader null = leave
 *        the sole-trader `{if}` blocks unevaluated, as buildPaymentTile() does
 * @returns {HTMLElement}
 */
function renderPaymentTile(soleTrader) {
    const tpl = fs.readFileSync(
        path.join(REPO_ROOT, 'views/templates/hook/paymentinfo.tpl'),
        'utf8'
    );
    let html = tpl.replace(/\{\*[\s\S]*?\*\}/g, '');
    if (soleTrader) {
        // Both shapes the template uses, if/else first: an if/else block's own
        // `{/if}` would otherwise terminate the plain-`{if}` pattern early and
        // leave `{else}...` in the output.
        html = html
            .replace(
                /\{if \$sole_trader_available\}([\s\S]*?)\{else\}([\s\S]*?)\{\/if\}/g,
                soleTrader.available ? '$1' : '$2'
            )
            .replace(
                /\{if \$sole_trader_available\}([\s\S]*?)\{\/if\}/g,
                soleTrader.available ? '$1' : ''
            )
            .replace(/\{\$sole_trader_country\|[^}]*\}/g, soleTrader.country);
    }
    html = html
        .replace(/\{if[\s\S]*?\{\/if\}/g, '')
        .replace(/\{l\s+s='([^']*)'[^}]*\}/g, '$1')
        .replace(/\{\$[^}]*\}/g, '');
    const holder = global.document.createElement('div');
    holder.innerHTML = html;
    const container = holder.querySelector('.two-payment-container');
    if (!container) {
        throw new Error('harness: paymentinfo.tpl produced no .two-payment-container');
    }
    // The `{if}` strip is non-greedy, so each `{if}...{/if}` block in the
    // template (the tile-location company-search mount, TWO-25326 §7.1, and
    // the optional-fields block below it) is stripped independently as long
    // as they do not nest or overlap.
    global.document.body.appendChild(container);
    return container;
}

/**
 * The panel's own controls (TWO-25326 §1/§2).
 *
 * Every test that used to type into `input[name='company']` types HERE now:
 * the company-name field stopped being the search box when the anchored
 * dropdown grew a query field of its own.
 *
 * Resolved from the live DOM on each call, never cached - PrestaShop replaces
 * the address form wholesale on `updatedAddressForm`, and a cached node is the
 * exact staleness these tests exist to catch.
 *
 * @returns {{panel: Object, query: Object, results: Object, notListed: Object}}
 *   jQuery objects; any may be empty if the panel has not been built.
 */
function panelParts() {
    const $ = global.$;
    return {
        panel: $('.two-company-dropdown'),
        query: $('.two-company-dropdown__query'),
        results: $('.two-company-dropdown__results'),
        notListed: $('.two-company-not-listed')
    };
}

/**
 * Is this element rendered?
 *
 * NOT `jQuery(':visible')`. jsdom performs no layout, so every element reports
 * `offsetWidth === 0` and jQuery's `:visible`/`:hidden` therefore answer
 * "hidden" for the entire document - a test written on them passes or fails
 * for reasons unrelated to the code. Computed `display` IS resolved by jsdom,
 * from both the stylesheet cascade and inline styles, so that is what these
 * suites assert on.
 *
 * @param {Object|Element} el jQuery object or raw node
 * @returns {boolean}
 */
function shown(el) {
    const node = el && el.jquery ? el.get(0) : el;
    if (!node || !global.document.contains(node)) {
        return false;
    }
    let current = node;
    while (current && current.nodeType === 1) {
        if (current.hasAttribute('hidden')) {
            return false;
        }
        if (global.window.getComputedStyle(current).display === 'none') {
            return false;
        }
        current = current.parentElement;
    }
    return true;
}

/**
 * Open the dropdown the way a buyer does: a real mousedown on the
 * company-name field (§1 - focus alone must NOT open it).
 *
 * @returns {Object} the query field, as a jQuery object
 */
function openPanel() {
    const $ = global.$;
    $("input[name='company']").trigger('mousedown');
    return panelParts().query;
}

/**
 * Type into the panel's query field and run the widget's 300ms debounce out.
 *
 * Jest fake timers are NOT assumed: callers that want to observe the
 * pre-debounce state install their own. This drives the real `input` event so
 * both render paths (jQuery UI's widget and the fallback engine) see it.
 *
 * @param {string} value
 * @returns {void}
 */
function typeQuery(value) {
    const query = panelParts().query;
    if (!query.length) {
        throw new Error('harness: no query field - was the panel opened?');
    }
    query.val(value);
    query.get(0).dispatchEvent(new global.window.Event('input', { bubbles: true }));
}

/**
 * The rows currently rendered in the panel's results host, as plain text.
 *
 * Covers both render paths: jQuery UI appends its own `<ul>` into the host,
 * and the fallback engine builds one with the same shape deliberately.
 *
 * @returns {Array<string>}
 */
function resultTexts() {
    const host = panelParts().results;
    if (!host.length) {
        return [];
    }
    return host.find('li').map(function () {
        return global.$(this).text();
    }).get();
}

/**
 * Load TwoSoleTrader as a <script> tag would.
 *
 * No jQuery: the module is plain DOM + `fetch` + MutationObserver, all of which
 * jsdom supplies (bar `fetch`, which every test stubs because the availability
 * round trip's timing is the thing under test).
 *
 * @returns {Function} the TwoSoleTrader class
 */
function loadSoleTrader() {
    // TWO-25326 §12, review round 2: applyBuyer()'s status display now calls
    // window.TwoCompanyNumber.forDisplay() unguarded, exactly as the real page
    // does (twopayment.php loads it at a lower priority than every module that
    // renders a number, TwoSoleTrader included) - so it must be in place
    // before this module loads here too.
    loadCompanyNumber();
    loadScript('views/js/modules/TwoSoleTrader.js');
    const TwoSoleTrader = global.window.TwoSoleTrader;
    if (typeof TwoSoleTrader !== 'function') {
        throw new Error('harness: TwoSoleTrader was not exported onto window');
    }
    return TwoSoleTrader;
}

module.exports = {
    REPO_ROOT: REPO_ROOT,
    buildPaymentTile: buildPaymentTile,
    buildPaymentTileWithSoleTraderAnswer: buildPaymentTileWithSoleTraderAnswer,
    loadCompanyNumber: loadCompanyNumber,
    loadSoleTrader: loadSoleTrader,
    countGifFrames: countGifFrames,
    releaseWidgets: releaseWidgets,
    flushPromises: flushPromises,
    loadCompanySearch: loadCompanySearch,
    loadOrderIntent: loadOrderIntent,
    loadScript: loadScript,
    installStylesheet: installStylesheet,
    buildAddressForm: buildAddressForm,
    replaceAddressForm: replaceAddressForm,
    stubAjax: stubAjax,
    callbackRecorder: callbackRecorder,
    panelParts: panelParts,
    shown: shown,
    openPanel: openPanel,
    typeQuery: typeQuery,
    resultTexts: resultTexts
};
