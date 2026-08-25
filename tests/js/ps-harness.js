/**
 * TWO-25239. Browser-JS-in-Jest harness for the PrestaShop module.
 *
 * The module's JS is plain classic scripts with no AMD/ESM, loaded into a
 * page where jQuery, jQuery UI and PrestaShop's `prestashop` event bus are
 * already globals - so this harness assembles the real browser (jsdom + real
 * jQuery/jQuery UI + a `prestashop` stub) rather than mocking it.
 *
 * The real jQuery UI widget is used deliberately: two of the three defects
 * these tests pin are properties OF jQuery UI itself, which a hand-written
 * mock would have to reproduce correctly to catch.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const REPO_ROOT = path.resolve(__dirname, '..', '..');

/** Spain, `id_country` 6 - the only stock country besides Mexico whose
 *  address format includes `dni` (`install-dev/data/xml/country.xml`). */
const DNI_COUNTRY_ID = '6';
/** Mexico, `id_country` 144 - the only other one. */
const OTHER_DNI_COUNTRY_ID = '144';
const DNI_COUNTRY_IDS = [DNI_COUNTRY_ID, OTHER_DNI_COUNTRY_ID];

/**
 * @returns {Function} the jQuery instance bound to the current jsdom window
 */
function installJQuery() {
    // jquery's UMD build calls its factory with `noGlobal = true`, so these
    // four assignments are load-bearing, not tidying - without them the
    // module's free `$` resolves to nothing.
    const jQuery = require('jquery');
    global.$ = jQuery;
    global.jQuery = jQuery;
    global.window.$ = jQuery;
    global.window.jQuery = jQuery;
    // jquery-ui ships no CommonJS branch, so each file must be required in
    // its real dependency order by hand.
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
 * Asserting only on the loading class once passed with a rule that never
 * actually painted the spinner (an unscoped `!important` out-ranked it) -
 * `getComputedStyle()` exercises the real cascade instead.
 *
 * jsdom's CSS parser drops declarations it does not understand, notably the
 * multi-value `background-position` form - do not assert on it.
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
 * `indirectEval` keeps evaluation in global scope, so top-level `class Foo {}`
 * and `window.Foo = Foo` behave as they do in the browser.
 *
 * @param {string} relPath repo-relative path, e.g. 'views/js/modules/X.js'
 */
function loadScript(relPath) {
    const src = fs.readFileSync(path.join(REPO_ROOT, relPath), 'utf8');
    const indirectEval = eval;
    indirectEval(src);
}

/**
 * Load the shared company-number display helper (TWO-25326 §12).
 *
 * Must load before any module that calls `window.TwoCompanyNumber` unguarded
 * - the priority order twopayment.php uses on a real page.
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
 * Load TwoOrderIntent as a <script> tag would (TWO-25326 §7.3) - no jQuery/
 * bus setup needed, since buildCompanyIntentMessage() only reads
 * window.twopayment.
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
 * Pass `country: null` to omit `data-iso-code` and exercise
 * getCurrentCountry()'s later resolution strategies.
 *
 * Renders `dni` unconditionally, unlike core (whose presence follows the
 * address format) - use buildAddressesStep() for anything testing that.
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
 * The checkout addresses step, in PrestaShop's OWN markup (TWO-40).
 *
 * Reproduced from core's `checkout/_partials/steps/addresses.tpl` and related
 * partials (byte-identical across the 8 and 9 images), not invented, since
 * the module's whole job is to read and write that exact markup. Structural
 * facts it reproduces:
 *
 *  - exactly ONE editable address form at a time; the other side is a saved-
 *    address selector or absent;
 *  - the editable form's own `.js-address-form` wrapper nests inside its
 *    block id div, and innermost-first root resolution lands on it;
 *  - `dni` is present only for a country whose address format asks for it
 *    (ES/MX on stock data) - not for every country;
 *  - the shared-address checkbox exists only while the delivery form is
 *    editable;
 *  - the country select carries a disabled placeholder AND a real option
 *    both marked `selected` - last one wins, so an unanswered select reads
 *    as the shop's default country, never `''`;
 *  - the outer step `<form>` and the address form's own `<form>` nest, so
 *    the block div - not the form - is the usable scope.
 *
 * @param {Object} [options]
 * @param {string} [options.editing] 'delivery' (default) or 'invoice' - which
 *        side gets the editable form; the other gets a selector
 * @param {boolean} [options.sameAddress] shared-address checkbox state
 *        (rendered only when the delivery form is editable). Default false.
 * @param {boolean} [options.invoiceBlock] whether an invoice block exists at
 *        all. Default: true unless sameAddress.
 * @param {?string} [options.countryId] the country rendered as selected.
 *        Default '1' (Germany). Pass DNI_COUNTRY_ID (Spain) for a `dni`
 *        field, or null for the placeholder-only EXCEPTION case core does
 *        not produce.
 * @param {boolean} [options.countryIsoAttrs] give the country options
 *        `data-iso-code` (default false - core's classic theme does not)
 * @param {string} [options.company] initial value of the company input
 * @param {string} [options.address1] initial value of the street input
 * @param {string} [options.postcode] initial value of the postcode input
 * @param {string} [options.city] initial value of the city input
 * @param {string} [options.phone] initial value of the phone input
 * @param {boolean} [options.blockContainers] whether the editable form has
 *        its own block id div and `.js-address-form` wrapper (default true).
 *        false models a theme that flattens both, widening the usable scope
 *        to span both address blocks.
 * @param {boolean} [options.blockIds] whether the address blocks carry
 *        core's ids (default true). false, combined with
 *        blockContainers:false, defeats an id-based scope guard.
 * @param {string} [options.dni] initial value of the identification input
 * @param {boolean} [options.formGroups] wrap each field in core's
 *        `.form-group` + `<label>` pair (default false, the flat shape most
 *        specs here use). true is for testing the field's WRAPPER.
 * @returns {void}
 */
function buildAddressesStep(options) {
    const opts = options || {};
    const editing = opts.editing || 'delivery';
    const sameAddress = opts.sameAddress === true;
    const invoiceBlock = 'invoiceBlock' in opts ? opts.invoiceBlock : !sameAddress;
    const countryId = 'countryId' in opts ? opts.countryId : '1';
    const isoAttrs = opts.countryIsoAttrs === true;
    const company = opts.company || '';
    const address1 = opts.address1 || '';
    const postcode = opts.postcode || '';
    const city = opts.city || '';
    const phone = opts.phone || '';
    const blockContainers = opts.blockContainers !== false;
    const blockIds = opts.blockIds !== false;
    const dni = opts.dni || '';
    const formGroups = opts.formGroups === true;

    /**
     * One rendered address field, optionally in core's own `.form-group` + label
     * wrapper.
     *
     * @param {string} label the visible label text
     * @param {string} control the input/select markup
     * @returns {string}
     */
    const fieldGroup = function (label, control) {
        if (!formGroups) {
            return '        ' + control;
        }

        return [
            '        <div class="form-group row">',
            '          <label class="col-md-3 form-control-label">' + label + '</label>',
            '          <div class="col-md-6">',
            '            ' + control,
            '          </div>',
            '        </div>'
        ].join('\n');
    };

    const countryOption = function (value, label, iso) {
        const attr = isoAttrs ? ' data-iso-code="' + iso + '"' : '';
        const selected = (countryId !== null && String(countryId) === value) ? ' selected' : '';
        return '        <option value="' + value + '"' + attr + selected + '>' + label + '</option>';
    };

    const addressForm = function (type) {
        const lines = [];
        if (blockContainers) {
            lines.push(
                '      <div' + (blockIds ? ' id="' + type + '-address"' : '') + '>',
                // The rendered form's own wrapper, inside the block id - core's
                // `address_form` block emits it, and it is what the innermost-first
                // root resolution actually lands on.
                '        <div class="js-address-form">'
            );
        }
        lines.push(
            '        <form method="POST" data-id-address="0">',
            fieldGroup('Company', '<input type="text" name="company" id="field-company" value="' + company + '">')
        );
        if (DNI_COUNTRY_IDS.indexOf(String(countryId)) !== -1) {
            lines.push(fieldGroup(
                'Identification number',
                '<input type="text" name="dni" id="field-dni" value="' + dni + '" required>'
            ));
        }
        lines.push(
            fieldGroup('VAT number', '<input type="text" name="vat_number" id="field-vat_number" value="">'),
            fieldGroup('Address', '<input type="text" name="address1" id="field-address1" value="' + address1 + '">'),
            fieldGroup('Zip/Postal code', '<input type="text" name="postcode" id="field-postcode" value="' + postcode + '">'),
            fieldGroup('City', '<input type="text" name="city" id="field-city" value="' + city + '">'),
            fieldGroup('Phone', '<input type="tel" name="phone" id="field-phone" value="' + phone + '">'),
            '        <select id="field-id_country" class="form-control form-control-select js-country" name="id_country" required>',
            // `selected` unconditionally, as core emits it - see this function's
            // docblock. The real country option below carries it too.
            '        <option value disabled selected>Please choose</option>',
            countryOption('17', 'United Kingdom', 'GB'),
            countryOption('8', 'France', 'FR'),
            countryOption('1', 'Germany', 'DE'),
            countryOption(DNI_COUNTRY_ID, 'Spain', 'ES'),
            countryOption(OTHER_DNI_COUNTRY_ID, 'Mexico', 'MX'),
            '        </select>',
            '        <input type="hidden" name="saveAddress" value="' + type + '">'
        );
        if (type === 'delivery') {
            lines.push(
                '        <div class="form-group row"><div class="col-md-9 col-md-offset-3">',
                '        <input name="use_same_address" id="use_same_address" type="checkbox" value="1"'
                    + (sameAddress ? ' checked' : '') + '>',
                '        <label for="use_same_address">Use this address for invoice too</label>',
                '        </div></div>'
            );
        }
        lines.push('        </form>');
        if (blockContainers) {
            lines.push('        </div>', '      </div>');
        }
        return lines.join('\n');
    };

    const addressSelector = function (type) {
        const name = type === 'invoice' ? 'id_address_invoice' : 'id_address_delivery';
        return [
            '      <div' + (blockIds ? ' id="' + type + '-addresses"' : '')
                + ' class="address-selector js-address-selector">',
            // Both classes, in core's order - `address-selector-block.tpl` emits
            // the `js-` hook and the presentational one together.
            '        <article class="js-address-item address-item">',
            '          <label><span class="custom-radio">',
            '            <input type="radio" name="' + name + '" value="7" checked>',
            '          </span><div class="address">Saved address</div></label>',
            '        </article>',
            '      </div>'
        ].join('\n');
    };

    const html = ['<div class="js-address-form">', '  <form method="POST" data-id-address="0">'];
    html.push(editing === 'delivery' ? addressForm('delivery') : addressSelector('delivery'));
    if (invoiceBlock) {
        html.push(editing === 'invoice' ? addressForm('invoice') : addressSelector('invoice'));
    } else {
        // core's `$use_same_address` branch: a link, not a toggle. Its href
        // navigates - which is why the mirror has to be a cross-page-load
        // operation and cannot listen for a reveal.
        html.push(
            '      <p><a data-link-action="different-invoice-address" href="/order?use_same_address=0">',
            '        Billing address differs from shipping address</a></p>'
        );
    }
    html.push('  </form>', '</div>');
    document.body.innerHTML = html.join('\n');
}

/**
 * Rebuild the addresses step the way core's OWN country-change handler does, so
 * a test can observe what that handler leaves behind (TWO-40).
 *
 * Reproduced from `themes/_core/js/address.js`, which is what runs when anything
 * fires `change` on a `.js-country` select - our own mirrored country write
 * included, since core binds it delegated on `body`:
 *
 *   1. read every `.js-address-form input`'s value into a map keyed by `name`;
 *   2. `$('.js-address-form').replaceWith(resp.address_form)` - a fresh
 *      server-rendered form, so the newly chosen country is the one rendered as
 *      selected, and nothing the browser had added to the old nodes exists;
 *   3. write the saved values back over the new `.js-address-form input` set.
 *
 * The load-bearing detail is that step 3 is INPUT-only and VALUE-only: `<select>`
 * elements are not restored at all, and no ATTRIBUTE is. So a value the plugin
 * wrote survives while the `data-two-autofilled-value` marker recording that the
 * plugin wrote it does not - which is the whole reason the mirror has a re-mark
 * operation. Modelling this with a simplified stand-in would prove nothing.
 *
 * @param {Object} [options] forwarded to buildAddressesStep - the SERVER's new
 *        render, so pass the country the buyer just chose as `countryId`
 * @returns {void}
 */
function rebuildAddressesStepAsCoreDoes(options) {
    const saved = {};
    document.querySelectorAll('.js-address-form input').forEach(function (input) {
        saved[input.getAttribute('name')] = input.value;
    });

    buildAddressesStep(options);

    document.querySelectorAll('.js-address-form input').forEach(function (input) {
        const name = input.getAttribute('name');
        if (Object.prototype.hasOwnProperty.call(saved, name)) {
            input.value = saved[name];
        }
    });
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
 * bug 9, round 3; TWO-40 removed the chip UI this answer used to draw).
 *
 * buildPaymentTile() leaves every `{if}` block stripped, which reproduces a
 * render where Smarty gave no answer - the fallback path where TwoSoleTrader
 * still has to fetch. This one fills in the `data-two-available`/
 * `data-two-country` attributes instead, so the markup under test is the
 * markup a real shop serves. Rendered FROM the template rather than
 * hand-written in the test, so a template change that breaks the handover
 * breaks these tests.
 *
 * @param {string} answer the value Smarty rendered into `data-two-available`:
 *        '1' available, '0' business-only, '' the registry did not answer.
 *        Arbitrary strings are accepted deliberately - a theme or a future
 *        template can emit one, and rejecting it is behaviour under test.
 * @param {string} countryIso the country that answer is about
 *
 * @returns {HTMLElement} the `.two-payment-container` that was appended
 */
function buildPaymentTileWithSoleTraderAnswer(answer, countryIso) {
    return renderPaymentTile({ answer: String(answer), country: countryIso });
}

/**
 * @param {{answer: string, country: string}|null} soleTrader null = leave
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
        // `$sole_trader_available` is what the template DRAWS from; it is true
        // only for the '1' answer, exactly as twopayment.php resolves it (an
        // unresolved answer draws as not-available).
        const available = soleTrader.answer === '1';
        // Both shapes the template uses, if/else first: an if/else block's own
        // `{/if}` would otherwise terminate the plain-`{if}` pattern early and
        // leave `{else}...` in the output.
        html = html
            .replace(
                /\{if \$sole_trader_available\}([\s\S]*?)\{else\}([\s\S]*?)\{\/if\}/g,
                available ? '$1' : '$2'
            )
            .replace(
                /\{if \$sole_trader_available\}([\s\S]*?)\{\/if\}/g,
                available ? '$1' : ''
            )
            .replace(/\{\$sole_trader_answer\|[^}]*\}/g, soleTrader.answer)
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
        // The query field's row - the unit that is hidden while the Sole
        // trader chip is selected, since the spinner is a sibling inside it.
        searchRow: $('.two-company-dropdown__search'),
        results: $('.two-company-dropdown__results'),
        // The company-NAME field and the sole-trader in-flight spinner that
        // now sits over it (TWO-40 follow-up). Outside the panel, unlike
        // everything else here, and deliberately so: the spinner has to serve
        // the "Select a different sole trader" flow, which opens no panel.
        nameField: $("input[name='company']"),
        nameSpinner: $('.two-company-name-spinner'),
        // The three-chip mode selector (TWO-40 design revision).
        modeChips: $('.two-company-mode-chips'),
        notListed: $('.two-company-not-listed'),
        registered: $('.two-company-registered-entry'),
        // Sole-trader enrolment entry point (TWO-40).
        soleTrader: $('.two-company-sole-trader-entry')
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
    DNI_COUNTRY_ID: DNI_COUNTRY_ID,
    OTHER_DNI_COUNTRY_ID: OTHER_DNI_COUNTRY_ID,
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
    buildAddressesStep: buildAddressesStep,
    rebuildAddressesStepAsCoreDoes: rebuildAddressesStepAsCoreDoes,
    replaceAddressForm: replaceAddressForm,
    stubAjax: stubAjax,
    callbackRecorder: callbackRecorder,
    panelParts: panelParts,
    shown: shown,
    openPanel: openPanel,
    typeQuery: typeQuery,
    resultTexts: resultTexts
};
